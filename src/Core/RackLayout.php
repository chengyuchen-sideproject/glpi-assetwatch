<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Assetwatch\Core;

/**
 * Front view of a rack as vertical blocks, top U first (the order seen when standing in
 * front of the rack). Consecutive units holding the same set of assets form one block:
 * a 2U server is one block of 2 units, two half-rack devices side by side share a block,
 * and a run of empty units is one empty block.
 */
final class RackLayout
{
    /**
     * @param int $number_units height of the rack
     * @param array<int, array{key: string, position: int, units: int}> $items
     *        position = lowest unit used (GLPI convention), units = height in U (>= 1)
     *
     * @return array{blocks: list<array{top: int, bottom: int, units: int, keys: list<string>}>, used_units: int, outside: list<string>}
     *         outside = keys of the assets reaching beyond the rack (still drawn on the units that exist)
     */
    public static function blocks(int $number_units, array $items): array
    {
        $number_units = max(0, $number_units);
        $occupied = [];
        $outside = [];
        foreach ($items as $item) {
            $units = max(1, (int) $item['units']);
            $bottom = (int) $item['position'];
            $top = $bottom + $units - 1;
            if ($bottom < 1 || $top > $number_units) {
                $outside[] = $item['key'];
            }
            for ($u = max(1, $bottom); $u <= min($number_units, $top); $u++) {
                if (!in_array($item['key'], $occupied[$u] ?? [], true)) {
                    $occupied[$u][] = $item['key'];
                }
            }
        }

        $blocks = [];
        for ($u = $number_units; $u >= 1; $u--) {
            $keys = $occupied[$u] ?? [];
            $last = array_key_last($blocks);
            if ($last !== null && $blocks[$last]['keys'] === $keys) {
                $blocks[$last]['bottom'] = $u;
                $blocks[$last]['units']++;
                continue;
            }
            $blocks[] = ['top' => $u, 'bottom' => $u, 'units' => 1, 'keys' => $keys];
        }

        return ['blocks' => $blocks, 'used_units' => count($occupied), 'outside' => $outside];
    }
}
