<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Assetwatch\Core;

/**
 * Normalized view of what matters about a computer, built from GLPI rows.
 *
 * Shape (stored as JSON in the snapshot table):
 * [
 *   'serial'    => string,
 *   'name'      => string,
 *   'memory_mb' => int,                    // total installed memory
 *   'cpus'      => array<string, int>,     // designation => count
 *   'drives'    => array<string, string>,  // drive key (serial) => label
 *   'macs'      => string[],               // physical NIC MACs, sorted, lower-case
 *   'ips'       => string[],               // IPs bound to physical NICs, sorted
 * ]
 */
final class Snapshot
{
    /**
     * @param array{serial?: ?string, name?: ?string} $computer
     * @param array<int, array{size?: mixed}> $memories
     * @param array<int, array{designation?: ?string}> $processors
     * @param array<int, array{serial?: ?string, designation?: ?string, capacity?: mixed}> $drives
     * @param array<int, array{id?: mixed, name?: ?string, mac?: ?string}> $ports
     * @param array<int, array{ip?: ?string, port_name?: ?string}> $ips
     *
     * @return array<string, mixed>
     */
    public static function build(array $computer, array $memories, array $processors, array $drives, array $ports, array $ips, Settings $settings): array
    {
        $memory = 0;
        foreach ($memories as $row) {
            $memory += max(0, (int) ($row['size'] ?? 0));
        }

        $cpus = [];
        foreach ($processors as $row) {
            $label = self::clean($row['designation'] ?? '');
            if ($label === '') {
                $label = 'Unknown CPU';
            }
            $cpus[$label] = ($cpus[$label] ?? 0) + 1;
        }
        ksort($cpus);

        $driveMap = [];
        $anonymous = [];
        foreach ($drives as $row) {
            $label = self::clean($row['designation'] ?? '');
            $capacity = (int) ($row['capacity'] ?? 0);
            if ($capacity > 0) {
                $label = trim($label . ' (' . self::formatMb($capacity) . ')');
            }
            $serial = self::clean($row['serial'] ?? '');
            if ($serial !== '') {
                $driveMap['sn:' . $serial] = $label;
            } else {
                // Drives without serial: identify by label + rank among identical labels.
                $anonymous[$label] = ($anonymous[$label] ?? 0) + 1;
                $driveMap['nosn:' . $label . '#' . $anonymous[$label]] = $label;
            }
        }
        ksort($driveMap);

        $macs = [];
        foreach ($ports as $row) {
            $name = self::clean($row['name'] ?? '');
            if ($name !== '' && Wildcard::matchesAny($name, $settings->portExcludedPatterns)) {
                continue;
            }
            $mac = self::normalizeMac((string) ($row['mac'] ?? ''));
            if ($mac !== '') {
                $macs[$mac] = true;
            }
        }
        $macs = array_keys($macs);
        sort($macs);

        $ipList = [];
        foreach ($ips as $row) {
            $portName = self::clean($row['port_name'] ?? '');
            if ($portName !== '' && Wildcard::matchesAny($portName, $settings->portExcludedPatterns)) {
                continue;
            }
            $ip = strtolower(self::clean($row['ip'] ?? ''));
            if (self::isUsefulIp($ip)) {
                $ipList[$ip] = true;
            }
        }
        $ipList = array_keys($ipList);
        sort($ipList);

        return [
            'serial'    => self::clean($computer['serial'] ?? ''),
            'name'      => self::clean($computer['name'] ?? ''),
            'memory_mb' => $memory,
            'cpus'      => $cpus,
            'drives'    => $driveMap,
            'macs'      => $macs,
            'ips'       => $ipList,
        ];
    }

    /**
     * True when the inventory carried no hardware at all (partial inventory);
     * comparing it would produce a burst of false "removed" alerts.
     *
     * @param array<string, mixed> $snapshot
     */
    public static function hasHardware(array $snapshot): bool
    {
        return ($snapshot['memory_mb'] ?? 0) > 0 || !empty($snapshot['cpus']) || !empty($snapshot['drives']);
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function hasNetwork(array $snapshot): bool
    {
        return !empty($snapshot['macs']) || !empty($snapshot['ips']);
    }

    /**
     * Snapshot to store as the next baseline: sections missing from a partial
     * inventory keep their previous values.
     *
     * @param array<string, mixed>|null $old
     * @param array<string, mixed> $new
     *
     * @return array<string, mixed>
     */
    public static function mergeForStorage(?array $old, array $new): array
    {
        if ($old === null) {
            return $new;
        }
        if (!self::hasHardware($new)) {
            foreach (['memory_mb', 'cpus', 'drives'] as $key) {
                $new[$key] = $old[$key] ?? $new[$key];
            }
        }
        if (!self::hasNetwork($new)) {
            foreach (['macs', 'ips'] as $key) {
                $new[$key] = $old[$key] ?? $new[$key];
            }
        }
        return $new;
    }

    public static function normalizeMac(string $mac): string
    {
        $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $mac) ?? '');
        if (strlen($hex) !== 12 || $hex === '000000000000' || $hex === 'ffffffffffff') {
            return '';
        }
        return implode(':', str_split($hex, 2));
    }

    public static function isUsefulIp(string $ip): bool
    {
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($ip === '0.0.0.0' || $ip === '::' || $ip === '::1') {
            return false;
        }
        if (strpos($ip, '127.') === 0 || strpos($ip, '169.254.') === 0 || strpos($ip, 'fe80:') === 0) {
            return false;
        }
        return true;
    }

    public static function formatMb(int $mb): string
    {
        if ($mb >= 1024 * 1024) {
            return rtrim(rtrim(number_format($mb / 1024 / 1024, 1, '.', ''), '0'), '.') . ' TB';
        }
        if ($mb >= 1024) {
            return rtrim(rtrim(number_format($mb / 1024, 1, '.', ''), '0'), '.') . ' GB';
        }
        return $mb . ' MB';
    }

    /**
     * @param mixed $value
     */
    private static function clean($value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }
}
