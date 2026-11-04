<?php

namespace Tests\Integration\Services;

use FluentCart\App\Services\Reminders\RenewalReminderService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Traits\BuildsCustomerOrders;
use Tests\Support\Traits\ConfiguresStoreOrderMode;

/**
 * Overdue stages map to notification events by POSITION in the configured day
 * list, not by fixed day thresholds — the escalation tone must follow the
 * admin's schedule (e.g. 14,30 → first at 14, final at 30).
 */
class RenewalReminderServiceTest extends WPTestCase
{
    use BuildsCustomerOrders;
    use ConfiguresStoreOrderMode;

    public function setUp(): void
    {
        parent::setUp();

        // OrderFactory creates live-mode orders; reminders only send when the
        // store mode matches.
        $this->setStoreOrderMode('live');
    }

    private function resolveEvent(string $stage): string
    {
        $service = new RenewalReminderService();
        $method = new \ReflectionMethod($service, 'resolveEventName');
        $method->setAccessible(true);

        return $method->invoke($service, $stage);
    }

    private function withOverdueDays(array $days, callable $assertions): void
    {
        $filter = function () use ($days) {
            return $days;
        };
        add_filter('fluent_cart/reminders/renewal_overdue_days', $filter);
        try {
            $assertions();
        } finally {
            remove_filter('fluent_cart/reminders/renewal_overdue_days', $filter);
        }
    }

    public function test_default_days_map_first_followup_final(): void
    {
        $this->assertSame('renewal_overdue_first', $this->resolveEvent('overdue_1'));
        $this->assertSame('renewal_overdue_followup', $this->resolveEvent('overdue_3'));
        $this->assertSame('renewal_overdue_final', $this->resolveEvent('overdue_7'));
    }

    public function test_non_overdue_stage_maps_to_due_event(): void
    {
        $this->assertSame('renewal_reminder_due', $this->resolveEvent('due_0'));
    }

    public function test_custom_days_map_by_position_not_threshold(): void
    {
        $this->withOverdueDays([30, 14], function () {
            $this->assertSame('renewal_overdue_first', $this->resolveEvent('overdue_14'));
            $this->assertSame('renewal_overdue_final', $this->resolveEvent('overdue_30'));
        });
    }

    public function test_many_days_middle_stages_map_to_followup(): void
    {
        $this->withOverdueDays([21, 14, 7, 2], function () {
            $this->assertSame('renewal_overdue_first', $this->resolveEvent('overdue_2'));
            $this->assertSame('renewal_overdue_followup', $this->resolveEvent('overdue_7'));
            $this->assertSame('renewal_overdue_followup', $this->resolveEvent('overdue_14'));
            $this->assertSame('renewal_overdue_final', $this->resolveEvent('overdue_21'));
        });
    }

    public function test_single_day_list_maps_to_first(): void
    {
        $this->withOverdueDays([5], function () {
            $this->assertSame('renewal_overdue_first', $this->resolveEvent('overdue_5'));
        });
    }

    /**
     * Producer-level contract: a scheduled overdue send fires its staged hook
     * AND the legacy fluent_cart/renewal_reminder_overdue hook (deprecated
     * bridge for third-party listeners, shipped in 1.6.0) — but the core
     * mailer must deliver only the staged email, never a duplicate through
     * the legacy hook.
     */
    public function test_scheduled_overdue_send_fires_staged_and_legacy_hooks_but_sends_one_email(): void
    {
        $this->setExpectedDeprecated('fluent_cart/renewal_reminder_overdue');

        $order = $this->makeOrderWithCustomer();
        $order->updateMeta('due_date', gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS));

        $service = new RenewalReminderService();
        $dueAt = (new \ReflectionMethod($service, 'getDueTimestamp'))->invoke($service, $order);
        $cycleKey = (new \ReflectionMethod($service, 'getCycleKey'))->invoke($service, $order, $dueAt);

        $stagedPayloads = [];
        $legacyPayloads = [];
        $sentMail = [];

        $stagedListener = function ($data) use (&$stagedPayloads) {
            $stagedPayloads[] = $data;
        };
        $legacyListener = function ($data) use (&$legacyPayloads) {
            $legacyPayloads[] = $data;
        };
        $mailCapture = function ($null, array $atts) use (&$sentMail) {
            $sentMail[] = $atts;
            return true;
        };

        add_action('fluent_cart/renewal_overdue_first', $stagedListener);
        add_action('fluent_cart/renewal_reminder_overdue', $legacyListener);
        add_filter('pre_wp_mail', $mailCapture, 10, 2);

        try {
            $sent = $service->send($order->id, 'overdue_1', $cycleKey);
        } finally {
            remove_action('fluent_cart/renewal_overdue_first', $stagedListener);
            remove_action('fluent_cart/renewal_reminder_overdue', $legacyListener);
            remove_filter('pre_wp_mail', $mailCapture, 10);
        }

        $this->assertTrue($sent, 'scheduled overdue send should succeed');
        $this->assertCount(1, $stagedPayloads, 'staged hook must fire once');
        $this->assertCount(1, $legacyPayloads, 'legacy hook must still fire for external listeners');
        $this->assertSame('overdue_1', $legacyPayloads[0]['reminder']['stage'], 'legacy hook carries the same payload');
        $this->assertTrue($legacyPayloads[0]['staged_email_dispatched'], 'bridge payload must carry the already-delivered flag');

        $this->assertCount(1, $sentMail, 'exactly one email — the staged one, no legacy duplicate');
        $this->assertStringContainsString('Payment Reminder for Renewal', $sentMail[0]['subject']);
    }

    public function test_send_skips_live_invoice_when_store_is_in_test_mode(): void
    {
        $order = $this->makeOrderWithCustomer();
        $order->updateMeta('due_date', gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS));

        $service = new RenewalReminderService();
        $dueAt = (new \ReflectionMethod($service, 'getDueTimestamp'))->invoke($service, $order);
        $cycleKey = (new \ReflectionMethod($service, 'getCycleKey'))->invoke($service, $order, $dueAt);

        $this->setStoreOrderMode('test');

        $sentMail = [];
        $mailCapture = function ($null, array $atts) use (&$sentMail) {
            $sentMail[] = $atts;
            return true;
        };
        add_filter('pre_wp_mail', $mailCapture, 10, 2);

        try {
            $sent = $service->send($order->id, 'overdue_1', $cycleKey);
        } finally {
            remove_filter('pre_wp_mail', $mailCapture, 10);
        }

        $this->assertFalse($sent, 'a live invoice must not be reminded from a test-mode store');
        $this->assertSame([], $sentMail);
    }

    /**
     * The legacy hook is public API: a direct external dispatch with a
     * scheduled-style stage (no bridge flag) must still send the legacy email —
     * suppression keys on the staged_email_dispatched flag, not on stage shape.
     */
    public function test_direct_legacy_dispatch_with_overdue_stage_still_sends_email(): void
    {
        $order = $this->makeOrderWithCustomer();

        $sentMail = [];
        $mailCapture = function ($null, array $atts) use (&$sentMail) {
            $sentMail[] = $atts;
            return true;
        };
        add_filter('pre_wp_mail', $mailCapture, 10, 2);

        try {
            do_action('fluent_cart/renewal_reminder_overdue', [
                'order'    => $order,
                'customer' => $order->customer,
                'reminder' => [
                    'stage'        => 'overdue_1',
                    'order_id'     => (int)$order->id,
                    'order_ref'    => '#' . $order->id,
                    'due_at'       => gmdate('Y-m-d H:i:s'),
                    'due_amount'   => 10000,
                    'payment_link' => '#',
                ],
            ]);
        } finally {
            remove_filter('pre_wp_mail', $mailCapture, 10);
        }

        $this->assertCount(1, $sentMail, 'unflagged direct dispatch must still send the legacy overdue email');
    }

    public function test_manual_reminder_still_sends_legacy_overdue_email(): void
    {
        $order = $this->makeOrderWithCustomer();

        $sentMail = [];
        $mailCapture = function ($null, array $atts) use (&$sentMail) {
            $sentMail[] = $atts;
            return true;
        };
        add_filter('pre_wp_mail', $mailCapture, 10, 2);

        try {
            do_action('fluent_cart/renewal_reminder_overdue', [
                'order'    => $order,
                'customer' => $order->customer,
                'reminder' => [
                    'stage'        => 'manual',
                    'order_id'     => (int)$order->id,
                    'order_ref'    => '#' . $order->id,
                    'due_at'       => gmdate('Y-m-d H:i:s'),
                    'due_amount'   => 10000,
                    'payment_link' => '#',
                ],
            ]);
        } finally {
            remove_filter('pre_wp_mail', $mailCapture, 10);
        }

        $this->assertCount(1, $sentMail, 'manual-stage payloads must still send the legacy overdue email');
    }
}
