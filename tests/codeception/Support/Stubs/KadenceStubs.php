<?php
/**
 * A stand-in for Kadence's `kadence()` template-tags API.
 *
 * The disposable test install never loads Kadence, so the settings-reader
 * tests declare the namespaced `Kadence\kadence()` once (guarded) and drive it
 * through KadenceOptionsStub::$mods (the theme mods Kadence reads) and
 * KadenceOptionsStub::$palette (the decoded `kadence_global_palette` option).
 *
 * The three template tags mirror Kadence 1.5.0 (inc/components/options/component.php):
 *  - option(): a saved, non-empty mod is returned as saved; otherwise
 *    Kadence's default for that key; otherwise `$default`. No per-sub-key merge.
 *  - sub_option(): walks the sub-keys and returns null for an empty one.
 *  - palette_option(): the active palette of three, with palette10's
 *    `#FfFfFf` sentinel returned as Kadence's oklch() complement expression.
 *
 * Unarmed (`$mods === null`) there is no Kadence: `Kadence\kadence()` returns
 * null, so the reader does not apply and a test class that runs after one that
 * loaded this file sees no Kadence settings and no Kadence palette.
 */

namespace {
    if (!class_exists('KadenceOptionsStub', false)) {
        class KadenceOptionsStub
        {
            /** @var array|null mod key => value; null = unarmed (no Kadence) */
            public static $mods = null;

            /** @var array|null decoded kadence_global_palette; null = Kadence's defaults */
            public static $palette = null;

            public static function reset(): void
            {
                self::$mods = null;
                self::$palette = null;
            }

            /** Kadence 1.5.0's defaults for the keys the reader reads. */
            public static function defaults(): array
            {
                return [
                    'site_background'    => ['desktop' => ['color' => 'palette8']],
                    'content_background' => ['desktop' => ['color' => 'palette9']],
                    'link_color'         => [
                        'highlight'      => 'palette1',
                        'highlight-alt'  => 'palette2',
                        'highlight-alt2' => 'palette9',
                        'style'          => 'standard',
                    ],
                    'buttons_color'      => ['color' => 'palette9', 'hover' => 'palette9'],
                    'buttons_background' => ['color' => 'palette1', 'hover' => 'palette2'],
                    'base_font'          => ['family' => '-apple-system', 'color' => 'palette4'],
                    'buttons_border_radius' => [
                        'size' => ['mobile' => '', 'tablet' => '', 'desktop' => ''],
                        'unit' => ['mobile' => 'px', 'tablet' => 'px', 'desktop' => 'px'],
                    ],
                ];
            }

            /** One of Kadence's default palettes (all three ship identical). */
            public static function defaultSwatches(): array
            {
                $colors = [
                    '#2B6CB0', '#215387', '#1A202C', '#2D3748', '#4A5568', '#718096', '#EDF2F7', '#F7FAFC',
                    '#ffffff', '#FfFfFf', '#13612e', '#1159af', '#b82105', '#f7630c', '#f5a524',
                ];
                $swatches = [];

                foreach ($colors as $index => $color) {
                    $swatches[] = ['color' => $color, 'slug' => 'palette' . ($index + 1), 'name' => 'Palette Color ' . ($index + 1)];
                }

                return $swatches;
            }

            public static function paletteData(): array
            {
                if (is_array(self::$palette)) {
                    return self::$palette;
                }

                return [
                    'palette'        => self::defaultSwatches(),
                    'second-palette' => self::defaultSwatches(),
                    'third-palette'  => self::defaultSwatches(),
                    'active'         => 'palette',
                ];
            }
        }
    }

    if (!class_exists('KadenceTemplateTagsStub', false)) {
        class KadenceTemplateTagsStub
        {
            public function option($key, $default = '')
            {
                $mods = (array)KadenceOptionsStub::$mods;
                $defaults = KadenceOptionsStub::defaults();
                $value = (isset($mods[$key]) && '' !== $mods[$key]) ? $mods[$key] : null;

                if (is_null($value) || '' === $value) {
                    $value = (isset($defaults[$key]) && '' !== $defaults[$key]) ? $defaults[$key] : null;
                }

                if (is_null($value) || '' === $value) {
                    $value = $default;
                }

                return $value;
            }

            public function sub_option($key, $first_key = '', $second_key = '', $third_key = '')
            {
                $value = $this->option($key);

                foreach ([$first_key, $second_key, $third_key] as $subKey) {
                    if (empty($subKey)) {
                        break;
                    }

                    if (isset($value[$subKey]) && (!empty($value[$subKey]) || 0 === $value[$subKey])) {
                        $value = $value[$subKey];
                    } else {
                        $value = null;
                    }
                }

                return $value;
            }

            public function palette_option($subkey, $active_palette = null)
            {
                $palette = KadenceOptionsStub::paletteData();
                $active = !empty($active_palette)
                    ? $active_palette
                    : (!empty($palette['active']) ? $palette['active'] : 'palette');
                $value = '';

                if (isset($palette[$active]) && is_array($palette[$active])) {
                    $number = (int)preg_replace('/[^0-9]/', '', $subkey) - 1;
                    $item = isset($palette[$active][$number]) && is_array($palette[$active][$number]) ? $palette[$active][$number] : [];

                    if (isset($item['slug']) && $item['slug'] === $subkey) {
                        if ('palette10' == $subkey && '#FfFfFf' == $item['color']) {
                            $value = 'oklch(from var(--global-palette1) calc(l + 0.10 * (1 - l)) calc(c * 1.00) calc(h + 180) / 100%)';
                        } else {
                            $value = !empty($item['color']) ? $item['color'] : '';
                        }
                    }
                }

                return $value;
            }
        }
    }
}

namespace Kadence {
    if (!function_exists('Kadence\\kadence')) {
        function kadence()
        {
            return is_array(\KadenceOptionsStub::$mods) ? new \KadenceTemplateTagsStub() : null;
        }
    }
}
