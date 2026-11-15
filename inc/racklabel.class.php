<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Assetwatch\Core\RackLayout;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Printable rack label, one A4 page per rack: front view of the rack unit by unit, and a QR
 * code per asset that opens the asset in GLPI (to stick on the rack door).
 *
 * The QR codes are drawn with bacon/bacon-qr-code, which GLPI ships for two-factor
 * authentication; without it the page still prints, with the asset numbers only.
 */
class PluginAssetwatchRackLabel extends CommonGLPI
{
    public static $rightname = 'plugin_assetwatch_alert';

    public static function getTypeName($nb = 0)
    {
        return _n('Rack label', 'Rack labels', $nb, 'assetwatch');
    }

    /**
     * @param array{rack?: int, room?: int} $params
     */
    public static function getUrl(array $params): string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        return $CFG_GLPI['root_doc'] . '/plugins/assetwatch/front/racklabel.php?' . http_build_query($params);
    }

    /**
     * Absolute link for a QR code (a phone has to open it, so the root URL of GLPI is needed).
     */
    public static function absoluteUrl(string $path_without_root): string
    {
        /** @var array $CFG_GLPI */
        global $CFG_GLPI;

        $base = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $CFG_GLPI['root_doc'];
        }
        return $base . $path_without_root;
    }

    /**
     * Inline SVG of a QR code, or null when the library is not available.
     */
    public static function qrSvg(string $text, int $size = 160): ?string
    {
        if (!class_exists(Writer::class) || !class_exists(SvgImageBackEnd::class)) {
            return null;
        }
        try {
            $writer = new Writer(new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd()));
            $svg = $writer->writeString($text);
        } catch (Throwable $e) {
            return null;
        }
        // Inline in HTML: drop the XML declaration, let CSS size the drawing.
        $svg = (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $svg);
        return (string) preg_replace('/<svg([^>]*?) width="[^"]*" height="[^"]*"/', '<svg$1', $svg, 1);
    }

    /**
     * Racks to print: one rack, or every rack of a room.
     *
     * @return int[]
     */
    public static function resolveRacks(int $rack_id, int $room_id): array
    {
        /** @var DBmysql $DB */
        global $DB;

        $where = ['is_deleted' => 0, 'is_template' => 0] + getEntitiesRestrictCriteria('glpi_racks', '', '', true);
        if ($rack_id > 0) {
            $where['id'] = $rack_id;
        } elseif ($room_id > 0) {
            $where['dcrooms_id'] = $room_id;
        } else {
            return [];
        }
        $racks = iterator_to_array($DB->request(['SELECT' => ['id', 'name'], 'FROM' => 'glpi_racks', 'WHERE' => $where]), false);
        usort($racks, static fn (array $a, array $b): int => strnatcasecmp((string) $a['name'], (string) $b['name']));
        return array_map(static fn (array $r): int => (int) $r['id'], $racks);
    }

    /**
     * @param int[] $rack_ids
     *
     * @return list<array<string, mixed>>
     */
    public static function pages(array $rack_ids): array
    {
        $rooms = PluginAssetwatchAudit::rooms();
        $racks = [];
        foreach ($rack_ids as $id) {
            $rack = new Rack();
            if ($rack->getFromDB($id) && $rack->can($id, READ)) {
                $racks[$id] = $rack;
            }
        }

        // One evaluation per room (gives the assets of every rack with their model height).
        $items_by_rack = [];
        $room_ids = array_values(array_unique(array_map(static fn (Rack $r): int => (int) $r->fields['dcrooms_id'], $racks)));
        $in_rooms = array_values(array_filter($room_ids, static fn (int $r): bool => isset($rooms[$r])));
        if ($in_rooms !== []) {
            foreach (PluginAssetwatchAudit::collect($in_rooms)['items'] as $item) {
                if ($item['racked']) {
                    $items_by_rack[$item['racks_id']][] = $item;
                }
            }
        }
        // Racks in no room: read the placements directly.
        foreach ($racks as $id => $rack) {
            if (!isset($rooms[(int) $rack->fields['dcrooms_id']])) {
                $items_by_rack[$id] = self::itemsOfRack($id);
            }
        }

        $pages = [];
        foreach ($racks as $id => $rack) {
            $room = $rooms[(int) $rack->fields['dcrooms_id']] ?? null;
            $items = [];
            foreach ($items_by_rack[$id] ?? [] as $item) {
                $items[$item['itemtype'] . ':' . $item['id']] = $item;
            }
            $number_units = (int) $rack->fields['number_units'];
            $layout = RackLayout::blocks($number_units, array_map(static fn (array $i): array => [
                'key' => $i['itemtype'] . ':' . $i['id'], 'position' => (int) $i['position'], 'units' => (int) $i['units'],
            ], $items));

            // Number the assets from the top of the rack, in the order of the blocks.
            $numbers = [];
            foreach ($layout['blocks'] as $block) {
                foreach ($block['keys'] as $key) {
                    $numbers[$key] ??= count($numbers) + 1;
                }
            }
            foreach ($items as $key => $item) {
                $numbers[$key] ??= count($numbers) + 1;   // outside the rack height
            }

            $blocks = [];
            foreach ($layout['blocks'] as $block) {
                $blocks[] = $block + ['assets' => array_map(static fn (string $k): array => [
                    'number' => $numbers[$k],
                    'name'   => $items[$k]['name'] !== '' ? $items[$k]['name'] : '(' . $items[$k]['id'] . ')',
                    'rear'   => (int) $items[$k]['orientation'] === Rack::REAR,
                ], $block['keys'])];
            }

            $count = count($numbers);
            $qr_mm = $count <= 14 ? 24 : ($count <= 24 ? 18 : 15);
            $assets = [];
            foreach ($numbers as $key => $number) {
                $item = $items[$key];
                $path = $item['itemtype']::getFormURLWithID($item['id'], false);
                $assets[] = [
                    'number'   => $number,
                    'name'     => $item['name'] !== '' ? $item['name'] : '(' . $item['id'] . ')',
                    'type'     => $item['type_label'],
                    'serial'   => $item['serial'],
                    'model'    => trim($item['manufacturer'] . ' ' . $item['model']),
                    'units'    => $item['position'] . ($item['units'] > 1 ? '–' . ($item['position'] + $item['units'] - 1) : ''),
                    'rear'     => (int) $item['orientation'] === Rack::REAR,
                    'outside'  => in_array($key, $layout['outside'], true),
                    'qr'       => self::qrSvg(self::absoluteUrl($path)),
                ];
            }
            usort($assets, static fn (array $a, array $b): int => $a['number'] <=> $b['number']);

            $pages[] = [
                'id'           => $id,
                'name'         => (string) $rack->fields['name'],
                'room'         => $room !== null ? trim($room['datacenter'] . ' / ' . $room['name'], ' /') : '',
                'number_units' => $number_units,
                'used_units'   => $layout['used_units'],
                'unit_mm'      => $number_units > 0 ? round(min(7.0, 235 / $number_units), 2) : 7,
                'blocks'       => $blocks,
                'assets'       => $assets,
                'qr_mm'        => $qr_mm,
                'rack_qr'      => self::qrSvg(self::absoluteUrl(Rack::getFormURLWithID($id, false))),
            ];
        }
        return $pages;
    }

    /**
     * Assets of a rack that is in no server room.
     *
     * @return list<array<string, mixed>>
     */
    private static function itemsOfRack(int $rack_id): array
    {
        /** @var DBmysql $DB */
        /** @var array $CFG_GLPI */
        global $DB, $CFG_GLPI;

        $placements = [];
        foreach ($DB->request(['FROM' => 'glpi_items_racks', 'WHERE' => ['racks_id' => $rack_id]]) as $row) {
            if (in_array($row['itemtype'], $CFG_GLPI['rackable_types'], true)) {
                $placements[(string) $row['itemtype']][(int) $row['items_id']] = $row;
            }
        }
        $items = [];
        foreach ($placements as $itemtype => $by_id) {
            foreach (PluginAssetwatchAudit::assets($itemtype, array_keys($by_id)) as $id => $asset) {
                $items[] = $asset + [
                    'racked' => true, 'racks_id' => $rack_id,
                    'position' => (int) $by_id[$id]['position'], 'orientation' => (int) $by_id[$id]['orientation'],
                ];
            }
        }
        return $items;
    }

    public static function render(array $rack_ids): string
    {
        return TemplateRenderer::getInstance()->render('@assetwatch/racklabel.html.twig', [
            'title'   => self::getTypeName(Session::getPluralNumber()),
            'pages'   => self::pages($rack_ids),
            'printed' => date('Y-m-d H:i'),
            'has_qr'  => class_exists(Writer::class),
            'lang'    => str_replace('_', '-', (string) ($_SESSION['glpilanguage'] ?? 'en_GB')),
        ]);
    }
}
