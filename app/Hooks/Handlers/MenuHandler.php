<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\App;
use FluentCart\App\Vite;
use FluentCart\Api\Taxonomy;
use FluentCart\App\Services\URL;
use FluentCart\Api\StoreSettings;
use FluentCart\Api\PaymentMethods;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\Customer;
use FluentCart\Database\DBMigrator;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;
use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Helpers\AdminHelper;
use FluentCart\App\Models\AttributeTerm;
use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Models\ShippingMethod;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\Framework\Support\Collection;
use FluentCart\App\Services\Filter\TaxFilter;
use FluentCart\App\Services\Theme\AdminTheme;
use FluentCart\App\Services\Filter\OrderFilter;
use FluentCart\App\Services\Filter\LicenseFilter;
use FluentCart\App\Services\Filter\ProductFilter;
use FluentCart\App\Services\Filter\CustomerFilter;
use FluentCart\App\Modules\Integrations\AddOnModule;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Services\Permission\PermissionManager;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;

class MenuHandler
{
    public function register()
    {
        add_action('init', [$this, 'init']);

        add_action('admin_init', function () {
            $page = App::request()->get('page') ?? '';
            if ($page == 'fluent-cart') {
                \FluentCart\App\Events\FirstTimePluginActivation::handle();

                add_action('admin_enqueue_scripts', function () {
                    Vite::enqueueStyle('fluent_cart_admin_app_css',
                        'styles/tailwind/style.css',
                    );

                    Vite::enqueueScript('fluent_cart_admin_global_js',
                        'admin/global.js',
                    );
                });

            }
        });

        // Add a post display state for special WC pages.
        add_filter('display_post_states', array($this, 'addDisplayPostStates'), 10, 2);

    }


    public function addDisplayPostStates($states, $post)
    {
        if (!$post || $post->post_type != 'page') {
            return $states;
        }


        $targetKeys = [
            'checkout_page_id'         => __('Checkout Page', 'fluent-cart'),
            'cart_page_id'             => __('Cart Page', 'fluent-cart'),
            'receipt_page_id'          => __('Receipt Page', 'fluent-cart'),
            'shop_page_id'             => __('Shop Page', 'fluent-cart'),
            'customer_profile_page_id' => __('Customer Profile Page', 'fluent-cart'),
        ];

        $pagesConfig = (new StoreSettings())->getPagesSettings();
        $pagesConfig = Arr::only($pagesConfig, array_keys($targetKeys));

        if (!is_array($pagesConfig)) {
            return $states;
        }
        $pagesConfig = array_filter($pagesConfig);

        if (empty($pagesConfig)) {
            return $states;
        }


        $pagesConfig = array_flip($pagesConfig);
        $targetKey = Arr::get($pagesConfig, $post->ID);

        if ($targetKey && isset($targetKeys[$targetKey])) {

            $logo = '<svg style="vertical-align: middle; height: 18px; width: 18px;" width="18" height="18" viewBox="0 0 300 300" fill="none" xmlns="http://www.w3.org/2000/svg">
<rect width="300" height="300" rx="30" fill="white"/>
<path d="M136.561 205.944H47.1367L61.1704 173.491C65.2906 163.963 74.6784 157.795 85.0589 157.795H191.584L184.338 174.551C176.098 193.607 157.322 205.944 136.561 205.944Z" fill="#00009F"/>
<path d="M210.643 142.439H84.8574L92.1035 125.683C100.344 106.627 119.12 94.2905 139.881 94.2905H248.565L234.531 126.743C230.411 136.271 221.023 142.439 210.643 142.439Z" fill="#00009F"/>
</svg>';

            $states['fluent_cart'] = '<span>' . $logo . '<span>' . $targetKeys[$targetKey] . '</span></span>';
        }

        return $states;

    }

    public function init()
    {

        add_action('admin_menu', array($this, 'addAdminMenu'));

        /*
         * Disable Gutenberg for Products
         */
        add_filter('parent_file', function ($parent_file) {
            $request = App::request()->all();
            global $submenu_file, $current_screen, $pagenow;

            if (isset($request['post_type']) && $request['post_type'] == FluentProducts::CPT_NAME && $current_screen->post_type == FluentProducts::CPT_NAME) {
                if ($pagenow == 'post.php') {
                    $submenu_file = 'edit.php?post_type=' . $current_screen->post_type;
                }

                if ($pagenow == 'edit-tags.php') {
                    $submenu_file = 'edit-tags.php?taxonomy=' . esc_attr($request['taxonomy']) . '&post_type=' . $current_screen->post_type;
                }

                $parent_file = 'fluent-cart';
            }

            return $parent_file;
        });

        add_action('fluent_cart/admin_menu', array($this, 'renderAdminMenu'));
        // add_action('in_admin_header', array($this, 'maybeRenderAdminMenu'));

        add_action('edit_form_top', array($this, 'pushProductNav'));

        add_action('admin_bar_menu', [$this, 'globalEnqueueAssets'], 10, 1);
    }

    public function addAdminMenu()
    {
        $capability = 'manage_options';

        if (!current_user_can('manage_options') && App::isProActive()) {
            $capability = PermissionManager::ADMIN_CAP;
        }

        if (!current_user_can($capability)) {
            return;
        }

        global $submenu;

        $adminMenuTitle = apply_filters('fluent_cart/admin_menu_title', 'FluentCart', []);

        add_menu_page(
            $adminMenuTitle,
            $adminMenuTitle,
            $capability,
            'fluent-cart',
            [$this, 'renderAdmin'],
            $this->getMenuIcon(),
            apply_filters('fluent_cart/admin_menu_position', 3)
        );

        $submenu['fluent-cart']['dashboard'] = array(
            __('Dashboard', 'fluent-cart'),
            $capability,
            'admin.php?page=fluent-cart#/',
            '',
            'fluent_cart_dashboard'
        );

        if (PermissionManager::hasPermission(["orders/view"])) {
            $submenu['fluent-cart']['orders'] = array(
                __('Orders', 'fluent-cart'),
                $capability,
                'admin.php?page=fluent-cart#/orders',
                '',
                'fluent_cart_orders'
            );
        }


        if (PermissionManager::hasPermission(["customers/view", "customers/manage"])) {
            $submenu['fluent-cart']['customers'] = array(
                __('Customers', 'fluent-cart'),
                $capability,
                'admin.php?page=fluent-cart#/customers',
                '',
                'fluent_cart_customers'
            );
        }

        if (PermissionManager::hasPermission(['products/view'])) {
            $submenu['fluent-cart']['products'] = array(
                __('Products', 'fluent-cart'),
                $capability,
                'admin.php?page=fluent-cart#/products',
                '',
                'fluent_cart_products'
            );
        }

        if (PermissionManager::userCan('is_super_admin')) {
            $submenu['fluent-cart']['integrations'] = array(
                __('Integrations', 'fluent-cart'),
                $capability,
                'admin.php?page=fluent-cart#/integrations',
                '',
                'fluent_cart_integrations'
            );
        }

        if (PermissionManager::hasPermission(['reports/view'])) {
            $submenu['fluent-cart']['reports'] = array(
                __('Reports', 'fluent-cart'),
                $capability,
                'admin.php?page=fluent-cart#/reports/overview',
                '',
                'fluent_cart_reports'
            );
        }

        if (PermissionManager::hasPermission(["store/settings", 'store/sensitive'])) {
            $submenu['fluent-cart']['settings'] = array(
                __('Settings', 'fluent-cart'),
                $capability,
                'admin.php?page=fluent-cart#/settings/store-settings/',
                '',
                'fluent_cart_settings'
            );
        }

        if (PermissionManager::hasPermission(['coupons/manage', 'coupons/view'])) {
            $submenu['fluent-cart']['coupons'] = array(
                __('Coupons', 'fluent-cart'),
                $capability,
                'admin.php?page=fluent-cart#/coupons',
                '',
                'fluent_cart_coupons'
            );
        }

        if (PermissionManager::userCan('is_super_admin')) {
            $submenu['fluent-cart']['logs'] = array(
                __('Logs', 'fluent-cart'),
                $capability,
                'admin.php?page=fluent-cart#/logs',
                '',
                'fluent_cart_logs'
            );
        }

        if (PermissionManager::hasPermission(['products/create', 'products/edit'])) {
            $taxonomies = get_taxonomies([
                'object_type' => [FluentProducts::CPT_NAME],
                'public'      => true,
            ], 'object');

            foreach ($taxonomies as $key => $taxonomy) {
                $menuUrl = admin_url('edit-tags.php?taxonomy=' . $taxonomy->name . '&post_type=' . FluentProducts::CPT_NAME);
                $submenu['fluent-cart'][$key] = [
                    $taxonomy->label,
                    $capability,
                    $menuUrl,
                    '',
                    $taxonomy->name
                ];
            }
        }

        do_action('fluent_cart/admin_submenu_added', $submenu);
    }

    public function renderAdmin()
    {
        $isCustomDashboardSlugChanged = (new StoreSettings())->getCustomerDashboardPageSlug() != (new StoreSettings())->get('customer_profile_page_slug');

        if ($isCustomDashboardSlugChanged) {
            $allSettings = (new StoreSettings())->get();
            (new StoreSettings())->save($allSettings); // we are just resaving to flash the settings cache
        }

        if (function_exists('wp_enqueue_editor')) {
            wp_enqueue_editor();
            wp_tinymce_inline_scripts();
            wp_enqueue_media();
        }

        // maybe database changes
        DBMigrator::maybeMigrateDBChanges();

        $this->enqueueAssets();

        $config = App::getInstance('config');

        $name = $config->get('app.name');

        $slug = $config->get('app.slug');

        $baseUrl = apply_filters('fluent_connector_base_url', admin_url('admin.php?page=newproject#/'));

        $app = App::getInstance();
        $assets = $app['url.assets'];

        App::make('view')->render('admin.admin_app', [
            'name'    => $name,
            'slug'    => $slug,
            'baseUrl' => $baseUrl,
            'logo'    => $assets . 'images/logo.svg',
        ]);
    }

    public function maybeRenderAdminMenu()
    {
        $request = App::request()->all();
        if (isset($request['post_type']) && $request['post_type'] == FluentProducts::CPT_NAME && is_admin()) {
            AdminHelper::getAdminMenu(true);
            AdminHelper::pushGlobalAdminAssets();
        }
    }

    public function renderAdminMenu()
    {
        AdminHelper::getAdminMenu(true);
    }

    public function pushProductNav($post)
    {
        if (!$post || $post->post_type != FluentProducts::CPT_NAME) {
            return;
        }

        AdminHelper::getProductMenu($post->ID, true, 'product_edit');
        AdminHelper::pushGlobalAdminAssets();
    }

    public function enqueueAssets()
    {
        $app = App::getInstance();

        $slug = $app->config->get('app.slug');

        do_action('fluent_cart/loading_app', $app);

        //This should be done before enqueueing the global script.
        Vite::enqueueScript($slug . '_admin_app_start', 'admin/bootstrap/app.js', [$slug . '_global_admin_hooks']);

        //Don't register this script using vite.
        wp_enqueue_script($slug . '_global_admin_hooks', Vite::getEnqueuePath('admin/admin_hooks.js'), [],FLUENTCART_VERSION,true);

        $manager = GatewayManager::getInstance();
        $payment_routes = $manager->getRoutes();
//        $payment_routes = apply_filters('fluent_cart/payments/payment_method_settings_routes', []);

        $storage_driver_routes = apply_filters('fluent_cart/storage/storage_driver_settings_routes', [], []);

        $settings = new StoreSettings();
        $checkoutUrl = add_query_arg(Helper::INSTANT_CHECKOUT_URL_PARAM, '=', $settings->getCheckoutPage());
        $restVars = Helper::getRestInfo();

        $filterOptions = [
            'order_filter_options'    => OrderFilter::getTableFilterOptions(),
            'customer_filter_options' => CustomerFilter::getTableFilterOptions(),
            'product_filter_options'  => ProductFilter::getTableFilterOptions(),
            'license_filter_options'  => LicenseFilter::getTableFilterOptions(),
            'tax_filter_options'      => TaxFilter::getTableFilterOptions(),
        ];
        $filterOptions = apply_filters('fluent_cart/admin_filter_options', $filterOptions, []);

        $currentUser = get_user_by('ID', get_current_user_id());

        // Get app config and merge additional properties into it
        $appConfig = $app->config->get('app');
        $appConfig['version'] = FLUENTCART_VERSION;
        $appConfig['permissions'] = PermissionManager::getUserPermissions();
        $appConfig['isProActive'] = App::isProActive();

        $appConfig['logos'] = [
            'dark'  => Vite::getAssetUrl('images/logo/logo-full-dark.svg'),
            'light' => Vite::getAssetUrl('images/logo/logo-full.svg'),
        ];
        $appConfig['isModuleTabEnabled'] = $settings->isModuleTabEnabled();

        $max_upload_size = wp_max_upload_size(); // Returns size in bytes
        $adminLocalizeData = apply_filters('fluent_cart/admin_app_data', [
            'app_config'                       => $appConfig,
            'slug'                             => $app->config->get('app.slug'),
            'admin_url'                        => admin_url('admin.php?page=fluent-cart#/'),
            'frontend_url'                     => URL::getFrontEndUrl(''),
            'nonce'                            => wp_create_nonce($slug),
            'rest'                             => $restVars,
            'brand_logo'                       => $this->getMenuIcon(),
            'asset_url'                        => Vite::getAssetUrl(),
            'me'                               => [
                'id'        => $currentUser->ID,
                'full_name' => trim($currentUser->display_name),
                'email'     => $currentUser->user_email,
                'avatar'    => get_avatar_url($currentUser->ID)
            ],
            'shop'                             => Helper::shopConfig(),
            'product_statuses'                 => Status::getProductStatuses(),
            'total_products_count'             => Product::query()->count(),
            'payment_statuses'                 => Status::getEditableTransactionStatuses(true),
            'payment_routes'                   => $payment_routes,
            'storage_driver_routes'            => $storage_driver_routes,
            'editable_payment_statuses'        => Status::getEditableTransactionStatuses(true),
            'order_statues'                    => Status::getOrderStatuses(),
            'editable_order_statues'           => Status::getEditableOrderStatuses(),
            'editable_customer_statues'        => Status::getEditableCustomerStatuses(),
            'shipping_statuses'                => Status::getShippingStatuses(),
            'allow_bulk_payment_status_change' => true,
            'variation_attributes'             => AttributeGroup::with(['terms'])->get(),
            'variation_terms'                  => AttributeTerm::query()->get()->keyBy('id'),
            'product_image_base_uri'           => Helper::getProductImageBaseUri(),
            'add_on_modules'                   => AddOnModule::showAddOns(),
            'stock_statuses'                   => Status::getStockStatuses(),
            'fulfillment_types'                => Helper::getFulfilmentTypes(),
            'variation_types'                  => Helper::getVariationTypes(),
            'trans'                            => TransStrings::getStrings(),
            'dashboard_url'                    => admin_url('admin.php?page=fluent-cart#'),
            'checkout_url'                     => $checkoutUrl,
            'dummy_product_info'               => apply_filters('fluent_cart/dummy_product_info', []) ?: [],
            'currency_signs'                   => CurrenciesHelper::getCurrencySigns(),
            'filter_options'                   => $filterOptions,
            'receipt_page_url'                 => $settings->getReceiptPage(),
            'payment_logos'                    => PaymentMethods::getIcons(),
            'is_admin_bar_showing'             => is_admin_bar_showing(),
            'max_upload_size'                  => size_format($max_upload_size),
            'available_shipping_method_count'  => ShippingMethod::query()->where('is_enabled', '1')->count(),
            'is_tax_enabled'                   => TaxModule::isTaxEnabled(),
            'eu_vat_county_options'            => TaxModule::euVatCountyOptions(),
            'country_tax_titles'               => TaxModule::taxTitleLists(),
            'site_url'                         => site_url(),
            'modules_settings'                 => ModuleSettings::getAllSettings(),
            'purchase_fluent_cart_link'        => 'https://fluentcart.com/',
            'admin_notices'                    => apply_filters('fluent_cart/admin_notices', []),
            'subscription_intervals'           => Helper::getAvailableSubscriptionIntervalOptions(),

            'datei18'    => TransStrings::dateTimeStrings(),
            'el_strings' => TransStrings::elStrings(),
            'wp_locale'  => get_locale()
        ]);

        wp_localize_script($slug . '_admin_app_start', 'fluentCartAdminApp', $adminLocalizeData);

        wp_localize_script($slug . '_admin_app_start', 'fluentCartRestVars', [
            'rest' => $restVars,
        ]);

        do_action('fluent_cart/admin_js_loaded', $app);
    }

    public function globalEnqueueAssets($adminBar)
    {
        $screen = get_current_screen();

        if ($screen && $screen->base !== 'user-edit') {
            return;
        }

        $app = App::getInstance();

        $slug = $app->config->get('app.slug');
        wp_enqueue_script($slug . '_edit_wp_user_admin_global_js', Vite::getEnqueuePath('admin/utils/edit-wp-user-global.js'), ['jquery'], FLUENTCART_VERSION, true);

        $baseUrl = apply_filters('fluent_cart/admin_base_url', admin_url('admin.php?page=fluent-cart#/'), []);

        $editingUserVars = null;

        if (function_exists('get_current_screen')) {
            $currentScreen = get_current_screen();
            if ($currentScreen && $currentScreen->id == 'user-edit') {
                $userId = (int)App::request()->get('user_id') ??0;
                $user = get_user_by('ID', $userId);

                if ($userId && $user) {
                    $fluentcartProfile = Customer::query()->where('email', $user->user_email)
                        ->orWhere('user_id', $user->ID)
                        ->first();
                    if ($fluentcartProfile) {
                        $fluentcartProfileUrl = $baseUrl . 'customers/' . $fluentcartProfile->id . '/view';
                        $editingUserVars = [
                            'user_id'         => $userId,
                            'fct_profile_id'  => $fluentcartProfile->id,
                            'fct_profile_url' => $fluentcartProfileUrl
                        ];
                    }
                }
            }
        }
        wp_localize_script('fluent-cart_edit_wp_user_admin_global_js', 'fluentcart_edit_user_global_bar_vars', [
            'customer_base'  => $baseUrl . 'customers/',
            'edit_user_vars' => $editingUserVars
        ]);
    }

    protected function getMenuIcon(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100" fill="none">
            <g clip-path="url(#clip0_394_8994)">
            <path d="M90 0C95.5229 0 100 4.47715 100 10V90C100 95.5229 95.5229 100 90 100H10C4.47715 100 0 95.5229 0 90V10C0 4.47715 4.47715 0 10 0H90ZM28.3525 52.5986C24.8927 52.5988 21.7641 54.6544 20.3906 57.8301L15.7119 68.6484H45.5205C52.4408 68.6484 58.6996 64.5355 61.4463 58.1836L63.8613 52.5986H28.3525ZM46.6748 31.333C39.7545 31.333 33.4959 35.4459 30.749 41.7979L28.333 47.3828H70.2617C73.7217 47.3828 76.8513 45.3264 78.2246 42.1504L82.9023 31.333H46.6748Z" fill="#9CA1A8"/>
            </g>
            <defs>
            <clipPath id="clip0_394_8994">
            <rect width="100" height="100" fill="white"/>
            </clipPath>
            </defs>
        </svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

}

