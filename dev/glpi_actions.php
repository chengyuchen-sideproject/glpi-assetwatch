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

    case 'audit-cleanup':
        foreach ([Computer::class => ['aw-ghost'], NetworkEquipment::class => ['aw-sw01'], PDU::class => ['aw-pdu01'],
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
