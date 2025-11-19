<?php

namespace FluentCart\App\Modules\StorageDrivers;

abstract class BaseStorageSettings
{
    protected $settings;

    protected $driverHandler = 'fluent_cart_storage_settings_';

    public function __construct($slug)
    {
        $settings = fluent_cart_get_option($this->driverHandler.$slug, []);
        $this->settings = wp_parse_args($settings, $this->getDefaultSettings());
    }

    abstract protected function getDefaultSettings();

    abstract public function isActive();

    public function get()
    {
        return $this->settings;
    }

}
