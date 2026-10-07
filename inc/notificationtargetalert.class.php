<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

use Glpi\Toolbox\Sanitizer;
use GlpiPlugin\Assetwatch\Core\AlertText;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Notifications for one-shot change alerts (hardware, identity, rack).
 */
class PluginAssetwatchNotificationTargetAlert extends NotificationTarget
{
    public function getEvents()
    {
        return [
            PluginAssetwatchAlert::TYPE_HARDWARE => __('Hardware change', 'assetwatch'),
            PluginAssetwatchAlert::TYPE_IDENTITY => __('Identity change', 'assetwatch'),
            PluginAssetwatchAlert::TYPE_RACK     => __('Rack placement change', 'assetwatch'),
        ];
    }

    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(
            Notification::ITEM_TECH_GROUP_IN_CHARGE,
            __('Group in charge of the asset', 'assetwatch')
        );
    }

    public function addDataForTemplate($event, $options = [])
    {
        $events = $this->getAllEvents();
        $fields = $this->obj->fields;
        $content = PluginAssetwatchAlert::decodeContent($fields);
        $usertype = $options['additionnaloption']['usertype'] ?? self::GLPI_USER;

        $item_type = $fields['itemtype'];
        $item_class = getItemForItemtype($item_type);

        $this->data['##assetwatch.action##']   = $events[$event] ?? $event;
        $this->data['##assetwatch.itemtype##'] = $item_class ? $item_class::getTypeName(1) : $item_type;
        $this->data['##assetwatch.item##']     = Sanitizer::decodeHtmlSpecialChars((string) $fields['item_name']);
        $this->data['##assetwatch.itemurl##']  = $this->formatURL($usertype, $item_type . '_' . $fields['items_id']);
        $this->data['##assetwatch.url##']      = $this->formatURL($usertype, PluginAssetwatchAlert::class . '_' . $fields['id']);
        $this->data['##assetwatch.date##']     = Html::convDateTime($fields['date_creation']);
        $this->data['##assetwatch.group##']    = $fields['groups_id_tech'] > 0
            ? Dropdown::getDropdownName('glpi_groups', (int) $fields['groups_id_tech'], false, true, false, '')
            : '';
        $this->data['##assetwatch.entity##']   = Dropdown::getDropdownName('glpi_entities', (int) $fields['entities_id'], false, true, false, '');
        $this->data['##assetwatch.summary##']  = Sanitizer::decodeHtmlSpecialChars((string) $fields['summary']);
        $this->data['##assetwatch.user##']     = (string) ($content['user'] ?? '');
        $this->data['##assetwatch.inventorydate##'] = !empty($content['inventory_date']) ? Html::convDateTime($content['inventory_date']) : '';

        $this->data['changes'] = [];
        if ($fields['alert_type'] === PluginAssetwatchAlert::TYPE_RACK) {
            $this->data['changes'][] = self::changeRow('rack', (string) ($content['action'] ?? ''), (string) ($content['old'] ?? ''), (string) ($content['new'] ?? ''));
        } else {
            foreach ((array) ($content['changes'] ?? []) as $change) {
                $this->data['changes'][] = self::changeRow(
                    (string) ($change['field'] ?? ''),
                    (string) ($change['direction'] ?? ''),
                    (string) ($change['old'] ?? ''),
                    (string) ($change['new'] ?? '')
                );
            }
        }

        $this->getTags();
        foreach ($this->tag_descriptions[NotificationTarget::TAG_LANGUAGE] as $tag => $values) {
            if (!isset($this->data[$tag])) {
                $this->data[$tag] = $values['label'];
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private static function changeRow(string $field, string $direction, string $old, string $new): array
    {
        return [
            '##change.field##'     => PluginAssetwatchAlert::fieldLabel($field),
            '##change.direction##' => PluginAssetwatchAlert::directionLabel($direction),
            '##change.old##'       => $old,
            '##change.new##'       => $new,
            '##change.value##'     => AlertText::arrow($old, $new),
        ];
    }

    public function getTags()
    {
        $tags = [
            'assetwatch.action'        => __('Event', 'assetwatch'),
            'assetwatch.itemtype'      => __('Item type'),
            'assetwatch.item'          => _n('Item', 'Items', 1),
            'assetwatch.itemurl'       => __('Item URL', 'assetwatch'),
            'assetwatch.url'           => __('Alert URL', 'assetwatch'),
            'assetwatch.date'          => __('Date'),
            'assetwatch.group'         => __('Group in charge'),
            'assetwatch.entity'        => Entity::getTypeName(1),
            'assetwatch.summary'       => __('Details', 'assetwatch'),
            'assetwatch.user'          => __('Changed by', 'assetwatch'),
            'assetwatch.inventorydate' => __('Inventory date', 'assetwatch'),
            'change.field'             => __('Field'),
            'change.direction'         => __('Change', 'assetwatch'),
            'change.old'               => __('Before', 'assetwatch'),
            'change.new'               => __('After', 'assetwatch'),
            'change.value'             => __('Before -> after', 'assetwatch'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }
        $this->addTagToList([
            'tag'     => 'changes',
            'label'   => __('List of changes', 'assetwatch'),
            'value'   => false,
            'foreach' => true,
        ]);
        asort($this->tag_descriptions);
    }
}
