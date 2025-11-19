<?php

namespace FluentCart\Api;

use FluentCart\App\Services\FileSystem\Drivers\S3\S3FileList;
use FluentCart\Framework\Support\Arr;

class StorageDrivers
{
    /**
     * @param string $driver name like local, s3 etc
     * Get storage driver settings
     * @return array
     *
     */
    public function getSettings($driver)
    {
        return apply_filters('fluent_cart/storage/get_global_storage_settings_' . $driver, [], []);
    }

    /**
     * @return array
     *
     * All storage drivers
     */
    public function getAll()
    {
        return apply_filters('fluent_cart/storage/get_global_storage_drivers', [], []);
    }

    /**
     * @return array
     *
     * All active storage drivers
     */
    public function getActive($withInstance = false)
    {
        $drivers = $this->getAll();
        $activeDrivers = [];

        foreach ($drivers as $index => $driver) {
            $settings = $this->getSettings($driver['route']);
            $instance = $driver['instance'];


            if ($instance->isEnabled()) {
                if(!$withInstance){
                    unset($driver['instance']);
                }
                $activeDrivers[$index] = $driver;
            }
        }

        return $activeDrivers;
    }

    /**
     * @param string $driver name like local, s3 etc
     * Get storage driver Status
     * @return array
     *
     */
    public function getStatus($driver)
    {
        return apply_filters('fluent_cart/storage/get_global_storage_driver_status_' . $driver, [], []);
    }

    /**
     * @param array $data containing connect info and driver name like s3 etc
     * Get Verify connect info
     * @return array
     *
     */
    public function verifyConnectInfo(array $data)
    {
        $driver = Arr::get($data, 'driver');
        $settings = Arr::get($data, 'settings');

        return apply_filters('fluent_cart/verify_driver_connect_info_' . sanitize_text_field($driver), $settings, []);
    }
}
