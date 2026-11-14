<?php

/**
 * Asset Watch - GLPI plugin
 *
 * Alert list (Tools > Asset Watch).
 *
 * @license GPL-3.0-or-later
 */

// GLPI 11 boots the application before running this file: no include needed.

Session::checkRight(PluginAssetwatchAlert::$rightname, READ);

Html::header(
    PluginAssetwatchAlert::getTypeName(Session::getPluralNumber()),
    PluginAssetwatchAlert::getSearchURL(),
    'tools',
    PluginAssetwatchAlert::class
);

Search::show(PluginAssetwatchAlert::class);

Html::footer();
