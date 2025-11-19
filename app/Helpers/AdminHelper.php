<?php

namespace FluentCart\App\Helpers;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\App;
use FluentCart\App\Http\Controllers\ProductController;
use FluentCart\App\Models\Product;
use FluentCart\App\Services\Filter\OrderFilter;
use FluentCart\App\Services\URL;

class AdminHelper
{
    public static function getProductMenu($product, $echo = false, $activeMenu = '')
    {
        if (!$product instanceof Product) {
            $product = Product::query()->find($product);
        }

        $productId = $product->ID;

        $baseUrl = apply_filters('fluent_cart/admin_base_url', admin_url('admin.php?page=fluent-cart#/'), []);

        $menuItems = apply_filters('fluent_cart/product_admin_items', [
            'product_edit'          => [
                'label' => __('Edit Product', 'fluent-cart'),
                'link'  => $baseUrl . 'products/' . $productId
            ],
            'product_upgrade_paths' => [
                'label' => __('Upgrade Paths', 'fluent-cart'),
                'link'  => $baseUrl . 'products/' . $productId . '/upgrade-paths'
            ],
            'product_integrations' => [
                'label' => __('Integrations', 'fluent-cart'),
                'link'  => $baseUrl . 'products/' . $productId . '/integrations'
            ],
            // 'product_pricing' => [
            //     'label' => __('Pricing', 'fluent-cart'),
            //     'link' => $baseUrl . 'products/' . $productId . '/pricing'
            // ],
//            'product_integrations' => [
//                'label' => __('Integrations', 'fluent-cart'),
//                'link' => $baseUrl . 'products/' . $productId . '/integrations'
//            ]
        ], [
            'product_id' => $productId,
            'base_url' => $baseUrl
        ]);

        $request = App::request()->all();
        if (isset($request['action']) && $request['action'] == 'edit') {
            $menuItems['product_details'] = [
                'label' => __('Edit Pricing', 'fluent-cart'),
                'link'  => admin_url('admin.php?page=fluent-cart#/products/' . $productId)
            ];
        }


        $productName = $product->post_title;

        $data = [
            'menu_items'   => $menuItems,
            'active'       => $activeMenu,
            'products_url' => $baseUrl . 'products',
            'product_name' => $productName,
            'status'       => $product->post_status,
            'product_id'   => $productId
        ];

        if ($echo) {
            App::make('view')->render('admin.admin_product_menu', $data);
        } else {
            return (string)App::make('view')->make('admin.admin_product_menu', $data);
        }
    }

    public static function getAdminMenu($echo = false, $activeNav = '')
    {
        $baseUrl = apply_filters('fluent_cart/admin_base_url', admin_url('admin.php?page=fluent-cart#/'), []);
        $menuItems = apply_filters('fluent_cart/global_admin_menu_items', [
            'dashboard'    => [
                'label' => __('Dashboard', 'fluent-cart'),
                'link'  => $baseUrl
            ],
            'orders'       => [
                'label' => __('Orders', 'fluent-cart'),
                'link'  => $baseUrl . 'orders',
                'permission' => ['orders/view']
            ],
            'customers'    => [
                'label' => __('Customers', 'fluent-cart'),
                'link'  => $baseUrl . 'customers',
                'permission' => ["customers/view", "customers/manage"]
            ],
            'products'     => [
                'label' => __('Products', 'fluent-cart'),
                'link'  => $baseUrl . 'products',
                'permission' => ['products/view']
            ],
//            'integrations' => [
//                'label' => __('Integrations', 'fluent-cart'),
//                'link'  => $baseUrl . 'integrations'
//            ],
            'reports'      => [
                'label' => __('Reports', 'fluent-cart'),
                'link'  => $baseUrl . 'reports/overview',
                'permission' => ['reports/view']
            ],
            // 'attributes' => [
            //     'label' => __('Attributes', 'fluent-cart'),
            //     'link' => $baseUrl . 'attributes'
            // ],
        ], ['base_url' => $baseUrl]);

//        $menuItems['settings'] = [
//            'label' => __('Settings', 'fluent-cart'),
//            'link'  => $baseUrl . 'settings',
//        ];

        $menuItems['more'] = [
            'label'    => __('More', 'fluent-cart'),
            'link'     => '#',
            'children' => []
        ];
        if (App::isProActive() && ModuleSettings::isActive('order_bump')) {
            $menuItems['more']['children']['order_bump'] = [
                'label' => __('Order Bump', 'fluent-cart'),
                'link'  => $baseUrl . 'order_bump',
            ];
        }
        $menuItems['more']['children']['coupons'] = [
            'label' => __('Coupons', 'fluent-cart'),
            'link'  => $baseUrl . 'coupons',
        ];
        $menuItems['more']['children']['logs'] = [
            'label' => __('Logs', 'fluent-cart'),
            'link'  => $baseUrl . 'logs',
        ];
        $menuItems['more']['children']['taxes'] = [
            'label' => __('Taxes', 'fluent-cart'),
            'link'  => $baseUrl . 'taxes',
        ];


        if ($echo) {
            App::make('view')->render('admin.admin_menu', [
                'menu_items' => $menuItems,
                'active'     => $activeNav
            ]);
        } else {
             return App::make('view')->make('admin.admin_menu', [
                'menu_items' => $menuItems,
                'active'     => $activeNav
            ]);
        }
    }

    public static function pushGlobalAdminAssets()
    {
        $app = App::getInstance();

        $assets = $app['url.assets'];

        $slug = $app->config->get('app.slug');

        wp_enqueue_style(
            $slug . '_global_admin_app', $assets . 'admin/global_admin.css',
            [],
            FLUENTCART_VERSION,
        );
    }


}







