<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

use Glpi\DBAL\QueryExpression;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Read-only access to GLPI inventory data needed by the checks.
 *
 * All SQL lives here, so that GLPI version differences only touch this layer.
 */
class PluginAssetwatchRepository
{
    /**
     * Computers handled by GLPI Agent (dynamic), not deleted, not templates.
     *
     * @return array<int, array<string, mixed>> keyed by computer id
     */
    public static function monitoredComputers(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $rows = [];
        $iterator = $DB->request([
            'SELECT' => ['id', 'name', 'serial', 'entities_id', 'computertypes_id', 'last_inventory_update'],
            'FROM'   => 'glpi_computers',
            'WHERE'  => [
                'is_deleted'  => 0,
                'is_template' => 0,
                'is_dynamic'  => 1,
            ],
            'ORDER'  => 'id',
        ]);
        foreach ($iterator as $row) {
            $rows[(int) $row['id']] = $row;
        }
        return self::withTechGroups('Computer', $rows);
    }

    /**
     * Computers whose inventory is newer than their stored snapshot (or that have none).
     *
     * @return array<int, array<string, mixed>> keyed by computer id, with `snapshot_date` / `snapshot_content`
     */
    public static function computersWithNewInventory(int $limit = 500): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $snapshots = PluginAssetwatchSnapshot::getTable();
        $rows = [];
        $iterator = $DB->request([
            'SELECT' => [
                'glpi_computers.id',
                'glpi_computers.name',
                'glpi_computers.serial',
                'glpi_computers.entities_id',
                'glpi_computers.last_inventory_update',
                'glpi_computers.last_boot',
                "$snapshots.inventory_date AS snapshot_date",
                "$snapshots.content AS snapshot_content",
            ],
            'FROM'      => 'glpi_computers',
            'LEFT JOIN' => [
                $snapshots => [
                    'ON' => [
                        $snapshots       => 'computers_id',
                        'glpi_computers' => 'id',
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_computers.is_deleted'  => 0,
                'glpi_computers.is_template' => 0,
                'glpi_computers.is_dynamic'  => 1,
                'NOT'                        => ['glpi_computers.last_inventory_update' => null],
                'OR'                         => [
                    "$snapshots.id"             => null,
                    "$snapshots.inventory_date" => null,
                    new QueryExpression(
                        $DB->quoteName('glpi_computers.last_inventory_update') . ' > ' . $DB->quoteName("$snapshots.inventory_date")
                    ),
                ],
            ],
            'ORDER' => 'glpi_computers.last_inventory_update',
            'LIMIT' => $limit,
        ]);
        foreach ($iterator as $row) {
            $rows[(int) $row['id']] = $row;
        }
        return self::withTechGroups('Computer', $rows);
    }

    /**
     * Technical groups ("Group in charge") of assets. GLPI 11 stores them in
     * glpi_groups_items (several groups per asset allowed), no longer in a column.
     *
     * @param int[] $ids
     *
     * @return array<int, int[]> asset id => sorted group ids (assets without group are absent)
     */
    public static function techGroups(string $itemtype, array $ids): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $result = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 500) as $chunk) {
            $iterator = $DB->request([
                'SELECT' => ['items_id', 'groups_id'],
                'FROM'   => 'glpi_groups_items',
                'WHERE'  => [
                    'itemtype' => $itemtype,
                    'items_id' => $chunk,
                    'type'     => Group_Item::GROUP_TYPE_TECH,
                    'groups_id' => ['>', 0],
                ],
                'ORDER'  => ['items_id', 'groups_id'],
            ]);
            foreach ($iterator as $row) {
                $result[(int) $row['items_id']][] = (int) $row['groups_id'];
            }
        }
        return $result;
    }

    /**
     * Add `tech_groups` (all technical groups) and `groups_id_tech` (the first one, stored on
     * alert rows for display and search) to asset rows keyed by id.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    public static function withTechGroups(string $itemtype, array $rows): array
    {
        $groups = $rows === [] ? [] : self::techGroups($itemtype, array_keys($rows));
        foreach ($rows as $id => $row) {
            $rows[$id]['tech_groups']    = $groups[$id] ?? [];
            $rows[$id]['groups_id_tech'] = $groups[$id][0] ?? 0;
        }
        return $rows;
    }

    /**
     * Partitions of the given computers.
     *
     * @param int[] $computer_ids
     *
     * @return array<int, array<int, array<string, mixed>>> computer id => list of disks
     */
    public static function disksByComputer(array $computer_ids): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $result = [];
        foreach (array_chunk($computer_ids, 500) as $chunk) {
            $iterator = $DB->request([
                'SELECT' => [
                    'glpi_items_disks.items_id',
                    'glpi_items_disks.name',
                    'glpi_items_disks.mountpoint',
                    'glpi_items_disks.totalsize AS total_mb',
                    'glpi_items_disks.freesize AS free_mb',
                    'glpi_filesystems.name AS filesystem',
                ],
                'FROM'      => 'glpi_items_disks',
                'LEFT JOIN' => [
                    'glpi_filesystems' => [
                        'ON' => [
                            'glpi_items_disks' => 'filesystems_id',
                            'glpi_filesystems' => 'id',
                        ],
                    ],
                ],
                'WHERE' => [
                    'glpi_items_disks.itemtype'   => 'Computer',
                    'glpi_items_disks.items_id'   => $chunk,
                    'glpi_items_disks.is_deleted' => 0,
                ],
            ]);
            foreach ($iterator as $row) {
                $result[(int) $row['items_id']][] = $row;
            }
        }
        return $result;
    }

    /**
     * Raw rows needed to build a snapshot of one computer.
     *
     * @return array{memories: array, processors: array, drives: array, ports: array, ips: array}
     */
    public static function snapshotRows(int $computers_id): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $item = [
            'itemtype'   => 'Computer',
            'items_id'   => $computers_id,
            'is_deleted' => 0,
        ];

        $memories = iterator_to_array($DB->request([
            'SELECT' => ['size'],
            'FROM'   => 'glpi_items_devicememories',
            'WHERE'  => $item,
        ]), false);

        $processors = iterator_to_array($DB->request([
            'SELECT'     => ['glpi_deviceprocessors.designation'],
            'FROM'       => 'glpi_items_deviceprocessors',
            'INNER JOIN' => [
                'glpi_deviceprocessors' => [
                    'ON' => [
                        'glpi_items_deviceprocessors' => 'deviceprocessors_id',
                        'glpi_deviceprocessors'       => 'id',
                    ],
                ],
            ],
            'WHERE' => self::prefix('glpi_items_deviceprocessors', $item),
        ]), false);

        $drives = iterator_to_array($DB->request([
            'SELECT'     => [
                'glpi_items_deviceharddrives.serial',
                'glpi_items_deviceharddrives.capacity',
                'glpi_deviceharddrives.designation',
            ],
            'FROM'       => 'glpi_items_deviceharddrives',
            'INNER JOIN' => [
                'glpi_deviceharddrives' => [
                    'ON' => [
                        'glpi_items_deviceharddrives' => 'deviceharddrives_id',
                        'glpi_deviceharddrives'       => 'id',
                    ],
                ],
            ],
            'WHERE' => self::prefix('glpi_items_deviceharddrives', $item),
        ]), false);

        $ports = iterator_to_array($DB->request([
            'SELECT' => ['id', 'name', 'mac', 'instantiation_type'],
            'FROM'   => 'glpi_networkports',
            'WHERE'  => $item + ['NOT' => ['instantiation_type' => 'NetworkPortLocal']],
        ]), false);

        $ips = iterator_to_array($DB->request([
            'SELECT'     => ['glpi_ipaddresses.name AS ip', 'glpi_networkports.name AS port_name'],
            'FROM'       => 'glpi_ipaddresses',
            'INNER JOIN' => [
                'glpi_networknames' => [
                    'ON' => [
                        'glpi_ipaddresses'  => 'items_id',
                        'glpi_networknames' => 'id',
                        ['AND' => ['glpi_ipaddresses.itemtype' => 'NetworkName']],
                    ],
                ],
                'glpi_networkports' => [
                    'ON' => [
                        'glpi_networknames' => 'items_id',
                        'glpi_networkports' => 'id',
                        ['AND' => ['glpi_networknames.itemtype' => 'NetworkPort']],
                    ],
                ],
            ],
            'WHERE' => [
                'glpi_ipaddresses.mainitemtype'  => 'Computer',
                'glpi_ipaddresses.mainitems_id'  => $computers_id,
                'glpi_ipaddresses.is_deleted'    => 0,
                'glpi_networknames.is_deleted'   => 0,
                'glpi_networkports.is_deleted'   => 0,
                'NOT'                            => ['glpi_networkports.instantiation_type' => 'NetworkPortLocal'],
            ],
        ]), false);

        return [
            'memories'   => $memories,
            'processors' => $processors,
            'drives'     => $drives,
            'ports'      => $ports,
            'ips'        => $ips,
        ];
    }

    // ------------------------------------------------------------------
    // 2.5: probe, antivirus, agent versions, disk history
    // ------------------------------------------------------------------

    /**
     * GLPI Agents attached to computers.
     *
     * @param int[]|null $computer_ids null = all
     *
     * @return array<int, array{agents_id: int, port: int, version: string, last_contact: string|null}> computer id => agent
     */
    public static function agentsByComputer(?array $computer_ids = null): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $where = ['itemtype' => 'Computer', 'items_id' => ['>', 0]];
        if ($computer_ids !== null) {
            if ($computer_ids === []) {
                return [];
            }
            $where['items_id'] = $computer_ids;
        }
        $result = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'items_id', 'port', 'version', 'useragent', 'last_contact'],
            'FROM'   => 'glpi_agents',
            'WHERE'  => $where,
            'ORDER'  => 'last_contact DESC',
        ]) as $row) {
            // Several agents for one computer (reinstall): the most recent contact wins.
            $result[(int) $row['items_id']] ??= [
                'agents_id'    => (int) $row['id'],
                'port'         => (int) $row['port'] > 0 ? (int) $row['port'] : 62354,
                // "version" is only filled when the agent sends it in its metadata; the user agent
                // ("GLPI-Agent_v1.11") always carries it.
                'version'      => (string) (($row['version'] ?? '') ?: \GlpiPlugin\Assetwatch\Core\HealthRules::normalizeVersion((string) ($row['useragent'] ?? ''))),
                'last_contact' => $row['last_contact'] !== null ? (string) $row['last_contact'] : null,
            ];
        }
        return $result;
    }

    /**
     * IP addresses of assets (ports matching the excluded patterns, e.g. docker0, are skipped).
     *
     * @param int[]    $ids
     * @param string[] $excluded_ports wildcard patterns
     *
     * @return array<int, string[]> asset id => addresses
     */
    public static function ipsByItem(string $itemtype, array $ids, array $excluded_ports): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $result = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 500) as $chunk) {
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
                    'glpi_ipaddresses.mainitems_id' => $chunk,
                    'glpi_ipaddresses.is_deleted'   => 0,
                    'glpi_networknames.is_deleted'  => 0,
                    'glpi_networkports.is_deleted'  => 0,
                ],
                'ORDER'      => ['glpi_networkports.id', 'glpi_ipaddresses.id'],
            ]) as $row) {
                if (\GlpiPlugin\Assetwatch\Core\Wildcard::matchesAny((string) $row['port_name'], $excluded_ports)) {
                    continue;
                }
                $result[(int) $row['mainitems_id']][] = (string) $row['ip'];
            }
        }
        return $result;
    }

    /**
     * Assets placed in a rack (not deleted, not templates), by type.
     *
     * @param string[] $itemtypes
     *
     * @return array<string, array<int, array<string, mixed>>> itemtype => id => row (id, name, entities_id, tech groups)
     */
    public static function rackedAssets(array $itemtypes): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $ids = [];
        foreach ($DB->request([
            'SELECT' => ['itemtype', 'items_id'],
            'FROM'   => 'glpi_items_racks',
            'WHERE'  => ['itemtype' => $itemtypes],
        ]) as $row) {
            $ids[(string) $row['itemtype']][] = (int) $row['items_id'];
        }
        $result = [];
        foreach ($ids as $itemtype => $list) {
            if (!is_a($itemtype, CommonDBTM::class, true)) {
                continue;
            }
            $table = $itemtype::getTable();
            $where = ['id' => $list];
            foreach (['is_deleted' => 0, 'is_template' => 0] as $field => $value) {
                if ($DB->fieldExists($table, $field)) {
                    $where[$field] = $value;
                }
            }
            $rows = [];
            foreach ($DB->request(['SELECT' => ['id', 'name', 'entities_id'], 'FROM' => $table, 'WHERE' => $where]) as $row) {
                $rows[(int) $row['id']] = $row;
            }
            $result[$itemtype] = self::withTechGroups($itemtype, $rows);
        }
        return $result;
    }

    /**
     * Antivirus rows of computers (as reported by the agent).
     *
     * @param int[] $computer_ids
     *
     * @return array<int, array<int, array<string, mixed>>> computer id => antiviruses
     */
    public static function antivirusesByComputer(array $computer_ids): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $result = [];
        foreach (array_chunk($computer_ids, 500) as $chunk) {
            foreach ($DB->request([
                'SELECT' => ['items_id', 'name', 'is_active', 'is_uptodate', 'date_expiration', 'antivirus_version', 'signature_version'],
                'FROM'   => 'glpi_itemantiviruses',
                'WHERE'  => ['itemtype' => 'Computer', 'items_id' => $chunk, 'is_deleted' => 0],
            ]) as $row) {
                $result[(int) $row['items_id']][] = $row;
            }
        }
        return $result;
    }

    /**
     * Record the free space of each partition at an inventory (disk trend).
     *
     * @param array<string, array<string, mixed>> $measures DiskRule::measureAll() result
     */
    public static function recordDiskSamples(int $computers_id, array $measures, string $date): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $table = PluginAssetwatchInstall::DISK_HISTORY_TABLE;
        foreach ($measures as $mount => $m) {
            $exists = countElementsInTable($table, ['computers_id' => $computers_id, 'mountpoint' => (string) $mount, 'date' => $date]);
            if ($exists === 0) {
                $DB->insert($table, [
                    'computers_id' => $computers_id,
                    'mountpoint'   => (string) $mount,
                    'total_mb'     => (int) round((float) $m['total_gb'] * 1024),
                    'free_mb'      => (int) round((float) $m['free_gb'] * 1024),
                    'date'         => $date,
                ]);
            }
        }
    }

    /**
     * Free space samples since a date.
     *
     * @param int[] $computer_ids
     *
     * @return array<int, array<string, list<array{date: string, free_gb: float}>>> computer id => mount => samples
     */
    public static function diskSamples(array $computer_ids, string $since): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $result = [];
        foreach (array_chunk($computer_ids, 500) as $chunk) {
            foreach ($DB->request([
                'SELECT' => ['computers_id', 'mountpoint', 'free_mb', 'date'],
                'FROM'   => PluginAssetwatchInstall::DISK_HISTORY_TABLE,
                'WHERE'  => ['computers_id' => $chunk, 'date' => ['>=', $since]],
                'ORDER'  => 'date',
            ]) as $row) {
                $result[(int) $row['computers_id']][(string) $row['mountpoint']][] = [
                    'date'    => (string) $row['date'],
                    'free_gb' => round((int) $row['free_mb'] / 1024, 2),
                ];
            }
        }
        return $result;
    }

    /**
     * Current rack placement of an item, or null.
     *
     * @return array{rack: string, position: int, orientation: int, hpos: int}|null
     */
    public static function rackPlacement(int $racks_id, int $position, int $orientation, int $hpos): ?array
    {
        if ($racks_id <= 0) {
            return null;
        }
        return [
            'rack'        => self::rackLabel($racks_id),
            'position'    => $position,
            'orientation' => $orientation,
            'hpos'        => $hpos,
        ];
    }

    /**
     * Rack label including its location, e.g. "DC-A > Room 1 > R01".
     */
    public static function rackLabel(int $racks_id): string
    {
        $rack = new Rack();
        if (!$rack->getFromDB($racks_id)) {
            return '#' . $racks_id;
        }
        $label = (string) $rack->fields['name'];
        if (!empty($rack->fields['locations_id'])) {
            $location = Dropdown::getDropdownName('glpi_locations', (int) $rack->fields['locations_id'], false, false);
            if ($location !== '' && $location !== '&nbsp;') {
                $label = $location . ' > ' . $label;
            }
        }
        return $label;
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return array<string, mixed>
     */
    private static function prefix(string $table, array $criteria): array
    {
        $result = [];
        foreach ($criteria as $field => $value) {
            $result["$table.$field"] = $value;
        }
        return $result;
    }
}
