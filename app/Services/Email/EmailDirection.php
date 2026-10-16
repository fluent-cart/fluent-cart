<?php

namespace FluentCart\App\Services\Email;

class EmailDirection
{
    private static $cache = [];

    /**
     * Email text is written in the site language, so an admin sending from an
     * English profile on an Arabic store must still get an RTL email.
     */
    public static function isRtl(): bool
    {
        if (is_locale_switched()) {
            return is_rtl();
        }

        $siteLocale = get_locale();
        $currentLocale = determine_locale();

        if ($siteLocale === $currentLocale) {
            return is_rtl();
        }

        if (!isset(static::$cache[$siteLocale])) {
            $switched = switch_to_locale($siteLocale);
            static::$cache[$siteLocale] = is_rtl();
            if ($switched) {
                restore_previous_locale();
            }
        }

        return static::$cache[$siteLocale];
    }

    public static function dir(): string
    {
        return static::isRtl() ? 'rtl' : 'ltr';
    }

    /**
     * Arrow pointing in the reading direction, as an HTML entity.
     */
    public static function forwardArrow(): string
    {
        return static::isRtl() ? '&larr;' : '&rarr;';
    }

    /**
     * Isolate an amount, as WooCommerce's wc_price() does with <bdi>: its own
     * characters pick the direction, so the sign and symbol stay put in RTL.
     */
    public static function amount(string $formatted): string
    {
        return '<bdi>' . esc_html($formatted) . '</bdi>';
    }

    // Phone numbers always read left to right.
    public static function ltr(string $text): string
    {
        return '<bdi dir="ltr">' . esc_html($text) . '</bdi>';
    }

    public static function lang(): string
    {
        return str_replace('_', '-', get_locale());
    }

    // Side text starts from: left in LTR, right in RTL.
    public static function start(): string
    {
        return static::isRtl() ? 'right' : 'left';
    }

    public static function end(): string
    {
        return static::isRtl() ? 'left' : 'right';
    }

    /**
     * Four-value padding/margin ("top right bottom left") with the side values
     * swapped in RTL. Email clients ignore logical CSS, so sides stay physical.
     */
    public static function sides(string $shorthand): string
    {
        $values = preg_split('/\s+/', trim($shorthand));
        if (count($values) !== 4 || !static::isRtl()) {
            return $shorthand;
        }

        return implode(' ', [$values[0], $values[3], $values[2], $values[1]]);
    }
}
