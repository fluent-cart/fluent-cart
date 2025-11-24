<?php

namespace FluentCartPro\App\Modules\Licensing\Services;

use FluentCart\Framework\Support\Arr;

class LicenseLog
{
    public function register()
    {
        add_action('fluent_cart_sl/site_license_deactivated', [$this, 'licenseDeactivated'], 10, 1);
        add_action('fluent_cart_sl/license_key_generated', [$this, 'licenseGenerated'], 10, 1);
        add_action('fluent_cart_sl/license_issued', [$this, 'licenseGenerated'], 10, 1);
    }

    public function log($title, $license_key, $data = [])
    {
        if (!function_exists('fluent_cart_success_log')) {
            return;
        }
        $otherInfo = [
            'module_name' => 'Order',
            'module_id' => Arr::get($data, 'order_id')
        ];

        $license_key = substr($license_key, 0, 8) . '******';
        $content = 'License (' . $license_key .') issued successfully!';
        fluent_cart_success_log($title, $content, $otherInfo);
    }

    public function licenseDeactivated($data)
    {
        $site = Arr::get($data, 'site', '');
        $license = Arr::get($data, 'license', '');
        fluent_cart_add_log('License Deactivated', $license->license_key, [
            'site' => $site,
            'license' => $license->license_key
        ]);
    }

    public function licenseGenerated($data)
    {
        $license = Arr::get($data, 'license', '');
        $data = Arr::get($data, 'data', []);
        $this->log('License Generated', $license->license_key, $data);
    }

}