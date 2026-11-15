<?php

/**
 * Asset Watch - development helper: answers like the HTTP server of a GLPI Agent.
 * Run INSIDE the glpi container (PHP built-in server):
 *
 *   php -S 0.0.0.0:62354 plugins/assetwatch/dev/fake_agent_httpd.php
 *
 * /status -> "status: waiting"; /now -> 200 (inventory requested); every request is
 * appended to /tmp/aw-fakeagent.log so tests can check what GLPI asked.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
file_put_contents('/tmp/aw-fakeagent.log', date('c') . ' ' . $path . "\n", FILE_APPEND);

header('Content-Type: text/plain');
switch ($path) {
    case '/status':
        echo 'status: waiting';
        break;
    case '/now':
        echo 'OK';
        break;
    default:
        http_response_code(404);
        echo 'not found';
}
