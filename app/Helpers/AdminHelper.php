<?php

namespace FluentCart\App\Helpers;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\App;
use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Http\Controllers\ProductController;
use FluentCart\App\Models\Product;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\App\Services\Filter\OrderFilter;
use FluentCart\App\Services\Permission\PermissionManager;
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

        /**
         * Product admin tabs. Each item: `label`, `link`, and an optional `icon`
         * (inline SVG string, printed as given).
         *
         * @param array $menuItems
         * @param array $context   product_id, base_url
         */
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
            // Any-of, covering every permission a child is gated on: a role
            // holding only one of them (reviews/manage for Reviews,
            // products/create or products/edit for the taxonomy rows) must
            // still see the parent, or its authorized children could never
            // render. Children gate individually — including on the reviews
            // module being switched on at all.
            'permission' => ['products/view', 'reviews/manage', 'products/create', 'products/edit']
        ];

        $children = [];

        // The parent link is not reachable on touch (the tap opens the dropdown),
        // and the off-canvas menu renders children only — so list it as a child too.
        $children['all_products'] = [
            'label'      => __('All Products', 'fluent-cart'),
            'link'       => $baseUrl . 'products',
            'permission' => ['products/view']
        ];

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

        // Only while the store has reviews switched on. The screen behind it
        // already redirects to the dashboard when the module is off, so an
        // entry here would be a menu item that bounces whoever clicks it.
        if (ModuleSettings::isActive('reviews')) {
            $children['reviews'] = [
                'label'      => __('Reviews', 'fluent-cart'),
                'link'       => $baseUrl . 'reviews',
                'permission' => ['reviews/manage']
            ];
        }

        if (PermissionManager::hasPermission(['products/create', 'products/edit'])) {
            $taxonomies = get_taxonomies([
                'object_type' => [FluentProducts::CPT_NAME],
                'public'      => true,
            ], 'objects');

            foreach ($taxonomies as $taxonomy) {
                $children[$taxonomy->name] = [
                    'label' => $taxonomy->label,
                    'link'  => admin_url('edit-tags.php?taxonomy=' . $taxonomy->name . '&post_type=' . FluentProducts::CPT_NAME),
                    'group' => 'taxonomies',
                ];
            }
        }

        $menu['children'] = $children;

        return $menu;
    }

    /**
     * Order dropdown children by group and tag each one with its group, which
     * admin_menu.php draws a divider for when it changes.
     *
     * @param array  $children Dropdown items keyed by slug.
     * @param array  $groups   Group name => slugs it claims, in display order.
     * @param string $fallback Group for items that name none and no group claims.
     * @return array
     */
    private static function groupChildren(array $children, array $groups, $fallback)
    {
        $buckets = array_fill_keys(array_keys($groups), []);

        // Claimed items first, in the order their group lists them.
        foreach ($groups as $name => $slugs) {
            foreach ($slugs as $slug) {
                if (isset($children[$slug]) && is_array($children[$slug])) {
                    $buckets[$name][$slug] = $children[$slug];
                    $buckets[$name][$slug]['group'] = $name;
                    unset($children[$slug]);
                }
            }
        }

        foreach ($children as $slug => $child) {
            if (!is_array($child)) {
                continue;
            }

            $group = isset($child['group']) && isset($buckets[$child['group']]) ? $child['group'] : $fallback;
            $child['group'] = $group;
            $buckets[$group][$slug] = $child;
        }

        $ordered = [];
        foreach ($buckets as $items) {
            $ordered += $items;
        }

        return $ordered;
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
            'dynamic_pricing' => App::isProActive() && ModuleSettings::isActive('dynamic_pricing') ? [
                'label'      => __('Dynamic Pricing', 'fluent-cart'),
                'link'       => $baseUrl . 'dynamic_pricing',
                'permission' => ['dynamic_pricing/view'],
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
            'logs'         => [
                'label' => __('Logs', 'fluent-cart'),
                'link'  => $baseUrl . 'logs',
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

        if (isset($menuItems['products']['children']) && is_array($menuItems['products']['children'])) {
            $menuItems['products']['children'] = self::groupChildren(
                $menuItems['products']['children'],
                ['products' => [], 'taxonomies' => []],
                'products'
            );
        }

        // Add-on items no group claims join Store, after its listed items.
        $moreGroups = apply_filters('fluent_cart/admin_menu/more_item_groups', [
            'marketing' => ['coupons', 'order_bump', 'dynamic_pricing'],
            'store'     => ['taxes', 'integrations', 'withdrawal_requests', 'logs'],
        ]);
        if (!is_array($moreGroups) || !$moreGroups) {
            $moreGroups = ['store' => []];
        }
        $moreFallback = isset($moreGroups['store']) ? 'store' : array_key_last($moreGroups);

        $menuItems['more'] = [
            'label'    => __('More', 'fluent-cart'),
            'link'     => '#',
            'children' => self::groupChildren($moreItems, $moreGroups, $moreFallback),
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

