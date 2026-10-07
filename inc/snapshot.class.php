<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Toolbox\Sanitizer;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Baseline of a computer (hardware + identity), compared after each new inventory.
 */
class PluginAssetwatchSnapshot extends CommonDBTM
{
    public static $rightname = 'plugin_assetwatch_alert';

    public static function getTypeName($nb = 0)
    {
        return _n('Baseline', 'Baselines', $nb, 'assetwatch');
    }

    /**
     * @return array{inventory_date: ?string, date_mod: ?string, content: array<string, mixed>}|null
     */
    public static function getForComputer(int $computers_id): ?array
    {
        /** @var DBmysql $DB */
        global $DB;

        $row = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['computers_id' => $computers_id],
        ])->current();
        if (!$row) {
            return null;
        }
        return [
            'inventory_date' => $row['inventory_date'],
            'date_mod'       => $row['date_mod'],
            'content'        => self::decode($row['content']),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function decode(?string $stored): ?array
    {
        if ($stored === null || $stored === '') {
            return null;
        }
        $decoded = json_decode(Sanitizer::decodeHtmlSpecialChars($stored), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $content
     */
    public static function save(int $computers_id, string $inventory_date, array $content, string $now): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $values = PluginAssetwatchAlert::escapeRow([
            'inventory_date' => $inventory_date,
            'content'        => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'date_mod'       => $now,
        ]);
        if (countElementsInTable(self::getTable(), ['computers_id' => $computers_id]) > 0) {
            $DB->update(self::getTable(), $values, ['computers_id' => $computers_id]);
        } else {
            $DB->insert(self::getTable(), $values + ['computers_id' => $computers_id, 'date_creation' => $now]);
        }
    }

    public static function purgeForComputer(int $computers_id): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->delete(self::getTable(), ['computers_id' => $computers_id]);
    }
}
