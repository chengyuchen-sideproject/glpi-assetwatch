<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Dashboard\Dashboard;
use GlpiPlugin\Assetwatch\Core\FleetStatus;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Cards for GLPI dashboards (Home > Dashboard, "Edit" > "Add a card" > group "Asset Watch").
 *
 * Every card can be added to, moved in or removed from any dashboard by the users; the plugin
 * also creates an "Asset Watch" dashboard with all of them as a starting point.
 * Numbers follow the entities of the current session; cards stay empty without the
 * "Asset alerts: Read" right.
 */
class PluginAssetwatchDashboard
{
    /** Prefix of every card id (used to clean dashboards on uninstall). */
    public const CARD_PREFIX = 'plugin_assetwatch_';

    /** Key of the dashboard created at install. */
    public const DASHBOARD_KEY = 'asset-watch';

    /** Days shown by the "change events" chart. */
    public const TREND_DAYS = 30;

    private const COLOR_OK      = '#3bb06f';
    private const COLOR_OFFLINE = '#d6383d';
    private const COLOR_DISK    = '#f0a028';
    private const COLOR_NEUTRAL = '#5c7aa8';

    /** @var array{total: int, offline: int, disk_low: int, healthy: int, offline_ids: int[]}|null */
    private static ?array $fleet = null;

    /**
     * Hook "dashboard_cards". GLPI chains the plugins: $cards holds the cards of the plugins called
     * before this one and must be returned with ours added.
     *
     * @param mixed $cards
     *
     * @return array<string, array<string, mixed>>
     */
    public static function cards($cards = null): array
    {
        $cards = is_array($cards) ? $cards : [];
        if (!self::canView()) {
            return $cards;
        }

        $group  = __('Asset Watch', 'assetwatch');
        $charts = ['summaryNumbers', 'multipleNumber', 'pie', 'donut', 'halfdonut', 'bar', 'hbar'];
        $p      = self::CARD_PREFIX;

        $cards[$p . 'monitored'] = [
            'widgettype' => ['bigNumber'],
            'group'      => $group,
            'label'      => __('Monitored computers', 'assetwatch'),
            'provider'   => self::class . '::monitored',
        ];
        $cards[$p . 'offline'] = [
            'widgettype' => ['bigNumber'],
            'group'      => $group,
            'label'      => __('Offline computers (no inventory)', 'assetwatch'),
            'provider'   => self::class . '::offline',
        ];
        $cards[$p . 'disklow'] = [
            'widgettype' => ['bigNumber'],
            'group'      => $group,
            'label'      => __('Computers with low disk space', 'assetwatch'),
            'provider'   => self::class . '::diskLow',
        ];
        $cards[$p . 'openalerts'] = [
            'widgettype' => ['bigNumber'],
            'group'      => $group,
            'label'      => __('Open alerts', 'assetwatch'),
            'provider'   => self::class . '::openAlerts',
        ];
        $cards[$p . 'health'] = [
            'widgettype' => $charts,
            'group'      => $group,
            'label'      => __('Computer health', 'assetwatch'),
            'provider'   => self::class . '::health',
        ];
        $cards[$p . 'bytype'] = [
            'widgettype' => $charts,
            'group'      => $group,
            'label'      => __('Open alerts by type', 'assetwatch'),
            'provider'   => self::class . '::byType',
        ];
        $cards[$p . 'offlinelist'] = [
            'widgettype' => ['searchShowList'],
            'itemtype'   => '\\' . PluginAssetwatchAlert::class,
            'group'      => $group,
            'label'      => __('Offline computers (list)', 'assetwatch'),
            'provider'   => self::class . '::offlineList',
        ];
        $cards[$p . 'changes'] = [
            'widgettype' => ['bar', 'line'],
            'group'      => $group,
            'label'      => sprintf(__('Change events (last %d days)', 'assetwatch'), self::TREND_DAYS),
            'provider'   => self::class . '::changes',
        ];
        return $cards;
    }

    // ------------------------------------------------------------------
    // Providers (called by GLPI with the card "params")
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function monitored(array $params = []): array
    {
        return [
            'number' => self::fleet()['total'],
            'url'    => Computer::getSearchURL(),
            'label'  => $params['label'] ?? __('Monitored computers', 'assetwatch'),
            'icon'   => Computer::getIcon(),
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function offline(array $params = []): array
    {
        return [
            'number' => self::fleet()['offline'],
            'url'    => self::alertListUrl(PluginAssetwatchAlert::TYPE_NO_REPORT),
            'label'  => $params['label'] ?? __('Offline computers (no inventory)', 'assetwatch'),
            'icon'   => 'ti ti-plug-connected-x',
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function diskLow(array $params = []): array
    {
        return [
            'number' => self::fleet()['disk_low'],
            'url'    => self::alertListUrl(PluginAssetwatchAlert::TYPE_DISK_LOW),
            'label'  => $params['label'] ?? __('Computers with low disk space', 'assetwatch'),
            'icon'   => 'ti ti-database-exclamation',
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function openAlerts(array $params = []): array
    {
        return [
            'number' => array_sum(self::openAlertsByType()),
            'url'    => self::alertListUrl(null),
            'label'  => $params['label'] ?? __('Open alerts', 'assetwatch'),
            'icon'   => PluginAssetwatchAlert::getIcon(),
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function health(array $params = []): array
    {
        $fleet = self::fleet();
        $data = [];
        if ($fleet['total'] > 0) {
            $data = [
                ['label' => __('Healthy', 'assetwatch'), 'number' => $fleet['healthy'], 'url' => Computer::getSearchURL(), 'color' => self::COLOR_OK],
                ['label' => __('Offline', 'assetwatch'), 'number' => $fleet['offline'], 'url' => self::alertListUrl(PluginAssetwatchAlert::TYPE_NO_REPORT), 'color' => self::COLOR_OFFLINE],
                ['label' => __('Low disk space', 'assetwatch'), 'number' => $fleet['disk_low'], 'url' => self::alertListUrl(PluginAssetwatchAlert::TYPE_DISK_LOW), 'color' => self::COLOR_DISK],
            ];
        }
        return [
            'data'  => $data !== [] ? $data : ['nodata' => true],
            'label' => $params['label'] ?? __('Computer health', 'assetwatch'),
            'icon'  => Computer::getIcon(),
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function byType(array $params = []): array
    {
        $data = [];
        foreach (self::openAlertsByType() as $type => $count) {
            if ($count > 0) {
                $data[] = [
                    'label'  => PluginAssetwatchAlert::typeLabel($type),
                    'number' => $count,
                    'url'    => self::alertListUrl($type),
                ];
            }
        }
        return [
            'data'  => $data !== [] ? $data : ['nodata' => true],
            'label' => $params['label'] ?? __('Open alerts by type', 'assetwatch'),
            'icon'  => PluginAssetwatchAlert::getIcon(),
        ];
    }

    /**
     * Rows: open "no inventory" alerts (the search engine applies entities and rights itself).
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function offlineList(array $params = []): array
    {
        return [
            'number'     => self::fleet()['offline'],
            'url'        => self::alertListUrl(PluginAssetwatchAlert::TYPE_NO_REPORT),
            'label'      => $params['label'] ?? __('Offline computers (list)', 'assetwatch'),
            'icon'       => 'ti ti-plug-connected-x',
            's_criteria' => self::openCriteria(PluginAssetwatchAlert::TYPE_NO_REPORT),
            'itemtype'   => PluginAssetwatchAlert::class,
        ];
    }

    /**
     * Hardware, identity and rack change events per day.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function changes(array $params = []): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $now = new DateTimeImmutable(self::now());
        $from = $now->setTime(0, 0)->modify('-' . (self::TREND_DAYS - 1) . ' days')->format('Y-m-d H:i:s');
        $dates = [];
        if (self::canView()) {
            $iterator = $DB->request([
                'SELECT' => ['date_creation'],
                'FROM'   => PluginAssetwatchAlert::getTable(),
                'WHERE'  => [
                    'status'        => PluginAssetwatchAlert::STATUS_EVENT,
                    'date_creation' => ['>=', $from],
                ] + getEntitiesRestrictCriteria(PluginAssetwatchAlert::getTable()),
            ]);
            foreach ($iterator as $row) {
                $dates[] = (string) $row['date_creation'];
            }
        }
        $data = [];
        foreach (FleetStatus::perDay($dates, $now, self::TREND_DAYS) as $day => $count) {
            $data[] = ['label' => substr($day, 5), 'number' => $count, 'url' => ''];
        }
        return [
            'data'        => $data,
            'label'       => $params['label'] ?? sprintf(__('Change events (last %d days)', 'assetwatch'), self::TREND_DAYS),
            'icon'        => 'ti ti-arrows-exchange',
            // Time series: GLPI then keeps the LAST "limit" points (most recent days) instead of the first ones.
            'distributed' => false,
        ];
    }

    // ------------------------------------------------------------------
    // Default dashboard (created at install, removed at uninstall)
    // ------------------------------------------------------------------

    /**
     * Layout of the "Asset Watch" dashboard (GLPI grid: 26 columns).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function defaultLayout(): array
    {
        $p = self::CARD_PREFIX;
        $cards = [
            // card, x, y, width, height, widget, color
            ['monitored', 0, 0, 5, 3, 'bigNumber', self::COLOR_NEUTRAL],
            ['offline', 5, 0, 5, 3, 'bigNumber', self::COLOR_OFFLINE],
            ['disklow', 10, 0, 5, 3, 'bigNumber', self::COLOR_DISK],
            ['openalerts', 15, 0, 5, 3, 'bigNumber', '#7f7f7f'],
            ['health', 0, 3, 8, 6, 'donut', '#ffffff'],
            ['bytype', 8, 3, 8, 6, 'bar', '#ffffff'],
            ['changes', 16, 3, 10, 6, 'bar', '#ffffff'],
            ['offlinelist', 0, 9, 26, 7, 'searchShowList', '#ffffff'],
        ];
        $limits = ['changes' => (string) self::TREND_DAYS];
        $items = [];
        foreach ($cards as [$card, $x, $y, $width, $height, $widget, $color]) {
            $items[] = [
                'gridstack_id' => $p . $card . '_default',
                'card_id'      => $p . $card,
                'x'            => $x,
                'y'            => $y,
                'width'        => $width,
                'height'       => $height,
                'card_options' => [
                    'color'        => $color,
                    'widgettype'   => $widget,
                    'use_gradient' => '0',
                    'limit'        => $limits[$card] ?? '20',
                ],
            ];
        }
        return $items;
    }

    /**
     * Create the "Asset Watch" dashboard once, visible to the profiles that can read alerts.
     * An existing one is left as is (users may have customized it).
     */
    public static function installDefaultDashboard(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        if (countElementsInTable(Dashboard::getTable(), ['key' => self::DASHBOARD_KEY]) > 0) {
            return;
        }
        $profiles = [];
        foreach ($DB->request([
            'SELECT' => 'profiles_id',
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => ['name' => PluginAssetwatchInstall::RIGHT_ALERT, 'rights' => ['&', READ]],
        ]) as $row) {
            $profiles[] = (int) $row['profiles_id'];
        }
        $dashboard = new Dashboard(self::DASHBOARD_KEY);
        $dashboard->saveNew('Asset Watch', 'core', self::defaultLayout(), $profiles !== [] ? ['profiles_id' => $profiles] : []);
    }

    /**
     * Remove the plugin dashboard and the plugin cards placed on other dashboards.
     */
    public static function uninstallDashboards(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $dashboard = new Dashboard(self::DASHBOARD_KEY);
        if ($dashboard->getFromDB(self::DASHBOARD_KEY)) {
            // force = purge: Dashboard::cleanDBonPurge() also removes its items, rights and filters
            $dashboard->delete(['key' => self::DASHBOARD_KEY], true);
        }
        if ($DB->tableExists('glpi_dashboards_items')) {
            $DB->delete('glpi_dashboards_items', ['card_id' => ['LIKE', self::CARD_PREFIX . '%']]);
        }
    }

    // ------------------------------------------------------------------
    // Data
    // ------------------------------------------------------------------

    private static function canView(): bool
    {
        return Session::haveRight(PluginAssetwatchAlert::$rightname, READ);
    }

    /**
     * Monitored computers of the active entities, classified (computed once per request).
     *
     * @return array{total: int, offline: int, disk_low: int, healthy: int, offline_ids: int[]}
     */
    private static function fleet(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        if (self::$fleet !== null) {
            return self::$fleet;
        }
        if (!self::canView()) {
            return self::$fleet = FleetStatus::summarize([], [], new DateTimeImmutable(), PluginAssetwatchConfig::getSettings());
        }

        $computers = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'last_inventory_update', 'computertypes_id'],
            'FROM'   => 'glpi_computers',
            'WHERE'  => [
                'is_deleted'  => 0,
                'is_template' => 0,
                'is_dynamic'  => 1,
            ] + getEntitiesRestrictCriteria('glpi_computers'),
        ]) as $row) {
            $computers[(int) $row['id']] = $row;
        }

        $disk_low = [];
        foreach ($DB->request([
            'SELECT'   => ['items_id'],
            'DISTINCT' => true,
            'FROM'     => PluginAssetwatchAlert::getTable(),
            'WHERE'    => [
                'itemtype'   => 'Computer',
                'alert_type' => PluginAssetwatchAlert::TYPE_DISK_LOW,
                'status'     => [PluginAssetwatchAlert::STATUS_ACTIVE, PluginAssetwatchAlert::STATUS_ACKNOWLEDGED],
            ],
        ]) as $row) {
            $disk_low[] = (int) $row['items_id'];
        }

        return self::$fleet = FleetStatus::summarize(
            $computers,
            $disk_low,
            new DateTimeImmutable(self::now()),
            PluginAssetwatchConfig::getSettings()
        );
    }

    /**
     * @return array<string, int> alert type => number of open (active or acknowledged) alerts
     */
    private static function openAlertsByType(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $counts = array_fill_keys([
            PluginAssetwatchAlert::TYPE_NO_REPORT,
            PluginAssetwatchAlert::TYPE_DISK_LOW,
        ], 0);
        if (!self::canView()) {
            return $counts;
        }
        foreach ($DB->request([
            'SELECT' => ['alert_type', 'COUNT' => 'id AS cpt'],
            'FROM'   => PluginAssetwatchAlert::getTable(),
            'WHERE'  => [
                'status' => [PluginAssetwatchAlert::STATUS_ACTIVE, PluginAssetwatchAlert::STATUS_ACKNOWLEDGED],
            ] + getEntitiesRestrictCriteria(PluginAssetwatchAlert::getTable()),
            'GROUPBY' => 'alert_type',
        ]) as $row) {
            $counts[(string) $row['alert_type']] = (int) $row['cpt'];
        }
        return $counts;
    }

    /**
     * Search criteria: open alerts, optionally of one type (search options 3 = type, 4 = status).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function openCriteria(?string $type): array
    {
        $criteria = [];
        if ($type !== null) {
            $criteria[] = ['link' => 'AND', 'field' => 3, 'searchtype' => 'equals', 'value' => $type];
        }
        $criteria[] = ['link' => 'AND', 'criteria' => [
            ['field' => 4, 'searchtype' => 'equals', 'value' => PluginAssetwatchAlert::STATUS_ACTIVE],
            ['link' => 'OR', 'field' => 4, 'searchtype' => 'equals', 'value' => PluginAssetwatchAlert::STATUS_ACKNOWLEDGED],
        ]];
        return $criteria;
    }

    private static function alertListUrl(?string $type): string
    {
        $url = PluginAssetwatchAlert::getSearchURL();
        return $url . (str_contains($url, '?') ? '&' : '?') . Toolbox::append_params([
            'criteria' => self::openCriteria($type),
            'reset'    => 'reset',
        ]);
    }

    private static function now(): string
    {
        return $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
    }
}
