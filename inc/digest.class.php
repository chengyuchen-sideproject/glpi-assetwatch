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
 * One status digest e-mail (per technical group and run). Kept as history and
 * used as the object the "digest" notification is raised on.
 */
class PluginAssetwatchDigest extends CommonDBTM
{
    public static $rightname = 'plugin_assetwatch_alert';

    public $dohistory = false;

    public static function getTypeName($nb = 0)
    {
        return _n('Asset Watch digest', 'Asset Watch digests', $nb, 'assetwatch');
    }

    public static function canCreate()
    {
        return false;
    }

    public static function canUpdate()
    {
        return false;
    }

    /**
     * Store a digest and return it loaded.
     *
     * @param array{entities_id: int, groups_id_tech: int, nb_new: int, nb_reminder: int, nb_resolved: int, entries: array} $digest
     */
    public static function store(array $digest, string $now): ?self
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->insert(self::getTable(), PluginAssetwatchAlert::escapeRow([
            'entities_id'    => $digest['entities_id'],
            'groups_id_tech' => $digest['groups_id_tech'],
            'nb_new'         => $digest['nb_new'],
            'nb_reminder'    => $digest['nb_reminder'],
            'nb_resolved'    => $digest['nb_resolved'],
            'content'        => json_encode($digest['entries'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'date_creation'  => $now,
            'date_mod'       => $now,
        ]));
        $stored = new self();
        return $stored->getFromDB((int) $DB->insertId()) ? $stored : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getEntries(): array
    {
        $decoded = json_decode(Sanitizer::decodeHtmlSpecialChars((string) ($this->fields['content'] ?? '')), true);
        return is_array($decoded) ? $decoded : [];
    }
}
