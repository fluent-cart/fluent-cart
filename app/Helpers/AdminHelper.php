<?php

namespace FluentCart\App\Helpers;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\App;
use FluentCart\App\Http\Controllers\ProductController;
use FluentCart\App\Models\Product;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\App\Services\Filter\OrderFilter;
use FluentCart\App\Services\URL;

class AdminHelper
{
    public static function getProductMenu($product, $echo = false, $activeMenu = '')
    {
        // Skip rendering menu when custom editor modal is open
        if (App::request()->get('custom-editor') === 'true') {
            return '';
        }

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
            'product_integrations'  => [
                'label' => __('Integrations', 'fluent-cart'),
                'link'  => $baseUrl . 'products/' . $productId . '/integrations'
            ],
        ], [
            'product_id' => $productId,
            'base_url'   => $baseUrl
        ]);

        $request = App::request()->all();
        if (isset($request['action']) && $request['action'] === 'edit') {
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

        if (!$echo) {
            return (string)App::make('view')->make('admin.admin_product_menu', $data);
        }

        App::make('view')->render('admin.admin_product_menu', $data);
    }

    private static function getProductsMenu($baseUrl)
    {
        $menu = [
            'label'      => __('Products', 'fluent-cart'),
            'link'       => $baseUrl . 'products',
            'permission' => ['products/view']
        ];

        $children = [];

        // Attributes power the advanced-variations feature.
        $children['product_attributes'] = [
            'label'      => __('Attributes', 'fluent-cart'),
            'link'       => $baseUrl . 'products/attributes',
            'permission' => ['products/view']
        ];

        // Inventory only when the advanced-inventory toggle is on.
        if (ModuleSettings::isActive('stock_management') &&
            ModuleSettings::getSettings('stock_management.enable_advanced_inventory') === 'yes') {
            $children['product_inventory'] = [
                'label'      => __('Inventory', 'fluent-cart'),
                'link'       => $baseUrl . 'products/inventory',
                'permission' => ['products/view']
            ];
        }

        $menu['children'] = $children;

        return $menu;
    }

    public static function getAdminMenu($echo = false, $activeNav = '')
    {
        $menuItems = self::getMenuItems();

        if (!$echo) {
            return App::make('view')->make('admin.admin_menu', [
                'menu_items' => $menuItems,
                'active'     => $activeNav
            ]);
        }

        App::make('view')->render('admin.admin_menu', [
            'menu_items' => $menuItems,
            'active'     => $activeNav
        ]);
    }

    public static function getMenuItems($withSettings = false)
    {
        $baseUrl = apply_filters('fluent_cart/admin_base_url', admin_url('admin.php?page=fluent-cart#/'), []);
        $menuItems = apply_filters('fluent_cart/global_admin_menu_items', [
            'dashboard'     => [
                'label' => __('Dashboard', 'fluent-cart'),
                'link'  => $baseUrl
            ],
            'orders'        => [
                'label'      => __('Orders', 'fluent-cart'),
                'link'       => $baseUrl . 'orders',
                'permission' => ['orders/view']
            ],
            'customers'     => [
                'label'      => __('Customers', 'fluent-cart'),
                'link'       => $baseUrl . 'customers',
                'permission' => ['customers/view', 'customers/manage']
            ],
            'products'      => self::getProductsMenu($baseUrl),
            'subscriptions' => [
                'label'      => __('Subscriptions', 'fluent-cart'),
                'link'       => $baseUrl . 'subscriptions',
                'permission' => ['subscriptions/view']
            ],
            'reports'       => [
                'label'      => __('Reports', 'fluent-cart'),
                'link'       => $baseUrl . 'reports/overview',
                'permission' => ['reports/view']
            ],
        ], ['base_url' => $baseUrl]);

        $moreItems = apply_filters('fluent_cart/global_admin_menu_more_items', array_filter([
            'integrations' => [
                'label'      => __('Integrations', 'fluent-cart'),
                'link'       => $baseUrl . 'integrations',
                'permission' => ['is_super_admin'],
            ],
            'order_bump'   => App::isProActive() && ModuleSettings::isActive('order_bump') ? [
                'label' => __('Order Bump', 'fluent-cart'),
                'link'  => $baseUrl . 'order_bump',
            ] : false,
            'coupons'      => [
                'label' => __('Coupons', 'fluent-cart'),
                'link'  => $baseUrl . 'coupons',
            ],
            'taxes'        => TaxModule::isTaxEnabled() ? [
                'label'      => __('Taxes', 'fluent-cart'),
                'link'       => $baseUrl . 'taxes',
                'permission' => ['store/settings', 'store/sensitive'],
            ] : false,
            'categories'   => [
                'label' => __('Categories', 'fluent-cart'),
                'link'  => admin_url('edit-tags.php?taxonomy=product-categories&post_type=fluent-products')
            ],
            'brands'       => [
                'label' => __('Brands', 'fluent-cart'),
                'link'  => admin_url('edit-tags.php?taxonomy=product-brands&post_type=fluent-products')
            ],
            'logs'         => [
                'label' => __('Logs', 'fluent-cart'),
                'link'  => $baseUrl . 'logs',
            ],
            'reviews'      => [
                'label' => __('Reviews', 'fluent-cart'),
                'link'  => $baseUrl . 'reviews',
            ],
        ]), ['base_url' => $baseUrl]);

        if ($withSettings) {
            $menuItems['settings'] = [
                'label'      => __('Settings', 'fluent-cart'),
                'link'       => $baseUrl . 'settings/store-settings',
                'permission' => ['store/settings', 'store/sensitive'],
                'icon'       => 'fluent-settings'
            ];
        }

        $menuItems['more'] = [
            'label'    => __('More', 'fluent-cart'),
            'link'     => '#',
            'children' => $moreItems,
        ];


        return $menuItems;
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

