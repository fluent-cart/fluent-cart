<?php

namespace FluentCartPro\App\Services\Onboarding;

class OutsideInstaller
{

    public function installFromOutside($addonSlug, $title, $url)
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();
        $plugin_slug = $addonSlug;
        // Check if the plugin is present
        foreach ($plugins as $plugin_file => $plugin_data) {
            // Check if the plugin slug or name matches
            if ($plugin_slug === $plugin_data['TextDomain'] || $plugin_slug === $plugin_data['Name']) {
                if (!function_exists('activate_plugin')) {
                    require_once(ABSPATH . 'wp-admin/includes/plugin.php');
                }
                // Activate the plugin
                $plugin_activation = activate_plugin($plugin_file);
                if (is_wp_error($plugin_activation)) {
                    // Handle the error
                    $error_message = $plugin_activation->get_error_message();
                    wp_send_json_error($error_message, 423);
                    // ...
                }
                wp_send_json_success(
                    [
                        'message'  => sprintf(
                            __('Successfully enabled', 'fluent-cart-pro'),
                            $title
                        ),
                        'redirect_url' => self_admin_url('admin.php?page=fluent-cart#/beta')
                    ],
                    200
                );
            }
        }
        // If the loop completes without finding the plugin, it is not present

        $this->proccedToInstall($addonSlug, $title, $url);
    }

    public function proccedToInstall($addonSlug, $title, $url)
    {
        $plugin_url = $url;
        if ('' == $plugin_url) {
            wp_send_json_error(['message' => __('No valid url provided to install!', 'fluent-cart-pro')], 423);
        }
        $response = wp_remote_get($plugin_url, array(
            'timeout' => 30 // Increase to 30 seconds (adjust as needed)
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(
                [
                    'message' => sprintf(
                        __('Error downloading plugin', 'fluent-cart-pro'),
                        $response->get_error_message()
                    )
                ],
                423
            );
        }
        // Get the plugin contents from the response
        $plugin_contents = wp_remote_retrieve_body($response);
        // Save the plugin ZIP file to a temporary location
        $temp_file = tempnam(sys_get_temp_dir(),  'plugin');
        if (!$temp_file) {
            // Handle the error
            wp_send_json_error(
                [
                    'message' => 'Error creating temporary.'
                ],
                423
            );
        }
        file_put_contents($temp_file, $plugin_contents);
        // now extract, rename and activate plugin
        static::renameAndActivatePlugin($addonSlug, $temp_file, $title);
    }

    public static function renameAndActivatePlugin($slug, $tempFile, $title)
    {
        // Extract the plugin ZIP file
        $zip = new \ZipArchive();
        $extracted_path = WP_CONTENT_DIR . '/plugins/' . $slug . '/';

        if ($zip->open($tempFile) === true) {
            $zip->extractTo($extracted_path);
            // get folder name
            $first_index = 0; // Assuming the first index contains the folder
            $extracted_file_name = $zip->getNameIndex($first_index);
            $extracted_folder_name = basename($extracted_file_name);
            $zip->close();
            // rename to actuall addonSlug
            $new_folder_path = $extracted_path . $slug;

            rename($extracted_path . $extracted_folder_name, $new_folder_path);
            // flushing the wp_cache to recognize the newly added plugin
            wp_cache_flush();
        } else {
            // Handle the error
            wp_send_json_error([
                'message' => 'Error extracting plugin ZIP file'
            ], 423);
            return;
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        // safe activation
        $plugins = get_plugins();
        $plugin_slug = $slug;
        // Check if the plugin is present
        foreach ($plugins as $plugin_file => $plugin_data) {
            // Check if the plugin slug or name matches
            if ($plugin_slug === $plugin_data['TextDomain'] || $plugin_slug === $plugin_data['Name']) {
                if (!function_exists('activate_plugin')) {
                    require_once(ABSPATH . 'wp-admin/includes/plugin.php');
                }
                // Activate the plugin
                $plugin_activation = activate_plugin($plugin_file);
                if (is_wp_error($plugin_activation)) {
                    // Handle the error
                    $error_message = $plugin_activation->get_error_message();
                    wp_send_json_error($error_message, 423);
                    // ...
                }
                wp_send_json_success(
                    [
                        'message'  => 'Successfully installed ' . $title,
                        'redirect_url' => self_admin_url('admin.php?page=fluent-cart#/beta')
                    ],
                    200
                );
            }
        }

        // Plugin activation failed
        wp_send_json_error(
            [
                'message' => 'Error activating plugin: Plugin not found.'
            ],
            423
        );
    }


    /*
     * Install Plugins with direct download link ( which doesn't have wordpress.org repo )
     */
    public static function backgroundInstallerDirect($plugin_to_install, $plugin_id, $downloadUrl)
    {
        if (!empty($plugin_to_install['repo-slug'])) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            require_once ABSPATH . 'wp-admin/includes/plugin.php';

            \WP_Filesystem();

            $skin = new \Automatic_Upgrader_Skin();
            $upgrader = new \WP_Upgrader($skin);
            $installed_plugins = array_reduce(array_keys(\get_plugins()), array(static::class, 'associate_plugin_file'), array());
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
                    $package = $downloadUrl;
                    $download = $upgrader->download_package($package);

                    if (is_wp_error($download)) {
                        throw new \Exception($download->get_error_message());
                    }

                    $working_dir = $upgrader->unpack_package($download, true);

                    if (is_wp_error($working_dir)) {
                        throw new \Exception($working_dir->get_error_message());
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
                        throw new \Exception($result->get_error_message());
                    }

                    $activate = true;

                } catch (\Exception $e) {
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
                        throw new \Exception($result->get_error_message());
                    }
                } catch (\Exception $e) {
                }
            }
        }
    }

    private static function associate_plugin_file($plugins, $key)
    {
        $path = explode('/', $key);
        $filename = end($path);
        $plugins[$filename] = $key;
        return $plugins;
    }

}
