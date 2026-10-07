<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

/**
 * Minimal PSR-4 autoloader for the GlpiPlugin\Assetwatch namespace.
 *
 * GLPI 10 does not autoload namespaced plugin classes without composer,
 * and this plugin intentionally ships without third-party dependencies.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'GlpiPlugin\\Assetwatch\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
