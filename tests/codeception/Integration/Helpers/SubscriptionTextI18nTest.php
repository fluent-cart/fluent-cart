<?php

namespace Tests\Integration\Helpers;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Services\Translations\TransStrings;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Storefront subscription wording must reach translators as whole sentences
 * with plural forms, so a translated storefront keeps no English from code.
 */
class SubscriptionTextI18nTest extends WPTestCase
{
    private $gettextFilter;
    private $contextFilter;

    public function tearDown(): void
    {
        if ($this->gettextFilter) {
            remove_filter('gettext_fluent-cart', $this->gettextFilter, 10);
        }
        if ($this->contextFilter) {
            remove_filter('gettext_with_context_fluent-cart', $this->contextFilter, 10);
        }
        remove_all_filters('fluent_cart/date_time_strings');
        TransStrings::dateTimeStrings(true);
        parent::tearDown();
    }

    private function translate(array $map): void
    {
        $this->gettextFilter = static function ($translation, $text) use ($map) {
            return $map[$text] ?? $translation;
        };
        add_filter('gettext_fluent-cart', $this->gettextFilter, 10, 2);
    }

    public function test_term_text_english_output_is_unchanged(): void
    {
        $text = Helper::getSubscriptionTermText([
            'trial_days'       => 30,
            'interval'         => 'monthly',
            'times'            => 0,
            'price'            => '$100.00',
            'signup_fee'       => '$10.00',
            'signup_fee_label' => 'Setup Fee',
        ]);

        $this->assertSame('30 days free then $100.00 per month + $10.00 one-time Setup Fee', $text);
    }

    public function test_term_text_trial_and_signup_fee_are_single_reorderable_sentences(): void
    {
        $this->translate([
            '%1$d %2$s free then %3$s'   => '[%3$s after %1$d %2$s]',
            '%1$s + %2$s one-time %3$s' => '%3$s %2$s | %1$s',
        ]);

        $text = Helper::getSubscriptionTermText([
            'trial_days'       => 7,
            'interval'         => 'yearly',
            'times'            => 0,
            'price'            => '$50.00',
            'signup_fee'       => '$5.00',
            'signup_fee_label' => 'Setup Fee',
        ]);

        $this->assertSame('Setup Fee $5.00 | [$50.00 per year after 7 days]', $text);
    }

    public function test_installment_count_uses_plural_interval_unit(): void
    {
        $info = Helper::generateSubscriptionInfo(['repeat_interval' => 'monthly', 'times' => 4], 1000, 'USD');
        $this->assertStringEndsWith('per month, for 4 months', $info);

        $info = Helper::generateSubscriptionInfo(['repeat_interval' => 'yearly', 'times' => 0], 1000, 'USD');
        $this->assertStringEndsWith('per year until cancel', $info);
    }

    public function test_trial_info_has_singular_and_plural_forms(): void
    {
        $this->assertSame('Free Trial: 1 day', Helper::generateTrialInfo(['trial_days' => 1]));
        $this->assertSame('Free Trial: 14 days', Helper::generateTrialInfo(['trial_days' => 14]));
    }

    public function test_schedule_anchor_ordinal_is_translatable(): void
    {
        $monthly = Helper::generateScheduleSubscriptionInfo(
            ['period' => 'month', 'interval' => 1, 'anchor' => ['day' => 2]],
            ['times' => 0],
            1000,
            'USD'
        );
        $this->assertStringContainsString('per month on the 2nd until cancel', $monthly);

        $this->contextFilter = static function ($translation, $text, $context) {
            if ($context === 'ordinal day of month') {
                return '%d.';
            }
            return $translation;
        };
        add_filter('gettext_with_context_fluent-cart', $this->contextFilter, 10, 3);

        $monthly = Helper::generateScheduleSubscriptionInfo(
            ['period' => 'month', 'interval' => 1, 'anchor' => ['day' => 2]],
            ['times' => 0],
            1000,
            'USD'
        );
        $this->assertStringContainsString('on the 2. until cancel', $monthly);
    }

    public function test_schedule_anchor_month_day_follows_store_date_format(): void
    {
        $yearly = function ($month, $day) {
            return Helper::generateScheduleSubscriptionInfo(
                ['period' => 'year', 'interval' => 1, 'anchor' => ['month' => $month, 'day' => $day]],
                ['times' => 0],
                1000,
                'USD'
            );
        };

        $this->assertStringContainsString('on Aug 19 until cancel', $yearly(8, 19));

        add_filter('fluent_cart/date_time_strings', function ($strings) {
            $strings['formats']['date'] = 'j. F Y';
            return $strings;
        });
        TransStrings::dateTimeStrings(true);

        $this->assertStringContainsString('on 19. Aug until cancel', $yearly(8, 19));
        $this->assertStringContainsString('on 29. Feb until cancel', $yearly(2, 29));
    }
}
