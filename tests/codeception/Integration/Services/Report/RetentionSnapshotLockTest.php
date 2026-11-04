<?php

namespace Tests\Integration\Services\Report;

use FluentCart\App\Services\Report\RetentionSnapshotService;
use Tests\Support\DatabaseTestCase;

/**
 * Two snapshot rebuilds at once (a manual Generate and the queued rebuild) each emptied the table and wrote every row,
 * so the rows doubled. One rebuild runs at a time: an options-table lock, never a database lock (shared hosting).
 */
class RetentionSnapshotLockTest extends DatabaseTestCase
{
    const LOCK = 'fluent_cart_retention_snapshot_lock';

    protected function tearDown(): void
    {
        global $wpdb;
        delete_option(self::LOCK);
        $wpdb->query("DELETE FROM {$wpdb->prefix}fct_retention_snapshots");
        $wpdb->query("DELETE FROM {$wpdb->prefix}fct_retention_snapshots_build");
        parent::tearDown();
    }

    private function snapshotRows(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_retention_snapshots");
    }

    private function seedRow(): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'fct_retention_snapshots', [
            'cohort' => '2026-06', 'period' => '2026-07', 'product_id' => 1, 'currency' => 'USD', 'billing_interval' => '', 'rebuy_adjusted' => 0,
        ]);
    }

    public function test_a_rebuild_while_another_runs_is_skipped_and_leaves_the_rows(): void
    {
        $this->seedRow();
        add_option(self::LOCK, time() + 600, '', 'no');

        $result = (new RetentionSnapshotService())->generate();

        $this->assertFalse($result['success']);
        $this->assertSame('already_running', $result['code'] ?? null);
        $this->assertSame(1, $this->snapshotRows(), 'the running rebuild keeps its rows');
        $this->assertNotFalse(get_option(self::LOCK), 'the other run still holds the lock');
    }

    public function test_an_expired_lock_is_taken_over_and_released(): void
    {
        $this->seedRow();
        add_option(self::LOCK, time() - 60, '', 'no');

        $result = (new RetentionSnapshotService())->generate();

        $this->assertNotSame('already_running', $result['code'] ?? null);
        $this->assertSame(0, $this->snapshotRows(), 'the rebuild ran: no subscriptions, so no rows');
        $this->assertFalse(get_option(self::LOCK), 'the lock is released when the run ends');
    }

    public function test_a_lock_another_request_just_wrote_is_not_taken_again(): void
    {
        global $wpdb;
        // The other request's row is in the table, but this request's option cache has not seen it
        $wpdb->insert($wpdb->options, ['option_name' => self::LOCK, 'option_value' => maybe_serialize(['expires' => time() + 600]), 'autoload' => 'no']);
        $notOptions = (array) wp_cache_get('notoptions', 'options');
        $notOptions[self::LOCK] = true;
        wp_cache_set('notoptions', $notOptions, 'options');

        $result = (new RetentionSnapshotService())->start();

        $this->assertSame([false, 'already_running'], [$result['success'], $result['code'] ?? null]);
    }

    public function test_only_the_build_that_holds_the_lock_can_step(): void
    {
        $service = new RetentionSnapshotService();
        $started = $service->start();
        $this->assertTrue($started['success']);

        $other = $service->step('not-this-build');
        $this->assertSame([false, 'already_running'], [$other['success'], $other['code'] ?? null]);
        $this->assertSame(true, $service->step($started['token'])['success']);
    }
}
