<?php

namespace Tests\Unit\Services\Report;

use Codeception\Test\Unit;
use FluentCart\App\Services\Report\ReportHelper;

/**
 * Regression coverage for GitHub issue #22: a missing groupKey resolved to null
 * and was interpolated directly into raw SQL. ReportHelper::sanitizeGroupKey()
 * is the guard processParams() runs on every report request.
 */
class ReportHelperTest extends Unit
{
    public function test_sanitize_group_key_defaults_to_payment_method_for_null(): void
    {
        $this->assertSame(
            'payment_method',
            ReportHelper::sanitizeGroupKey(null),
            'A missing groupKey (null) must fall back to payment_method instead of reaching raw SQL as null'
        );
    }

    public function test_sanitize_group_key_defaults_to_payment_method_for_empty_string(): void
    {
        $this->assertSame(
            'payment_method',
            ReportHelper::sanitizeGroupKey(''),
            'An empty-string groupKey must fall back to payment_method'
        );
    }

    public function test_sanitize_group_key_defaults_to_payment_method_for_unrecognized_value(): void
    {
        $this->assertSame(
            'payment_method',
            ReportHelper::sanitizeGroupKey('nonsense'),
            'A groupKey outside the whitelist must fall back to payment_method rather than being used as-is'
        );
    }

    public function test_sanitize_group_key_defaults_to_payment_method_for_sql_injection_attempt(): void
    {
        $this->assertSame(
            'payment_method',
            ReportHelper::sanitizeGroupKey("o.id); DROP TABLE wp_users; --"),
            'A malicious groupKey must never pass through to the raw SQL builder unsanitized'
        );
    }

    /**
     * @dataProvider whitelistedGroupKeyProvider
     */
    public function test_sanitize_group_key_passes_through_whitelisted_values(string $value): void
    {
        $this->assertSame(
            $value,
            ReportHelper::sanitizeGroupKey($value),
            "Whitelisted groupKey '{$value}' must be returned unchanged"
        );
    }

    public function whitelistedGroupKeyProvider(): array
    {
        return [
            'billing_country'  => ['billing_country'],
            'shipping_country' => ['shipping_country'],
            'payment_method'   => ['payment_method'],
            'payment_status'   => ['payment_status'],
            'default'          => ['default'],
            // The line-chart selector's explicit Daily option: without this
            // whitelist entry the sanitizer rewrites 'daily' to
            // 'payment_method', which processGroup() still buckets daily but
            // getPeriodRange() zero-fills with yearly labels — desynced chart.
            'daily'            => ['daily'],
            'monthly'          => ['monthly'],
            'yearly'           => ['yearly'],
        ];
    }
}
