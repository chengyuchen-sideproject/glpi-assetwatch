<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Assetwatch\Core;

/**
 * Compares two snapshots and lists hardware and identity changes.
 *
 * A change row is:
 *   ['field' => memory|cpu|drive|serial|name|mac|ip,
 *    'direction' => added|removed|increased|decreased|changed,
 *    'old' => string, 'new' => string]
 */
final class SnapshotDiff
{
    public const DIR_ADDED     = 'added';
    public const DIR_REMOVED   = 'removed';
    public const DIR_INCREASED = 'increased';
    public const DIR_DECREASED = 'decreased';
    public const DIR_CHANGED   = 'changed';

    /**
     * @param array<string, mixed> $old baseline
     * @param array<string, mixed> $new freshly built snapshot
     *
     * @return array{hardware: array<int, array<string, string>>, identity: array<int, array<string, string>>}
     */
    public static function compare(array $old, array $new, bool $checkHardware = true, bool $checkIdentity = true): array
    {
        $hardware = [];
        $identity = [];

        if ($checkHardware && Snapshot::hasHardware($new) && Snapshot::hasHardware($old)) {
            $oldMem = (int) ($old['memory_mb'] ?? 0);
            $newMem = (int) ($new['memory_mb'] ?? 0);
            if ($oldMem !== $newMem) {
                $hardware[] = self::row(
                    'memory',
                    $newMem > $oldMem ? self::DIR_INCREASED : self::DIR_DECREASED,
                    Snapshot::formatMb($oldMem),
                    Snapshot::formatMb($newMem)
                );
            }

            $oldCpus = (array) ($old['cpus'] ?? []);
            $newCpus = (array) ($new['cpus'] ?? []);
            $labels = array_unique(array_merge(array_keys($oldCpus), array_keys($newCpus)));
            sort($labels);
            foreach ($labels as $label) {
                $before = (int) ($oldCpus[$label] ?? 0);
                $after = (int) ($newCpus[$label] ?? 0);
                if ($before !== $after) {
                    $hardware[] = self::row(
                        'cpu',
                        $after > $before ? self::DIR_ADDED : self::DIR_REMOVED,
                        $before > 0 ? $label . ' x' . $before : '',
                        $after > 0 ? $label . ' x' . $after : ''
                    );
                }
            }

            $oldDrives = (array) ($old['drives'] ?? []);
            $newDrives = (array) ($new['drives'] ?? []);
            foreach (array_diff_key($oldDrives, $newDrives) as $key => $label) {
                $hardware[] = self::row('drive', self::DIR_REMOVED, self::driveLabel((string) $key, (string) $label), '');
            }
            foreach (array_diff_key($newDrives, $oldDrives) as $key => $label) {
                $hardware[] = self::row('drive', self::DIR_ADDED, '', self::driveLabel((string) $key, (string) $label));
            }
        }

        if ($checkIdentity) {
            foreach (['serial', 'name'] as $field) {
                $before = (string) ($old[$field] ?? '');
                $after = (string) ($new[$field] ?? '');
                // An empty new value means "not reported", not "erased".
                if ($after !== '' && strcasecmp($before, $after) !== 0) {
                    $identity[] = self::row($field, self::DIR_CHANGED, $before, $after);
                }
            }

            if (Snapshot::hasNetwork($new) && Snapshot::hasNetwork($old)) {
                foreach (['mac', 'ip'] as $field) {
                    $key = $field . 's';
                    $before = (array) ($old[$key] ?? []);
                    $after = (array) ($new[$key] ?? []);
                    foreach (array_diff($before, $after) as $value) {
                        $identity[] = self::row($field, self::DIR_REMOVED, (string) $value, '');
                    }
                    foreach (array_diff($after, $before) as $value) {
                        $identity[] = self::row($field, self::DIR_ADDED, '', (string) $value);
                    }
                }
            }
        }

        return ['hardware' => $hardware, 'identity' => $identity];
    }

    private static function driveLabel(string $key, string $label): string
    {
        if (strpos($key, 'sn:') === 0) {
            return trim($label . ' [S/N ' . substr($key, 3) . ']');
        }
        return $label;
    }

    /**
     * @return array<string, string>
     */
    private static function row(string $field, string $direction, string $old, string $new): array
    {
        return ['field' => $field, 'direction' => $direction, 'old' => $old, 'new' => $new];
    }
}
