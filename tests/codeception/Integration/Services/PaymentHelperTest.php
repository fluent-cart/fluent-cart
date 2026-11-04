<?php

namespace Tests\Integration\Services;

use FluentCart\App\Services\Payments\PaymentHelper;
use lucatume\WPBrowser\TestCase\WPTestCase;

class PaymentHelperTest extends WPTestCase
{
    // =========================================================================
    // Spec 149: listenerUrl matches the WebRoutes dispatch contract
    // (fluent-cart=fct_payment_listener_ipn + method={slug}) so the IPN
    // listener shown to merchants/gateways is actually routable.
    // =========================================================================

    public function test_listener_url_contains_listener_and_method(): void
    {
        $result = (new PaymentHelper('offline_payment'))->listenerUrl();

        $this->assertIsArray($result);
        $this->assertStringContainsString('fluent-cart=fct_payment_listener_ipn', $result['listener_url']);
        $this->assertStringContainsString('method=offline_payment', $result['listener_url']);
    }

    // =========================================================================
    // Spec 150: listener_url applies filter
    // =========================================================================

    public function test_listener_url_applies_filter(): void
    {
        add_filter('fluent_cart/ipn_url_offline_payment', function ($data) {
            $data['listener_url'] = 'https://overridden.example/listener';
            return $data;
        });

        $result = (new PaymentHelper('offline_payment'))->listenerUrl();

        remove_all_filters('fluent_cart/ipn_url_offline_payment');
        $this->assertSame('https://overridden.example/listener', $result['listener_url']);
    }

    // =========================================================================
    // Spec 151: successUrl includes method, trx_hash, fct_redirect
    // =========================================================================

    public function test_success_url_includes_expected_query_params(): void
    {
        $url = (new PaymentHelper('offline_payment'))->successUrl('my-trx-hash');

        $this->assertStringContainsString('method=offline_payment', $url);
        $this->assertStringContainsString('trx_hash=my-trx-hash', $url);
        $this->assertStringContainsString('fct_redirect=yes', $url);
    }

    // =========================================================================
    // Spec 152: Falls back to site_url when receipt page empty
    // =========================================================================

    public function test_success_url_falls_back_to_site_url(): void
    {
        $url = (new PaymentHelper('offline_payment'))->successUrl('fallback-hash');
        $this->assertStringContainsString(site_url(), $url);
    }

    // =========================================================================
    // Spec 153: successUrl applies filter
    // =========================================================================

    public function test_success_url_applies_filter(): void
    {
        add_filter('fluent_cart/payment/success_url', function () {
            return 'https://override.example/success';
        });

        $url = (new PaymentHelper('offline_payment'))->successUrl('hash');

        remove_all_filters('fluent_cart/payment/success_url');
        $this->assertSame('https://override.example/success', $url);
    }

    /**
     * Spec: Cases/payment-success-url.md [4]
     *   "The success URL filter context carries no transaction or order when the uuid is unknown."
     */
    public function test_success_url_filter_context_is_null_for_unknown_uuid(): void
    {
        $captured = null;
        add_filter('fluent_cart/payment/success_url', function ($url, $context) use (&$captured) {
            $captured = $context;
            return $url;
        }, 10, 2);

        (new PaymentHelper('offline_payment'))->successUrl('no-such-transaction-uuid');

        remove_all_filters('fluent_cart/payment/success_url');

        $this->assertIsArray($captured, 'Filter must receive a context array');
        $this->assertArrayHasKey('transaction', $captured, 'Context must expose a transaction key even when unknown');
        $this->assertArrayHasKey('order', $captured, 'Context must expose an order key even when unknown');
        $this->assertNull($captured['transaction'], 'Unknown uuid must yield a null transaction');
        $this->assertNull($captured['order'], 'Unknown uuid must yield a null order');
    }

    // =========================================================================
    // Spec 157: parsePaymentMethodDetails — Stripe card
    // =========================================================================

    public function test_parse_payment_method_details_stripe_card(): void
    {
        $parsed = PaymentHelper::parsePaymentMethodDetails('stripe', [
            'type' => 'card',
            'id'   => 'pm_1',
            'card' => [
                'brand'      => 'visa',
                'last4'      => '4242',
                'exp_month'  => '12',
                'exp_year'   => '2030',
                'fingerprint'=> 'fp123',
            ],
            'billing_details' => [
                'name'  => 'John',
                'email' => 'john@example.com',
            ],
        ]);

        $this->assertSame('stripe', $parsed['method']);
        $this->assertSame('card', $parsed['type']);
        $this->assertSame('visa', $parsed['details']['brand']);
        $this->assertSame('4242', $parsed['details']['last_4']);
        $this->assertSame('12', $parsed['details']['exp_month']);
        $this->assertSame('2030', $parsed['details']['exp_year']);
    }

    // =========================================================================
    // Spec 158: parsePaymentMethodDetails — PayPal standard
    // =========================================================================

    public function test_parse_payment_method_details_paypal(): void
    {
        $parsed = PaymentHelper::parsePaymentMethodDetails('paypal', [
            'email'    => 'payer@example.com',
            'payer_id' => 'PAYER123',
        ]);

        $this->assertSame('paypal', $parsed['method']);
        $this->assertSame('standard', $parsed['type']);
        $this->assertSame('PAYER123', $parsed['details']['payer_id']);
        $this->assertSame('payer@example.com', $parsed['details']['email']);
    }

    // =========================================================================
    // Spec 159-163: getIntervalDays mappings
    // =========================================================================

    public function test_get_interval_days_yearly(): void
    {
        $this->assertSame(365, PaymentHelper::getIntervalDays('yearly'));
    }

    public function test_get_interval_days_monthly(): void
    {
        $this->assertSame((int) gmdate('t'), PaymentHelper::getIntervalDays('monthly'));
    }

    public function test_get_interval_days_weekly(): void
    {
        $this->assertSame(7, PaymentHelper::getIntervalDays('weekly'));
    }

    public function test_get_interval_days_quarterly(): void
    {
        $this->assertSame(90, PaymentHelper::getIntervalDays('quarterly'));
    }

    public function test_get_interval_days_half_yearly(): void
    {
        $this->assertSame(182, PaymentHelper::getIntervalDays('half_yearly'));
    }

    // =========================================================================
    // Spec 164: getIntervalDays filter
    // =========================================================================

    public function test_get_interval_days_applies_filter(): void
    {
        add_filter('fluent_cart/subscription_interval_in_days', function () {
            return 999;
        });

        $result = PaymentHelper::getIntervalDays('monthly');

        remove_all_filters('fluent_cart/subscription_interval_in_days');
        $this->assertSame(999, $result);
    }

    // =========================================================================
    // Spec 164a-164c: getIntervalDays daily and unresolved-interval contract
    // =========================================================================

    public function test_get_interval_days_daily(): void
    {
        $this->assertSame(1, PaymentHelper::getIntervalDays('daily'));
    }

    public function test_get_interval_days_unknown_interval_returns_zero(): void
    {
        $this->assertSame(0, PaymentHelper::getIntervalDays('fortnightly'));
    }

    public function test_get_interval_days_clamps_invalid_filter_result_to_zero(): void
    {
        add_filter('fluent_cart/subscription_interval_in_days', function () {
            return -5;
        });

        $result = PaymentHelper::getIntervalDays('monthly');

        remove_all_filters('fluent_cart/subscription_interval_in_days');
        $this->assertSame(0, $result);
    }
}
