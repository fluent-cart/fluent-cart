<?php

namespace FluentCartPro\App\Modules\Licensing\Hooks\Handlers;

use FluentCartPro\App\Modules\Licensing\Models\License;

class ManualLicenseRenewalHandler
{
    public function register()
    {
        add_action('fluent_cart_action_renew_license', [$this, 'handleManualLicenseRenewalRedirect'], 10, 1);
    }

    public function handleManualLicenseRenewalRedirect($data = [])
    {
        $defaults = [
            'license_key' => ''
        ];

        $data = wp_parse_args($data, $defaults);

        $key = sanitize_text_field($data['license_key']);

        if (empty($key)) {
            $this->showError(__('License key is required.', 'fluentcart-pro'));
        }

        $license = License::query()->where('license_key', $key)->first();

        if (!$license) {
            $this->showError(__('License could not be found.', 'fluentcart-pro'));
        }

        if (!$license->isExpired()) {
            $this->showError(__('Sorry, you can not renew this active license.', 'fluentcart-pro'));
        }

        $renewUrl = $license->getRenewalUrl();

        if(empty($renewUrl)) {
            $this->showError(__('Renewal URL is not available for this license.', 'fluentcart-pro'));
        }

        wp_redirect($renewUrl);
        exit;
    }

    private function showError($message)
    {
        die($message);
    }
}
