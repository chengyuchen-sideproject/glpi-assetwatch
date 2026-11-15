<?php

/**
 * Asset Watch - GLPI plugin
 *
 * Configuration page (Setup > Plugins > Asset Watch).
 *
 * @license GPL-3.0-or-later
 */

// GLPI 11 boots the application before running this file: no include needed.

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @var array $CFG_GLPI */
global $CFG_GLPI;

$self_url = $CFG_GLPI['root_doc'] . '/plugins/assetwatch/front/config.form.php';

Session::checkRight(PluginAssetwatchConfig::$rightname, UPDATE);

if (!Plugin::isPluginActive('assetwatch')) {
    throw new NotFoundHttpException();
}

// 2.5: probe once without saving anything nor sending mail (to test the network path).
if (isset($_POST['probe_test'])) {
    // A large fleet takes about (machines / 64) x timeout seconds.
    @set_time_limit(600);
    $_SESSION['assetwatch_probe_test'] = PluginAssetwatchProbe::run(true);
    Html::redirect($self_url . '#probe');
}

if (isset($_POST['update'])) {
    PluginAssetwatchConfig::saveFromForm($_POST);
    Session::addMessageAfterRedirect(__('Configuration saved.', 'assetwatch'));
    Html::redirect($self_url);
}

Html::header(PluginAssetwatchConfig::getTypeName(), $self_url, 'config', 'plugin');
PluginAssetwatchConfig::showConfigForm();
Html::footer();
