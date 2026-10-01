<?php

namespace FluentCart\App\Services\Theme\Readers;

use FluentCart\App\Services\Theme\ColorMath;
use FluentCart\App\Services\Theme\ThemePalette;

/**
 * Divi's Customizer colours, read from its settings.
 *
 * Divi publishes no palette to theme.json, so without a reader inheritance has
 * nothing to follow. Its Customizer keeps the colours as flat values in the
 * `et_divi` option row, read through `et_get_option()` — the call Divi's own
 * `et_divi_add_customizer_css()` prints them from: Theme Accent Color
 * (`accent_color`), Body Text Color (`font_color`), and the Buttons section
 * (`all_buttons_bg_color`, `all_buttons_text_color` and their `_hover` pair).
 * The content area is painted from the core `background_color` theme mod
 * (`et_divi_add_main_content_background_css()`).
 *
 * Accent and body text are Divi 5's Customizer global colours, printed as
 * `--gcid-primary-color` and `--gcid-body-color` from these same options
 * (GlobalData::get_customizer_colors()), so they are stated live with the
 * option's hex as the fallback: `var(--gcid-primary-color, #hex)`. The button
 * and background settings have no property, so they are stated as hex.
 *
 * Divi's default button is an outline: a transparent background
 * (`rgba(0,0,0,0)`), the accent as its text and border. Only a background the
 * owner set to a solid hex is a filled button; anything else states no button
 * and FluentCart falls back as it did before.
 *
 * An empty value is what Divi's own CSS treats as unset — its static
 * stylesheet paints the default — so it takes Divi's default here too. A
 * value FluentCart cannot write (rgb(), rgba(), Divi 5's `hsl(from var(--gcid…))`
 * or a `$variable(…)$` reference) is not stated.
 *
 * Verified against Divi 5.0.1.
 */
class DiviSettingsReader implements ThemeSettingsReader
{
    /**
     * Divi's defaults (GlobalData::$customizer_colors, the Customizer settings
     * and the static stylesheet).
     *
     * @var array
     */
    protected static $defaults = [
        'accent_color' => '#2ea3f2',
        'font_color'   => '#666666',
        // `#main-content { background-color: #fff }` in Divi's stylesheet.
        'content'      => '#ffffff',
    ];

    /**
     * Detected by Divi's own option API and customizer printer, and by the
     * `$shortname` Divi's `et_setup_theme()` sets: `et_get_option()` is shared
     * by every Elegant Themes product and reads the row named after that
     * global, so only 'divi' means the `et_divi` row these settings live in.
     * Theme name is not used, so a child theme keeps working.
     *
     * @return bool
     */
    public static function applies(): bool
    {
        return function_exists('et_get_option')
            && function_exists('et_divi_add_customizer_css')
            && isset($GLOBALS['shortname'])
            && $GLOBALS['shortname'] === 'divi';
    }

    /**
     * @return array Role => hex or `var(--gcid-…, #hex)`.
     */
    public static function roles(): array
    {
        if (!self::applies()) {
            return [];
        }

        $accentHex = self::hex(et_get_option('accent_color', self::$defaults['accent_color']), self::$defaults['accent_color']);
        $textHex = self::hex(et_get_option('font_color', self::$defaults['font_color']), self::$defaults['font_color']);

        $roles = [
            'accent'  => $accentHex !== '' ? 'var(--gcid-primary-color, ' . $accentHex . ')' : '',
            'text'    => $textHex !== '' ? 'var(--gcid-body-color, ' . $textHex . ')' : '',
            'surface' => self::surface(),
        ];

        $button = self::button($roles['accent']);

        if ($button) {
            $roles = array_merge($roles, $button);
        }

        return array_filter($roles, function ($value) {
            return $value !== '';
        });
    }

    /**
     * The filled button and its hover, when Divi paints one.
     *
     * A background that is not a solid hex — Divi's transparent default, an
     * rgba() — is an outline or a tint, not a fill, so no button is stated and
     * its hover goes with it.
     *
     * An empty text keeps what Divi paints on a button, the accent, while that
     * reads on the background (WCAG 4.5:1); otherwise the colour that does.
     * Divi prints no hover text when it is empty, so the resting text carries
     * over — which resolve() already does when no hover text is stated. A
     * hover background that is not a solid hex (Divi's default white tint)
     * states no hover, and FluentCart derives it from the button as before.
     *
     * @param string $accent The stated accent, or ''.
     * @return array
     */
    protected static function button(string $accent): array
    {
        $background = self::hex(et_get_option('all_buttons_bg_color', 'rgba(0,0,0,0)'));

        if ($background === '') {
            return [];
        }

        $text = self::hex(et_get_option('all_buttons_text_color', ''));

        if ($text === '') {
            $accentHex = ThemePalette::measurable($accent);

            $text = $accentHex !== '' && ColorMath::contrast($background, $accentHex) >= 4.5
                ? $accent
                : ColorMath::readableText($background);
        }

        $roles = [
            'button_bg'   => $background,
            'button_text' => $text,
        ];

        $hoverBg = self::hex(et_get_option('all_buttons_bg_color_hover', 'rgba(255,255,255,0.2)'));

        if ($hoverBg !== '') {
            $roles['button_hover_bg'] = $hoverBg;
            $roles['button_hover_text'] = self::hex(et_get_option('all_buttons_text_color_hover', ''));
        }

        return $roles;
    }

    /**
     * The content background.
     *
     * Outside the Divi Builder, Divi paints `#main-content` with the core
     * Background Color and turns it transparent over a Background Image
     * (`et_divi_add_main_content_background_css()`); with neither, its
     * stylesheet paints it white. An image paints no colour, so it states no
     * surface. The theme mod is saved without its `#`.
     *
     * @return string
     */
    protected static function surface(): string
    {
        if ((string)get_theme_mod('background_image', '') !== '') {
            return '';
        }

        $color = trim((string)get_theme_mod('background_color', ''));

        if ($color === '') {
            return self::$defaults['content'];
        }

        return self::hex('#' . ltrim($color, '#'));
    }

    /**
     * A Divi colour value as a hex FluentCart can write.
     *
     * Empty or missing takes `$default` — what Divi's own stylesheet paints
     * when the setting prints nothing. Anything but a hex is not stated.
     *
     * @param mixed  $raw
     * @param string $default
     * @return string Lowercase hex, or ''.
     */
    protected static function hex($raw, string $default = ''): string
    {
        if (!is_string($raw) || trim($raw) === '') {
            $raw = $default;
        }

        $raw = trim($raw);

        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $raw) ? strtolower(ColorMath::hex($raw)) : '';
    }
}
