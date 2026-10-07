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
 * "The machine has not sent an inventory for too long."
 */
final class NoReportRule
{
    /**
     * @param string|null $lastInventory GLPI datetime (Y-m-d H:i:s), null if never inventoried
     *
     * @return array{last_inventory: string, hours_since: int, threshold_hours: int}|null
     *         finding, or null when the machine is fine (or was never inventoried)
     */
    public static function evaluate(?string $lastInventory, int $computertypes_id, DateTimeImmutable $now, Settings $settings): ?array
    {
        if ($lastInventory === null || $lastInventory === '' || strpos($lastInventory, '0000-00-00') === 0) {
            return null;
        }
        try {
            $last = new DateTimeImmutable($lastInventory, $now->getTimezone());
        } catch (Exception $e) {
            return null;
        }

        $threshold = $settings->noReportHoursFor($computertypes_id);
        $hours = intdiv($now->getTimestamp() - $last->getTimestamp(), 3600);
        if ($hours < $threshold) {
            return null;
        }
        return [
            'last_inventory'  => $last->format('Y-m-d H:i:s'),
            'hours_since'     => $hours,
            'threshold_hours' => $threshold,
        ];
    }
}
