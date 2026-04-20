<?php

namespace FluentCart\App\Modules\Turnstile;

use FluentCart\Api\ModuleSettings;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Helpers\AddressHelper;

class TurnstileValidator
{
    public function register()
    {
        add_filter('fluent_cart/checkout/validate_before_process', [$this, 'validateCheckout'], 10, 2);
    }

    /**
     * Validate Turnstile token during checkout
     *
     * @param bool $isValid
     * @param array $data
     * @return bool|WP_Error
     */
    public function validateCheckout($isValid, $data)
    {
        // If validation already failed, don't proceed
        if (is_wp_error($isValid)) {
            return $isValid;
        }

        $turnstileSettings = ModuleSettings::getSettings('turnstile');
        
        // Only validate if Turnstile is active
        if (Arr::get($turnstileSettings, 'active', 'no') !== 'yes') {
            return $isValid;
        }

        $turnstileToken = Arr::get($data, 'cf_turnstile_token', '');

        if (empty($turnstileToken)) {
            return new \WP_Error(
                'turnstile_missing',
                __('Security check failed. Please refresh the page and try again.', 'fluent-cart')
            );
        }

        $isValidToken = $this->validateToken($turnstileToken, $turnstileSettings);
        
        if (!$isValidToken) {
            return new \WP_Error(
                'turnstile_invalid',
                __('Security check failed. Please try again or refresh the page.', 'fluent-cart')
            );
        }

        return $isValid;
    }

    /**
     * Validate Cloudflare Turnstile token
     *
     * @param string $token
     * @param array $turnstileSettings
     * @return bool
     */
    public function validateToken($token, $turnstileSettings)
    {
        $secretKey = Arr::get($turnstileSettings, 'secret_key', '');
        if (empty($secretKey)) {
            return false;
        }

        $ipAddress = AddressHelper::getIpAddress();
        
        $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'body' => [
                'secret'   => $secretKey,
                'response' => $token,
                'remoteip' => $ipAddress
            ],
            'timeout' => 10
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        return isset($result['success']) && $result['success'] === true;
    }
}

