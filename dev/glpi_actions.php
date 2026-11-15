<?php

/**
 * Asset Watch - development helper, run INSIDE the glpi container:
 *
 *   php plugins/assetwatch/dev/glpi_actions.php rack-place <computer name> <rack name> <position>
 *   php plugins/assetwatch/dev/glpi_actions.php rack-remove <computer name>
 *   php plugins/assetwatch/dev/glpi_actions.php purge <computer name>...
 *
 * Goes through GLPI objects (not raw SQL) so that plugin hooks fire exactly
 * as when a technician edits the rack in the web UI.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

chdir('/var/www/glpi');
// GLPI 11: boot the kernel (database connection, configuration, active plugins and their hooks)
// the same way bin/console does; inc/includes.php no longer bootstraps anything.
require '/var/www/glpi/vendor/autoload.php';
$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();

// Act as the "glpi" super-admin, like a logged-in technician.
$user = new User();
$user->getFromDBbyName('glpi');
$auth = new Auth();
$auth->auth_succeded = true;
$auth->user = $user;
Session::init($auth);
Session::loadLanguage('en_GB');

[$script, $action] = array_pad($argv, 2, '');

function find_computer(string $name): Computer
{
    $computer = new Computer();
    if (!$computer->getFromDBByCrit(['name' => $name, 'is_deleted' => 0])) {
        fwrite(STDERR, "computer not found: $name\n");
        exit(1);
    }
    return $computer;
}

function find_or_create_rack(string $name): Rack
{
    $rack = new Rack();
    if (!$rack->getFromDBByCrit(['name' => $name])) {
        $rack->add(['name' => $name, 'entities_id' => 0, 'number_units' => 42]);
    }
    return $rack;
}

switch ($action) {
    case 'rack-place':
        $computer = find_computer($argv[2]);
        $rack = find_or_create_rack($argv[3]);
        $position = (int) $argv[4];
        $relation = new Item_Rack();
        if ($relation->getFromDBByCrit(['itemtype' => 'Computer', 'items_id' => $computer->getID()])) {
            $ok = $relation->update(['id' => $relation->getID(), 'racks_id' => $rack->getID(), 'position' => $position]);
        } else {
            $ok = $relation->add([
                'itemtype'    => 'Computer',
                'items_id'    => $computer->getID(),
                'racks_id'    => $rack->getID(),
                'position'    => $position,
                'orientation' => Rack::FRONT,
            ]);
        }
        echo $ok ? "placed {$argv[2]} in {$argv[3]} U$position\n" : "failed\n";
        exit($ok ? 0 : 1);

    case 'rack-remove':
        $computer = find_computer($argv[2]);
        $relation = new Item_Rack();
        if ($relation->getFromDBByCrit(['itemtype' => 'Computer', 'items_id' => $computer->getID()])) {
            $relation->delete(['id' => $relation->getID()], true);
            echo "removed {$argv[2]} from rack\n";
        }
        exit(0);

    case 'purge':
        // Remove test computers entirely (with ports, disks, components...) so a
        // re-run starts from the same empty state as a fresh stack.
        foreach (array_slice($argv, 2) as $name) {
            $computer = new Computer();
            foreach ($computer->find(['name' => $name]) as $row) {
                $computer->delete(['id' => $row['id']], true);
            }
        }
        exit(0);

    case 'audit-fixture':
        // Datacenter "AW DC" with two rooms; Room A has a location, Room B has none.
        //   AW-RA1 (Room A): aw-aud01 U10 (agent), aw-ghost U20 (manual computer, same serial as aw-aud01),
        //                    aw-sw01 U42 (switch, no agent), aw-pdu01 U1 (PDU)
        //   AW-RB1 (Room B): aw-aud03 U5 (agent)
        //   aw-aud02: agent, no rack, location = Room A
        $location = new Location();
        if (!$location->getFromDBByCrit(['name' => 'AW Room A'])) {
            $location->add(['name' => 'AW Room A', 'entities_id' => 0, 'is_recursive' => 1]);
        }
        $dc = new Datacenter();
        if (!$dc->getFromDBByCrit(['name' => 'AW DC'])) {
            $dc->add(['name' => 'AW DC', 'entities_id' => 0]);
        }
        $rooms = [];
        foreach (['AW Room A' => $location->getID(), 'AW Room B' => 0] as $name => $locations_id) {
            $room = new DCRoom();
            if (!$room->getFromDBByCrit(['name' => $name])) {
                $room->add(['name' => $name, 'entities_id' => 0, 'datacenters_id' => $dc->getID(), 'locations_id' => $locations_id, 'vis_cols' => 10, 'vis_rows' => 10]);
            }
            $rooms[$name] = $room->getID();
        }
        $racks = [];
        foreach (['AW-RA1' => 'AW Room A', 'AW-RB1' => 'AW Room B'] as $name => $room_name) {
            $rack = find_or_create_rack($name);
            $rack->update(['id' => $rack->getID(), 'dcrooms_id' => $rooms[$room_name], 'position' => $name === 'AW-RA1' ? '1,1' : '2,2']);
            $racks[$name] = $rack->getID();
        }
        $sw = new NetworkEquipment();
        if (!$sw->getFromDBByCrit(['name' => 'aw-sw01'])) {
            $sw->add(['name' => 'aw-sw01', 'entities_id' => 0, 'serial' => 'SW-0001']);
        }
        $pdu = new PDU();
        if (!$pdu->getFromDBByCrit(['name' => 'aw-pdu01'])) {
            $pdu->add(['name' => 'aw-pdu01', 'entities_id' => 0]);
        }
        $ghost = new Computer();
        if (!$ghost->getFromDBByCrit(['name' => 'aw-ghost'])) {
            $ghost->add(['name' => 'aw-ghost', 'entities_id' => 0, 'serial' => find_computer('aw-aud01')->fields['serial']]);
        }
        $aud02 = find_computer('aw-aud02');
        $aud02->update(['id' => $aud02->getID(), 'locations_id' => $location->getID()]);
        $places = [
            [Computer::class, find_computer('aw-aud01')->getID(), 'AW-RA1', 10],
            [Computer::class, $ghost->getID(), 'AW-RA1', 20],
            [NetworkEquipment::class, $sw->getID(), 'AW-RA1', 42],
            [PDU::class, $pdu->getID(), 'AW-RA1', 1],
            [Computer::class, find_computer('aw-aud03')->getID(), 'AW-RB1', 5],
        ];
        foreach ($places as [$itemtype, $id, $rack_name, $position]) {
            $relation = new Item_Rack();
            if (!$relation->getFromDBByCrit(['itemtype' => $itemtype, 'items_id' => $id])) {
                $relation->add([
                    'itemtype' => $itemtype, 'items_id' => $id, 'racks_id' => $racks[$rack_name],
                    'position' => $position, 'orientation' => Rack::FRONT,
                ]);
            }
        }
        echo json_encode(['rooms' => $rooms, 'racks' => $racks, 'location' => $location->getID()]), "\n";
        exit(0);

    case 'topology-fixture':
        // After audit-fixture (2.3): floor map, rack labels and network topology.
        //   model "AW-2U" (2U, 500 W) on aw-aud01; AW-RA1 max power 1000 W
        //   aw-sw02 (switch) in AW-RB1 U40
        //   GLPI ports: aw-sw01 Gi1/0/10 <-> aw-aud01 (agent port), aw-sw01 Te1/1 <-> aw-sw02 Te1/48
        //   typed by hand: aw-aud03 -> aw-sw02 Gi1/0/5, aw-aud02 -> aw-sw01 (no port); aw-ghost: none
        $model = new ComputerModel();
        if (!$model->getFromDBByCrit(['name' => 'AW-2U'])) {
            $model->add(['name' => 'AW-2U', 'required_units' => 2, 'power_consumption' => 500]);
        }
        $aud01 = find_computer('aw-aud01');
        $aud01->update(['id' => $aud01->getID(), 'computermodels_id' => $model->getID()]);
        $ra1 = new Rack();
        $ra1->getFromDBByCrit(['name' => 'AW-RA1']);
        $ra1->update(['id' => $ra1->getID(), 'max_power' => 1000]);
        $rb1 = new Rack();
        $rb1->getFromDBByCrit(['name' => 'AW-RB1']);
        $sw1 = new NetworkEquipment();
        $sw1->getFromDBByCrit(['name' => 'aw-sw01']);
        $sw2 = new NetworkEquipment();
        if (!$sw2->getFromDBByCrit(['name' => 'aw-sw02'])) {
            $sw2->add(['name' => 'aw-sw02', 'entities_id' => 0, 'serial' => 'SW-0002']);
        }
        $relation = new Item_Rack();
        if (!$relation->getFromDBByCrit(['itemtype' => 'NetworkEquipment', 'items_id' => $sw2->getID()])) {
            $relation->add(['itemtype' => 'NetworkEquipment', 'items_id' => $sw2->getID(), 'racks_id' => $rb1->getID(), 'position' => 40, 'orientation' => Rack::FRONT]);
        }
        $port = static function (CommonDBTM $item, string $name, int $number): int {
            $p = new NetworkPort();
            if ($p->getFromDBByCrit(['itemtype' => $item::class, 'items_id' => $item->getID(), 'name' => $name])) {
                return $p->getID();
            }
            return (int) $p->add([
                'itemtype' => $item::class, 'items_id' => $item->getID(), 'entities_id' => 0,
                'name' => $name, 'logical_number' => $number, 'instantiation_type' => 'NetworkPortEthernet',
            ]);
        };
        $connect = static function (int $a, int $b): void {
            $link = new NetworkPort_NetworkPort();
            if (!$link->getFromDBByCrit(['OR' => [['networkports_id_1' => $a], ['networkports_id_2' => $a]]])) {
                $link->add(['networkports_id_1' => $a, 'networkports_id_2' => $b]);
            }
        };
        $agent_port = new NetworkPort();
        $first = $agent_port->find(['itemtype' => 'Computer', 'items_id' => $aud01->getID(), 'NOT' => ['instantiation_type' => 'NetworkPortLocal']], 'id', 1);
        $agent_port->getFromDB((int) array_key_first($first));
        $connect($port($sw1, 'Gi1/0/10', 10), $agent_port->getID());
        $connect($port($sw1, 'Te1/1', 49), $port($sw2, 'Te1/48', 48));
        foreach ([['aw-aud03', $sw2, 'Gi1/0/5'], ['aw-aud02', $sw1, '']] as [$name, $switch, $switch_port]) {
            $uplink = new PluginAssetwatchUplink();
            $computer = find_computer($name);
            if (!$uplink->getFromDBByCrit(['itemtype' => 'Computer', 'items_id' => $computer->getID()])) {
                $uplink->add(['itemtype' => 'Computer', 'items_id' => $computer->getID(), 'networkequipments_id' => $switch->getID(), 'switch_port' => $switch_port]);
            }
        }
        $ghost = new Computer();
        $ghost->getFromDBByCrit(['name' => 'aw-ghost']);
        echo json_encode(['sw1' => $sw1->getID(), 'sw2' => $sw2->getID(), 'ghost' => $ghost->getID(),
            'ra1' => $ra1->getID(), 'agent_port' => $agent_port->fields['name'] ?? ''], JSON_UNESCAPED_UNICODE), "\n";
        exit(0);

    case 'topology-json':
        // php glpi_actions.php topology-json <scope> -> connections by name
        $scope = PluginAssetwatchAudit::resolveScope($argv[2] ?? '', PluginAssetwatchAudit::rooms());
        if ($scope === null) {
            fwrite(STDERR, "unknown scope\n");
            exit(1);
        }
        $data = PluginAssetwatchTopology::data($scope);
        $name = static fn (string $key): string => $data['nodes'][$key]['name'];
        $devices = [];
        foreach ($data['graph']['devices'] as $key => $links) {
            $devices[$name($key)] = array_map(static fn (array $l): string => trim($name($l['switch']) . ' ' . $l['switch_port']) . ' ' . implode('+', $l['sources']), $links);
        }
        echo json_encode([
            'switches'    => array_map($name, $data['graph']['switches']),
            'devices'     => $devices,
            'peers'       => array_map(static fn (array $l): string => $name($l['a']) . ' ' . $l['a_port'] . ' - ' . $name($l['b']) . ' ' . $l['b_port'], $data['graph']['switch_links']),
            'unconnected' => array_map($name, $data['graph']['unconnected']),
            'conflicts'   => array_map(static fn (array $c): string => $name($c['switch']) . ' ' . $c['port'] . ': ' . implode(',', array_map($name, $c['keys'])), $data['graph']['port_conflicts']),
            'counts'      => $data['counts'],
            'edges'       => count($data['layout']['edges']),
        ], JSON_UNESCAPED_UNICODE), "\n";
        exit(0);

    case 'rack-path':
        // php glpi_actions.php rack-path <rack id> -> link encoded in the QR code of the rack
        $rack = new Rack();
        $rack->getFromDB((int) ($argv[2] ?? 0));
        echo PluginAssetwatchAudit::rackPath($rack->getID(), (int) ($rack->fields['dcrooms_id'] ?? 0)), "\n";
        exit(0);

    case 'request-inventory':
        // php glpi_actions.php request-inventory <computer name>... -> "inventory now" summary
        $ids = [];
        foreach (array_slice($argv, 2) as $name) {
            $ids[] = find_computer($name)->getID();
        }
        echo json_encode(PluginAssetwatchProbe::requestInventory($ids)), "\n";
        exit(0);

    case 'probe-dry':
        // php glpi_actions.php probe-dry -> probe once, nothing saved (configuration test button)
        $summary = PluginAssetwatchProbe::run(true);
        echo json_encode([
            'total' => $summary['total'], 'ok' => $summary['ok'], 'agent_down' => $summary['agent_down'],
            'unreachable' => $summary['unreachable'], 'failures' => array_column($summary['failures'], 'result', 'name'),
        ]), "\n";
        exit(0);

    case 'roommap-json':
        // php glpi_actions.php roommap-json <room id> -> metrics per rack
        $room_id = (int) ($argv[2] ?? 0);
        $map = PluginAssetwatchRoomMap::build($room_id, PluginAssetwatchAudit::collect([$room_id])['items']);
        $racks = [];
        foreach ($map['cells'] ?? [] as $cell) {
            $racks[$cell['name']] = ['x' => $cell['x'], 'y' => $cell['y'], 'issues' => $cell['issues'], 'units_pct' => $cell['units_pct'],
                'power_pct' => $cell['power_pct'], 'levels' => $cell['levels'], 'used' => $cell['used_units']];
        }
        echo json_encode(['racks' => $racks, 'cols' => $map['cols'] ?? 0, 'unracked' => $map['unracked'] ?? 0]), "\n";
        exit(0);

    case 'audit-cleanup':
        foreach ([Computer::class => ['aw-ghost'], NetworkEquipment::class => ['aw-sw01', 'aw-sw02'], PDU::class => ['aw-pdu01'], ComputerModel::class => ['AW-2U'],
                  Rack::class => ['AW-RA1', 'AW-RB1'], DCRoom::class => ['AW Room A', 'AW Room B'],
                  Datacenter::class => ['AW DC'], Location::class => ['AW Room A']] as $itemtype => $names) {
            $item = new $itemtype();
            foreach ($item->find(['name' => $names]) as $row) {
                $item->delete(['id' => $row['id']], true);
            }
        }
        exit(0);

    case 'audit-json':
        // php glpi_actions.php audit-json <scope>   -> evaluated rows of the inventory page
        $scope = PluginAssetwatchAudit::resolveScope($argv[2] ?? '', PluginAssetwatchAudit::rooms());
        if ($scope === null) {
            fwrite(STDERR, "unknown scope\n");
            exit(1);
        }
        $result = PluginAssetwatchAudit::collect($scope['room_ids'], $scope['unassigned']);
        $rows = [];
        foreach ($result['items'] as $item) {
            $rows[$item['name']] = [
                'room' => $item['room'], 'rack' => $item['rack'], 'u' => $item['position'],
                'status' => $item['status'], 'issues' => array_keys($item['issues']), 'ips' => $item['ips'],
                'spec' => $item['spec'], 'groups' => $item['tech_groups'],
            ];
        }
        echo json_encode([
            'rows' => $rows, 'status' => $result['status_counts'], 'issues' => $result['issue_counts'],
            'with_issues' => $result['items_with_issues'], 'rooms' => array_column(PluginAssetwatchAudit::rooms(), 'id', 'name'),
        ], JSON_UNESCAPED_UNICODE), "\n";
        exit(0);

    case 'card':
        // php glpi_actions.php card <provider> [<filter id>=<value>...]  -> provider output as JSON
        $filters = [];
        foreach (array_slice($argv, 3) as $pair) {
            [$k, $v] = explode('=', $pair, 2);
            $filters[$k] = $v;
        }
        echo json_encode(call_user_func([PluginAssetwatchDashboard::class, $argv[2]], ['apply_filters' => $filters]), JSON_UNESCAPED_UNICODE), "\n";
        exit(0);

    default:
        fwrite(STDERR, "unknown action\n");
        exit(2);
}
