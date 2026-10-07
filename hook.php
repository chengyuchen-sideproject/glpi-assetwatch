<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

/**
 * Install or upgrade the plugin.
 */
function plugin_assetwatch_install()
{
    $migration = new Migration(PLUGIN_ASSETWATCH_VERSION);
    PluginAssetwatchInstall::install($migration);
    return true;
}

/**
 * Uninstall the plugin and remove every trace of it.
 */
function plugin_assetwatch_uninstall()
{
    PluginAssetwatchInstall::uninstall();
    return true;
}

/**
 * Item_Rack hooks: notify about rack placement changes.
 *
 * @param CommonDBTM $item
 */
function plugin_assetwatch_item_rack_added(CommonDBTM $item)
{
    if ($item instanceof Item_Rack) {
        PluginAssetwatchRackWatcher::handle($item, PluginAssetwatchRackWatcher::EVENT_ADD);
    }
}

/**
 * @param CommonDBTM $item
 */
function plugin_assetwatch_item_rack_updated(CommonDBTM $item)
{
    if ($item instanceof Item_Rack) {
        PluginAssetwatchRackWatcher::handle($item, PluginAssetwatchRackWatcher::EVENT_UPDATE);
    }
}

/**
 * @param CommonDBTM $item
 */
function plugin_assetwatch_item_rack_purged(CommonDBTM $item)
{
    if ($item instanceof Item_Rack) {
        PluginAssetwatchRackWatcher::handle($item, PluginAssetwatchRackWatcher::EVENT_PURGE);
    }
}

/**
 * Computer purge hook: drop snapshot and alerts of a purged computer.
 *
 * @param CommonDBTM $item
 */
function plugin_assetwatch_computer_purged(CommonDBTM $item)
{
    if ($item instanceof Computer) {
        PluginAssetwatchSnapshot::purgeForComputer((int) $item->getID());
        PluginAssetwatchAlert::purgeForComputer((int) $item->getID());
    }
}
