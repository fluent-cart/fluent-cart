<?php

namespace Tests\Integration\Services;

use FluentCart\App\Services\RateLimiter;
use Tests\Support\DatabaseTestCase;

class RateLimiterTest extends DatabaseTestCase
{
    // =========================================================================
    // Spec 179: Allows up to $limit requests
    // =========================================================================

    public function test_is_spamming_allows_within_limit(): void
    {
        if (!wp_using_ext_object_cache()) {
            // Without ext object cache, rate limiting is disabled
            $uniqueKey = 'test_within_' . uniqid('', true);
            $this->assertFalse(RateLimiter::isSpamming($uniqueKey, 5, 60));
            return;
        }

        // With ext object cache, first requests should be allowed
        $uniqueKey = 'test_within_' . uniqid('', true);
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse(RateLimiter::isSpamming($uniqueKey, 5, 60));
        }
    }

    // =========================================================================
    // Spec 180: isActive returns false without ext object cache
    // =========================================================================

    public function test_is_active_reflects_ext_object_cache(): void
    {
        $expected = wp_using_ext_object_cache();
        $this->assertSame($expected, RateLimiter::isActive());
    }

    // =========================================================================
    // Spec 180a-180b (SCAN-08): the limiter must also count without an
    // external object cache, and parallel hits must not slip past the limit.
    // =========================================================================

    public function test_is_spamming_blocks_the_request_over_the_limit_without_ext_object_cache(): void
    {
        if (wp_using_ext_object_cache()) {
            $this->markTestSkipped('This case covers the stores that have no external object cache.');
        }

        $uniqueKey = 'test_over_' . uniqid('', true);
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse(RateLimiter::isSpamming($uniqueKey, 5, 60), 'request ' . ($i + 1) . ' is within the limit of 5');
        }

        $this->assertTrue(RateLimiter::isSpamming($uniqueKey, 5, 60), 'the 6th request in the window must be refused even without an object cache');
    }

    public function test_is_spamming_fails_closed_while_a_parallel_hit_holds_the_lock(): void
    {
        global $wpdb;
        $uniqueKey = 'test_lock_' . uniqid('', true);
        $identifier = md5(\FluentCart\App\Helpers\AddressHelper::getIpAddress()) . '_' . $uniqueKey;

        // Another request from the same visitor is updating the hits right now.
        $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $other->get_var($other->prepare('SELECT GET_LOCK(%s, %d)', 'fct_rate_' . md5($identifier), 0));

        try {
            $whileBusy = RateLimiter::isSpamming($uniqueKey, 5, 60);
        } finally {
            $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', 'fct_rate_' . md5($identifier)));
            $other->close();
        }

        $this->assertTrue($whileBusy, 'a hit that cannot update the count must be treated as over the limit');
        $this->assertFalse(RateLimiter::isSpamming($uniqueKey, 5, 60), 'once the parallel hit is done, the next request counts normally');
    }

    public function test_is_spamming_blocks_the_request_over_the_limit_with_ext_object_cache(): void
    {
        $was = wp_using_ext_object_cache();
        wp_using_ext_object_cache(true);

        try {
            $uniqueKey = 'test_cache_' . uniqid('', true);
            for ($i = 0; $i < 5; $i++) {
                $this->assertFalse(RateLimiter::isSpamming($uniqueKey, 5, 60), 'request ' . ($i + 1) . ' is within the limit of 5');
            }
            $this->assertTrue(RateLimiter::isSpamming($uniqueKey, 5, 60), 'the 6th request must be refused on the object cache branch too');
        } finally {
            wp_using_ext_object_cache($was);
        }
    }
}
