<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\ModuleSettings;
use FluentCart\Api\Sanitizer\Sanitizer;
use FluentCart\App\Services\PluginInstaller\AddonManager;
use FluentCart\App\Vite;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;

class ModuleSettingsController extends Controller
{
    public function getSettings(): \WP_REST_Response
    {
        $fields = ModuleSettings::fileds();
        $values = ModuleSettings::getAllSettings();

        return $this->sendSuccess([
            'fields'   => [
                'modules_settings' => [
                    'title'           => __('Features & addon', 'fluent-cart'),
                    'type'            => 'section',
                    'class'           => 'no-padding',
                    'disable_nesting' => true,
                    'columns'         => [
                        'default' => 1,
                        'md'      => 1
                    ],
                    'schema'          => $fields
                ]
            ],
            'settings' => $values
        ]);
    }

    public function saveSettings(Request $request)
    {
        $prevSettings = ModuleSettings::getAllSettings(false);

        $data = $request->only(
            ModuleSettings::validKeys()
        );

        $data = Sanitizer::sanitize($data);

        ModuleSettings::saveSettings($data);

        foreach ($data as $moduleKey => $moduleData) {
            $prevStatus = Arr::get($prevSettings, $moduleKey . '.active', 'no');
            $newStatus = Arr::get($moduleData, 'active', 'no');

            if ($prevStatus === 'yes' && $newStatus === 'no') {
                // Module deactivated
                do_action('fluent_cart/module/deactivated/' . $moduleKey, $moduleData, $prevSettings[$moduleKey] ?? []);
            } elseif ($prevStatus === 'no' && $newStatus === 'yes') {
                // Module activated
                do_action('fluent_cart/module/activated/' . $moduleKey, $moduleData, $prevSettings[$moduleKey] ?? []);
            } elseif ($newStatus === 'yes') {
                // Module is active — fire activation hook if any child settings changed
                $hasChanged = $this->hasModuleSettingsChanged($moduleData, $prevSettings[$moduleKey] ?? []);
                if ($hasChanged) {
                    do_action('fluent_cart/module/activated/' . $moduleKey, $moduleData, $prevSettings[$moduleKey] ?? []);
                }
            }
        }

        return $this->sendSuccess([
            'message' => __('Settings saved successfully', 'fluent-cart')
        ]);
    }

    private function hasModuleSettingsChanged($newData, $oldData)
    {
        if (!is_array($newData) || !is_array($oldData)) {
            return true;
        }

        foreach ($newData as $key => $value) {
            if ($key === 'active') {
                continue;
            }

            $oldValue = Arr::get($oldData, $key);
            if ($oldValue !== $value) {
                return true;
            }
        }

        return false;
    }

    public function getPluginAddons(): \WP_REST_Response
    {
        $addons = $this->getRegisteredPluginAddons();

        // Add installation status for each addon
        foreach ($addons as $key => &$addon) {
            $isUpcoming = Arr::get($addon, 'upcoming', false);
            if($isUpcoming){
                $addon['is_installed'] = false;
                $addon['is_active'] = false;
                $addon['plugin_file'] = '';
                $addon['source_link'] = '';
                $addon['source_type'] = '';
                continue;
            }
            if (!empty($addon['plugin_file']) && !empty($addon['plugin_slug'])) {
                $status = (new AddonManager())->getAddonStatus($addon['plugin_slug'], $addon['plugin_file']);
                $addon['is_installed'] = $status['is_installed'];
                $addon['is_active'] = $status['is_active'];
            } else {
                $addon['is_installed'] = false;
                $addon['is_active'] = false;
            }
        }

        return $this->sendSuccess([
            'addons' => $addons,
        ]);
    }

    public function installPluginAddon(Request $request): \WP_REST_Response
    {
        $pluginSlug = $request->getSafe('plugin_slug', 'sanitize_text_field');
        $sourceType = $request->getSafe('source_type', 'sanitize_text_field', 'wordpress');
        $sourceLink = $request->getSafe('source_link', 'sanitize_url', '');
        $assetPath = $request->getSafe('asset_path', 'sanitize_text_field', '');
        $assetPath = trim($assetPath);
        if(empty($assetPath)){
            $assetPath = 'zipball_url';
        }


        if (!$pluginSlug) {
            return $this->sendError([
                'message' => __('Plugin slug is required.', 'fluent-cart')
            ]);
        }

        // Validate the addon is in the allowed list
        $registeredAddons = $this->getRegisteredPluginAddons();
        $allowedAddon = null;

        foreach ($registeredAddons as $addon) {
            if ($addon['plugin_slug'] === $pluginSlug) {
                $allowedAddon = $addon;
                break;
            }
        }

        if (!$allowedAddon) {
            return $this->sendError([
                'message' => __('This addon cannot be installed.', 'fluent-cart')
            ]);
        }

        // Use source from registered addon if not provided
        if (empty($sourceType) && !empty($allowedAddon['source_type'])) {
            $sourceType = $allowedAddon['source_type'];
        }
        if (empty($sourceLink) && !empty($allowedAddon['source_link'])) {
            $sourceLink = $allowedAddon['source_link'];
        }

        $addonManager = new AddonManager();
        $result = $addonManager->installAddon($sourceType, $sourceLink, $pluginSlug, $assetPath);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message()
            ]);
        }

        return $this->sendSuccess($result);
    }

    public function activatePluginAddon(Request $request): \WP_REST_Response
    {
        $pluginFile = $request->getSafe('plugin_file', 'sanitize_text_field');

        if (!$pluginFile) {
            return $this->sendError([
                'message' => __('Plugin file is required.', 'fluent-cart')
            ]);
        }


        $addonManager = new AddonManager();
        $result = $addonManager->activateAddon($pluginFile);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message()
            ]);
        }

        return $this->sendSuccess([
            'message' => __('Addon activated successfully.', 'fluent-cart')
        ]);
    }

    public function verifyTurnstileKeys(Request $request): \WP_REST_Response
    {
        $siteKey = $request->getSafe('site_key', 'sanitize_text_field');
        $secretKey = $request->getSafe('secret_key', 'sanitize_text_field');

        $token = $request->getSafe('token', 'sanitize_text_field');

        if (empty($siteKey) || empty($secretKey)) {
            return $this->sendError([
                'message' => __('Both Site Key and Secret Key are required.', 'fluent-cart')
            ]);
        }

        if (empty($token)) {
            return $this->sendError([
                'message' => __('Could not get a Turnstile token. Please check your Site Key and ensure this domain is allowed in Cloudflare.', 'fluent-cart')
            ]);
        }

        $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'body'    => [
                'secret'   => $secretKey,
                'response' => $token,
            ],
            'timeout' => 10
        ]);

        if (is_wp_error($response)) {
            return $this->sendError([
                'message' => __('Could not connect to Cloudflare. Please try again.', 'fluent-cart')
            ]);
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if (!empty($result['success'])) {
            return $this->sendSuccess([
                'message' => __('Turnstile keys are valid and working.', 'fluent-cart')
            ]);
        }

        $errorCodes = Arr::get($result, 'error-codes', []);
        $errorMessage = __('Invalid Turnstile keys. Please check your Site Key and Secret Key.', 'fluent-cart');

        if (in_array('invalid-input-secret', $errorCodes)) {
            $errorMessage = __('The Secret Key is invalid. Please check it in your Cloudflare Dashboard.', 'fluent-cart');
        }

        return $this->sendError([
            'message' => $errorMessage
        ]);
    }

    private function getRegisteredPluginAddons(): array
    {
        $addons = [
            'elementor-block' => [
                'title'       => __('Elementor Blocks', 'fluent-cart'),
                'description' => __('Enable to get Elementor Blocks for FluentCart. Minimum Requirement: Elementor V3.34', 'fluent-cart'),
                'logo'        => Vite::getAssetUrl('images/elementor/black.svg'),
                'dark_logo'   => Vite::getAssetUrl('images/elementor/white.svg'),
                'plugin_slug' => 'fluent-cart-elementor-blocks',
                'plugin_file' => 'fluent-cart-elementor-blocks/fluent-cart-elementor-blocks.php',
                'source_type' => 'cdn',
                'source_link' => 'https://addons-cdn.fluentcart.com/fluent-cart-elementor-blocks.zip',
                'upcoming' => false,
                'repo_link' => 'https://github.com/WPManageNinja/fluent-cart-elementor-blocks'
            ],
            'fluent-pdf' => [
                'title'       => __('Fluent PDF', 'fluent-cart'),
                'description' => __('Generate PDF receipts and attach them to email notifications.', 'fluent-cart'),
                'logo'        => Vite::getAssetUrl('images/fluent-pdf/black.svg'),
                'dark_logo'   => Vite::getAssetUrl('images/fluent-pdf/white.svg'),
                'plugin_slug' => 'fluentforms-pdf',
                'plugin_file' => 'fluentforms-pdf/fluentforms-pdf.php',
                'source_type' => 'wordpress',
                'upcoming'    => false,
            ],
            'fluent-cart-migrator' => [
                'title'       => __('FluentCart Migrator', 'fluent-cart'),
                'description' => __('Migrate your store data to FluentCart from other eCommerce platforms.', 'fluent-cart'),
                'logo'        => Vite::getAssetUrl('images/logo.svg'),
                'plugin_slug' => 'fluent-cart-migrator',
                'plugin_file' => 'fluent-cart-migrator/fluent-cart-migrator.php',
                'source_type' => 'cdn',
                'source_link' => 'https://addons-cdn.fluentcart.com/fluent-cart-migrator.zip',
                'upcoming'    => false,
                'repo_link'   => 'https://fluentcart.com/fluentcart-addons/?3181_search=Migrator'
            ],
        ];

        // Allow other modules/plugins to register their addons
        return apply_filters('fluent_cart/module_settings/plugin_addons', $addons);
    }
}
