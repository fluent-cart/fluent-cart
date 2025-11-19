<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\App\Modules\StorageDrivers\Local\Local;
use FluentCart\App\Modules\StorageDrivers\S3\S3;
use FluentCart\Api\StorageDrivers;

class GlobalStorageHandler
{
    public function register()
    {
        add_action('fluentcart_loaded', [$this, 'init']);
    }

    public function init()
    {
        add_action('init', function () {
            (new Local())->init();
            (new S3())->init();
        });
        //This hook will allow others to register their storage driver with ours
        do_action('fluent_cart/register_storage_drivers');

    }

    public function getSettings($driver)
    {
        $storageDrivers = new StorageDrivers();
        return $storageDrivers->getSettings($driver);
    }

    public function getAll()
    {
        $storageDrivers = new StorageDrivers();
        return $storageDrivers->getAll();
    }

    public function getStatus($driver)
    {
        $storageDrivers = new StorageDrivers();
        return $storageDrivers->getStatus($driver);
    }

    public function getAllActive()
    {
        $storageDrivers = new StorageDrivers();
        return $storageDrivers->getActive();
    }
    
}
