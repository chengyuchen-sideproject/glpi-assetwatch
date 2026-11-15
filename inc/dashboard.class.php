<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Dashboard\Dashboard;
use Glpi\Dashboard\Filter;
use Glpi\Dashboard\Provider;
use GlpiPlugin\Assetwatch\Core\FleetStatus;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Cards for GLPI dashboards (Home > Dashboard, "Edit" > "Add a card" > group "Asset Watch").
 *
 * Every card can be added to, moved in or removed from any dashboard by the users; the plugin
 * also creates an "Asset Watch" dashboard with all of them as a starting point.
 * Numbers follow the entities of the current session and the dashboard filters that apply to
 * computers (location, technician group, state, manufacturer... and the plugin's "Server room"
 * filter); cards stay empty without the "Asset alerts: Read" right.
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

    /** @var array<string, array{total: int, offline: int, disk_low: int, healthy: int, offline_ids: int[], ids: int[]}> per filter set */
    private static array $fleet = [];

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

        $group   = __('Asset Watch', 'assetwatch');
        $charts  = ['summaryNumbers', 'multipleNumber', 'pie', 'donut', 'halfdonut', 'bar', 'hbar'];
        $p       = self::CARD_PREFIX;
        // Filters that change the numbers (GLPI highlights the cards they apply to).
        $filters = Filter::getAppliableFilters('glpi_computers');

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
        $cards[$p . 'auditissues'] = [
            'widgettype' => ['bigNumber'],
            'group'      => $group,
            'label'      => __('Assets to check on site (inventory)', 'assetwatch'),
            'provider'   => self::class . '::auditIssues',
        ];
        foreach ($cards as $id => $card) {
            if (str_starts_with($id, $p)) {
                $cards[$id]['filters'] = $id === $p . 'auditissues' ? [PluginAssetwatchRoomFilter::getId()] : $filters;
            }
        }
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
            'number' => self::fleet($params)['total'],
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
            'number' => self::fleet($params)['offline'],
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
            'number' => self::fleet($params)['disk_low'],
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
            'number' => array_sum(self::openAlertsByType($params)),
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
        $fleet = self::fleet($params);
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
        foreach (self::openAlertsByType($params) as $type => $count) {
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
            'number'     => self::fleet($params)['offline'],
            'url'        => self::alertListUrl(PluginAssetwatchAlert::TYPE_NO_REPORT),
            'label'      => $params['label'] ?? __('Offline computers (list)', 'assetwatch'),
            'icon'       => 'ti ti-plug-connected-x',
            's_criteria' => array_merge(
                self::openCriteria(PluginAssetwatchAlert::TYPE_NO_REPORT),
                self::alertIdCriteria($params, PluginAssetwatchAlert::TYPE_NO_REPORT)
            ),
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
                ] + self::filteredAlertCriteria($params) + getEntitiesRestrictCriteria(PluginAssetwatchAlert::getTable()),
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

    /**
     * Assets with at least one inventory discrepancy, in the room of the "Server room" filter
     * (all rooms without it). Links to the inventory page.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function auditIssues(array $params = []): array
    {
        $room = (int) (($params['apply_filters'] ?? [])[PluginAssetwatchRoomFilter::getId()] ?? 0);
        $count = 0;
        if (self::canView()) {
            $rooms = PluginAssetwatchAudit::rooms();
            $ids = $room > 0 ? (isset($rooms[$room]) ? [$room] : []) : array_keys($rooms);
            $count = $ids !== [] ? PluginAssetwatchAudit::collect($ids)['items_with_issues'] : 0;
        }
        return [
            'number' => $count,
            'url'    => PluginAssetwatchAudit::getSearchURL() . '?scope=' . ($room > 0 ? 'room:' . $room : 'all'),
            'label'  => $params['label'] ?? __('Assets to check on site (inventory)', 'assetwatch'),
            'icon'   => PluginAssetwatchAudit::getIcon(),
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
            ['auditissues', 20, 0, 6, 3, 'bigNumber', '#e8710a'],
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
     * Dashboard filter values that are set (empty ones are ignored).
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function activeFilters(array $params): array
    {
        $filters = $params['apply_filters'] ?? [];
        if (!is_array($filters)) {
            return [];
        }
        return array_filter($filters, static function ($value): bool {
            if (is_array($value)) {
                return array_filter($value, static fn ($v): bool => $v !== '' && $v !== null) !== [];
            }
            return $value !== '' && $value !== null && $value !== '0' && $value !== 0;
        });
    }

    /**
     * Monitored computers of the active entities matching the dashboard filters, classified
     * (computed once per request and filter set).
     *
     * @param array<string, mixed> $params
     *
     * @return array{total: int, offline: int, disk_low: int, healthy: int, offline_ids: int[], ids: int[]}
     */
    private static function fleet(array $params = []): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $filters = self::activeFilters($params);
        $key = md5((string) json_encode($filters));
        if (isset(self::$fleet[$key])) {
            return self::$fleet[$key];
        }
        if (!self::canView()) {
            return self::$fleet[$key] = FleetStatus::summarize([], [], new DateTimeImmutable(), PluginAssetwatchConfig::getSettings()) + ['ids' => []];
        }

        $criteria = [
            'SELECT'   => ['glpi_computers.id', 'glpi_computers.last_inventory_update', 'glpi_computers.computertypes_id'],
            'DISTINCT' => true,
            'FROM'     => 'glpi_computers',
            'WHERE'    => [
                'glpi_computers.is_deleted'  => 0,
                'glpi_computers.is_template' => 0,
                'glpi_computers.is_dynamic'  => 1,
            ] + getEntitiesRestrictCriteria('glpi_computers'),
        ];
        if ($filters !== []) {
            $filter_criteria = Provider::getFiltersCriteria('glpi_computers', $filters);
            if (!empty($filter_criteria['WHERE'])) {
                $criteria['WHERE'][] = $filter_criteria['WHERE'];
            }
            if (!empty($filter_criteria['LEFT JOIN'])) {
                $criteria['LEFT JOIN'] = $filter_criteria['LEFT JOIN'];
            }
        }
        $computers = [];
        foreach ($DB->request($criteria) as $row) {
            $computers[(int) $row['id']] = $row;
        }

        $disk_low = [];
        if ($computers !== []) {
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
        }

        return self::$fleet[$key] = FleetStatus::summarize(
            $computers,
            $disk_low,
            new DateTimeImmutable(self::now()),
            PluginAssetwatchConfig::getSettings()
        ) + ['ids' => array_keys($computers)];
    }

    /**
     * With dashboard filters set: only the alerts of the matching computers.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function filteredAlertCriteria(array $params): array
    {
        if (self::activeFilters($params) === []) {
            return [];
        }
        $ids = self::fleet($params)['ids'];
        return ['itemtype' => 'Computer', 'items_id' => $ids !== [] ? $ids : [0]];
    }

    /**
     * Search criteria restricting a list card to the open alerts of the filtered computers.
     *
     * @param array<string, mixed> $params
     *
     * @return array<int, array<string, mixed>>
     */
    private static function alertIdCriteria(array $params, string $type): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $where = self::filteredAlertCriteria($params);
        if ($where === []) {
            return [];
        }
        $ids = [];
        foreach ($DB->request([
            'SELECT' => 'id',
            'FROM'   => PluginAssetwatchAlert::getTable(),
            'WHERE'  => $where + [
                'alert_type' => $type,
                'status'     => [PluginAssetwatchAlert::STATUS_ACTIVE, PluginAssetwatchAlert::STATUS_ACKNOWLEDGED],
            ],
        ]) as $row) {
            $ids[] = (int) $row['id'];
        }
        $or = [];
        foreach ($ids !== [] ? $ids : [0] as $i => $id) {
            // search option 2 = alert id
            $or[] = ['link' => $i === 0 ? 'AND' : 'OR', 'field' => 2, 'searchtype' => 'equals', 'value' => $id];
        }
        return [['link' => 'AND', 'criteria' => $or]];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, int> alert type => number of open (active or acknowledged) alerts
     */
    private static function openAlertsByType(array $params = []): array
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
            ] + self::filteredAlertCriteria($params) + getEntitiesRestrictCriteria(PluginAssetwatchAlert::getTable()),
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
