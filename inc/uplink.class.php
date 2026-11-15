<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Connection typed by hand: "this device is plugged into that network device" (ports optional).
 * Used by the network topology page next to the port connections recorded by GLPI.
 * Every addition / removal is written to the history of the device.
 */
class PluginAssetwatchUplink extends CommonDBTM
{
    /** Same right as editing network devices in GLPI. */
    public static $rightname = 'networking';

    public $dohistory = false;

    /** Types that can be plugged into a network device. */
    public const LINKABLE_TYPES = ['Computer', 'NetworkEquipment', 'Peripheral', 'PDU', 'Enclosure'];

    public static function getTypeName($nb = 0)
    {
        return _n('Network connection', 'Network connections', $nb, 'assetwatch');
    }

    public static function canEditLinks(): bool
    {
        return Session::haveRight(self::$rightname, UPDATE);
    }

    public function prepareInputForAdd($input)
    {
        $itemtype = (string) ($input['itemtype'] ?? '');
        $items_id = (int) ($input['items_id'] ?? 0);
        $switch = (int) ($input['networkequipments_id'] ?? 0);
        if (!in_array($itemtype, self::LINKABLE_TYPES, true) || $items_id <= 0 || $switch <= 0) {
            return false;
        }
        if ($itemtype === 'NetworkEquipment' && $items_id === $switch) {
            return false;
        }
        $device = getItemForItemtype($itemtype);
        $equipment = new NetworkEquipment();
        if (!$device || !$device->getFromDB($items_id) || !$device->can($items_id, READ) || !$equipment->getFromDB($switch) || !$equipment->can($switch, READ)) {
            return false;
        }
        $input['switch_port'] = mb_substr(trim((string) ($input['switch_port'] ?? '')), 0, 255);
        $input['device_port'] = mb_substr(trim((string) ($input['device_port'] ?? '')), 0, 255);
        $input['users_id'] = Session::getLoginUserID() ?: 0;
        $input['date_creation'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        return $input;
    }

    public function post_addItem()
    {
        $this->logOnDevice(__('Network connection added: %s', 'assetwatch'));
        parent::post_addItem();
    }

    public function post_purgeItem()
    {
        $this->logOnDevice(__('Network connection removed: %s', 'assetwatch'));
        parent::post_purgeItem();
    }

    public function describe(): string
    {
        $switch = new NetworkEquipment();
        $name = $switch->getFromDB((int) $this->fields['networkequipments_id']) ? $switch->getName() : '#' . $this->fields['networkequipments_id'];
        $text = $name . ($this->fields['switch_port'] !== '' ? ' ' . $this->fields['switch_port'] : '');
        if ($this->fields['device_port'] !== '') {
            $text = $this->fields['device_port'] . ' → ' . $text;
        }
        return $text;
    }

    private function logOnDevice(string $format): void
    {
        Log::history(
            (int) $this->fields['items_id'],
            (string) $this->fields['itemtype'],
            [0, '', sprintf($format, $this->describe())],
            0,
            Log::HISTORY_LOG_SIMPLE_MESSAGE
        );
    }

    /**
     * Forget the connections of an asset that is purged (as device or as network device).
     */
    public static function purgeForItem(CommonDBTM $item): void
    {
        /** @var DBmysql $DB */
        global $DB;

        $where = ['itemtype' => $item::class, 'items_id' => $item->getID()];
        if ($item instanceof NetworkEquipment) {
            $where = ['OR' => [$where, ['networkequipments_id' => $item->getID()]]];
        }
        $DB->delete(self::getTable(), $where);
    }
}
