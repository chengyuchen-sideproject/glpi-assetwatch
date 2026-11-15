<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetwatch\Core\Topology;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Network topology page (Tools > Network topology): which device is plugged into which
 * network device of which rack, for a server room / datacenter. Connections come from the
 * ports connected in GLPI (SNMP network inventory) and from the plugin's own table
 * (PluginAssetwatchUplink, typed in the "Edit connections" mode of the page).
 */
class PluginAssetwatchTopology extends CommonGLPI
{
    public static $rightname = 'plugin_assetwatch_alert';

    public static function getTypeName($nb = 0)
    {
        return __('Network topology', 'assetwatch');
    }

    public static function getMenuName()
    {
        return self::getTypeName();
    }

    public static function getIcon()
    {
        return 'ti ti-topology-star-3';
    }

    public static function getSearchURL($full = true)
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/assetwatch/front/topology.php';
    }

    // ------------------------------------------------------------------
    // Data
    // ------------------------------------------------------------------

    /**
     * @param array{room_ids: int[], unassigned: bool} $scope
     *
     * @return array<string, mixed>
     */
    public static function data(array $scope): array
    {
        $result = PluginAssetwatchAudit::collect($scope['room_ids'], $scope['unassigned']);

        $nodes = [];
        foreach ($result['items'] as $item) {
            if (in_array($item['itemtype'], PluginAssetwatchUplink::LINKABLE_TYPES, true)) {
                $nodes[$item['itemtype'] . ':' . $item['id']] = self::node($item, false);
            }
        }

        $ids = [];
        foreach ($nodes as $node) {
            $ids[$node['itemtype']][] = $node['id'];
        }
        $links = array_merge(self::autoLinks($ids), self::manualLinks($ids));

        // Far ends outside the scope (switch of another room, device of another room).
        $missing = [];
        foreach ($links as $link) {
            foreach (['a', 'b'] as $end) {
                if (!isset($nodes[$link[$end]])) {
                    [$type, $id] = explode(':', $link[$end], 2);
                    $missing[$type][(int) $id] = true;
                }
            }
        }
        $nodes += self::externalNodes($missing);

        $graph = Topology::build($nodes, $links);
        $layout = Topology::layout($graph, $nodes);

        // Text of the boxes (cut to the box width; the full text is in the tooltip).
        $count_on = [];
        foreach ($graph['devices'] as $links_of_device) {
            foreach ($links_of_device as $link) {
                $count_on[$link['switch']] = ($count_on[$link['switch']] ?? 0) + 1;
            }
        }
        foreach ($layout['switch_boxes'] as $key => &$box) {
            $box['line1'] = self::cut($nodes[$key]['name'], 28);
            $box['line2'] = self::cut(($nodes[$key]['where'] !== '' ? $nodes[$key]['where'] . ' · ' : '') . sprintf(__('%d device(s)', 'assetwatch'), $count_on[$key] ?? 0), 34);
        }
        unset($box);
        foreach ($layout['device_boxes'] as &$box) {
            $node = $nodes[$box['key']];
            $box['line1'] = self::cut($node['name'], 24);
            $box['line2'] = self::cut(trim(($node['place'] !== '' ? $node['place'] : $node['where']) . ($box['port'] !== '' ? ' · ' . $box['port'] : ''), ' ·'), 28);
        }
        unset($box);

        // Connections of each asset, for the tables and the edit form.
        $node_links = [];
        foreach ($graph['devices'] as $device => $links_of_device) {
            foreach ($links_of_device as $link) {
                $node_links[$device][] = ['peer' => $link['switch'], 'my_port' => $link['device_port'], 'peer_port' => $link['switch_port'],
                    'sources' => $link['sources'], 'manual_ids' => $link['manual_ids']];
            }
        }
        foreach ($graph['switch_links'] as $link) {
            $node_links[$link['a']][] = ['peer' => $link['b'], 'my_port' => $link['a_port'], 'peer_port' => $link['b_port'],
                'sources' => $link['sources'], 'manual_ids' => $link['manual_ids']];
            $node_links[$link['b']][] = ['peer' => $link['a'], 'my_port' => $link['b_port'], 'peer_port' => $link['a_port'],
                'sources' => $link['sources'], 'manual_ids' => $link['manual_ids']];
        }
        $on_switch = [];
        foreach ($graph['devices'] as $device => $links_of_device) {
            foreach ($links_of_device as $link) {
                $on_switch[$link['switch']][] = ['device' => $device] + $link;
            }
        }
        foreach ($on_switch as &$list) {
            usort($list, static fn (array $x, array $y): int => Topology::comparePorts($x['switch_port'], $y['switch_port']) ?: strnatcasecmp($nodes[$x['device']]['sort'], $nodes[$y['device']]['sort']));
        }
        unset($list);
        $ordered = $nodes;
        uasort($ordered, static fn (array $x, array $y): int => strnatcasecmp($x['sort'], $y['sort']));

        return [
            'nodes'      => $nodes,
            'ordered'    => array_keys(array_filter($ordered, static fn (array $n): bool => !$n['external'])),
            'graph'      => $graph,
            'layout'     => $layout,
            'node_links' => $node_links,
            'on_switch'  => $on_switch,
            'unconnected_set' => array_fill_keys($graph['unconnected'], true),
            'counts' => [
                'switches'    => count(array_filter($nodes, static fn (array $n): bool => $n['is_switch'] && !$n['external'])),
                'connected'   => count(array_filter(array_keys($graph['devices']), static fn (string $k): bool => !$nodes[$k]['external'])),
                'unconnected' => count($graph['unconnected']),
                'conflicts'   => count($graph['port_conflicts']),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $item row of PluginAssetwatchAudit::collect (or an external asset)
     *
     * @return array<string, mixed>
     */
    private static function node(array $item, bool $external): array
    {
        $place = $item['racked'] ? $item['rack'] . ' U' . $item['position'] : '';
        $where = trim(($item['room'] ?? '') . ($place !== '' ? ' / ' . $place : ''), ' /');
        return [
            'key'         => $item['itemtype'] . ':' . $item['id'],
            'itemtype'    => $item['itemtype'],
            'id'          => (int) $item['id'],
            'name'        => $item['name'] !== '' ? $item['name'] : '(' . $item['id'] . ')',
            'url'         => $item['url'],
            'type_label'  => $item['type_label'],
            'is_switch'   => $item['itemtype'] === 'NetworkEquipment',
            'expect_link' => !$external && in_array($item['itemtype'], ['Computer', 'NetworkEquipment'], true),
            'external'    => $external,
            'place'       => $place,
            'where'       => $where,
            'status'      => $item['status'] ?? '',
            // Columns and rows in rack order, top of the rack first.
            'sort'        => sprintf(
                '%d|%s|%s|%s|%04d|%s',
                $external ? 1 : 0,
                $item['room'] ?? '',
                $item['racked'] ? '0' . $item['rack'] : '1',
                $item['racked'] ? '' : '~',
                9999 - (int) $item['position'],
                $item['name']
            ),
        ];
    }

    /**
     * Port connections recorded by GLPI, seen from the assets of the scope.
     *
     * @param array<string, int[]> $ids itemtype => ids
     *
     * @return list<array<string, mixed>>
     */
    private static function autoLinks(array $ids): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $links = [];
        foreach ($ids as $itemtype => $list) {
            foreach ([['networkports_id_1', 'networkports_id_2'], ['networkports_id_2', 'networkports_id_1']] as [$mine, $other]) {
                foreach ($DB->request([
                    'SELECT'     => [
                        'p1.itemtype AS a_type', 'p1.items_id AS a_id', 'p1.name AS a_port', 'p1.logical_number AS a_num',
                        'p2.itemtype AS b_type', 'p2.items_id AS b_id', 'p2.name AS b_port', 'p2.logical_number AS b_num',
                    ],
                    'FROM'       => 'glpi_networkports AS p1',
                    'INNER JOIN' => [
                        'glpi_networkports_networkports AS nn' => ['ON' => ['nn' => $mine, 'p1' => 'id']],
                        'glpi_networkports AS p2'              => ['ON' => ['nn' => $other, 'p2' => 'id']],
                    ],
                    'WHERE'      => [
                        'p1.itemtype'   => $itemtype,
                        'p1.items_id'   => $list,
                        'p1.is_deleted' => 0,
                        'p2.is_deleted' => 0,
                        'p2.itemtype'   => PluginAssetwatchUplink::LINKABLE_TYPES,
                    ],
                ]) as $row) {
                    $links[] = [
                        'a'      => $row['a_type'] . ':' . $row['a_id'],
                        'b'      => $row['b_type'] . ':' . $row['b_id'],
                        'a_port' => self::portName($row['a_port'], $row['a_num']),
                        'b_port' => self::portName($row['b_port'], $row['b_num']),
                        'source' => Topology::SOURCE_AUTO,
                    ];
                }
            }
        }
        return $links;
    }

    private static function cut(string $text, int $width): string
    {
        return mb_strimwidth($text, 0, $width, '…');
    }

    private static function portName(?string $name, $number): string
    {
        $name = trim((string) $name);
        return $name !== '' ? $name : ((int) $number > 0 ? '#' . (int) $number : '');
    }

    /**
     * @param array<string, int[]> $ids
     *
     * @return list<array<string, mixed>>
     */
    private static function manualLinks(array $ids): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $or = [];
        foreach ($ids as $itemtype => $list) {
            $or[] = ['itemtype' => $itemtype, 'items_id' => $list];
        }
        if (!empty($ids['NetworkEquipment'])) {
            $or[] = ['networkequipments_id' => $ids['NetworkEquipment']];
        }
        if ($or === []) {
            return [];
        }
        $links = [];
        foreach ($DB->request(['FROM' => PluginAssetwatchUplink::getTable(), 'WHERE' => ['OR' => $or]]) as $row) {
            $links[] = [
                'a'      => $row['itemtype'] . ':' . $row['items_id'],
                'b'      => 'NetworkEquipment:' . $row['networkequipments_id'],
                'a_port' => (string) $row['device_port'],
                'b_port' => (string) $row['switch_port'],
                'source' => Topology::SOURCE_MANUAL,
                'id'     => (int) $row['id'],
            ];
        }
        return $links;
    }

    /**
     * @param array<string, array<int, bool>> $missing itemtype => [id => true]
     *
     * @return array<string, array<string, mixed>>
     */
    private static function externalNodes(array $missing): array
    {
        $nodes = [];
        foreach ($missing as $itemtype => $ids) {
            if (!in_array($itemtype, PluginAssetwatchUplink::LINKABLE_TYPES, true)) {
                continue;
            }
            $assets = PluginAssetwatchAudit::assets($itemtype, array_keys($ids));
            $places = self::placements($itemtype, array_keys($assets));
            foreach ($assets as $id => $asset) {
                $nodes["$itemtype:$id"] = self::node($asset + ($places[$id] ?? ['racked' => false, 'rack' => '', 'position' => 0, 'room' => __('Outside the selection', 'assetwatch')]), true);
            }
        }
        return $nodes;
    }

    /**
     * Rack and room of assets outside the scope.
     *
     * @param int[] $ids
     *
     * @return array<int, array{racked: bool, rack: string, position: int, room: string}>
     */
    private static function placements(string $itemtype, array $ids): array
    {
        /** @var DBmysql $DB */
        global $DB;

        if ($ids === []) {
            return [];
        }
        $places = [];
        foreach ($DB->request([
            'SELECT'    => ['glpi_items_racks.items_id', 'glpi_items_racks.position', 'glpi_racks.name AS rack', 'glpi_dcrooms.name AS room'],
            'FROM'      => 'glpi_items_racks',
            'INNER JOIN' => ['glpi_racks' => ['ON' => ['glpi_items_racks' => 'racks_id', 'glpi_racks' => 'id']]],
            'LEFT JOIN' => ['glpi_dcrooms' => ['ON' => ['glpi_racks' => 'dcrooms_id', 'glpi_dcrooms' => 'id']]],
            'WHERE'     => ['glpi_items_racks.itemtype' => $itemtype, 'glpi_items_racks.items_id' => $ids],
        ]) as $row) {
            $places[(int) $row['items_id']] = [
                'racked' => true, 'rack' => (string) $row['rack'], 'position' => (int) $row['position'], 'room' => (string) ($row['room'] ?? ''),
            ];
        }
        return $places;
    }

    /**
     * Network devices offered in the edit form: those of the scope, those placed in a rack of
     * any room, and those already connected (at most 1000, by name).
     *
     * @param array<string, array<string, mixed>> $nodes
     *
     * @return list<array{id: int, label: string}>
     */
    public static function switchChoices(array $nodes): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $choices = [];
        foreach ($nodes as $node) {
            if ($node['is_switch']) {
                $choices[$node['id']] = $node['name'] . ($node['where'] !== '' ? ' — ' . $node['where'] : '');
            }
        }
        foreach ($DB->request([
            'SELECT'     => ['glpi_networkequipments.id', 'glpi_networkequipments.name', 'glpi_items_racks.position', 'glpi_racks.name AS rack', 'glpi_dcrooms.name AS room'],
            'FROM'       => 'glpi_networkequipments',
            'INNER JOIN' => [
                'glpi_items_racks' => ['ON' => ['glpi_items_racks' => 'items_id', 'glpi_networkequipments' => 'id', ['AND' => ['glpi_items_racks.itemtype' => 'NetworkEquipment']]]],
                'glpi_racks'       => ['ON' => ['glpi_items_racks' => 'racks_id', 'glpi_racks' => 'id']],
            ],
            'LEFT JOIN'  => ['glpi_dcrooms' => ['ON' => ['glpi_racks' => 'dcrooms_id', 'glpi_dcrooms' => 'id']]],
            'WHERE'      => ['glpi_networkequipments.is_deleted' => 0, 'glpi_networkequipments.is_template' => 0]
                + getEntitiesRestrictCriteria('glpi_networkequipments', '', '', true),
            'ORDER'      => 'glpi_networkequipments.name',
            'LIMIT'      => 1000,
        ]) as $row) {
            $choices[(int) $row['id']] ??= $row['name'] . ' — ' . trim(($row['room'] ?? '') . ' / ' . $row['rack'] . ' U' . $row['position'], ' /');
        }
        asort($choices, SORT_NATURAL | SORT_FLAG_CASE);
        $list = [];
        foreach ($choices as $id => $label) {
            $list[] = ['id' => (int) $id, 'label' => $label];
        }
        return $list;
    }

    // ------------------------------------------------------------------
    // Edition
    // ------------------------------------------------------------------

    /**
     * Apply the edit form: remove the ticked manual connections, add the new ones.
     *
     * @param array<string, mixed> $post
     *
     * @return array{added: int, removed: int, rejected: int}
     */
    public static function save(array $post): array
    {
        $added = $removed = $rejected = 0;
        $uplink = new PluginAssetwatchUplink();

        foreach ((array) ($post['remove'] ?? []) as $id) {
            if (!$uplink->getFromDB((int) $id)) {
                continue;
            }
            // Only connections of assets the user can see (active entities).
            $device = getItemForItemtype((string) $uplink->fields['itemtype']);
            if (!$device || !$device->can((int) $uplink->fields['items_id'], READ)) {
                $rejected++;
                continue;
            }
            if ($uplink->delete(['id' => (int) $id], true)) {
                $removed++;
            }
        }
        foreach ((array) ($post['add'] ?? []) as $key => $row) {
            if (!is_array($row) || (int) ($row['switch'] ?? 0) <= 0 || !preg_match('/^([A-Za-z]+):(\d+)$/', (string) $key, $m)) {
                continue;
            }
            $input = [
                'itemtype'             => $m[1],
                'items_id'             => (int) $m[2],
                'networkequipments_id' => (int) $row['switch'],
                'switch_port'          => (string) ($row['switch_port'] ?? ''),
                'device_port'          => (string) ($row['device_port'] ?? ''),
            ];
            $exists = $uplink->getFromDBByCrit([
                'itemtype' => $input['itemtype'], 'items_id' => $input['items_id'],
                'networkequipments_id' => $input['networkequipments_id'], 'switch_port' => trim($input['switch_port']),
            ]);
            if ($exists) {
                continue;
            }
            if ((new PluginAssetwatchUplink())->add($input)) {
                $added++;
            } else {
                $rejected++;
            }
        }
        return ['added' => $added, 'removed' => $removed, 'rejected' => $rejected];
    }

    // ------------------------------------------------------------------
    // Output
    // ------------------------------------------------------------------

    public static function showPage(string $scope_key, bool $edit): void
    {
        $rooms = PluginAssetwatchAudit::rooms();
        $scope = PluginAssetwatchAudit::resolveScope($scope_key, $rooms);
        $data = $scope !== null ? self::data($scope) : null;
        $can_edit = PluginAssetwatchUplink::canEditLinks();
        $edit = $edit && $can_edit && $data !== null;

        TemplateRenderer::getInstance()->display('@assetwatch/topology.html.twig', [
            'title'          => self::getTypeName(),
            'self_url'       => self::getSearchURL(),
            'datacenters'    => PluginAssetwatchAudit::datacenterChoices($rooms),
            'scope'          => $scope,
            'scope_key'      => $scope_key,
            'data'           => $data,
            'edit'           => $edit,
            'can_edit'       => $can_edit,
            'switch_choices' => $edit ? self::switchChoices($data['nodes']) : [],
            'status_labels'  => PluginAssetwatchAudit::statusLabels(),
            'datacenter_url' => Datacenter::getSearchURL(),
            'datacenter_label' => Datacenter::getTypeName(Session::getPluralNumber()),
            'none_key'       => Topology::NONE,
        ]);
    }

    /**
     * CSV: one line per connection, then the devices without connection.
     */
    public static function csv(string $scope_key): ?string
    {
        $scope = PluginAssetwatchAudit::resolveScope($scope_key, PluginAssetwatchAudit::rooms());
        if ($scope === null) {
            return null;
        }
        $data = self::data($scope);
        $nodes = $data['nodes'];
        $sources = self::sourceLabels();

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            __('Network device', 'assetwatch'), __('Location', 'assetwatch'), __('Switch port', 'assetwatch'),
            __('Device', 'assetwatch'), __('Asset type', 'assetwatch'), __('Location', 'assetwatch'), __('Device port', 'assetwatch'),
            __('Source', 'assetwatch'),
        ], ',', '"', '');
        $line = static function (array $sw, string $sw_port, array $dev, string $dev_port, array $srcs) use ($out, $sources): void {
            fputcsv($out, [
                $sw['name'] ?? '', $sw['where'] ?? '', $sw_port,
                $dev['name'], $dev['type_label'], $dev['where'], $dev_port,
                implode(' + ', array_map(static fn (string $s): string => $sources[$s], $srcs)),
            ], ',', '"', '');
        };
        foreach ($data['graph']['switches'] as $sw_key) {
            foreach ($data['graph']['devices'] as $dev_key => $links) {
                foreach ($links as $link) {
                    if ($link['switch'] === $sw_key) {
                        $line($nodes[$sw_key], $link['switch_port'], $nodes[$dev_key], $link['device_port'], $link['sources']);
                    }
                }
            }
        }
        foreach ($data['graph']['switch_links'] as $link) {
            $line($nodes[$link['a']], $link['a_port'], $nodes[$link['b']], $link['b_port'], $link['sources']);
        }
        foreach ($data['graph']['unconnected'] as $key) {
            $line([], '', $nodes[$key], '', []);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    /**
     * @return array<string, string>
     */
    public static function sourceLabels(): array
    {
        return [
            Topology::SOURCE_AUTO   => __('GLPI ports', 'assetwatch'),
            Topology::SOURCE_MANUAL => __('Typed by hand', 'assetwatch'),
        ];
    }
}
