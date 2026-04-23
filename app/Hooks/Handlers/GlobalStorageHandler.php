<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\App\App;
use FluentCart\App\Modules\StorageDrivers\Local\Local;
use FluentCart\App\Modules\StorageDrivers\S3\S3;
use FluentCart\App\Vite;
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

            if (!App::isProActive()) {
                add_filter('fluent_cart/storage/get_global_storage_drivers', [$this, 'registerPromoR2Driver'], 20, 2);
            }

            //This hook will allow others to register their storage driver with ours
            do_action('fluent_cart/register_storage_drivers');
        },9);


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

    public function registerPromoR2Driver($drivers, $args)
    {
        if (isset($drivers['r2'])) {
            return $drivers;
        }

        $drivers['r2'] = [
            'title'        => __('Cloudflare R2', 'fluent-cart'),
            'route'        => '',
            'description'  => __('Store downloadable product files in Cloudflare R2. Available in FluentCart Pro.', 'fluent-cart'),
            'logo'         => $this->getPromoR2Logo(),
            'dark_logo'    => $this->getPromoR2DarkLogo(),
            'status'       => false,
            'brand_color'  => '#f38020',
            'has_bucket'   => true,
            'requires_pro' => true,
            'upgrade_url'  => 'https://fluentcart.com/pricing/',
            'instance'     => new class {
                public function isEnabled()
                {
                    return false;
                }
            }
        ];

        return $drivers;
    }

    public function getPromoR2Logo()
    {
        return Vite::getAssetUrl('images/storage-drivers/r2.svg');
    }

    public function getPromoR2DarkLogo()
    {
        return Vite::getAssetUrl('images/storage-drivers/r2.svg');
    }

}
