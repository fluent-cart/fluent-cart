<?php

namespace FluentCartPro\App\Modules\Licensing\Services;

use FluentCart\Api\Resource\ProductResource;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDownload;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\FileSystem\DownloadService;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\Licensing\Concerns\CanManageLicenseSites;
use FluentCartPro\App\Modules\Licensing\Models\License;
use FluentCartPro\App\Modules\Licensing\Models\LicenseActivation;


class LicenseManager
{
    use CanManageLicenseSites;

    public function checkLicense(array $data)
    {
        $license = $this->validateLicense($data);
        if ($license instanceof \WP_Error) {
            return $license;
        }

        $url = $this->parseUrl(Arr::get($data, 'url'));

        // if url provide the check if the site is active
        if ($url) {
            $isSiteActive = $this->isSiteActive($license->id, $url);
            $responseData = $this->makeResponseData($license, 'valid', null, $isSiteActive ? 'yes' : 'no');
            wp_send_json($responseData, 200);
        }

        $responseData = $this->makeResponseData($license, 'valid', null);
        wp_send_json($responseData, 200);
    }

    private function parseUrl($url)
    {
        if (empty($url)) {
            return false;
        }
        return LicenseHelper::sanitizeSiteUrl($url);
    }

    public function getVersion($data)
    {
        $data = [
            'item_id' => !empty($data['item_id']) ? absint($data['item_id']) : false,
            'license' => !empty($data['license']) ? sanitize_text_field($data['license']) : false,
            'site_url' => isset($data['url']) ? LicenseHelper::sanitizeSiteUrl($data['url']) : '',
            'activation_hash' => isset($data['activation_hash']) ? sanitize_text_field($data['activation_hash']) : '',
            'slug' => isset($data['slug']) ? sanitize_text_field($data['slug']) : '',
        ];

        if (empty($data['item_id'])) {
            $this->sendEmptyVersionInfo();
        }

        $product = ProductResource::getQuery()->find((int)$data['item_id']);
        if (empty($product)) {
            $this->sendEmptyVersionInfo();
        }

        $licenseDetails = LicenseHelper::getProductLicenseConfig(intval($product->ID), 'view');

        if (empty($licenseDetails) || $licenseDetails['enabled'] != 'yes' || !$product) {
            $this->sendEmptyVersionInfo();
        }

        $license = $this->findLicenseByKey($data['license'], $data['item_id']);

        if (empty($license)) {
            $this->sendEmptyVersionInfo();
        }

        $changelogMeta = ProductMeta::query()->select('meta_value')
            ->where('object_id', $data['item_id'])
            ->where('meta_key', '_fluent_sl_changelog')
            ->first();

        $changelog = $changelogMeta ? $changelogMeta['meta_value'] : '';

        $productUrl = get_permalink($product->ID);
        $downloadUrl = $this->getEncodedDownloadPackageUrl($data, $license);

        $response = array(
            'new_version' => $licenseDetails['version'],
            'stable_version' => $licenseDetails['version'],
            'name' => $product->post_title,
            'slug' => $data['slug'] ?: $product->post_name,
            'url' => esc_url(add_query_arg('changelog', '1', $productUrl)),
            'last_updated' => $product->post_modified_gmt,
            'homepage' => $productUrl,
            'package' => $downloadUrl,
            'download_link' => $downloadUrl,
            'sections' => serialize(
                array(
                    'description' => wpautop(strip_tags($product->post_excerpt ?? '', '<p><li><ul><ol><strong><a><em><span><br>')),
                    'changelog' => wpautop(strip_tags(stripslashes($changelog), '<p><li><ul><ol><strong><a><em><span><br>')),
                )
            ),
            'banners' => serialize(
                array(
                    'high' => $licenseDetails['wp']['banner_url'],
                    'low' => $licenseDetails['wp']['banner_url']
                )
            ),
            'icons' => array(
                '1x' => $licenseDetails['wp']['icon_url'] ?? "",
                '2x' => $licenseDetails['wp']['icon_url'] ?? ""
            ),
        );

        wp_send_json($response, 200);
    }

    public function getEncodedDownloadPackageUrl($data, $license)
    {
        $this->validateLicense($data);
        $siteUrl = $this->parseUrl(Arr::get($data, 'site_url'));

        if (!$siteUrl) {
            return '';
        }

        // get license activation and then check if the provided site is in the activation
        $licenseId = $license->id;
        $isSiteActive = $this->isSiteActive($licenseId, $siteUrl);
        if (!$isSiteActive) {
            return '';
        }

        // get product license settings
        $productId = Arr::get($data, 'item_id');
        $licenseConfig = LicenseHelper::getProductLicenseConfig($productId, 'view');
        $downloadFileId = Arr::get($licenseConfig, 'global_update_file');
        $download = ProductDownload::find($downloadFileId);
        $params = [
            'download_id' => Arr::get($download, 'id'),
            'driver' => Arr::get($download, 'driver'),
            'file' => Arr::get($download, 'file_path'),
        ];

        $downloadService = new DownloadService();
        $package_url = $downloadService->getDownloadableUrl($params);

        return apply_filters( 'fluent_cart_sl_encoded_package_url', $package_url, []);
    }

    public function isSiteActive($licenseId, $siteUrl)
    {
        if (empty($licenseId) || empty($siteUrl)) {
            return null;
        }

        return LicenseActivation::getQuery()
            ->where('license_id', $licenseId)
            ->join('fct_license_sites', 'fct_license_activations.site_id', '=', 'fct_license_sites.id')
            ->where('fct_license_sites.site_url', $siteUrl)
            ->where('fct_license_activations.status', 'active')
            ->first();
    }

    public function validateLicense(array $data, $licenseKeyName = null)
    {
       $license = $this->getLicense($data, $licenseKeyName);

        if ($license->isExpired()) {
            $this->sendErrorResponse($license, 'expired');
        }

        if ($license->status == 'expired') {
            $this->sendErrorResponse($license, 'expired');
        }

        if ($license->status != 'active') {
            $this->sendErrorResponse($license, 'invalid');
        }

        return $license;
    }

    public function getLicense(array $data, $licenseKeyName = null)
    {
        $licenseKey = Arr::get($data, 'license');
        $productId = Arr::get($data, 'item_id');
        $license = null;

        $product = ProductResource::getQuery()->find($productId);

        if ($licenseKeyName !== 'id' && (empty($productId) || empty($product))) {
            $this->sendErrorResponse($license, 'invalid_item_id');
        }

        $license = $this->findLicenseByKey($licenseKey, $productId, $licenseKeyName);

        if (empty($license)) {
            $this->sendErrorResponse($license, 'missing');
        }

        return $license;
    }

    public function findLicenseByKey($licenseKey, $productId = null, $licenseKeyName = 'license_key')
    {
        $licenseKeyName ??= 'license_key';
        $licenseQuery =  License::query()->where($licenseKeyName, $licenseKey);
        if ($licenseKeyName !== 'id' && !empty($productId)) {
            $licenseQuery->where('product_id', $productId);
        }

        return $licenseQuery->with([
            'order', 'customer', 'productVariant'
        ])
            ->first();
    }

    public static function deleteLicensesByOrderId($orderId = null)
    {
        if (empty($orderId)) {
            return false;
        }

        try {
            $licenses = License::query()->where('order_id', $orderId)->get();
            $licenseIds = $licenses->pluck('id')->toArray();
            do_action('fluent_cart_sl/before_deleting_licenses', [
                'licenses' => $licenses
            ]);
            License::query()->where('order_id', $orderId)->delete();
            LicenseActivation::query()->whereIn('license_id', $licenseIds)->delete();
            do_action('fluent_cart_sl/after_deleting_licenses', [
                'licenses' => $licenses
            ]);
            return true;
        } catch (\Exception $e) {
            return new \WP_Error('license_delete_error', $e->getMessage(), $e);
        }
    }

    public static function issueLicense($data = [])
    {
        $defaults = [
            'status' => 'active',
            'limit' => 1
        ];

        $data = wp_parse_args($data, $defaults);

        $data['license_key'] = UUID::licensesKey($data);

        $data = apply_filters('fluent_cart_sl/issue_license_data', $data, []);

        try {
            $license = License::query()->create($data);
            do_action('fluent_cart_sl/license_issued', [
                'license' => $license,
                'data' => $data
            ]);

            return $license;
        } catch (\Exception $e) {
            print_r($e->getMessage());
        }
    }

    public static function disableLicenseByOrderId($orderId = null)
    {
        if (empty($orderId)) {
            return false;
        }

        $licenses = License::query()->where('order_id', $orderId)->get();
        $licenseIds = $licenses->pluck('id')->toArray();

        if (empty($licenseIds)) {
            return false;
        }

        do_action('fluent_cart_sl/before_updating_licenses_status', ['licenses' => $licenses]);
        do_action('fluent_cart_sl/before_updating_licenses_status_to_disabled', ['licenses' => $licenses]);

        $markDisable = License::query()->where('order_id', $orderId)->update(['status' => 'disabled']);

        do_action('fluent_cart_sl/after_updating_licenses_status', ['licenses' => $licenses]);
        do_action('fluent_cart_sl/after_updating_licenses_status_to_disabled', ['licenses' => $licenses]);

        return $markDisable;
    }

    private function makeResponseData($license, $licenseStatus = 'valid', $checksum = '', $siteStatus = null): array
    {
        if (empty($license)) {
            $data = [
                "success" => false,
                "error" => "missing",
                "license" => 'invalid'
            ];
        } else {
            $data = [
                "success" => true,
                "license" => $licenseStatus,
                "item_id" => $license->product_id,
                "item_name" => empty($license->product_variant) ? '' : $license->product_variant->variation_title,
                "license_limit" => $license->limit ?? 'unlimited',
                "site_count" => $license->activation_count,
                "expires" => $license->expiration_date ?? 'Lifetime',
                "activations_left" => $license->getActivationLimit(),
                "customer_name" => empty($license->customer) ? '' : $license->customer->full_name,
                "customer_email" => empty($license->email) ? '' : $license->customer->email,
                "price_id" => $license->variation_id
            ];
        }

        if ($siteStatus) {
            $data['site_active'] = $siteStatus;
        }

        return array_merge(
            $data,
            empty($checksum) ? [] : [
                "checksum" => $checksum,
            ]
        );
    }

    private function sendErrorResponse($license, $status): void
    {
        $processData = $this->makeResponseData($license, '', '');
        $error = [
            "success" => false,
            "license" => sanitize_text_field($status)
        ];
        $error = array_merge($processData, $error);
        wp_send_json($error, 200);
    }

    private function sendEmptyVersionInfo(): void
    {
        $data = [
            "new_version" => "",
            "stable_version" => "",
            "sections" => "",
            "license_check" => "",
            "msg" => "License key is not valid for this product!",
            "homepage" => "",
            "package" => "",
            "icons" => [],
            "banners" => []
        ];

        wp_send_json($data, 200);
    }

    private function makeError($data, $code): \WP_Error
    {
        return new \WP_Error($code, $data, $code);
    }

}