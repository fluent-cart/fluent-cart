<?php

namespace FluentCart\App\Services\Theme\Readers;

use FluentCart\App\Services\Theme\ColorMath;
use FluentCart\App\Services\Theme\ThemePalette;
use FluentCart\Framework\Support\Arr;

/**
 * Astra's Customizer colours, read from its settings rather than its slots.
 *
 * Astra keeps its Theme Colors as settings that point at a palette swatch
 * (`var(--ast-global-color-N)`) or hold a custom colour, and its primary
 * button colours as four settings that are empty until the owner sets them.
 * `astra_get_option()` merges what the owner saved over Astra's defaults, so
 * an untouched install reads its defaults and a customised one reads the
 * customisation — the same values Astra's own dynamic CSS prints from.
 *
 * Empty button settings follow Astra's own chain, not a FluentCart default:
 * background → Accent (`theme-color`), hover background → Link Hover
 * (`link-h-color`), and each text partner → the colour that reads on its
 * background.
 *
 * Verified against Astra 4.13.11.
 */
class AstraSettingsReader implements ThemeSettingsReader
{
    /**
     * Astra's outline button presets: a transparent background with a border.
     *
     * @var array
     */
    protected static $outlinePresets = ['button_04', 'button_05', 'button_06'];

    /**
     * @return bool
     */
    public static function applies(): bool
    {
        return function_exists('astra_get_option');
    }

    /**
     * @return array Role => hex or `var(--ast-global-color-N, #hex)`.
     */
    public static function roles(): array
    {
        if (!self::applies()) {
            return [];
        }

        $roles = [];

        $accent = self::read('theme-color');
        $roles['accent'] = $accent;
        $roles['text'] = self::read('text-color');
        $roles['border'] = self::read('border-color');

        // Astra paints the content area with the content background and falls
        // back to the site background outside it. Tablet and mobile inherit
        // desktop when empty, so desktop is the value that speaks for all.
        $surface = self::readResponsive('content-bg-obj-responsive');

        if ($surface === '') {
            $surface = self::readResponsive('site-layout-outside-bg-obj-responsive');
        }

        $roles['surface'] = $surface;

        // An outline preset turns the primary button transparent: there is no
        // filled button to report, so the button roles are left to the
        // palette-slot fallback.
        $preset = (string)astra_get_option('button-preset-style');

        if (!in_array($preset, self::$outlinePresets, true)) {
            $roles = array_merge($roles, self::buttonPair(
                astra_get_option('button-bg-color'),
                $accent,
                astra_get_option('button-color'),
                'button_bg',
                'button_text'
            ));

            $roles = array_merge($roles, self::buttonPair(
                astra_get_option('button-bg-h-color'),
                self::read('link-h-color'),
                astra_get_option('button-h-color'),
                'button_hover_bg',
                'button_hover_text'
            ));
        }

        return array_filter($roles, function ($value) {
            return $value !== '';
        });
    }

    /**
     * One background + text pair, following Astra's fallback chain.
     *
     * A stated background wins; an empty one follows `$fallbackBg`. A value
     * that is set but unreadable is not replaced by the fallback — Astra is
     * painting it, just not in a form FluentCart can write, so the pair is
     * omitted rather than reported as a colour Astra is not showing.
     *
     * @param mixed  $rawBg      The background setting as saved.
     * @param string $fallbackBg Already-normalised fallback background.
     * @param mixed  $rawText    The text setting as saved.
     * @param string $bgRole
     * @param string $textRole
     * @return array
     */
    protected static function buttonPair($rawBg, string $fallbackBg, $rawText, string $bgRole, string $textRole): array
    {
        $isSet = is_string($rawBg) && trim($rawBg) !== '';
        $background = $isSet ? ThemePalette::settingValue($rawBg) : $fallbackBg;

        if ($background === '') {
            return [];
        }

        $text = ThemePalette::settingValue($rawText);

        if ($text === '') {
            // Astra's own rule for an empty text: the foreground that reads on
            // the background.
            $bgHex = ThemePalette::measurable($background);
            $text = $bgHex !== '' ? ColorMath::readableText($bgHex) : '';
        }

        return [
            $bgRole   => $background,
            $textRole => $text,
        ];
    }

    /**
     * @param string $key
     * @return string
     */
    protected static function read(string $key): string
    {
        return ThemePalette::settingValue(astra_get_option($key));
    }

    /**
     * @param string $key
     * @return string
     */
    protected static function readResponsive(string $key): string
    {
        $value = astra_get_option($key);

        if (!is_array($value)) {
            return '';
        }

        return ThemePalette::settingValue(Arr::get($value, 'desktop.background-color', ''));
    }
}
