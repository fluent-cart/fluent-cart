<?php

namespace FluentCartPro\App\Modules\Licensing\Services;

use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\Licensing\Models\License;
use FluentCartPro\App\Modules\Licensing\Models\LicenseSite;


class UUID
{
    public static function activationHash(License $license, LicenseSite $site): string
    {
        return md5($license->license_key . $site->site_url . time() . '_' . wp_generate_uuid4());
    }

    public static function licensesKey(array $data): string
    {
        $defaults = [
            'product_id' => '',
            'variation_id' => '',
            'order_id' => '',
            'customer_id' => ''
        ];

        $data = wp_parse_args($data, $defaults);

        $lisenseSettings = LicenseHelper::getProductLicenseConfig($data['product_id']);

        $key = Arr::get($lisenseSettings, 'prefix', '') . md5(Arr::get($data, 'product_id') . '_' . Arr::get($data, 'variation_id') . Arr::get($data, 'order_id') . '_' . Arr::get($data, 'customer_id') . '_' . wp_generate_uuid4());

        return apply_filters('fluent_cart_sl/generate_license_key', $key, [
            'data' => $data
        ]);
    }



}