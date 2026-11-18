<?php

namespace FluentCart\App\Services\PluginInstaller;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\Framework\Support\Arr;

class AddonManager
{
    public function installAddon($sourceType, $sourceLink, $pluginSlug, $path = 'zipball_url')
    {
        if (!current_user_can('install_plugins')) {
            return new \WP_Error('permission_denied', __('You do not have permission to install plugins.', 'fluent-cart'));
        }

        $backgroundInstaller = new BackgroundInstaller();
        if ($sourceType === 'wordpress') {
            $result = $backgroundInstaller->installPlugin($pluginSlug);
        } else if ($sourceType === 'cdn') {
            $result = $backgroundInstaller->installFromCdn($sourceLink, $pluginSlug);
        } else if ($sourceType === 'github') {
            $result = $backgroundInstaller->installFromGithub($sourceLink, $pluginSlug, $path);
        } else {
            return new \WP_Error('invalid_source', __('Invalid addon source type.', 'fluent-cart'));
        }

        return $result;
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

    /**
     * What the dashboard recommendations look at: store country, another store plugin, physical products.
     */
    public static function getStoreSignals()
    {
        $country = strtoupper((string)(new StoreSettings())->get('store_country'));
        $otherStore = '';
        if (defined('WC_VERSION')) {
            $otherStore = 'WooCommerce';
        } elseif (defined('EDD_VERSION')) {
            $otherStore = 'Easy Digital Downloads';
        }

        return [
            'country'     => $country,
            'eu'          => LocalizationManager::getInstance()->isEuTaxCountry($country),
            'other_store' => $otherStore,
            'physical'    => ProductVariation::query()->where('fulfillment_type', 'physical')->exists(),
        ];
    }

    /**
     * Page builders that are active while their FluentCart add-on is not. Constants only, so it costs nothing
     * on the many requests where the list is empty.
     */
    public static function getBuilderSignals()
    {
        $builders = [
            ['key' => 'elementor-block', 'name' => 'Elementor', 'builder' => defined('ELEMENTOR_VERSION'), 'addon' => defined('FLUENTCART_ELEMENTOR_BLOCKS_VERSION')],
            ['key' => 'fluent-cart-bricks-blocks', 'name' => 'Bricks', 'builder' => defined('BRICKS_VERSION'), 'addon' => defined('FLUENT_CART_BRICKS_BLOCKS_VERSION')],
            // FLUENTCART_DIVI_BLOCKS_VERSION covers installs from before the slug rename.
            ['key' => 'fluent-cart-divi-modules', 'name' => 'Divi', 'builder' => strpos(get_template(), 'Divi') !== false, 'addon' => defined('FLUENTCART_DIVI_MODULES_VERSION') || defined('FLUENTCART_DIVI_BLOCKS_VERSION')],
        ];

        $missing = [];
        foreach ($builders as $builder) {
            if ($builder['builder'] && !$builder['addon']) {
                $missing[] = ['key' => $builder['key'], 'name' => $builder['name']];
            }
        }

        return $missing;
    }

    /**
     * Add-on list entries for the builders without their add-on, in order; the page shows the first one not closed.
     * Only entries whose button this user may run: activate_plugins if installed, install_plugins if not.
     */
    public static function pickBuilderAddons(array $addons, array $missingBuilders)
    {
        $picked = [];
        foreach ($missingBuilders as $builder) {
            $addon = Arr::get($addons, $builder['key']);
            if (!$addon) {
                continue;
            }

            $pluginFile = Arr::get($addon, 'plugin_file', '');
            $isInstalled = $pluginFile !== '' && file_exists(WP_PLUGIN_DIR . '/' . $pluginFile);

            // Multisite: a subsite admin is a FluentCart admin but may not manage plugins.
            if (!current_user_can($isInstalled ? 'activate_plugins' : 'install_plugins')) {
                continue;
            }

            $picked[] = array_merge($addon, [
                'key'          => $builder['key'],
                'builder'      => $builder['name'],
                'is_installed' => $isInstalled,
            ]);
        }

        return $picked;
    }

    /**
     * Add-ons from the plugin add-on list whose signal matches this store and that are not active.
     * Builders are left out: the Getting Started checklist already lists them.
     */
    public static function pickRecommendations(array $addons, array $signals)
    {
        $isGermany = Arr::get($signals, 'country') === 'DE';
        $otherStore = Arr::get($signals, 'other_store', '');

        $rules = [
            'fluent-cart-customer-rights' => [
                'match'  => !empty($signals['eu']),
                'reason' => $isGermany
                    ? __('Your store is in Germany. German B2C stores need a withdrawal button.', 'fluent-cart')
                    : __('Your store is in the EU. Let customers request withdrawals, refunds and cancellations.', 'fluent-cart'),
                'badge'  => $isGermany ? __('Required in Germany', 'fluent-cart') : '',
            ],
            'fluent-cart-migrator'        => [
                'match'  => $otherStore !== '',
                /* translators: 1: the other store plugin, e.g. WooCommerce */
                'reason' => sprintf(__('%1$s is installed. Import your products, customers and orders.', 'fluent-cart'), $otherStore),
                'badge'  => '',
            ],
            'shipping-for-fluent-cart'    => [
                'match'  => !empty($signals['physical']),
                'reason' => __('You sell physical products. Get live carrier rates and print shipping labels.', 'fluent-cart'),
                'badge'  => '',
            ],
        ];

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $items = [];
        foreach ($rules as $key => $rule) {
            $addon = Arr::get($addons, $key);
            if (!$rule['match'] || !$addon) {
                continue;
            }

            // Cheap status for the matches only: the active_plugins option and one file check.
            $pluginFile = Arr::get($addon, 'plugin_file', '');
            if (!isset($addon['is_active'])) {
                $addon['is_active'] = $pluginFile && is_plugin_active($pluginFile);
                $addon['is_installed'] = $pluginFile && file_exists(WP_PLUGIN_DIR . '/' . $pluginFile);
            }
            if ($addon['is_active']) {
                continue;
            }

            // The whole list entry travels along: the card installs, activates and redirects with it.
            $items[] = array_merge($addon, [
                'key'          => $key,
                'is_installed' => !empty($addon['is_installed']),
                'reason'       => $rule['reason'],
                'badge'        => $rule['badge'],
            ]);
        }

        return $items;
    }

    /**
     * Check for updates based on source type
     *
     * @param string $sourceType Source type (github, wordpress, other)
     * @param string $sourceLink Source URL (for github, other sources)
     * @param string $pluginFile Plugin file path
     * @param string $pluginSlug Plugin slug (for wordpress)
     * @return array|\WP_Error
     */
    public function checkForUpdate($sourceType, $sourceLink, $pluginFile, $pluginSlug = '')
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Get current plugin version
        $pluginPath = WP_PLUGIN_DIR . '/' . $pluginFile;
        if (!file_exists($pluginPath)) {
            return new \WP_Error('plugin_not_found', __('Plugin file not found.', 'fluent-cart'));
        }

        $pluginData = get_plugin_data($pluginPath);
        $currentVersion = $pluginData['Version'] ?? '1.0.0';

        // Route to appropriate update check method
        switch ($sourceType) {
            case 'github':
                return $this->checkUpdateFromGitHub($sourceLink, $currentVersion);

            case 'wordpress':
                return $this->checkUpdateFromWordPress($pluginFile, $currentVersion);

            default:
                return $this->checkUpdateFromOther($sourceType, $sourceLink, $currentVersion);
        }
    }

    /**
     * Check for updates from GitHub
     *
     * @param string $sourceLink releases/latest URL
     * @param string $currentVersion Current plugin version
     * @return array|\WP_Error
     */
    private function checkUpdateFromGitHub($sourceLink, $currentVersion)
    {
        if (!$sourceLink) {
            return new \WP_Error('invalid_url', __('Source link is required', 'fluent-cart'));
        }

        $latestVersionInfo = $this->getLatestGitHubVersion($sourceLink);

        if (is_wp_error($latestVersionInfo)) {
            return $latestVersionInfo;
        }

        $latestVersion = $latestVersionInfo['version'];
        $hasUpdate = version_compare($latestVersion, $currentVersion, '>');

        return [
            'current_version' => $currentVersion,
            'latest_version'  => $latestVersion,
            'has_update'      => $hasUpdate,
            'download_url'    => $latestVersionInfo['download_url'],
            'release_notes'   => $latestVersionInfo['release_notes'] ?? '',
            'source_type'     => 'github'
        ];
    }

    /**
     * Check for updates from WordPress.org
     *
     * @param string $pluginFile Plugin file path
     * @param string $currentVersion Current plugin version
     * @return array|\WP_Error
     */
    private function checkUpdateFromWordPress($pluginFile, $currentVersion)
    {
        // Force refresh of update transients
        wp_update_plugins();

        // Get available updates
        $update_plugins = get_site_transient('update_plugins');

        if (!$update_plugins || !isset($update_plugins->response)) {
            return new \WP_Error('no_updates', __('Unable to check for updates from WordPress.org', 'fluent-cart'));
        }

        // Check if this plugin has an update available
        if (isset($update_plugins->response[$pluginFile])) {
            $update = $update_plugins->response[$pluginFile];

            return [
                'current_version' => $currentVersion,
                'latest_version'  => $update->new_version,
                'has_update'      => true,
                'download_url'    => $update->package ?? '',
                'release_notes'   => $update->upgrade_notice ?? '',
                'source_type'     => 'wordpress'
            ];
        }

        // No update available
        return [
            'current_version' => $currentVersion,
            'latest_version'  => $currentVersion,
            'has_update'      => false,
            'download_url'    => '',
            'release_notes'   => '',
            'source_type'     => 'wordpress'
        ];
    }

    /**
     * Check for updates from other sources (placeholder)
     *
     * @param string $sourceType Source type
     * @param string $sourceLink Source link
     * @param string $currentVersion Current plugin version
     * @return array|\WP_Error
     */
    private function checkUpdateFromOther($sourceType, $sourceLink, $currentVersion)
    {
        return new \WP_Error(
            'not_supported',
            sprintf(
                /* translators: %s: add-on source type */
                __('Update check for source type "%s" is not currently available.', 'fluent-cart'),
                $sourceType
            )
        );
    }

    /**
     * Get latest version info from GitHub
     *
     * @param string $releasesUrl GitHub releases URL
     * @return array|\WP_Error
     */
    private function getLatestGitHubVersion($releasesUrl)
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
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'FluentCart/' . FLUENTCART_VERSION
            ]
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error('api_error', $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            /* translators: %1$s: HTTP status code */
            return new \WP_Error('api_error', sprintf(__('Github API error with status code: %1$s', 'fluent-cart'), $code));
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data['tag_name']) || empty($data['zipball_url'])) {
            return new \WP_Error('no_release', __('No release found. Please ensure the repository has published releases.', 'fluent-cart'));
        }

        // Extract version number from tag (remove 'v' prefix if present)
        $version = ltrim($data['tag_name'], 'vV');

        return [
            'version'       => $version,
            'download_url'  => $data['zipball_url'],
            'release_notes' => $data['body'] ?? ''
        ];
    }


    /**
     * Update addon from WordPress.org
     *
     * @param string $pluginFile Plugin file path
     * @return bool|\WP_Error
     */
    private function updateFromWordPress($pluginFile)
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $was_active = is_plugin_active($pluginFile);

        if ($was_active) {
            deactivate_plugins($pluginFile, true); // true = silent
        }

        // Use WordPress native Plugin_Upgrader
        $skin = new \WP_Ajax_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);

        // Perform the upgrade
        $result = $upgrader->upgrade($pluginFile);

        if (is_wp_error($result)) {
            if ($was_active) {
                activate_plugin($pluginFile, '', false, true); // true = silent
            }
            return $result;
        }

        if ($result === false) {
            if ($was_active) {
                activate_plugin($pluginFile, '', false, true);
            }
            return new \WP_Error('update_failed', __('Plugin update failed.', 'fluent-cart'));
        }

        if ($was_active) {
            $activate_result = activate_plugin($pluginFile, '', false, true);

            if (is_wp_error($activate_result)) {
                return new \WP_Error('activation_failed', $activate_result->get_error_message());
            }
        }

        return true;
    }

    /**
     * Update addon from other sources (placeholder)
     *
     * @param string $sourceType Source type
     * @return \WP_Error
     */
    private function updateFromOther($sourceType)
    {
        return new \WP_Error(
            'not_supported',
            sprintf(
                /* translators: %s: add-on source type */
                __('Update for source type "%s" is not currently available.', 'fluent-cart'),
                $sourceType
            )
        );
    }
}