<?php

/**
 * Asset Watch - GLPI plugin
 *
 * Configuration page (Setup > Plugins > Asset Watch).
 *
 * @license GPL-3.0-or-later
 */

include('../../../inc/includes.php');

Session::checkRight(PluginAssetwatchConfig::$rightname, UPDATE);

if (!Plugin::isPluginActive('assetwatch')) {
    Html::displayNotFoundError();
}

if (isset($_POST['update'])) {
    PluginAssetwatchConfig::saveFromForm($_POST);
    Session::addMessageAfterRedirect(__('Configuration saved.', 'assetwatch'));
    Html::back();
}

Html::header(PluginAssetwatchConfig::getTypeName(), $_SERVER['PHP_SELF'], 'config', 'plugin');
PluginAssetwatchConfig::showConfigForm();
Html::footer();
