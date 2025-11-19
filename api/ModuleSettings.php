<?php

namespace FluentCart\Api;

use FluentCart\Framework\Support\Arr;

class ModuleSettings
{

    const MODULE_SETTINGS_OPTION = 'fluent_cart_modules_settings';

    public static function isActive($moduleName = '')
    {
        $allSettings = self::getAllSettings();

        return Arr::get($allSettings, $moduleName . '.active') === 'yes';
    }

    public static function fileds()
    {
        return apply_filters('fluent_cart/module_setting/fields', [], []);
    }

    public static function saveSettings(array $data)
    {
        return update_option(static::MODULE_SETTINGS_OPTION, $data, true);
    }

    public static function getAllSettings($cached = true)
    {
        static $settings;
        if (isset($settings) && $cached) {
            return $settings;
        }

        $savedSettings = get_option(static::MODULE_SETTINGS_OPTION, []);

        if (!$savedSettings || !is_array($savedSettings)) {
            $savedSettings = [];
        }

        $defaults = apply_filters('fluent_cart/module_setting/default_values', [], []);

        foreach ($defaults as $key => $value) {
            if (!isset($savedSettings[$key])) {
                $savedSettings[$key] = $value;
            } else {
                $savedSettings[$key] = wp_parse_args(Arr::get($savedSettings, $key, []), $value);
            }
        }

        $settings = $savedSettings;

        return $settings;
    }

    public static function getSettings($key = null)
    {
        $settings = static::getAllSettings();
        if ($key) {
            return Arr::get($settings, $key);
        }

        return $settings;
    }

    public static function validKeys(): array
    {
        return array_keys(static::fileds());
    }

}
