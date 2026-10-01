<?php

namespace FluentCart\App\Services\Theme\Readers;

use FluentCart\App\Services\Theme\ColorMath;
use FluentCart\App\Services\Theme\ThemePalette;
use FluentCart\Framework\Support\Arr;

/**
 * Kadence's Customizer colours, read from its theme mods rather than its slots.
 *
 * Kadence keeps each semantic colour in its own setting — Links
 * (`link_color`), Base Font (`base_font`), Content and Site Background
 * (`content_background`, `site_background`) and Buttons (`buttons_background`,
 * `buttons_color`) — each holding a palette slug (`palette3`) or a custom
 * colour. The button is not tied to the link colour, so each role is read
 * from its own setting through `\Kadence\kadence()->sub_option()`, the same
 * call Kadence's own styles component prints from.
 *
 * A slug is written the way Kadence writes it (`Kadence_CSS::render_color()`):
 * `paletteN` becomes `var(--global-paletteN)`, which ThemePalette resolves to
 * `var(--global-paletteN, #hex)` from `palette_option()` (the active palette
 * of three; see paletteValues()). A sub-key `sub_option()` answers null for —
 * cleared, or missing from a saved setting — takes Kadence's own default.
 *
 * Kadence has no border setting (its borders use the static
 * `--global-gray-400`), so no border is stated and FluentCart keeps deriving it.
 *
 * Verified against Kadence 1.5.0.
 */
class KadenceSettingsReader implements ThemeSettingsReader
{
    /**
     * Kadence's defaults per setting and sub-key
     * (inc/components/options/component.php, defaults()).
     *
     * @var array
     */
    protected static $defaults = [
        'link_color'         => ['highlight' => 'palette1'],
        'base_font'          => ['color' => 'palette4'],
        'buttons_background' => ['color' => 'palette1', 'hover' => 'palette2'],
        'buttons_color'      => ['color' => 'palette9', 'hover' => 'palette9'],
    ];

    /**
     * Detected by Kadence's own template-tags API, not by theme name, so a
     * child theme or renamed folder keeps working.
     *
     * @return bool
     */
    public static function applies(): bool
    {
        $kadence = self::api();

        return $kadence !== null
            && is_callable([$kadence, 'sub_option'])
            && is_callable([$kadence, 'palette_option']);
    }

    /**
     * @return array Role => hex or `var(--global-paletteN, #hex)`.
     */
    public static function roles(): array
    {
        if (!self::applies()) {
            return [];
        }

        $roles = [
            'accent'  => self::read('link_color', 'highlight'),
            'text'    => self::read('base_font', 'color'),
            'surface' => self::surface(),
        ];

        // Kadence states both halves of its button, resting and hover. The
        // hover belongs to the resting button: without one there is no hover.
        $button = self::buttonPair('color');

        if ($button) {
            $roles = array_merge($roles, $button, self::buttonPair('hover', 'button_hover_'));
        }

        return array_filter($roles, function ($value) {
            return $value !== '';
        });
    }

    /**
     * Each palette slot's property and current colour:
     * `--global-paletteN` => colour, from the palette Kadence has active — the
     * same `palette_option()` Kadence prints the properties from.
     *
     * @return array Property => colour, as Kadence answers it (palette10 can
     *               be an oklch() expression; ThemePalette drops non-hex).
     */
    public static function paletteValues(): array
    {
        if (!self::applies()) {
            return [];
        }

        $values = [];

        try {
            $kadence = self::api();

            foreach (range(1, 15) as $index) {
                $values['--global-palette' . $index] = (string)$kadence->palette_option('palette' . $index);
            }
        } catch (\Throwable $e) {
            // Kadence's API misbehaving means no palette values: references
            // stay bare and unmeasurable, which resolve() already refuses.
            return [];
        }

        return $values;
    }

    /**
     * One button half-pair: background from `buttons_background`, text from
     * `buttons_color`, both at the same state.
     *
     * A background that is set but unwritable (a gradient, an rgba()) is not
     * replaced by a default — Kadence is painting it, just not in a form
     * FluentCart can write — so the pair is omitted. A text that cannot be
     * written gets the colour that reads on the background.
     *
     * @param string $state  'color' (resting) or 'hover'.
     * @param string $prefix Role prefix.
     * @return array
     */
    protected static function buttonPair(string $state, string $prefix = 'button_'): array
    {
        $background = self::read('buttons_background', $state);

        if ($background === '') {
            return [];
        }

        $text = self::read('buttons_color', $state);

        if ($text === '') {
            $text = ColorMath::readableText(ThemePalette::measurable($background));
        }

        return [
            $prefix . 'bg'   => $background,
            $prefix . 'text' => $text,
        ];
    }

    /**
     * The content background, falling back to the site background.
     *
     * Kadence paints `.content-bg` (and the unboxed `.site`) with the content
     * background and the body with the site background; both are saved per
     * device, and desktop is the unprefixed rule that speaks for all. A
     * gradient paints no colour, so it states no surface — and the site
     * background is not under the content either, so it is not used instead.
     *
     * @return string
     */
    protected static function surface(): string
    {
        foreach (['content_background', 'site_background'] as $setting) {
            $value = self::subOption($setting, 'desktop');

            if (!is_array($value)) {
                continue;
            }

            if (Arr::get($value, 'type', 'color') === 'gradient' && Arr::get($value, 'gradient', '') !== '') {
                return '';
            }

            $color = Arr::get($value, 'color', '');

            if (is_string($color) && trim($color) !== '') {
                return self::normalise($color);
            }
        }

        return '';
    }

    /**
     * One colour setting at one sub-key, over Kadence's default for it.
     *
     * @param string $setting
     * @param string $subKey
     * @return string
     */
    protected static function read(string $setting, string $subKey): string
    {
        $raw = self::subOption($setting, $subKey);

        if (!is_string($raw) || trim($raw) === '') {
            $raw = (string)Arr::get(self::$defaults, $setting . '.' . $subKey, '');
        }

        return self::normalise($raw);
    }

    /**
     * A Kadence colour value as FluentCart writes it.
     *
     * `paletteN` is written as Kadence writes it, `var(--global-paletteN)`,
     * and resolved through ThemePalette::settingValue() like every other
     * reader's value. A value that cannot be measured — a gradient, rgba(),
     * or palette10's oklch() complement — is not stated.
     *
     * @param string $raw
     * @return string
     */
    protected static function normalise(string $raw): string
    {
        $raw = trim($raw);

        if (preg_match('/^palette(\d{1,2})$/', $raw, $matches)) {
            $raw = 'var(--global-palette' . $matches[1] . ')';
        }

        $value = ThemePalette::settingValue($raw);

        return ThemePalette::measurable($value) !== '' ? $value : '';
    }

    /**
     * `sub_option()`, shielded from Kadence's API throwing.
     *
     * @param string $setting
     * @param string $subKey
     * @return mixed
     */
    protected static function subOption(string $setting, string $subKey)
    {
        try {
            return self::api()->sub_option($setting, $subKey);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Kadence's template-tags object, or null when Kadence is not loaded.
     *
     * @return object|null
     */
    protected static function api()
    {
        if (!function_exists('Kadence\\kadence')) {
            return null;
        }

        try {
            $kadence = \Kadence\kadence();
        } catch (\Throwable $e) {
            return null;
        }

        return is_object($kadence) ? $kadence : null;
    }
}
