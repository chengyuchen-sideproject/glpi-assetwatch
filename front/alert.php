<?php

/**
 * Asset Watch - GLPI plugin
 *
 * Alert list (Tools > Asset Watch).
 *
 * @license GPL-3.0-or-later
 */

include('../../../inc/includes.php');

Session::checkRight(PluginAssetwatchAlert::$rightname, READ);

Html::header(
    PluginAssetwatchAlert::getTypeName(Session::getPluralNumber()),
    $_SERVER['PHP_SELF'],
    'tools',
    PluginAssetwatchAlert::class
);

Search::show(PluginAssetwatchAlert::class);

Html::footer();
