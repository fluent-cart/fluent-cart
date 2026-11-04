<?php
/**
 * A stand-in for Blocksy's theme-mod reader and colour manager.
 *
 * The disposable test install never loads Blocksy, so the settings-reader
 * tests declare `blocksy_get_theme_mod()` and `blocksy_manager()` once
 * (guarded) and drive them through BlocksyThemeModsStub::$mods.
 *
 * `blocksy_get_theme_mod()` mirrors Blocksy's own rule (inc/classes/database.php):
 * a saved mod is returned as saved, an absent one returns `$default_value`.
 * `blocksy_manager()->colors->get_color_palette()` mirrors
 * inc/classes/colors.php — the `colorPalette` mod over Blocksy's eight
 * defaults, each slot's variable `theme-palette-color-N` unless the slot
 * carries its own `variable`.
 *
 * Unarmed (`$mods === null`) there is no Blocksy: `blocksy_manager()` returns
 * null, so the reader does not apply and a test class that runs after one
 * that loaded this file sees no Blocksy settings and no Blocksy palette.
 */

namespace {
    if (!class_exists('BlocksyThemeModsStub', false)) {
        class BlocksyThemeModsStub
        {
            /** @var array|null mod name => value; null = unarmed (no Blocksy) */
            public static $mods = null;

            public static function reset(): void
            {
                self::$mods = null;
            }
        }
    }

    if (!class_exists('BlocksyColorsStub', false)) {
        class BlocksyColorsStub
        {
            public function get_color_palette($args = [])
            {
                $defaults = [
                    'color1' => ['color' => '#2872fa'],
                    'color2' => ['color' => '#1559ed'],
                    'color3' => ['color' => '#3A4F66'],
                    'color4' => ['color' => '#192a3d'],
                    'color5' => ['color' => '#e1e8ed'],
                    'color6' => ['color' => '#f2f5f7'],
                    'color7' => ['color' => '#FAFBFC'],
                    'color8' => ['color' => '#ffffff'],
                ];

                $colorPalette = blocksy_get_theme_mod('colorPalette', $defaults);
                $result = [];

                foreach ((array)$colorPalette as $key => $value) {
                    if (strpos($key, 'color') === false) {
                        continue;
                    }

                    $variableName = str_replace('color', 'theme-palette-color-', $key);

                    if (isset($value['variable']) && !empty($value['variable'])) {
                        $variableName = $value['variable'];
                    }

                    if (!$value) {
                        $value = $defaults[$key];
                    }

                    $result[$key] = [
                        'id'       => $key,
                        'slug'     => 'palette-color-' . str_replace('color', '', $key),
                        'color'    => $value['color'],
                        'variable' => $variableName,
                        'title'    => 'Color ' . str_replace('color', '', $key),
                    ];
                }

                return $result;
            }
        }
    }

    if (!class_exists('BlocksyManagerStub', false)) {
        class BlocksyManagerStub
        {
            public $colors;

            public function __construct()
            {
                $this->colors = new BlocksyColorsStub();
            }
        }
    }

    if (!function_exists('blocksy_get_theme_mod')) {
        function blocksy_get_theme_mod($name, $default_value = false)
        {
            $mods = BlocksyThemeModsStub::$mods;

            if (!is_array($mods)) {
                return $default_value;
            }

            return isset($mods[$name]) ? $mods[$name] : $default_value;
        }
    }

    if (!function_exists('blocksy_manager')) {
        function blocksy_manager()
        {
            return is_array(BlocksyThemeModsStub::$mods) ? new BlocksyManagerStub() : null;
        }
    }
}
