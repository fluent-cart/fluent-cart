<?php
/**
 * A stand-in for the parts of GeneratePress the settings reader talks to.
 *
 * The disposable test install never loads GeneratePress, so the settings-reader
 * tests declare its option API once (guarded) and drive it through
 * GeneratePressStub::$settings (the `generate_settings` option row), supplied
 * through `pre_option_generate_settings` while armed — never written.
 *
 * Mirrors GeneratePress 3.6.1:
 *  - `generate_get_defaults()` / `generate_get_color_defaults()` return the
 *    defaults (inc/defaults.php) through the same `generate_option_defaults` /
 *    `generate_color_option_defaults` filters. Only the colour keys the reader
 *    reads are carried.
 *  - `generate_get_option()` answers only keys `generate_get_defaults()`
 *    knows, over `wp_parse_args()` of the saved row (inc/theme-functions.php)
 *    — so a saved '' is returned as ''.
 *  - `generate_get_global_colors()` lists `global_colors` as slug/colour
 *    pairs, the list css-output.php prints `--{slug}` from.
 *  - GeneratePress hooks `generate_enqueue_dynamic_css` on
 *    `wp_enqueue_scripts` at 50 (inc/css-output.php) — the printer of every
 *    colour read. Arming hooks it; reset unhooks it.
 *
 * `GENERATE_VERSION` and the functions stay declared once loaded, as they would
 * for any vendor stub; unarmed, the printer is not hooked, so the reader does
 * not apply and a test class that runs after one that loaded this file sees no
 * GeneratePress settings and no GeneratePress palette.
 */

namespace {
    if (!defined('GENERATE_VERSION')) {
        define('GENERATE_VERSION', '3.6.1');
    }

    if (!class_exists('GeneratePressStub', false)) {
        class GeneratePressStub
        {
            /** @var array|null the saved `generate_settings` row; null = unarmed (no GeneratePress) */
            public static $settings = null;

            public static function arm(array $settings = []): void
            {
                self::$settings = $settings;

                add_filter('pre_option_generate_settings', [self::class, 'option']);
                add_action('wp_enqueue_scripts', 'generate_enqueue_dynamic_css', 50);
            }

            public static function reset(): void
            {
                self::$settings = null;

                remove_filter('pre_option_generate_settings', [self::class, 'option']);
                remove_action('wp_enqueue_scripts', 'generate_enqueue_dynamic_css', 50);
            }

            public static function option($pre)
            {
                return is_array(self::$settings) ? self::$settings : $pre;
            }

            /** GeneratePress 3.6.1's default global colours. */
            public static function defaultGlobalColors(): array
            {
                return [
                    ['name' => 'Contrast', 'slug' => 'contrast', 'color' => '#222222'],
                    ['name' => 'Contrast 2', 'slug' => 'contrast-2', 'color' => '#575760'],
                    ['name' => 'Contrast 3', 'slug' => 'contrast-3', 'color' => '#b2b2be'],
                    ['name' => 'Base', 'slug' => 'base', 'color' => '#f0f0f0'],
                    ['name' => 'Base 2', 'slug' => 'base-2', 'color' => '#f7f8f9'],
                    ['name' => 'Base 3', 'slug' => 'base-3', 'color' => '#ffffff'],
                    ['name' => 'Accent', 'slug' => 'accent', 'color' => '#1e73be'],
                ];
            }
        }
    }

    if (!function_exists('generate_get_defaults')) {
        function generate_get_defaults()
        {
            return apply_filters('generate_option_defaults', [
                'background_color'   => 'var(--base-2)',
                'text_color'         => 'var(--contrast)',
                'link_color'         => 'var(--accent)',
                'link_color_hover'   => 'var(--contrast)',
                'link_color_visited' => '',
                'global_colors'      => \GeneratePressStub::defaultGlobalColors(),
            ]);
        }
    }

    if (!function_exists('generate_get_color_defaults')) {
        function generate_get_color_defaults()
        {
            return apply_filters('generate_color_option_defaults', [
                'content_background_color'           => 'var(--base-3)',
                'content_text_color'                 => '',
                'content_link_color'                 => '',
                'content_link_hover_color'           => '',
                'form_button_background_color'       => '#55555e',
                'form_button_background_color_hover' => '#3f4047',
                'form_button_text_color'             => '#ffffff',
                'form_button_text_color_hover'       => '#ffffff',
            ]);
        }
    }

    if (!function_exists('generate_get_option')) {
        function generate_get_option($option)
        {
            $defaults = generate_get_defaults();

            if (!isset($defaults[$option])) {
                return;
            }

            $options = wp_parse_args(
                get_option('generate_settings', []),
                $defaults
            );

            return $options[$option];
        }
    }

    if (!function_exists('generate_get_global_colors')) {
        function generate_get_global_colors()
        {
            $global_colors = generate_get_option('global_colors');
            $colors = [];

            if (!empty($global_colors)) {
                foreach ((array)$global_colors as $key => $data) {
                    $colors[] = [
                        'slug'  => $data['slug'],
                        'color' => $data['color'],
                    ];
                }
            }

            return $colors;
        }
    }

    if (!function_exists('generate_enqueue_dynamic_css')) {
        function generate_enqueue_dynamic_css()
        {
        }
    }
}
