<?php

namespace FluentCart\App\Services\Theme;

/**
 * Colour maths for the storefront palette.
 *
 * A block theme hands us a handful of palette colours. The storefront needs a
 * coherent set of surfaces, borders, muted text and accent tints. Rather than
 * invent those, we derive them from the colours the theme actually declares —
 * a border is the body text mixed most of the way toward the surface, a muted
 * text tone is the same mix stopped earlier, and button text is whichever of
 * light/dark reads better on the button it sits on.
 *
 * All maths is sRGB. Luminance follows the WCAG 2.x relative-luminance
 * definition so the contrast picks match what an accessibility checker sees.
 */
class ColorMath
{
    /**
     * Parse a colour into an RGB triplet.
     *
     * Accepts #rgb, #rrggbb, #rrggbbaa and rgb()/rgba(). Returns null for
     * anything unreadable so callers can fall back instead of guessing.
     *
     * @param string $color
     * @return array|null [r, g, b] or null
     */
    public static function parse($color): ?array
    {
        $color = trim((string)$color);

        if ($color === '') {
            return null;
        }

        if (preg_match('/^#([0-9a-f]{3})$/i', $color, $matches)) {
            $shorthand = $matches[1];

            return [
                hexdec(str_repeat($shorthand[0], 2)),
                hexdec(str_repeat($shorthand[1], 2)),
                hexdec(str_repeat($shorthand[2], 2)),
            ];
        }

        if (preg_match('/^#([0-9a-f]{6})(?:[0-9a-f]{2})?$/i', $color, $matches)) {
            $sixDigit = $matches[1];

            return [
                hexdec(substr($sixDigit, 0, 2)),
                hexdec(substr($sixDigit, 2, 2)),
                hexdec(substr($sixDigit, 4, 2)),
            ];
        }

        if (preg_match('/^rgba?\(\s*([0-9.]+)[\s,]+([0-9.]+)[\s,]+([0-9.]+)/i', $color, $matches)) {
            return [
                (int)round((float)$matches[1]),
                (int)round((float)$matches[2]),
                (int)round((float)$matches[3]),
            ];
        }

        return null;
    }

    /**
     * Convert an RGB triplet to #rrggbb.
     *
     * @param array $rgb
     * @return string
     */
    public static function toHex(array $rgb): string
    {
        $hex = '#';

        foreach (array_slice($rgb, 0, 3) as $channel) {
            $channel = max(0, min(255, (int)round($channel)));
            $hex .= str_pad(dechex($channel), 2, '0', STR_PAD_LEFT);
        }

        return $hex;
    }

    /**
     * Normalise any readable colour to #rrggbb.
     *
     * @param string $color
     * @param string $fallback Returned when the colour cannot be parsed.
     * @return string
     */
    public static function hex($color, string $fallback = ''): string
    {
        $rgb = self::parse($color);

        return $rgb ? self::toHex($rgb) : $fallback;
    }

    /**
     * Mix two colours.
     *
     * @param string $first
     * @param string $second
     * @param int    $percent How much of $first to keep, 0-100.
     * @return string Hex, or '' when neither colour is readable.
     */
    public static function mix($first, $second, int $percent = 50): string
    {
        $firstRgb = self::parse($first);
        $secondRgb = self::parse($second);

        if (!$firstRgb || !$secondRgb) {
            return self::hex($firstRgb ? $first : $second);
        }

        $ratio = max(0, min(100, $percent)) / 100;
        $mixed = [];

        for ($channel = 0; $channel < 3; $channel++) {
            $mixed[$channel] = ($firstRgb[$channel] * $ratio) + ($secondRgb[$channel] * (1 - $ratio));
        }

        return self::toHex($mixed);
    }

    /**
     * WCAG relative luminance.
     *
     * @param string $color
     * @return float 0-1, or -1 when the colour cannot be read.
     */
    public static function luminance($color): float
    {
        $rgb = self::parse($color);

        if (!$rgb) {
            return -1.0;
        }

        $linear = [];

        foreach (array_slice($rgb, 0, 3) as $channel) {
            $channel = $channel / 255;
            $linear[] = $channel <= 0.03928
                ? $channel / 12.92
                : pow(($channel + 0.055) / 1.055, 2.4);
        }

        return (0.2126 * $linear[0]) + (0.7152 * $linear[1]) + (0.0722 * $linear[2]);
    }

    /**
     * Whether a colour reads as dark.
     *
     * The 0.45 threshold sits above the midpoint on purpose: mid-tone brand
     * colours read as "dark" to the eye well before their luminance halves.
     *
     * @param string $color
     * @return bool
     */
    public static function isDark($color): bool
    {
        $luminance = self::luminance($color);

        return $luminance >= 0 && $luminance < 0.45;
    }

    /**
     * WCAG contrast ratio between two colours.
     *
     * @param string $first
     * @param string $second
     * @return float 1-21, or 0 when either colour cannot be read.
     */
    public static function contrast($first, $second): float
    {
        $firstLuminance = self::luminance($first);
        $secondLuminance = self::luminance($second);

        if ($firstLuminance < 0 || $secondLuminance < 0) {
            return 0.0;
        }

        $lighter = max($firstLuminance, $secondLuminance);
        $darker = min($firstLuminance, $secondLuminance);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Pick whichever of two text colours reads better on a background.
     *
     * @param string $background
     * @param string $light
     * @param string $dark
     * @return string
     */
    public static function readableOn($background, string $light = '#ffffff', string $dark = '#1f2937'): string
    {
        return self::contrast($background, $light) >= self::contrast($background, $dark) ? $light : $dark;
    }

    /**
     * Nudge a colour toward white.
     *
     * @param string $color
     * @param int    $percent
     * @return string
     */
    public static function lighten($color, int $percent): string
    {
        return self::mix('#ffffff', $color, $percent);
    }

    /**
     * Nudge a colour toward black.
     *
     * @param string $color
     * @param int    $percent
     * @return string
     */
    public static function darken($color, int $percent): string
    {
        return self::mix('#000000', $color, $percent);
    }

    /**
     * Move a colour away from the surface it sits on, so it stays visible on
     * a light or a dark background without the caller knowing which it has.
     *
     * @param string $color
     * @param string $background
     * @param int    $percent
     * @return string
     */
    public static function awayFrom($color, $background, int $percent): string
    {
        return self::isDark($background)
            ? self::lighten($color, $percent)
            : self::darken($color, $percent);
    }
}
