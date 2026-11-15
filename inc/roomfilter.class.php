<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Dashboard\Filters\AbstractFilter;
use Glpi\DBAL\QuerySubQuery;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Dashboard filter "Server room": keeps the assets placed in a rack of the room, plus the assets
 * whose location is the room location or below it (computers not yet racked).
 *
 * Works on the Asset Watch cards and on GLPI's own cards of rackable assets (computers, network
 * equipments, PDUs...). Add it with the filter button of the dashboard toolbar.
 */
class PluginAssetwatchRoomFilter extends AbstractFilter
{
    public static function getName(): string
    {
        return __('Server room', 'assetwatch');
    }

    public static function getId(): string
    {
        return 'plugin_assetwatch_dcroom';
    }

    public static function canBeApplied(string $table): bool
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $itemtype = getItemTypeForTable($table);
        return in_array($itemtype, $CFG_GLPI['rackable_types'], true);
    }

    public static function getCriteria(string $table, $value): array
    {
        $dcrooms_id = (int) $value;
        if ($dcrooms_id <= 0) {
            return [];
        }
        return ['WHERE' => [self::roomCondition($table, getItemTypeForTable($table), $dcrooms_id)]];
    }

    /**
     * WHERE condition "this asset belongs to the room" for the given table.
     *
     * @return array<string, mixed>
     */
    public static function roomCondition(string $table, string $itemtype, int $dcrooms_id): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $or = [
            "$table.id" => new QuerySubQuery([
                'SELECT'     => 'glpi_items_racks.items_id',
                'FROM'       => 'glpi_items_racks',
                'INNER JOIN' => ['glpi_racks' => ['ON' => ['glpi_items_racks' => 'racks_id', 'glpi_racks' => 'id']]],
                'WHERE'      => [
                    'glpi_items_racks.itemtype' => $itemtype,
                    'glpi_racks.dcrooms_id'     => $dcrooms_id,
                    'glpi_racks.is_deleted'     => 0,
                ],
            ]),
        ];
        $room = new DCRoom();
        if ($room->getFromDB($dcrooms_id) && (int) $room->fields['locations_id'] > 0 && $DB->fieldExists($table, 'locations_id')) {
            $or["$table.locations_id"] = array_values(getSonsOf('glpi_locations', (int) $room->fields['locations_id']));
        }
        return ['OR' => $or];
    }

    public static function getSearchCriteria(string $table, $value): array
    {
        // Rack membership cannot be expressed with search criteria; GLPI then links to the full list.
        return [];
    }

    public static function getHtml($value): string
    {
        return self::displayList(
            self::getName(),
            is_scalar($value) ? (string) $value : '',
            self::getId(),
            DCRoom::class
        );
    }
}
