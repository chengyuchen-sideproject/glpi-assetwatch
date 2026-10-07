<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Assetwatch\Core;

/**
 * Describes a rack placement change of one item.
 *
 * A placement is ['rack' => string label, 'position' => int, 'orientation' => int, 'hpos' => int]
 * or null when the item is not in a rack.
 */
final class RackChange
{
    public const ACTION_ADDED   = 'added';
    public const ACTION_MOVED   = 'moved';
    public const ACTION_REMOVED = 'removed';

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     *
     * @return array{action: string, old: string, new: string}|null null when nothing relevant changed
     */
    public static function describe(?array $before, ?array $after): ?array
    {
        if ($before === null && $after === null) {
            return null;
        }
        if ($before === null) {
            return ['action' => self::ACTION_ADDED, 'old' => '', 'new' => self::label($after)];
        }
        if ($after === null) {
            return ['action' => self::ACTION_REMOVED, 'old' => self::label($before), 'new' => ''];
        }
        if (self::label($before) === self::label($after)) {
            return null;
        }
        return ['action' => self::ACTION_MOVED, 'old' => self::label($before), 'new' => self::label($after)];
    }

    /**
     * Human readable placement, e.g. "R01 / U12 (rear)".
     *
     * @param array<string, mixed> $placement
     */
    public static function label(array $placement): string
    {
        $label = trim((string) ($placement['rack'] ?? ''));
        $label .= ' / U' . (int) ($placement['position'] ?? 0);
        $details = [];
        if ((int) ($placement['orientation'] ?? 0) === 1) {
            $details[] = 'rear';
        }
        $hpos = (int) ($placement['hpos'] ?? 0);
        if ($hpos === 1) {
            $details[] = 'left';
        } elseif ($hpos === 2) {
            $details[] = 'right';
        }
        if ($details !== []) {
            $label .= ' (' . implode(', ', $details) . ')';
        }
        return $label;
    }
}
