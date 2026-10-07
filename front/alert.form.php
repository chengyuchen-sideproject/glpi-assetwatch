<?php

/**
 * Asset Watch - GLPI plugin
 *
 * Alert detail page, acknowledge / un-acknowledge actions.
 *
 * @license GPL-3.0-or-later
 */

include('../../../inc/includes.php');

Session::checkRight(PluginAssetwatchAlert::$rightname, READ);

$alert = new PluginAssetwatchAlert();

if (isset($_POST['acknowledge']) || isset($_POST['unacknowledge'])) {
    Session::checkRight(PluginAssetwatchAlert::$rightname, PluginAssetwatchAlert::ACKNOWLEDGE);
    $id = (int) ($_POST['id'] ?? 0);
    $alert->check($id, READ);

    if (isset($_POST['acknowledge'])) {
        $until = trim((string) ($_POST['date_ack_until'] ?? ''));
        if ($until !== '' && strtotime($until) === false) {
            $until = '';
        }
        // $_POST is already sanitized by GLPI; store the comment as-is (decoded once, re-encoded by updateAlert).
        $comment = Glpi\Toolbox\Sanitizer::unsanitize((string) ($_POST['ack_comment'] ?? ''));
        if ($alert->acknowledge($comment, $until !== '' ? date('Y-m-d H:i:s', strtotime($until)) : null)) {
            Session::addMessageAfterRedirect(__('Alert acknowledged.', 'assetwatch'));
        }
    } elseif ($alert->unacknowledge()) {
        Session::addMessageAfterRedirect(__('Acknowledgement removed: reminders resume.', 'assetwatch'));
    }
    Html::back();
}

$id = (int) ($_GET['id'] ?? 0);
Html::header(
    PluginAssetwatchAlert::getTypeName(1),
    $_SERVER['PHP_SELF'],
    'tools',
    PluginAssetwatchAlert::class
);
$alert->showForm($id);
Html::footer();
