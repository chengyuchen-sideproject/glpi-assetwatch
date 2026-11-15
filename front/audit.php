<?php

/**
 * Asset Watch - GLPI plugin
 *
 * Datacenter inventory (Tools > Datacenter inventory). ?scope=room:<id>|dc:<id>|all|unassigned
 * &export=csv downloads the same rows as a CSV file.
 *
 * @license GPL-3.0-or-later
 */

// GLPI 11 boots the application before running this file: no include needed.

use Symfony\Component\HttpFoundation\Response;

Session::checkRight(PluginAssetwatchAudit::$rightname, READ);

$scope = is_string($_GET['scope'] ?? null) ? $_GET['scope'] : '';

if (($_GET['export'] ?? '') === 'csv') {
    $csv = PluginAssetwatchAudit::csv($scope);
    if ($csv !== null) {
        $name = 'assetwatch-inventory-' . preg_replace('/[^a-z0-9]+/i', '-', $scope) . '-' . date('Ymd-His') . '.csv';
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

PluginAssetwatchAudit::showPage($scope);

Html::footer();
