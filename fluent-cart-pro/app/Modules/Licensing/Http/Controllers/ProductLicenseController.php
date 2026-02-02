<?php

namespace FluentCartPro\App\Modules\Licensing\Http\Controllers;

use FluentCart\App\Models\ProductMeta;
use FluentCart\Framework\Http\Controller;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\Product;
use FluentCartPro\App\Modules\Licensing\Services\LicenseHelper;

class ProductLicenseController extends Controller
{
    public function getSettings(Request $request, $id): array
    {
        $product = Product::query()->find($id);
        $isBundleProduct = $product && $product->isBundleProduct();

        $settings = LicenseHelper::getProductLicenseConfig($id, 'edit');

        $changeLog = ProductMeta::query()->where('object_id', $id)->where('meta_key', '_fluent_sl_changelog')->first();
        $settings['changelog'] = $changeLog ? $changeLog->meta_value : '';

        $licenseKeys = ProductMeta::query()->where('object_id', $id)->where('meta_key', 'license_keys')->first();
        $settings['license_keys'] = $licenseKeys ? $licenseKeys->meta_value : '';

        // Force disable licensing for bundle products
        if ($isBundleProduct) {
            $settings['enabled'] = 'no';
        }

        return [
            'settings' => $settings,
            'is_bundle_product' => $isBundleProduct,
        ];
    }

    public function saveSettings(Request $request, $id): array
    {
        $product = Product::query()->find($id);
        $isBundleProduct = $product && $product->isBundleProduct();

        // Prevent saving license settings for bundle products
        if ($isBundleProduct) {
            return $this->sendError([
                'message' => __('License settings cannot be saved for bundle products. Licenses are generated according to bundle items\' license settings.', 'fluent-cart-pro'),
            ], 422);
        }

        $data = $request->get('settings', []);

        $licenseSettings = Arr::only($data, [
            'enabled', 'version', 'global_update_file', 'variations', 'wp', 'prefix'
        ]);

        $isEnabled = $licenseSettings['enabled'] === 'yes';

        if ($isEnabled) {
            $this->validate($licenseSettings, [
                'version' => 'required'
            ]);
        }

        $formattedVariations = [];
        foreach ($licenseSettings['variations'] as $variation) {
            $variationId = $variation['variation_id'];
            $formattedVariations[$variationId] = Arr::only($variation, [
                'variation_id', 'activation_limit', 'validity'
            ]);

            $variation = ProductVariation::query()->find($variationId);

            $formattedVariations[$variationId]['validity'] = apply_filters('fluent_cart/license/validity_by_variation', $formattedVariations[$variationId]['validity'], [
                'variation' => $variation
            ]);


            $this->validate($formattedVariations[$variationId], [
                'activation_limit' => [function ($attribute, $value) use ($variation) {
                    if ($value != '' && $value < 0) {
                        return __('Activation limit must be greater than or equal to 0', 'fluent-cart-pro');
                    }
                }],

            ]);


            if ($isEnabled) {
                $this->validate($formattedVariations[$variationId]['validity'], [
                    'unit' => 'required'
                ], [
                    'unit.required' => sprintf(__('Validity type is required for %s.', 'fluent-cart-pro'), $variation['title'])
                ]);
            }
        }

        $licenseSettings['variations'] = $formattedVariations;

        ProductMeta::updateOrCreate(
            ['object_id' => $id, 'meta_key'  => 'license_settings'],
            ['meta_value' => $licenseSettings]
        );

        if ($data['changelog']) {
            ProductMeta::updateOrCreate(
                ['object_id' => $id, 'meta_key'  => '_fluent_sl_changelog'],
                ['meta_value' => wp_kses_post($data['changelog'])]
            );
        }

        if ($data['license_keys']) {
            ProductMeta::updateOrCreate(
                ['object_id' => $id, 'meta_key'  => 'license_keys'],
                ['meta_value' => wp_kses_post($data['license_keys'])]
            );
        }

        return [
            'message' => __('Settings has been updated successfully.', 'fluent-cart-pro'),
        ];
    }
}
