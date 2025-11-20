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
                'link'  => $baseUrl,
                'icon_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
                      <path d="M5.875 9.625C5.43179 9.625 4.99292 9.5377 4.58344 9.36809C4.17397 9.19848 3.80191 8.94988 3.48851 8.63649C3.17512 8.32309 2.92652 7.95103 2.75691 7.54156C2.5873 7.13208 2.5 6.69321 2.5 6.25C2.5 5.80679 2.5873 5.36792 2.75691 4.95844C2.92652 4.54897 3.17512 4.17691 3.48851 3.86351C3.80191 3.55012 4.17397 3.30152 4.58344 3.13191C4.99292 2.9623 5.43179 2.875 5.875 2.875C6.77011 2.875 7.62855 3.23058 8.26148 3.86351C8.89442 4.49645 9.25 5.35489 9.25 6.25C9.25 7.14511 8.89442 8.00355 8.26148 8.63649C7.62855 9.26942 6.77011 9.625 5.875 9.625V9.625ZM6.25 17.125C5.35489 17.125 4.49645 16.7694 3.86351 16.1365C3.23058 15.5035 2.875 14.6451 2.875 13.75C2.875 12.8549 3.23058 11.9965 3.86351 11.3635C4.49645 10.7306 5.35489 10.375 6.25 10.375C7.14511 10.375 8.00355 10.7306 8.63648 11.3635C9.26942 11.9965 9.625 12.8549 9.625 13.75C9.625 14.6451 9.26942 15.5035 8.63648 16.1365C8.00355 16.7694 7.14511 17.125 6.25 17.125V17.125ZM13.75 9.625C13.3068 9.625 12.8679 9.5377 12.4584 9.36809C12.049 9.19848 11.6769 8.94988 11.3635 8.63649C11.0501 8.32309 10.8015 7.95103 10.6319 7.54156C10.4623 7.13208 10.375 6.69321 10.375 6.25C10.375 5.80679 10.4623 5.36792 10.6319 4.95844C10.8015 4.54897 11.0501 4.17691 11.3635 3.86351C11.6769 3.55012 12.049 3.30152 12.4584 3.13191C12.8679 2.9623 13.3068 2.875 13.75 2.875C14.6451 2.875 15.5035 3.23058 16.1365 3.86351C16.7694 4.49645 17.125 5.35489 17.125 6.25C17.125 7.14511 16.7694 8.00355 16.1365 8.63649C15.5035 9.26942 14.6451 9.625 13.75 9.625V9.625ZM13.75 17.125C12.8549 17.125 11.9964 16.7694 11.3635 16.1365C10.7306 15.5035 10.375 14.6451 10.375 13.75C10.375 12.8549 10.7306 11.9965 11.3635 11.3635C11.9964 10.7306 12.8549 10.375 13.75 10.375C14.6451 10.375 15.5035 10.7306 16.1365 11.3635C16.7694 11.9965 17.125 12.8549 17.125 13.75C17.125 14.6451 16.7694 15.5035 16.1365 16.1365C15.5035 16.7694 14.6451 17.125 13.75 17.125ZM5.875 8.125C6.37228 8.125 6.84919 7.92746 7.20082 7.57583C7.55246 7.22419 7.75 6.74728 7.75 6.25C7.75 5.75272 7.55246 5.27581 7.20082 4.92417C6.84919 4.57254 6.37228 4.375 5.875 4.375C5.37772 4.375 4.90081 4.57254 4.54917 4.92417C4.19754 5.27581 4 5.75272 4 6.25C4 6.74728 4.19754 7.22419 4.54917 7.57583C4.90081 7.92746 5.37772 8.125 5.875 8.125V8.125ZM6.25 15.625C6.74728 15.625 7.22419 15.4275 7.57582 15.0758C7.92746 14.7242 8.125 14.2473 8.125 13.75C8.125 13.2527 7.92746 12.7758 7.57582 12.4242C7.22419 12.0725 6.74728 11.875 6.25 11.875C5.75272 11.875 5.27581 12.0725 4.92417 12.4242C4.57254 12.7758 4.375 13.2527 4.375 13.75C4.375 14.2473 4.57254 14.7242 4.92417 15.0758C5.27581 15.4275 5.75272 15.625 6.25 15.625ZM13.75 8.125C14.2473 8.125 14.7242 7.92746 15.0758 7.57583C15.4275 7.22419 15.625 6.74728 15.625 6.25C15.625 5.75272 15.4275 5.27581 15.0758 4.92417C14.7242 4.57254 14.2473 4.375 13.75 4.375C13.2527 4.375 12.7758 4.57254 12.4242 4.92417C12.0725 5.27581 11.875 5.75272 11.875 6.25C11.875 6.74728 12.0725 7.22419 12.4242 7.57583C12.7758 7.92746 13.2527 8.125 13.75 8.125ZM13.75 15.625C14.2473 15.625 14.7242 15.4275 15.0758 15.0758C15.4275 14.7242 15.625 14.2473 15.625 13.75C15.625 13.2527 15.4275 12.7758 15.0758 12.4242C14.7242 12.0725 14.2473 11.875 13.75 11.875C13.2527 11.875 12.7758 12.0725 12.4242 12.4242C12.0725 12.7758 11.875 13.2527 11.875 13.75C11.875 14.2473 12.0725 14.7242 12.4242 15.0758C12.7758 15.4275 13.2527 15.625 13.75 15.625Z" fill="currentColor"/>
                </svg>',
            ],
            'purchase-history' => [
                'label' => __('Purchase History', 'fluent-cart'),
                'css_class' => 'fct_route',
                'link'  => $baseUrl . 'purchase-history',
                'icon_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
                  <path d="M10 2.5C14.1422 2.5 17.5 5.85775 17.5 10C17.5 14.1422 14.1422 17.5 10 17.5C5.85775 17.5 2.5 14.1422 2.5 10H4C4 13.3135 6.6865 16 10 16C13.3135 16 16 13.3135 16 10C16 6.6865 13.3135 4 10 4C7.9375 4 6.118 5.04025 5.03875 6.625H7V8.125H2.5V3.625H4V5.5C5.368 3.6775 7.54675 2.5 10 2.5ZM10.75 6.25V9.68875L13.1823 12.121L12.121 13.1823L9.25 10.3098V6.25H10.75Z" fill="currentColor"/>
                </svg>'
            ],
            'subscriptions'    => [
                'label' => __('Subscription Plans', 'fluent-cart'),
                'css_class' => 'fct_route',
                'link'  => $baseUrl . 'subscriptions',
                'icon_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
                  <path d="M10 5C11.7179 5 13.2343 5.86641 14.1348 7.1875H12.5V8.4375H16.25V4.6875H15V6.2496C13.8601 4.73229 12.0452 3.75 10 3.75C6.54822 3.75 3.75 6.54822 3.75 10H5C5 7.23857 7.23857 5 10 5ZM15 10C15 12.7614 12.7614 15 10 15C8.28215 15 6.76567 14.1336 5.86527 12.8125H7.5V11.5625H3.75V15.3125H5V13.7504C6.13988 15.2677 7.95477 16.25 10 16.25C13.4517 16.25 16.25 13.4517 16.25 10H15Z" fill="currentColor"/>
                </svg>'
            ],
            'licenses'         => [
                'label' => __('Licenses', 'fluent-cart'),
                'css_class' => 'fct_route',
                'link'  => $baseUrl . 'licenses',
                'icon_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
                  <path d="M16 17.5H4C3.80109 17.5 3.61032 17.421 3.46967 17.2803C3.32902 17.1397 3.25 16.9489 3.25 16.75V3.25C3.25 3.05109 3.32902 2.86032 3.46967 2.71967C3.61032 2.57902 3.80109 2.5 4 2.5H16C16.1989 2.5 16.3897 2.57902 16.5303 2.71967C16.671 2.86032 16.75 3.05109 16.75 3.25V16.75C16.75 16.9489 16.671 17.1397 16.5303 17.2803C16.3897 17.421 16.1989 17.5 16 17.5ZM15.25 16V4H4.75V16H15.25ZM7 6.25H13V7.75H7V6.25ZM7 9.25H13V10.75H7V9.25ZM7 12.25H10.75V13.75H7V12.25Z" fill="currentColor"/>
                </svg>'
            ],
            'downloads'        => [
                'label' => __('Downloads', 'fluent-cart'),
                'css_class' => 'fct_route',
                'link'  => $baseUrl . 'downloads',
                'icon_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
                  <path d="M10.75 8.5H14.5L10 13L5.5 8.5H9.25V3.25H10.75V8.5ZM4 15.25H16V10H17.5V16C17.5 16.1989 17.421 16.3897 17.2803 16.5303C17.1397 16.671 16.9489 16.75 16.75 16.75H3.25C3.05109 16.75 2.86032 16.671 2.71967 16.5303C2.57902 16.3897 2.5 16.1989 2.5 16V10H4V15.25Z" fill="currentColor"/>
                </svg>'
            ],
            'profile'          => [
                'label' => __('Profile', 'fluent-cart'),
                'css_class' => 'fct_route',
                'link'  => $baseUrl . 'profile',
                'icon_svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20" fill="none">
                  <path d="M4 17.5C4 15.9087 4.63214 14.3826 5.75736 13.2574C6.88258 12.1321 8.4087 11.5 10 11.5C11.5913 11.5 13.1174 12.1321 14.2426 13.2574C15.3679 14.3826 16 15.9087 16 17.5H14.5C14.5 16.3065 14.0259 15.1619 13.182 14.318C12.3381 13.4741 11.1935 13 10 13C8.80653 13 7.66193 13.4741 6.81802 14.318C5.97411 15.1619 5.5 16.3065 5.5 17.5H4ZM10 10.75C7.51375 10.75 5.5 8.73625 5.5 6.25C5.5 3.76375 7.51375 1.75 10 1.75C12.4863 1.75 14.5 3.76375 14.5 6.25C14.5 8.73625 12.4863 10.75 10 10.75ZM10 9.25C11.6575 9.25 13 7.9075 13 6.25C13 4.5925 11.6575 3.25 10 3.25C8.3425 3.25 7 4.5925 7 6.25C7 7.9075 8.3425 9.25 10 9.25Z" fill="currentColor"/>
                </svg>'
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

        add_filter('fct_allowed_svg_tags', function ($tags) {
            return [
                'svg' => [
                    'xmlns'     => true,
                    'width'     => true,
                    'height'    => true,
                    'viewBox'   => true,
                    'fill'      => true,
                    'stroke'    => true,
                    'stroke-width' => true,
                    'class'     => true
                ],
                'path' => [
                    'd'         => true,
                    'fill'      => true,
                    'stroke'    => true,
                    'stroke-width' => true
                ],
                'g' => [
                    'fill'      => true,
                    'stroke'    => true
                ]
            ];
        });


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
