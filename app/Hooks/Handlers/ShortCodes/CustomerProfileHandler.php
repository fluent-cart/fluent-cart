<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes;

use FluentCart\Api\ModuleSettings;
use FluentCart\Api\PaymentMethods;
use FluentCart\Api\Resource\CustomerResource;
use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\TemplateService;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;

class CustomerProfileHandler extends ShortCode
{
    const SHORT_CODE = 'fluent_cart_customer_profile';
    protected static string $shortCodeName = 'fluent_cart_customer_profile';

    protected static $slug = '';
    protected string $assetsPath = '';

    public function renderShortcode($block = null)
    {
        ob_start(null);
        $view = $this->render(
            $this->viewData()
        );
        return $view ?? ob_get_clean();
    }

    public static function register()
    {
        parent::register();

        // Add wildcard customer profile pages
        // add a custom permalink endpoint
        add_action('init', function () {
            $pageSlug = (new StoreSettings())->getCustomerDashboardPageSlug();
            $customerProfilePageId = (new StoreSettings())->getCustomerProfilePageId();
            static::$slug = $pageSlug;

            if($customerProfilePageId && $pageSlug) {
                 add_rewrite_rule(
                    '^'.$pageSlug.'/(.+)?$',
                    'index.php?page_id='.$customerProfilePageId,
                    'top'
                );
            }
        });
    }


    public function render(?array $viewData = null)
    {
        if (!is_user_logged_in()) {
             ob_start();
            $redirectUrl = (new StoreSettings())->getCustomerProfilePage();
            if (defined('FLUENT_AUTH_VERSION') && (new \FluentAuth\App\Hooks\Handlers\CustomAuthHandler())->isEnabled()) {
                ?>
                <div style="max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #CBD5E0; border-radius: 8px;" class="fct_auth_wrap">
                <h4><?php echo esc_html__('Please log in to access your customer portal.', 'fluent-cart'); ?></h4>
                <?php
                echo do_shortcode('[fluent_auth redirect_to="' . $redirectUrl . '"]');
                echo '</div>';
            } else {
                ?>
                <div class="fct_auth_wrap">
                    <div class="fct_auth_message">
                        <h2><?php echo esc_html__('Login', 'fluent-cart'); ?></h2>
                        <p><?php echo esc_html__('Please log in to access your customer portal.', 'fluent-cart'); ?></p>
                        <a href="<?php echo esc_url(wp_login_url($redirectUrl ?? '')); ?>" class="button">
                            <?php echo esc_html__('Login', 'fluent-cart'); ?>
                        </a>
                    </div>
                </div>
                <?php
            }
            return ob_get_clean();
        }

        $this->renderCustomerAppContainer();
    }

    public function renderCustomerAppContainer()
    {

        // Enqueue global styles
         Vite::enqueueStyle( 'fluent-cart-customer-profile-global',
            'public/customer-profile/style/customer-profile-global.scss',
        );

        $customEndpointContent = $this->maybeCustomEndpointContent();

        if(!$customEndpointContent) {
            (new static())->enqueueStyles();
        }

        $colors = self::generateCssColorVariables(Arr::get($this->shortCodeAttributes, 'colors', ''));
        add_action('fluent_cart/customer_menu', array($this, 'renderCustomerMenu'));
        add_action('fluent_cart/customer_app', function () use ($customEndpointContent) {
            if($customEndpointContent) {
                echo $customEndpointContent; // @phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } else {
                AssetLoader::loadCustomerDashboardAssets();
                $this->renderCustomerApp();
            }
        });

        App::make('view')->render('frontend.customer_app', [
            'wp_page_title' => get_the_title(),
            'colors'        => $colors,
            'active_tab' => apply_filters('fluent_cart/customer_portal/active_tab', ''),
        ]);
    }

    public function maybeCustomEndpointContent() {
        global $wp;
        $requestedPath = $wp->request;
        // remove static::$slug from the requested path
        if (static::$slug && Str::startsWith($requestedPath, static::$slug)) {
            $requestedPath = Str::replaceFirst(static::$slug, '', $requestedPath);
            $requestedPath = trim($requestedPath, '/');
        }

        if($requestedPath) {
            $paths = explode('/', $requestedPath);
            $requestedPath = array_shift($paths);
        }

        $reserved = ['dashboard', 'purchase-history', 'subscriptions', 'licenses', 'downloads', 'profile'];
        if(!$requestedPath || in_array($requestedPath, $reserved)) {
            return ''; // No specific path requested, return early
        }

        // Maybe it's a custom endpoint path
        $customEndpoints = apply_filters('fluent_cart/customer_portal/custom_endpoints', []);

        if(empty($customEndpoints) || !isset($customEndpoints[$requestedPath])) {
            return ''; // No custom endpoints defined, return early
        }

        ob_start();
        $endpoint = $customEndpoints[$requestedPath];

        add_filter('fluent_cart/customer_portal/active_tab', function ($activeTab) use ($requestedPath) {
            return $requestedPath;
        });

        if (isset($endpoint['render_callback']) && is_callable($endpoint['render_callback'])) {
            call_user_func($endpoint['render_callback']);
            return ob_get_clean();
        }

        if(isset($endpoint['page_id'])) {
            $pageId = (int) $endpoint['page_id'];
            // Create a custom query to fetch the two pages
            $args = array(
                'post_type' => 'page',
                'post__in' => [$pageId],
                'posts_per_page' => 1,
                'orderby' => 'post__in', // Preserve the order of the IDs
            );

            $page_query = new \WP_Query($args);

            // Check if the query has posts
            if ($page_query->have_posts()) :
                while ($page_query->have_posts()) : $page_query->the_post();
                    ?>
                    <div class="fluent-cart-custom-page-content">
                        <div><?php the_content(); ?></div>
                    </div>
                    <?php
                endwhile;
                // Reset post data to avoid conflicts with other queries
                wp_reset_postdata();
            else :
                echo '<p>' . esc_html__('No content found!', 'fluent-cart') . '</p>';
            endif;
        }

        return ob_get_clean();
    }

    public function renderCustomerApp()
    {
        echo '<div data-fluent-cart-customer-profile-app><app/></div>';
    }

    public function renderCustomerMenu()
    {
        $baseUrl = TemplateService::getCustomerProfileUrl('/');

        $menuItems = [
            'dashboard'        => [
                'label' => __('Dashboard', 'fluent-cart'),
                'css_class' => 'fct_route',
                'link'  => $baseUrl
            ],
            'purchase-history' => [
                'label' => __('Purchase History', 'fluent-cart'),
                'css_class' => 'fct_route',
                'link'  => $baseUrl . 'purchase-history'
            ],
            'subscriptions'    => [
                'label' => __('Subscription Plans', 'fluent-cart'),
                'css_class' => 'fct_route',
                'link'  => $baseUrl . 'subscriptions'
            ],
            'licenses'         => [
                'label' => __('Licenses', 'fluent-cart'),
                'css_class' => 'fct_route',
                'link'  => $baseUrl . 'licenses'
            ],
            'downloads'        => [
                'label' => __('Downloads', 'fluent-cart'),
                'css_class' => 'fct_route',
                'link'  => $baseUrl . 'downloads'
            ],
            'profile'          => [
                'label' => __('Profile', 'fluent-cart'),
                'css_class' => 'fct_route',
                'link'  => $baseUrl . 'profile'
            ],
            'logout'           => [
                'label'     => __('Logout', 'fluent-cart'),
                'css_class' => 'fct_logout',
                'link'      => wp_logout_url($baseUrl)
            ]
        ];

        $currentCustomer = CustomerResource::getCurrentCustomer();

        if (!ModuleSettings::isActive('license') || !App::isProActive()) {
            unset($menuItems['licenses']);
        }


        if($currentCustomer) {
            $hasSubscriptions = Subscription::query()->where('customer_id', $currentCustomer->id)->exists();
            if(!$hasSubscriptions) {
                unset($menuItems['subscriptions']);
            }
        } else {
            unset($menuItems['subscriptions']);
        }

        $menuItems = apply_filters('fluent_cart/global_customer_menu_items', $menuItems, [
            'base_url' => $baseUrl
        ]);

        $is_admin_bar_showing = is_admin_bar_showing();
        if ($is_admin_bar_showing) {
            unset($menuItems['logout']);
        }


        $profileData = null;
        if($currentCustomer) {
            $profileData = [
                'email'      => $currentCustomer->email,
                'full_name' => $currentCustomer->full_name,
                'photo'      => $currentCustomer->photo
            ];
        } else if(is_user_logged_in()) {
            $user = wp_get_current_user();
            $profileData = [
                'email'      => $user->user_email,
                'full_name' => $user->display_name,
                'photo'      => get_avatar_url($user->ID)
            ];
        }

        App::make('view')->render('frontend.customer_menu', [
            'menuItems' => $menuItems,
            'profileData' => $profileData,
        ]);
    }

    public static function getLocalizationData($attributes = []):array
    {

        $currentCustomer = CustomerResource::getCurrentCustomer();
        $customerEmail = $currentCustomer ? $currentCustomer->email : '';


        $pageUrl = TemplateService::getCustomerProfileUrl();

        $pageSlug = trim(str_replace(home_url('/'), '', $pageUrl), '/');
        $dashboardSlug = (new StoreSettings())->getCustomerDashboardPageSlug();

        if($pageSlug !== $dashboardSlug) {
            // we should resave the page slug from settings
            $prevSettings = (new StoreSettings())->get();
            (new StoreSettings())->save($prevSettings);
        }

        $shopLocalizationData = Helper::shopConfig();
        $shopLocalizationData['shop_url'] = (new StoreSettings())->getShopPage();


        // For supporting subdirectory installations
        $domainParts = parse_url(home_url('/'));
        // if we have path in domain url, we need to set it as base path
        $basePath = trim(Arr::get($domainParts, 'path', ''), '/');
        if($basePath && $basePath !== '/') {
            $pageSlug = $basePath.'/'.$pageSlug;
        }

        return [
            'fluentcart_customer_profile_vars' => [
                'app_slug' => $pageSlug,
                'app_url' => TemplateService::getCustomerProfileUrl(),
                'shop'              => $shopLocalizationData,
                'trans'             => TransStrings::getCustomerProfileString(),
                'download_url_base' => site_url('fluent-cart/download-file/?fluent_cart_download=true'),
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg'),
                'stripe_pub_key'    => apply_filters('fluent_cart/payment_methods/stripe_pub_key', ''),
                'paypal_client_id'  => apply_filters('fluent_cart/payment_methods/paypal_client_id', '', []),
                'assets_path'       => Vite::getAssetUrl(),
                'rest'              => Helper::getRestInfo(),
                'customer_email'    => $customerEmail,
                'wp_page_title'     => get_the_title(),
                'payment_methods'   => PaymentMethods::getActiveMeta(),
                'site_url'          => site_url(),
                'me' => [
                    'email'      => $currentCustomer ? $currentCustomer->email : '',
                    'first_name' =>  $currentCustomer ? $currentCustomer->first_name : '',
                    'last_name'  =>  $currentCustomer ? $currentCustomer->last_name : '',
                    'photo'        =>  $currentCustomer ? $currentCustomer->photo : ''

                ],
                'logout_url' => wp_logout_url(home_url()),
                'datei18'    => TransStrings::dateTimeStrings(),
                'el_strings' => TransStrings::elStrings(),
                'wp_locale'  => get_locale()
            ],
            'fluentCartRestVars'               => [
                'rest' => Helper::getRestInfo(),
            ],
        ];
    }

    protected function localizeData(): array
    {
        return static::getLocalizationData($this->shortCodeAttributes);
    }

    private static function generateCssColorVariables($colors): string
    {
        $cssVariables = '';

        if (!empty($colors)) {
            // Split the colors string by commas to separate each key-value pair
            $pairs = explode(',', $colors);
            $colorVariables = [];

            foreach ($pairs as $pair) {
                list($key, $value) = explode('=', trim($pair));

                // Only add to the array if the value is not empty or just a semicolon
                if (!empty($value) && $value !== ';') {
                    $colorVariables[] = "$key: $value;";
                }
            }

            $cssVariables = implode("\n", $colorVariables);
        }

        return $cssVariables;
    }

}
