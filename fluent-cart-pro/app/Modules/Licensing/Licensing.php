<?php

namespace FluentCartPro\App\Modules\Licensing;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Services\Permission\PermissionManager;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class Licensing
{
    public function register($app)
    {

        add_action('fluent_cart/module/activated/license', function ($newData, $oldData) {
            (new \FluentCartPro\App\Modules\Licensing\Database\DBMigrator())->migrate();
        }, 10, 2);

        $app->addFilter('fluent_cart/module_setting/fields', function ($fields, $args) {
            $fields['license'] = [
                'title'       => __('Product Licensing', 'fluent-cart-pro'),
                'description' => __('Sale Licenses of your products easier than ever!', 'fluent-cart-pro'),
                'type'        => 'component',
                'component'   => 'ModuleSettings',
            ];
            return $fields;
        }, 10, 2);

        $app->addFilter('fluent_cart/module_setting/default_values', function ($values, $args) {
            if (empty($values['license']['active'])) {
                $values['license']['active'] = 'no';
            }
            return $values;
        }, 10, 2);

        if (!ModuleSettings::isActive('license')) {
            return;
        }

        $app->router->group(function ($router) {
            require_once __DIR__ . '/Http/licensing-api.php';
        });

        require_once __DIR__ . '/Hooks/license-actions.php';
        require_once __DIR__ . '/Hooks/license-filters.php';

        add_filter('fluent_cart/product_admin_items', [$this, 'addLicenseSettingsMenu'], 10, 2);
        add_filter('fluent_cart/global_admin_menu_items', [$this, 'addLicenseAdminMenu'], 10, 2);

        add_action('fluent_cart/loading_app', function () {
            Vite::enqueueScript('fluent_cart_licensing', 'licensing/license.js');
        });
    }

    public function addLicenseAdminMenu($items, $args)
    {
        $baseUrl = Arr::get($args, 'base_url');

        if (PermissionManager::hasPermission(['store/sensitive'])) {
            $items['licenses'] = [
                'label' => __('Licenses', 'fluent-cart-pro'),
                'link'  => $baseUrl . 'licenses'
            ];
        }

        return $items;
    }

    public function addLicenseSettingsMenu($items, $args)
    {
        $baseUrl = Arr::get($args, 'base_url');
        $productId = Arr::get($args, 'product_id');

        $items['license_settings'] = [
            'label' => __('License Settings', 'fluent-cart-pro'),
            'link'  => $baseUrl . 'products/' . $productId . '/license_settings'
        ];

        return $items;
    }

}
