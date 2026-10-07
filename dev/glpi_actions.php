<?php

/**
 * Asset Watch - development helper, run INSIDE the glpi container:
 *
 *   php plugins/assetwatch/dev/glpi_actions.php rack-place <computer name> <rack name> <position>
 *   php plugins/assetwatch/dev/glpi_actions.php rack-remove <computer name>
 *
 * Goes through GLPI objects (not raw SQL) so that plugin hooks fire exactly
 * as when a technician edits the rack in the web UI.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

chdir('/var/www/glpi');
include '/var/www/glpi/inc/includes.php';

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

    default:
        fwrite(STDERR, "unknown action\n");
        exit(2);
}
