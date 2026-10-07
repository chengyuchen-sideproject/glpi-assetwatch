<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

/**
 * Minimal PSR-4 autoloader for the GlpiPlugin\Assetwatch namespace, used by
 * the unit tests (tests/bootstrap.php) to load src/Core without GLPI.
 *
 * Inside GLPI it is not needed: Plugin::load() registers a PSR-4 loader for
 * the plugin's src/ directory itself.
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
