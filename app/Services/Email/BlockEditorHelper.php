<?php

namespace FluentCart\App\Services\Email;

class BlockEditorHelper
{
    /**
     * All editor style presets in email-safe values (px, hex).
     * Used by both the Gutenberg editor settings and the email block renderer.
     *
     * @return array{spacing: array, font-family: array, font-size: array, color: array}
     */
    public static function getStyleDefaults()
    {
        return [
            'spacing'     => [
                [
                    'name' => '2X-Small',
                    'slug' => 'fct-xx-small',
                    'size' => '5px',
                ],
                [
                    'name' => 'X-Small',
                    'slug' => 'fct-x-small',
                    'size' => '10px',
                ],
                [
                    'name' => 'Small',
                    'slug' => 'fct-small',
                    'size' => '14px',
                ],
                [
                    'name' => 'Medium',
                    'slug' => 'fct-medium',
                    'size' => '20px',
                ],
                [
                    'name' => 'Large',
                    'slug' => 'fct-large',
                    'size' => '30px',
                ],
                [
                    'name' => 'X-Large',
                    'slug' => 'fct-x-large',
                    'size' => '45px',
                ],
                [
                    'name' => '2X-Large',
                    'slug' => 'fct-xx-large',
                    'size' => '60px',
                ],
            ],
            'font-family' => [
                [
                    'name'       => 'System UI',
                    'slug'       => 'system-ui',
                    'fontFamily' => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol'",
                ],
                [
                    'name'       => 'Arial',
                    'slug'       => 'arial',
                    'fontFamily' => "Arial, 'Helvetica Neue', Helvetica, sans-serif",
                ],
                [
                    'name'       => 'Georgia',
                    'slug'       => 'georgia',
                    'fontFamily' => "Georgia, Times, 'Times New Roman', serif",
                ],
                [
                    'name'       => 'Helvetica',
                    'slug'       => 'helvetica',
                    'fontFamily' => "Helvetica, Arial, Verdana, sans-serif",
                ],
                [
                    'name'       => 'Courier New',
                    'slug'       => 'courier-new',
                    'fontFamily' => "'Courier New', Courier, 'Lucida Sans Typewriter', monospace",
                ],
                [
                    'name'       => 'Times New Roman',
                    'slug'       => 'times-new-roman',
                    'fontFamily' => "'Times New Roman', Times, Baskerville, Georgia, serif",
                ],
                [
                    'name'       => 'Trebuchet MS',
                    'slug'       => 'trebuchet-ms',
                    'fontFamily' => "'Trebuchet MS', 'Lucida Grande', 'Lucida Sans Unicode', Tahoma, sans-serif",
                ],
                [
                    'name'       => 'Verdana',
                    'slug'       => 'verdana',
                    'fontFamily' => "Verdana, Geneva, sans-serif",
                ],
            ],
            'font-size'   => [
                [
                    'name' => 'Small',
                    'slug' => 'fct-small',
                    'size' => '13px',
                ],
                [
                    'name' => 'Regular',
                    'slug' => 'fct-regular',
                    'size' => '16px',
                ],
                [
                    'name' => 'Medium',
                    'slug' => 'fct-medium',
                    'size' => '18px',
                ],
                [
                    'name' => 'Large',
                    'slug' => 'fct-large',
                    'size' => '26px',
                ],
                [
                    'name' => 'Extra Large',
                    'slug' => 'fct-x-large',
                    'size' => '32px',
                ],
            ],
            'color'       => [
                [
                    'name'  => 'Black',
                    'slug'  => 'black',
                    'color' => '#000000',
                ],
                [
                    'name'  => 'Cyan bluish gray',
                    'slug'  => 'cyan-bluish-gray',
                    'color' => '#abb8c3',
                ],
                [
                    'name'  => 'White',
                    'slug'  => 'white',
                    'color' => '#ffffff',
                ],
                [
                    'name'  => 'Pale pink',
                    'slug'  => 'pale-pink',
                    'color' => '#f78da7',
                ],
                [
                    'name'  => 'Vivid red',
                    'slug'  => 'vivid-red',
                    'color' => '#cf2e2e',
                ],
                [
                    'name'  => 'Luminous vivid orange',
                    'slug'  => 'luminous-vivid-orange',
                    'color' => '#ff6900',
                ],
                [
                    'name'  => 'Luminous vivid amber',
                    'slug'  => 'luminous-vivid-amber',
                    'color' => '#fcb900',
                ],
                [
                    'name'  => 'Light green cyan',
                    'slug'  => 'light-green-cyan',
                    'color' => '#7bdcb5',
                ],
                [
                    'name'  => 'Vivid green cyan',
                    'slug'  => 'vivid-green-cyan',
                    'color' => '#00d084',
                ],
                [
                    'name'  => 'Pale cyan blue',
                    'slug'  => 'pale-cyan-blue',
                    'color' => '#8ed1fc',
                ],
                [
                    'name'  => 'Vivid cyan blue',
                    'slug'  => 'vivid-cyan-blue',
                    'color' => '#0693e3',
                ],
                [
                    'name'  => 'Vivid purple',
                    'slug'  => 'vivid-purple',
                    'color' => '#9b51e0',
                ],
            ],
        ];
    }

    /**
     * Get a single preset category.
     *
     * @param string $key One of: spacing, font-family, font-size, color
     * @return array
     */
    public static function getDefaultPreset($key = '')
    {
        $defaults = self::getStyleDefaults();

        if ($key && isset($defaults[$key])) {
            return $defaults[$key];
        }

        return [];
    }

    /**
     * Generate CSS custom properties for spacing presets.
     *
     * @return string
     */
    public static function getStyleDefaultPresets()
    {
        $defaults = self::getStyleDefaults();
        $cssProps = '';

        foreach ($defaults['spacing'] as $item) {
            $cssProps .= '--wp--preset--spacing--' . $item['slug'] . ': ' . $item['size'] . ';';
        }

        return $cssProps;
    }

    /**
     * Replace CSS variable references with actual values for email rendering.
     *
     * @param string $css
     * @return string
     */
    public static function replaceStyleSlugsWithValues($css = '')
    {
        if (!$css) {
            return '';
        }

        $defaults = self::getStyleDefaults();

        $replaces = [
            'var(--wp--preset--color--white)' => 'white',
        ];

        foreach ($defaults as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            foreach ($value as $item) {
                if ($key === 'spacing') {
                    $replaces['var(--wp--preset--spacing--' . $item['slug'] . ')'] = $item['size'];
                } elseif ($key === 'font-size') {
                    $replaces['var(--wp--preset--font-size--' . $item['slug'] . ')'] = $item['size'];
                } elseif ($key === 'color') {
                    $replaces['var(--wp--preset--color--' . $item['slug'] . ')'] = $item['color'];
                } elseif ($key === 'font-family') {
                    $replaces['var(--wp--preset--font-family--' . $item['slug'] . ')'] = $item['fontFamily'];
                }
            }
        }

        return str_replace(array_keys($replaces), array_values($replaces), $css);
    }

    /**
     * Generate dynamic CSS for the editor to support font-family classes.
     *
     * @return string
     */
    public static function getDynamicCssForEditor()
    {
        $fonts = self::getDefaultPreset('font-family');
        $css = '';

        foreach ($fonts as $font) {
            $css .= '.editor-styles-wrapper .has-' . $font['slug'] . '-font-family { font-family: ' . $font['fontFamily'] . ' !important; }';
        }

        return $css;
    }

    /**
     * Get theme color palette from editor-color-palette support or theme.json.
     *
     * @return array
     */
    public static function getThemeColorPalette()
    {
        $color_palette = current((array)get_theme_support('editor-color-palette'));
        $theme_json_path = get_theme_file_path('theme.json');

        if (file_exists($theme_json_path)) {
            $theme_json = json_decode(file_get_contents($theme_json_path), true);

            if (isset($theme_json['settings']['color']['palette'])) {
                $color_palette = $theme_json['settings']['color']['palette'];
            }
        }
        if (!$color_palette) {
            $color_palette = [];
        }

        return (array)$color_palette;
    }

    /**
     * Get theme preference scheme (colors + font sizes).
     *
     * @return array
     */
    public static function getThemePrefScheme()
    {
        static $pref;
        if (!$pref) {
            $color_palette = self::getDefaultPreset('color');
            $font_sizes = self::getDefaultPreset('font-size');

            /**
             * Filter the theme preferences for FluentCart.
             *
             * @param array $pref The theme preferences with colors and font_sizes.
             */
            $pref = apply_filters('fluent_cart/theme_pref', [
                'colors'     => (array)$color_palette,
                'font_sizes' => (array)$font_sizes
            ]);
        }

        return $pref;
    }
}
