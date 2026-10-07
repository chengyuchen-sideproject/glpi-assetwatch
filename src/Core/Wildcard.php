<?php

/**
 * Asset Watch - GLPI plugin
 *
 * @license GPL-3.0-or-later
 */

namespace GlpiPlugin\Assetwatch\Core;

/**
 * Case-insensitive shell-style matching ("*" and "?" only).
 */
final class Wildcard
{
    /**
     * @param string[] $patterns
     */
    public static function matchesAny(string $subject, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches($subject, $pattern)) {
                return true;
            }
        }
        return false;
    }

    public static function matches(string $subject, string $pattern): bool
    {
        $regex = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];
            if ($char === '*') {
                $regex .= '.*';
            } elseif ($char === '?') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($char, '/');
            }
        }
        return preg_match('/^' . $regex . '$/iu', $subject) === 1;
    }
}
