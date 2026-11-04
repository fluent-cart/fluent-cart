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

        // Without both keys no widget can issue or verify a token, so requiring one would refuse every order.
        if (!self::isConfigured($turnstileSettings)) {
            return $isValid;
        }

        $turnstileToken = Arr::get($data, 'cf_turnstile_token', '');

        if (empty($turnstileToken)) {
            return new \WP_Error(
                'turnstile_missing',
                __('Security check failed. Please refresh the page and try again.', 'fluent-cart')
            );
        }

        $outcome = $this->verifyToken($turnstileToken, $turnstileSettings);

        if ($outcome === 'unreachable') {
            /**
             * Allow checkout when the server cannot reach Cloudflare to verify the token.
             * Off by default: an outage would otherwise switch bot protection off.
             *
             * @param bool  $allow
             * @param array $context ['data' => array] The checkout data.
             */
            if (apply_filters('fluent_cart/turnstile/allow_when_unreachable', false, ['data' => $data])) {
                return $isValid;
            }

            return new \WP_Error(
                'turnstile_unreachable',
                __('We could not complete the security check right now. Please try again in a minute.', 'fluent-cart')
            );
        }

        if ($outcome !== 'valid') {
            return new \WP_Error(
                'turnstile_invalid',
                __('The security check was not accepted. Please refresh the page and try again.', 'fluent-cart')
            );
        }

        return $isValid;
    }

    /**
     * @param array $turnstileSettings
     * @return bool
     */
    public static function isConfigured($turnstileSettings)
    {
        return Arr::get($turnstileSettings, 'active', 'no') === 'yes'
            && !empty(Arr::get($turnstileSettings, 'site_key', ''))
            && !empty(Arr::get($turnstileSettings, 'secret_key', ''));
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
        return $this->verifyToken($token, $turnstileSettings) === 'valid';
    }

    /**
     * @param string $token
     * @param array $turnstileSettings
     * @return string 'valid', 'invalid', or 'unreachable' when Cloudflare could not answer
     */
    public function verifyToken($token, $turnstileSettings)
    {
        $secretKey = Arr::get($turnstileSettings, 'secret_key', '');
        if (empty($secretKey)) {
            return 'invalid';
        }

        // Cloudflare's documented way to retry siteverify without spending the token twice.
        $body = [
            'secret'          => $secretKey,
            'response'        => $token,
            'remoteip'        => AddressHelper::getIpAddress(),
            'idempotency_key' => wp_generate_uuid4()
        ];

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [ // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- server-side Turnstile token verification
                'body'    => $body,
                'timeout' => 4
            ]);

            if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) < 500) {
                break;
            }
        }

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) >= 500) {
            $this->logUnreachable(is_wp_error($response)
                ? $response->get_error_message()
                : 'HTTP ' . wp_remote_retrieve_response_code($response));
            return 'unreachable';
        }

        $result = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($result['success']) && $result['success'] === true) {
            return 'valid';
        }

        $this->logRejected((array) Arr::get((array) $result, 'error-codes', []));

        return 'invalid';
    }

    private function logRejected($errorCodes)
    {
        $errorCodes = array_map('sanitize_key', $errorCodes);
        sort($errorCodes);
        $reason = $errorCodes ? implode(', ', $errorCodes) : 'unknown';

        // Bots trip this on every attempt; one entry per reason an hour is enough to diagnose a bad key.
        $throttleKey = 'fluent_cart_turnstile_rejected_' . substr(sanitize_key(str_replace(', ', '_', $reason)), 0, 100);
        if (get_transient($throttleKey)) {
            return;
        }
        set_transient($throttleKey, 1, HOUR_IN_SECONDS);

        fluent_cart_warning_log(
            'Turnstile token rejected',
            sprintf(
                /* translators: %s: Cloudflare error codes */
                __('Cloudflare refused a checkout security token (%s).', 'fluent-cart'),
                esc_html($reason)
            ),
            ['module_name' => 'activity']
        );
    }

    private function logUnreachable($reason)
    {
        // One entry per outage window, not one per checkout attempt.
        if (get_transient('fluent_cart_turnstile_unreachable_logged')) {
            return;
        }
        set_transient('fluent_cart_turnstile_unreachable_logged', 1, 10 * MINUTE_IN_SECONDS);

        fluent_cart_warning_log(
            __('Turnstile could not reach Cloudflare', 'fluent-cart'),
            sprintf(
                /* translators: %s: the connection error */
                __('Checkout security verification failed because Cloudflare could not be reached (%s). Orders are refused until it is reachable again.', 'fluent-cart'),
                esc_html($reason)
            ),
            ['module_name' => 'activity']
        );
    }
}
