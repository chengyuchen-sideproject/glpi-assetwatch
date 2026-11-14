<?php

/**
 * Asset Watch - GLPI plugin
 *
 * Alert detail page, acknowledge / un-acknowledge actions.
 *
 * @license GPL-3.0-or-later
 */

// GLPI 11 boots the application before running this file: no include needed.

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
        // GLPI 11 passes $_POST raw; the comment is stored as typed and escaped on output.
        $comment = (string) ($_POST['ack_comment'] ?? '');
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
    PluginAssetwatchAlert::getFormURL(),
    'tools',
    PluginAssetwatchAlert::class
);
$alert->showForm($id);
Html::footer();
