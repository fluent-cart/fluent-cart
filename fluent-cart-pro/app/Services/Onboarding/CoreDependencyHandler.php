<?php

namespace FluentCartPro\App\Services\Onboarding;

use FluentCartPro\App\Utils\Enqueuer\Vite;
use FluentCartPro\App\Services\Translations\Translations;

class CoreDependencyHandler
{
    public function register()
    {
        // add a link to an admin menu which will redirect to /portal
        add_action('admin_menu', function () {
            add_menu_page(
                __('FluentCart', 'fluent-cart-pro'),
                __('FluentCart', 'fluent-cart-pro'),
                'edit_posts',
                'fluent-cart',
                [$this, 'showAdminPage'],
                $this->logo(),
                10
            );
        });

        add_action('wp_ajax_fluent_cart_pro_install_core_plugin', [$this, 'installCorePlugin']);
    }

    public function installCorePlugin()
    {
        if(!current_user_can('activate_plugins')) {
            wp_send_json(['message' => 'Sorry, you do not have permission to install plugin!'], 403);
        }

        //just temporary force to download from outside link
        $otherSource = 'https://wpcolorlab.s3.amazonaws.com/fluent-cart.zip';

        // verify nonce
        if (!wp_verify_nonce($_POST['_nonce'], 'fluent-cart-onboarding-nonce')) {
            wp_send_json(['message' => 'Invalid nonce'], 403);
        }

        if (defined('FLUENTCART_VERSION')) {
            wp_send_json(['message' => 'Already installed'], 200);
        }

        $result = true;

        $otherSource = '';

        if ($otherSource) {
            OutsideInstaller::backgroundInstallerDirect([
                'name'      => 'FluentCart',
                'repo-slug' => 'fluent-cart',
                'file'      => 'fluent-cart.php'
            ], 'fluent-cart', $otherSource);
        } else {
            $result = $this->installPlugin('fluent-cart');
        }

        if (is_wp_error($result)) {
            wp_send_json(['message' => $result->get_error_message()], 403);
        }

        wp_send_json_success(
            [
                'message'  => 'Successfully installed ',
                'redirect_url' => self_admin_url('admin.php?page=fluent-cart')
            ],
            200
        );
    }

    public function showAdminPage()
    {
        vite::enqueueScript('fluent-cart-pro-onboard', 'admin/onboarding/onboarding-app.js', ['jquery'], FLUENTCART_PRO_PLUGIN_VERSION, true);

        $text = __('Install Plugin', 'fluent-cart-pro');

        if (file_exists(WP_PLUGIN_DIR . '/fluent-cart/fluent-cart.php')) {
            $text = __('Activate Plugin', 'fluent-cart-pro');
        }

        wp_localize_script('fluent-cart-pro-onboard', 'fluentCartOnboardingAdmin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            '_nonce'   => wp_create_nonce('fluent-cart-onboarding-nonce'),
            'logo'     => $this->getMenuIcon(),
            'install_fluent_cart_text' => $text,
            'translations' => Translations::getTranslations()
        ]);

        Vite::enqueueStyle('fluent-cart-pro-onboard', 'admin/onboarding/onboarding-app.scss', [], FLUENTCART_PRO_PLUGIN_VERSION);
        echo '<div id="fluent_cart_onboarding_app"></div>';
    }

    public function logo()
    {
        return 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 100 100" fill="none">
            <path fill-rule="evenodd" clip-rule="evenodd"
                  d="M0 24.3243C0 10.8904 10.8904 0 24.3243 0H75.6757C89.1096 0 100 10.8904 100 24.3243V75.6757C100 89.1096 89.1096 100 75.6757 100H24.3243C10.8904 100 0 89.1096 0 75.6757V24.3243ZM45.5224 68.6479H15.7142L20.3921 57.8304C21.7655 54.6544 24.8948 52.5983 28.355 52.5983H63.8635L61.4481 58.1838C58.7013 64.5356 52.4427 68.6479 45.5224 68.6479ZM28.2879 47.4799H70.2163C73.6764 47.4799 76.8057 45.4237 78.1791 42.2478L82.857 31.4303H46.629C39.7087 31.4303 33.4501 35.5426 30.7033 41.8944L28.2879 47.4799Z"
                  fill="#00009F"/>
        </svg>');
    }


    private function getMenuIcon()
    {
        return 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" aria-labelledby="Fluent Cart Logo" width="100%" height="auto" viewBox="0 0 436 100" fill="none">
    <rect width="100" height="100" rx="24.3243" fill="#00009F"/>
    <path d="M45.5224 68.6477H15.7142L20.3921 57.8302C21.7655 54.6543 24.8948 52.5981 28.355 52.5981H63.8635L61.4481 58.1836C58.7013 64.5355 52.4427 68.6477 45.5224 68.6477Z" fill="white"/>
    <path d="M70.2163 47.4798H28.288L30.7033 41.8943C33.4501 35.5424 39.7087 31.4302 46.629 31.4302H82.8571L78.1792 42.2477C76.8058 45.4236 73.6765 47.4798 70.2163 47.4798Z" fill="white"/>
    <path fill="black" class="logo-dark-fill" d="M136.607 26.3793C137.514 26.2885 138.206 26.4132 138.683 26.7538C139.159 27.0943 139.397 27.6278 139.397 28.3543C139.397 29.7618 138.626 30.5338 137.083 30.67L135.11 30.8062C132.524 30.9877 130.596 31.771 129.325 33.1559C128.055 34.5406 127.307 36.618 127.08 39.3877L126.943 41.2947H134.361C135.087 41.2947 135.643 41.4877 136.029 41.8736C136.414 42.2595 136.607 42.7704 136.607 43.4061C136.607 44.0872 136.403 44.6318 135.994 45.0406C135.586 45.4493 134.996 45.6534 134.225 45.6534H126.603L124.493 72.1472C124.448 73.0098 124.153 73.6683 123.609 74.1225C123.065 74.5763 122.407 74.8035 121.635 74.8035C120.864 74.8035 120.218 74.5763 119.696 74.1225C119.174 73.6683 118.936 73.0098 118.981 72.1472L121.091 45.6534H116.531C115.806 45.6534 115.25 45.4605 114.864 45.0746C114.479 44.6886 114.286 44.178 114.286 43.5423C114.286 42.8612 114.49 42.3163 114.898 41.9076C115.306 41.4991 115.919 41.2947 116.736 41.2947H121.431L121.499 40.0688C121.817 35.6644 123.11 32.3613 125.378 30.1591C127.647 27.9569 130.936 26.7198 135.246 26.4472L136.607 26.3793Z"/>
    <path fill="black" d="M143.344 74.8034C142.572 74.8034 141.937 74.5762 141.438 74.1223C140.939 73.6682 140.712 72.9871 140.758 72.0789L144.228 28.6267C144.274 27.7638 144.591 27.117 145.181 26.6857C145.771 26.2541 146.452 26.0386 147.223 26.0386C147.994 26.0386 148.618 26.2541 149.094 26.6857C149.571 27.117 149.786 27.7638 149.741 28.6267L146.27 72.0789C146.225 72.9871 145.918 73.6682 145.351 74.1223C144.784 74.5762 144.115 74.8034 143.344 74.8034Z"/>
    <path fill="black" d="M182.883 40.6162C183.7 40.6162 184.346 40.8546 184.823 41.3313C185.299 41.808 185.492 42.4778 185.401 43.3405L183.087 72.286C183.042 73.0578 182.736 73.6709 182.168 74.125C181.601 74.5789 180.932 74.8061 180.161 74.8061C179.39 74.8061 178.777 74.5903 178.323 74.159C177.87 73.7277 177.666 73.126 177.711 72.3542L177.983 69.085C176.849 70.9466 175.329 72.3881 173.424 73.4097C171.518 74.4313 169.386 74.9422 167.027 74.9422C163.352 74.9422 160.562 73.9774 158.656 72.0476C156.751 70.118 155.798 67.2916 155.798 63.5683C155.798 62.751 155.821 62.138 155.866 61.7294L157.295 43.3405C157.386 42.4778 157.715 41.808 158.282 41.3313C158.849 40.8546 159.518 40.6162 160.29 40.6162C161.106 40.6162 161.753 40.8546 162.229 41.3313C162.705 41.808 162.898 42.4778 162.808 43.3405L161.378 61.3889C161.333 61.7066 161.31 62.1833 161.31 62.819C161.31 67.8136 163.692 70.311 168.456 70.311C171.405 70.311 173.764 69.4027 175.533 67.5867C177.303 65.7704 178.301 63.2506 178.528 60.0268L179.889 43.3405C179.979 42.4778 180.308 41.808 180.875 41.3313C181.443 40.8546 182.112 40.6162 182.883 40.6162Z" />
    <path fill="black" d="M197.377 58.7291V59.0017C197.377 62.634 198.273 65.449 200.065 67.4468C201.857 69.4446 204.454 70.4436 207.857 70.4436C211.441 70.4436 214.594 69.4446 217.316 67.4468C217.77 67.1291 218.223 66.9701 218.677 66.9701C219.222 66.9701 219.664 67.1745 220.004 67.5832C220.344 67.9917 220.514 68.4684 220.514 69.0133C220.514 69.8308 220.152 70.5798 219.426 71.2609C218.201 72.3507 216.488 73.2361 214.288 73.9172C212.087 74.5982 209.853 74.9388 207.585 74.9388C202.775 74.9388 198.953 73.4858 196.118 70.5798C193.282 67.674 191.864 63.7692 191.864 58.8653C191.864 55.233 192.534 52.0321 193.872 49.2622C195.21 46.4926 197.116 44.3358 199.588 42.7921C202.061 41.2484 204.93 40.4766 208.197 40.4766C212.643 40.4766 216.046 41.8045 218.405 44.4608C220.764 47.1168 221.944 50.9651 221.944 56.0049C221.944 56.8675 221.717 57.5374 221.263 58.0141C220.809 58.4908 220.197 58.7291 219.426 58.7291H197.377ZM208.401 44.7673C205.361 44.7673 202.934 45.6639 201.119 47.4574C199.305 49.251 198.148 51.8277 197.649 55.1877H216.976C217.112 51.7823 216.42 49.1942 214.9 47.4234C213.38 45.6525 211.214 44.7673 208.401 44.7673Z" />
    <path fill="black" d="M247.259 40.4766C250.979 40.4766 253.815 41.4528 255.766 43.4052C257.716 45.3576 258.692 48.1952 258.692 51.9185C258.692 52.7357 258.669 53.3488 258.624 53.7573L257.195 72.0781C257.104 72.9863 256.786 73.6674 256.242 74.1215C255.697 74.5754 255.04 74.8026 254.268 74.8026C253.406 74.8026 252.737 74.5754 252.261 74.1215C251.784 73.6674 251.592 72.9863 251.682 72.0781L253.111 54.0979C253.157 53.7801 253.18 53.3034 253.18 52.6678C253.18 47.6277 250.752 45.1078 245.898 45.1078C242.813 45.1078 240.363 46.0273 238.548 47.8661C236.733 49.7049 235.69 52.2362 235.418 55.46L234.057 72.0781C234.011 72.9863 233.716 73.6674 233.172 74.1215C232.628 74.5754 231.947 74.8026 231.13 74.8026C230.314 74.8026 229.667 74.5754 229.191 74.1215C228.715 73.6674 228.499 72.9863 228.544 72.0781L230.926 43.2008C230.972 42.3836 231.278 41.7479 231.845 41.2938C232.412 40.8397 233.081 40.6127 233.853 40.6127C234.624 40.6127 235.236 40.8397 235.69 41.2938C236.144 41.7479 236.348 42.3608 236.302 43.1326L236.03 46.4018C237.164 44.5401 238.707 43.0872 240.658 42.0431C242.609 40.9987 244.809 40.4766 247.259 40.4766Z" />
    <path fill="black" d="M275.364 62.4755C275.319 62.7934 275.296 63.2475 275.296 63.8376C275.296 66.0172 275.795 67.6745 276.793 68.8094C277.791 69.9446 279.198 70.5123 281.013 70.5123C281.602 70.5123 282.169 70.4781 282.714 70.4101C283.258 70.3419 283.667 70.3079 283.939 70.3079C284.393 70.3079 284.767 70.4895 285.062 70.8528C285.357 71.2159 285.504 71.7155 285.504 72.3511C285.504 73.3045 285.107 73.9744 284.313 74.3603C283.519 74.7463 282.124 74.9392 280.128 74.9392C276.861 74.9392 274.321 74.0084 272.506 72.1468C270.691 70.2851 269.784 67.6516 269.784 64.2463C269.784 63.6106 269.807 63.1111 269.852 62.748L271.145 45.653H266.654C265.928 45.653 265.372 45.46 264.986 45.0741C264.601 44.6882 264.408 44.1775 264.408 43.5418C264.408 42.8154 264.623 42.2591 265.054 41.8731C265.485 41.4872 266.087 41.2943 266.858 41.2943H271.485L272.098 33.53C272.188 32.7581 272.495 32.1339 273.016 31.6572C273.538 31.1802 274.207 30.9419 275.024 30.9419C275.886 30.9419 276.555 31.1916 277.032 31.6912C277.508 32.1907 277.701 32.8489 277.61 33.6662L276.998 41.2943H284.483C285.164 41.2943 285.708 41.4872 286.116 41.8731C286.525 42.2591 286.729 42.77 286.729 43.4057C286.729 44.1321 286.525 44.6882 286.116 45.0741C285.708 45.46 285.119 45.653 284.347 45.653H276.657L275.364 62.4755Z" />
    <path fill="black" d="M435.102 48.5526H426.597V65.0148C426.597 66.4324 426.917 67.4613 427.557 68.1015C428.197 68.696 429.135 69.0389 430.37 69.1304C431.65 69.1761 433.228 69.1533 435.102 69.0618V75.7153C429.432 76.4012 425.362 75.9211 422.893 74.2749C420.424 72.5829 419.189 69.4962 419.189 65.0148V48.5526H412.878V41.4189H419.189V34.0109L426.597 31.8159V41.4189H435.102V48.5526Z"/>
    <path fill="black" d="M399.057 47.1804C400.932 42.9276 404.453 40.8013 409.621 40.8013V48.8266C406.786 48.6437 404.316 49.3296 402.213 50.8844C400.109 52.3934 399.057 54.9085 399.057 58.4296V75.715H391.649V41.4186H399.057V47.1804Z"/>
    <path fill="black" d="M375.607 41.4181H383.015V75.7145H375.607V70.7758C372.817 74.6627 368.816 76.6062 363.603 76.6062C358.893 76.6062 354.869 74.8685 351.531 71.3931C348.193 67.872 346.523 63.5964 346.523 58.5663C346.523 53.4904 348.193 49.2148 351.531 45.7394C354.869 42.2641 358.893 40.5264 363.603 40.5264C368.816 40.5264 372.817 42.447 375.607 46.2882V41.4181ZM357.018 66.4544C359.076 68.5122 361.66 69.5411 364.769 69.5411C367.879 69.5411 370.462 68.5122 372.52 66.4544C374.578 64.3509 375.607 61.7215 375.607 58.5663C375.607 55.411 374.578 52.8045 372.52 50.7467C370.462 48.6432 367.879 47.5914 364.769 47.5914C361.66 47.5914 359.076 48.6432 357.018 50.7467C354.96 52.8045 353.931 55.411 353.931 58.5663C353.931 61.7215 354.96 64.3509 357.018 66.4544Z"/>
    <path fill="black" d="M321.791 76.6075C314.566 76.6075 308.576 74.2296 303.82 69.4738C299.064 64.6723 296.686 58.7505 296.686 51.7083C296.686 44.6661 299.064 38.7671 303.82 34.0113C308.576 29.2098 314.566 26.8091 321.791 26.8091C326.136 26.8091 330.137 27.838 333.795 29.8958C337.499 31.9535 340.38 34.743 342.438 38.2641L335.579 42.2425C334.298 39.8646 332.423 37.9897 329.954 36.6179C327.53 35.2003 324.809 34.4915 321.791 34.4915C316.67 34.4915 312.508 36.1148 309.307 39.3616C306.152 42.6083 304.575 46.7239 304.575 51.7083C304.575 56.6927 306.152 60.8082 309.307 64.055C312.508 67.3017 316.67 68.9251 321.791 68.9251C324.809 68.9251 327.553 68.2391 330.023 66.8673C332.492 65.4497 334.344 63.552 335.579 61.1741L342.438 65.0839C340.426 68.605 337.568 71.4173 333.864 73.5208C330.205 75.5786 326.181 76.6075 321.791 76.6075Z"/>
</svg>');

    }





    private function installPlugin($pluginSlug)
    {
        $plugin = [
            'name'      => $pluginSlug,
            'repo-slug' => $pluginSlug,
            'file'      => $pluginSlug . '.php',
        ];

        $UrlMaps = [
            'fluent-cart' => [
                'admin_url' => admin_url('admin.php?page=fluent-cart'),
                'title'     => __('Go to FluentCart Dashboard', 'fluent-cart-pro'),
            ],
        ];
        if (!isset($UrlMaps[$pluginSlug]) || (defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS)) {
            return new \WP_Error('invalid_plugin', __('Invalid plugin or file mods are disabled.', 'fluent-cart-pro'));
        }

        try {
            $this->backgroundInstaller($plugin);
        } catch (\Exception $exception) {
            return new \WP_Error('plugin_install_error', $exception->getMessage());
        }
    }

    private function backgroundInstaller($plugin_to_install)
    {
        if (!empty($plugin_to_install['repo-slug'])) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/plugin.php';

            WP_Filesystem();

            $skin = new \Automatic_Upgrader_Skin();
            $upgrader = new \WP_Upgrader($skin);
            $installed_plugins = array_keys(\get_plugins());
            $plugin_slug = $plugin_to_install['repo-slug'];
            $plugin_file = isset($plugin_to_install['file']) ? $plugin_to_install['file'] : $plugin_slug . '.php';
            $installed = false;
            $activate = false;
            // See if the plugin is installed already.
            if (isset($installed_plugins[$plugin_file])) {
                $installed = true;
                $activate = !is_plugin_active($installed_plugins[$plugin_file]);
            }

            // Install this thing!
            if (!$installed) {
                // Suppress feedback.
                ob_start();

                try {
                    $plugin_information = plugins_api(
                        'plugin_information',
                        array(
                            'slug'   => $plugin_slug,
                            'fields' => array(
                                'short_description' => false,
                                'sections'          => false,
                                'requires'          => false,
                                'rating'            => false,
                                'ratings'           => false,
                                'downloaded'        => false,
                                'last_updated'      => false,
                                'added'             => false,
                                'tags'              => false,
                                'homepage'          => false,
                                'donate_link'       => false,
                                'author_profile'    => false,
                                'author'            => false,
                            ),
                        )
                    );

                    if (is_wp_error($plugin_information)) {
                        throw new \Exception(wp_kses_post($plugin_information->get_error_message()));
                    }

                    $package = $plugin_information->download_link;
                    $download = $upgrader->download_package($package);

                    if (is_wp_error($download)) {
                        throw new \Exception(wp_kses_post($download->get_error_message()));
                    }

                    $working_dir = $upgrader->unpack_package($download, true);

                    if (is_wp_error($working_dir)) {
                        throw new \Exception(wp_kses_post($working_dir->get_error_message()));
                    }

                    $result = $upgrader->install_package(
                        array(
                            'source'                      => $working_dir,
                            'destination'                 => WP_PLUGIN_DIR,
                            'clear_destination'           => false,
                            'abort_if_destination_exists' => false,
                            'clear_working'               => true,
                            'hook_extra'                  => array(
                                'type'   => 'plugin',
                                'action' => 'install',
                            ),
                        )
                    );

                    if (is_wp_error($result)) {
                        throw new \Exception(wp_kses_post($result->get_error_message()));
                    }

                    $activate = true;

                } catch (\Exception $e) {
                    throw new \Exception(esc_html($e->getMessage()));
                }

                // Discard feedback.
                ob_end_clean();
            }

            wp_clean_plugins_cache();

            // Activate this thing.
            if ($activate) {
                try {
                    $result = activate_plugin($installed ? $installed_plugins[$plugin_file] : $plugin_slug . '/' . $plugin_file);

                    if (is_wp_error($result)) {
                        throw new \Exception(esc_html($result->get_error_message()));
                    }
                } catch (\Exception $e) {
                    throw new \Exception(esc_html($e->getMessage()));
                }
            }
        }
    }
}
