<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Assetwatch\Core\AlertText;
use GlpiPlugin\Assetwatch\Core\DigestBuilder;
use GlpiPlugin\Assetwatch\Core\DiskRule;
use GlpiPlugin\Assetwatch\Core\NoReportRule;
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

        foreach (PluginAssetwatchRepository::computersWithNewInventory() as $id => $computer) {
            $rows = PluginAssetwatchRepository::snapshotRows($id);
            $new = Snapshot::build($computer, $rows['memories'], $rows['processors'], $rows['drives'], $rows['ports'], $rows['ips'], $settings);
            $old = PluginAssetwatchSnapshot::decode($computer['snapshot_content']);

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
            }
            // First sighting only records the baseline: no alert on install.
            PluginAssetwatchSnapshot::save($id, (string) $computer['last_inventory_update'], Snapshot::mergeForStorage($old, $new), $now);
            $processed++;
        }
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
        if ($types === []) {
            return 0;
        }

        $computers = PluginAssetwatchRepository::monitoredComputers();
        $findings = [];
        foreach ($computers as $id => $computer) {
            if ($settings->checkNoReport) {
                $finding = NoReportRule::evaluate($computer['last_inventory_update'], (int) $computer['computertypes_id'], $nowDate, $settings);
                if ($finding !== null) {
                    $findings[] = self::finding($id, PluginAssetwatchAlert::TYPE_NO_REPORT, '', $finding);
                }
            }
        }
        if ($settings->checkDisk && $computers !== []) {
            foreach (PluginAssetwatchRepository::disksByComputer(array_keys($computers)) as $id => $disks) {
                foreach (DiskRule::evaluate($disks, $settings) as $mount => $finding) {
                    $findings[] = self::finding($id, PluginAssetwatchAlert::TYPE_DISK_LOW, (string) $mount, $finding);
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
            if (AlertText::summary($finding['alert_type'], $finding['content']) !== PluginAssetwatchAlert::decodeRow($alert)['summary']
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
                $entries[] = self::entry(DigestBuilder::KIND_RESOLVED, (int) $alert['id'], $computers[$id], (string) $alert['alert_type'], (string) $alert['alert_key'], PluginAssetwatchAlert::decodeContent($alert), (string) $alert['date_creation']);
            }
        }

        foreach (DigestBuilder::build($entries) as $digest) {
            $stored = PluginAssetwatchDigest::store($digest, $now);
            if ($stored !== null) {
                NotificationEvent::raiseEvent('digest', $stored);
            }
        }

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

        return $removed;
    }

    /**
     * Record a one-shot change alert and send its notification.
     *
     * @param array<string, mixed> $item    asset fields (id, name, entities_id, groups_id_tech)
     * @param array<string, mixed> $content
     */
    public static function raiseEventAlert(string $itemtype, array $item, string $type, array $content, string $now): void
    {
        $alert_id = PluginAssetwatchAlert::createAlert($itemtype, $item, $type, '', PluginAssetwatchAlert::STATUS_EVENT, $content, $now);
        $alert = new PluginAssetwatchAlert();
        if ($alert_id > 0 && $alert->getFromDB($alert_id)) {
            NotificationEvent::raiseEvent($type, $alert);
        }
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
    private static function entry(string $kind, int $alert_id, array $computer, string $type, string $key, array $content, string $since): array
    {
        return [
            'kind'           => $kind,
            'alert_id'       => $alert_id,
            'entities_id'    => (int) $computer['entities_id'],
            'groups_id_tech' => (int) $computer['groups_id_tech'],
            'itemtype'       => 'Computer',
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
