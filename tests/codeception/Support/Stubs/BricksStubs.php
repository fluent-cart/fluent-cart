<?php
/**
 * A stand-in for the parts of Bricks the settings reader talks to.
 *
 * The disposable test install never loads Bricks, so the settings-reader tests
 * declare `Bricks\Theme` and `Bricks\Theme_Styles` once (guarded) and drive
 * them through BricksStub::$styles (the `bricks_theme_styles` option) and
 * BricksStub::$palette (the `bricks_color_palette` option). Both options are
 * supplied through `pre_option_*` filters while armed, never written.
 *
 * Mirrors Bricks 2.1.3:
 *  - `Theme::$instance` is the running theme; `Theme::$instance->theme_styles`
 *    is the Theme_Styles object its constructor creates (includes/init.php).
 *  - `Theme_Styles::$settings_by_id` holds the style(s) Bricks chose for the
 *    page. Bricks fills it on `wp` through `load_set_styles()`, and
 *    `set_active_style()` only ever ADDS to it (includes/theme-styles.php).
 *  - `set_active_style()` scores each style's conditions the way
 *    Database::screen_conditions() does for the three kinds the tests use —
 *    `any` (entire website) 2, `postType` 7, `ids` 10 — sorts ascending and,
 *    with the default loading method, keeps only the most specific match.
 *
 * Unarmed (`Theme::$instance === null`) there is no running Bricks: the class
 * exists but the reader does not apply, so a test class that runs after one
 * that loaded this file sees no Bricks settings.
 */

namespace Bricks {
    if (!class_exists('Bricks\\Theme', false)) {
        class Theme
        {
            /** @var Theme|null */
            public static $instance = null;

            /** @var Theme_Styles|null */
            public $theme_styles = null;
        }
    }

    if (!class_exists('Bricks\\Theme_Styles', false)) {
        class Theme_Styles
        {
            public static $styles = [];

            public static $settings_by_id = [];

            public static function load_set_styles($post_id = 0)
            {
                self::$styles = get_option('bricks_theme_styles', []);
                self::set_active_style($post_id);
            }

            public static function set_active_style($post_id = 0, $return_id = false)
            {
                $styles = get_option('bricks_theme_styles', []);

                if (empty($post_id) || is_object($post_id)) {
                    $post_id = is_home() ? get_option('page_for_posts') : get_the_ID();
                }

                $postType = get_post_type($post_id);
                $byScore = [];

                foreach ((array)$styles as $styleId => $style) {
                    $conditions = isset($style['settings']['conditions']['conditions']) ? $style['settings']['conditions']['conditions'] : false;

                    if (!is_array($conditions)) {
                        continue;
                    }

                    foreach ($conditions as $condition) {
                        $main = isset($condition['main']) ? $condition['main'] : '';
                        $score = 0;

                        if ($main === 'any') {
                            $score = 2;
                        } elseif ($main === 'postType' && $post_id && in_array($postType, (array)($condition['postType'] ?? []), true)) {
                            $score = 7;
                        } elseif ($main === 'ids' && $post_id && in_array((int)$post_id, array_map('intval', (array)($condition['ids'] ?? [])), true)) {
                            $score = 10;
                        }

                        if ($score) {
                            $byScore[$score][] = $styleId;
                        }
                    }
                }

                ksort($byScore, SORT_NUMERIC);

                $matching = [];

                foreach ($byScore as $ids) {
                    $matching = array_merge($matching, $ids);
                }

                if (!$matching) {
                    return;
                }

                $matching = [end($matching)];

                foreach ($matching as $styleId) {
                    self::$settings_by_id[$styleId] = isset($styles[$styleId]['settings']) ? $styles[$styleId]['settings'] : [];
                }

                if ($return_id) {
                    return array_keys(self::$settings_by_id);
                }
            }
        }
    }
}

namespace {
    if (!class_exists('BricksStub', false)) {
        class BricksStub
        {
            /** @var array The `bricks_theme_styles` option while armed. */
            public static $styles = [];

            /** @var array The `bricks_color_palette` option while armed. */
            public static $palette = [];

            /**
             * A running Bricks with these theme styles and palette, as an
             * install where they were saved. Like Bricks' own constructor, it
             * chooses the page's style on `wp`.
             */
            public static function arm(array $styles = [], array $palette = []): void
            {
                self::$styles = $styles;
                self::$palette = $palette;

                $bricks = new \Bricks\Theme();
                $bricks->theme_styles = new \Bricks\Theme_Styles();
                \Bricks\Theme::$instance = $bricks;
                \Bricks\Theme_Styles::$settings_by_id = [];

                add_filter('pre_option_bricks_theme_styles', [__CLASS__, 'stylesOption']);
                add_filter('pre_option_bricks_color_palette', [__CLASS__, 'paletteOption']);
                add_action('wp', [__CLASS__, 'onWp']);
            }

            public static function reset(): void
            {
                self::$styles = [];
                self::$palette = [];

                if (class_exists('Bricks\\Theme', false)) {
                    \Bricks\Theme::$instance = null;
                    \Bricks\Theme_Styles::$settings_by_id = [];
                    \Bricks\Theme_Styles::$styles = [];
                }

                remove_filter('pre_option_bricks_theme_styles', [__CLASS__, 'stylesOption']);
                remove_filter('pre_option_bricks_color_palette', [__CLASS__, 'paletteOption']);
                remove_action('wp', [__CLASS__, 'onWp']);
            }

            public static function stylesOption()
            {
                return self::$styles;
            }

            public static function paletteOption()
            {
                return self::$palette;
            }

            /** Bricks' `add_action('wp', [$this, 'load_set_styles'])`. */
            public static function onWp(): void
            {
                \Bricks\Theme_Styles::load_set_styles();
            }
        }
    }
}
