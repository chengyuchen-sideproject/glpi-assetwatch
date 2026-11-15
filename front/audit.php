<?php

/**
 * Asset Watch - GLPI plugin
 *
 * Datacenter inventory (Tools > Datacenter inventory). ?scope=room:<id>|dc:<id>|all|unassigned
 * &rack=<id> keeps one rack of the room (link of the QR code on the rack);
 * &export=csv downloads the same rows as a CSV file.
 *
 * @license GPL-3.0-or-later
 */

// GLPI 11 boots the application before running this file: no include needed.

use Symfony\Component\HttpFoundation\Response;

Session::checkRight(PluginAssetwatchAudit::$rightname, READ);

$scope = is_string($_GET['scope'] ?? null) ? $_GET['scope'] : '';
$rack = (int) ($_GET['rack'] ?? 0);

// 2.5: ask the agents of the room (or rack) to send their inventory now.
if (isset($_POST['request_inventory'])) {
    $scope = is_string($_POST['scope'] ?? null) ? $_POST['scope'] : '';
    $rack = (int) ($_POST['rack'] ?? 0);
    if (!PluginAssetwatchAudit::canRequestInventory()) {
        Session::addMessageAfterRedirect(__('You need the right to update computers.', 'assetwatch'), false, ERROR);
    } else {
        $r = PluginAssetwatchProbe::requestInventory(PluginAssetwatchAudit::computersOf($scope, $rack));
        Session::addMessageAfterRedirect(sprintf(
            __('Inventory requested from %1$d of %2$d agent(s): their data arrives in a few minutes, then reload the page.', 'assetwatch'),
            $r['requested'],
            $r['total']
        ));
        if ($r['refused'] > 0) {
            Session::addMessageAfterRedirect(sprintf(
                __('%1$d agent(s) refused (the GLPI host is not in their httpd-trust option): %2$s', 'assetwatch'),
                $r['refused'],
                implode(', ', array_slice($r['refused_names'], 0, 20))
            ), false, WARNING);
        }
        if ($r['failed'] > 0) {
            Session::addMessageAfterRedirect(sprintf(
                __('%1$d agent(s) did not answer (machine off, agent not running as a service, or port 62354 filtered): %2$s', 'assetwatch'),
                $r['failed'],
                implode(', ', array_slice($r['failed_names'], 0, 20))
            ), false, WARNING);
        }
    }
    Html::redirect(PluginAssetwatchAudit::getSearchURL() . '?' . http_build_query(array_filter(['scope' => $scope, 'rack' => $rack ?: null])));
}

if (($_GET['export'] ?? '') === 'csv') {
    $csv = PluginAssetwatchAudit::csv($scope, $rack);
    if ($csv !== null) {
        $name = 'assetwatch-inventory-' . preg_replace('/[^a-z0-9]+/i', '-', $scope) . ($rack > 0 ? '-rack' . $rack : '') . '-' . date('Ymd-His') . '.csv';
        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control'       => 'no-store',
        ]);
    }
}

Html::header(
    PluginAssetwatchAudit::getTypeName(),
    PluginAssetwatchAudit::getSearchURL(),
    'tools',
    PluginAssetwatchAudit::class
);

PluginAssetwatchAudit::showPage($scope, $rack);

Html::footer();
