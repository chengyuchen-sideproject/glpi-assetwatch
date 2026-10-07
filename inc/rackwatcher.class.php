<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

use GlpiPlugin\Assetwatch\Core\RackChange;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Real-time notification when someone changes the rack placement of an item.
 */
class PluginAssetwatchRackWatcher
{
    public const EVENT_ADD    = 'add';
    public const EVENT_UPDATE = 'update';
    public const EVENT_PURGE  = 'purge';

    private const PLACEMENT_FIELDS = ['racks_id', 'position', 'orientation', 'hpos'];

    public static function handle(Item_Rack $relation, string $event): void
    {
        if (!PluginAssetwatchConfig::getSettings()->checkRack) {
            return;
        }

        $fields = $relation->fields;
        $before = null;
        $after = null;

        switch ($event) {
            case self::EVENT_ADD:
                $after = self::placement($fields);
                break;
            case self::EVENT_PURGE:
                $before = self::placement($fields);
                break;
            case self::EVENT_UPDATE:
                if (array_intersect($relation->updates, self::PLACEMENT_FIELDS) === []) {
                    return;
                }
                $after = self::placement($fields);
                $before = self::placement(array_merge($fields, array_intersect_key($relation->oldvalues, array_flip(self::PLACEMENT_FIELDS))));
                break;
            default:
                return;
        }

        $change = RackChange::describe($before, $after);
        if ($change === null) {
            return;
        }

        $item = getItemForItemtype((string) $fields['itemtype']);
        if (!$item || !$item->getFromDB((int) $fields['items_id'])) {
            return;
        }

        $users_id = (int) Session::getLoginUserID();
        $content = $change + [
            'users_id' => $users_id,
            'user'     => $users_id > 0 ? getUserName($users_id) : __('Automatic action', 'assetwatch'),
        ];

        PluginAssetwatchMonitor::raiseEventAlert(
            $item->getType(),
            [
                'id'             => $item->getID(),
                'name'           => $item->fields['name'] ?? '',
                'entities_id'    => $item->fields['entities_id'] ?? 0,
                'groups_id_tech' => $item->fields['groups_id_tech'] ?? 0,
            ],
            PluginAssetwatchAlert::TYPE_RACK,
            $content,
            $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s')
        );
    }

    /**
     * @param array<string, mixed> $fields Item_Rack fields
     *
     * @return array<string, mixed>|null
     */
    private static function placement(array $fields): ?array
    {
        return PluginAssetwatchRepository::rackPlacement(
            (int) ($fields['racks_id'] ?? 0),
            (int) ($fields['position'] ?? 0),
            (int) ($fields['orientation'] ?? 0),
            (int) ($fields['hpos'] ?? 0)
        );
    }
}
