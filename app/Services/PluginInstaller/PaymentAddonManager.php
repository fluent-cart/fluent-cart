<?php

namespace FluentCart\App\Services\PluginInstaller;

class PaymentAddonManager
{
    public static function getAddonStatus($pluginSlug, $pluginFile)
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $is_installed = isset($all_plugins[$pluginFile]);
        $is_active = is_plugin_active($pluginFile);

        return [
            'is_installed' => $is_installed,
            'is_active'    => $is_active,
            'plugin_slug'  => $pluginSlug,
            'plugin_file'  => $pluginFile
        ];
    }


    public function installAddon($sourceType, $sourceLink, $pluginSlug)
    {
        if (!current_user_can('install_plugins')) {
            return new \WP_Error('permission_denied', __('You do not have permission to install plugins.', 'fluent-cart'));
        }

        $allowedAddons = apply_filters('fluent_cart/payment_addons', [
            'paystack-for-fluent-cart',
            'sslcommerz-for-fluent-cart'
        ]);

        if (!in_array($pluginSlug, $allowedAddons)) {
            return new \WP_Error('invalid_addon', __('Invalid payment addon selected.', 'fluent-cart'));
        }

        if ($sourceType === 'wordpress') {
            $result = (new BackgroundInstaller())->installPlugin($pluginSlug);
        } else if ($sourceType === 'github') {
            $result = $this->installFromGithub($sourceLink, $pluginSlug);
        } else {
            return new \WP_Error('invalid_source', __('Invalid addon source type.', 'fluent-cart'));
        }

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

 
    private function installFromGithub($githubUrl, $pluginSlug)
    {   
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        WP_Filesystem();

        $skin = new \Automatic_Upgrader_Skin();
        $upgrader = new \WP_Upgrader($skin);

        ob_start();

        $download = null;

        try {
            if (strpos($githubUrl, '/releases/latest') !== false) {
                $downloadUrl = $this->getLatestReleaseDownloadUrl($githubUrl);
                if (is_wp_error($downloadUrl)) {
                    throw new \Exception($downloadUrl->get_error_message());
                }
                $githubUrl = $downloadUrl;
            }

 
            $download = $upgrader->download_package($githubUrl);
            

            if (is_wp_error($download)) {
                throw new \Exception(wp_kses_post($download->get_error_message()));
            }

            $working_dir = $upgrader->unpack_package($download, true);

            if (is_wp_error($working_dir)) {
                throw new \Exception(wp_kses_post($working_dir->get_error_message()));
            }


            // $source_files = glob($working_dir . '/*');
            
            // if (count($source_files) === 1 && is_dir($source_files[0])) {
            //     // Single subdirectory found, use it as the actual source
            //     $working_dir = $source_files[0];
            // }

            $result = $upgrader->install_package(
                array(
                    'source'                      => $working_dir,
                    'destination'                 => WP_PLUGIN_DIR . '/' . $pluginSlug,
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

            ob_end_clean();

            wp_clean_plugins_cache();

            $plugin_file = $pluginSlug . '/' . $pluginSlug . '.php';
            $activate_result = activate_plugin($plugin_file);

            if (is_wp_error($activate_result)) {
                throw new \Exception(esc_html($activate_result->get_error_message()));
            };

            return true;

        } catch (\Exception $e) {
            ob_end_clean();
            
            return new \WP_Error('installation_failed', $e->getMessage());
        }
    }

    private function getLatestReleaseDownloadUrl($releasesUrl)
    {
        preg_match('#github\.com/([^/]+)/([^/]+)/releases#', $releasesUrl, $matches);
        
        if (empty($matches[1]) || empty($matches[2])) {
            return new \WP_Error('invalid_url', __('Invalid GitHub releases URL', 'fluent-cart'));
        }

        $owner = $matches[1];
        $repo = $matches[2];

        $api_url = "https://api.github.com/repos/{$owner}/{$repo}/releases/latest";
        
        $response = wp_remote_get($api_url, [
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'FluentCart/' . FLUENTCART_VERSION
            ]
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error('api_error', $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new \WP_Error('api_error', 'Github API error with status code: ' . $code);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);


        if (empty($data['zipball_url'])) {
            return new \WP_Error('no_release', 'No release found. Please ensure the repository has published releases.');
        }

        return $data['zipball_url'];
    }


    public function activateAddon($pluginFile)
    {
        if (!current_user_can('activate_plugins')) {
            return new \WP_Error('permission_denied', __('You do not have permission to activate plugins.', 'fluent-cart'));
        }

        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $result = activate_plugin($pluginFile);

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }
}

