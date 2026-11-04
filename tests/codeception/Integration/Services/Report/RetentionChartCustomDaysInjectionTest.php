<?php

namespace Tests\Integration\Services\Report;

use FluentCart\App\Services\Report\ReportHelper;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Security regression: the user-supplied `customDays` param once reached a raw
 * SELECT alias, and ReportHelper::sanitizeParams() declared no rule for it.
 * The 'customDays' => 'intval' rule must keep neutralising it at the entry point.
 */
class RetentionChartCustomDaysInjectionTest extends WPTestCase
{
    /**
     * A classic column-injection payload: closes the intended `day_` alias with a
     * digit, then smuggles a second reflected column that reads wp_users.
     */
    private const INJECTION_PAYLOAD = "1,(SELECT CONCAT(user_login,0x3a,user_pass) FROM wp_users LIMIT 1) AS pwned";

    /**
     * Defense-in-depth: the sanitize rule must neutralise customDays before it
     * ever reaches any sink. processParams() is the real controller entry point.
     */
    public function test_sanitize_params_casts_custom_days_to_integer(): void
    {
        $processed = ReportHelper::processParams(
            [
                'customDays' => self::INJECTION_PAYLOAD,
                'startDate'  => '2020-01-01 00:00:00',
                'endDate'    => '2030-01-01 23:59:59',
            ],
            ['customDays']
        );

        $this->assertSame(1, $processed['customDays'], 'sanitizeParams() must intval customDays, stripping the injected SQL.');
    }

    /**
     * A legitimate integer window survives sanitisation unchanged.
     */
    public function test_sanitize_params_preserves_a_valid_integer_custom_days(): void
    {
        $processed = ReportHelper::processParams(
            [
                'customDays' => '30',
                'startDate'  => '2020-01-01 00:00:00',
                'endDate'    => '2030-01-01 23:59:59',
            ],
            ['customDays']
        );

        $this->assertSame(30, $processed['customDays'], 'A valid integer customDays must pass through as an int.');
    }
}
