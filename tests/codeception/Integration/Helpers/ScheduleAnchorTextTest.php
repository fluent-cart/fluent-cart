<?php

namespace Tests\Integration\Helpers;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Services\Translations\TransStrings;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * The billing anchor in the subscription term ("per year on Aug 16") must
 * follow the store date format and be fully translatable (ticket 3632).
 */
class ScheduleAnchorTextTest extends WPTestCase
{
    private $originalStoreSettings;
    private $originalDateFormat;

    public function setUp(): void
    {
        parent::setUp();
        $this->originalStoreSettings = get_option('fluent_cart_store_settings', []);
        $this->originalDateFormat    = get_option('date_format');
    }

    public function tearDown(): void
    {
        update_option('fluent_cart_store_settings', $this->originalStoreSettings);
        update_option('date_format', $this->originalDateFormat);
        remove_all_filters('gettext_with_context');
        StoreSettings::clearCache();
        TransStrings::dateTimeStrings(true);
        parent::tearDown();
    }

    private function useFormatSource(string $source): void
    {
        $settings = get_option('fluent_cart_store_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }
        $settings['date_time_format_source'] = $source;
        update_option('fluent_cart_store_settings', $settings);
        StoreSettings::clearCache();
        TransStrings::dateTimeStrings(true);
    }

    private function term(string $period, array $anchor): string
    {
        return (string) Helper::generateScheduleSubscriptionInfo(
            ['period' => $period, 'interval' => 1, 'anchor' => $anchor],
            [],
            1000,
            'USD'
        );
    }

    public function test_yearly_anchor_keeps_the_legacy_text_on_the_fluent_cart_format_source(): void
    {
        $this->useFormatSource('fluent_cart');

        $this->assertStringContainsString('per year on Aug 16', $this->term('year', ['month' => 8, 'day' => 16]));
    }

    public function test_yearly_anchor_follows_the_store_date_field_order(): void
    {
        update_option('date_format', 'j. F Y');
        $this->useFormatSource('wordpress');

        $this->assertStringContainsString('per year on 16. Aug', $this->term('year', ['month' => 8, 'day' => 16]));
    }

    public function test_yearly_anchor_accepts_february_29(): void
    {
        $this->useFormatSource('fluent_cart');

        $this->assertStringContainsString('on Feb 29', $this->term('year', ['month' => 2, 'day' => 29]));
    }

    /**
     * @dataProvider englishOrdinals
     */
    public function test_monthly_anchor_renders_english_ordinals(int $day, string $expected): void
    {
        $this->assertStringContainsString('per month on the ' . $expected, $this->term('month', ['day' => $day]));
    }

    public function englishOrdinals(): array
    {
        return [
            [1, '1st'],
            [2, '2nd'],
            [3, '3rd'],
            [4, '4th'],
            [11, '11th'],
            [12, '12th'],
            [13, '13th'],
            [21, '21st'],
            [22, '22nd'],
            [23, '23rd'],
        ];
    }

    public function test_monthly_ordinal_is_translatable(): void
    {
        add_filter('gettext_with_context', function ($translation, $text, $context, $domain) {
            if ($domain !== 'fluent-cart') {
                return $translation;
            }
            if ($context === 'ordinal day of month') {
                return '%d.';
            }
            if ($text === 'on the %1$s' && $context === 'subscription billing day') {
                return 'am %s';
            }
            return $translation;
        }, 10, 4);

        $this->assertStringContainsString('am 16.', $this->term('month', ['day' => 16]));
    }

    public function test_schedule_on_has_its_own_translation_context(): void
    {
        add_filter('gettext_with_context', function ($translation, $text, $context, $domain) {
            if ($domain === 'fluent-cart' && $text === 'on %1$s' && $context === 'subscription billing day') {
                return 'am %s';
            }
            return $translation;
        }, 10, 4);

        $this->assertStringContainsString('am Friday', $this->term('week', ['weekday' => 5]));
    }
}
