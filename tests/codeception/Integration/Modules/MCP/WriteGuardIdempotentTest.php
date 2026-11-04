<?php

namespace Tests\Integration\Modules\MCP;

use FluentCart\App\Modules\MCP\Support\WriteGuard;
use lucatume\WPBrowser\TestCase\WPTestCase;

class WriteGuardIdempotentTest extends WPTestCase
{
    public function test_unrecorded_refund_is_replayed_without_running_again(): void
    {
        $calls = 0;
        $refund = function () use (&$calls) {
            $calls++;
            return new \WP_Error('refund_not_recorded', 'Stripe refunded $30.00, but FluentCart could not record it.', [
                'vendor_refund_id' => 're_x',
                'amount'           => 3000,
            ]);
        };

        $first = WriteGuard::idempotent('refund-order', 'order:7', 'key-unrecorded', $refund);
        wp_cache_flush();
        $second = WriteGuard::idempotent('refund-order', 'order:7', 'key-unrecorded', $refund);

        $this->assertSame(1, $calls);
        $this->assertInstanceOf(\WP_Error::class, $second);
        $this->assertSame('refund_not_recorded', $second->get_error_code());
        $this->assertSame($first->get_error_message(), $second->get_error_message());
        $this->assertSame(['vendor_refund_id' => 're_x', 'amount' => 3000], $second->get_error_data());
    }

    public function test_other_errors_are_not_cached(): void
    {
        $calls = 0;
        $refund = function () use (&$calls) {
            $calls++;
            return new \WP_Error('remote_refund_failed', 'Card declined.');
        };

        WriteGuard::idempotent('refund-order', 'order:8', 'key-failed', $refund);
        WriteGuard::idempotent('refund-order', 'order:8', 'key-failed', $refund);

        $this->assertSame(2, $calls);
    }
}
