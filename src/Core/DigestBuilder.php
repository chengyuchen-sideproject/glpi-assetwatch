<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Assetwatch\Core;

/**
 * Groups the outcome of one status run into one digest per (entity, technical group),
 * so that an outage of many machines produces a single e-mail per team.
 */
final class DigestBuilder
{
    public const KIND_NEW      = 'new';
    public const KIND_REMINDER = 'reminder';
    public const KIND_RESOLVED = 'resolved';

    /**
     * @param array<int, array<string, mixed>> $entries each entry needs: kind, entities_id,
     *        groups_id_tech, item_name, alert_type, alert_key (other keys are carried as-is)
     *
     * @return array<int, array{entities_id: int, groups_id_tech: int, nb_new: int, nb_reminder: int, nb_resolved: int, entries: array<int, array<string, mixed>>}>
     */
    public static function build(array $entries): array
    {
        $digests = [];
        foreach ($entries as $entry) {
            $entity = (int) ($entry['entities_id'] ?? 0);
            $group = (int) ($entry['groups_id_tech'] ?? 0);
            $key = $entity . '#' . $group;
            if (!isset($digests[$key])) {
                $digests[$key] = [
                    'entities_id'    => $entity,
                    'groups_id_tech' => $group,
                    'nb_new'         => 0,
                    'nb_reminder'    => 0,
                    'nb_resolved'    => 0,
                    'entries'        => [],
                ];
            }
            $kind = (string) $entry['kind'];
            if ($kind === self::KIND_NEW) {
                $digests[$key]['nb_new']++;
            } elseif ($kind === self::KIND_REMINDER) {
                $digests[$key]['nb_reminder']++;
            } elseif ($kind === self::KIND_RESOLVED) {
                $digests[$key]['nb_resolved']++;
            }
            $digests[$key]['entries'][] = $entry;
        }

        $order = [self::KIND_NEW => 0, self::KIND_REMINDER => 1, self::KIND_RESOLVED => 2];
        foreach ($digests as &$digest) {
            usort($digest['entries'], static function (array $a, array $b) use ($order): int {
                return [$order[$a['kind']] ?? 9, (string) $a['item_name'], (string) $a['alert_type'], (string) $a['alert_key']]
                    <=> [$order[$b['kind']] ?? 9, (string) $b['item_name'], (string) $b['alert_type'], (string) $b['alert_key']];
            });
        }
        unset($digest);

        ksort($digests);
        return array_values($digests);
    }
}
