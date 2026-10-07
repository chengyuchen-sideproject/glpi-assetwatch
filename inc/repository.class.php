<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Read-only access to GLPI inventory data needed by the checks.
 *
 * All SQL lives here, so that porting to GLPI 11 only touches this layer.
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
            'SELECT' => ['id', 'name', 'serial', 'entities_id', 'computertypes_id', 'groups_id_tech', 'last_inventory_update'],
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
        return $rows;
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
                'glpi_computers.groups_id_tech',
                'glpi_computers.last_inventory_update',
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
