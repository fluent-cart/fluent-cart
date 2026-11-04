<?php

namespace Tests\Integration\Modules\Shipping;

use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Shipment;
use FluentCart\App\Modules\Shipping\ShippingModule;
use FluentCart\App\Services\ShipmentService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Shipping\StubShippingCarrier;
use Tests\Support\Traits\BuildsShipmentOrders;
use Tests\Support\Traits\HealsCommittedRows;

/**
 * Each carrier webhook delivery leaves a log row the admin can read, without
 * secrets, and without letting a chatty carrier or an attacker flood the log.
 */
class CarrierWebhookLogTest extends DatabaseTestCase
{
    // ShipmentService commits its own transaction.
    use HealsCommittedRows;
    use BuildsShipmentOrders;

    private const SECRET = 'whsec_SuperSecretToken123';

    private int $activityWatermark = 0;

    public function setUp(): void
    {
        parent::setUp();
        $this->captureRowWatermarks();
        StubShippingCarrier::install();

        foreach (['event', 'rejected', 'unknown_shipment', 'no_change', 'no_label', 'mode_mismatch'] as $bucket) {
            delete_transient('fct_carrier_webhook_log_' . StubShippingCarrier::SLUG . '_' . $bucket);
        }

        $this->activityWatermark = (int) Activity::query()->max('id');
    }

    public function tearDown(): void
    {
        remove_all_filters('fluent_cart/shipping/log_carrier_webhooks');
        StubShippingCarrier::uninstall();
        $this->healCommittedRows();
        parent::tearDown();
    }

    /** The stub answers like Shippo/ShipStation do through wp_send_json(), minus the die. */
    private function carrierAnswers(int $status, array $body, ?callable $before = null): void
    {
        StubShippingCarrier::$onWebhook = function () use ($status, $body, $before) {
            if ($before) {
                $before();
            }
            status_header($status);
            echo wp_json_encode($body);
        };
    }

    private function deliver(string $rawBody = '{"event":"track_updated"}'): ?int
    {
        ob_start();
        $status = (new ShippingModule())->routeCarrierWebhook(StubShippingCarrier::SLUG, $rawBody);
        ob_end_clean();

        return $status;
    }

    /** @return Activity[] */
    private function webhookLogs(): array
    {
        return Activity::query()
            ->where('id', '>', $this->activityWatermark)
            ->where('title', 'like', '%webhook%')
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function test_applied_tracking_change_writes_one_order_activity(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(1);
        StubShippingCarrier::$dispatchResult = ['tracking_number' => 'TRK-LOG', 'transaction_id' => 'txn_log'];
        $shipment = $this->shipThroughStub($order, $item, 1);
        $this->assertInstanceOf(Shipment::class, $shipment, 'fixture: the shipment must be created');

        $this->carrierAnswers(200, ['applied' => true, 'status' => 'delivered'], function () use ($shipment) {
            ShipmentService::applyTrackingUpdate(Shipment::find($shipment->id), 'delivered', []);
        });

        $this->deliver('{"event":"track_updated","test":false,"data":{"tracking_number":"TRK-LOG"}}');

        $logs = $this->webhookLogs();
        $this->assertCount(1, $logs, 'an applied delivery must leave exactly one row');

        $log = $logs[0];
        $this->assertSame('Tracking updated via Stub Carrier webhook: Dispatched → Delivered', $log->title, 'the order activity must name the carrier and the change');
        $this->assertSame('order', $log->module_name, 'the row must link to the order');
        $this->assertSame((int) $order->id, (int) $log->module_id, 'the row must link to the order');
        $this->assertSame('success', $log->status);
        $this->assertStringContainsString('Outcome: applied', $log->content);
        $this->assertStringContainsString('HTTP 200', $log->content);
        $this->assertStringContainsString('Event: track_updated', $log->content);
        $this->assertStringContainsString('Shipment #' . $shipment->id, $log->content);
    }

    public function test_tracking_refresh_outside_a_webhook_is_not_logged_as_webhook(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(1);
        StubShippingCarrier::$dispatchResult = ['tracking_number' => 'TRK-REF', 'transaction_id' => 'txn_ref'];
        $shipment = $this->shipThroughStub($order, $item, 1);

        ShipmentService::applyTrackingUpdate(Shipment::find($shipment->id), 'delivered', []);

        $this->assertCount(0, $this->webhookLogs(), 'only a webhook may write a webhook row');
    }

    public function test_ignored_events_are_not_logged_one_per_delivery(): void
    {
        $this->carrierAnswers(200, ['ignored' => 'event']);

        for ($i = 0; $i < 25; $i++) {
            $this->deliver('{"event":"transaction_created"}');
        }

        $logs = $this->webhookLogs();
        $this->assertLessThanOrEqual(1, count($logs), 'ignored events must not log one row each');
        $this->assertSame(25, StubShippingCarrier::$webhookCalls, 'every delivery still reaches the carrier');
    }

    public function test_invalid_token_spam_is_bounded(): void
    {
        $this->carrierAnswers(401, ['message' => 'Invalid webhook token.']);

        for ($i = 0; $i < 50; $i++) {
            $this->deliver('{"event":"track_updated"}');
        }

        $logs = $this->webhookLogs();
        $this->assertCount(1, $logs, 'rejected deliveries must log at most once per window');
        $this->assertStringContainsString('Outcome: rejected:invalid_token', $logs[0]->content);
        $this->assertSame('warning', $logs[0]->status);
        $this->assertStringNotContainsString('Event:', $logs[0]->content, 'an unauthenticated body is never trusted, not even its event');
    }

    public function test_next_window_reports_what_was_suppressed(): void
    {
        $this->carrierAnswers(401, ['message' => 'Invalid webhook token.']);
        for ($i = 0; $i < 6; $i++) {
            $this->deliver();
        }

        $key   = 'fct_carrier_webhook_log_' . StubShippingCarrier::SLUG . '_rejected';
        $state = get_transient($key);
        $this->assertSame(5, $state['suppressed'] ?? null, 'suppressed deliveries must be counted');
        $state['until'] = time() - 1;
        set_transient($key, $state, DAY_IN_SECONDS);

        $this->deliver();

        $logs = $this->webhookLogs();
        $this->assertCount(2, $logs, 'a new window logs again');
        $this->assertStringContainsString('5 similar deliveries were not logged', $logs[1]->content);
    }

    public function test_tracking_change_is_kept_when_logging_is_off(): void
    {
        add_filter('fluent_cart/shipping/log_carrier_webhooks', '__return_false');
        [$order, $item] = $this->shipmentOrderWithItem(1);
        StubShippingCarrier::$dispatchResult = ['tracking_number' => 'TRK-OFF', 'transaction_id' => 'txn_off'];
        $shipment = $this->shipThroughStub($order, $item, 1);

        $this->carrierAnswers(200, ['applied' => true], function () use ($shipment) {
            ShipmentService::applyTrackingUpdate(Shipment::find($shipment->id), 'in_transit', []);
        });
        $this->deliver();

        $logs = $this->webhookLogs();
        $this->assertCount(1, $logs, 'the order history entry is not a delivery log');
        $this->assertSame((int) $order->id, (int) $logs[0]->module_id);
    }

    public function test_unknown_shipment_and_error_outcomes_are_logged(): void
    {
        $this->carrierAnswers(200, ['ignored' => 'unknown_shipment']);
        $this->deliver();

        $this->carrierAnswers(502, ['message' => 'Could not read the label.']);
        $this->deliver();

        $outcomes = array_map(function ($log) {
            return preg_match('/Outcome: (\S+)/', $log->content, $match) ? $match[1] : null;
        }, $this->webhookLogs());

        $this->assertSame(['unknown_shipment', 'error'], $outcomes);
    }

    public function test_an_unknown_shipment_row_names_the_tracking_number_carrier_and_mode(): void
    {
        $this->carrierAnswers(200, ['ignored' => 'unknown_shipment', 'details' => [
            'tracking_number' => '9400111899223',
            'carrier'         => 'usps',
            'mode'            => 'live',
        ]]);
        $this->deliver();

        $logs = $this->webhookLogs();
        $this->assertCount(1, $logs);
        $this->assertStringContainsString('Tracking number: 9400111899223', $logs[0]->content);
        $this->assertStringContainsString('Tracking carrier: usps', $logs[0]->content);
        $this->assertStringContainsString('Mode: Live', $logs[0]->content);
        $this->assertStringContainsString('Repeats of this outcome are logged at most once an hour.', $logs[0]->content);

        // Throttled per outcome, not per number, so a stream of foreign events cannot flood the log.
        $this->carrierAnswers(200, ['ignored' => 'unknown_shipment', 'details' => ['tracking_number' => 'OTHER', 'mode' => 'test']]);
        $this->deliver();
        $this->assertCount(1, $this->webhookLogs());
    }

    public function test_details_of_a_rejected_delivery_are_never_logged(): void
    {
        $this->carrierAnswers(401, ['message' => 'Invalid webhook token.', 'details' => ['tracking_number' => 'FORGED']]);
        $this->deliver();

        $logs = $this->webhookLogs();
        $this->assertCount(1, $logs);
        $this->assertStringNotContainsString('FORGED', $logs[0]->content);
    }

    public function test_a_throwing_carrier_logs_an_error(): void
    {
        StubShippingCarrier::$onWebhook = function () {
            throw new \RuntimeException('boom');
        };

        $this->assertSame(500, $this->deliver());

        $logs = $this->webhookLogs();
        $this->assertCount(1, $logs);
        $this->assertStringContainsString('Outcome: error', $logs[0]->content);
        $this->assertStringContainsString('HTTP 500', $logs[0]->content);
    }

    public function test_a_carrier_that_exits_is_logged_once_at_shutdown(): void
    {
        $module = new ShippingModule();

        StubShippingCarrier::$onWebhook = function () use ($module) {
            $this->assertNotFalse(has_action('shutdown', [$module, 'finishCarrierWebhookLog']), 'a carrier that exits must still be logged');
            status_header(200);
            echo wp_json_encode(['ignored' => 'mode_mismatch']);
            // Stands in for wp_send_json()'s die: PHP runs shutdown next.
            $module->finishCarrierWebhookLog();
        };

        ob_start();
        $module->routeCarrierWebhook(StubShippingCarrier::SLUG, '{}');
        ob_end_clean();

        $logs = $this->webhookLogs();
        $this->assertCount(1, $logs, 'the delivery must be logged once');
        $this->assertStringContainsString('Outcome: mode_mismatch', $logs[0]->content);
        $this->assertFalse(has_action('shutdown', [$module, 'finishCarrierWebhookLog']), 'the shutdown hook must be released');
    }

    public function test_no_secret_reaches_any_log_row(): void
    {
        $_GET['fcs_token']     = self::SECRET;
        $_REQUEST['fcs_token'] = self::SECRET;
        $body = '{"event":"track_updated","api_key":"' . self::SECRET . '","data":{"tracking_number":"' . self::SECRET . '"}}';

        try {
            $this->carrierAnswers(401, ['message' => 'Invalid webhook token.']);
            $this->deliver($body);
            $this->carrierAnswers(200, ['ignored' => 'unknown_shipment']);
            $this->deliver($body);
            $this->carrierAnswers(502, ['message' => self::SECRET]);
            $this->deliver($body);
        } finally {
            unset($_GET['fcs_token'], $_REQUEST['fcs_token']);
        }

        $logs = $this->webhookLogs();
        $this->assertCount(3, $logs, 'fixture: every distinct outcome logs');
        foreach ($logs as $log) {
            $this->assertStringNotContainsString(self::SECRET, $log->title . $log->content, 'no token, body or response text may be logged');
            $this->assertStringNotContainsString('fcs_token', $log->title . $log->content);
        }
    }

    public function test_logging_can_be_switched_off(): void
    {
        add_filter('fluent_cart/shipping/log_carrier_webhooks', '__return_false');
        $this->carrierAnswers(200, ['ignored' => 'unknown_shipment']);

        $this->deliver();

        $this->assertCount(0, $this->webhookLogs(), 'the filter must silence delivery logs');
    }
    /** A carrier shipment with a bought label, as Pro's webhooks find them. */
    private function labelledShipment(string $tracking): array
    {
        [$order, $item] = $this->shipmentOrderWithItem(1);
        StubShippingCarrier::$dispatchResult = ['tracking_number' => $tracking, 'transaction_id' => 'txn_' . $tracking];
        $shipment = $this->shipThroughStub($order, $item, 1);
        $this->assertInstanceOf(Shipment::class, $shipment, 'fixture: the shipment must be created');

        foreach (['no_change', 'no_label', 'mode_mismatch'] as $bucket) {
            delete_transient('fct_carrier_webhook_log_' . StubShippingCarrier::SLUG . '_' . $bucket . '_s' . (int) $shipment->id);
        }

        return [$order, $shipment];
    }

    /** @return array{0: string, 1: int} module_name and module_id of each row */
    private function logLinks(): array
    {
        return array_map(function ($log) {
            return [$log->module_name, (int) $log->module_id];
        }, $this->webhookLogs());
    }

    public function test_matched_but_ignored_deliveries_link_to_the_order(): void
    {
        [$order, $shipment] = $this->labelledShipment('TRK-IGN');
        $shipmentId = (int) $shipment->id;

        // Pro's WebhookOutcomes shapes: ignored, mode_mismatch and unchanged.
        $this->carrierAnswers(200, ['outcome' => 'ignored', 'reason' => 'no_label', 'ignored' => 'no_label', 'shipment_id' => $shipmentId]);
        $this->deliver();
        $this->carrierAnswers(200, ['outcome' => 'ignored', 'reason' => 'mode_mismatch', 'ignored' => 'mode_mismatch', 'shipment_id' => $shipmentId]);
        $this->deliver();
        $this->carrierAnswers(200, ['outcome' => 'unchanged', 'reason' => 'no_change', 'applied' => false, 'shipment_id' => $shipmentId, 'status' => 'dispatched']);
        $this->deliver();

        $logs = $this->webhookLogs();
        $this->assertCount(3, $logs, 'each distinct outcome must log once');
        foreach ($logs as $log) {
            $this->assertSame('order', $log->module_name, 'a delivery matched to a shipment must link to its order');
            $this->assertSame((int) $order->id, (int) $log->module_id, 'a delivery matched to a shipment must link to its order');
            $this->assertStringContainsString('Shipment #' . $shipmentId, $log->content, 'the row must name the shipment');
        }
        $this->assertStringContainsString('Outcome: ignored:no_label', $logs[0]->content);
        $this->assertStringContainsString('Outcome: mode_mismatch', $logs[1]->content);
        $this->assertStringContainsString('Outcome: ignored:no_change', $logs[2]->content);
    }

    public function test_an_error_for_a_known_shipment_links_to_the_order(): void
    {
        [$order, $shipment] = $this->labelledShipment('TRK-ERR');

        $this->carrierAnswers(502, ['outcome' => 'error', 'reason' => 'api_error', 'message' => 'Shippo is down', 'shipment_id' => (int) $shipment->id]);
        $this->deliver();

        $this->assertSame([['order', (int) $order->id]], $this->logLinks(), 'an error on a known shipment belongs on its order');
    }

    public function test_a_shipment_id_that_no_longer_exists_stays_a_shipping_row(): void
    {
        $this->carrierAnswers(200, ['outcome' => 'ignored', 'reason' => 'no_label', 'ignored' => 'no_label', 'shipment_id' => 987654321]);
        $this->deliver();

        $this->assertSame([['shipping', 0]], $this->logLinks(), 'an unresolvable shipment must not link to any order');
    }

    public function test_a_rejected_delivery_never_links_to_an_order(): void
    {
        [, $shipment] = $this->labelledShipment('TRK-REJ');

        $this->carrierAnswers(401, ['outcome' => 'rejected', 'reason' => 'invalid_token', 'shipment_id' => (int) $shipment->id]);
        $this->deliver();

        $this->assertSame([['shipping', 0]], $this->logLinks(), 'an unauthenticated delivery must stay off the order');
    }

    public function test_order_linked_repeats_are_throttled_per_shipment(): void
    {
        [$orderA, $shipmentA] = $this->labelledShipment('TRK-THA');
        [$orderB, $shipmentB] = $this->labelledShipment('TRK-THB');
        $unchanged = function ($shipmentId) {
            return ['outcome' => 'unchanged', 'reason' => 'no_change', 'applied' => false, 'shipment_id' => (int) $shipmentId];
        };

        $this->carrierAnswers(200, $unchanged($shipmentA->id));
        for ($i = 0; $i < 10; $i++) {
            $this->deliver();
        }
        $this->carrierAnswers(200, $unchanged($shipmentB->id));
        $this->deliver();
        $this->carrierAnswers(200, ['applied' => false]);
        $this->deliver();

        $this->assertSame(
            [['order', (int) $orderA->id], ['order', (int) $orderB->id], ['shipping', 0]],
            $this->logLinks(),
            'one shipment\'s repeats must not flood its order, nor silence another shipment or the carrier-wide row'
        );
        $this->assertSame(12, StubShippingCarrier::$webhookCalls, 'every delivery still reaches the carrier');
    }
}
