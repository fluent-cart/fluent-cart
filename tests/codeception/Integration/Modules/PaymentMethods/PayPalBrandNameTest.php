<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Modules\PaymentMethods\PayPalGateway\PayPalSettingsBase;
use lucatume\WPBrowser\TestCase\WPTestCase;

class PayPalBrandNameTest extends WPTestCase
{
    private function settingsWithBrand($brand): PayPalSettingsBase
    {
        $settings = new PayPalSettingsBase();
        $settings->settings['brand_name'] = $brand;

        return $settings;
    }

    public function test_empty_brand_name_sends_nothing()
    {
        $this->assertSame('', $this->settingsWithBrand('')->getBrandName());
        $this->assertSame('', $this->settingsWithBrand('   ')->getBrandName());
        $this->assertSame('', (new PayPalSettingsBase())->getBrandName(), 'Default must be empty so existing stores are unchanged.');
    }

    public function test_brand_name_is_trimmed_and_stripped_to_plain_text()
    {
        $this->assertSame('Acme Tea', $this->settingsWithBrand('  <b>Acme Tea</b> ')->getBrandName());
    }

    public function test_brand_name_is_capped_at_127_characters_without_splitting_multibyte_characters()
    {
        $name = $this->settingsWithBrand(str_repeat('é', 200))->getBrandName();

        $this->assertSame(127, mb_strlen($name));
        $this->assertTrue(mb_check_encoding($name, 'UTF-8'));
    }

    public function test_filter_can_override_the_brand_name_and_receives_context()
    {
        $received = null;
        $filter = function ($name, $context) use (&$received) {
            $received = $context;
            return 'Filtered Brand';
        };
        add_filter('fluent_cart/paypal/brand_name', $filter, 10, 2);

        $name = $this->settingsWithBrand('Saved Brand')->getBrandName(['order' => 'o']);

        remove_filter('fluent_cart/paypal/brand_name', $filter, 10);

        $this->assertSame('Filtered Brand', $name);
        $this->assertSame(['order' => 'o'], $received);
    }

    public function test_filter_result_is_also_capped()
    {
        $filter = function () {
            return str_repeat('a', 300);
        };
        add_filter('fluent_cart/paypal/brand_name', $filter);

        $name = $this->settingsWithBrand('')->getBrandName();

        remove_filter('fluent_cart/paypal/brand_name', $filter);

        $this->assertSame(127, strlen($name));
    }
}
