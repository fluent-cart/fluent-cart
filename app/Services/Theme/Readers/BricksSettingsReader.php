<?php

namespace FluentCart\App\Services\Theme\Readers;

use FluentCart\App\Services\Theme\ColorMath;
use FluentCart\App\Services\Theme\ThemePalette;
use FluentCart\Framework\Support\Arr;

/**
 * Bricks' colours, read from the theme style Bricks applies to the page.
 *
 * Bricks publishes neither its palette nor its theme styles to theme.json, so
 * without a reader inheritance has nothing to follow. Its colours live in theme
 * styles (`bricks_theme_styles`): several can exist, each applied by its own
 * conditions, and Bricks itself picks the ones for the page on `wp` into
 * `Theme_Styles::$settings_by_id` — the same array its stylesheet is printed
 * from (Assets::generate_inline_css()). That choice is what is read here (see
 * activeStyles()); a page no style applies to states nothing, and so does a
 * fresh install, which has no theme style at all.
 *
 * The keys, from the theme-style controls:
 *  - Primary color (`colors.colorPrimary`) is the accent — the Link colour
 *    (`links.typography.color`) when it is unset — and also what Bricks fills
 *    its default button with (`.bricks-background-primary`; a new Button
 *    element's style is `primary`).
 *  - Body typography colour (`typography.typographyBody.color`) is the text.
 *  - Site background (`general.siteBackground`) paints `html`; on a boxed
 *    layout the content background (`general.contentBackground`) paints the
 *    `.brx-boxed` body over it. Unset, Bricks' stylesheet paints the body
 *    white.
 *  - The primary button (`button.primaryBackground`,
 *    `button.primaryTypography.color`, and the every-button
 *    `button.typography.color`), with its `:hover` keys — Bricks stores a
 *    pseudo-class state as the setting key suffixed with it.
 * Bricks has no border colour setting (`--bricks-border-color` is static), so
 * no border is stated.
 *
 * A colour is an object `{hex, rgb, raw, id}` printed the way
 * Assets::generate_css_color() prints it: a palette colour as its palette
 * property (`var(--bricks-color-{id})`, or the property its raw value names),
 * then raw, then rgb, then hex. A palette reference stays live, with the
 * palette colour as its fallback (see paletteValues()). A value that cannot be
 * measured — a translucent rgba(), dynamic data, a gradient, a var() nothing
 * resolves — is not stated.
 *
 * Verified against Bricks 2.1.3.
 */
class BricksSettingsReader implements ThemeSettingsReader
{
    /**
     * Bricks' stylesheet: `body{background-color:#fff}` (frontend.min.css).
     *
     * @var string
     */
    protected static $defaultSurface = '#ffffff';

    /**
     * Detected by the running Bricks theme — its `Theme` singleton holding the
     * Theme_Styles object its constructor creates — not by theme name, so a
     * child theme or renamed folder keeps working.
     *
     * @return bool
     */
    public static function applies(): bool
    {
        if (!class_exists('Bricks\\Theme', false) || !class_exists('Bricks\\Theme_Styles', false)) {
            return false;
        }

        $bricks = \Bricks\Theme::$instance;

        return is_object($bricks)
            && isset($bricks->theme_styles)
            && $bricks->theme_styles instanceof \Bricks\Theme_Styles;
    }

    /**
     * @return array Role => hex or `var(--bricks-color-{id}, #hex)`.
     */
    public static function roles(): array
    {
        if (!self::applies()) {
            return [];
        }

        $styles = self::activeStyles();

        if (!$styles) {
            return [];
        }

        $text = self::color($styles, 'typography', 'typographyBody', 'color');

        $roles = [
            'accent' => self::accent($styles),
            'text'   => $text,
        ];

        $button = self::button($styles, $text);

        if ($button) {
            $roles = array_merge($roles, $button);
        }

        $roles = array_filter($roles, function ($value) {
            return $value !== '';
        });

        // A style that states no colour says nothing about colour: the white
        // body below is only Bricks' stylesheet, not a choice to wear.
        if (!$roles && !self::states($styles, 'general', 'siteBackground') && !self::states($styles, 'general', 'contentBackground')) {
            return [];
        }

        $surface = self::surface($styles);

        if ($surface !== '') {
            $roles['surface'] = $surface;
        }

        return $roles;
    }

    /**
     * Each palette colour's property and current colour, as Bricks prints them
     * (Assets::generate_inline_css_color_vars()): `--bricks-color-{id}`, or
     * the property a raw `var(--x)` names, => the colour — rgb before hex, a
     * raw non-var value over both. Only an opaque colour is kept: a
     * translucent one cannot be measured, and ColorMath would drop its alpha.
     *
     * @return array Property => hex.
     */
    public static function paletteValues(): array
    {
        if (!self::applies()) {
            return [];
        }

        $values = [];

        foreach (self::palette() as $entry) {
            $hex = self::opaque($entry['value']);

            if ($hex !== '') {
                $values[$entry['property']] = $hex;
            }
        }

        return $values;
    }

    /**
     * The settings of the theme style(s) Bricks applies to this page, least
     * specific first — the order its stylesheet prints them in.
     *
     * On a front-end page Bricks chose them on `wp` for the queried post (the
     * id it scored every style's conditions against), and that choice is
     * read as is; if nothing applied, nothing did. Before `wp` — the admin
     * settings preview, a REST request — no page is being shown and Bricks
     * has chosen nothing, so it is asked the way its own block-editor
     * integration asks (`set_active_style()` with no post: the site-wide
     * conditions), and its state is put back afterwards: `set_active_style()`
     * only ever adds, and a leftover style would be painted on the page.
     *
     * ThemePalette caches the result per request, which is right here: one
     * request shows one page, and the choice is made before FluentCart prints.
     *
     * @return array Style id => settings.
     */
    protected static function activeStyles(): array
    {
        $chosen = \Bricks\Theme_Styles::$settings_by_id;

        if (!empty($chosen) || did_action('wp')) {
            return is_array($chosen) ? $chosen : [];
        }

        $before = $chosen;

        try {
            \Bricks\Theme_Styles::set_active_style(0);
            $chosen = \Bricks\Theme_Styles::$settings_by_id;
        } catch (\Throwable $e) {
            $chosen = [];
        }

        \Bricks\Theme_Styles::$settings_by_id = $before;

        return is_array($chosen) ? $chosen : [];
    }

    /**
     * Primary color, or the Link colour when Primary is unset. A Primary
     * color that is set but unwritable is not replaced: Bricks is painting it.
     *
     * @param array $styles
     * @return string
     */
    protected static function accent(array $styles): string
    {
        if (self::states($styles, 'colors', 'colorPrimary')) {
            return self::color($styles, 'colors', 'colorPrimary');
        }

        return self::color($styles, 'links', 'typography', 'color');
    }

    /**
     * The filled button Bricks paints by default, and its hover.
     *
     * A Button element's default style is `primary`, which carries the class
     * `bricks-background-primary`: Primary background
     * (`:root .bricks-button[class*="primary"]:not(.outline)`) when set, else
     * the Primary color. A background set but unwritable states no button —
     * Bricks paints it, just not in a form FluentCart can write — and neither
     * does no background at all.
     *
     * The text is the primary text, else the every-button text; unset, the
     * button inherits the body text, which is kept while it reads (WCAG 4.5:1),
     * otherwise the colour that does. The hover background is stated only
     * when written; an unset hover text is not stated, so the resting text
     * carries over as resolve() already does.
     *
     * @param array  $styles
     * @param string $bodyText The stated body text, or ''.
     * @return array
     */
    protected static function button(array $styles, string $bodyText): array
    {
        $fromPrimaryColor = !self::states($styles, 'button', 'primaryBackground');
        $background = $fromPrimaryColor
            ? self::color($styles, 'colors', 'colorPrimary')
            : self::color($styles, 'button', 'primaryBackground');

        $backgroundHex = ThemePalette::measurable($background);

        if ($backgroundHex === '') {
            return [];
        }

        $text = self::firstColor($styles, [
            ['button', 'primaryTypography', 'color'],
            ['button', 'typography', 'color'],
        ]);

        if ($text === '') {
            $bodyHex = ThemePalette::measurable($bodyText);

            $text = $bodyHex !== '' && ColorMath::contrast($backgroundHex, $bodyHex) >= 4.5
                ? $bodyText
                : ColorMath::readableText($backgroundHex);
        }

        $roles = [
            'button_bg'   => $background,
            'button_text' => $text,
        ];

        // The hover of the rule that paints the resting button: a Primary
        // color hover loses to a Primary background's resting rule.
        $hoverBg = self::color($styles, 'button', 'primaryBackground:hover');

        if ($hoverBg === '' && $fromPrimaryColor && !self::states($styles, 'button', 'primaryBackground:hover')) {
            $hoverBg = self::color($styles, 'colors', 'colorPrimary:hover');
        }

        if ($hoverBg !== '') {
            $roles['button_hover_bg'] = $hoverBg;
            $roles['button_hover_text'] = self::firstColor($styles, [
                ['button', 'primaryTypography:hover', 'color'],
                ['button', 'typography:hover', 'color'],
            ]);
        }

        return array_filter($roles, function ($value) {
            return $value !== '';
        });
    }

    /**
     * The surface Bricks paints under the content.
     *
     * `siteBackground` paints `html` (and clears the body); on a boxed layout
     * the body is `.brx-boxed`, painted with `contentBackground`, and an unset
     * one lets the site background through. `.brx-boxed` never exists on a
     * wide layout, so a content background there paints nothing. An image
     * paints no colour, so it states no surface and nothing is guessed under
     * it. With no background set, Bricks' stylesheet paints the body white.
     *
     * @param array $styles
     * @return string
     */
    protected static function surface(array $styles): string
    {
        $settings = ['siteBackground'];

        if (self::layout($styles) === 'boxed') {
            array_unshift($settings, 'contentBackground');
        }

        foreach ($settings as $setting) {
            if (self::hasImage(self::setting($styles, 'general', $setting, 'image'))) {
                return '';
            }

            if (self::states($styles, 'general', $setting, 'color')) {
                return self::color($styles, 'general', $setting, 'color');
            }
        }

        return self::$defaultSurface;
    }

    /**
     * The site layout, as Bricks decides the body class: the page's own
     * setting over the theme style's (Setup::body_class()).
     *
     * @param array $styles
     * @return string
     */
    protected static function layout(array $styles): string
    {
        if (class_exists('Bricks\\Database', false) && isset(\Bricks\Database::$page_settings)) {
            $page = (array)\Bricks\Database::$page_settings;

            if (!empty($page['siteLayout'])) {
                return (string)$page['siteLayout'];
            }
        }

        return (string)self::setting($styles, 'general', 'siteLayout');
    }

    /**
     * @param mixed $image
     * @return bool
     */
    protected static function hasImage($image): bool
    {
        return is_array($image) && (!empty($image['url']) || !empty($image['useDynamicData']));
    }

    /**
     * The first colour of several settings that is set, normalised. A set but
     * unwritable one ends the search rather than falling through.
     *
     * @param array $styles
     * @param array $paths List of [group, key, sub-key].
     * @return string
     */
    protected static function firstColor(array $styles, array $paths): string
    {
        foreach ($paths as $path) {
            if (self::states($styles, $path[0], $path[1], $path[2])) {
                return self::color($styles, $path[0], $path[1], $path[2]);
            }
        }

        return '';
    }

    /**
     * Whether any applied style sets this value.
     *
     * @param array       $styles
     * @param string      $group
     * @param string      $key
     * @param string|null $subKey
     * @return bool
     */
    protected static function states(array $styles, string $group, string $key, $subKey = null): bool
    {
        $value = self::setting($styles, $group, $key, $subKey);

        return $value !== null && $value !== '' && $value !== [];
    }

    /**
     * One value from the applied styles, the most specific first — the last
     * printed rule wins in Bricks' stylesheet, and a style that does not set
     * a value prints nothing for it. Read per leaf, so a later style's body
     * font size does not hide an earlier style's body colour.
     *
     * @param array       $styles
     * @param string      $group
     * @param string      $key
     * @param string|null $subKey
     * @return mixed|null
     */
    protected static function setting(array $styles, string $group, string $key, $subKey = null)
    {
        foreach (array_reverse($styles, true) as $settings) {
            if (!is_array($settings) || !isset($settings[$group]) || !is_array($settings[$group])) {
                continue;
            }

            if (!array_key_exists($key, $settings[$group])) {
                continue;
            }

            $value = $settings[$group][$key];

            if ($subKey !== null) {
                if (!is_array($value) || !array_key_exists($subKey, $value)) {
                    continue;
                }

                $value = $value[$subKey];
            }

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            return $value;
        }

        return null;
    }

    /**
     * One colour setting, written as FluentCart can write it.
     *
     * @param array       $styles
     * @param string      $group
     * @param string      $key
     * @param string|null $subKey
     * @return string
     */
    protected static function color(array $styles, string $group, string $key, $subKey = null): string
    {
        return self::normalise(self::setting($styles, $group, $key, $subKey));
    }

    /**
     * A Bricks colour object as a value FluentCart can write, in the order
     * Assets::generate_css_color() prints it: a palette colour as its live
     * property (measured through ThemePalette::settingValue(), which attaches
     * the palette colour as the fallback), then raw, then rgb, then hex.
     *
     * @param mixed $color
     * @return string Hex, `var(--x, #hex)`, or '' when it cannot be measured.
     */
    protected static function normalise($color): string
    {
        if (!is_array($color)) {
            return '';
        }

        $id = isset($color['id']) && is_string($color['id']) ? $color['id'] : '';

        if ($id !== '') {
            foreach (self::palette() as $entry) {
                if ($entry['id'] === $id) {
                    return self::measured('var(' . $entry['property'] . ')');
                }
            }
        }

        foreach (['raw', 'rgb', 'hex'] as $field) {
            $value = isset($color[$field]) && is_string($color[$field]) ? trim($color[$field]) : '';

            if ($value === '') {
                continue;
            }

            if (strpos($value, 'var(') === 0) {
                return self::measured($value);
            }

            return self::opaque($value);
        }

        return '';
    }

    /**
     * A reference, kept only when it can be measured.
     *
     * @param string $reference
     * @return string
     */
    protected static function measured(string $reference): string
    {
        $value = ThemePalette::settingValue($reference);

        return ThemePalette::measurable($value) !== '' ? $value : '';
    }

    /**
     * An opaque hex, #rgb or rgb()/rgba() as a lowercase hex; '' for a
     * translucent colour or anything else. ColorMath drops alpha, so it is
     * checked here first.
     *
     * @param mixed $value
     * @return string
     */
    protected static function opaque($value): string
    {
        $value = trim((string)$value);

        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})(?:[fF]{2})?$/', $value)) {
            return strtolower(ColorMath::hex($value));
        }

        if (preg_match('/^rgba?\(\s*[0-9.]+[\s,]+[0-9.]+[\s,]+[0-9.]+\s*(?:[,\/]\s*([0-9.]+%?)\s*)?\)$/i', $value, $matches)) {
            $alpha = isset($matches[1]) ? $matches[1] : '1';
            $opaque = substr($alpha, -1) === '%' ? (float)$alpha >= 100 : (float)$alpha >= 1;

            return $opaque ? strtolower(ColorMath::hex($value)) : '';
        }

        return '';
    }

    /**
     * The palette colours Bricks prints as custom properties, in its own
     * precedence: rgb, then hex, then a raw value that is not a var(); a raw
     * `var(--x)` with a colour renames the property to `--x`. A colour with
     * neither is not printed, and a reference to it falls through to the
     * colour object's own values, as in Bricks.
     *
     * The palette is the one Bricks prints on the page
     * (`Database::$global_data['colorPalette']`, multisite-aware), else the
     * `bricks_color_palette` option.
     *
     * @return array List of ['id', 'property', 'value'].
     */
    protected static function palette(): array
    {
        $palettes = null;

        if (class_exists('Bricks\\Database', false) && isset(\Bricks\Database::$global_data['colorPalette'])) {
            $palettes = \Bricks\Database::$global_data['colorPalette'];
        }

        if (!is_array($palettes)) {
            $palettes = get_option(defined('BRICKS_DB_COLOR_PALETTE') ? BRICKS_DB_COLOR_PALETTE : 'bricks_color_palette', []);
        }

        $entries = [];

        foreach ((array)$palettes as $palette) {
            if (!is_array($palette) || empty($palette['id']) || empty($palette['colors']) || !is_array($palette['colors'])) {
                continue;
            }

            foreach ($palette['colors'] as $color) {
                $id = is_array($color) ? (string)Arr::get($color, 'id', '') : '';

                if ($id === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
                    continue;
                }

                $value = (string)Arr::get($color, 'rgb', '');

                if ($value === '') {
                    $value = (string)Arr::get($color, 'hex', '');
                }

                $property = '--bricks-color-' . $id;
                $raw = trim((string)Arr::get($color, 'raw', ''));

                if ($raw !== '') {
                    if (strpos($raw, 'var(') === false) {
                        $value = $raw;
                    } elseif ($value !== '') {
                        $property = trim(str_replace(['var(', ')'], '', $raw));
                    } else {
                        continue;
                    }
                }

                if ($value === '' || !preg_match('/^--[A-Za-z0-9_-]+$/', $property)) {
                    continue;
                }

                $entries[] = [
                    'id'       => $id,
                    'property' => $property,
                    'value'    => $value,
                ];
            }
        }

        return $entries;
    }
}
