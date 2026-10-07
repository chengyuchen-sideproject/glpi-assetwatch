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
 * State machine for "condition" alerts (missing inventory, low disk).
 *
 * Given the alerts currently open in the database and the conditions found
 * by this run, decides what to open, remind, refresh and resolve:
 *
 *   (none) --condition--> ACTIVE --every N days--> reminder
 *   ACTIVE / ACKNOWLEDGED --condition gone--> RESOLVED
 *   ACKNOWLEDGED --ack period over--> ACTIVE (+ reminder)
 */
final class StatusTracker
{
    public const STATUS_ACTIVE       = 1;
    public const STATUS_ACKNOWLEDGED = 2;
    public const STATUS_RESOLVED     = 3;
    public const STATUS_EVENT        = 4;

    /**
     * @param array<int, array<string, mixed>> $openAlerts alerts with status ACTIVE or ACKNOWLEDGED; keys used:
     *        id, itemtype, items_id, alert_type, alert_key, status, date_last_notified, date_ack_until
     * @param array<int, array<string, mixed>> $findings current conditions; keys used:
     *        itemtype, items_id, alert_type, alert_key (+ anything the caller wants to carry)
     * @param string[] $types alert types evaluated by this run; open alerts of
     *        other types are left untouched (e.g. a check that was disabled)
     *
     * @return array{
     *     open: array<int, array<string, mixed>>,
     *     remind: array<int, array{alert: array<string, mixed>, finding: array<string, mixed>}>,
     *     refresh: array<int, array{alert: array<string, mixed>, finding: array<string, mixed>}>,
     *     resolve: array<int, array<string, mixed>>
     * }
     */
    public static function reconcile(array $openAlerts, array $findings, array $types, DateTimeImmutable $now, int $reminderDays): array
    {
        $result = ['open' => [], 'remind' => [], 'refresh' => [], 'resolve' => []];

        $indexed = [];
        foreach ($findings as $finding) {
            $indexed[self::key($finding)] = $finding;
        }

        $seen = [];
        foreach ($openAlerts as $alert) {
            if (!in_array((string) $alert['alert_type'], $types, true)) {
                continue;
            }
            $key = self::key($alert);
            if (isset($seen[$key])) {
                // Duplicate open alert for the same condition: close the extra one.
                $result['resolve'][] = $alert;
                continue;
            }
            $seen[$key] = true;

            if (!isset($indexed[$key])) {
                $result['resolve'][] = $alert;
                continue;
            }

            $finding = $indexed[$key];
            $pair = ['alert' => $alert, 'finding' => $finding];
            if (self::isReminderDue($alert, $now, $reminderDays)) {
                $result['remind'][] = $pair;
            } else {
                $result['refresh'][] = $pair;
            }
        }

        foreach ($indexed as $key => $finding) {
            if (!isset($seen[$key]) && in_array((string) $finding['alert_type'], $types, true)) {
                $result['open'][] = $finding;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $alert
     */
    public static function isReminderDue(array $alert, DateTimeImmutable $now, int $reminderDays): bool
    {
        $status = (int) $alert['status'];
        if ($status === self::STATUS_ACKNOWLEDGED) {
            $until = self::parse($alert['date_ack_until'] ?? null, $now);
            // Acknowledged without end date: silent until resolved.
            return $until !== null && $until <= $now;
        }
        if ($status !== self::STATUS_ACTIVE || $reminderDays <= 0) {
            return false;
        }
        $last = self::parse($alert['date_last_notified'] ?? null, $now);
        if ($last === null) {
            return true;
        }
        return $now->getTimestamp() - $last->getTimestamp() >= $reminderDays * 86400;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function key(array $row): string
    {
        return $row['itemtype'] . '#' . (int) $row['items_id'] . '#' . $row['alert_type'] . '#' . $row['alert_key'];
    }

    /**
     * @param mixed $value
     */
    private static function parse($value, DateTimeImmutable $now): ?DateTimeImmutable
    {
        if (!is_string($value) || $value === '' || strpos($value, '0000-00-00') === 0) {
            return null;
        }
        try {
            return new DateTimeImmutable($value, $now->getTimezone());
        } catch (Exception $e) {
            return null;
        }
    }
}
