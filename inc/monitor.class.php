<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

use Glpi\DBAL\QuerySubQuery;
use GlpiPlugin\Assetwatch\Core\AlertText;
use GlpiPlugin\Assetwatch\Core\DigestBuilder;
use GlpiPlugin\Assetwatch\Core\DiskRule;
use GlpiPlugin\Assetwatch\Core\HealthRules;
use GlpiPlugin\Assetwatch\Core\NoReportRule;
use GlpiPlugin\Assetwatch\Core\Settings;
use GlpiPlugin\Assetwatch\Core\Snapshot;
use GlpiPlugin\Assetwatch\Core\SnapshotDiff;
use GlpiPlugin\Assetwatch\Core\StatusTracker;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Orchestrates the checks: glue between GLPI data (Repository), the pure
 * rules (src/Core) and persistence / notifications.
 */
class PluginAssetwatchMonitor
{
    /**
     * Compare every computer that received a new inventory with its baseline.
     *
     * @return int number of computers processed
     */
    public static function detectChanges(): int
    {
        $settings = PluginAssetwatchConfig::getSettings();
        $now = self::now();
        $processed = 0;
        $entries = [];

        $computers = PluginAssetwatchRepository::computersWithNewInventory();
        $disks = $computers === [] ? [] : PluginAssetwatchRepository::disksByComputer(array_keys($computers));
        foreach ($computers as $id => $computer) {
            $rows = PluginAssetwatchRepository::snapshotRows($id);
            $new = Snapshot::build($computer, $rows['memories'], $rows['processors'], $rows['drives'], $rows['ports'], $rows['ips'], $settings);
            $old = PluginAssetwatchSnapshot::decode($computer['snapshot_content']);
            // 2.5: boot time kept in the baseline (an inventory without it keeps the previous one).
            $new['last_boot'] = !empty($computer['last_boot']) ? (string) $computer['last_boot'] : ($old['last_boot'] ?? null);

            // 2.5: free space at each inventory, for the disk trend.
            if (!empty($computer['last_inventory_update'])) {
                PluginAssetwatchRepository::recordDiskSamples($id, DiskRule::measureAll($disks[$id] ?? [], $settings), (string) $computer['last_inventory_update']);
            }

            if ($old !== null) {
                $diff = SnapshotDiff::compare($old, $new, $settings->checkHardware, $settings->checkIdentity);
                $base = [
                    'inventory_date'          => $computer['last_inventory_update'],
                    'previous_inventory_date' => $computer['snapshot_date'],
                ];
                if ($diff['hardware'] !== []) {
                    self::raiseEventAlert('Computer', $computer, PluginAssetwatchAlert::TYPE_HARDWARE, $base + ['changes' => $diff['hardware']], $now);
                }
                if ($diff['identity'] !== []) {
                    self::raiseEventAlert('Computer', $computer, PluginAssetwatchAlert::TYPE_IDENTITY, $base + ['changes' => $diff['identity']], $now);
                }
                // 2.5: unexpected reboot -> one-shot alert in the digest of the group (sent below).
                $reboot = $settings->checkReboot
                    ? HealthRules::reboot($old['last_boot'] ?? null, $new['last_boot'], $settings->rebootQuietHours, new DateTimeZone(date_default_timezone_get()))
                    : null;
                if ($reboot !== null) {
                    $alert_id = PluginAssetwatchAlert::createAlert('Computer', $computer, PluginAssetwatchAlert::TYPE_REBOOT, '', PluginAssetwatchAlert::STATUS_EVENT, $reboot, $now);
                    $entries[] = self::entry(DigestBuilder::KIND_NEW, $alert_id, $computer, PluginAssetwatchAlert::TYPE_REBOOT, '', $reboot, $now);
                }
            }
            // First sighting only records the baseline: no alert on install.
            PluginAssetwatchSnapshot::save($id, (string) $computer['last_inventory_update'], Snapshot::mergeForStorage($old, $new), $now);
            $processed++;
        }
        self::sendDigests($entries, $now);
        return $processed;
    }

    /**
     * Evaluate missing inventories and disk space, update alert states and
     * send one digest per technical group.
     *
     * @return int number of digest entries (new + reminders + resolved)
     */
    public static function checkStatus(): int
    {
        $settings = PluginAssetwatchConfig::getSettings();
        $now = self::now();
        $nowDate = new DateTimeImmutable($now);

        $types = [];
        if ($settings->checkNoReport) {
            $types[] = PluginAssetwatchAlert::TYPE_NO_REPORT;
        }
        if ($settings->checkDisk) {
            $types[] = PluginAssetwatchAlert::TYPE_DISK_LOW;
        }
        if ($settings->checkAntivirus) {
            $types[] = PluginAssetwatchAlert::TYPE_ANTIVIRUS;
        }
        if ($settings->agentMinVersion !== '') {
            $types[] = PluginAssetwatchAlert::TYPE_AGENT_OUTDATED;
        }
        if ($settings->checkDiskForecast) {
            $types[] = PluginAssetwatchAlert::TYPE_DISK_FORECAST;
        }
        if ($types === []) {
            return 0;
        }

        $computers = PluginAssetwatchRepository::monitoredComputers();
        $findings = [];
        // Current partition usage, to show real values in "resolved" notices.
        $diskNow = [];
        foreach ($computers as $id => $computer) {
            if ($settings->checkNoReport) {
                $finding = NoReportRule::evaluate($computer['last_inventory_update'], (int) $computer['computertypes_id'], $nowDate, $settings);
                if ($finding !== null) {
                    $findings[] = self::finding($id, PluginAssetwatchAlert::TYPE_NO_REPORT, '', $finding);
                }
            }
        }
        if (($settings->checkDisk || $settings->checkDiskForecast) && $computers !== []) {
            $samples = $settings->checkDiskForecast
                ? PluginAssetwatchRepository::diskSamples(array_keys($computers), $nowDate->modify('-' . HealthRules::FORECAST_WINDOW_DAYS . ' days')->format('Y-m-d H:i:s'))
                : [];
            foreach (PluginAssetwatchRepository::disksByComputer(array_keys($computers)) as $id => $disks) {
                $diskNow[$id] = DiskRule::measureAll($disks, $settings);
                $low = DiskRule::evaluate($disks, $settings);
                if ($settings->checkDisk) {
                    foreach ($low as $mount => $finding) {
                        $findings[] = self::finding($id, PluginAssetwatchAlert::TYPE_DISK_LOW, (string) $mount, $finding);
                    }
                }
                if ($settings->checkDiskForecast) {
                    foreach ($diskNow[$id] as $mount => $m) {
                        if (isset($low[$mount])) {
                            continue;   // already low: the disk_low alert says it
                        }
                        $threshold = HealthRules::lowThresholdGb((float) $m['total_gb'], $settings->diskMinFreePercent, $settings->diskMinFreeGb);
                        $forecast = HealthRules::diskForecast($samples[$id][$mount] ?? [], $threshold, $nowDate, $settings->diskForecastDays);
                        if ($forecast !== null) {
                            $findings[] = self::finding($id, PluginAssetwatchAlert::TYPE_DISK_FORECAST, (string) $mount, ['mountpoint' => (string) $mount] + $forecast);
                        }
                    }
                }
            }
        }
        if ($settings->checkAntivirus && $computers !== []) {
            foreach (PluginAssetwatchRepository::antivirusesByComputer(array_keys($computers)) as $id => $antiviruses) {
                foreach (HealthRules::antivirus($antiviruses, $nowDate) as $name => $finding) {
                    $findings[] = self::finding($id, PluginAssetwatchAlert::TYPE_ANTIVIRUS, (string) $name, $finding);
                }
            }
        }
        if ($settings->agentMinVersion !== '' && $computers !== []) {
            foreach (PluginAssetwatchRepository::agentsByComputer(array_keys($computers)) as $id => $agent) {
                if (HealthRules::isAgentOutdated($agent['version'], $settings->agentMinVersion)) {
                    $findings[] = self::finding($id, PluginAssetwatchAlert::TYPE_AGENT_OUTDATED, '', [
                        'version' => HealthRules::normalizeVersion($agent['version']),
                        'minimum' => $settings->agentMinVersion,
                    ]);
                }
            }
        }

        $plan = StatusTracker::reconcile(
            PluginAssetwatchAlert::getOpenConditionAlerts(),
            $findings,
            $types,
            $nowDate,
            $settings->reminderDays
        );

        $entries = [];
        foreach ($plan['open'] as $finding) {
            $computer = $computers[$finding['items_id']];
            $alert_id = PluginAssetwatchAlert::createAlert('Computer', $computer, $finding['alert_type'], $finding['alert_key'], PluginAssetwatchAlert::STATUS_ACTIVE, $finding['content'], $now);
            $entries[] = self::entry(DigestBuilder::KIND_NEW, $alert_id, $computer, $finding['alert_type'], $finding['alert_key'], $finding['content'], $now);
        }

        foreach ($plan['remind'] as $pair) {
            $alert = $pair['alert'];
            $finding = $pair['finding'];
            $computer = $computers[$finding['items_id']];
            PluginAssetwatchAlert::updateAlert((int) $alert['id'], self::refreshValues($computer, $finding) + [
                'status'             => PluginAssetwatchAlert::STATUS_ACTIVE,
                'date_ack_until'     => null,
                'date_last_notified' => $now,
                'date_mod'           => $now,
            ]);
            $entries[] = self::entry(DigestBuilder::KIND_REMINDER, (int) $alert['id'], $computer, $finding['alert_type'], $finding['alert_key'], $finding['content'], (string) $alert['date_creation']);
        }

        foreach ($plan['refresh'] as $pair) {
            $alert = $pair['alert'];
            $finding = $pair['finding'];
            $values = self::refreshValues($computers[$finding['items_id']], $finding);
            // Avoid an hourly write when nothing visible changed.
            if (AlertText::summary($finding['alert_type'], $finding['content']) !== (string) $alert['summary']
                || (int) $values['groups_id_tech'] !== (int) $alert['groups_id_tech']) {
                PluginAssetwatchAlert::updateAlert((int) $alert['id'], $values + ['date_mod' => $now]);
            }
        }

        foreach ($plan['resolve'] as $alert) {
            PluginAssetwatchAlert::updateAlert((int) $alert['id'], [
                'status'        => PluginAssetwatchAlert::STATUS_RESOLVED,
                'date_resolved' => $now,
                'date_mod'      => $now,
            ]);
            $id = (int) $alert['items_id'];
            // Machines that left monitoring (trashed, no longer dynamic) resolve silently.
            if ($alert['itemtype'] === 'Computer' && isset($computers[$id])) {
                $content = self::currentState($alert, $computers[$id], $diskNow[$id] ?? [], $nowDate, $settings);
                $entries[] = self::entry(DigestBuilder::KIND_RESOLVED, (int) $alert['id'], $computers[$id], (string) $alert['alert_type'], (string) $alert['alert_key'], $content, (string) $alert['date_creation']);
            }
        }

        self::sendDigests($entries, $now);
        return count($entries);
    }

    /**
     * One digest per (entity, technical group); a machine with several groups is in each of them.
     *
     * @param array<int, array<string, mixed>> $entries
     */
    public static function sendDigests(array $entries, string $now): void
    {
        foreach (DigestBuilder::build(DigestBuilder::expandByGroups($entries)) as $digest) {
            $stored = PluginAssetwatchDigest::store($digest, $now);
            if ($stored !== null) {
                NotificationEvent::raiseEvent('digest', $stored);
            }
        }
    }

    /**
     * Generic version of the open / remind / resolve cycle for assets of any type (liveness probe).
     *
     * @param array<int, array<string, mixed>> $findings itemtype, items_id, alert_type, alert_key, content
     * @param string[]                         $types    alert types owned by the caller
     * @param array<string, array<string, mixed>> $items "itemtype#id" => asset (id, name, entities_id,
     *        groups_id_tech, tech_groups); alerts of assets missing here resolve silently
     * @param callable(array<string, mixed>): array<string, mixed> $resolved_content content shown in a resolved notice
     *
     * @return int number of digest entries
     */
    public static function reconcileItems(array $findings, array $types, array $items, string $now, Settings $settings, callable $resolved_content): int
    {
        $plan = StatusTracker::reconcile(
            PluginAssetwatchAlert::getOpenConditionAlerts(),
            $findings,
            $types,
            new DateTimeImmutable($now),
            $settings->reminderDays
        );
        $item = static fn (array $row): ?array => $items[$row['itemtype'] . '#' . (int) $row['items_id']] ?? null;

        $entries = [];
        foreach ($plan['open'] as $finding) {
            $asset = $item($finding);
            if ($asset === null) {
                continue;
            }
            $alert_id = PluginAssetwatchAlert::createAlert($finding['itemtype'], $asset, $finding['alert_type'], $finding['alert_key'], PluginAssetwatchAlert::STATUS_ACTIVE, $finding['content'], $now);
            $entries[] = self::entry(DigestBuilder::KIND_NEW, $alert_id, $asset, $finding['alert_type'], $finding['alert_key'], $finding['content'], $now, $finding['itemtype']);
        }
        foreach ($plan['remind'] as $pair) {
            $asset = $item($pair['finding']);
            if ($asset === null) {
                continue;
            }
            PluginAssetwatchAlert::updateAlert((int) $pair['alert']['id'], self::refreshValues($asset, $pair['finding']) + [
                'status'             => PluginAssetwatchAlert::STATUS_ACTIVE,
                'date_ack_until'     => null,
                'date_last_notified' => $now,
                'date_mod'           => $now,
            ]);
            $entries[] = self::entry(DigestBuilder::KIND_REMINDER, (int) $pair['alert']['id'], $asset, $pair['finding']['alert_type'], $pair['finding']['alert_key'], $pair['finding']['content'], (string) $pair['alert']['date_creation'], $pair['finding']['itemtype']);
        }
        foreach ($plan['refresh'] as $pair) {
            $asset = $item($pair['finding']);
            if ($asset !== null) {
                $values = self::refreshValues($asset, $pair['finding']);
                if ($values['summary'] !== (string) $pair['alert']['summary'] || (int) $values['groups_id_tech'] !== (int) $pair['alert']['groups_id_tech']) {
                    PluginAssetwatchAlert::updateAlert((int) $pair['alert']['id'], $values + ['date_mod' => $now]);
                }
            }
        }
        foreach ($plan['resolve'] as $alert) {
            PluginAssetwatchAlert::updateAlert((int) $alert['id'], [
                'status'        => PluginAssetwatchAlert::STATUS_RESOLVED,
                'date_resolved' => $now,
                'date_mod'      => $now,
            ]);
            $asset = $item($alert);
            if ($asset !== null) {
                $entries[] = self::entry(DigestBuilder::KIND_RESOLVED, (int) $alert['id'], $asset, (string) $alert['alert_type'], (string) $alert['alert_key'], $resolved_content($alert), (string) $alert['date_creation'], (string) $alert['itemtype']);
            }
        }
        self::sendDigests($entries, $now);
        return count($entries);
    }

    /**
     * Drop closed alerts and digests older than the retention period, and orphan snapshots.
     *
     * @return int number of rows removed
     */
    public static function purgeHistory(): int
    {
        /** @var DBmysql $DB */
        global $DB;

        $settings = PluginAssetwatchConfig::getSettings();
        $limit = date('Y-m-d H:i:s', strtotime(self::now()) - $settings->retentionDays * DAY_TIMESTAMP);

        $DB->delete(PluginAssetwatchAlert::getTable(), [
            'status'   => [PluginAssetwatchAlert::STATUS_RESOLVED, PluginAssetwatchAlert::STATUS_EVENT],
            'date_mod' => ['<', $limit],
        ]);
        $removed = $DB->affectedRows();

        $DB->delete(PluginAssetwatchDigest::getTable(), ['date_creation' => ['<', $limit]]);
        $removed += $DB->affectedRows();

        $DB->delete(PluginAssetwatchSnapshot::getTable(), [
            'NOT' => ['computers_id' => new QuerySubQuery(['SELECT' => 'id', 'FROM' => 'glpi_computers'])],
        ]);
        $removed += $DB->affectedRows();

        // 2.5: disk trend samples (60 days kept; the forecast uses 30).
        $DB->delete(PluginAssetwatchInstall::DISK_HISTORY_TABLE, [
            'OR' => [
                'date' => ['<', date('Y-m-d H:i:s', strtotime(self::now()) - 60 * DAY_TIMESTAMP)],
                'NOT'  => ['computers_id' => new QuerySubQuery(['SELECT' => 'id', 'FROM' => 'glpi_computers'])],
            ],
        ]);
        $removed += $DB->affectedRows();

        return $removed;
    }

    /**
     * Record a one-shot change alert and send its notification.
     *
     * @param array<string, mixed> $item    asset fields (id, name, entities_id, groups_id_tech, tech_groups)
     * @param array<string, mixed> $content
     */
    public static function raiseEventAlert(string $itemtype, array $item, string $type, array $content, string $now): void
    {
        $alert_id = PluginAssetwatchAlert::createAlert($itemtype, $item, $type, '', PluginAssetwatchAlert::STATUS_EVENT, $content, $now);
        $alert = new PluginAssetwatchAlert();
        if ($alert_id > 0 && $alert->getFromDB($alert_id)) {
            // The row keeps one group; notify every technical group of the asset (GLPI 11
            // NotificationTarget::addItemGroupTechInCharge() accepts a list).
            $groups = array_values(array_filter(array_map('intval', (array) ($item['tech_groups'] ?? [])), static fn (int $g): bool => $g > 0));
            if (count($groups) > 1) {
                $alert->fields['groups_id_tech'] = $groups;
            }
            NotificationEvent::raiseEvent($type, $alert);
        }
    }

    /**
     * Values to show for a resolved alert: the situation now, not the last bad one.
     *
     * @param array<string, mixed> $alert
     * @param array<string, mixed> $computer
     * @param array<string, array<string, mixed>> $disks current measurements by mount point
     *
     * @return array<string, mixed>
     */
    private static function currentState(array $alert, array $computer, array $disks, DateTimeImmutable $now, Settings $settings): array
    {
        // Stored key and measured mount points are both raw strings (GLPI 11 no longer HTML-encodes).
        $key = (string) $alert['alert_key'];
        if ($alert['alert_type'] === PluginAssetwatchAlert::TYPE_DISK_LOW && isset($disks[$key])) {
            return $disks[$key];
        }
        if ($alert['alert_type'] === PluginAssetwatchAlert::TYPE_NO_REPORT && !empty($computer['last_inventory_update'])) {
            $last = new DateTimeImmutable((string) $computer['last_inventory_update'], $now->getTimezone());
            return [
                'last_inventory'  => $last->format('Y-m-d H:i:s'),
                'hours_since'     => max(0, intdiv($now->getTimestamp() - $last->getTimestamp(), 3600)),
                'threshold_hours' => $settings->noReportHoursFor((int) $computer['computertypes_id']),
            ];
        }
        // Partition gone or check data missing: keep the last known values.
        return PluginAssetwatchAlert::decodeContent($alert);
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private static function finding(int $computers_id, string $type, string $key, array $content): array
    {
        return [
            'itemtype'   => 'Computer',
            'items_id'   => $computers_id,
            'alert_type' => $type,
            'alert_key'  => $key,
            'content'    => $content,
        ];
    }

    /**
     * @param array<string, mixed> $computer
     * @param array<string, mixed> $finding
     *
     * @return array<string, mixed>
     */
    private static function refreshValues(array $computer, array $finding): array
    {
        return [
            'item_name'      => (string) $computer['name'],
            'entities_id'    => (int) $computer['entities_id'],
            'groups_id_tech' => (int) $computer['groups_id_tech'],
            'summary'        => AlertText::summary($finding['alert_type'], $finding['content']),
            'content'        => json_encode($finding['content'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * @param array<string, mixed> $computer
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    private static function entry(string $kind, int $alert_id, array $computer, string $type, string $key, array $content, string $since, string $itemtype = 'Computer'): array
    {
        return [
            'kind'           => $kind,
            'alert_id'       => $alert_id,
            'entities_id'    => (int) $computer['entities_id'],
            'groups_id_tech' => (int) $computer['groups_id_tech'],
            'tech_groups'    => (array) ($computer['tech_groups'] ?? []),
            'itemtype'       => $itemtype,
            'items_id'       => (int) $computer['id'],
            'item_name'      => (string) $computer['name'],
            'alert_type'     => $type,
            'alert_key'      => $key,
            'content'        => $content,
            'since'          => $since,
        ];
    }

    private static function now(): string
    {
        return $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    }
}
