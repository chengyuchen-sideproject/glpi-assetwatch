<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Assetwatch\Core\AlertText;
use GlpiPlugin\Assetwatch\Core\ProbeRule;
use GlpiPlugin\Assetwatch\Core\Settings;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Liveness probe (automatic action AssetwatchProbe, every 5 minutes, off by default):
 *  1. every computer reported by a GLPI Agent: HTTP request to its agent (port 62354 unless the
 *     agent says otherwise) on its name and addresses - the same request as the "status" button
 *     of the agent page in GLPI;
 *  2. the ones that did not answer, and the assets in a rack without agent (switches, PDUs...):
 *     TCP connection to the configured ports on their addresses.
 * Alerts "Not answering" / "Agent not answering" after N failed runs in a row, in the digest of
 * the technical group, resolved when it answers again.
 *
 * Also sends "inventory now" requests to agents (datacenter inventory page).
 */
class PluginAssetwatchProbe extends CommonDBTM
{
    public $dohistory = false;

    /** Rackable types probed by TCP when they have no agent. */
    public const DEVICE_TYPES = ['NetworkEquipment', 'PDU', 'Peripheral', 'Enclosure', 'Computer'];

    public static function getTypeName($nb = 0)
    {
        return __('Liveness probe', 'assetwatch');
    }

    /**
     * Assets to probe.
     *
     * @return array<string, array<string, mixed>> "itemtype#id" => asset + has_agent, port, names, ips
     */
    public static function targets(Settings $settings): array
    {
        $targets = [];
        $computers = PluginAssetwatchRepository::monitoredComputers();
        $agents = PluginAssetwatchRepository::agentsByComputer(array_keys($computers));
        $ips = PluginAssetwatchRepository::ipsByItem('Computer', array_keys($agents), $settings->portExcludedPatterns);
        foreach ($agents as $id => $agent) {
            $computer = $computers[$id];
            $targets["Computer#$id"] = $computer + [
                'itemtype'  => 'Computer',
                'has_agent' => true,
                'port'      => $agent['port'],
                'names'     => trim((string) $computer['name']) !== '' ? [trim((string) $computer['name'])] : [],
                'ips'       => ProbeRule::usableIps($ips[$id] ?? []),
            ];
        }

        if ($settings->probeDevices) {
            foreach (PluginAssetwatchRepository::rackedAssets(self::DEVICE_TYPES) as $itemtype => $rows) {
                $device_ips = PluginAssetwatchRepository::ipsByItem($itemtype, array_keys($rows), $settings->portExcludedPatterns);
                foreach ($rows as $id => $row) {
                    $key = "$itemtype#$id";
                    $usable = ProbeRule::usableIps($device_ips[$id] ?? []);
                    if (isset($targets[$key]) || $usable === []) {
                        continue;   // computer with agent already there; nothing to probe without address
                    }
                    $targets[$key] = $row + [
                        'itemtype'  => $itemtype,
                        'has_agent' => false,
                        'port'      => 0,
                        'names'     => [],
                        'ips'       => $usable,
                    ];
                }
            }
        }
        return $targets;
    }

    /**
     * Probe the targets.
     *
     * @param array<string, array<string, mixed>> $targets
     *
     * @return array<string, array{result: string, checked: string}>
     */
    public static function probe(array $targets, Settings $settings): array
    {
        // 1. agents
        $requests = [];
        foreach ($targets as $key => $t) {
            if (!$t['has_agent']) {
                continue;
            }
            foreach (['http', 'https'] as $proto) {
                foreach (array_merge($t['ips'], $t['names']) as $i => $address) {
                    $requests["$key|a|$proto|$i"] = ['url' => sprintf('%s://%s:%d/status', $proto, ProbeRule::urlHost($address), $t['port'])];
                }
            }
        }
        $agent_ok = [];
        foreach (PluginAssetwatchProber::run($requests, $settings->probeTimeout) as $rkey => $r) {
            if ($r['ok']) {
                $agent_ok[explode('|', $rkey)[0]] = true;
            }
        }

        // 2. TCP
        $requests = [];
        foreach ($targets as $key => $t) {
            if (isset($agent_ok[$key])) {
                continue;
            }
            foreach ($t['ips'] as $i => $ip) {
                foreach ($settings->probeTcpPorts as $port) {
                    $requests["$key|t|$i|$port"] = ['url' => sprintf('tcp://%s:%d', ProbeRule::urlHost($ip), $port), 'tcp' => true];
                }
            }
        }
        $tcp_ok = [];
        foreach (PluginAssetwatchProber::run($requests, $settings->probeTimeout) as $rkey => $r) {
            if ($r['ok']) {
                $tcp_ok[explode('|', $rkey)[0]] = true;
            }
        }

        $results = [];
        foreach ($targets as $key => $t) {
            $checked = [];
            if ($t['has_agent']) {
                $checked[] = sprintf('agent :%d', $t['port']);
            }
            if (!isset($agent_ok[$key]) && $settings->probeTcpPorts !== []) {
                $checked[] = 'TCP ' . implode('/', $settings->probeTcpPorts);
            }
            $results[$key] = [
                'result'  => ProbeRule::classify($t['has_agent'], isset($agent_ok[$key]), isset($tcp_ok[$key])),
                'checked' => AlertText::truncate(implode(', ', $checked) . ' @ ' . implode(' ', array_slice(array_merge($t['ips'], $t['names']), 0, 4))),
            ];
        }
        return $results;
    }

    /**
     * One probe run.
     *
     * @param bool $dry_run probe and report only: no state saved, no alert (configuration test)
     *
     * @return array{enabled: bool, total: int, ok: int, agent_down: int, unreachable: int, storm: bool, failures: list<array{name: string, type: string, url: string, result: string, checked: string}>, seconds: float}
     */
    public static function run(bool $dry_run): array
    {
        $settings = PluginAssetwatchConfig::getSettings();
        $summary = ['enabled' => $settings->checkProbe, 'total' => 0, 'ok' => 0, 'agent_down' => 0, 'unreachable' => 0, 'storm' => false, 'failures' => [], 'seconds' => 0.0];
        if (!$dry_run && !$settings->checkProbe) {
            return $summary;
        }
        $start = microtime(true);
        $targets = self::targets($settings);
        $results = self::probe($targets, $settings);

        foreach ($results as $key => $r) {
            $summary['total']++;
            $summary[$r['result']]++;
            if ($r['result'] !== ProbeRule::STATE_OK) {
                $t = $targets[$key];
                $summary['failures'][] = [
                    'name'    => (string) $t['name'],
                    'type'    => $t['itemtype']::getTypeName(1),
                    'url'     => $t['itemtype']::getFormURLWithID((int) $t['id']),
                    'result'  => $r['result'],
                    'checked' => $r['checked'],
                ];
            }
        }
        usort($summary['failures'], static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
        $summary['storm'] = ProbeRule::isStorm(array_column($results, 'result'));
        $summary['seconds'] = round(microtime(true) - $start, 1);
        if ($dry_run) {
            return $summary;
        }

        $now = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        $states = self::saveStates($results, $now);
        if ($summary['storm']) {
            // Looks like the GLPI host lost the network: keep the alerts as they are.
            return $summary;
        }

        $findings = [];
        foreach ($states as $key => $state) {
            $type = ProbeRule::alertType($state, $settings->probeFailCount);
            if ($type === null) {
                continue;
            }
            [$itemtype, $id] = explode('#', $key);
            $findings[] = [
                'itemtype'   => $itemtype,
                'items_id'   => (int) $id,
                'alert_type' => $type,
                'alert_key'  => '',
                'content'    => [
                    'state'      => $state['state'],
                    'since'      => $state['date_first_fail'],
                    'fail_count' => $state['fail_count'],
                    'last_ok'    => $state['date_last_ok'],
                    'checked'    => $results[$key]['checked'],
                ],
            ];
        }
        PluginAssetwatchMonitor::reconcileItems(
            $findings,
            [AlertText::TYPE_UNREACHABLE, AlertText::TYPE_AGENT_DOWN],
            $targets,
            $now,
            $settings,
            static function (array $alert) use ($states): array {
                $state = $states[$alert['itemtype'] . '#' . (int) $alert['items_id']] ?? null;
                return ['last_ok' => $state['date_last_ok'] ?? null] + PluginAssetwatchAlert::decodeContent($alert);
            }
        );
        return $summary;
    }

    /**
     * @param array<string, array{result: string, checked: string}> $results
     *
     * @return array<string, array{state: string, fail_count: int, date_first_fail: string|null, date_last_ok: string|null}>
     */
    private static function saveStates(array $results, string $now): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $table = self::getTable();
        $previous = [];
        foreach ($DB->request(['FROM' => $table]) as $row) {
            $previous[$row['itemtype'] . '#' . (int) $row['items_id']] = $row;
        }
        $states = [];
        foreach ($results as $key => $r) {
            $state = ProbeRule::next(isset($previous[$key]) ? [
                'state'           => (string) $previous[$key]['state'],
                'fail_count'      => (int) $previous[$key]['fail_count'],
                'date_first_fail' => $previous[$key]['date_first_fail'],
                'date_last_ok'    => $previous[$key]['date_last_ok'],
            ] : null, $r['result'], $now);
            $states[$key] = $state;
            $values = $state + ['checked' => $r['checked'], 'date_last_probe' => $now];
            if (isset($previous[$key])) {
                $DB->update($table, $values, ['id' => (int) $previous[$key]['id']]);
            } else {
                [$itemtype, $id] = explode('#', $key);
                $DB->insert($table, $values + ['itemtype' => $itemtype, 'items_id' => (int) $id]);
            }
        }
        // Assets no longer probed (deleted, agent gone, probe of devices switched off).
        $gone = array_diff_key($previous, $results);
        if ($gone !== []) {
            $DB->delete($table, ['id' => array_map(static fn (array $row): int => (int) $row['id'], array_values($gone))]);
        }
        return $states;
    }

    /**
     * Last probe state of assets, for display.
     *
     * @param int[] $ids
     *
     * @return array<int, array{state: string, fail_count: int, date_last_ok: string|null, date_last_probe: string|null, down: bool}>
     */
    public static function statesOf(string $itemtype, array $ids): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $settings = PluginAssetwatchConfig::getSettings();
        if (!$settings->checkProbe || $ids === []) {
            return [];
        }
        $result = [];
        foreach ($DB->request(['FROM' => self::getTable(), 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $ids]]) as $row) {
            $state = ['state' => (string) $row['state'], 'fail_count' => (int) $row['fail_count']];
            $result[(int) $row['items_id']] = $state + [
                'date_last_ok'    => $row['date_last_ok'],
                'date_last_probe' => $row['date_last_probe'],
                'down'            => ProbeRule::alertType($state, $settings->probeFailCount) !== null,
            ];
        }
        return $result;
    }

    public static function purgeForItem(CommonDBTM $item): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->delete(self::getTable(), ['itemtype' => $item::class, 'items_id' => $item->getID()]);
    }

    // ------------------------------------------------------------------
    // Inventory now
    // ------------------------------------------------------------------

    /**
     * Ask the agents of computers to send their inventory now (agent "/now" endpoint; the agent
     * must trust the GLPI host: option httpd-trust).
     *
     * @param int[] $computer_ids
     *
     * @return array{total: int, requested: int, refused: int, failed: int, refused_names: list<string>, failed_names: list<string>}
     */
    public static function requestInventory(array $computer_ids): array
    {
        $settings = PluginAssetwatchConfig::getSettings();
        $summary = ['total' => 0, 'requested' => 0, 'refused' => 0, 'failed' => 0, 'refused_names' => [], 'failed_names' => []];
        $agents = PluginAssetwatchRepository::agentsByComputer(array_values(array_unique(array_map('intval', $computer_ids))));
        if ($agents === []) {
            return $summary;
        }
        $ips = PluginAssetwatchRepository::ipsByItem('Computer', array_keys($agents), $settings->portExcludedPatterns);
        $names = [];
        $requests = [];
        foreach ($agents as $id => $agent) {
            $computer = new Computer();
            if (!$computer->getFromDB($id) || !$computer->can($id, READ)) {
                continue;
            }
            $names[$id] = $computer->getName();
            $addresses = array_merge(ProbeRule::usableIps($ips[$id] ?? []), [trim((string) $computer->fields['name'])]);
            foreach (['http', 'https'] as $proto) {
                foreach (array_filter($addresses) as $i => $address) {
                    $requests["$id|$proto|$i"] = ['url' => sprintf('%s://%s:%d/now', $proto, ProbeRule::urlHost($address), $agent['port'])];
                }
            }
        }
        $best = [];
        foreach (PluginAssetwatchProber::run($requests, $settings->probeTimeout) as $rkey => $r) {
            $id = (int) explode('|', $rkey)[0];
            $code = $r['ok'] ? $r['http_code'] : 0;
            // 2xx = requested; another HTTP code = the agent answered but refused (not trusted).
            $rank = $code >= 200 && $code < 300 ? 2 : ($code > 0 ? 1 : 0);
            $best[$id] = max($best[$id] ?? 0, $rank);
        }
        foreach ($names as $id => $name) {
            $summary['total']++;
            $rank = $best[$id] ?? 0;
            if ($rank === 2) {
                $summary['requested']++;
            } elseif ($rank === 1) {
                $summary['refused']++;
                $summary['refused_names'][] = $name;
            } else {
                $summary['failed']++;
                $summary['failed_names'][] = $name;
            }
        }
        sort($summary['refused_names'], SORT_NATURAL | SORT_FLAG_CASE);
        sort($summary['failed_names'], SORT_NATURAL | SORT_FLAG_CASE);
        return $summary;
    }
}
