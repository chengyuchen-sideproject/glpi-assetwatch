<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Assetwatch\Core;

/**
 * Immutable, validated view of the plugin configuration.
 *
 * Raw values come from GLPI's config table (all strings); this class has no
 * GLPI dependency so the rules can be unit tested in isolation.
 */
final class Settings
{
    /** @var array<string, string> */
    public const DEFAULTS = [
        'check_noreport'         => '1',
        'check_disk'             => '1',
        'check_hardware'         => '1',
        'check_identity'         => '1',
        'check_rack'             => '1',
        'noreport_default_hours' => '36',
        // JSON object: {"<computertypes_id>": hours}
        'noreport_type_hours'    => '{}',
        'disk_min_free_percent'  => '10',
        'disk_min_free_gb'       => '20',
        'disk_excluded_fs'       => 'tmpfs,devtmpfs,overlay,squashfs,iso9660,udf,ramfs,devfs,autofs,nsfs',
        'disk_excluded_mounts'   => '',
        'port_excluded_patterns' => 'lo,lo0,docker*,veth*,br-*,virbr*,vnet*,tun*,tap*,cni*,flannel*,cali*,vEthernet*,Loopback*,isatap*,Teredo*,*Pseudo-Interface*',
        'reminder_days'          => '3',
        'retention_days'         => '180',
        // Datacenter inventory: last report older than this = "probably removed"
        'retire_days'            => '30',
        // JSON object: {"<dcrooms_id>": "10.1.0.0/16,10.2.5.0/24"}
        'room_subnets'           => '{}',
    ];

    public bool $checkNoReport;
    public bool $checkDisk;
    public bool $checkHardware;
    public bool $checkIdentity;
    public bool $checkRack;
    public int $noReportDefaultHours;
    /** @var array<int, int> computertypes_id => hours */
    public array $noReportTypeHours;
    public float $diskMinFreePercent;
    public float $diskMinFreeGb;
    /** @var string[] lower-case filesystem names */
    public array $diskExcludedFs;
    /** @var string[] wildcard patterns */
    public array $diskExcludedMounts;
    /** @var string[] wildcard patterns */
    public array $portExcludedPatterns;
    public int $reminderDays;
    public int $retentionDays;
    public int $retireDays;
    /** @var array<int, string> dcrooms_id => network list as typed (comma separated) */
    public array $roomSubnets;

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        $v = $values + self::DEFAULTS;
        $s = new self();
        $s->checkNoReport        = self::toBool($v['check_noreport']);
        $s->checkDisk            = self::toBool($v['check_disk']);
        $s->checkHardware        = self::toBool($v['check_hardware']);
        $s->checkIdentity        = self::toBool($v['check_identity']);
        $s->checkRack            = self::toBool($v['check_rack']);
        $s->noReportDefaultHours = self::toInt($v['noreport_default_hours'], 1, 24 * 365, 36);
        $s->noReportTypeHours    = self::parseTypeHours((string) $v['noreport_type_hours']);
        $s->diskMinFreePercent   = self::toFloat($v['disk_min_free_percent'], 0, 100, 10);
        $s->diskMinFreeGb        = self::toFloat($v['disk_min_free_gb'], 0, 1000000, 20);
        $s->diskExcludedFs       = array_map('strtolower', self::splitList((string) $v['disk_excluded_fs']));
        $s->diskExcludedMounts   = self::splitList((string) $v['disk_excluded_mounts']);
        $s->portExcludedPatterns = self::splitList((string) $v['port_excluded_patterns']);
        $s->reminderDays         = self::toInt($v['reminder_days'], 0, 365, 3);
        $s->retentionDays        = self::toInt($v['retention_days'], 1, 3650, 180);
        $s->retireDays           = self::toInt($v['retire_days'], 1, 3650, 30);
        $s->roomSubnets          = self::parseRoomSubnets((string) $v['room_subnets']);
        return $s;
    }

    /**
     * Missing-inventory threshold (hours) for a computer type.
     */
    public function noReportHoursFor(int $computertypes_id): int
    {
        return $this->noReportTypeHours[$computertypes_id] ?? $this->noReportDefaultHours;
    }

    /**
     * Split a comma / newline separated list, trimming blanks.
     *
     * @return string[]
     */
    public static function splitList(string $raw): array
    {
        $parts = preg_split('/[,\r\n]+/', $raw) ?: [];
        $parts = array_map('trim', $parts);
        return array_values(array_filter($parts, static function (string $p): bool {
            return $p !== '';
        }));
    }

    /**
     * @return array<int, int>
     */
    public static function parseTypeHours(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach ($decoded as $type => $hours) {
            if (is_numeric($type) && is_numeric($hours) && (int) $type > 0 && (int) $hours > 0) {
                $result[(int) $type] = (int) $hours;
            }
        }
        return $result;
    }

    /**
     * @return array<int, string>
     */
    public static function parseRoomSubnets(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach ($decoded as $room => $list) {
            if (is_numeric($room) && (int) $room > 0 && is_string($list) && trim($list) !== '') {
                $result[(int) $room] = implode(',', self::splitList($list));
            }
        }
        return $result;
    }

    /**
     * Parsed networks per room (invalid entries are dropped; the form reports them).
     *
     * @return array<int, array<int, array{bytes: string, prefix: int, text: string}>>
     */
    public function roomNetworks(): array
    {
        $result = [];
        foreach ($this->roomSubnets as $room => $list) {
            $networks = Cidr::parseList($list)['networks'];
            if ($networks !== []) {
                $result[$room] = $networks;
            }
        }
        return $result;
    }

    /**
     * @param mixed $value
     */
    private static function toBool($value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param mixed $value
     */
    private static function toInt($value, int $min, int $max, int $fallback): int
    {
        if (!is_numeric($value)) {
            return $fallback;
        }
        return max($min, min($max, (int) $value));
    }

    /**
     * @param mixed $value
     */
    private static function toFloat($value, float $min, float $max, float $fallback): float
    {
        if (!is_numeric($value)) {
            return $fallback;
        }
        return max($min, min($max, (float) $value));
    }
}
