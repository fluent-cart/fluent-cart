<?php

namespace FluentCart\App\Services\Theme\Readers;

use FluentCart\App\Services\Theme\ColorMath;
use FluentCart\App\Services\Theme\ThemePalette;
use FluentCart\Framework\Support\Arr;

/**
 * GeneratePress's Customizer colours, read from its settings rather than its
 * palette slots.
 *
 * GeneratePress keeps every colour in the `generate_settings` option row, each
 * a Global Color reference (`var(--accent)`) or a custom colour. Its dynamic
 * CSS reads that row over its own defaults (inc/css-output.php) — the body
 * keys over `generate_get_defaults()` in generate_base_css(), the area keys
 * over `generate_get_color_defaults()` in generate_advanced_css() — and so
 * does this reader. `generate_get_option()` is not used: it only answers keys
 * `generate_get_defaults()` knows, and the content and button colours are not
 * among them.
 *
 * The roles, from the selectors GeneratePress prints:
 *  - Content background (`content_background_color`) paints
 *    `.separate-containers .inside-article` and `.one-container .container`
 *    — the area the store is rendered in on both layouts. Saved empty, the
 *    content area is transparent and the Body background (`background_color`)
 *    shows through.
 *  - Content text / link (`content_text_color`, `content_link_color`) repaint
 *    that area when set; otherwise the Body text (`text_color`) and Link
 *    (`link_color`) colours reach it. The link colour is the accent: it is
 *    what GeneratePress paints its brand colour through (its default is
 *    `var(--accent)`), and it moves with the owner when they repoint it.
 *  - Buttons (`form_button_background_color`, `form_button_text_color` and
 *    their `_hover` pair) paint `button`, `a.button` and core button blocks.
 *    GeneratePress's default button is a grey `#55555e`, not its accent.
 * GeneratePress has no general border colour (`form_border_color` is only its
 * inputs' border), so no border is stated.
 *
 * A saved '' prints nothing (GeneratePress_CSS::add_property() skips it). For
 * the button that leaves GeneratePress's stylesheet painting it — main.css
 * `button{background:#55555e;color:#fff}` — so that is what an empty button
 * states; its stylesheet has no button hover, so an empty hover states none
 * and FluentCart derives it. An empty body colour leaves the browser's, which
 * is no theme choice, so it states nothing.
 *
 * `var(--slug)` stays live, measured through the Global Colors GeneratePress
 * prints on :root (see paletteValues()). A value that cannot be measured — an
 * rgba(), a named colour, a reference to a Global Color that does not exist —
 * is not stated, and a set-but-unwritable value is not replaced by the next
 * setting in its chain: GeneratePress is painting it, just not in a form
 * FluentCart can write.
 *
 * Verified against GeneratePress 3.6.1.
 */
class GeneratePressSettingsReader implements ThemeSettingsReader
{
    /**
     * What GeneratePress's stylesheet paints a button with when its colour is
     * saved empty (assets/css/main.min.css).
     *
     * @var array
     */
    protected static $stylesheetButton = [
        'background' => '#55555e',
        'text'       => '#ffffff',
    ];

    /**
     * Detected by GeneratePress's own API — its version constant, its option
     * defaults, its Global Colors, and its dynamic-CSS printer hooked on
     * `wp_enqueue_scripts` (inc/css-output.php) — not by theme name, so a
     * child theme or renamed folder keeps working. The hook is what makes the
     * settings real: it is the printer that paints every colour read here.
     *
     * @return bool
     */
    public static function applies(): bool
    {
        return defined('GENERATE_VERSION')
            && function_exists('generate_get_defaults')
            && function_exists('generate_get_color_defaults')
            && function_exists('generate_get_global_colors')
            && has_action('wp_enqueue_scripts', 'generate_enqueue_dynamic_css') !== false;
    }

    /**
     * @return array Role => hex or `var(--slug, #hex)`.
     */
    public static function roles(): array
    {
        if (!self::applies()) {
            return [];
        }

        $settings = self::settings();

        $roles = [
            'accent'  => self::first($settings, ['content_link_color', 'link_color']),
            'text'    => self::first($settings, ['content_text_color', 'text_color']),
            'surface' => self::first($settings, ['content_background_color', 'background_color']),
        ];

        $button = self::button($settings);

        if ($button) {
            $roles = array_merge($roles, $button);
        }

        return array_filter($roles, function ($value) {
            return $value !== '';
        });
    }

    /**
     * Each Global Color's property and current colour: `--{slug}` => colour,
     * from `generate_get_global_colors()` — the list GeneratePress prints the
     * properties on :root from (generate_base_css()), and publishes to the
     * editor palette as bare `var(--{slug})`.
     *
     * @return array Property => colour, as GeneratePress saved it
     *               (ThemePalette drops non-hex).
     */
    public static function paletteValues(): array
    {
        if (!self::applies()) {
            return [];
        }

        $values = [];

        try {
            foreach ((array)generate_get_global_colors() as $color) {
                $slug = (string)Arr::get((array)$color, 'slug', '');
                $value = (string)Arr::get((array)$color, 'color', '');

                // GeneratePress prints only a slug with a colour.
                if ($slug !== '' && $value !== '') {
                    $values['--' . $slug] = $value;
                }
            }
        } catch (\Throwable $e) {
            // GeneratePress's API misbehaving means no palette values:
            // references stay bare and unmeasurable, which resolve() refuses.
            return [];
        }

        return $values;
    }

    /**
     * The button and its hover.
     *
     * A background saved empty is GeneratePress's stylesheet grey; one that is
     * set but unwritable states no button, and its hover goes with it. A text
     * saved empty is the stylesheet's white; one that cannot be written gets
     * the colour that reads on the background. A hover background is stated
     * only when written, and a hover text only when written — otherwise the
     * resting text carries over, as resolve() already does.
     *
     * @param array $settings
     * @return array
     */
    protected static function button(array $settings): array
    {
        $background = self::read($settings, 'form_button_background_color', self::$stylesheetButton['background']);

        if ($background === '') {
            return [];
        }

        $text = self::read($settings, 'form_button_text_color', self::$stylesheetButton['text']);

        if ($text === '') {
            $text = ColorMath::readableText(ThemePalette::measurable($background));
        }

        $roles = [
            'button_bg'   => $background,
            'button_text' => $text,
        ];

        $hoverBg = self::read($settings, 'form_button_background_color_hover');

        if ($hoverBg !== '') {
            $roles['button_hover_bg'] = $hoverBg;
            $roles['button_hover_text'] = self::read($settings, 'form_button_text_color_hover');
        }

        return array_filter($roles, function ($value) {
            return $value !== '';
        });
    }

    /**
     * The first setting in a chain that GeneratePress prints — one saved
     * non-empty — as FluentCart writes it. A set value that cannot be written
     * ends the chain: it is what GeneratePress paints there.
     *
     * @param array $settings
     * @param array $keys
     * @return string
     */
    protected static function first(array $settings, array $keys): string
    {
        foreach ($keys as $key) {
            if (self::isSet($settings, $key)) {
                return self::read($settings, $key);
            }
        }

        return '';
    }

    /**
     * One setting as FluentCart writes it; empty takes `$default`.
     *
     * @param array  $settings
     * @param string $key
     * @param string $default
     * @return string Hex, `var(--slug, #hex)`, or ''.
     */
    protected static function read(array $settings, string $key, string $default = ''): string
    {
        $raw = self::isSet($settings, $key) ? trim((string)$settings[$key]) : $default;

        $value = ThemePalette::settingValue($raw);

        return ThemePalette::measurable($value) !== '' ? $value : '';
    }

    /**
     * Whether GeneratePress prints this setting: a non-empty string.
     *
     * @param array  $settings
     * @param string $key
     * @return bool
     */
    protected static function isSet(array $settings, string $key): bool
    {
        return isset($settings[$key]) && is_string($settings[$key]) && trim($settings[$key]) !== '';
    }

    /**
     * The saved `generate_settings` row over GeneratePress's defaults — both
     * sets, merged the way its dynamic CSS merges each.
     *
     * @return array
     */
    protected static function settings(): array
    {
        $saved = get_option('generate_settings', []);
        $saved = is_array($saved) ? $saved : [];

        try {
            $defaults = array_merge((array)generate_get_color_defaults(), (array)generate_get_defaults());
        } catch (\Throwable $e) {
            $defaults = [];
        }

        return wp_parse_args($saved, $defaults);
    }
}
