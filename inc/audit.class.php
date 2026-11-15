<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QuerySubQuery;
use GlpiPlugin\Assetwatch\Core\RoomAudit;
use GlpiPlugin\Assetwatch\Core\Wildcard;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Datacenter inventory page (Tools > Datacenter inventory): every asset of a server room,
 * rack by rack, with its live status and the discrepancies to check on site.
 *
 * Read only. A room holds:
 *  - the assets placed in the racks of the room;
 *  - the computers reported by GLPI Agent that are in no rack and whose location is the room
 *    location or below it (the deepest matching room wins).
 */
class PluginAssetwatchAudit extends CommonGLPI
{
    public static $rightname = 'plugin_assetwatch_alert';

    /** Scope of the computers in no rack and without location. */
    public const SCOPE_UNASSIGNED = 'unassigned';

    public static function getTypeName($nb = 0)
    {
        return __('Datacenter inventory', 'assetwatch');
    }

    public static function getMenuName()
    {
        return self::getTypeName();
    }

    public static function getIcon()
    {
        return 'ti ti-clipboard-list';
    }

    public static function getSearchURL($full = true)
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        return ($full ? $CFG_GLPI['root_doc'] : '') . '/plugins/assetwatch/front/audit.php';
    }

    // ------------------------------------------------------------------
    // Scope
    // ------------------------------------------------------------------

    /**
     * Server rooms of the active entities.
     *
     * @return array<int, array{id: int, name: string, datacenters_id: int, datacenter: string, locations_id: int}>
     */
    public static function rooms(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $rooms = [];
        foreach ($DB->request([
            'SELECT'    => [
                'glpi_dcrooms.id',
                'glpi_dcrooms.name',
                'glpi_dcrooms.datacenters_id',
                'glpi_dcrooms.locations_id',
                'glpi_datacenters.name AS datacenter',
            ],
            'FROM'      => 'glpi_dcrooms',
            'LEFT JOIN' => [
                'glpi_datacenters' => ['ON' => ['glpi_dcrooms' => 'datacenters_id', 'glpi_datacenters' => 'id']],
            ],
            'WHERE'     => ['glpi_dcrooms.is_deleted' => 0] + getEntitiesRestrictCriteria('glpi_dcrooms', '', '', true),
        ]) as $row) {
            $rooms[(int) $row['id']] = [
                'id'             => (int) $row['id'],
                'name'           => (string) $row['name'],
                'datacenters_id' => (int) $row['datacenters_id'],
                'datacenter'     => (string) ($row['datacenter'] ?? ''),
                'locations_id'   => (int) $row['locations_id'],
            ];
        }
        uasort($rooms, static fn (array $a, array $b): int => strnatcasecmp($a['datacenter'], $b['datacenter']) ?: strnatcasecmp($a['name'], $b['name']));
        return $rooms;
    }

    /**
     * Parse the "scope" request parameter: "room:<id>", "dc:<id>", "all" or "unassigned".
     *
     * @param array<int, array<string, mixed>> $rooms
     *
     * @return array{key: string, room_ids: int[], unassigned: bool, label: string}|null
     */
    public static function resolveScope(string $scope, array $rooms): ?array
    {
        if ($scope === self::SCOPE_UNASSIGNED) {
            return ['key' => $scope, 'room_ids' => [], 'unassigned' => true, 'label' => __('Computers in no rack and without location', 'assetwatch')];
        }
        if ($scope === 'all') {
            return ['key' => $scope, 'room_ids' => array_keys($rooms), 'unassigned' => false, 'label' => __('All server rooms', 'assetwatch')];
        }
        if (preg_match('/^(room|dc):(\d+)$/', $scope, $m)) {
            $id = (int) $m[2];
            if ($m[1] === 'room' && isset($rooms[$id])) {
                $room = $rooms[$id];
                return ['key' => $scope, 'room_ids' => [$id], 'unassigned' => false, 'label' => trim($room['datacenter'] . ' / ' . $room['name'], ' /')];
            }
            if ($m[1] === 'dc') {
                $ids = array_keys(array_filter($rooms, static fn (array $r): bool => $r['datacenters_id'] === $id));
                if ($ids !== []) {
                    return ['key' => $scope, 'room_ids' => $ids, 'unassigned' => false, 'label' => $rooms[$ids[0]]['datacenter']];
                }
            }
        }
        return null;
    }

    // ------------------------------------------------------------------
    // Data
    // ------------------------------------------------------------------

    /**
     * Assets of the given rooms (or the unassigned computers), evaluated.
     *
     * @param int[] $room_ids
     *
     * @return array{items: array<int, array<string, mixed>>, status_counts: array<string, int>, issue_counts: array<string, int>, items_with_issues: int}
     */
    public static function collect(array $room_ids, bool $unassigned = false): array
    {
        $settings = PluginAssetwatchConfig::getSettings();
        $rooms = self::rooms();
        $room_ids = array_values(array_filter(array_map('intval', $room_ids), static fn (int $id): bool => isset($rooms[$id])));

        $items = [];
        if ($unassigned) {
            foreach (self::unrackedComputers([], $rooms, true) as $row) {
                $items[] = $row;
            }
        } elseif ($room_ids !== []) {
            $items = array_merge(self::rackedItems($room_ids, $rooms), self::unrackedComputers($room_ids, $rooms, false));
        }
        $items = self::enrich($items, $settings);

        $now = new DateTimeImmutable($_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s'));
        $result = RoomAudit::evaluate($items, $settings->roomNetworks(), $now, $settings);
        usort($result['items'], static function (array $a, array $b): int {
            return strnatcasecmp($a['datacenter'], $b['datacenter'])
                ?: strnatcasecmp($a['room'], $b['room'])
                ?: ((int) $b['racked'] <=> (int) $a['racked'])
                ?: strnatcasecmp($a['rack'], $b['rack'])
                ?: ($b['position'] <=> $a['position'])
                ?: strnatcasecmp($a['name'], $b['name']);
        });
        return $result;
    }

    /**
     * @param int[] $room_ids
     * @param array<int, array<string, mixed>> $rooms
     *
     * @return array<int, array<string, mixed>>
     */
    private static function rackedItems(array $room_ids, array $rooms): array
    {
        /** @var DBmysql $DB */
        /** @var array $CFG_GLPI */
        global $DB, $CFG_GLPI;

        $placements = [];
        foreach ($DB->request([
            'SELECT'     => [
                'glpi_items_racks.itemtype',
                'glpi_items_racks.items_id',
                'glpi_items_racks.position',
                'glpi_items_racks.orientation',
                'glpi_racks.id AS racks_id',
                'glpi_racks.name AS rack',
                'glpi_racks.dcrooms_id',
            ],
            'FROM'       => 'glpi_items_racks',
            'INNER JOIN' => [
                'glpi_racks' => ['ON' => ['glpi_items_racks' => 'racks_id', 'glpi_racks' => 'id']],
            ],
            'WHERE'      => [
                'glpi_racks.dcrooms_id'  => $room_ids,
                'glpi_racks.is_deleted'  => 0,
                'glpi_racks.is_template' => 0,
            ] + getEntitiesRestrictCriteria('glpi_racks', '', '', true),
        ]) as $row) {
            $itemtype = (string) $row['itemtype'];
            if (!in_array($itemtype, $CFG_GLPI['rackable_types'], true)) {
                continue;
            }
            $placements[$itemtype][(int) $row['items_id']] = $row;
        }

        $items = [];
        foreach ($placements as $itemtype => $by_id) {
            foreach (self::assets($itemtype, array_keys($by_id)) as $id => $asset) {
                $p = $by_id[$id];
                $room = $rooms[(int) $p['dcrooms_id']];
                $items[] = $asset + [
                    'dcrooms_id'  => $room['id'],
                    'room'        => $room['name'],
                    'datacenter'  => $room['datacenter'],
                    'racked'      => true,
                    'racks_id'    => (int) $p['racks_id'],
                    'rack'        => (string) $p['rack'],
                    'position'    => (int) $p['position'],
                    'orientation' => (int) $p['orientation'],
                ];
            }
        }
        return $items;
    }

    /**
     * Computers reported by GLPI Agent and placed in no rack, attached to a room by location.
     *
     * @param int[] $room_ids
     * @param array<int, array<string, mixed>> $rooms
     * @param bool  $without_location true: the ones with no location at all (room 0)
     *
     * @return array<int, array<string, mixed>>
     */
    private static function unrackedComputers(array $room_ids, array $rooms, bool $without_location): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $not_racked = ['NOT' => ['glpi_computers.id' => new QuerySubQuery([
            'SELECT' => 'items_id',
            'FROM'   => 'glpi_items_racks',
            'WHERE'  => ['itemtype' => 'Computer'],
        ])]];
        $base = [
            'glpi_computers.is_deleted'  => 0,
            'glpi_computers.is_template' => 0,
            'glpi_computers.is_dynamic'  => 1,
        ] + $not_racked + getEntitiesRestrictCriteria('glpi_computers', '', '', true);

        if ($without_location) {
            $ids = array_column(iterator_to_array($DB->request([
                'SELECT' => 'id',
                'FROM'   => 'glpi_computers',
                'WHERE'  => $base + ['glpi_computers.locations_id' => 0],
            ]), false), 'id');
            $items = [];
            foreach (self::assets('Computer', array_map('intval', $ids)) as $asset) {
                $items[] = $asset + self::noPlacement(0, '', '');
            }
            return $items;
        }

        // Location id => room it belongs to: the room whose location is the deepest ancestor-or-self.
        $room_of_location = self::roomOfLocations($rooms);
        $wanted = array_filter($room_of_location, static fn (int $room): bool => in_array($room, $room_ids, true));
        if ($wanted === []) {
            return [];
        }
        $by_id = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'locations_id'],
            'FROM'   => 'glpi_computers',
            'WHERE'  => $base + ['glpi_computers.locations_id' => array_keys($wanted)],
        ]) as $row) {
            $by_id[(int) $row['id']] = $wanted[(int) $row['locations_id']];
        }
        $items = [];
        foreach (self::assets('Computer', array_keys($by_id)) as $id => $asset) {
            $room = $rooms[$by_id[$id]];
            $items[] = $asset + self::noPlacement($room['id'], $room['name'], $room['datacenter']);
        }
        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $rooms
     *
     * @return array<int, int> locations_id => dcrooms_id
     */
    private static function roomOfLocations(array $rooms): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $room_locations = [];
        foreach ($rooms as $room) {
            if ($room['locations_id'] > 0) {
                $room_locations[$room['locations_id']] = $room['id'];
            }
        }
        if ($room_locations === []) {
            return [];
        }
        $levels = [];
        foreach ($DB->request(['SELECT' => ['id', 'level'], 'FROM' => 'glpi_locations', 'WHERE' => ['id' => array_keys($room_locations)]]) as $row) {
            $levels[(int) $row['id']] = (int) $row['level'];
        }
        // Shallow first, so that a deeper (more specific) room location overwrites.
        uksort($room_locations, static fn (int $a, int $b): int => ($levels[$a] ?? 0) <=> ($levels[$b] ?? 0));
        $map = [];
        foreach ($room_locations as $location => $room) {
            foreach (getSonsOf('glpi_locations', $location) as $son) {
                $map[(int) $son] = $room;
            }
        }
        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private static function noPlacement(int $dcrooms_id, string $room, string $datacenter): array
    {
        return [
            'dcrooms_id'  => $dcrooms_id,
            'room'        => $room,
            'datacenter'  => $datacenter,
            'racked'      => false,
            'racks_id'    => 0,
            'rack'        => '',
            'position'    => 0,
            'orientation' => 0,
        ];
    }

    /**
     * Basic fields of assets of one type (deleted / template ones and other entities are skipped).
     *
     * @param int[] $ids
     *
     * @return array<int, array<string, mixed>> keyed by id
     */
    private static function assets(string $itemtype, array $ids): array
    {
        /** @var DBmysql $DB */
        global $DB;

        if ($ids === [] || !is_a($itemtype, CommonDBTM::class, true)) {
            return [];
        }
        $table = $itemtype::getTable();
        $select = ["$table.id", "$table.name", "$table.serial"];
        $joins = [];
        $where = ["$table.id" => $ids];
        foreach (['is_deleted' => 0, 'is_template' => 0] as $field => $value) {
            if ($DB->fieldExists($table, $field)) {
                $where["$table.$field"] = $value;
            }
        }
        if ($DB->fieldExists($table, 'entities_id')) {
            $where += getEntitiesRestrictCriteria($table, '', '', true);
        }
        $optional = ['is_dynamic', 'last_inventory_update', 'computertypes_id', 'locations_id'];
        foreach ($optional as $field) {
            if ($DB->fieldExists($table, $field)) {
                $select[] = "$table.$field";
            }
        }
        if ($DB->fieldExists($table, 'manufacturers_id')) {
            $select[] = 'glpi_manufacturers.name AS manufacturer';
            $joins['glpi_manufacturers'] = ['ON' => [$table => 'manufacturers_id', 'glpi_manufacturers' => 'id']];
        }
        $model_class = $itemtype . 'Model';
        if (class_exists($model_class) && is_a($model_class, CommonDBTM::class, true)) {
            $model_fk = $model_class::getForeignKeyField();
            if ($DB->fieldExists($table, $model_fk)) {
                $model_table = $model_class::getTable();
                $select[] = "$model_table.name AS model";
                $joins[$model_table] = ['ON' => [$table => $model_fk, $model_table => 'id']];
            }
        }

        $assets = [];
        $criteria = ['SELECT' => $select, 'FROM' => $table, 'WHERE' => $where];
        if ($joins !== []) {
            $criteria['LEFT JOIN'] = $joins;
        }
        foreach ($DB->request($criteria) as $row) {
            $last = $row['last_inventory_update'] ?? null;
            $assets[(int) $row['id']] = [
                'itemtype'         => $itemtype,
                'type_label'       => $itemtype::getTypeName(1),
                'id'               => (int) $row['id'],
                'name'             => (string) ($row['name'] ?? ''),
                'url'              => $itemtype::getFormURLWithID((int) $row['id']),
                'serial'           => trim((string) ($row['serial'] ?? '')),
                'manufacturer'     => (string) ($row['manufacturer'] ?? ''),
                'model'            => (string) ($row['model'] ?? ''),
                'computertypes_id' => (int) ($row['computertypes_id'] ?? 0),
                'last_inventory'   => $last !== null ? (string) $last : null,
                'inventoried'      => !empty($row['is_dynamic']) && $last !== null,
            ];
        }
        return $assets;
    }

    /**
     * Add technical groups, addresses, open low disk alerts, duplicated serials and a hardware
     * summary (from the plugin snapshots) to the rows.
     *
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<int, array<string, mixed>>
     */
    private static function enrich(array $items, \GlpiPlugin\Assetwatch\Core\Settings $settings): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $ids = [];
        foreach ($items as $item) {
            $ids[$item['itemtype']][] = $item['id'];
        }

        $groups = $ips = $disk_low = $duplicates = $specs = [];
        foreach ($ids as $itemtype => $list) {
            foreach ($DB->request([
                'SELECT'     => ['glpi_groups_items.items_id', 'glpi_groups.completename'],
                'FROM'       => 'glpi_groups_items',
                'INNER JOIN' => ['glpi_groups' => ['ON' => ['glpi_groups_items' => 'groups_id', 'glpi_groups' => 'id']]],
                'WHERE'      => [
                    'glpi_groups_items.itemtype' => $itemtype,
                    'glpi_groups_items.items_id' => $list,
                    'glpi_groups_items.type'     => Group_Item::GROUP_TYPE_TECH,
                ],
                'ORDER'      => 'glpi_groups.completename',
            ]) as $row) {
                $groups[$itemtype][(int) $row['items_id']][] = (string) $row['completename'];
            }

            foreach ($DB->request([
                'SELECT'     => ['glpi_ipaddresses.mainitems_id', 'glpi_ipaddresses.name AS ip', 'glpi_networkports.name AS port_name'],
                'FROM'       => 'glpi_ipaddresses',
                'INNER JOIN' => [
                    'glpi_networknames' => ['ON' => [
                        'glpi_ipaddresses'  => 'items_id',
                        'glpi_networknames' => 'id',
                        ['AND' => ['glpi_ipaddresses.itemtype' => 'NetworkName']],
                    ]],
                    'glpi_networkports' => ['ON' => [
                        'glpi_networknames' => 'items_id',
                        'glpi_networkports' => 'id',
                        ['AND' => ['glpi_networknames.itemtype' => 'NetworkPort']],
                    ]],
                ],
                'WHERE'      => [
                    'glpi_ipaddresses.mainitemtype' => $itemtype,
                    'glpi_ipaddresses.mainitems_id' => $list,
                    'glpi_ipaddresses.is_deleted'   => 0,
                    'glpi_networknames.is_deleted'  => 0,
                    'glpi_networkports.is_deleted'  => 0,
                    'NOT'                           => ['glpi_networkports.instantiation_type' => 'NetworkPortLocal'],
                ],
                'ORDER'      => 'glpi_ipaddresses.name',
            ]) as $row) {
                if (Wildcard::matchesAny((string) $row['port_name'], $settings->portExcludedPatterns)) {
                    continue;
                }
                $ips[$itemtype][(int) $row['mainitems_id']][(string) $row['ip']] = (string) $row['ip'];
            }

            // Serials used by more than one non-deleted asset of the same type.
            $table = $itemtype::getTable();
            $serials = array_values(array_unique(array_filter(array_map(
                static fn (array $i): string => $i['itemtype'] === $itemtype ? $i['serial'] : '',
                $items
            ))));
            if ($serials !== []) {
                $where = ['serial' => $serials];
                foreach (['is_deleted' => 0, 'is_template' => 0] as $field => $value) {
                    if ($DB->fieldExists($table, $field)) {
                        $where[$field] = $value;
                    }
                }
                foreach ($DB->request([
                    'SELECT'  => ['serial', 'COUNT' => 'id AS cpt'],
                    'FROM'    => $table,
                    'WHERE'   => $where,
                    'GROUPBY' => 'serial',
                    'HAVING'  => ['cpt' => ['>', 1]],
                ]) as $row) {
                    $duplicates[$itemtype][(string) $row['serial']] = (int) $row['cpt'] - 1;
                }
            }
        }

        if (!empty($ids['Computer'])) {
            foreach ($DB->request([
                'SELECT'   => ['items_id'],
                'DISTINCT' => true,
                'FROM'     => PluginAssetwatchAlert::getTable(),
                'WHERE'    => [
                    'itemtype'   => 'Computer',
                    'items_id'   => $ids['Computer'],
                    'alert_type' => PluginAssetwatchAlert::TYPE_DISK_LOW,
                    'status'     => [PluginAssetwatchAlert::STATUS_ACTIVE, PluginAssetwatchAlert::STATUS_ACKNOWLEDGED],
                ],
            ]) as $row) {
                $disk_low[(int) $row['items_id']] = true;
            }
            foreach ($DB->request([
                'SELECT' => ['computers_id', 'content'],
                'FROM'   => PluginAssetwatchSnapshot::getTable(),
                'WHERE'  => ['computers_id' => $ids['Computer']],
            ]) as $row) {
                $specs[(int) $row['computers_id']] = self::specSummary(json_decode((string) $row['content'], true));
            }
        }

        foreach ($items as $i => $item) {
            $type = $item['itemtype'];
            $id = $item['id'];
            $items[$i]['tech_groups'] = $groups[$type][$id] ?? [];
            $items[$i]['ips'] = array_values($ips[$type][$id] ?? []);
            $items[$i]['disk_low'] = $type === 'Computer' && isset($disk_low[$id]);
            $items[$i]['serial_duplicates'] = $item['serial'] !== '' ? ($duplicates[$type][$item['serial']] ?? 0) : 0;
            $items[$i]['spec'] = $type === 'Computer' ? ($specs[$id] ?? '') : '';
        }
        return $items;
    }

    /**
     * "64 GB · 2 × Intel Xeon Gold 6338 · 4 drives" from a snapshot.
     *
     * @param mixed $snapshot
     */
    public static function specSummary($snapshot): string
    {
        if (!is_array($snapshot)) {
            return '';
        }
        $parts = [];
        $memory = (int) ($snapshot['memory_mb'] ?? 0);
        if ($memory > 0) {
            $parts[] = round($memory / 1024, $memory % 1024 === 0 ? 0 : 1) . ' GB';
        }
        $cpus = is_array($snapshot['cpus'] ?? null) ? $snapshot['cpus'] : [];
        foreach ($cpus as $label => $count) {
            $parts[] = ((int) $count > 1 ? (int) $count . ' × ' : '') . $label;
        }
        $drives = is_array($snapshot['drives'] ?? null) ? count($snapshot['drives']) : 0;
        if ($drives > 0) {
            $parts[] = sprintf(_n('%d drive', '%d drives', $drives, 'assetwatch'), $drives);
        }
        return implode(' · ', $parts);
    }

    // ------------------------------------------------------------------
    // Labels
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{label: string, color: string}>
     */
    public static function statusLabels(): array
    {
        return [
            RoomAudit::STATUS_OK       => ['label' => __('Healthy', 'assetwatch'), 'color' => 'success'],
            RoomAudit::STATUS_DISK_LOW => ['label' => __('Low disk space', 'assetwatch'), 'color' => 'warning'],
            RoomAudit::STATUS_OFFLINE  => ['label' => __('Offline', 'assetwatch'), 'color' => 'danger'],
            RoomAudit::STATUS_RETIRED  => ['label' => __('Probably removed', 'assetwatch'), 'color' => 'dark'],
            RoomAudit::STATUS_NO_AGENT => ['label' => __('No agent', 'assetwatch'), 'color' => 'secondary'],
        ];
    }

    /**
     * @return array<string, array{label: string, help: string}>
     */
    public static function issueLabels(): array
    {
        $days = PluginAssetwatchConfig::getSettings()->retireDays;
        return [
            RoomAudit::ISSUE_NEVER_REPORTED   => [
                'label' => __('In a rack, never reported', 'assetwatch'),
                'help'  => __('Computer placed in a rack but GLPI Agent never sent its inventory: agent not installed, or the machine is not there.', 'assetwatch'),
            ],
            RoomAudit::ISSUE_RETIRED          => [
                'label' => __('Probably removed', 'assetwatch'),
                'help'  => sprintf(__('No inventory for %d days or more: check that it is still in the rack.', 'assetwatch'), $days),
            ],
            RoomAudit::ISSUE_UNRACKED         => [
                'label' => __('Not in a rack', 'assetwatch'),
                'help'  => __('Reporting computer of this room (by location) that is placed in no rack.', 'assetwatch'),
            ],
            RoomAudit::ISSUE_DUPLICATE_SERIAL => [
                'label' => __('Duplicate serial number', 'assetwatch'),
                'help'  => __('Another asset of the same type has the same serial number (duplicate record?).', 'assetwatch'),
            ],
            RoomAudit::ISSUE_NO_TECH_GROUP    => [
                'label' => __('No technical group', 'assetwatch'),
                'help'  => __('Nobody receives the alerts of this computer.', 'assetwatch'),
            ],
            RoomAudit::ISSUE_SUBNET_MISMATCH  => [
                'label' => __('Address outside the room networks', 'assetwatch'),
                'help'  => __('None of its addresses is in the networks configured for the room (Setup > Plugins > Asset Watch).', 'assetwatch'),
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Output
    // ------------------------------------------------------------------

    public static function showPage(string $scope_key): void
    {
        $rooms = self::rooms();
        $scope = self::resolveScope($scope_key, $rooms);
        $result = $scope !== null ? self::collect($scope['room_ids'], $scope['unassigned']) : null;

        $datacenters = [];
        foreach ($rooms as $room) {
            $datacenters[$room['datacenters_id']]['name'] = $room['datacenter'] !== '' ? $room['datacenter'] : __('No datacenter', 'assetwatch');
            $datacenters[$room['datacenters_id']]['rooms'][] = $room;
        }

        // Group rows: room -> rack (racked) / unracked
        $groups = [];
        if ($result !== null) {
            foreach ($result['items'] as $item) {
                $room_key = $item['dcrooms_id'];
                $groups[$room_key]['label'] ??= trim($item['datacenter'] . ' / ' . $item['room'], ' /');
                $rack_key = $item['racked'] ? 'rack:' . $item['racks_id'] : 'unracked';
                $groups[$room_key]['racks'][$rack_key]['label'] ??= $item['racked'] ? $item['rack'] : __('Not in a rack', 'assetwatch');
                $groups[$room_key]['racks'][$rack_key]['racked'] = $item['racked'];
                $groups[$room_key]['racks'][$rack_key]['items'][] = $item;
            }
        }

        TemplateRenderer::getInstance()->display('@assetwatch/audit.html.twig', [
            'title'          => self::getTypeName(),
            'self_url'       => self::getSearchURL(),
            'datacenters'    => $datacenters,
            'scope'          => $scope,
            'scope_key'      => $scope_key,
            'result'         => $result,
            'groups'         => $groups,
            'status_labels'  => self::statusLabels(),
            'issue_labels'   => self::issueLabels(),
            'retire_days'    => PluginAssetwatchConfig::getSettings()->retireDays,
            'can_config'     => Session::haveRight(PluginAssetwatchConfig::$rightname, UPDATE),
            'config_url'     => PluginAssetwatchConfig::getConfigUrl(),
            'datacenter_url' => Datacenter::getSearchURL(),
            'datacenter_label' => Datacenter::getTypeName(Session::getPluralNumber()),
        ]);
    }

    /**
     * CSV for spreadsheets (UTF-8 with BOM so that Excel shows Chinese correctly).
     */
    public static function csv(string $scope_key): ?string
    {
        $scope = self::resolveScope($scope_key, self::rooms());
        if ($scope === null) {
            return null;
        }
        $result = self::collect($scope['room_ids'], $scope['unassigned']);
        $status_labels = self::statusLabels();
        $issue_labels = self::issueLabels();
        $orientations = [Rack::FRONT => __('Front', 'assetwatch'), Rack::REAR => __('Rear', 'assetwatch')];

        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [
            __('Datacenter', 'assetwatch'), __('Server room', 'assetwatch'), __('Rack', 'assetwatch'), __('Rack unit (U)', 'assetwatch'), __('Side', 'assetwatch'),
            __('Asset type', 'assetwatch'), __('Name'), __('Serial number'), __('Manufacturer', 'assetwatch'), __('Model', 'assetwatch'),
            __('IP addresses', 'assetwatch'), __('Last inventory', 'assetwatch'), __('Status'),
            __('Discrepancies', 'assetwatch'), __('Technical groups', 'assetwatch'), __('Hardware', 'assetwatch'),
        ], ',', '"', '');
        foreach ($result['items'] as $item) {
            $issues = [];
            foreach ($item['issues'] as $issue => $detail) {
                if ($issue === RoomAudit::ISSUE_DUPLICATE_SERIAL) {
                    $detail = sprintf(__('%d other asset(s) with this serial', 'assetwatch'), (int) $detail);
                }
                $issues[] = $issue_labels[$issue]['label'] . ($detail !== '' ? " ($detail)" : '');
            }
            fputcsv($out, [
                $item['datacenter'],
                $item['room'],
                $item['racked'] ? $item['rack'] : __('Not in a rack', 'assetwatch'),
                $item['racked'] ? $item['position'] : '',
                $item['racked'] ? ($orientations[$item['orientation']] ?? '') : '',
                $item['type_label'],
                $item['name'],
                $item['serial'],
                $item['manufacturer'],
                $item['model'],
                implode(' ', $item['ips']),
                $item['last_inventory'] ?? '',
                $status_labels[$item['status']]['label'],
                implode('; ', $issues),
                implode('; ', $item['tech_groups']),
                $item['spec'],
            ], ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return $csv;
    }
}
