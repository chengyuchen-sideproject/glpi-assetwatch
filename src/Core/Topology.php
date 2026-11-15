<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Assetwatch\Core;

/**
 * Which device is plugged into which network device ("switch"), from two sources:
 *  - auto:   port connections recorded by GLPI (SNMP network inventory, or ports connected by hand);
 *  - manual: the plugin's own table, for when only "server X is on switch Y" is known (port optional).
 * The same device / switch pair from both sources is one connection (the GLPI ports win).
 */
final class Topology
{
    public const SOURCE_AUTO   = 'auto';
    public const SOURCE_MANUAL = 'manual';

    /** Pseudo column of the devices without any connection. */
    public const NONE = '__none';

    // Layout (pixels)
    public const COL_W  = 240;
    public const BOX_W  = 210;
    public const SW_H   = 46;
    public const DEV_H  = 36;
    public const GAP    = 8;
    public const INDENT = 24;
    public const MARGIN = 16;

    /**
     * @param array<string, array<string, mixed>> $nodes key => node; a node needs "is_switch" (bool),
     *        and may have "sort" (string, order of the columns) and "expect_link" (bool: listed as
     *        unconnected when it has no link)
     * @param list<array{a: string, b: string, a_port?: string, b_port?: string, source: string, id?: int}> $links
     *        manual links: a = device, b = switch; auto links: any order
     *
     * @return array{
     *   switches: list<string>,
     *   devices: array<string, list<array{switch: string, device_port: string, switch_port: string, sources: list<string>, manual_ids: list<int>}>>,
     *   switch_links: list<array{a: string, b: string, a_port: string, b_port: string, sources: list<string>, manual_ids: list<int>}>,
     *   unconnected: list<string>,
     *   port_conflicts: list<array{switch: string, port: string, keys: list<string>}>,
     *   ignored: int
     * }
     */
    public static function build(array $nodes, array $links): array
    {
        $device_links = [];   // "device|switch" => link
        $switch_links = [];   // "a|b" (sorted) => link
        $ignored = 0;

        foreach ($links as $link) {
            $a = (string) $link['a'];
            $b = (string) $link['b'];
            if ($a === $b || !isset($nodes[$a], $nodes[$b])) {
                $ignored++;
                continue;
            }
            $a_port = trim((string) ($link['a_port'] ?? ''));
            $b_port = trim((string) ($link['b_port'] ?? ''));
            $source = $link['source'] === self::SOURCE_MANUAL ? self::SOURCE_MANUAL : self::SOURCE_AUTO;
            $manual_id = $source === self::SOURCE_MANUAL ? (int) ($link['id'] ?? 0) : 0;
            $a_switch = !empty($nodes[$a]['is_switch']);
            $b_switch = !empty($nodes[$b]['is_switch']);

            if ($a_switch && $b_switch) {
                if (strcmp($a, $b) > 0) {
                    [$a, $b, $a_port, $b_port] = [$b, $a, $b_port, $a_port];
                }
                $key = "$a|$b";
                $switch_links[$key] = self::merge($switch_links[$key] ?? null, [
                    'a' => $a, 'b' => $b, 'a_port' => $a_port, 'b_port' => $b_port,
                ], $source, $manual_id);
                continue;
            }
            if (!$a_switch && !$b_switch) {
                $ignored++;
                continue;
            }
            if ($a_switch) {
                [$a, $b, $a_port, $b_port] = [$b, $a, $b_port, $a_port];
            }
            // $a = device, $b = switch
            $key = "$a|$b";
            $device_links[$key] = self::merge($device_links[$key] ?? null, [
                'device' => $a, 'switch' => $b, 'device_port' => $a_port, 'switch_port' => $b_port,
            ], $source, $manual_id);
        }

        // Columns: every switch of the nodes, in "sort" order.
        $switches = array_keys(array_filter($nodes, static fn (array $n): bool => !empty($n['is_switch'])));
        usort($switches, static fn (string $x, string $y): int => strnatcasecmp((string) ($nodes[$x]['sort'] ?? $x), (string) ($nodes[$y]['sort'] ?? $y)) ?: strcmp($x, $y));
        $column = array_flip($switches);

        $devices = [];
        foreach ($device_links as $link) {
            $devices[$link['device']][] = [
                'switch'      => $link['switch'],
                'device_port' => $link['device_port'],
                'switch_port' => $link['switch_port'],
                'sources'     => $link['sources'],
                'manual_ids'  => $link['manual_ids'],
            ];
        }
        foreach ($devices as &$list) {
            // GLPI-recorded connections first, then column order.
            usort($list, static fn (array $x, array $y): int => (int) !in_array(self::SOURCE_AUTO, $x['sources'], true) <=> (int) !in_array(self::SOURCE_AUTO, $y['sources'], true)
                ?: ($column[$x['switch']] <=> $column[$y['switch']]));
        }
        unset($list);

        $linked = array_fill_keys(array_keys($devices), true);
        foreach ($device_links as $link) {
            $linked[$link['switch']] = true;
        }
        foreach ($switch_links as $link) {
            $linked[$link['a']] = $linked[$link['b']] = true;
        }
        $unconnected = [];
        foreach ($nodes as $key => $node) {
            if (!empty($node['expect_link']) && !isset($linked[$key])) {
                $unconnected[] = (string) $key;
            }
        }
        usort($unconnected, static fn (string $x, string $y): int => strnatcasecmp((string) ($nodes[$x]['sort'] ?? $x), (string) ($nodes[$y]['sort'] ?? $y)));

        // Same switch port claimed by several devices (only possible with manual entries or stale data).
        $ports = [];
        foreach ($device_links as $link) {
            if ($link['switch_port'] !== '') {
                $ports[$link['switch'] . '|' . mb_strtolower($link['switch_port'])][$link['device']] = [$link['switch'], $link['switch_port']];
            }
        }
        foreach ($switch_links as $link) {
            foreach ([[$link['a'], $link['a_port'], $link['b']], [$link['b'], $link['b_port'], $link['a']]] as [$sw, $port, $peer]) {
                if ($port !== '') {
                    $ports[$sw . '|' . mb_strtolower($port)][$peer] = [$sw, $port];
                }
            }
        }
        $conflicts = [];
        foreach ($ports as $by_device) {
            if (count($by_device) > 1) {
                $first = reset($by_device);
                $keys = array_map('strval', array_keys($by_device));
                sort($keys);
                $conflicts[] = ['switch' => $first[0], 'port' => $first[1], 'keys' => $keys];
            }
        }

        return [
            'switches'       => $switches,
            'devices'        => $devices,
            'switch_links'   => array_values($switch_links),
            'unconnected'    => $unconnected,
            'port_conflicts' => $conflicts,
            'ignored'        => $ignored,
        ];
    }

    /**
     * @param array<string, mixed>|null $current
     * @param array<string, string>     $new
     *
     * @return array<string, mixed>
     */
    private static function merge(?array $current, array $new, string $source, int $manual_id): array
    {
        if ($current === null) {
            $current = $new + ['sources' => [], 'manual_ids' => []];
        } elseif ($source === self::SOURCE_AUTO && !in_array(self::SOURCE_AUTO, $current['sources'], true)) {
            // GLPI ports are more reliable than what was typed by hand.
            $current = $new + ['sources' => $current['sources'], 'manual_ids' => $current['manual_ids']];
        } else {
            // Fill a missing port from the other entry.
            foreach ($new as $field => $value) {
                if (($current[$field] ?? '') === '' && $value !== '') {
                    $current[$field] = $value;
                }
            }
        }
        if (!in_array($source, $current['sources'], true)) {
            $current['sources'][] = $source;
            sort($current['sources']);
        }
        if ($manual_id > 0 && !in_array($manual_id, $current['manual_ids'], true)) {
            $current['manual_ids'][] = $manual_id;
        }
        return $current;
    }

    /**
     * Natural port order, unknown ports last.
     */
    public static function comparePorts(string $a, string $b): int
    {
        if (($a === '') !== ($b === '')) {
            return $a === '' ? 1 : -1;
        }
        return strnatcasecmp($a, $b);
    }

    /**
     * Drawing: one column per switch with its devices below it; a device connected to several
     * switches sits under the first one, with an extra line to the others; switch-to-switch
     * links are arcs above the switches; the devices without connection get a last column.
     *
     * @param array<string, mixed>                $graph result of build()
     * @param array<string, array<string, mixed>> $nodes same as build() (for the row order)
     *
     * @return array{width: int, height: int, top: int, columns: list<array{key: string, x: int}>,
     *   switch_boxes: array<string, array{x: int, y: int, w: int, h: int}>,
     *   device_boxes: list<array{key: string, column: string, x: int, y: int, w: int, h: int, port: string}>,
     *   edges: list<array{d: string, kind: string, manual: bool}>}
     */
    public static function layout(array $graph, array $nodes = []): array
    {
        $sort = static fn (string $key): string => (string) ($nodes[$key]['sort'] ?? $key);
        $columns = $graph['switches'];
        // An unconnected switch already has its own column.
        $lonely = array_values(array_diff($graph['unconnected'], $columns));
        if ($lonely !== []) {
            $columns[] = self::NONE;
        }
        $index = array_flip($columns);

        // Arcs: taller for switches further apart.
        $arc_h = [];
        $max_arc = 0;
        foreach ($graph['switch_links'] as $i => $link) {
            $dist = abs(($index[$link['a']] ?? 0) - ($index[$link['b']] ?? 0));
            $arc_h[$i] = min(120, 24 + 14 * $dist);
            $max_arc = max($max_arc, $arc_h[$i]);
        }
        $top = self::MARGIN + $max_arc;

        $switch_boxes = [];
        $col_x = [];
        foreach ($columns as $i => $key) {
            $x = self::MARGIN + $i * self::COL_W;
            $col_x[$key] = $x;
            if ($key !== self::NONE) {
                $switch_boxes[$key] = ['x' => $x, 'y' => $top, 'w' => self::BOX_W, 'h' => self::SW_H];
            }
        }

        // Rows under each column.
        $rows = array_fill_keys($columns, []);
        foreach ($graph['devices'] as $device => $links) {
            $rows[$links[0]['switch']][] = ['key' => $device, 'port' => $links[0]['switch_port'], 'others' => array_slice($links, 1)];
        }
        foreach ($lonely as $device) {
            $rows[self::NONE][] = ['key' => $device, 'port' => '', 'others' => []];
        }

        $device_boxes = [];
        $edges = [];
        $placed = [];
        $max_y = $top + self::SW_H;
        foreach ($rows as $column => $list) {
            // Port order when the ports are known, else rack order (the unconnected column keeps rack order).
            usort($list, static fn (array $x, array $y): int => self::comparePorts($x['port'], $y['port']) ?: strnatcasecmp($sort($x['key']), $sort($y['key'])));
            $x = $col_x[$column];
            $y = $top + self::SW_H + 2 * self::GAP;
            foreach ($list as $row) {
                $box = [
                    'key' => $row['key'], 'column' => $column,
                    'x' => $x + self::INDENT, 'y' => $y, 'w' => self::BOX_W - self::INDENT, 'h' => self::DEV_H,
                    'port' => $row['port'],
                ];
                $device_boxes[] = $box;
                $placed[$row['key']] = $box;
                if ($column !== self::NONE) {
                    $link = $graph['devices'][$row['key']][0];
                    $edges[] = [
                        'd'      => sprintf('M %d %d V %d H %d', $x + 12, $top + self::SW_H, $y + self::DEV_H / 2, $x + self::INDENT),
                        'kind'   => 'primary',
                        'manual' => !in_array(self::SOURCE_AUTO, $link['sources'], true),
                    ];
                }
                $y += self::DEV_H + self::GAP;
                $max_y = max($max_y, $y);
            }
        }

        // Extra uplinks of a device: from the other switch to the right side of the device box.
        foreach ($rows as $list) {
            foreach ($list as $row) {
                foreach ($row['others'] as $link) {
                    $sw = $switch_boxes[$link['switch']];
                    $box = $placed[$row['key']];
                    $sx = $sw['x'] + (int) ($sw['w'] / 2);
                    $sy = $sw['y'] + $sw['h'];
                    $dx = $box['x'] + $box['w'];
                    $dy = $box['y'] + (int) ($box['h'] / 2);
                    $edges[] = [
                        'd'      => sprintf('M %d %d C %d %d, %d %d, %d %d', $sx, $sy, $sx, (int) (($sy + $dy) / 2), $dx + 40, $dy, $dx, $dy),
                        'kind'   => 'secondary',
                        'manual' => !in_array(self::SOURCE_AUTO, $link['sources'], true),
                    ];
                }
            }
        }

        foreach ($graph['switch_links'] as $i => $link) {
            $a = $switch_boxes[$link['a']];
            $b = $switch_boxes[$link['b']];
            $ax = $a['x'] + (int) ($a['w'] / 2) + 10;
            $bx = $b['x'] + (int) ($b['w'] / 2) - 10;
            $edges[] = [
                'd'      => sprintf('M %d %d C %d %d, %d %d, %d %d', $ax, $top, $ax, $top - $arc_h[$i], $bx, $top - $arc_h[$i], $bx, $top),
                'kind'   => 'switch',
                'manual' => !in_array(self::SOURCE_AUTO, $link['sources'], true),
            ];
        }

        return [
            'width'        => self::MARGIN * 2 + max(1, count($columns)) * self::COL_W - (self::COL_W - self::BOX_W),
            'height'       => $max_y + self::MARGIN,
            'top'          => $top,
            'columns'      => array_map(static fn (string $k): array => ['key' => $k, 'x' => $col_x[$k]], $columns),
            'switch_boxes' => $switch_boxes,
            'device_boxes' => $device_boxes,
            'edges'        => $edges,
        ];
    }
}
