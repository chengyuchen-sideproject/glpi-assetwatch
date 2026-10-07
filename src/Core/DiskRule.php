<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Assetwatch\Core;

/**
 * "A partition is low on free space."
 *
 * A partition is reported only when BOTH thresholds are crossed, so that a
 * huge volume with a few percent left but hundreds of GB free stays quiet.
 */
final class DiskRule
{
    /**
     * @param array<int, array{mountpoint?: string, name?: string, filesystem?: string, total_mb?: int|float|string, free_mb?: int|float|string}> $disks
     *
     * @return array<string, array{mountpoint: string, filesystem: string, total_gb: float, free_gb: float, free_percent: float}>
     *         findings keyed by mount point
     */
    public static function evaluate(array $disks, Settings $settings): array
    {
        $findings = [];
        foreach ($disks as $disk) {
            $mount = trim((string) ($disk['mountpoint'] ?? ''));
            if ($mount === '') {
                $mount = trim((string) ($disk['name'] ?? ''));
            }
            $fs = strtolower(trim((string) ($disk['filesystem'] ?? '')));
            $total = (float) ($disk['total_mb'] ?? 0);
            $free = (float) ($disk['free_mb'] ?? 0);

            if ($mount === '' || $total <= 0 || $free < 0) {
                continue;
            }
            if ($fs !== '' && in_array($fs, $settings->diskExcludedFs, true)) {
                continue;
            }
            if (Wildcard::matchesAny($mount, $settings->diskExcludedMounts)) {
                continue;
            }

            $free = min($free, $total);
            $freePercent = $free / $total * 100;
            $freeGb = $free / 1024;
            if ($freePercent < $settings->diskMinFreePercent && $freeGb < $settings->diskMinFreeGb) {
                $findings[$mount] = [
                    'mountpoint'   => $mount,
                    'filesystem'   => $fs,
                    'total_gb'     => round($total / 1024, 1),
                    'free_gb'      => round($freeGb, 1),
                    'free_percent' => round($freePercent, 1),
                ];
            }
        }
        ksort($findings);
        return $findings;
    }
}
