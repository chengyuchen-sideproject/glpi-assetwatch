<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Assetwatch\Core;

/**
 * Liveness probe of assets, between two inventories:
 *  - asset with a GLPI Agent: the agent answers on its HTTP port (any HTTP answer, even
 *    "not allowed", proves the machine and the agent are up);
 *  - otherwise (or when the agent does not answer): a TCP connection to one of the
 *    configured ports on one of its addresses.
 * An alert is raised after N consecutive failed runs, so a single lost packet is ignored.
 */
final class ProbeRule
{
    public const STATE_OK          = 'ok';
    /** The machine answers on TCP but its agent does not (service stopped, port filtered). */
    public const STATE_AGENT_DOWN  = 'agent_down';
    /** Nothing answers. */
    public const STATE_UNREACHABLE = 'unreachable';

    /** A run where at least this share of the targets fails looks like a problem on the GLPI side. */
    public const STORM_RATIO = 0.8;
    /** ...but only with enough targets for the ratio to mean something. */
    public const STORM_MIN_TARGETS = 10;

    public static function classify(bool $has_agent, bool $agent_answered, bool $tcp_answered): string
    {
        if ($has_agent && $agent_answered) {
            return self::STATE_OK;
        }
        if ($tcp_answered) {
            return $has_agent ? self::STATE_AGENT_DOWN : self::STATE_OK;
        }
        return self::STATE_UNREACHABLE;
    }

    /**
     * New stored state after a run.
     *
     * @param array{state?: string, fail_count?: int, date_first_fail?: string|null, date_last_ok?: string|null}|null $previous
     *
     * @return array{state: string, fail_count: int, date_first_fail: string|null, date_last_ok: string|null}
     */
    public static function next(?array $previous, string $result, string $now): array
    {
        if ($result === self::STATE_OK) {
            return ['state' => self::STATE_OK, 'fail_count' => 0, 'date_first_fail' => null, 'date_last_ok' => $now];
        }
        $was_failing = $previous !== null && ($previous['state'] ?? self::STATE_OK) !== self::STATE_OK;
        return [
            'state'           => $result,
            'fail_count'      => $was_failing ? (int) ($previous['fail_count'] ?? 0) + 1 : 1,
            'date_first_fail' => $was_failing ? ($previous['date_first_fail'] ?? $now) : $now,
            'date_last_ok'    => $previous['date_last_ok'] ?? null,
        ];
    }

    /**
     * Alert type for a stored state, or null when it should not alert (yet).
     *
     * @param array{state: string, fail_count: int} $state
     */
    public static function alertType(array $state, int $fail_threshold): ?string
    {
        if ($state['state'] === self::STATE_OK || $state['fail_count'] < max(1, $fail_threshold)) {
            return null;
        }
        return $state['state'] === self::STATE_AGENT_DOWN ? AlertText::TYPE_AGENT_DOWN : AlertText::TYPE_UNREACHABLE;
    }

    /**
     * True when the run looks broken as a whole (GLPI host cut from the network, DNS down...):
     * the caller then keeps the alerts as they are instead of opening one per asset.
     *
     * @param string[] $results one result per target
     */
    public static function isStorm(array $results): bool
    {
        $total = count($results);
        if ($total < self::STORM_MIN_TARGETS) {
            return false;
        }
        $failed = count(array_filter($results, static fn (string $r): bool => $r !== self::STATE_OK));
        return $failed / $total >= self::STORM_RATIO;
    }

    /**
     * Addresses worth probing: no loopback / link-local / unspecified, duplicates removed.
     *
     * @param string[] $ips
     *
     * @return string[]
     */
    public static function usableIps(array $ips): array
    {
        $result = [];
        foreach ($ips as $ip) {
            $ip = trim((string) $ip);
            if ($ip !== '' && !Cidr::isIgnorable($ip) && !in_array($ip, $result, true)) {
                $result[] = $ip;
            }
        }
        return $result;
    }

    /**
     * Host part of an URL: IPv6 between brackets.
     */
    public static function urlHost(string $address): string
    {
        return str_contains($address, ':') && !str_starts_with($address, '[') ? '[' . $address . ']' : $address;
    }
}
