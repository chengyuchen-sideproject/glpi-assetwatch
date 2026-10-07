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
 * "Asset Watch" tab on profiles: read / acknowledge / configure rights.
 */
class PluginAssetwatchProfile extends CommonGLPI
{
    public static $rightname = 'profile';

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('interface') === 'central') {
            return self::createTabEntry(__('Asset Watch', 'assetwatch'));
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Profile) {
            self::showFormForProfile($item);
        }
        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getAllRights(): array
    {
        return [
            [
                'itemtype' => PluginAssetwatchAlert::class,
                'label'    => PluginAssetwatchAlert::getTypeName(Session::getPluralNumber()),
                'field'    => PluginAssetwatchInstall::RIGHT_ALERT,
            ],
            [
                'rights' => [READ => __('Read'), UPDATE => __('Update')],
                'label'  => __('Configuration'),
                'field'  => PluginAssetwatchInstall::RIGHT_CONFIG,
            ],
        ];
    }

    public static function showFormForProfile(Profile $profile): void
    {
        $profiles_id = (int) $profile->getID();
        $canedit = Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, PURGE]);

        echo "<div class='spaced'>";
        if ($canedit) {
            echo "<form method='post' action='" . Profile::getFormURL() . "'>";
        }

        $profile->displayRightsChoiceMatrix(self::getAllRights(), [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => __('Asset Watch', 'assetwatch'),
        ]);

        if ($canedit) {
            echo "<div class='center'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary mt-2']);
            echo "</div>";
            Html::closeForm();
        }
        echo "</div>";
    }
}
