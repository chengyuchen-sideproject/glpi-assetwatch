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
 * Default e-mail templates created on install (English default + Traditional Chinese).
 * Admins can edit them afterwards in Setup > Notifications > Notification templates.
 */
class PluginAssetwatchNotificationTemplates
{
    /**
     * @return array<string, array{name: string, itemtype: string, translations: array<string, array{subject: string, text: string, html: string}>}>
     */
    public static function all(): array
    {
        return [
            'digest' => [
                'name'         => 'Asset Watch - Status digest',
                'itemtype'     => PluginAssetwatchDigest::class,
                'translations' => [
                    ''      => self::digestEnglish(),
                    'zh_TW' => self::digestChinese(),
                ],
            ],
            'change' => [
                'name'         => 'Asset Watch - Change event',
                'itemtype'     => PluginAssetwatchAlert::class,
                'translations' => [
                    ''      => self::changeEnglish(),
                    'zh_TW' => self::changeChinese(),
                ],
            ],
        ];
    }

    /**
     * @return array{subject: string, text: string, html: string}
     */
    private static function digestEnglish(): array
    {
        return [
            'subject' => '[Asset Watch] ##digest.group##: ##digest.nbnew## new, ##digest.nbreminder## still active, ##digest.nbresolved## resolved',
            'text'    => <<<'TXT'
Asset Watch status digest - ##digest.date##
Group: ##digest.group##

New: ##digest.nbnew## / Still active: ##digest.nbreminder## / Resolved: ##digest.nbresolved##
##FOREACHalerts##

[##alert.kind##] ##alert.item## - ##alert.type##
  ##alert.details##
  Since: ##alert.since##
  ##alert.url##
##ENDFOREACHalerts##

All alerts: ##digest.listurl##
TXT,
            'html'    => <<<'HTML'
<p><strong>Asset Watch status digest</strong> - ##digest.date##<br>Group: ##digest.group##</p>
<p>New: <strong>##digest.nbnew##</strong> / Still active: <strong>##digest.nbreminder##</strong> / Resolved: <strong>##digest.nbresolved##</strong></p>
<table style="border-collapse: collapse;" border="1" cellpadding="4">
<tr><th>Status</th><th>Item</th><th>Type</th><th>Details</th><th>Since</th></tr>
##FOREACHalerts##
<tr><td>##alert.kind##</td><td><a href="##alert.itemurl##">##alert.item##</a></td><td><a href="##alert.url##">##alert.type##</a></td><td>##alert.details##</td><td>##alert.since##</td></tr>
##ENDFOREACHalerts##
</table>
<p><a href="##digest.listurl##">All alerts</a></p>
HTML,
        ];
    }

    /**
     * @return array{subject: string, text: string, html: string}
     */
    private static function digestChinese(): array
    {
        return [
            'subject' => '[資產監看] ##digest.group##：新增 ##digest.nbnew##、持續 ##digest.nbreminder##、已恢復 ##digest.nbresolved##',
            'text'    => <<<'TXT'
資產監看狀態彙整 - ##digest.date##
群組：##digest.group##

新增：##digest.nbnew## / 持續中：##digest.nbreminder## / 已恢復：##digest.nbresolved##
##FOREACHalerts##

[##alert.kind##] ##alert.item## - ##alert.type##
  ##alert.details##
  開始時間：##alert.since##
  ##alert.url##
##ENDFOREACHalerts##

所有告警：##digest.listurl##
TXT,
            'html'    => <<<'HTML'
<p><strong>資產監看狀態彙整</strong> - ##digest.date##<br>群組：##digest.group##</p>
<p>新增：<strong>##digest.nbnew##</strong> / 持續中：<strong>##digest.nbreminder##</strong> / 已恢復：<strong>##digest.nbresolved##</strong></p>
<table style="border-collapse: collapse;" border="1" cellpadding="4">
<tr><th>狀態</th><th>資產</th><th>類型</th><th>內容</th><th>開始時間</th></tr>
##FOREACHalerts##
<tr><td>##alert.kind##</td><td><a href="##alert.itemurl##">##alert.item##</a></td><td><a href="##alert.url##">##alert.type##</a></td><td>##alert.details##</td><td>##alert.since##</td></tr>
##ENDFOREACHalerts##
</table>
<p><a href="##digest.listurl##">查看所有告警</a></p>
HTML,
        ];
    }

    /**
     * @return array{subject: string, text: string, html: string}
     */
    private static function changeEnglish(): array
    {
        return [
            'subject' => '[Asset Watch] ##assetwatch.action##: ##assetwatch.item##',
            'text'    => <<<'TXT'
##assetwatch.action## - ##assetwatch.itemtype## ##assetwatch.item##
Date: ##assetwatch.date##
Group: ##assetwatch.group##
##IFassetwatch.user##Changed by: ##assetwatch.user####ENDIFassetwatch.user##

##FOREACHchanges##
- ##change.field## [##change.direction##]: ##change.value##
##ENDFOREACHchanges##

Asset: ##assetwatch.itemurl##
Alert: ##assetwatch.url##
TXT,
            'html'    => <<<'HTML'
<p><strong>##assetwatch.action##</strong> - ##assetwatch.itemtype## <a href="##assetwatch.itemurl##">##assetwatch.item##</a></p>
<p>Date: ##assetwatch.date##<br>Group: ##assetwatch.group##<br>##IFassetwatch.user##Changed by: ##assetwatch.user####ENDIFassetwatch.user##</p>
<table style="border-collapse: collapse;" border="1" cellpadding="4">
<tr><th>Field</th><th>Change</th><th>Before</th><th>After</th></tr>
##FOREACHchanges##
<tr><td>##change.field##</td><td>##change.direction##</td><td>##change.old##</td><td>##change.new##</td></tr>
##ENDFOREACHchanges##
</table>
<p><a href="##assetwatch.url##">View alert</a></p>
HTML,
        ];
    }

    /**
     * @return array{subject: string, text: string, html: string}
     */
    private static function changeChinese(): array
    {
        return [
            'subject' => '[資產監看] ##assetwatch.action##：##assetwatch.item##',
            'text'    => <<<'TXT'
##assetwatch.action## - ##assetwatch.itemtype## ##assetwatch.item##
時間：##assetwatch.date##
群組：##assetwatch.group##
##IFassetwatch.user##變更者：##assetwatch.user####ENDIFassetwatch.user##

##FOREACHchanges##
- ##change.field## [##change.direction##]：##change.value##
##ENDFOREACHchanges##

資產：##assetwatch.itemurl##
告警：##assetwatch.url##
TXT,
            'html'    => <<<'HTML'
<p><strong>##assetwatch.action##</strong> - ##assetwatch.itemtype## <a href="##assetwatch.itemurl##">##assetwatch.item##</a></p>
<p>時間：##assetwatch.date##<br>群組：##assetwatch.group##<br>##IFassetwatch.user##變更者：##assetwatch.user####ENDIFassetwatch.user##</p>
<table style="border-collapse: collapse;" border="1" cellpadding="4">
<tr><th>欄位</th><th>變化</th><th>變更前</th><th>變更後</th></tr>
##FOREACHchanges##
<tr><td>##change.field##</td><td>##change.direction##</td><td>##change.old##</td><td>##change.new##</td></tr>
##ENDFOREACHchanges##
</table>
<p><a href="##assetwatch.url##">查看告警</a></p>
HTML,
        ];
    }
}
