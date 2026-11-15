<?php

/**
 * Asset Watch - GLPI plugin
 *
 * Printable rack labels: ?rack=<id> (one rack) or ?room=<id> (every rack of a server room);
 * &format=sticker prints only the QR code of each rack (several per A4 sheet).
 *
 * @license GPL-3.0-or-later
 */

// GLPI 11 boots the application before running this file: no include needed.

use Symfony\Component\HttpFoundation\Response;

Session::checkRight(PluginAssetwatchRackLabel::$rightname, READ);

$racks = PluginAssetwatchRackLabel::resolveRacks((int) ($_GET['rack'] ?? 0), (int) ($_GET['room'] ?? 0));

$format = ($_GET['format'] ?? '') === PluginAssetwatchRackLabel::FORMAT_STICKER
    ? PluginAssetwatchRackLabel::FORMAT_STICKER
    : PluginAssetwatchRackLabel::FORMAT_LABEL;

return new Response(PluginAssetwatchRackLabel::render($racks, $format), 200, [
    'Content-Type'  => 'text/html; charset=UTF-8',
    'Cache-Control' => 'no-store',
]);
