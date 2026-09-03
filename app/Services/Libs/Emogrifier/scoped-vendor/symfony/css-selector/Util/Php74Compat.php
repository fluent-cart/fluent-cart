<?php

namespace FluentEmogrifier\Vendor\Symfony\Component\CssSelector\Util;

/**
 * Minimal compatibility helpers for keeping scoped css-selector PHP 7.4-safe.
 */
final class Php74Compat
{
    public static function strContains(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return strpos($haystack, $needle) !== false;
    }
}