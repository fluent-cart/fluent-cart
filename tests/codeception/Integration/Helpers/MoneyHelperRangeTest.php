<?php

namespace Tests\Integration\Helpers;

use FluentCart\App\Helpers\Helper;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Lives in Integration rather than Unit because the rejection message goes
 * through esc_html(), which needs WordPress loaded.
 */
class MoneyHelperRangeTest extends WPTestCase
{
    public function test_round_cent_rejects_magnitudes_beyond_exact_float_integers(): void
    {
        // Above 2^53 a float cannot hold every integer and an (int) cast wraps into a
        // negative BIGINT that MySQL would accept silently. No real cents amount comes
        // near this, so reject loudly instead of coercing.
        $rejected = [
            [1e19, 'a float beyond PHP_INT_MAX must throw, not wrap into a negative BIGINT'],
            [1e100, 'a float beyond every integer type must throw, not coerce'],
            [-1e19, 'a hugely negative float must throw, not wrap'],
            ['1e19', 'exponential-notation strings pass is_numeric and must throw the same way'],
        ];

        foreach ($rejected as $case) {
            list($value, $why) = $case;
            try {
                Helper::roundCent($value);
                $this->fail($why . ' (supplied ' . var_export($value, true) . ', no exception thrown)');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('cents', $e->getMessage(), 'the rejection must say it is a cents-range violation');
            }
        }
    }
}
