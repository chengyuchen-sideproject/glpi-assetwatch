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
 * Notifications for status digests (missing inventory, low disk), one per technical group.
 */
class PluginAssetwatchNotificationTargetDigest extends NotificationTarget
{
    public function getEvents()
    {
        return ['digest' => __('Asset status digest', 'assetwatch')];
    }

    public function addAdditionalTargets($event = '')
    {
        $this->addTarget(
            Notification::ITEM_TECH_GROUP_IN_CHARGE,
            __('Group in charge of the assets', 'assetwatch')
        );
    }

    public function addDataForTemplate($event, $options = [])
    {
        /** @var PluginAssetwatchDigest $digest */
        $digest = $this->obj;
        $fields = $digest->fields;
        $usertype = $options['additionnaloption']['usertype'] ?? self::GLPI_USER;

        $kinds = [
            'new'      => __('New', 'assetwatch'),
            'reminder' => __('Still active', 'assetwatch'),
            'resolved' => __('Resolved', 'assetwatch'),
        ];

        $this->data['##digest.nbnew##']      = (int) $fields['nb_new'];
        $this->data['##digest.nbreminder##'] = (int) $fields['nb_reminder'];
        $this->data['##digest.nbresolved##'] = (int) $fields['nb_resolved'];
        $this->data['##digest.nbtotal##']    = (int) $fields['nb_new'] + (int) $fields['nb_reminder'] + (int) $fields['nb_resolved'];
        $this->data['##digest.date##']       = Html::convDateTime($fields['date_creation']);
        $this->data['##digest.group##']      = $fields['groups_id_tech'] > 0
            ? Dropdown::getDropdownName('glpi_groups', (int) $fields['groups_id_tech'], false, true, false, '')
            : '';
        $this->data['##digest.listurl##']    = $this->formatURL($usertype, PluginAssetwatchAlert::class);

        $this->data['alerts'] = [];
        foreach ($digest->getEntries() as $entry) {
            $lines = PluginAssetwatchAlert::describeLines([
                'alert_type' => $entry['alert_type'],
                'content'    => json_encode($entry['content'] ?? []),
                'summary'    => '',
            ]);
            $this->data['alerts'][] = [
                '##alert.kind##'    => $kinds[$entry['kind']] ?? $entry['kind'],
                '##alert.type##'    => PluginAssetwatchAlert::typeLabel((string) $entry['alert_type']),
                '##alert.item##'    => Sanitizer::decodeHtmlSpecialChars((string) $entry['item_name']),
                '##alert.details##' => implode(' / ', $lines),
                '##alert.since##'   => Html::convDateTime($entry['since'] ?? null),
                '##alert.url##'     => $this->formatURL($usertype, PluginAssetwatchAlert::class . '_' . (int) $entry['alert_id']),
                '##alert.itemurl##' => $this->formatURL($usertype, $entry['itemtype'] . '_' . (int) $entry['items_id']),
            ];
        }

        $this->getTags();
        foreach ($this->tag_descriptions[NotificationTarget::TAG_LANGUAGE] as $tag => $values) {
            if (!isset($this->data[$tag])) {
                $this->data[$tag] = $values['label'];
            }
        }
    }

    public function getTags()
    {
        $tags = [
            'digest.nbnew'      => __('Number of new alerts', 'assetwatch'),
            'digest.nbreminder' => __('Number of reminders', 'assetwatch'),
            'digest.nbresolved' => __('Number of resolved alerts', 'assetwatch'),
            'digest.nbtotal'    => __('Total', 'assetwatch'),
            'digest.date'       => __('Date'),
            'digest.group'      => __('Group in charge'),
            'digest.listurl'    => __('Alert list URL', 'assetwatch'),
            'alert.kind'        => __('Status'),
            'alert.type'        => _n('Type', 'Types', 1),
            'alert.item'        => _n('Item', 'Items', 1),
            'alert.details'     => __('Details', 'assetwatch'),
            'alert.since'       => __('Since', 'assetwatch'),
            'alert.url'         => __('Alert URL', 'assetwatch'),
            'alert.itemurl'     => __('Item URL', 'assetwatch'),
        ];
        foreach ($tags as $tag => $label) {
            $this->addTagToList(['tag' => $tag, 'label' => $label, 'value' => true]);
        }
        $this->addTagToList([
            'tag'     => 'alerts',
            'label'   => __('List of alerts', 'assetwatch'),
            'value'   => false,
            'foreach' => true,
        ]);
        asort($this->tag_descriptions);
    }
}
