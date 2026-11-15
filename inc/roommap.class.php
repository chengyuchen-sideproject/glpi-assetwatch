<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetwatch\Core\RackLayout;
use GlpiPlugin\Assetwatch\Core\RoomMap;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Floor map of a server room: the racks seen from above, coloured by the number of assets to
 * check on site (default), the rack space used or the power used. Shown in a tab of the server
 * room and above the racks of the room in the datacenter inventory page.
 */
class PluginAssetwatchRoomMap extends CommonGLPI
{
    public static $rightname = 'plugin_assetwatch_alert';

    /** Front of the rack, from the GLPI room orientation (1 north ... 4 west). */
    private const FRONT_ICONS = [
        Rack::ROOM_O_NORTH => 'up',
        Rack::ROOM_O_EAST  => 'right',
        Rack::ROOM_O_SOUTH => 'down',
        Rack::ROOM_O_WEST  => 'left',
    ];

    public static function getTypeName($nb = 0)
    {
        return __('Floor map', 'assetwatch');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof DCRoom && !$item->isNewItem() && Session::haveRight(self::$rightname, READ)) {
            return self::createTabEntry(self::getTypeName(), 0, $item::class, 'ti ti-layout-grid');
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if (!$item instanceof DCRoom || !Session::haveRight(self::$rightname, READ)) {
            return false;
        }
        $result = PluginAssetwatchAudit::collect([$item->getID()]);
        $map = self::build($item->getID(), $result['items']);
        if ($map === null) {
            return false;
        }
        TemplateRenderer::getInstance()->display('@assetwatch/roommap.html.twig', [
            'map'      => $map,
            'in_audit' => false,
        ]);
        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $items evaluated assets (PluginAssetwatchAudit::collect)
     *        - only the ones of this room are used
     *
     * @return array<string, mixed>|null
     */
    public static function build(int $room_id, array $items): ?array
    {
        /** @var DBmysql $DB */
        global $DB;

        $room = new DCRoom();
        if (!$room->getFromDB($room_id) || !$room->can($room_id, READ)) {
            return null;
        }

        $racks = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'position', 'room_orientation', 'number_units', 'max_power'],
            'FROM'   => 'glpi_racks',
            'WHERE'  => ['dcrooms_id' => $room_id, 'is_deleted' => 0, 'is_template' => 0] + getEntitiesRestrictCriteria('glpi_racks', '', '', true),
        ]) as $row) {
            $racks[(int) $row['id']] = $row;
        }
        uasort($racks, static fn (array $a, array $b): int => strnatcasecmp((string) $a['name'], (string) $b['name']));

        $by_rack = [];
        $unracked = 0;
        foreach ($items as $item) {
            if ((int) $item['dcrooms_id'] !== $room_id) {
                continue;
            }
            if ($item['racked'] && isset($racks[$item['racks_id']])) {
                $by_rack[$item['racks_id']][] = $item;
            } elseif (!$item['racked']) {
                $unracked++;
            }
        }

        $grid = RoomMap::grid((int) $room->fields['vis_cols'], (int) $room->fields['vis_rows'], array_map(static fn (array $r): ?string => $r['position'], $racks));
        $audit_url = PluginAssetwatchAudit::getSearchURL() . '?scope=room:' . $room_id;

        $cells = [];
        $totals = ['issues' => 0, 'items' => 0];
        foreach ($racks as $id => $rack) {
            $list = $by_rack[$id] ?? [];
            $issues = count(array_filter($list, static fn (array $i): bool => $i['issues'] !== []));
            $layout = RackLayout::blocks((int) $rack['number_units'], array_map(static fn (array $i): array => [
                'key'      => $i['itemtype'] . ':' . $i['id'],
                'position' => (int) $i['position'],
                'units'    => (int) $i['units'],
            ], $list));
            $power = array_sum(array_column($list, 'power'));
            $metrics = RoomMap::metrics($issues, $layout['used_units'], (int) $rack['number_units'], $power, (int) $rack['max_power']);
            $totals['issues'] += $issues;
            $totals['items'] += count($list);

            $cells[] = $metrics + [
                'id'           => $id,
                'name'         => (string) $rack['name'],
                'x'            => $grid['placed'][$id]['x'] ?? null,
                'y'            => $grid['placed'][$id]['y'] ?? null,
                'front'        => self::FRONT_ICONS[(int) $rack['room_orientation']] ?? null,
                'items'        => count($list),
                'used_units'   => $layout['used_units'],
                'number_units' => (int) $rack['number_units'],
                'power'        => $power,
                'max_power'    => (int) $rack['max_power'],
                'rack_url'     => Rack::getFormURLWithID($id),
                'audit_anchor' => 'aw-rack-' . $id,
                'audit_url'    => $audit_url . '#aw-rack-' . $id,
                'label_url'    => PluginAssetwatchRackLabel::getUrl(['rack' => $id]),
            ];
        }

        $cols = $grid['cols'];
        $blueprint = !empty($room->fields['blueprint']) ? Toolbox::getPictureUrl($room->fields['blueprint']) : '';
        return [
            'room_id'    => $room_id,
            'room_name'  => (string) $room->fields['name'],
            'room_url'   => DCRoom::getFormURLWithID($room_id),
            'audit_url'  => $audit_url,
            'labels_url' => PluginAssetwatchRackLabel::getUrl(['room' => $room_id]),
            'stickers_url' => PluginAssetwatchRackLabel::getUrl(['room' => $room_id, 'format' => PluginAssetwatchRackLabel::FORMAT_STICKER]),
            'cols'       => $cols,
            'rows'       => $grid['rows'],
            'cell'       => $cols <= 12 ? 72 : ($cols <= 20 ? 56 : 44),
            'blueprint'  => $blueprint ?: '',
            'cells'      => $cells,
            'placed'     => array_values(array_filter($cells, static fn (array $c): bool => $c['x'] !== null)),
            'unplaced'   => array_values(array_filter($cells, static fn (array $c): bool => $c['x'] === null)),
            'unracked'   => $unracked,
            'totals'     => $totals,
            'thresholds' => [
                'units_warn' => RoomMap::UNITS_WARN, 'units_bad' => RoomMap::UNITS_BAD,
                'power_warn' => RoomMap::POWER_WARN, 'power_bad' => RoomMap::POWER_BAD,
                'issues_bad' => RoomMap::ISSUES_BAD,
            ],
        ];
    }
}
