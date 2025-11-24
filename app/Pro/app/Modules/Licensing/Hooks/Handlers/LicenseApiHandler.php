<?php

namespace FluentCartPro\App\Modules\Licensing\Hooks\Handlers;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\Licensing\Models\License;
use FluentCartPro\App\Modules\Licensing\Models\LicenseActivation;
use FluentCartPro\App\Modules\Licensing\Models\LicenseSite;
use FluentCartPro\App\Modules\Licensing\Services\LicenseHelper;

class LicenseApiHandler
{

    public function register()
    {
        add_action('fluent_cart_action_check_license', [$this, 'checkLicense']);
        add_action('fluent_cart_action_activate_license', [$this, 'activateLicense']);
        add_action('fluent_cart_action_deactivate_license', [$this, 'deActivateLicense']);
        add_action('fluent_cart_action_get_license_version', [$this, 'getVersion']);
        add_action('fluent_cart_action_download_license_package', [$this, 'downloadLicensePackage']);
    }

    public function checkLicense($data = [])
    {
        $formattedData = [
            'license_key'     => sanitize_text_field(Arr::get($data, 'license_key', '')),
            'activation_hash' => sanitize_text_field(Arr::get($data, 'activation_hash', '')),
            'item_id'         => sanitize_text_field(Arr::get($data, 'item_id', '')),
            'site_url'        => LicenseHelper::sanitizeSiteUrl(Arr::get($data, 'site_url', '')),
        ];

        if (!$formattedData['site_url'] || (!$formattedData['license_key'] && !$formattedData['activation_hash']) || !$formattedData['item_id']) {
            return $this->sendSuccessResponse([
                'status'     => 'invalid',
                'error_type' => 'validation_error',
                'message'    => __('license_key, site_url and item_id is required', 'fluent-cart-pro'),
            ]);
        }

        [$license, $activation] = LicenseHelper::getLicenseByKeyHashData($formattedData);

        if (is_wp_error($license)) {
            $errorResponse = apply_filters('fluent_cart/license/checking_error', [
                'status'     => 'invalid',
                'error_type' => $license->get_error_code(),
                'message'    => $license->get_error_message()
            ], $formattedData);

            return $this->sendSuccessResponse($errorResponse);
        }

        if (($formattedData['item_id'] != $license->product_id) && apply_filters('fluent_cart/license/check_item_id', true, $license, $activation, $formattedData)) {

            $errorResponse = apply_filters('fluent_cart/license/checking_error', [
                'status'     => 'invalid',
                'error_type' => 'key_mismatch',
                'message'    => 'This license key is not valid for this product. Did you provide the valid license key?'
            ], $formattedData);

            return $this->sendSuccessResponse($errorResponse);
        }

        $status = $license->getPublicStatus();
        $product = $license->product;

        $returnData = [
            'status'            => $status,
            'activation_limit'  => $license->limit,
            'activation_hash'   => $activation ? $activation->activation_hash : '',
            'activations_count' => $license->activation_count,
            'license_key'       => $license->license_key,
            'expiration_date'   => $license->expiration_date ?: 'lifetime',
            'product_id'        => $license->product_id,
            'variation_id'      => $license->variation_id,
            'variation_title'   => $license->variation->variation_title ?? '',
            'product_title'     => $product ? $product->post_title : 'unknown product',
            'created_at'        => $license->created_at,
            'updated_at'        => $license->updated_at
        ];

        $returnData = apply_filters('fluent_cart/license/check_license_response', $returnData, $license, $activation, $data);

        if (is_wp_error($returnData)) {
            return $this->sendSuccessResponse([
                'status'     => 'invalid',
                'error_type' => 'license_error',
                'message'    => $returnData->get_error_message()
            ]);
        }

        return $this->sendSuccessResponse($returnData);
    }

    public function activateLicense($data = [])
    {
        $formattedData = [
            'license_key' => sanitize_text_field(Arr::get($data, 'license_key', '')),
            'item_id'     => sanitize_text_field(Arr::get($data, 'item_id', '')),
            'site_url'    => LicenseHelper::sanitizeSiteUrl(Arr::get($data, 'site_url', '')),
        ];

        if (!$formattedData['site_url'] || !$formattedData['license_key'] || !$formattedData['item_id']) {
            return $this->sendErrorResponse([
                'message'    => __('license_key, site_url and item_id is required', 'fluent-cart-pro'),
                'error_type' => 'validation_error'
            ], 422);
        }

        $license = License::query()
            ->where('license_key', $formattedData['license_key'])
            ->first();


        if (!$license) {
            return $this->sendErrorResponse([
                'message'    => __('License not found', 'fluent-cart-pro'),
                'error_type' => 'license_not_found'
            ], 422);
        }

        if ($license->product_id != $formattedData['item_id']) {
            return $this->sendErrorResponse([
                'message'    => __('This license key is not valid for this product. Did you provide the valid license key?', 'fluent-cart-pro'),
                'error_type' => 'key_mismatch'
            ], 422);
        }

        if ($license->isExpired()) {
            return $this->sendErrorResponse([
                'message'    => __('The license key is expired. Please renew or purchase a new license', 'fluent-cart-pro'),
                'error_type' => 'license_expired'
            ], 422);
        }

        if (!$license->isActive()) {
            return $this->sendErrorResponse([
                'message'    => __('The license is not Active. Please contact the support.', 'fluent-cart-pro'),
                'error_type' => 'license_not_active'
            ], 422);
        }

        $site = LicenseSite::query()
            ->where('site_url', $formattedData['site_url'])
            ->first();

        if ($site) {
            $activation = LicenseActivation::query()
                ->where('license_id', $license->id)
                ->where('site_id', $site->id)
                ->first();

            if ($activation) {
                $returnData = [
                    'status'            => 'valid',
                    'activation_limit'  => $license->limit,
                    'activation_hash'   => $activation->activation_hash ?? '',
                    'activations_count' => $license->activation_count,
                    'license_key'       => $license->license_key,
                    'expiration_date'   => $license->expiration_date ?: 'lifetime',
                    'product_id'        => $license->product_id,
                    'variation_id'      => $license->variation_id,
                    'variation_title'   => $license->variation->variation_title ?? '',
                    'product_title'     => $license->product ? $license->product->post_title : 'unknown product',
                    'created_at'        => $license->created_at,
                    'updated_at'        => $license->updated_at
                ];

                $returnData = apply_filters('fluent_cart/license/activate_license_response', $returnData, $license, $activation, $data);

                if (is_wp_error($returnData)) {
                    return $this->sendErrorResponse([
                        'message'    => $returnData->get_error_message(),
                        'error_type' => 'activation_error'
                    ], 422);
                }

                return $this->sendSuccessResponse($returnData);
            }
        }

        $activationLimit = $license->getActivationLimit();
        $isLocalSite = LicenseHelper::isLocalSite($formattedData['site_url']);

        if (!$isLocalSite && !$activationLimit) {
            return $this->sendErrorResponse([
                'message'    => __('This license key has no activation limit. Please upgrade or purchase a new license.', 'fluent-cart-pro'),
                'error_type' => 'activation_limit_exceeded'
            ], 422);
        }

        $isNewSite = false;

        if (!$site) {
            $site = LicenseSite::query()->create(array_filter([
                'site_url'         => $formattedData['site_url'],
                'server_version'   => sanitize_text_field(Arr::get($data, 'server_version', '')),
                'platform_version' => sanitize_text_field(Arr::get($data, 'platform_version', ''))
            ]));
            $isNewSite = true;
        } else {
            $serverVersion = sanitize_text_field(Arr::get($data, 'server_version', ''));
            $platformVersion = sanitize_text_field(Arr::get($data, 'platform_version', ''));

            $willUpdate = false;
            if ($serverVersion && $serverVersion != $site->server_version) {
                $site->server_version = $serverVersion;
                $willUpdate = true;
            }
            if ($platformVersion && $platformVersion != $site->platform_version) {
                $site->platform_version = $platformVersion;
                $willUpdate = true;
            }

            if ($willUpdate) {
                $site->save();
            }
        }


        $activation = null;

        if (!$isNewSite) {
            $activation = LicenseActivation::query()
                ->where('license_id', $license->id)
                ->where('site_id', $site->id)
                ->first();
        }

        if (!$activation) {
            $activation = LicenseActivation::query()->create(array_filter([
                'site_id'          => $site->id,
                'license_id'       => $license->id,
                'status'           => 'active',
                'is_local'         => $isLocalSite ? 1 : 0,
                'product_id'       => $license->product_id,
                'variation_id'     => $license->variation_id,
                'variation_title'   => $license->variation->variation_title ?? '',
                'activation_hash'  => md5(wp_generate_uuid4() . $license->license_key . $site->id),
                'last_update_date' => current_time('mysql')
            ]));
        } else {
            $activation->status = 'active';
            $activation->is_local = $isLocalSite ? 1 : 0;
            $activation->last_update_date = current_time('mysql');
            $activation->product_id = $license->product_id;
            $activation->variation_id = $license->variation_id;
            $activation->variation_title = $license->variation->variation_title ?? '';
            $activation->save();
        }

        // Let's recount the activations count
        $license->recountActivations();
        do_action('fluent_cart/license/site_activated', $site, $activation, $license, $data);

        $product = $license->product;

        $returnData = [
            'status'            => 'valid',
            'activation_limit'  => $license->limit,
            'activation_hash'   => $activation->activation_hash ?? '',
            'activations_count' => $license->activation_count,
            'license_key'       => $license->license_key,
            'expiration_date'   => $license->expiration_date ?: 'lifetime',
            'product_id'        => $license->product_id,
            'variation_id'      => $license->variation_id,
            'variation_title'   => $license->variation->variation_title ?? '',
            'product_title'     => $product ? $product->post_title : 'unknown product',
            'created_at'        => $license->created_at,
            'updated_at'        => $license->updated_at
        ];

        $returnData = apply_filters('fluent_cart/license/activate_license_response', $returnData, $license, $activation, $data);

        if (is_wp_error($returnData)) {
            return $this->sendErrorResponse([
                'message'    => $returnData->get_error_message(),
                'error_type' => 'activation_error'
            ], 422);
        }

        return $this->sendSuccessResponse($returnData);
    }

    public function deActivateLicense($data = [])
    {
        $formattedData = [
            'license_key' => sanitize_text_field(Arr::get($data, 'license_key', '')),
            'item_id'     => sanitize_text_field(Arr::get($data, 'item_id', '')),
            'site_url'    => LicenseHelper::sanitizeSiteUrl(Arr::get($data, 'site_url', '')),
        ];

        if (!$formattedData['site_url'] || !$formattedData['license_key'] || !$formattedData['item_id']) {
            do_action('fluent_cart/license/site_deactivated_failed', $formattedData);

            return $this->sendErrorResponse([
                'message'    => __('license_key, site_url and item_id is required', 'fluent-cart-pro'),
                'error_type' => 'validation_error'
            ], 422);
        }


        $license = License::query()
            ->where('license_key', $formattedData['license_key'])
            ->first();

        if (!$license || $license->product_id != $formattedData['item_id']) {
            do_action('fluent_cart/license/site_deactivated_failed', $formattedData);
            return $this->sendErrorResponse([
                'message'    => __('License not found or does not match with the item_id', 'fluent-cart-pro'),
                'error_type' => 'license_not_found'
            ], 422);
        }

        $site = LicenseSite::query()
            ->where('site_url', $formattedData['site_url'])
            ->first();

        if (!$site) {
            do_action('fluent_cart/license/site_deactivated_failed', $formattedData);
            return $this->sendErrorResponse([
                'message'    => __('Site not found', 'fluent-cart-pro'),
                'error_type' => 'site_not_found'
            ], 422);
        }

        $activation = LicenseActivation::query()
            ->where('license_id', $license->id)
            ->where('site_id', $site->id)
            ->first();

        if ($activation) {
            $activation->delete();
        }

        $license->recountActivations();

        if ($site) {
            do_action('fluent_cart/license/site_deactivated', $site, $activation, $license, $data);
        }

        $returnData = [
            'status'            => 'deactivated',
            'activation_limit'  => $license->limit,
            'activations_count' => $license->activation_count,
            'expiration_date'   => $license->expiration_date,
            'product_id'        => $license->product_id,
            'variation_id'      => $license->variation_id,
            'product_title'     => $license->product ? $license->product->post_title : 'unknown product',
            'variation_title'   => $license->variation->variation_title ?? '',
            'created_at'        => $license->created_at,
            'updated_at'        => $license->updated_at
        ];

        $returnData = apply_filters('fluent_cart/license/deactivate_license_response', $returnData, $license, $activation, $data);

        if (is_wp_error($returnData)) {
            return $this->sendErrorResponse([
                'message'    => $returnData->get_error_message(),
                'error_type' => 'deactivation_error'
            ], 422);
        }

        return $this->sendSuccessResponse($returnData);
    }

    public function getVersion($data = [])
    {
        $itemId = sanitize_text_field(Arr::get($data, 'item_id', ''));
        // Let's find the license version details by this itemId
        $product = Product::query()->find($itemId);

        if (!$product) {
            return $this->sendErrorResponse([
                'message'    => __('Product not found', 'fluent-cart-pro'),
                'error_type' => 'product_not_found'
            ], 422);
        }

        $licenseSettings = $product->getProductMeta('license_settings');
        if (empty($licenseSettings) || !is_array($licenseSettings)) {
            return $this->sendErrorResponse([
                'message'    => __('License settings not found for this product', 'fluent-cart-pro'),
                'error_type' => 'license_settings_not_found'
            ], 422);
        }

        $enabled = Arr::get($licenseSettings, 'enabled', '') === 'yes';
        if (!$enabled) {
            return $this->sendErrorResponse([
                'message'    => __('License is not enabled for this product', 'fluent-cart-pro'),
                'error_type' => 'license_not_enabled'
            ], 422);
        }

        $changelog = (string)$product->getProductMeta('_fluent_sl_changelog');

        $changeLogUrl = Arr::get($licenseSettings, 'wp.readme_url', '');

        if (!$changeLogUrl) {
            $changeLogUrl = get_permalink($product->ID);
        }

        $icon = Arr::get($licenseSettings, 'wp.icon_url', '');
        $bannerURL = Arr::get($licenseSettings, 'wp.banner_url', '');

        $changeLogData = [
            'new_version'    => Arr::get($licenseSettings, 'version', ''),
            'stable_version' => Arr::get($licenseSettings, 'version', ''),
            'name'           => $product->post_title,
            'slug'           => $product->post_name,
            'url'            => $changeLogUrl,
            'last_updated'   => $product->post_modified,
            'homepage'       => get_permalink($product->ID),
            'package'        => '',
            'download_link'  => '',
            'sections'       => [
                'description' => (string)$product->post_excerpt,
                'changelog'   => $changelog
            ],
            'banners'        => [
                'low'  => $bannerURL,
                'high' => $bannerURL
            ],
            'icons'          => [
                '2x' => $icon,
                '1x' => $icon
            ]
        ];

        $formattedData = array_filter([
            'license_key'     => sanitize_text_field(Arr::get($data, 'license_key', '')),
            'activation_hash' => sanitize_text_field(Arr::get($data, 'activation_hash', '')),
            'item_id'         => sanitize_text_field(Arr::get($data, 'item_id', '')),
            'site_url'        => LicenseHelper::sanitizeSiteUrl(Arr::get($data, 'site_url', '')),
        ]);

        [$license, $activation] = LicenseHelper::getLicenseByKeyHashData($formattedData, false);

        if (is_wp_error($license) || !$license->isValid()) {
            $changeLogData['license_message'] = 'Invalid License Key';
            $changeLogData['license_status'] = 'invalid';
        } else {
            $changeLogData['license_status'] = $license->getPublicStatus();
            if (Arr::get($formattedData, 'activation_hash', '')) {
                $formattedData['license_key'] = '';
            }

            $expires = time() + 48 * HOUR_IN_SECONDS;
            $packageData = Arr::get($formattedData, 'license_key') . ':' . Arr::get($formattedData, 'activation_hash', '') . ':' . Arr::get($formattedData, 'site_url') . ':' . $license->product_id . ':' . $expires;
            $hash = base64_encode($packageData);

            $packageUrl = add_query_arg([
                'fct_package' => $hash
            ], home_url('?fluent-cart=download_license_package'));

            $changeLogData['download_link'] = $packageUrl;
            $changeLogData['package'] = $packageUrl;
            $changeLogData['trunk'] = $packageUrl;
        }

        $returnData = apply_filters('fluent_cart/license/get_version_response', $changeLogData, $product, $data);

        if (is_wp_error($returnData)) {
            return $this->sendErrorResponse([
                'message'    => $returnData->get_error_message(),
                'error_type' => $returnData->get_error_code()
            ], 422);
        }

        return $this->sendSuccessResponse($returnData);
    }

    public function downloadLicensePackage($data = [])
    {
        $packageHash = Arr::get($data, 'fct_package', '');
        $packageData = base64_decode($packageHash);

        if (!$packageData) {
            return $this->sendErrorResponse([
                'message'    => __('Invalid package data', 'fluent-cart-pro'),
                'error_type' => 'invalid_package_data'
            ], 422);
        }

        $packageData = explode(':', $packageData);

        $data = [
            'license_key'     => sanitize_text_field(Arr::get($packageData, 0, '')),
            'activation_hash' => sanitize_text_field(Arr::get($packageData, 1, '')),
            'site_url'        => LicenseHelper::sanitizeSiteUrl(Arr::get($packageData, 2, '')),
            'item_id'         => sanitize_text_field(Arr::get($packageData, 3, ''))
        ];

        [$license, $activation] = LicenseHelper::getLicenseByKeyHashData($data, false);

        if (is_wp_error($license)) {
            return $this->sendErrorResponse([
                'message'    => $license->get_error_message(),
                'error_type' => $license->get_error_code()
            ], 422);
        }

        if (!$license->isValid()) {
            return $this->sendErrorResponse([
                'message'    => __('This license key is not valid', 'fluent-cart-pro'),
                'error_type' => 'expired_license'
            ], 422);
        }

        // Let's find the downloadable product by item_id
        $product = Product::query()->find($data['item_id']);
        if (!$product) {
            return $this->sendErrorResponse([
                'message'    => __('Product not found', 'fluent-cart-pro'),
                'error_type' => 'product_not_found'
            ], 422);
        }

        $licenseSettings = $product->getProductMeta('license_settings');
        $enabled = Arr::get($licenseSettings, 'enabled', '') === 'yes';
        if (!$enabled) {
            return $this->sendErrorResponse([
                'message'    => __('License is not enabled for this product', 'fluent-cart-pro'),
                'error_type' => 'license_not_enabled'
            ], 422);
        }
        $downloadableFiles = $product->downloadable_files;
        $downloadableFile = null;

        $updateFileId = Arr::get($licenseSettings, 'global_update_file', '');
        if ($updateFileId) {
            $downloadableFile = $downloadableFiles->firstWhere('id', $updateFileId);
        }

        if (!$downloadableFile && $downloadableFiles->count() == 1) {
            $downloadableFile = $downloadableFiles->first();
        }

        if (!$downloadableFile) {
            return $this->sendErrorResponse([
                'message'    => __('No downloadable file found for this product', 'fluent-cart-pro'),
                'error_type' => 'downloadable_file_not_found'
            ], 422);
        }

        $signedDownlloadUrl = Helper::generateDownloadFileLink($downloadableFile);

        if ($signedDownlloadUrl) {
            header('Location: ' . $signedDownlloadUrl);
            exit();
        }

        die('Download link is not available for this product.');
    }

    private function sendSuccessResponse(array $data, int $code = 200)
    {
        $data['success'] = true;
        wp_send_json($data, $code);
    }

    private function sendErrorResponse(array $data, int $code = 400)
    {
        if (empty($data['success'])) {
            $data['success'] = false;
        }

        wp_send_json($data, $code);
    }

}
