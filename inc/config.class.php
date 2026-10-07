<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Application\View\TemplateRenderer;
use Glpi\Toolbox\Sanitizer;
use GlpiPlugin\Assetwatch\Core\Settings;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Plugin configuration page and access to the settings.
 */
class PluginAssetwatchConfig extends CommonGLPI
{
    public static $rightname = 'plugin_assetwatch_config';

    private static ?Settings $cache = null;

    public static function getTypeName($nb = 0)
    {
        return __('Asset Watch configuration', 'assetwatch');
    }

    /**
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        return Settings::DEFAULTS;
    }

    public static function getSettings(): Settings
    {
        if (self::$cache === null) {
            $values = Config::getConfigurationValues(PluginAssetwatchInstall::CONFIG_CONTEXT);
            // Stored values follow GLPI's HTML-encoded convention.
            self::$cache = Settings::fromArray(Sanitizer::decodeHtmlSpecialCharsRecursive($values));
        }
        return self::$cache;
    }

    public static function resetCache(): void
    {
        self::$cache = null;
    }

    /**
     * Save the form. $input comes from $_POST, already sanitized by GLPI.
     *
     * @param array<string, mixed> $input
     */
    public static function saveFromForm(array $input): void
    {
        $values = [];
        foreach (['check_noreport', 'check_disk', 'check_hardware', 'check_identity', 'check_rack'] as $flag) {
            $values[$flag] = !empty($input[$flag]) ? '1' : '0';
        }
        foreach (['noreport_default_hours', 'reminder_days', 'retention_days'] as $int) {
            if (isset($input[$int]) && is_numeric($input[$int])) {
                $values[$int] = (string) (int) $input[$int];
            }
        }
        foreach (['disk_min_free_percent', 'disk_min_free_gb'] as $float) {
            if (isset($input[$float]) && is_numeric($input[$float])) {
                $values[$float] = (string) (float) $input[$float];
            }
        }
        foreach (['disk_excluded_fs', 'disk_excluded_mounts', 'port_excluded_patterns'] as $list) {
            if (isset($input[$list])) {
                // Already sanitized by GLPI: only normalize separators.
                $values[$list] = implode(',', Settings::splitList(str_replace(['\\r', '\\n'], "\n", (string) $input[$list])));
            }
        }

        $type_hours = [];
        foreach ((array) ($input['type_hours'] ?? []) as $type_id => $hours) {
            if (is_numeric($type_id) && is_numeric($hours) && (int) $hours > 0) {
                $type_hours[(int) $type_id] = (int) $hours;
            }
        }
        $values['noreport_type_hours'] = Sanitizer::sanitize(json_encode((object) $type_hours));

        // Re-validate through Settings so that the stored values are always usable.
        $settings = Settings::fromArray(array_merge(Settings::DEFAULTS, $values));
        $values['noreport_default_hours'] = (string) $settings->noReportDefaultHours;
        $values['reminder_days']          = (string) $settings->reminderDays;
        $values['retention_days']         = (string) $settings->retentionDays;
        $values['disk_min_free_percent']  = (string) $settings->diskMinFreePercent;
        $values['disk_min_free_gb']       = (string) $settings->diskMinFreeGb;

        Config::setConfigurationValues(PluginAssetwatchInstall::CONFIG_CONTEXT, $values);
        self::resetCache();
    }

    /**
     * Problems that would prevent alerts from reaching anyone.
     *
     * @return array<int, array{level: string, message: string}>
     */
    public static function healthChecks(): array
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $checks = [];

        if (!$CFG_GLPI['use_notifications'] || !$CFG_GLPI['notifications_mailing']) {
            $checks[] = ['level' => 'danger', 'message' => __('E-mail notifications are disabled in GLPI (Setup > Notifications): no alert will be sent.', 'assetwatch')];
        }

        $cron = new CronTask();
        foreach (['AssetwatchChanges', 'AssetwatchStatus', 'AssetwatchPurge'] as $name) {
            if (!$cron->getFromDBbyName(PluginAssetwatchAlert::class, $name)) {
                $checks[] = ['level' => 'danger', 'message' => sprintf(__('Automatic action %s is missing: reinstall the plugin.', 'assetwatch'), $name)];
                continue;
            }
            if ((int) $cron->fields['state'] === CronTask::STATE_DISABLE) {
                $checks[] = ['level' => 'warning', 'message' => sprintf(__('Automatic action %s is disabled.', 'assetwatch'), $name)];
            } elseif ((int) $cron->fields['mode'] !== CronTask::MODE_EXTERNAL) {
                $checks[] = ['level' => 'warning', 'message' => sprintf(__('Automatic action %s runs in GLPI mode: it only runs when someone browses GLPI. Switch it to CLI.', 'assetwatch'), $name)];
            }
        }

        $without_group = countElementsInTable('glpi_computers', [
            'is_deleted'     => 0,
            'is_template'    => 0,
            'is_dynamic'     => 1,
            'groups_id_tech' => 0,
        ]);
        if ($without_group > 0) {
            $checks[] = ['level' => 'warning', 'message' => sprintf(
                _n(
                    '%d monitored computer has no technical group: its alerts are recorded but nobody is notified.',
                    '%d monitored computers have no technical group: their alerts are recorded but nobody is notified.',
                    $without_group,
                    'assetwatch'
                ),
                $without_group
            )];
        }

        return $checks;
    }

    public static function showConfigForm(): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $settings = self::getSettings();
        $types = [];
        foreach ($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_computertypes', 'ORDER' => 'name']) as $row) {
            $types[] = [
                'id'    => (int) $row['id'],
                'name'  => Sanitizer::decodeHtmlSpecialChars((string) $row['name']),
                'hours' => $settings->noReportTypeHours[(int) $row['id']] ?? '',
            ];
        }

        TemplateRenderer::getInstance()->display('@assetwatch/config.html.twig', [
            'settings'    => $settings,
            'types'       => $types,
            'checks'      => self::healthChecks(),
            'monitored'   => countElementsInTable('glpi_computers', ['is_deleted' => 0, 'is_template' => 0, 'is_dynamic' => 1]),
            'form_url'    => Plugin::getWebDir('assetwatch') . '/front/config.form.php',
            'version'     => PLUGIN_ASSETWATCH_VERSION,
        ]);
    }
}
