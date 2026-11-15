<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Toolbox\SanitizedStringsDecoder;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Install / upgrade / uninstall routines.
 *
 * Every step is idempotent so that re-running install (upgrade) is safe.
 */
class PluginAssetwatchInstall
{
    public const CONFIG_CONTEXT = 'plugin:assetwatch';

    public const RIGHT_ALERT  = 'plugin_assetwatch_alert';
    public const RIGHT_CONFIG = 'plugin_assetwatch_config';

    /**
     * Config key telling how text is stored in the plugin tables. 1.x (GLPI 10) never wrote it;
     * 2.x writes "raw" once the data is in GLPI 11 form. The version recorded in glpi_plugins
     * cannot be used: GLPI already sets it to the new version before calling the install.
     */
    public const STORAGE_FORMAT_KEY = 'storage_format';

    /** 2.5: free space of each partition at each inventory (disk trend). */
    public const DISK_HISTORY_TABLE = 'glpi_plugin_assetwatch_diskhistories';

    public static function install(Migration $migration): void
    {
        /** @var DBmysql $DB */
        global $DB;

        // Tables existing before this run = upgrade (or reinstall); missing = fresh install.
        $had_data = $DB->tableExists(PluginAssetwatchAlert::getTable());

        self::createTables($migration);
        if ($had_data && Config::getConfigurationValue(self::CONFIG_CONTEXT, self::STORAGE_FORMAT_KEY) !== 'raw') {
            self::decodeLegacyData($migration);
        }
        Config::setConfigurationValues(self::CONFIG_CONTEXT, [self::STORAGE_FORMAT_KEY => 'raw']);
        self::installConfig();
        self::installRights();
        self::installCronTasks();
        self::installNotifications();
        self::installDisplayPreferences();
        PluginAssetwatchDashboard::installDefaultDashboard();
        $migration->executeMigration();
    }

    public static function uninstall(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        foreach (self::tableNames() as $table) {
            if ($DB->tableExists($table)) {
                $DB->doQueryOrDie("DROP TABLE `$table`", $DB->error());
            }
        }

        Config::deleteConfigurationValues(
            self::CONFIG_CONTEXT,
            array_merge(array_keys(PluginAssetwatchConfig::defaults()), [self::STORAGE_FORMAT_KEY])
        );
        ProfileRight::deleteProfileRights([self::RIGHT_ALERT, self::RIGHT_CONFIG]);
        CronTask::unregister('assetwatch');
        self::uninstallNotifications();
        PluginAssetwatchDashboard::uninstallDashboards();

        $itemtypes = [PluginAssetwatchAlert::class, PluginAssetwatchDigest::class, PluginAssetwatchSnapshot::class, PluginAssetwatchUplink::class];
        foreach (['glpi_displaypreferences', 'glpi_savedsearches', 'glpi_logs', 'glpi_queuednotifications'] as $table) {
            if ($DB->tableExists($table)) {
                $DB->delete($table, ['itemtype' => $itemtypes]);
            }
        }
    }

    /**
     * @return string[]
     */
    public static function tableNames(): array
    {
        return [
            PluginAssetwatchAlert::getTable(),
            PluginAssetwatchSnapshot::getTable(),
            PluginAssetwatchDigest::getTable(),
            PluginAssetwatchUplink::getTable(),
            PluginAssetwatchProbe::getTable(),
            self::DISK_HISTORY_TABLE,
        ];
    }

    private static function createTables(Migration $migration): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $charset   = DBConnection::getDefaultCharset();
        $collation = DBConnection::getDefaultCollation();
        $sign      = DBConnection::getDefaultPrimaryKeySignOption();

        $table = PluginAssetwatchAlert::getTable();
        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Creating $table");
            $DB->doQueryOrDie("CREATE TABLE `$table` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$sign} NOT NULL DEFAULT '0',
                `itemtype` varchar(100) NOT NULL DEFAULT '',
                `items_id` int {$sign} NOT NULL DEFAULT '0',
                `item_name` varchar(255) NOT NULL DEFAULT '',
                `groups_id_tech` int {$sign} NOT NULL DEFAULT '0',
                `alert_type` varchar(32) NOT NULL DEFAULT '',
                `alert_key` varchar(255) NOT NULL DEFAULT '',
                `status` tinyint NOT NULL DEFAULT '1',
                `summary` varchar(255) NOT NULL DEFAULT '',
                `content` text,
                `users_id` int {$sign} NOT NULL DEFAULT '0',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                `date_last_notified` timestamp NULL DEFAULT NULL,
                `date_resolved` timestamp NULL DEFAULT NULL,
                `users_id_ack` int {$sign} NOT NULL DEFAULT '0',
                `ack_comment` text,
                `date_ack` timestamp NULL DEFAULT NULL,
                `date_ack_until` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `item` (`itemtype`, `items_id`),
                KEY `groups_id_tech` (`groups_id_tech`),
                KEY `alert_type` (`alert_type`),
                KEY `status` (`status`),
                KEY `users_id` (`users_id`),
                KEY `users_id_ack` (`users_id_ack`),
                KEY `date_creation` (`date_creation`),
                KEY `date_mod` (`date_mod`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC", $DB->error());
        }

        $table = PluginAssetwatchSnapshot::getTable();
        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Creating $table");
            $DB->doQueryOrDie("CREATE TABLE `$table` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `computers_id` int {$sign} NOT NULL DEFAULT '0',
                `inventory_date` timestamp NULL DEFAULT NULL,
                `content` mediumtext,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `computers_id` (`computers_id`),
                KEY `inventory_date` (`inventory_date`),
                KEY `date_creation` (`date_creation`),
                KEY `date_mod` (`date_mod`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC", $DB->error());
        }

        $table = PluginAssetwatchDigest::getTable();
        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Creating $table");
            $DB->doQueryOrDie("CREATE TABLE `$table` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `entities_id` int {$sign} NOT NULL DEFAULT '0',
                `groups_id_tech` int {$sign} NOT NULL DEFAULT '0',
                `nb_new` int NOT NULL DEFAULT '0',
                `nb_reminder` int NOT NULL DEFAULT '0',
                `nb_resolved` int NOT NULL DEFAULT '0',
                `content` mediumtext,
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `entities_id` (`entities_id`),
                KEY `groups_id_tech` (`groups_id_tech`),
                KEY `date_creation` (`date_creation`),
                KEY `date_mod` (`date_mod`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC", $DB->error());
        }

        // 2.3: connections typed by hand (network topology page).
        $table = PluginAssetwatchUplink::getTable();
        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Creating $table");
            $DB->doQueryOrDie("CREATE TABLE `$table` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `itemtype` varchar(100) NOT NULL DEFAULT '',
                `items_id` int {$sign} NOT NULL DEFAULT '0',
                `networkequipments_id` int {$sign} NOT NULL DEFAULT '0',
                `switch_port` varchar(255) NOT NULL DEFAULT '',
                `device_port` varchar(255) NOT NULL DEFAULT '',
                `users_id` int {$sign} NOT NULL DEFAULT '0',
                `date_creation` timestamp NULL DEFAULT NULL,
                `date_mod` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `item` (`itemtype`, `items_id`),
                KEY `networkequipments_id` (`networkequipments_id`),
                KEY `users_id` (`users_id`),
                KEY `date_creation` (`date_creation`),
                KEY `date_mod` (`date_mod`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC", $DB->error());
        }

        // 2.5: liveness probe state, one row per probed asset.
        $table = PluginAssetwatchProbe::getTable();
        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Creating $table");
            $DB->doQueryOrDie("CREATE TABLE `$table` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `itemtype` varchar(100) NOT NULL DEFAULT '',
                `items_id` int {$sign} NOT NULL DEFAULT '0',
                `state` varchar(16) NOT NULL DEFAULT 'ok',
                `fail_count` int NOT NULL DEFAULT '0',
                `checked` varchar(255) NOT NULL DEFAULT '',
                `date_first_fail` timestamp NULL DEFAULT NULL,
                `date_last_ok` timestamp NULL DEFAULT NULL,
                `date_last_probe` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `item` (`itemtype`, `items_id`),
                KEY `state` (`state`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC", $DB->error());
        }

        // 2.5: disk trend samples.
        $table = self::DISK_HISTORY_TABLE;
        if (!$DB->tableExists($table)) {
            $migration->displayMessage("Creating $table");
            $DB->doQueryOrDie("CREATE TABLE `$table` (
                `id` int {$sign} NOT NULL AUTO_INCREMENT,
                `computers_id` int {$sign} NOT NULL DEFAULT '0',
                `mountpoint` varchar(255) NOT NULL DEFAULT '',
                `total_mb` bigint NOT NULL DEFAULT '0',
                `free_mb` bigint NOT NULL DEFAULT '0',
                `date` timestamp NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `computer_mount_date` (`computers_id`, `mountpoint`(100), `date`),
                KEY `date` (`date`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation} ROW_FORMAT=DYNAMIC", $DB->error());
        }
    }

    /**
     * 1.x ran on GLPI 10, which stored text HTML-encoded (`&#38;`, `&#60;`, `&#62;`), and the
     * JSON documents held values that were already encoded when read from GLPI tables.
     * GLPI 11 works with raw strings: decode every stored string once (and every string inside
     * the JSON documents), otherwise the first new inventory of a host whose name/port contains
     * one of these characters would look like an identity change.
     * The decoder only touches strings that look encoded, so running it twice is harmless.
     */
    private static function decodeLegacyData(Migration $migration): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $decoder = new SanitizedStringsDecoder();
        $decode_json = static function (?string $json) use ($decoder): ?string {
            $data = $json === null || $json === '' ? null : json_decode($json, true);
            if (!is_array($data)) {
                return $json;
            }
            array_walk_recursive($data, static function (&$value) use ($decoder): void {
                if (is_string($value)) {
                    // Values read from GLPI 10 tables (encoded) were encoded again on write.
                    $value = $decoder->decodeHtmlSpecialChars($decoder->decodeHtmlSpecialChars($value));
                }
            });
            return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        };

        $plan = [
            PluginAssetwatchAlert::getTable()    => [['item_name', 'alert_key', 'summary', 'ack_comment'], ['content']],
            PluginAssetwatchSnapshot::getTable() => [[], ['content']],
            PluginAssetwatchDigest::getTable()   => [[], ['content']],
        ];

        // A database upgraded from GLPI 10 has "must_unsanitize_db_data" on: GLPI decodes every
        // string on read. Read the stored values as they are, so that they really get rewritten,
        // then restore the flag as GLPI configured it.
        $unsanitize_on_read = (bool) (Config::getConfigurationValue('core', 'must_unsanitize_db_data') ?? true);
        $DB->setMustUnsanitizeData(false);
        try {
            self::rewriteDecoded($plan, $decoder, $decode_json, $migration);
        } finally {
            $DB->setMustUnsanitizeData($unsanitize_on_read);
        }
    }

    /**
     * @param array<string, array{0: string[], 1: string[]}> $plan table => [text fields, JSON fields]
     */
    private static function rewriteDecoded(array $plan, SanitizedStringsDecoder $decoder, callable $decode_json, Migration $migration): void
    {
        /** @var DBmysql $DB */
        global $DB;

        foreach ($plan as $table => [$text_fields, $json_fields]) {
            $changed = 0;
            foreach ($DB->request(['FROM' => $table]) as $row) {
                $update = [];
                foreach ($text_fields as $field) {
                    if (is_string($row[$field] ?? null)) {
                        $decoded = $decoder->decodeHtmlSpecialChars($decoder->decodeHtmlSpecialChars($row[$field]));
                        if ($decoded !== $row[$field]) {
                            $update[$field] = $decoded;
                        }
                    }
                }
                foreach ($json_fields as $field) {
                    $decoded = $decode_json($row[$field] ?? null);
                    if ($decoded !== ($row[$field] ?? null)) {
                        $update[$field] = $decoded;
                    }
                }
                if ($update !== []) {
                    $DB->update($table, $update, ['id' => $row['id']]);
                    $changed++;
                }
            }
            $migration->displayMessage(sprintf('Asset Watch 2.0: %d row(s) of %s decoded from GLPI 10 encoding', $changed, $table));
        }
    }

    /**
     * Add missing config keys only, never overwrite values set by the admin.
     */
    private static function installConfig(): void
    {
        $current = Config::getConfigurationValues(self::CONFIG_CONTEXT);
        $missing = array_diff_key(PluginAssetwatchConfig::defaults(), $current);
        if ($missing !== []) {
            Config::setConfigurationValues(self::CONFIG_CONTEXT, $missing);
        }
    }

    /**
     * Register rights and grant everything to profiles that can update GLPI config
     * (Super-Admin by default). Other profiles get nothing until an admin decides.
     */
    private static function installRights(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $new_rights = [];
        foreach ([self::RIGHT_ALERT, self::RIGHT_CONFIG] as $right) {
            if (countElementsInTable('glpi_profilerights', ['name' => $right]) === 0) {
                $new_rights[] = $right;
            }
        }
        if ($new_rights === []) {
            return;
        }
        ProfileRight::addProfileRights($new_rights);

        $full = [
            self::RIGHT_ALERT  => READ | PluginAssetwatchAlert::ACKNOWLEDGE,
            self::RIGHT_CONFIG => READ | UPDATE,
        ];
        $admins = $DB->request([
            'SELECT' => 'profiles_id',
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => [
                'name'   => 'config',
                'rights' => ['&', UPDATE],
            ],
        ]);
        foreach ($admins as $row) {
            ProfileRight::updateProfileRights((int) $row['profiles_id'], array_intersect_key($full, array_flip($new_rights)));
        }

        // Refresh rights of the user who is installing the plugin.
        if (isset($_SESSION['glpiactiveprofile']['id'])) {
            foreach ($new_rights as $right) {
                $_SESSION['glpiactiveprofile'][$right] = (int) ($DB->request([
                    'SELECT' => 'rights',
                    'FROM'   => 'glpi_profilerights',
                    'WHERE'  => ['profiles_id' => $_SESSION['glpiactiveprofile']['id'], 'name' => $right],
                ])->current()['rights'] ?? 0);
            }
        }
    }

    private static function installCronTasks(): void
    {
        $common = [
            'mode'  => CronTask::MODE_EXTERNAL,
            'state' => CronTask::STATE_WAITING,
        ];
        CronTask::register(
            PluginAssetwatchAlert::class,
            'AssetwatchChanges',
            5 * MINUTE_TIMESTAMP,
            $common + ['comment' => 'Detect hardware / identity changes after new inventories']
        );
        CronTask::register(
            PluginAssetwatchAlert::class,
            'AssetwatchStatus',
            HOUR_TIMESTAMP,
            $common + ['comment' => 'Check missing inventories and low disk space, send digests']
        );
        CronTask::register(
            PluginAssetwatchAlert::class,
            'AssetwatchPurge',
            DAY_TIMESTAMP,
            $common + ['comment' => 'Purge alert and digest history older than the retention period']
        );
        // 2.5: does nothing until the probe is enabled in the plugin configuration.
        CronTask::register(
            PluginAssetwatchAlert::class,
            'AssetwatchProbe',
            5 * MINUTE_TIMESTAMP,
            $common + ['comment' => 'Check that machines and GLPI Agents answer (liveness probe)']
        );
    }

    /**
     * Notification definitions: itemtype, event, template key.
     *
     * @return array<int, array{itemtype: string, event: string, name: string, template: string}>
     */
    public static function notificationDefinitions(): array
    {
        return [
            [
                'itemtype' => PluginAssetwatchDigest::class,
                'event'    => 'digest',
                'name'     => 'Asset Watch - Status digest',
                'template' => 'digest',
            ],
            [
                'itemtype' => PluginAssetwatchAlert::class,
                'event'    => PluginAssetwatchAlert::TYPE_HARDWARE,
                'name'     => 'Asset Watch - Hardware change',
                'template' => 'change',
            ],
            [
                'itemtype' => PluginAssetwatchAlert::class,
                'event'    => PluginAssetwatchAlert::TYPE_IDENTITY,
                'name'     => 'Asset Watch - Identity change',
                'template' => 'change',
            ],
            [
                'itemtype' => PluginAssetwatchAlert::class,
                'event'    => PluginAssetwatchAlert::TYPE_RACK,
                'name'     => 'Asset Watch - Rack placement change',
                'template' => 'change',
            ],
        ];
    }

    /**
     * Create default templates and notifications (only once; admins may edit them afterwards).
     */
    private static function installNotifications(): void
    {
        $templates = [];
        foreach (PluginAssetwatchNotificationTemplates::all() as $key => $definition) {
            $template = new NotificationTemplate();
            if ($template->getFromDBByCrit(['itemtype' => $definition['itemtype'], 'name' => $definition['name']])) {
                $templates[$key] = (int) $template->getID();
                continue;
            }
            $templates_id = (int) $template->add([
                'name'     => $definition['name'],
                'itemtype' => $definition['itemtype'],
                'comment'  => 'Created by the Asset Watch plugin',
            ]);
            foreach ($definition['translations'] as $language => $translation) {
                (new NotificationTemplateTranslation())->add([
                    'notificationtemplates_id' => $templates_id,
                    'language'                 => $language,
                    'subject'                  => $translation['subject'],
                    'content_text'             => $translation['text'],
                    'content_html'             => $translation['html'],
                ]);
            }
            $templates[$key] = $templates_id;
        }

        foreach (self::notificationDefinitions() as $definition) {
            $notification = new Notification();
            if ($notification->getFromDBByCrit(['itemtype' => $definition['itemtype'], 'event' => $definition['event']])) {
                continue;
            }
            $notifications_id = (int) $notification->add([
                'name'         => $definition['name'],
                'entities_id'  => 0,
                'is_recursive' => 1,
                'is_active'    => 1,
                'itemtype'     => $definition['itemtype'],
                'event'        => $definition['event'],
            ]);
            (new Notification_NotificationTemplate())->add([
                'notifications_id'         => $notifications_id,
                'mode'                     => Notification_NotificationTemplate::MODE_MAIL,
                'notificationtemplates_id' => $templates[$definition['template']],
            ]);
            (new NotificationTarget())->add([
                'notifications_id' => $notifications_id,
                'type'             => Notification::USER_TYPE,
                'items_id'         => Notification::ITEM_TECH_GROUP_IN_CHARGE,
            ]);
        }
    }

    /**
     * Default columns of the alert list (only when none were defined yet).
     */
    private static function installDisplayPreferences(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $itemtype = PluginAssetwatchAlert::class;
        $existing = countElementsInTable('glpi_displaypreferences', ['itemtype' => $itemtype, 'users_id' => 0]);
        if ($existing > 0) {
            // GLPI 10 always showed search option 1 (the asset name) as first column; GLPI 11 shows
            // only the configured columns. Add it once to lists configured by 1.x.
            if (countElementsInTable('glpi_displaypreferences', ['itemtype' => $itemtype, 'users_id' => 0, 'num' => 1]) === 0) {
                $DB->insert('glpi_displaypreferences', [
                    'itemtype' => $itemtype,
                    'num'      => 1,
                    'rank'     => 0,
                    'users_id' => 0,
                ]);
            }
            return;
        }
        $rank = 1;
        foreach ([1, 3, 4, 5, 6, 15] as $num) {
            $DB->insert('glpi_displaypreferences', [
                'itemtype' => $itemtype,
                'num'      => $num,
                'rank'     => $rank++,
                'users_id' => 0,
            ]);
        }
    }

    private static function uninstallNotifications(): void
    {
        $itemtypes = [PluginAssetwatchAlert::class, PluginAssetwatchDigest::class];
        // deleteByCriteria() purges related targets, template links and translations too.
        (new Notification())->deleteByCriteria(['itemtype' => $itemtypes], true);
        (new NotificationTemplate())->deleteByCriteria(['itemtype' => $itemtypes], true);
    }
}
