<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Application\View\TemplateRenderer;
use Glpi\Toolbox\Sanitizer;
use GlpiPlugin\Assetwatch\Core\AlertText;
use GlpiPlugin\Assetwatch\Core\StatusTracker;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * One alert raised about an asset.
 *
 * Condition alerts (missing inventory, low disk) live through
 * ACTIVE -> (ACKNOWLEDGED) -> RESOLVED; change alerts (hardware, identity,
 * rack) are one-shot EVENT records kept for history.
 */
class PluginAssetwatchAlert extends CommonDBTM
{
    public const TYPE_NO_REPORT = AlertText::TYPE_NO_REPORT;
    public const TYPE_DISK_LOW  = AlertText::TYPE_DISK_LOW;
    public const TYPE_HARDWARE  = AlertText::TYPE_HARDWARE;
    public const TYPE_IDENTITY  = AlertText::TYPE_IDENTITY;
    public const TYPE_RACK      = AlertText::TYPE_RACK;

    public const STATUS_ACTIVE       = StatusTracker::STATUS_ACTIVE;
    public const STATUS_ACKNOWLEDGED = StatusTracker::STATUS_ACKNOWLEDGED;
    public const STATUS_RESOLVED     = StatusTracker::STATUS_RESOLVED;
    public const STATUS_EVENT        = StatusTracker::STATUS_EVENT;

    /** Custom right bit: acknowledge / snooze alerts. */
    public const ACKNOWLEDGE = 256;

    public static $rightname = 'plugin_assetwatch_alert';

    public $dohistory = false;

    public static function getTypeName($nb = 0)
    {
        return _n('Asset alert', 'Asset alerts', $nb, 'assetwatch');
    }

    public static function getMenuName()
    {
        return __('Asset Watch', 'assetwatch');
    }

    public static function getIcon()
    {
        return 'ti ti-heartbeat';
    }

    public static function canCreate()
    {
        return false;
    }

    public static function canUpdate()
    {
        return Session::haveRight(static::$rightname, self::ACKNOWLEDGE);
    }

    public static function canDelete()
    {
        return false;
    }

    public static function canPurge()
    {
        return false;
    }

    public function getRights($interface = 'central')
    {
        return [
            READ              => __('Read'),
            self::ACKNOWLEDGE => __('Acknowledge', 'assetwatch'),
        ];
    }

    public function getForbiddenStandardMassiveAction()
    {
        $forbidden = parent::getForbiddenStandardMassiveAction();
        $forbidden[] = 'update';
        $forbidden[] = 'delete';
        $forbidden[] = 'purge';
        $forbidden[] = 'clone';
        return $forbidden;
    }

    // ---------------------------------------------------------------------
    // Labels
    // ---------------------------------------------------------------------

    /**
     * @return array<string, string>
     */
    public static function getAlertTypes(): array
    {
        return [
            self::TYPE_NO_REPORT => __('No inventory', 'assetwatch'),
            self::TYPE_DISK_LOW  => __('Low disk space', 'assetwatch'),
            self::TYPE_HARDWARE  => __('Hardware change', 'assetwatch'),
            self::TYPE_IDENTITY  => __('Identity change', 'assetwatch'),
            self::TYPE_RACK      => __('Rack placement change', 'assetwatch'),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function getStatuses(): array
    {
        return [
            self::STATUS_ACTIVE       => __('Active', 'assetwatch'),
            self::STATUS_ACKNOWLEDGED => __('Acknowledged', 'assetwatch'),
            self::STATUS_RESOLVED     => __('Resolved', 'assetwatch'),
            self::STATUS_EVENT        => __('Event', 'assetwatch'),
        ];
    }

    public static function typeLabel(string $type): string
    {
        return self::getAlertTypes()[$type] ?? $type;
    }

    public static function statusLabel(int $status): string
    {
        return self::getStatuses()[$status] ?? (string) $status;
    }

    public static function fieldLabel(string $field): string
    {
        $labels = [
            'memory' => _n('Memory', 'Memories', 1),
            'cpu'    => _n('Processor', 'Processors', 1),
            'drive'  => _n('Hard drive', 'Hard drives', 1),
            'serial' => __('Serial number'),
            'name'   => __('Name'),
            'mac'    => __('MAC'),
            'ip'     => __('IP'),
            'rack'   => Rack::getTypeName(1),
        ];
        return $labels[$field] ?? $field;
    }

    public static function directionLabel(string $direction): string
    {
        $labels = [
            'added'     => __('Added', 'assetwatch'),
            'removed'   => __('Removed', 'assetwatch'),
            'increased' => __('Increased', 'assetwatch'),
            'decreased' => __('Decreased', 'assetwatch'),
            'changed'   => __('Changed', 'assetwatch'),
            'moved'     => __('Moved', 'assetwatch'),
        ];
        return $labels[$direction] ?? $direction;
    }

    /**
     * Localized multi-line description of an alert, used in forms and e-mails.
     *
     * @param array<string, mixed> $fields alert row
     *
     * @return string[]
     */
    public static function describeLines(array $fields): array
    {
        $content = self::decodeContent($fields);
        if ($content === []) {
            return [Sanitizer::decodeHtmlSpecialChars((string) ($fields['summary'] ?? ''))];
        }

        switch ($fields['alert_type']) {
            case self::TYPE_NO_REPORT:
                return [
                    sprintf(__('Last inventory: %s', 'assetwatch'), Html::convDateTime($content['last_inventory'] ?? null)),
                    sprintf(__('%1$d hours without inventory (threshold: %2$d hours)', 'assetwatch'), (int) ($content['hours_since'] ?? 0), (int) ($content['threshold_hours'] ?? 0)),
                ];
            case self::TYPE_DISK_LOW:
                return [
                    sprintf(
                        __('%1$s: %2$s GB free of %3$s GB (%4$s%%)', 'assetwatch'),
                        $content['mountpoint'] ?? '?',
                        number_format((float) ($content['free_gb'] ?? 0), 1),
                        number_format((float) ($content['total_gb'] ?? 0), 1),
                        number_format((float) ($content['free_percent'] ?? 0), 1)
                    ),
                ];
            case self::TYPE_HARDWARE:
            case self::TYPE_IDENTITY:
                $lines = [];
                foreach ((array) ($content['changes'] ?? []) as $change) {
                    $lines[] = sprintf(
                        '%s [%s] %s',
                        self::fieldLabel((string) ($change['field'] ?? '')),
                        self::directionLabel((string) ($change['direction'] ?? '')),
                        AlertText::arrow((string) ($change['old'] ?? ''), (string) ($change['new'] ?? ''))
                    );
                }
                return $lines;
            case self::TYPE_RACK:
                return [
                    sprintf('[%s] %s', self::directionLabel((string) ($content['action'] ?? '')), AlertText::arrow((string) ($content['old'] ?? ''), (string) ($content['new'] ?? ''))),
                    sprintf(__('Changed by: %s', 'assetwatch'), (string) ($content['user'] ?? '')),
                ];
        }
        return [Sanitizer::decodeHtmlSpecialChars((string) ($fields['summary'] ?? ''))];
    }

    // ---------------------------------------------------------------------
    // Persistence helpers (raw rows, no GLPI input sanitizing involved)
    // ---------------------------------------------------------------------

    /**
     * Insert an alert row.
     *
     * @param array<string, mixed> $item    fields of the asset (id, name, entities_id, groups_id_tech)
     * @param array<string, mixed> $content structured details
     */
    public static function createAlert(
        string $itemtype,
        array $item,
        string $type,
        string $key,
        int $status,
        array $content,
        string $now
    ): int {
        /** @var DBmysql $DB */
        global $DB;

        $DB->insert(self::getTable(), self::escapeRow([
            'entities_id'        => (int) ($item['entities_id'] ?? 0),
            'itemtype'           => $itemtype,
            'items_id'           => (int) ($item['id'] ?? 0),
            'item_name'          => (string) ($item['name'] ?? ''),
            'groups_id_tech'     => (int) ($item['groups_id_tech'] ?? 0),
            'alert_type'         => $type,
            'alert_key'          => $key,
            'status'             => $status,
            'summary'            => AlertText::summary($type, $content),
            'content'            => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'users_id'           => (int) Session::getLoginUserID(),
            'date_creation'      => $now,
            'date_mod'           => $now,
            'date_last_notified' => $now,
        ]));
        return (int) $DB->insertId();
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function updateAlert(int $id, array $values): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->update(self::getTable(), self::escapeRow($values), ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function escapeRow(array $row): array
    {
        /** @var DBmysql $DB */
        global $DB;

        // Follow GLPI 10 storage convention: HTML special chars encoded, then SQL-escaped.
        foreach ($row as $field => $value) {
            if (is_string($value)) {
                $row[$field] = $DB->escape(Sanitizer::encodeHtmlSpecialChars($value));
            }
        }
        return $row;
    }

    /**
     * Decode a row read from the database for display (Twig / e-mail escape on their own).
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function decodeRow(array $row): array
    {
        foreach ($row as $field => $value) {
            if (is_string($value)) {
                $row[$field] = Sanitizer::decodeHtmlSpecialChars($value);
            }
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $fields alert row as stored
     *
     * @return array<string, mixed>
     */
    public static function decodeContent(array $fields): array
    {
        $content = json_decode(Sanitizer::decodeHtmlSpecialChars((string) ($fields['content'] ?? '')), true);
        return is_array($content) ? $content : [];
    }

    /**
     * Open (active or acknowledged) condition alerts.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getOpenConditionAlerts(): array
    {
        /** @var DBmysql $DB */
        global $DB;

        return iterator_to_array($DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => [
                'status'     => [self::STATUS_ACTIVE, self::STATUS_ACKNOWLEDGED],
                'alert_type' => [self::TYPE_NO_REPORT, self::TYPE_DISK_LOW],
            ],
            'ORDER' => 'id',
        ]), false);
    }

    public static function purgeForComputer(int $computers_id): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $DB->delete(self::getTable(), ['itemtype' => 'Computer', 'items_id' => $computers_id]);
    }

    public function acknowledge(string $comment, ?string $until): bool
    {
        if ((int) $this->fields['status'] !== self::STATUS_ACTIVE && (int) $this->fields['status'] !== self::STATUS_ACKNOWLEDGED) {
            return false;
        }
        self::updateAlert((int) $this->getID(), [
            'status'         => self::STATUS_ACKNOWLEDGED,
            'users_id_ack'   => (int) Session::getLoginUserID(),
            'ack_comment'    => $comment,
            'date_ack'       => $_SESSION['glpi_currenttime'],
            'date_ack_until' => $until,
            'date_mod'       => $_SESSION['glpi_currenttime'],
        ]);
        return true;
    }

    public function unacknowledge(): bool
    {
        if ((int) $this->fields['status'] !== self::STATUS_ACKNOWLEDGED) {
            return false;
        }
        self::updateAlert((int) $this->getID(), [
            'status'         => self::STATUS_ACTIVE,
            'date_ack_until' => null,
            'date_mod'       => $_SESSION['glpi_currenttime'],
        ]);
        return true;
    }

    // ---------------------------------------------------------------------
    // Search
    // ---------------------------------------------------------------------

    public static function getDefaultSearchRequest()
    {
        return [
            'criteria' => [
                ['field' => 4, 'searchtype' => 'equals', 'value' => self::STATUS_ACTIVE],
                ['link' => 'OR', 'field' => 4, 'searchtype' => 'equals', 'value' => self::STATUS_ACKNOWLEDGED],
            ],
            'sort'  => 15,
            'order' => 'DESC',
        ];
    }

    public function rawSearchOptions()
    {
        $tab = [];

        $tab[] = [
            'id'   => 'common',
            'name' => self::getTypeName(Session::getPluralNumber()),
        ];

        $tab[] = [
            'id'            => 1,
            'table'         => self::getTable(),
            'field'         => 'item_name',
            'name'          => _n('Item', 'Items', 1),
            'datatype'      => 'itemlink',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 2,
            'table'         => self::getTable(),
            'field'         => 'id',
            'name'          => __('ID'),
            'datatype'      => 'number',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 3,
            'table'         => self::getTable(),
            'field'         => 'alert_type',
            'name'          => _n('Type', 'Types', 1),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 4,
            'table'         => self::getTable(),
            'field'         => 'status',
            'name'          => __('Status'),
            'datatype'      => 'specific',
            'searchtype'    => ['equals', 'notequals'],
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 5,
            'table'         => self::getTable(),
            'field'         => 'summary',
            'name'          => __('Details', 'assetwatch'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 6,
            'table'         => 'glpi_groups',
            'field'         => 'completename',
            'linkfield'     => 'groups_id_tech',
            'name'          => __('Group in charge'),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 7,
            'table'         => self::getTable(),
            'field'         => 'itemtype',
            'name'          => __('Item type'),
            'datatype'      => 'itemtypename',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 8,
            'table'         => 'glpi_users',
            'field'         => 'name',
            'linkfield'     => 'users_id_ack',
            'name'          => __('Acknowledged by', 'assetwatch'),
            'datatype'      => 'dropdown',
            'right'         => 'all',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 9,
            'table'         => self::getTable(),
            'field'         => 'ack_comment',
            'name'          => __('Acknowledgement comment', 'assetwatch'),
            'datatype'      => 'text',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 10,
            'table'         => self::getTable(),
            'field'         => 'date_last_notified',
            'name'          => __('Last notification', 'assetwatch'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 11,
            'table'         => self::getTable(),
            'field'         => 'date_resolved',
            'name'          => __('Resolution date'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 12,
            'table'         => self::getTable(),
            'field'         => 'date_ack_until',
            'name'          => __('Silenced until', 'assetwatch'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 13,
            'table'         => self::getTable(),
            'field'         => 'alert_key',
            'name'          => __('Key', 'assetwatch'),
            'datatype'      => 'string',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 15,
            'table'         => self::getTable(),
            'field'         => 'date_creation',
            'name'          => __('Creation date'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 19,
            'table'         => self::getTable(),
            'field'         => 'date_mod',
            'name'          => __('Last update'),
            'datatype'      => 'datetime',
            'massiveaction' => false,
        ];

        $tab[] = [
            'id'            => 80,
            'table'         => 'glpi_entities',
            'field'         => 'completename',
            'name'          => Entity::getTypeName(1),
            'datatype'      => 'dropdown',
            'massiveaction' => false,
        ];

        return $tab;
    }

    public static function getSpecificValueToDisplay($field, $values, array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        switch ($field) {
            case 'alert_type':
                return htmlspecialchars(self::typeLabel((string) $values[$field]));
            case 'status':
                return htmlspecialchars(self::statusLabel((int) $values[$field]));
        }
        return parent::getSpecificValueToDisplay($field, $values, $options);
    }

    public static function getSpecificValueToSelect($field, $name = '', $values = '', array $options = [])
    {
        if (!is_array($values)) {
            $values = [$field => $values];
        }
        $options['display'] = false;
        switch ($field) {
            case 'alert_type':
                $options['value'] = $values[$field];
                return Dropdown::showFromArray($name, self::getAlertTypes(), $options);
            case 'status':
                $options['value'] = $values[$field];
                return Dropdown::showFromArray($name, self::getStatuses(), $options);
        }
        return parent::getSpecificValueToSelect($field, $name, $values, $options);
    }

    // ---------------------------------------------------------------------
    // Display
    // ---------------------------------------------------------------------

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if (!($item instanceof CommonDBTM) || $item->isNewItem() || !self::canView()) {
            return '';
        }
        $count = 0;
        if ($_SESSION['glpishow_count_on_tabs']) {
            $count = countElementsInTable(self::getTable(), [
                'itemtype' => $item->getType(),
                'items_id' => $item->getID(),
                'status'   => [self::STATUS_ACTIVE, self::STATUS_ACKNOWLEDGED],
            ]);
        }
        return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), $count);
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof CommonDBTM) {
            self::showForItem($item);
        }
        return true;
    }

    /**
     * Alert history + current baseline of one asset.
     */
    public static function showForItem(CommonDBTM $item): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $alerts = [];
        $iterator = $DB->request([
            'FROM'  => self::getTable(),
            'WHERE' => ['itemtype' => $item->getType(), 'items_id' => $item->getID()],
            'ORDER' => ['date_creation DESC', 'id DESC'],
            'LIMIT' => 200,
        ]);
        foreach ($iterator as $row) {
            $row['lines']        = self::describeLines($row);
            $row                 = self::decodeRow($row);
            $row['type_label']   = self::typeLabel((string) $row['alert_type']);
            $row['status_label'] = self::statusLabel((int) $row['status']);
            $row['url']          = self::getFormURLWithID((int) $row['id']);
            $alerts[] = $row;
        }

        $snapshot = null;
        if ($item instanceof Computer) {
            $snapshot = PluginAssetwatchSnapshot::getForComputer((int) $item->getID());
        }

        TemplateRenderer::getInstance()->display('@assetwatch/item_alerts.html.twig', [
            'alerts'   => $alerts,
            'snapshot'    => $snapshot,
            'is_computer' => $item instanceof Computer,
            'is_open'  => [self::STATUS_ACTIVE, self::STATUS_ACKNOWLEDGED],
        ]);
    }

    public function showForm($ID, array $options = [])
    {
        if (!$this->getFromDB($ID)) {
            return false;
        }
        $this->check($ID, READ);

        $item_url = '';
        $item = getItemForItemtype($this->fields['itemtype']);
        if ($item && $item->getFromDB((int) $this->fields['items_id'])) {
            $item_url = $item->getLinkURL();
        }

        $status = (int) $this->fields['status'];
        TemplateRenderer::getInstance()->display('@assetwatch/alert_form.html.twig', [
            'alert'        => self::decodeRow($this->fields),
            'type_label'   => self::typeLabel((string) $this->fields['alert_type']),
            'status_label' => self::statusLabel($status),
            'item_type'    => $item ? $item::getTypeName(1) : $this->fields['itemtype'],
            'item_url'     => $item_url,
            'group'        => $this->fields['groups_id_tech'] > 0 ? Dropdown::getDropdownName('glpi_groups', (int) $this->fields['groups_id_tech'], false, true, false, '') : '',
            'ack_user'     => $this->fields['users_id_ack'] > 0 ? getUserName((int) $this->fields['users_id_ack']) : '',
            'lines'        => self::describeLines($this->fields),
            'can_ack'      => self::canUpdate() && in_array($status, [self::STATUS_ACTIVE, self::STATUS_ACKNOWLEDGED], true),
            'is_acked'     => $status === self::STATUS_ACKNOWLEDGED,
            'form_url'     => self::getFormURL(),
        ]);
        return true;
    }

    // ---------------------------------------------------------------------
    // Automatic actions
    // ---------------------------------------------------------------------

    public static function cronInfo($name)
    {
        switch ($name) {
            case 'AssetwatchChanges':
                return ['description' => __('Asset Watch: detect hardware and identity changes', 'assetwatch')];
            case 'AssetwatchStatus':
                return ['description' => __('Asset Watch: check missing inventories and disk space', 'assetwatch')];
            case 'AssetwatchPurge':
                return ['description' => __('Asset Watch: purge old history', 'assetwatch')];
        }
        return [];
    }

    /**
     * @param CronTask $task
     */
    public static function cronAssetwatchChanges($task)
    {
        $count = PluginAssetwatchMonitor::detectChanges();
        $task->addVolume($count);
        return $count > 0 ? 1 : 0;
    }

    /**
     * @param CronTask $task
     */
    public static function cronAssetwatchStatus($task)
    {
        $count = PluginAssetwatchMonitor::checkStatus();
        $task->addVolume($count);
        return $count > 0 ? 1 : 0;
    }

    /**
     * @param CronTask $task
     */
    public static function cronAssetwatchPurge($task)
    {
        $count = PluginAssetwatchMonitor::purgeHistory();
        $task->addVolume($count);
        return $count > 0 ? 1 : 0;
    }
}
