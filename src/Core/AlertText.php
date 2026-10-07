<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Assetwatch\Core;

/**
 * Builds the short, mostly language-neutral one-line summary stored with
 * each alert (used in lists and searches). Localized texts are produced by
 * the GLPI layer from the structured content.
 */
final class AlertText
{
    public const TYPE_NO_REPORT = 'no_report';
    public const TYPE_DISK_LOW  = 'disk_low';
    public const TYPE_HARDWARE  = 'hardware';
    public const TYPE_IDENTITY  = 'identity';
    public const TYPE_RACK      = 'rack';

    public const MAX_LENGTH = 255;

    /**
     * @param array<string, mixed> $content
     */
    public static function summary(string $type, array $content): string
    {
        switch ($type) {
            case self::TYPE_NO_REPORT:
                $text = sprintf(
                    '%d h without inventory (last: %s, threshold: %d h)',
                    (int) ($content['hours_since'] ?? 0),
                    (string) ($content['last_inventory'] ?? '?'),
                    (int) ($content['threshold_hours'] ?? 0)
                );
                break;
            case self::TYPE_DISK_LOW:
                $text = sprintf(
                    '%s: %s GB free / %s GB (%s%%)',
                    (string) ($content['mountpoint'] ?? '?'),
                    self::number($content['free_gb'] ?? 0),
                    self::number($content['total_gb'] ?? 0),
                    self::number($content['free_percent'] ?? 0)
                );
                break;
            case self::TYPE_HARDWARE:
            case self::TYPE_IDENTITY:
                $parts = [];
                foreach ((array) ($content['changes'] ?? []) as $change) {
                    $parts[] = self::changeLine((array) $change);
                }
                $text = implode('; ', $parts);
                break;
            case self::TYPE_RACK:
                $text = self::arrow((string) ($content['old'] ?? ''), (string) ($content['new'] ?? ''));
                if (!empty($content['user'])) {
                    $text .= ' (' . $content['user'] . ')';
                }
                break;
            default:
                $text = '';
        }
        return self::truncate($text);
    }

    /**
     * @param array<string, mixed> $change
     */
    public static function changeLine(array $change): string
    {
        return ((string) ($change['field'] ?? '')) . ' ' . self::arrow((string) ($change['old'] ?? ''), (string) ($change['new'] ?? ''));
    }

    public static function arrow(string $old, string $new): string
    {
        if ($old === '') {
            return '+ ' . $new;
        }
        if ($new === '') {
            return '- ' . $old;
        }
        return $old . ' -> ' . $new;
    }

    public static function truncate(string $text, int $max = self::MAX_LENGTH): string
    {
        if (mb_strlen($text, 'UTF-8') <= $max) {
            return $text;
        }
        return mb_substr($text, 0, $max - 3, 'UTF-8') . '...';
    }

    /**
     * @param mixed $value
     */
    private static function number($value): string
    {
        return number_format((float) $value, 1, '.', '');
    }
}
