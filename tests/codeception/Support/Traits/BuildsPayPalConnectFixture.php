<?php

namespace Tests\Support\Traits;

use FluentCart\App\App;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\PayPalSettingsBase;

trait BuildsPayPalConnectFixture
{
    private array $originalPayPalConnectSettings = [];
    private array $payPalConnectRequests = [];
    private array $payPalConnectRedirects = [];
    private $payPalConnectHttpFilter;
    private $payPalConnectRedirectFilter;

    protected function installPayPalConnectFixture(): void
    {
        $this->originalPayPalConnectSettings = App::gateway('paypal')->settings->get();
        $settings = array_merge(PayPalSettingsBase::getDefaults(), [
            'live_email_address' => 'merchant@example.test',
            'live_account_status' => 'BUSINESS_ACCOUNT',
            'live_account_id' => 'MERCHANT-LIVE',
            'live_client_id' => 'fixture-live-client',
            'live_client_secret' => 'fixture-live-secret',
            'test_account_id' => 'MERCHANT-TEST',
            'test_client_id' => 'fixture-test-client',
            'test_client_secret' => 'fixture-test-secret',
        ]);
        fluent_cart_update_option('fluent_cart_payment_settings_paypal', $settings);
        App::gateway('paypal')->settings->settings = $settings;

        $this->payPalConnectRequests = [];
        $this->payPalConnectRedirects = [];
        $this->payPalConnectHttpFilter = function ($preempt, $args, $url) {
            $this->payPalConnectRequests[] = ['url' => $url, 'args' => $args];
            $connectApi = App::config()->get('paypal.connect_api');
            if (!$connectApi || strpos($url, $connectApi) !== 0) {
                return new \WP_Error('unexpected_http', 'Unexpected external request during PayPal connection test.');
            }
            return [
                'response' => ['code' => 200],
                'body' => wp_json_encode(['links' => [[
                    'rel' => 'action_url',
                    'href' => 'https://www.sandbox.paypal.com/onboarding/fixture',
                ]]]),
                'headers' => [],
                'cookies' => [],
            ];
        };
        $this->payPalConnectRedirectFilter = function ($location) {
            $this->payPalConnectRedirects[] = $location;
            return false;
        };
        add_filter('pre_http_request', $this->payPalConnectHttpFilter, 999, 3);
        add_filter('wp_redirect', $this->payPalConnectRedirectFilter);
    }

    protected function restorePayPalConnectFixture(): void
    {
        App::gateway('paypal')->settings->settings = $this->originalPayPalConnectSettings;
        remove_filter('pre_http_request', $this->payPalConnectHttpFilter, 999);
        remove_filter('wp_redirect', $this->payPalConnectRedirectFilter);
        StoreSettings::clearCache();
        wp_set_current_user(0);
    }

    protected function readPayPalConnectSettings(): array
    {
        return fluent_cart_get_option('fluent_cart_payment_settings_paypal', [], false);
    }
}
