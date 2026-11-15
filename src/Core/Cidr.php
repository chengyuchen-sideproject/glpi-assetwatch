<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Assetwatch\Core;

/**
 * IPv4 / IPv6 network ranges ("10.1.0.0/16", "2001:db8::/32", or a single address).
 */
final class Cidr
{
    /**
     * Parse a comma / newline separated list.
     *
     * @return array{networks: array<int, array{bytes: string, prefix: int, text: string}>, invalid: string[]}
     */
    public static function parseList(string $raw): array
    {
        $networks = [];
        $invalid = [];
        foreach (Settings::splitList(str_replace([';', ' ', "\t"], ',', $raw)) as $entry) {
            $network = self::parse($entry);
            if ($network === null) {
                $invalid[] = $entry;
            } else {
                $networks[] = $network;
            }
        }
        return ['networks' => $networks, 'invalid' => $invalid];
    }

    /**
     * @return array{bytes: string, prefix: int, text: string}|null
     */
    public static function parse(string $entry): ?array
    {
        $entry = trim($entry);
        $parts = explode('/', $entry, 2);
        $bytes = @inet_pton($parts[0]);
        if ($bytes === false) {
            return null;
        }
        $bits = strlen($bytes) * 8;
        if (!isset($parts[1])) {
            $prefix = $bits;
        } elseif (ctype_digit($parts[1]) && (int) $parts[1] <= $bits) {
            $prefix = (int) $parts[1];
        } else {
            return null;
        }
        $bytes = self::mask($bytes, $prefix);
        return ['bytes' => $bytes, 'prefix' => $prefix, 'text' => inet_ntop($bytes) . '/' . $prefix];
    }

    /**
     * @param array<int, array{bytes: string, prefix: int, text: string}> $networks
     */
    public static function containsAny(array $networks, string $ip): bool
    {
        $bytes = @inet_pton(trim($ip));
        if ($bytes === false) {
            return false;
        }
        foreach ($networks as $network) {
            if (strlen($network['bytes']) === strlen($bytes) && self::mask($bytes, $network['prefix']) === $network['bytes']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Addresses that say nothing about where a machine is: loopback, link-local, unspecified.
     */
    public static function isIgnorable(string $ip): bool
    {
        $bytes = @inet_pton(trim($ip));
        if ($bytes === false) {
            return true;
        }
        static $ranges = null;
        $ranges ??= array_map([self::class, 'parse'], ['127.0.0.0/8', '169.254.0.0/16', '0.0.0.0/32', '::1/128', 'fe80::/10', '::/128']);
        return self::containsAny($ranges, $ip);
    }

    private static function mask(string $bytes, int $prefix): string
    {
        $out = '';
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $keep = max(0, min(8, $prefix - $i * 8));
            $out .= chr(ord($bytes[$i]) & ((0xFF << (8 - $keep)) & 0xFF));
        }
        return $out;
    }
}
