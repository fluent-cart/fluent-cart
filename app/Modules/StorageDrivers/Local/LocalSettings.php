<?php

namespace FluentCart\App\Modules\StorageDrivers\Local;

use FluentCart\Framework\Support\Arr;

class LocalSettings
{

    protected $settings;

    protected $driverHandler = 'fluent_cart_storage_settings_local';

    static ?array $cachedSettings = null;

    public function __construct()
    {
        if (self::$cachedSettings) {
            $this->settings = self::$cachedSettings;
            return;
        }

        $settings = fluent_cart_get_option($this->driverHandler, []);
        $this->settings = wp_parse_args($settings, static::getDefaults());
        self::$cachedSettings = $this->settings;
    }

    public static function getDefaults()
    {
        return [
            'is_active'    => 'yes',
        ];
    }

    public function isActive()
    {
        $settings = $this->get();

        return Arr::get($settings, 'is_active') === 'yes';
    }

    public function get($key = '')
    {
        return $this->settings;
    }
}