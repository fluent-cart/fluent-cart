<?php
/**
 * A stand-in for Divi's option reader.
 *
 * The disposable test install never loads Divi, so the settings-reader tests
 * declare `et_get_option()` and `et_divi_add_customizer_css()` once (guarded)
 * and drive them through DiviOptionsStub::$options (the decoded `et_divi`
 * option row).
 *
 * `et_get_option()` mirrors Divi 5.0.1 (epanel/custom_functions.php) for the
 * one-row storage Divi uses: a key saved in the row is returned as saved —
 * an empty string included — and only a key missing from the row takes
 * `$default`, and only when that default is not empty (otherwise false). The
 * result passes through `et_get_option_et_divi_{$name}`, as Divi's does.
 *
 * Divi is armed by setting the `$shortname` global to 'divi', the name
 * `et_setup_theme()` gives it and the one `et_get_option()` builds the option
 * row from. Unarmed (`$options === null`) the global is cleared, so the reader
 * does not apply and a test class that runs after one that loaded this file
 * sees no Divi settings.
 *
 * The core `background_color` / `background_image` theme mods are driven with
 * the `theme_mod_*` filters by the tests themselves, never written.
 */

namespace {
    if (!class_exists('DiviOptionsStub', false)) {
        class DiviOptionsStub
        {
            /** @var array|null option name => value; null = unarmed (no Divi) */
            public static $options = null;

            public static function arm(array $options = []): void
            {
                self::$options = $options;
                $GLOBALS['shortname'] = 'divi';
            }

            public static function reset(): void
            {
                self::$options = null;
                unset($GLOBALS['shortname']);
            }
        }
    }

    if (!function_exists('et_get_option')) {
        function et_get_option($option_name, $default_value = '', $used_for_object = '', $force_default_value = false)
        {
            $options = DiviOptionsStub::$options;
            $shortname = isset($GLOBALS['shortname']) ? $GLOBALS['shortname'] : '';

            $saved = is_array($options) && isset($options[$option_name]);
            $value = $saved ? $options[$option_name] : false;

            if (!$saved && (!empty($default_value) || $force_default_value)) {
                $value = $default_value;
            }

            return apply_filters('et_get_option_et_' . $shortname . '_' . $option_name, $value, 'et_' . $shortname . '_' . $option_name);
        }
    }

    if (!function_exists('et_divi_add_customizer_css')) {
        function et_divi_add_customizer_css()
        {
        }
    }
}
