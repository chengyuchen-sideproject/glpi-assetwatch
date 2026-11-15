<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Assetwatch\Core;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Rules on data the GLPI Agent already sends with each inventory:
 * unexpected reboot, antivirus state, agent version, disk usage trend.
 */
final class HealthRules
{
    /** A new boot time this much later than the previous one counts as a reboot (clock jitter). */
    public const REBOOT_MIN_SECONDS = 120;
    /** Disk trend: samples of the last N days, at least M samples spread over at least 1 day. */
    public const FORECAST_WINDOW_DAYS = 30;
    public const FORECAST_MIN_SAMPLES = 3;

    // ------------------------------------------------------------------
    // Reboot
    // ------------------------------------------------------------------

    /**
     * @return array{previous_boot: string, last_boot: string}|null null = no reboot, or one in quiet hours
     */
    public static function reboot(?string $previous_boot, ?string $last_boot, string $quiet_hours, ?DateTimeZone $tz = null): ?array
    {
        $old = self::date($previous_boot, $tz);
        $new = self::date($last_boot, $tz);
        if ($old === null || $new === null || $new->getTimestamp() - $old->getTimestamp() < self::REBOOT_MIN_SECONDS) {
            return null;
        }
        if (self::inQuietHours($new, $quiet_hours)) {
            return null;
        }
        return ['previous_boot' => $old->format('Y-m-d H:i:s'), 'last_boot' => $new->format('Y-m-d H:i:s')];
    }

    /**
     * "02:00-06:00, 22:30-23:30": daily windows (a window may cross midnight). Invalid parts are ignored.
     *
     * @return list<array{0: int, 1: int}> minutes since midnight [start, end)
     */
    public static function parseQuietHours(string $text): array
    {
        $windows = [];
        foreach (Settings::splitList(str_replace(';', ',', $text)) as $part) {
            if (preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $part, $m)
                && (int) $m[1] < 24 && (int) $m[2] < 60 && (int) $m[3] <= 24 && (int) $m[4] < 60) {
                $start = (int) $m[1] * 60 + (int) $m[2];
                $end = min(1440, (int) $m[3] * 60 + (int) $m[4]);
                if ($start !== $end) {
                    $windows[] = [$start, $end];
                }
            }
        }
        return $windows;
    }

    public static function inQuietHours(DateTimeImmutable $when, string $quiet_hours): bool
    {
        $minute = (int) $when->format('G') * 60 + (int) $when->format('i');
        foreach (self::parseQuietHours($quiet_hours) as [$start, $end]) {
            $inside = $start < $end ? ($minute >= $start && $minute < $end) : ($minute >= $start || $minute < $end);
            if ($inside) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Antivirus
    // ------------------------------------------------------------------

    /**
     * @param array<int, array{name?: string, is_active?: mixed, is_uptodate?: mixed, date_expiration?: string|null, antivirus_version?: string|null, signature_version?: string|null}> $antiviruses
     *
     * @return array<string, array{name: string, problems: list<string>, version: string, signature: string, expiration: string}>
     *         keyed by antivirus name; problems: disabled, outdated, expired
     */
    public static function antivirus(array $antiviruses, DateTimeImmutable $now): array
    {
        $findings = [];
        foreach ($antiviruses as $av) {
            $name = trim((string) ($av['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $problems = [];
            if (empty($av['is_active'])) {
                $problems[] = 'disabled';
            }
            if (empty($av['is_uptodate'])) {
                $problems[] = 'outdated';
            }
            $expiration = self::date($av['date_expiration'] ?? null, $now->getTimezone());
            if ($expiration !== null && $expiration < $now) {
                $problems[] = 'expired';
            }
            if ($problems !== []) {
                $findings[$name] = [
                    'name'       => $name,
                    'problems'   => $problems,
                    'version'    => (string) ($av['antivirus_version'] ?? ''),
                    'signature'  => (string) ($av['signature_version'] ?? ''),
                    'expiration' => $expiration !== null ? $expiration->format('Y-m-d') : '',
                ];
            }
        }
        ksort($findings);
        return $findings;
    }

    // ------------------------------------------------------------------
    // Agent version
    // ------------------------------------------------------------------

    /**
     * "GLPI-Agent_v1.11-1", "1.11", "v1.7.3" -> "1.11" / "1.7.3"; '' when no version found.
     */
    public static function normalizeVersion(string $raw): string
    {
        return preg_match('/(\d+(?:\.\d+)+|\d+)/', $raw, $m) ? $m[1] : '';
    }

    public static function isAgentOutdated(string $version, string $minimum): bool
    {
        $version = self::normalizeVersion($version);
        $minimum = self::normalizeVersion($minimum);
        return $version !== '' && $minimum !== '' && version_compare($version, $minimum, '<');
    }

    // ------------------------------------------------------------------
    // Disk trend
    // ------------------------------------------------------------------

    /**
     * Free space threshold in GB of a partition: it is "low" once below both limits.
     */
    public static function lowThresholdGb(float $total_gb, float $min_percent, float $min_gb): float
    {
        return min($total_gb * $min_percent / 100, $min_gb);
    }

    /**
     * Days before the partition goes below the low-space threshold, from a linear fit of the
     * free space over the last days.
     *
     * @param list<array{date: string, free_gb: float}> $samples
     *
     * @return array{days: int, gb_per_day: float, free_gb: float, threshold_gb: float, samples: int}|null
     *         null: not enough data, not shrinking, already low, or later than $horizon_days
     */
    public static function diskForecast(array $samples, float $threshold_gb, DateTimeImmutable $now, int $horizon_days): ?array
    {
        $from = $now->getTimestamp() - self::FORECAST_WINDOW_DAYS * 86400;
        $points = [];
        foreach ($samples as $sample) {
            $date = self::date($sample['date'], $now->getTimezone());
            if ($date !== null && $date->getTimestamp() >= $from && $date <= $now) {
                $points[] = [($date->getTimestamp() - $from) / 86400, (float) $sample['free_gb']];
            }
        }
        usort($points, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $n = count($points);
        if ($n < self::FORECAST_MIN_SAMPLES || $points[$n - 1][0] - $points[0][0] < 1) {
            return null;
        }

        $mean_x = array_sum(array_column($points, 0)) / $n;
        $mean_y = array_sum(array_column($points, 1)) / $n;
        $sxx = $sxy = 0.0;
        foreach ($points as [$x, $y]) {
            $sxx += ($x - $mean_x) ** 2;
            $sxy += ($x - $mean_x) * ($y - $mean_y);
        }
        $slope = $sxx > 0 ? $sxy / $sxx : 0.0;   // GB per day
        $free = $points[$n - 1][1];
        if ($slope >= -0.01 || $free <= $threshold_gb) {
            return null;
        }
        $days = (int) floor(($free - $threshold_gb) / -$slope);
        if ($days > $horizon_days) {
            return null;
        }
        return [
            'days'         => $days,
            'gb_per_day'   => round(-$slope, 2),
            'free_gb'      => round($free, 1),
            'threshold_gb' => round($threshold_gb, 1),
            'samples'      => $n,
        ];
    }

    private static function date(?string $value, ?DateTimeZone $tz): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        try {
            return new DateTimeImmutable($value, $tz);
        } catch (Exception $e) {
            return null;
        }
    }
}
