<?php

namespace FluentCart\App\Modules\StorageDrivers\S3;

use FluentCart\Framework\Support\Arr;

class S3Settings
{

    protected $settings;

    protected $driverHandler = 'fluent_cart_storage_settings_s3';

    static ?array $cachedSettings = null;

    public bool $isUsingDefineMode = false;

    public function __construct()
    {
        if (self::$cachedSettings) {
            $this->settings = self::$cachedSettings;
            return;
        }
        $settings = fluent_cart_get_option($this->driverHandler, []);

        $this->isUsingDefineMode = defined('FCT_S3_ACCESS_KEY') && defined('FCT_S3_SECRET_KEY');

        $this->settings = wp_parse_args($settings, static::getDefaults());

        if ($this->isUsingDefineMode) {
            $this->settings['is_using_define_mode'] = true;
            $this->settings['access_key'] = FCT_S3_ACCESS_KEY;
            $this->settings['secret_key'] = FCT_S3_SECRET_KEY;
        }

        $settings['region'] = 'us-east-1';
        self::$cachedSettings = $this->settings;
    }

    public static function getDefaults()
    {
        return [
            'is_active'  => 'no',
            'secret_key' => '',
            'access_key' => '',
            'bucket'     => '',
            'region'     => 'us-east-1',
        ];
    }

    public function isActive()
    {
        $settings = $this->get();

        $isActive = Arr::get($settings, 'is_active') === 'yes';

        if ($isActive) {
            $requiredKeys = ['secret_key', 'access_key', 'region'];

            return $this->hasRequiredKeys($settings, $requiredKeys);
        }

        return false;
    }

    private function hasRequiredKeys(array $settings, array $requiredKeys): bool
    {
        foreach ($requiredKeys as $key) {
            if (empty(Arr::get($settings, $key))) {
                return false;
            }
        }

        return true;
    }

    public function get($key = '')
    {
        return $this->settings;
    }
}