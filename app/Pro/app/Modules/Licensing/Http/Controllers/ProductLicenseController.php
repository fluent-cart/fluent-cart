<?php

namespace FluentCartPro\App\Modules\Licensing\Http\Controllers;

use FluentCart\App\Models\ProductMeta;
use FluentCart\Framework\Http\Controller;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\Licensing\Services\LicenseHelper;

class ProductLicenseController extends Controller
{
    public function getSettings(Request $request, $id): array
    {

        $settings = LicenseHelper::getProductLicenseConfig($id, 'edit');

        $changeLog = ProductMeta::query()->where('object_id', $id)->where('meta_key', '_fluent_sl_changelog')->first();
        $settings['changelog'] = $changeLog ? $changeLog->meta_value : '';

        $licenseKeys = ProductMeta::query()->where('object_id', $id)->where('meta_key', 'license_keys')->first();
        $settings['license_keys'] = $licenseKeys ? $licenseKeys->meta_value : '';

        return [
            'settings' => $settings,
        ];
    }

    public function saveSettings(Request $request, $id): array
    {
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
