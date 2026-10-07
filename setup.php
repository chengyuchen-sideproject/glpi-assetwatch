<?php

/**
 * Asset Watch - GLPI plugin
 *
 * Watches GLPI Agent inventories of servers and sends e-mail notifications
 * when a machine stops reporting, runs low on disk space, changes hardware
 * or identity, or is moved inside a rack.
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Plugin\Hooks;

define('PLUGIN_ASSETWATCH_VERSION', '1.0.0');
// Supported GLPI range: 10.0.x only (GLPI 11 will be handled by the 2.x branch).
define('PLUGIN_ASSETWATCH_MIN_GLPI', '10.0.0');
define('PLUGIN_ASSETWATCH_MAX_GLPI', '10.0.99');

/**
 * Plugin initialization, called on every GLPI page load.
 */
function plugin_init_assetwatch()
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['assetwatch'] = true;

    if (!Plugin::isPluginActive('assetwatch')) {
        return;
    }

    Plugin::registerClass(PluginAssetwatchProfile::class, ['addtabon' => [Profile::class]]);
    Plugin::registerClass(PluginAssetwatchAlert::class, [
        // Computers get all checks; other rackable assets only get rack placement alerts.
        'addtabon'                    => [
            Computer::class,
            NetworkEquipment::class,
            Peripheral::class,
            Enclosure::class,
            PDU::class,
            PassiveDCEquipment::class,
        ],
        'notificationtemplates_types' => true,
    ]);
    Plugin::registerClass(PluginAssetwatchDigest::class, [
        'notificationtemplates_types' => true,
    ]);

    // Rack placement changes are detected in real time.
    $PLUGIN_HOOKS[Hooks::ITEM_ADD]['assetwatch']    = [Item_Rack::class => 'plugin_assetwatch_item_rack_added'];
    $PLUGIN_HOOKS[Hooks::ITEM_UPDATE]['assetwatch'] = [Item_Rack::class => 'plugin_assetwatch_item_rack_updated'];
    $PLUGIN_HOOKS[Hooks::ITEM_PURGE]['assetwatch']  = [
        Item_Rack::class => 'plugin_assetwatch_item_rack_purged',
        // Snapshots and alerts follow the computer lifecycle.
        Computer::class  => 'plugin_assetwatch_computer_purged',
    ];

    if (Session::haveRight(PluginAssetwatchAlert::$rightname, READ)) {
        $PLUGIN_HOOKS[Hooks::MENU_TOADD]['assetwatch'] = ['tools' => PluginAssetwatchAlert::class];
    }
    if (Session::haveRight(PluginAssetwatchConfig::$rightname, UPDATE)) {
        $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['assetwatch'] = 'front/config.form.php';
    }
}

/**
 * Plugin metadata.
 */
function plugin_version_assetwatch()
{
    return [
        'name'         => 'Asset Watch',
        'version'      => PLUGIN_ASSETWATCH_VERSION,
        'author'       => 'chengyuchen-sideproject',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/chengyuchen-sideproject/glpi-assetwatch',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_ASSETWATCH_MIN_GLPI,
                'max' => PLUGIN_ASSETWATCH_MAX_GLPI,
            ],
            'php' => [
                'min' => '7.4',
            ],
        ],
    ];
}

/**
 * Prerequisites check before install.
 */
function plugin_assetwatch_check_prerequisites()
{
    return true;
}

/**
 * Configuration check.
 *
 * @param bool $verbose
 */
function plugin_assetwatch_check_config($verbose = false)
{
    return true;
}
