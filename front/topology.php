<?php

/**
 * Asset Watch - GLPI plugin
 *
 * Network topology (Tools > Network topology). ?scope=room:<id>|dc:<id>|all|unassigned
 * &export=csv downloads the connections; &edit=1 shows the form to type connections by hand
 * (right "Network devices: Update"), posted back here with "save".
 *
 * @license GPL-3.0-or-later
 */

// GLPI 11 boots the application before running this file: no include needed.

use Symfony\Component\HttpFoundation\Response;

Session::checkRight(PluginAssetwatchTopology::$rightname, READ);

$scope = $_POST['scope'] ?? $_GET['scope'] ?? '';
$scope = is_string($scope) ? $scope : '';

if (isset($_POST['save'])) {
    Session::checkRight(PluginAssetwatchUplink::$rightname, UPDATE);
    $result = PluginAssetwatchTopology::save($_POST);
    Session::addMessageAfterRedirect(sprintf(
        __('Connections saved: %1$d added, %2$d removed.', 'assetwatch'),
        $result['added'],
        $result['removed']
    ));
    if ($result['rejected'] > 0) {
        Session::addMessageAfterRedirect(
            sprintf(__('%d connection(s) refused (asset or network device not found, or not visible).', 'assetwatch'), $result['rejected']),
            false,
            WARNING
        );
    }
    Html::redirect(PluginAssetwatchTopology::getSearchURL() . '?' . http_build_query(['scope' => $scope, 'edit' => 1]));
}

if (($_GET['export'] ?? '') === 'csv') {
    $csv = PluginAssetwatchTopology::csv($scope);
    if ($csv !== null) {
        $name = 'assetwatch-topology-' . preg_replace('/[^a-z0-9]+/i', '-', $scope) . '-' . date('Ymd-His') . '.csv';
        return new Response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control'       => 'no-store',
        ]);
    }
}

Html::header(
    PluginAssetwatchTopology::getTypeName(),
    PluginAssetwatchTopology::getSearchURL(),
    'tools',
    PluginAssetwatchTopology::class
);

PluginAssetwatchTopology::showPage($scope, ($_GET['edit'] ?? '') === '1');

Html::footer();
