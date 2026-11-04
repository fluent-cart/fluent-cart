<?php

namespace Tests\Integration\Modules\Integrations;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Modules\Integrations\FluentPlugins\FluentSupportWidget;
use FluentCart\App\Services\Translations\TransStrings;
use lucatume\WPBrowser\TestCase\WPTestCase;

class FluentSupportWidgetTest extends WPTestCase
{
    // Spec 74: register() hooks fluent_support/customer_extra_widgets
    public function test_register_binds_fluent_support_filter(): void
    {
        (new FluentSupportWidget())->register();
        $this->assertNotFalse(has_filter('fluent_support/customer_extra_widgets'));
    }

    // Spec 75: unknown customer email → widgets unchanged
    public function test_get_purchase_widgets_returns_unchanged_when_no_customer(): void
    {
        $widget = new FluentSupportWidget();
        $phantom = (object) ['email' => 'no-cust-' . wp_generate_password(6, false) . '@example.com'];
        $out = $widget->getPurchaseWidgets(['existing' => 'w'], $phantom);
        $this->assertSame(['existing' => 'w'], $out);
    }

    // Spec 76: matching Customer → adds fluent_cart_purchases widget
    public function test_get_purchase_widgets_adds_purchases_widget_on_match(): void
    {
        $customer = (new \Tests\Support\Factory\CustomerFactory())->create();
        $fsCustomer = (object) ['email' => $customer->email];
        $out = (new FluentSupportWidget())->getPurchaseWidgets([], $fsCustomer);

        $this->assertArrayHasKey('fluent_cart_purchases', $out);
        $this->assertSame('Purchases', $out['fluent_cart_purchases']['header']);
        $this->assertStringContainsString('fc_full_listed', (string) $out['fluent_cart_purchases']['body_html']);
    }

    // No order context here: the default 'fluent_cart' timezone source must
    // render the site day, not fall back to UTC (ticket 3632).
    public function test_get_purchase_widgets_renders_dates_in_site_timezone(): void
    {
        $originalSettings = get_option('fluent_cart_store_settings', []);
        $originalTimezone = get_option('timezone_string');
        $originalFormat   = get_option('date_format');

        $settings = is_array($originalSettings) ? $originalSettings : [];
        $settings['timezone_source']         = 'fluent_cart';
        $settings['date_time_format_source'] = 'wordpress';
        update_option('fluent_cart_store_settings', $settings);
        update_option('timezone_string', 'Asia/Dhaka');
        update_option('date_format', 'Y-m-d');
        StoreSettings::clearCache();
        TransStrings::dateTimeStrings(true);

        try {
            // 20:30 UTC is already the next day in Dhaka (UTC+6).
            $customer = (new \Tests\Support\Factory\CustomerFactory())->create([
                'purchase_count'      => 1,
                'first_purchase_date' => '2026-07-30 20:30:00',
                'last_purchase_date'  => '2026-07-30 20:30:00',
            ]);

            $out  = (new FluentSupportWidget())->getPurchaseWidgets([], (object) ['email' => $customer->email]);
            $html = (string) $out['fluent_cart_purchases']['body_html'];

            $this->assertStringContainsString('2026-07-31', $html);
            $this->assertStringNotContainsString('2026-07-30', $html);
        } finally {
            update_option('fluent_cart_store_settings', $originalSettings);
            update_option('timezone_string', $originalTimezone);
            update_option('date_format', $originalFormat);
            StoreSettings::clearCache();
            TransStrings::dateTimeStrings(true);
        }
    }
}
