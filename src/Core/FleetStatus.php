<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Assetwatch\Core;

use DateInterval;
use DateTimeImmutable;

/**
 * Numbers shown on the dashboard cards. Pure functions: callers pass rows read from GLPI.
 */
final class FleetStatus
{
    /**
     * Classify monitored computers.
     *
     * "Offline" uses the same rule as the alerts (NoReportRule, per computer type threshold), computed
     * live so the card is right even between two hourly checks. Computers that never sent an
     * inventory are not offline (they are not monitored yet), as for the alerts.
     *
     * @param array<int, array{last_inventory_update: ?string, computertypes_id: int|string}> $computers keyed by id
     * @param int[] $disk_low_ids computers with an open low disk space alert
     *
     * @return array{total: int, offline: int, disk_low: int, healthy: int, offline_ids: int[]}
     */
    public static function summarize(array $computers, array $disk_low_ids, DateTimeImmutable $now, Settings $settings): array
    {
        $offline = [];
        foreach ($computers as $id => $computer) {
            $finding = NoReportRule::evaluate(
                $computer['last_inventory_update'] ?? null,
                (int) ($computer['computertypes_id'] ?? 0),
                $now,
                $settings
            );
            if ($finding !== null) {
                $offline[] = (int) $id;
            }
        }

        // Only count low disk alerts of computers still in the monitored set
        $disk_low = array_values(array_intersect(array_map('intval', $disk_low_ids), array_map('intval', array_keys($computers))));
        $disk_low = array_values(array_unique($disk_low));
        $problems = array_unique(array_merge($offline, $disk_low));

        return [
            'total'       => count($computers),
            'offline'     => count($offline),
            'disk_low'    => count($disk_low),
            'healthy'     => count($computers) - count($problems),
            'offline_ids' => $offline,
        ];
    }

    /**
     * Number of events per day over the last $days days (today included), oldest first.
     *
     * @param string[] $dates event dates ("Y-m-d H:i:s")
     *
     * @return array<string, int> "Y-m-d" => count, one key per day even when 0
     */
    public static function perDay(array $dates, DateTimeImmutable $now, int $days): array
    {
        $days = max(1, $days);
        $result = [];
        $day = $now->setTime(0, 0)->sub(new DateInterval('P' . ($days - 1) . 'D'));
        for ($i = 0; $i < $days; $i++) {
            $result[$day->format('Y-m-d')] = 0;
            $day = $day->add(new DateInterval('P1D'));
        }
        foreach ($dates as $date) {
            $key = substr((string) $date, 0, 10);
            if (isset($result[$key])) {
                $result[$key]++;
            }
        }
        return $result;
    }
}
