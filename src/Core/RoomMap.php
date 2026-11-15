<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Assetwatch\Core;

/**
 * Top view of a server room: racks on the room grid (GLPI rack field "position" = "x,y",
 * 1-based, origin top left) coloured by one metric.
 */
final class RoomMap
{
    public const LEVEL_OK   = 'ok';
    public const LEVEL_WARN = 'warn';
    public const LEVEL_BAD  = 'bad';
    /** Metric not available (e.g. no maximum power set on the rack). */
    public const LEVEL_NONE = 'none';

    public const METRIC_ISSUES = 'issues';
    public const METRIC_UNITS  = 'units';
    public const METRIC_POWER  = 'power';

    /** Rack space used: warn from 70 %, bad from 90 %. */
    public const UNITS_WARN = 70;
    public const UNITS_BAD  = 90;
    /** Power used vs rack maximum: warn from 60 %, bad from 80 % (usual breaker margin). */
    public const POWER_WARN = 60;
    public const POWER_BAD  = 80;
    /** Assets to check on site: 0 ok, 1-2 warn, 3+ bad. */
    public const ISSUES_BAD = 3;

    /**
     * @return array{x: int, y: int}|null
     */
    public static function parsePosition(?string $position): ?array
    {
        if ($position === null || !preg_match('/^\s*(\d+)\s*,\s*(\d+)\s*$/', $position, $m)) {
            return null;
        }
        $x = (int) $m[1];
        $y = (int) $m[2];
        return $x >= 1 && $y >= 1 ? ['x' => $x, 'y' => $y] : null;
    }

    /**
     * Place racks on the grid. The grid grows to hold every placed rack, so a rack is never
     * hidden because the room size was set too small, and stops one cell after the last rack
     * (an empty end of the room only makes the map longer); two racks on the same cell: the
     * first one (in the given order) keeps it, the other is listed as unplaced.
     *
     * @param array<int, string|null> $positions rack id => "x,y"
     *
     * @return array{cols: int, rows: int, placed: array<int, array{x: int, y: int}>, unplaced: list<int>}
     */
    public static function grid(int $cols, int $rows, array $positions): array
    {
        $placed = [];
        $unplaced = [];
        $taken = [];
        foreach ($positions as $id => $position) {
            $cell = self::parsePosition($position);
            if ($cell === null || isset($taken[$cell['x'] . ',' . $cell['y']])) {
                $unplaced[] = (int) $id;
                continue;
            }
            $taken[$cell['x'] . ',' . $cell['y']] = true;
            $placed[(int) $id] = $cell;
        }
        if ($placed !== []) {
            $max_x = max(array_column($placed, 'x'));
            $max_y = max(array_column($placed, 'y'));
            $cols = max($max_x, min($cols, $max_x + 1));
            $rows = max($max_y, min($rows, $max_y + 1));
        }
        return ['cols' => max(1, $cols), 'rows' => max(1, $rows), 'placed' => $placed, 'unplaced' => $unplaced];
    }

    public static function issuesLevel(int $count): string
    {
        if ($count <= 0) {
            return self::LEVEL_OK;
        }
        return $count >= self::ISSUES_BAD ? self::LEVEL_BAD : self::LEVEL_WARN;
    }

    public static function percent(int|float $value, int|float $max): ?int
    {
        return $max > 0 ? (int) round(100 * $value / $max) : null;
    }

    public static function percentLevel(?int $percent, int $warn, int $bad): string
    {
        if ($percent === null) {
            return self::LEVEL_NONE;
        }
        if ($percent >= $bad) {
            return self::LEVEL_BAD;
        }
        return $percent >= $warn ? self::LEVEL_WARN : self::LEVEL_OK;
    }

    /**
     * Metrics of one rack.
     *
     * @return array{issues: int, units_pct: int|null, power_pct: int|null, levels: array<string, string>}
     */
    public static function metrics(int $issues, int $used_units, int $number_units, int $power, int $max_power): array
    {
        $units_pct = self::percent($used_units, $number_units);
        $power_pct = self::percent($power, $max_power);
        return [
            'issues'    => $issues,
            'units_pct' => $units_pct,
            'power_pct' => $power_pct,
            'levels'    => [
                self::METRIC_ISSUES => self::issuesLevel($issues),
                self::METRIC_UNITS  => self::percentLevel($units_pct, self::UNITS_WARN, self::UNITS_BAD),
                self::METRIC_POWER  => self::percentLevel($power_pct, self::POWER_WARN, self::POWER_BAD),
            ],
        ];
    }
}
