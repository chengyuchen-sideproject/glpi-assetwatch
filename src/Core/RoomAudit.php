<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Assetwatch\Core;

use DateTimeImmutable;
use Exception;

/**
 * Datacenter inventory ("機房盤點"): status of each asset of a room and the discrepancies to check
 * on site. Pure functions: the caller passes rows read from GLPI.
 *
 * Item shape:
 * [
 *   'itemtype'          => string,        // GLPI class (Computer, NetworkEquipment, PDU...)
 *   'racked'            => bool,          // placed in a rack of the room (false = matched by location)
 *   'inventoried'       => bool,          // is_dynamic and last_inventory_update set
 *   'last_inventory'    => ?string,       // Y-m-d H:i:s
 *   'computertypes_id'  => int,
 *   'disk_low'          => bool,          // open low disk space alert
 *   'tech_groups'       => string[],
 *   'ips'               => string[],
 *   'serial_duplicates' => int,           // other non-deleted assets of the same type with this serial
 * ]
 */
final class RoomAudit
{
    public const STATUS_OK       = 'ok';
    public const STATUS_DISK_LOW = 'disk_low';
    public const STATUS_OFFLINE  = 'offline';
    public const STATUS_RETIRED  = 'retired';
    public const STATUS_NO_AGENT = 'no_agent';

    public const STATUSES = [
        self::STATUS_OK,
        self::STATUS_DISK_LOW,
        self::STATUS_OFFLINE,
        self::STATUS_RETIRED,
        self::STATUS_NO_AGENT,
    ];

    /** In a rack but GLPI Agent never reported it (computers only). */
    public const ISSUE_NEVER_REPORTED = 'never_reported';
    /** Last report older than the "probably removed" threshold. */
    public const ISSUE_RETIRED = 'retired';
    /** Reporting computer of this room (by location) that is in no rack. */
    public const ISSUE_UNRACKED = 'unracked';
    /** Same serial number on several assets of the same type. */
    public const ISSUE_DUPLICATE_SERIAL = 'duplicate_serial';
    /** Computer without technical group: nobody receives its alerts. */
    public const ISSUE_NO_TECH_GROUP = 'no_tech_group';
    /** None of its addresses is in the networks configured for the room. */
    public const ISSUE_SUBNET_MISMATCH = 'subnet_mismatch';

    public const ISSUES = [
        self::ISSUE_NEVER_REPORTED,
        self::ISSUE_RETIRED,
        self::ISSUE_UNRACKED,
        self::ISSUE_DUPLICATE_SERIAL,
        self::ISSUE_NO_TECH_GROUP,
        self::ISSUE_SUBNET_MISMATCH,
    ];

    /** Asset types whose addresses are compared with the room networks. */
    private const IP_CHECKED_TYPES = ['Computer', 'NetworkEquipment'];

    /**
     * @param array<string, mixed> $item
     */
    public static function status(array $item, DateTimeImmutable $now, Settings $settings): string
    {
        $last = $item['last_inventory'] ?? null;
        if (empty($item['inventoried']) || $last === null || $last === '' || str_starts_with((string) $last, '0000-00-00')) {
            return self::STATUS_NO_AGENT;
        }
        $hours = self::hoursSince((string) $last, $now);
        if ($hours !== null && $hours >= $settings->retireDays * 24) {
            return self::STATUS_RETIRED;
        }
        $types_id = ($item['itemtype'] ?? '') === 'Computer' ? (int) ($item['computertypes_id'] ?? 0) : 0;
        if (NoReportRule::evaluate((string) $last, $types_id, $now, $settings) !== null) {
            return self::STATUS_OFFLINE;
        }
        if (!empty($item['disk_low'])) {
            return self::STATUS_DISK_LOW;
        }
        return self::STATUS_OK;
    }

    /**
     * @param array<string, mixed> $item
     * @param array<int, array{bytes: string, prefix: int, text: string}> $room_networks empty = not checked
     *
     * @return array<string, string> issue => detail (may be empty)
     */
    public static function issues(array $item, string $status, array $room_networks): array
    {
        $itemtype = (string) ($item['itemtype'] ?? '');
        $is_computer = $itemtype === 'Computer';
        $racked = !empty($item['racked']);
        $issues = [];

        if ($is_computer && $racked && $status === self::STATUS_NO_AGENT) {
            $issues[self::ISSUE_NEVER_REPORTED] = '';
        }
        if ($status === self::STATUS_RETIRED) {
            $issues[self::ISSUE_RETIRED] = (string) ($item['last_inventory'] ?? '');
        }
        if ($is_computer && !$racked) {
            $issues[self::ISSUE_UNRACKED] = '';
        }
        if ((int) ($item['serial_duplicates'] ?? 0) > 0) {
            $issues[self::ISSUE_DUPLICATE_SERIAL] = (string) (int) $item['serial_duplicates'];
        }
        if ($is_computer && empty($item['tech_groups'])) {
            $issues[self::ISSUE_NO_TECH_GROUP] = '';
        }
        if ($room_networks !== [] && in_array($itemtype, self::IP_CHECKED_TYPES, true)) {
            $ips = array_values(array_filter(
                array_map('strval', (array) ($item['ips'] ?? [])),
                static fn (string $ip): bool => !Cidr::isIgnorable($ip)
            ));
            $outside = array_values(array_filter($ips, static fn (string $ip): bool => !Cidr::containsAny($room_networks, $ip)));
            if ($ips !== [] && count($outside) === count($ips)) {
                $issues[self::ISSUE_SUBNET_MISMATCH] = implode(', ', $outside);
            }
        }
        return $issues;
    }

    /**
     * Evaluate a list of items in place: adds 'status' and 'issues' to every row.
     *
     * @param array<int, array<string, mixed>> $items each with a 'dcrooms_id' key
     * @param array<int, array<int, array{bytes: string, prefix: int, text: string}>> $networks_by_room
     *
     * @return array{items: array<int, array<string, mixed>>, status_counts: array<string, int>, issue_counts: array<string, int>, items_with_issues: int}
     */
    public static function evaluate(array $items, array $networks_by_room, DateTimeImmutable $now, Settings $settings): array
    {
        $status_counts = array_fill_keys(self::STATUSES, 0);
        $issue_counts = array_fill_keys(self::ISSUES, 0);
        $with_issues = 0;
        foreach ($items as $i => $item) {
            $status = self::status($item, $now, $settings);
            $issues = self::issues($item, $status, $networks_by_room[(int) ($item['dcrooms_id'] ?? 0)] ?? []);
            $items[$i]['status'] = $status;
            $items[$i]['issues'] = $issues;
            $status_counts[$status]++;
            foreach (array_keys($issues) as $issue) {
                $issue_counts[$issue]++;
            }
            if ($issues !== []) {
                $with_issues++;
            }
        }
        return [
            'items'             => $items,
            'status_counts'     => $status_counts,
            'issue_counts'      => $issue_counts,
            'items_with_issues' => $with_issues,
        ];
    }

    private static function hoursSince(string $date, DateTimeImmutable $now): ?int
    {
        try {
            $then = new DateTimeImmutable($date, $now->getTimezone());
        } catch (Exception $e) {
            return null;
        }
        return intdiv($now->getTimestamp() - $then->getTimestamp(), 3600);
    }
}
