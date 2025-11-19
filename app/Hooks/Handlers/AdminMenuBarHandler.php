<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\App\Services\URL;
use FluentCart\App\Vite;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Services\Permission\PermissionManager;

class AdminMenuBarHandler
{

    public function register()
    {
        add_action('admin_bar_menu', [$this, 'init'], 100);
    }

    public function init($wp_admin_bar)
    {
        $isSuperAdmin = PermissionManager::userCan('is_super_admin');
        if (!$isSuperAdmin) {
            return;
        }

        $storeSettings = new StoreSettings();
        $id = 'fluent_cart_live_mode';
        $menuTitle = __('Live Mode', 'fluent-cart');
        $color = 'color: #189877;';

        if ($storeSettings->get('order_mode') === 'test') {
            $id = 'fluent_cart_test_mode';
            $menuTitle = __('Test Mode', 'fluent-cart');
            $color = 'color: #F58E07;';
        }


        $logo = Vite::getAssetUrl('images/logo/logo-white.svg');
        $title = '<div style="display: inline-flex;align-items: center;' . esc_attr($color) . '"><img src="' . esc_url($logo ?? '') . '" alt="Logo" style="width:20px; height:16px; vertical-align:middle; margin-right:6px;">' . $menuTitle . '</div>';


        // Parent node (with class)


        $wp_admin_bar->add_node([
            'id'    => $id,
            'title' => $title,
            'href'  => false, // disables direct link to act as dropdown
            'meta'  => [
                'class' => $id,
                'title' => __('FluentCart', 'fluent-cart')
            ]
        ]);

        // Child: Store Settings
        $wp_admin_bar->add_node([
            'id'     => $id . '_orders',
            'title'  => __('View Orders', 'fluent-cart'),
            'href'   => URL::getDashboardUrl('orders'),
            'parent' => $id
        ]);

        $wp_admin_bar->add_node([
            'id'     => $id . '_products',
            'title'  => __('View Products', 'fluent-cart'),
            'href'   => URL::getDashboardUrl('products'),
            'parent' => $id
        ]);

        $wp_admin_bar->add_node([
            'id'     => $id . '_settings',
            'title'  => __('Store Settings', 'fluent-cart'),
            'href'   => URL::getDashboardUrl('settings/store-settings/'),
            'parent' => $id
        ]);

        // Child: View Orders

    }

}

