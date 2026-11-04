<?php
/**
 * A stand-in for Astra's option reader.
 *
 * The disposable test install never loads Astra, so the settings-reader tests
 * declare `astra_get_option()` once (guarded) and drive it through
 * AstraOptionsStub::$options. The stub mirrors Astra's own merge rule: a saved
 * value that is an empty string counts as unset and returns `$default`.
 *
 * Unarmed (`$options === null`) it answers `$default` for every key — the same
 * as an Astra install that states nothing — so a test class that runs after
 * one that loaded this file sees no Astra settings and no Astra palette.
 */

namespace {
    if (!class_exists('AstraOptionsStub', false)) {
        class AstraOptionsStub
        {
            /** @var array|null option key => value; null = unarmed */
            public static $options = null;

            public static function reset(): void
            {
                self::$options = null;
            }
        }
    }

    if (!function_exists('astra_get_option')) {
        function astra_get_option($option, $default = '', $deprecated = '')
        {
            $options = AstraOptionsStub::$options;

            if (!is_array($options)) {
                return $default;
            }

            return isset($options[$option]) && '' !== $options[$option] ? $options[$option] : $default;
        }
    }
}
