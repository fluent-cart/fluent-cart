<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Modules\PaymentMethods\StripeGateway\StripeSettingsBase;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * `submit_type` reaches Stripe verbatim as a Checkout Session parameter, and
 * PaymentMethodController::store() hands the gateway the request body without
 * filtering it. An unrecognised value is a 400 on a live checkout, so both the
 * write and the read have to reject anything outside Stripe's enum.
 *
 * These exercise the pure validation only — nothing here writes the store's
 * real gateway settings row.
 */
class StripeSubmitTypeSettingTest extends WPTestCase
{
    /**
     * @param string|null $stored
     * @return StripeSettingsBase
     */
    private function withStored($stored)
    {
        $settings = new StripeSettingsBase();
        $current = $settings->settings;

        if ($stored === null) {
            unset($current['submit_type']);
        } else {
            $current['submit_type'] = $stored;
        }

        $settings->settings = $current;

        return $settings;
    }

    public function test_default_is_auto(): void
    {
        $defaults = StripeSettingsBase::getDefaults();

        $this->assertSame('auto', $defaults['submit_type']);
    }

    /**
     * 'auto' is our stored spelling for "send nothing" — Stripe then applies
     * pay for a payment-mode session and subscribe for a subscription one.
     */
    public function test_auto_sends_nothing(): void
    {
        $this->assertNull($this->withStored('auto')->getSubmitType());
    }

    public function test_missing_value_sends_nothing(): void
    {
        $this->assertNull($this->withStored(null)->getSubmitType());
    }

    public function test_every_stripe_enum_value_is_passed_through(): void
    {
        foreach (['pay', 'book', 'donate', 'subscribe'] as $value) {
            $this->assertSame(
                $value,
                $this->withStored($value)->getSubmitType(),
                $value . ' is a documented Checkout Session submit_type'
            );
        }
    }

    /**
     * `fluent_cart/stripe_settings` can rewrite the value after it was stored,
     * so the read is validated too rather than trusting the write.
     */
    public function test_unknown_value_sends_nothing_rather_than_a_400(): void
    {
        $this->assertNull($this->withStored('checkout')->getSubmitType());
        $this->assertNull($this->withStored('')->getSubmitType());
        $this->assertNull($this->withStored('PAY')->getSubmitType());
    }

    public function test_the_settings_field_offers_exactly_the_supported_values(): void
    {
        $this->assertSame(
            ['auto', 'pay', 'book', 'donate', 'subscribe'],
            StripeSettingsBase::SUBMIT_TYPES
        );
    }
}
