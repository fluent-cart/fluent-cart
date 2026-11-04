<?php

namespace Tests\Integration\Services;

use FluentCart\Api\Resource\OrderResource;
use FluentCart\App\App;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\Shipment;
use FluentCart\App\Services\ShipmentService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Shipping\StubShippingCarrier;
use Tests\Support\Traits\BuildsShipmentOrders;
use Tests\Support\Traits\HealsCommittedRows;

class ShipmentServiceTest extends DatabaseTestCase
{
    // ShipmentService commits its own transaction.
    use HealsCommittedRows;
    use BuildsShipmentOrders;

    private OrderFactory $orderFactory;
    private OrderItemFactory $itemFactory;
    private CustomerFactory $customerFactory;

    /** @var callable|null */
    private $queryInterceptor = null;

    /** @var array<int, array{old: ?string, new: ?string, level: int}> */
    private array $statusEvents = [];

    /** @var callable|null */
    private $statusEventRecorder = null;

    /** @var array|null */
    private $competitorState = null;

    /** @var callable|null */
    private $competitor = null;

    public function setUp(): void
    {
        parent::setUp();
        $this->captureRowWatermarks();
        $this->orderFactory    = new OrderFactory();
        $this->itemFactory     = new OrderItemFactory();
        $this->customerFactory = new CustomerFactory();
        StubShippingCarrier::install();
    }

    public function tearDown(): void
    {
        if ($this->queryInterceptor) {
            remove_filter('query', $this->queryInterceptor, 10);
            $this->queryInterceptor = null;
        }
        if ($this->statusEventRecorder) {
            remove_action('fluent_cart/shipping_status_changed', $this->statusEventRecorder, 10);
            $this->statusEventRecorder = null;
        }
        StubShippingCarrier::uninstall();
        $this->healCommittedRows();
        parent::tearDown();
    }

    /**
     * Runs $competitor as a second request at the moment the first request writes
     * the order's shipping_status, i.e. after it derived the status from totals.
     *
     * Mirrors InnoDB: while the first request holds a FOR UPDATE lock on the order
     * row (set by a SELECT ... fct_orders ... FOR UPDATE, released on COMMIT or
     * ROLLBACK) the competitor cannot get in, so it is left for runPendingCompetitor().
     */
    private function interleaveAtShippingStatusWrite(callable $competitor): void
    {
        $state = ['lockHeld' => false, 'running' => false, 'done' => false];
        $this->competitorState = &$state;
        $this->competitor = $competitor;

        $this->queryInterceptor = function ($sql) use (&$state, $competitor) {
            if ($state['running'] || $state['done'] || !is_string($sql)) {
                return $sql;
            }

            $normalized = strtolower(str_replace('`', '', $sql));

            if (preg_match('/^\s*select\b.*fct_orders\b.*\bfor update\s*;?\s*$/s', $normalized)) {
                $state['lockHeld'] = true;
            } elseif (preg_match('/^\s*(commit|rollback)\b/', $normalized)) {
                $state['lockHeld'] = false;
            } elseif (!$state['lockHeld']
                && preg_match('/^\s*update\s+\w*fct_orders\s+set\b.*\bshipping_status\s*=/s', $normalized)
            ) {
                $state['running'] = true;
                try {
                    $competitor();
                } finally {
                    $state['running'] = false;
                    $state['done'] = true;
                }
            }

            return $sql;
        };

        add_filter('query', $this->queryInterceptor, 10, 1);
    }

    /** The competitor that was blocked by the order lock runs once the lock is gone. */
    private function runPendingCompetitor(): void
    {
        if ($this->queryInterceptor) {
            remove_filter('query', $this->queryInterceptor, 10);
            $this->queryInterceptor = null;
        }

        if ($this->competitorState !== null && !$this->competitorState['done']) {
            $this->competitorState['done'] = true;
            ($this->competitor)();
        }
    }

    private function recordShippingStatusEvents(): void
    {
        $this->statusEventRecorder = function ($data) {
            $this->statusEvents[] = [
                'old'   => $data['old_status'] ?? null,
                'new'   => $data['new_status'] ?? null,
                'level' => (int) App::db()->transactionLevel(),
            ];
        };
        add_action('fluent_cart/shipping_status_changed', $this->statusEventRecorder, 10, 1);
    }

    /** Each transition must start where the previous one ended. */
    private function assertTransitionChain(string $from, string $to): void
    {
        $this->assertNotEmpty($this->statusEvents, 'expected at least one shipping status transition');

        $previous = $from;
        foreach ($this->statusEvents as $event) {
            $this->assertSame(
                $previous,
                $event['old'],
                'a transition was dispatched with a stale old_status: ' . wp_json_encode($this->statusEvents)
            );
            $previous = $event['new'];
        }

        $this->assertSame($to, $previous);
    }

    /** Create an order with one physical item of the given quantity. */
    private function orderWithItem(int $qty = 5): array
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->withAddresses()->create([
            'customer_id'     => $customer->id,
            'shipping_status' => 'unshipped',
        ]);
        $item = $this->itemFactory->create($order->id, [
            'fulfillment_type'   => 'physical',
            'quantity'           => $qty,
            'fulfilled_quantity' => 0,
            'post_title'         => 'Widget',
        ]);

        return [$order, $item];
    }

    private function dispatch(Order $order, OrderItem $item, int $qty)
    {
        return ShipmentService::createShipment($order, [
            'tracking_number' => 'TRK-' . wp_generate_password(6, false),
            'items'           => [['order_item_id' => $item->id, 'qty' => $qty]],
        ]);
    }

    private function refundUnits(OrderItem $item, int $qty): void
    {
        $item = OrderItem::find($item->id);
        $lineMeta = $item->line_meta;
        $lineMeta['refunded_quantity'] = $qty;
        $item->line_meta = $lineMeta;
        $item->save();
    }

    public function test_refunded_units_are_not_offered_for_shipping(): void
    {
        [$order, $item] = $this->orderWithItem(2);
        $this->refundUnits($item, 1);

        $this->assertWPError($this->dispatch($order, $item, 2), 'a refunded unit must not ship');
        $this->assertInstanceOf(Shipment::class, $this->dispatch($order, $item, 1));

        $this->assertSame(1, (int) OrderItem::find($item->id)->fulfilled_quantity);
        $this->assertSame('shipped', Order::find($order->id)->shipping_status, 'shipping every unit left ships the order');
    }

    public function test_refund_after_shipping_leaves_nothing_to_ship(): void
    {
        [$order, $item] = $this->orderWithItem(2);
        $this->assertInstanceOf(Shipment::class, $this->dispatch($order, $item, 2));
        $this->refundUnits($item, 1);

        $this->assertSame(['total' => 1, 'fulfilled' => 1], ShipmentService::getFulfillmentTotals($order->id));
        $this->assertWPError($this->dispatch(Order::find($order->id), $item, 1), 'no unit is left to ship');
        $this->assertSame(2, (int) OrderItem::find($item->id)->fulfilled_quantity);
    }

    public function test_delete_reverses_fulfilled_quantity(): void
    {
        [$order, $item] = $this->orderWithItem(2);
        $shipment = $this->dispatch($order, $item, 2);
        $this->assertInstanceOf(Shipment::class, $shipment);

        $result = ShipmentService::deleteShipment($shipment);

        $this->assertInstanceOf(Order::class, $result);
        $this->assertSame(0, (int) OrderItem::find($item->id)->fulfilled_quantity);
        $this->assertNull(Shipment::find($shipment->id));
    }

    /**
     * Two requests that loaded the same shipment must not both reverse it:
     * the stale second delete would strip units owned by the other shipment.
     */
    public function test_concurrent_delete_of_same_shipment_reverses_quantity_once(): void
    {
        [$order, $item] = $this->orderWithItem(2);
        $first  = $this->dispatch($order, $item, 1);
        $second = $this->dispatch($order, $item, 1);
        $this->assertSame(2, (int) OrderItem::find($item->id)->fulfilled_quantity);

        // Both "requests" hold the model loaded before either deleted.
        $staleCopy = Shipment::find($first->id);

        ShipmentService::deleteShipment(Shipment::find($first->id));
        $result = ShipmentService::deleteShipment($staleCopy);

        $this->assertSame(1, (int) OrderItem::find($item->id)->fulfilled_quantity);
        $this->assertWPError($result);
        $this->assertNotNull(Shipment::find($second->id));
        $this->assertSame('partially_shipped', Order::find($order->id)->shipping_status);
    }

    /**
     * Two creates finishing together: the first request derived partially_shipped
     * from 1/2, the second shipped the rest. The fully fulfilled order must not be
     * left at the first request's stale result.
     */
    public function test_concurrent_creates_leave_a_fully_fulfilled_order_shipped(): void
    {
        [$order, $item] = $this->orderWithItem(2);
        $orderForRequestB = Order::find($order->id);
        $this->recordShippingStatusEvents();

        $this->interleaveAtShippingStatusWrite(function () use ($orderForRequestB, $item) {
            $this->assertInstanceOf(Shipment::class, $this->dispatch($orderForRequestB, $item, 1));
        });

        $this->assertInstanceOf(Shipment::class, $this->dispatch($order, $item, 1));
        $this->runPendingCompetitor();

        $this->assertSame(2, (int) OrderItem::find($item->id)->fulfilled_quantity);
        $this->assertSame('shipped', Order::find($order->id)->shipping_status);
        $this->assertTransitionChain('unshipped', 'shipped');
    }

    public function test_concurrent_deletes_leave_an_emptied_order_unshipped(): void
    {
        [$order, $item] = $this->orderWithItem(2);
        $first  = $this->dispatch($order, $item, 1);
        $second = $this->dispatch(Order::find($order->id), $item, 1);
        $this->assertSame('shipped', Order::find($order->id)->shipping_status);
        $this->recordShippingStatusEvents();

        $this->interleaveAtShippingStatusWrite(function () use ($second) {
            $this->assertInstanceOf(Order::class, ShipmentService::deleteShipment(Shipment::find($second->id)));
        });

        $this->assertInstanceOf(Order::class, ShipmentService::deleteShipment(Shipment::find($first->id)));
        $this->runPendingCompetitor();

        $this->assertSame(0, (int) OrderItem::find($item->id)->fulfilled_quantity);
        $this->assertSame('unshipped', Order::find($order->id)->shipping_status);
        $this->assertTransitionChain('shipped', 'unshipped');
    }

    /**
     * Marking a shipment delivered while another shipment is deleted must not
     * leave the order claiming delivery of units that are no longer dispatched.
     */
    public function test_shipment_status_change_racing_a_delete_keeps_status_in_step(): void
    {
        [$order, $item] = $this->orderWithItem(2);
        $first  = $this->dispatch($order, $item, 1);
        $second = $this->dispatch(Order::find($order->id), $item, 1);
        ShipmentService::updateShipmentStatus(Shipment::find($second->id), Shipment::STATUS_DELIVERED);
        $this->assertSame('shipped', Order::find($order->id)->shipping_status);
        $this->recordShippingStatusEvents();

        $this->interleaveAtShippingStatusWrite(function () use ($second) {
            $this->assertInstanceOf(Order::class, ShipmentService::deleteShipment(Shipment::find($second->id)));
        });

        $result = ShipmentService::updateShipmentStatus(Shipment::find($first->id), Shipment::STATUS_DELIVERED);
        $this->assertInstanceOf(Order::class, $result);
        $this->runPendingCompetitor();

        $this->assertSame(1, (int) OrderItem::find($item->id)->fulfilled_quantity);
        $this->assertSame('partially_shipped', Order::find($order->id)->shipping_status);
        $this->assertTransitionChain('shipped', 'partially_shipped');
    }

    /**
     * The manual dropdown's "delivered" guard reads the totals; a shipment deleted
     * in between must not let the stale guard win.
     */
    public function test_manual_shipping_status_change_racing_a_delete_keeps_status_in_step(): void
    {
        [$order, $item] = $this->orderWithItem(2);
        $this->dispatch($order, $item, 1);
        $second = $this->dispatch(Order::find($order->id), $item, 1);
        $this->assertSame('shipped', Order::find($order->id)->shipping_status);
        $this->recordShippingStatusEvents();

        $this->interleaveAtShippingStatusWrite(function () use ($second) {
            $this->assertInstanceOf(Order::class, ShipmentService::deleteShipment(Shipment::find($second->id)));
        });

        $result = OrderResource::updateStatuses([
            'order'    => Order::find($order->id),
            'statuses' => ['shipping_status' => 'delivered'],
            'action'   => 'change_shipping_status',
        ]);
        $this->assertIsArray($result);
        $this->runPendingCompetitor();

        $this->assertSame(1, (int) OrderItem::find($item->id)->fulfilled_quantity);
        $this->assertSame('partially_shipped', Order::find($order->id)->shipping_status);
        $this->assertTransitionChain('shipped', 'partially_shipped');
    }

    /** Status listeners (emails, stock, integrations) must not run under the order lock. */
    public function test_shipping_status_events_fire_after_commit(): void
    {
        [$order, $item] = $this->orderWithItem(2);
        $this->recordShippingStatusEvents();

        $createdLevels = [];
        $createdListener = function () use (&$createdLevels) {
            $createdLevels[] = (int) App::db()->transactionLevel();
        };
        add_action('fluent_cart/order_shipment_created', $createdListener, 10, 1);

        try {
            $shipment = $this->dispatch($order, $item, 2);
            ShipmentService::updateShipmentStatus($shipment, Shipment::STATUS_DELIVERED);
            ShipmentService::deleteShipment(Shipment::find($shipment->id));
        } finally {
            remove_action('fluent_cart/order_shipment_created', $createdListener, 10);
        }

        $this->assertSame([0], $createdLevels);
        $this->assertCount(3, $this->statusEvents);
        foreach ($this->statusEvents as $event) {
            $this->assertSame(0, $event['level']);
        }
        $this->assertTransitionChain('unshipped', 'unshipped');
    }

    /** The controller reads the new status off the instance it passed in. */
    public function test_create_refreshes_the_passed_order_instance(): void
    {
        [$order, $item] = $this->orderWithItem(2);

        $this->dispatch($order, $item, 1);

        $this->assertSame('partially_shipped', $order->shipping_status);
    }

    public function test_resubmitting_the_current_shipment_status_keeps_a_manual_delivered(): void
    {
        [$order, $item] = $this->orderWithItem(2);
        $shipment = $this->dispatch($order, $item, 2);
        OrderResource::updateStatuses([
            'order'    => Order::find($order->id),
            'statuses' => ['shipping_status' => 'delivered'],
            'action'   => 'change_shipping_status',
        ]);
        $this->assertSame('delivered', Order::find($order->id)->shipping_status);
        $this->recordShippingStatusEvents();

        $result = ShipmentService::updateShipmentStatus(Shipment::find($shipment->id), Shipment::STATUS_DISPATCHED);

        $this->assertInstanceOf(Order::class, $result);
        $this->assertSame('delivered', Order::find($order->id)->shipping_status);
        $this->assertSame([], $this->statusEvents);
    }

    public function test_create_rejects_the_same_item_twice_in_one_shipment(): void
    {
        [$order, $item] = $this->orderWithItem(5);

        $result = ShipmentService::createShipment($order, [
            'tracking_number' => 'TRK-DUP',
            'items'           => [
                ['order_item_id' => $item->id, 'qty' => 1],
                ['order_item_id' => $item->id, 'qty' => 1],
            ],
        ]);

        $this->assertWPError($result);
        $this->assertSame(0, (int) OrderItem::find($item->id)->fulfilled_quantity);
        $this->assertSame(0, Shipment::where('order_id', $order->id)->count());
    }

    /**
     * @dataProvider overlongFieldProvider
     */
    public function test_create_rejects_fields_longer_than_their_columns(string $field, string $value): void
    {
        [$order, $item] = $this->orderWithItem(2);

        $result = ShipmentService::createShipment($order, array_merge([
            'tracking_number' => 'TRK-LEN',
            'items'           => [['order_item_id' => $item->id, 'qty' => 1]],
        ], [$field => $value]));

        $this->assertWPError($result);
        $this->assertSame(0, (int) OrderItem::find($item->id)->fulfilled_quantity);
        $this->assertSame(0, Shipment::where('order_id', $order->id)->count());
    }

    public function overlongFieldProvider(): array
    {
        return [
            'tracking number' => ['tracking_number', str_repeat('1', 256)],
            'carrier name'    => ['carrier_name', str_repeat('C', 101)],
            'tracking url'    => ['tracking_url', 'https://track.example/' . str_repeat('u', 480)],
        ];
    }

    public function test_create_accepts_fields_at_their_column_length(): void
    {
        [$order, $item] = $this->orderWithItem(2);

        $result = ShipmentService::createShipment($order, [
            'tracking_number' => str_repeat('1', 255),
            'carrier_name'    => str_repeat('C', 100),
            'tracking_url'    => 'https://track.example/' . str_repeat('u', 478),
            'items'           => [['order_item_id' => $item->id, 'qty' => 1]],
        ]);

        $this->assertInstanceOf(Shipment::class, $result);
        $this->assertSame(str_repeat('1', 255), Shipment::find($result->id)->tracking_number);
    }

    public function test_create_stamps_gmt_now_without_a_shipped_at_and_keeps_a_given_one(): void
    {
        [$order, $item] = $this->orderWithItem(2);

        $before = time();
        $stamped = ShipmentService::createShipment($order, [
            'shipped_at' => '',
            'items'      => [['order_item_id' => $item->id, 'qty' => 1]],
        ]);
        $storedAt = strtotime(Shipment::find($stamped->id)->shipped_at . ' UTC');
        $this->assertTrue($storedAt >= $before && $storedAt <= time(), 'an empty shipped_at must be stamped with GMT now');

        $given = ShipmentService::createShipment($order, [
            'shipped_at' => '2026-03-01 04:30:00',
            'items'      => [['order_item_id' => $item->id, 'qty' => 1]],
        ]);
        $this->assertSame('2026-03-01 04:30:00', (string) Shipment::find($given->id)->shipped_at);
    }

    // --- createShipment through a carrier: array / WP_Error / null results ---

    public function test_array_result_creates_shipment_and_bumps_fulfilled_quantity(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(5);
        StubShippingCarrier::$dispatchResult = [
            'tracking_number' => 'TRK123',
            'tracking_url'    => 'https://track.test/TRK123',
            'label_url'       => 'https://label.test/TRK123.pdf',
            'service_name'    => 'Stub — Ground',
            'transaction_id'  => 'txn_abc',
        ];

        $shipment = $this->shipThroughStub($order, $item, 2);

        $this->assertInstanceOf(Shipment::class, $shipment, 'a dispatched carrier result must create the shipment');
        $this->assertSame('dispatched', $shipment->status, 'a new shipment starts dispatched');
        $this->assertSame('TRK123', $shipment->tracking_number, 'the carrier-assigned tracking number must be stored');
        $this->assertSame(2, (int) OrderItem::find($item->id)->fulfilled_quantity, 'the dispatched quantity must be fulfilled');
    }

    public function test_carrier_result_meta_is_whitelisted(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem();
        StubShippingCarrier::$dispatchResult = [
            'tracking_number' => 'TRK1',
            'label_url'       => 'https://label.test/x.pdf',
            'service_name'    => 'Stub — Ground',
            'transaction_id'  => 'txn_abc',
            'secret_field'    => 'should-not-persist',
        ];

        $shipment = $this->shipThroughStub($order, $item, 1);
        $meta = is_array($shipment->meta) ? $shipment->meta : [];

        $this->assertSame('https://label.test/x.pdf', $meta['label_url'] ?? null, 'label_url must be kept');
        $this->assertSame('Stub — Ground', $meta['service_name'] ?? null, 'service_name must be kept');
        $this->assertSame('txn_abc', $meta['transaction_id'] ?? null, 'transaction_id must be kept so the label can be voided');
        $this->assertArrayNotHasKey('secret_field', $meta, 'unknown carrier keys must not be persisted');
    }

    public function test_null_result_manual_path_records_without_tracking_number(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem();
        StubShippingCarrier::$dispatchResult = null;

        $shipment = $this->shipThroughStub($order, $item, 1);

        $this->assertInstanceOf(Shipment::class, $shipment, is_wp_error($shipment) ? $shipment->get_error_code() : 'tracking is optional without a label');
        $this->assertSame('', (string) $shipment->tracking_number, 'no tracking number is stored');
        $this->assertSame('', (string) $shipment->tracking_url, 'no tracking URL is derived from an empty number');
        $this->assertSame(1, (int) OrderItem::find($item->id)->fulfilled_quantity, 'the dispatched quantity must be fulfilled');
    }

    public function test_manual_carrier_records_without_tracking_number(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem();

        $shipment = ShipmentService::createShipment($order, [
            'carrier_slug'    => Shipment::CARRIER_MANUAL,
            'tracking_number' => '',
            'items'           => [['order_item_id' => $item->id, 'qty' => 1]],
        ]);

        $this->assertInstanceOf(Shipment::class, $shipment, is_wp_error($shipment) ? $shipment->get_error_code() : 'a manual shipment needs no tracking number');
        $this->assertSame('', (string) $shipment->tracking_number);
        $this->assertSame('', (string) $shipment->tracking_url);
    }

    public function test_null_result_manual_path_uses_supplied_tracking(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem();
        StubShippingCarrier::$dispatchResult = null;

        $shipment = $this->shipThroughStub($order, $item, 1, ['tracking_number' => 'MANUAL-1']);

        $this->assertInstanceOf(Shipment::class, $shipment, 'a manual shipment with a tracking number must be created');
        $this->assertSame('MANUAL-1', $shipment->tracking_number, 'the supplied tracking number must be stored');
    }

    public function test_dispatched_result_without_tracking_number_is_still_recorded(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem();
        StubShippingCarrier::$dispatchResult = ['label_url' => 'https://label.test/x.pdf', 'transaction_id' => 'txn_1'];

        $shipment = $this->shipThroughStub($order, $item, 1);

        $this->assertInstanceOf(Shipment::class, $shipment, 'a paid label must not be thrown away for a missing tracking number');
        $this->assertSame([], StubShippingCarrier::$cancelCalls, 'a recorded shipment must not void its label');
    }

    public function test_wp_error_result_leaves_no_row_and_no_quantity_bump(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem();
        StubShippingCarrier::$dispatchResult = new \WP_Error('carrier_down', 'nope');

        $result = $this->shipThroughStub($order, $item, 2);

        $this->assertWPError($result, 'a refused dispatch must fail the create');
        $this->assertSame(0, Shipment::where('order_id', $order->id)->count(), 'no shipment row may be written');
        $this->assertSame(0, (int) OrderItem::find($item->id)->fulfilled_quantity, 'no quantity may be fulfilled');
    }

    public function test_carrier_name_is_derived_server_side(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem();
        StubShippingCarrier::$dispatchResult = ['tracking_number' => 'T'];

        $shipment = $this->shipThroughStub($order, $item, 1, ['carrier_name' => 'FORGED']);

        $this->assertSame('Stub Carrier', $shipment->carrier_name, 'a non-manual carrier ships under its own name');
    }

    public function test_over_dispatch_is_rejected_before_the_carrier_is_called(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(3);
        StubShippingCarrier::$dispatchResult = ['tracking_number' => 'T'];

        $result = $this->shipThroughStub($order, $item, 5);

        $this->assertWPError($result, 'shipping more than remains must fail');
        $this->assertSame([], StubShippingCarrier::$dispatchLevels, 'no label may be bought for an invalid shipment');
        $this->assertSame(0, Shipment::where('order_id', $order->id)->count(), 'no shipment row may be written');
    }

    public function test_unknown_carrier_slug_errors(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem();

        $result = ShipmentService::createShipment($order, [
            'carrier_slug' => 'does_not_exist',
            'items'        => [['order_item_id' => $item->id, 'qty' => 1]],
        ]);

        $this->assertWPError($result, 'an unregistered carrier must be rejected');
        $this->assertSame('invalid_carrier', $result->get_error_code(), 'wrong error code');
    }

    public function test_empty_items_errors(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem();

        $result = $this->shipThroughStub($order, $item, 0);

        $this->assertWPError($result, 'a shipment with nothing in it must be rejected');
    }

    // --- applyTrackingUpdate: carrier webhooks and refreshes ---

    /** @var array[] tracking + status-change hook calls, with the transaction level each ran at */
    private array $trackingHookCalls = [];

    /** @var callable[] hook => listener */
    private array $trackingListeners = [];

    /** A dispatched carrier shipment for the whole item, with optional stored tracking meta. */
    private function trackedShipment(array $meta = [], int $qty = 1): array
    {
        [$order, $item] = $this->shipmentOrderWithItem($qty);
        StubShippingCarrier::$dispatchResult = ['tracking_number' => 'TRK-T', 'transaction_id' => 'txn_track'];
        $shipment = $this->shipThroughStub($order, $item, $qty);
        $this->assertInstanceOf(Shipment::class, $shipment, 'the fixture shipment must be created');

        if ($meta) {
            $shipment->meta = array_merge(is_array($shipment->meta) ? $shipment->meta : [], $meta);
            $shipment->save();
        }

        return [Order::find($order->id), $item, Shipment::find($shipment->id)];
    }

    private function recordTrackingHooks(): void
    {
        foreach (['fluent_cart/shipment_tracking_updated', 'fluent_cart/order_shipment_status_changed', 'fluent_cart/order_shipment_status_changed_to_in_transit', 'fluent_cart/order_shipment_status_changed_to_delivered'] as $hook) {
            $listener = function ($payload) use ($hook) {
                $this->trackingHookCalls[] = [
                    'hook'  => $hook,
                    'old'   => $payload['old_status'] ?? null,
                    'new'   => $payload['new_status'] ?? null,
                    'level' => (int) App::db()->transactionLevel(),
                ];
            };
            add_action($hook, $listener, 10, 1);
            $this->trackingListeners[$hook] = $listener;
        }
    }

    private function stopRecordingTrackingHooks(): void
    {
        foreach ($this->trackingListeners as $hook => $listener) {
            remove_action($hook, $listener, 10);
        }
        $this->trackingListeners = [];
    }

    private function trackingHookNames(): array
    {
        return array_column($this->trackingHookCalls, 'hook');
    }

    public function test_tracking_unknown_status_is_ignored(): void
    {
        [, , $shipment] = $this->trackedShipment();

        $this->assertFalse(ShipmentService::applyTrackingUpdate($shipment, 'not_a_status', []), 'an unknown status must not apply');
        $this->assertSame('dispatched', Shipment::find($shipment->id)->status, 'the status must not move');
    }

    public function test_tracking_older_date_is_dropped(): void
    {
        [, , $shipment] = $this->trackedShipment(['tracking_status_date' => '2026-07-10T10:00:00Z']);
        ShipmentService::updateShipmentStatus($shipment, Shipment::STATUS_IN_TRANSIT);

        $applied = ShipmentService::applyTrackingUpdate(Shipment::find($shipment->id), 'dispatched', ['tracking_status_date' => '2026-07-09T10:00:00Z']);

        $this->assertFalse($applied, 'an event older than the stored one must be ignored');
        $this->assertSame('in_transit', Shipment::find($shipment->id)->status, 'the status must not walk backwards');
    }

    public function test_tracking_newer_date_is_applied_and_derives_the_order(): void
    {
        [$order, , $shipment] = $this->trackedShipment(['tracking_status_date' => '2026-07-10T10:00:00Z']);
        $this->assertSame('shipped', $order->shipping_status, 'fixture: a fully dispatched order is shipped');

        $applied = ShipmentService::applyTrackingUpdate($shipment, 'delivered', ['tracking_status_date' => '2026-07-11T10:00:00Z']);

        $this->assertTrue($applied, 'a newer event must apply');
        $this->assertSame('delivered', Shipment::find($shipment->id)->status, 'the shipment must move');
        $this->assertSame('delivered', Order::find($order->id)->shipping_status, 'the last parcel delivered must derive the order to delivered');
        $this->assertSame('delivered', $shipment->status, 'the caller\'s instance must carry the persisted status');
    }

    public function test_tracking_dateless_backward_move_is_rejected(): void
    {
        [, , $shipment] = $this->trackedShipment();
        ShipmentService::updateShipmentStatus($shipment, Shipment::STATUS_IN_TRANSIT);

        $applied = ShipmentService::applyTrackingUpdate(Shipment::find($shipment->id), 'dispatched', []);

        $this->assertFalse($applied, 'a dateless move to an earlier stage must be rejected');
        $this->assertSame('in_transit', Shipment::find($shipment->id)->status, 'the status must not walk backwards');
    }

    public function test_tracking_terminal_lock_pins_status_but_merges_meta(): void
    {
        [, , $shipment] = $this->trackedShipment(['tracking_status_date' => '2026-07-10T10:00:00Z']);
        ShipmentService::updateShipmentStatus($shipment, Shipment::STATUS_DELIVERED);

        // Same ordinal and a newer date: only the terminal lock can pin it.
        $applied = ShipmentService::applyTrackingUpdate(Shipment::find($shipment->id), 'returned', [
            'tracking_status_date' => '2026-07-12T10:00:00Z',
            'tracking_status'      => 'RETURNED',
        ]);

        $fresh = Shipment::find($shipment->id);
        $this->assertFalse($applied, 'a delivered shipment must not move');
        $this->assertSame('delivered', $fresh->status, 'the terminal lock must pin the status');
        $this->assertSame('RETURNED', $fresh->meta['tracking_status'] ?? null, 'the newer tracking detail must still be recorded');
    }

    public function test_tracking_update_on_a_deleted_shipment_returns_false(): void
    {
        [, , $shipment] = $this->trackedShipment();
        $stale = Shipment::find($shipment->id);
        ShipmentService::deleteShipment(Shipment::find($shipment->id));

        $this->assertFalse(ShipmentService::applyTrackingUpdate($stale, 'in_transit', []), 'a deleted shipment must not be written back');
        $this->assertNull(Shipment::find($shipment->id), 'the deleted row must not be resurrected');
    }

    public function test_tracking_change_fires_both_hook_families_once_after_commit(): void
    {
        [, , $shipment] = $this->trackedShipment();
        $this->recordTrackingHooks();

        try {
            $this->assertTrue(ShipmentService::applyTrackingUpdate($shipment, 'in_transit', ['tracking_status_date' => '2026-07-11T10:00:00Z']), 'the change must apply');
        } finally {
            $this->stopRecordingTrackingHooks();
        }

        $names = $this->trackingHookNames();
        sort($names);
        $this->assertSame([
            'fluent_cart/order_shipment_status_changed',
            'fluent_cart/order_shipment_status_changed_to_in_transit',
            'fluent_cart/shipment_tracking_updated',
        ], $names, 'a real change must fire the tracking hook and the status-change pair exactly once');

        foreach ($this->trackingHookCalls as $call) {
            $this->assertSame('dispatched', $call['old'], 'old_status must be the pre-update status');
            $this->assertSame('in_transit', $call['new'], 'new_status must be the applied status');
            $this->assertSame(0, $call['level'], 'hooks must run after commit');
        }
    }

    public function test_tracking_same_status_fires_no_hooks_but_records_meta(): void
    {
        [, , $shipment] = $this->trackedShipment(['tracking_status_date' => '2026-07-10T10:00:00Z']);
        $this->recordTrackingHooks();

        try {
            $applied = ShipmentService::applyTrackingUpdate($shipment, 'dispatched', [
                'tracking_status_date' => '2026-07-11T10:00:00Z',
                'tracking_status'      => 'PRE_TRANSIT',
            ]);
        } finally {
            $this->stopRecordingTrackingHooks();
        }

        $this->assertFalse($applied, 'a repeated status is not a change');
        $this->assertSame([], $this->trackingHookCalls, 'a no-op update must fire no hooks');
        $this->assertSame('PRE_TRANSIT', Shipment::find($shipment->id)->meta['tracking_status'] ?? null, 'the fresher detail must still be stored');
    }

    /** Two deliveries of events read from the same stale row: only the newer may win. */
    public function test_tracking_update_from_a_stale_copy_is_judged_against_the_locked_row(): void
    {
        [, , $shipment] = $this->trackedShipment();
        $staleCopy = Shipment::find($shipment->id);
        $this->recordTrackingHooks();

        try {
            $this->assertTrue(ShipmentService::applyTrackingUpdate(Shipment::find($shipment->id), 'in_transit', ['tracking_status_date' => '2026-07-12T10:00:00Z']), 'the newer event must apply');
            $this->assertFalse(ShipmentService::applyTrackingUpdate($staleCopy, 'in_transit', ['tracking_status_date' => '2026-07-11T10:00:00Z']), 'the older event read from a stale row must not apply');
        } finally {
            $this->stopRecordingTrackingHooks();
        }

        $this->assertSame('2026-07-12T10:00:00Z', Shipment::find($shipment->id)->meta['tracking_status_date'] ?? null, 'the older event must not overwrite the stored date');
        $this->assertCount(1, array_keys($this->trackingHookNames(), 'fluent_cart/shipment_tracking_updated', true), 'only one transition may be announced');
    }

    /**
     * A webhook (delivered) and a stale refresh (in_transit, dateless) race: the
     * refresh lands while the webhook holds the order lock, so it must wait and then
     * see the delivered row instead of overwriting it.
     */
    public function test_racing_tracking_updates_cannot_both_apply(): void
    {
        [$order, , $shipment] = $this->trackedShipment();
        $staleCopy = Shipment::find($shipment->id);
        $competitorApplied = null;
        $this->recordTrackingHooks();

        $this->interleaveAtShippingStatusWrite(function () use ($staleCopy, &$competitorApplied) {
            $competitorApplied = ShipmentService::applyTrackingUpdate($staleCopy, 'in_transit', []);
        });

        try {
            $this->assertTrue(ShipmentService::applyTrackingUpdate(Shipment::find($shipment->id), 'delivered', ['tracking_status_date' => '2026-07-12T10:00:00Z']), 'the webhook must apply');
            $this->runPendingCompetitor();
        } finally {
            $this->stopRecordingTrackingHooks();
        }

        $this->assertFalse($competitorApplied, 'the racing update must not apply over the delivered row');
        $this->assertSame('delivered', Shipment::find($shipment->id)->status, 'the delivered status must stand');
        $this->assertSame('delivered', Order::find($order->id)->shipping_status, 'the order must stay delivered');
        $this->assertSame(['fluent_cart/shipment_tracking_updated'], array_values(array_filter($this->trackingHookNames(), function ($name) {
            return $name === 'fluent_cart/shipment_tracking_updated';
        })), 'exactly one tracking transition may be announced');
    }

    // --- deleteShipment: voiding the carrier label ---

    public function test_delete_voids_the_label_after_commit_with_the_locked_rows_handle(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(3);
        StubShippingCarrier::$dispatchResult = ['tracking_number' => 'TRK-V', 'transaction_id' => 'txn_original'];
        $shipment  = $this->shipThroughStub($order, $item, 2);
        $staleCopy = Shipment::find($shipment->id);

        // The row moved on after the caller loaded it; the void must use the stored handle.
        $fresh = Shipment::find($shipment->id);
        $fresh->meta = array_merge($fresh->meta, ['transaction_id' => 'txn_current']);
        $fresh->save();

        StubShippingCarrier::$cancelResult = true;
        $voidWarning = 'unset';
        $result = ShipmentService::deleteShipment($staleCopy, $voidWarning);

        $this->assertInstanceOf(Order::class, $result, 'delete must keep returning the order');
        $this->assertNull($voidWarning, 'a clean void must leave no warning');
        $this->assertSame([0], StubShippingCarrier::$cancelLevels, 'the void must run after commit, with no transaction open');
        $this->assertSame('txn_current', StubShippingCarrier::$cancelCalls[0]['transaction_id'] ?? null, 'the label handle must come from the locked row');
        $this->assertNull(Shipment::find($shipment->id), 'the row must be deleted');
        $this->assertSame(0, (int) OrderItem::find($item->id)->fulfilled_quantity, 'the quantity must be given back');
    }

    public function test_delete_with_a_failed_void_still_deletes_warns_and_logs(): void
    {
        [$order, , $shipment] = $this->trackedShipment();
        StubShippingCarrier::$cancelResult = new \WP_Error('refund_failed', 'carrier refused');

        $voidWarning = null;
        $result = ShipmentService::deleteShipment($shipment, $voidWarning);

        $this->assertInstanceOf(Order::class, $result, 'a failed void must not fail the delete');
        $this->assertIsString($voidWarning, 'a failed void must hand back a warning');
        $this->assertStringContainsString('carrier refused', $voidWarning, 'the warning must carry the carrier detail');
        $this->assertNull(Shipment::find($shipment->id), 'the row must be deleted anyway');

        $log = \FluentCart\App\Models\Activity::query()
            ->where('module_name', 'order')
            ->where('module_id', $order->id)
            ->where('status', 'warning')
            ->orderBy('id', 'desc')
            ->first();
        $this->assertNotNull($log, 'a failed void must leave an order activity entry');
        $this->assertStringContainsString('carrier refused', (string) $log->content, 'the activity entry must carry the carrier detail');
    }

    public function test_delete_of_a_shipment_without_a_label_does_not_warn(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(1);
        StubShippingCarrier::$dispatchResult = null;
        $shipment = $this->shipThroughStub($order, $item, 1, ['tracking_number' => 'MANUAL-9']);

        $voidWarning = null;
        $result = ShipmentService::deleteShipment($shipment, $voidWarning);

        $this->assertInstanceOf(Order::class, $result, 'delete must return the order');
        $this->assertNull($voidWarning, 'nothing to void means no warning');
        $this->assertSame([], StubShippingCarrier::$cancelCalls, 'a shipment with no label handle must not reach the carrier');
    }

    public function test_a_label_pdf_from_the_carrier_is_not_stored(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(1);
        StubShippingCarrier::$dispatchResult = ['tracking_number' => 'TRK-P', 'transaction_id' => 'txn_p', 'label_pdf_base64' => base64_encode('%PDF-1.4 x')];

        $shipment = $this->shipThroughStub($order, $item, 1);

        $this->assertArrayNotHasKey('label_pdf_base64', (array) Shipment::find($shipment->id)->meta, 'labels are fetched on demand, never stored');
        $this->assertTrue($shipment->toArray()['has_label'], 'the carrier can still provide the label');
    }
    private function unsavedShipment(string $carrierSlug, array $meta): Shipment
    {
        $shipment = new Shipment([
            'carrier_slug'    => $carrierSlug,
            'tracking_number' => 'TRK-R',
            'items'           => [],
        ]);
        $shipment->status = Shipment::STATUS_DISPATCHED;
        $shipment->meta   = $meta;

        return $shipment;
    }

    public function test_can_refresh_tracking_only_for_a_carrier_label(): void
    {
        $this->assertTrue(
            ShipmentService::canRefreshTracking($this->unsavedShipment(StubShippingCarrier::SLUG, ['transaction_id' => 'txn_r'])),
            'a syncing carrier shipment with a bought label must be refreshable'
        );
        $this->assertFalse(
            ShipmentService::canRefreshTracking($this->unsavedShipment(StubShippingCarrier::SLUG, [])),
            'a carrier shipment recorded by hand has no label to pull tracking from'
        );
        $this->assertFalse(
            ShipmentService::canRefreshTracking($this->unsavedShipment(StubShippingCarrier::SLUG, ['transaction_id' => '   '])),
            'a blank label handle is no label'
        );
        $this->assertFalse(
            ShipmentService::canRefreshTracking($this->unsavedShipment('manual', ['transaction_id' => 'txn_r'])),
            'the manual carrier never syncs tracking'
        );
    }

    public function test_can_refresh_tracking_for_a_label_bought_through_the_carrier(): void
    {
        [, , $shipment] = $this->trackedShipment();

        $this->assertTrue(ShipmentService::canRefreshTracking($shipment), 'a shipment whose label the carrier bought must be refreshable');
    }

    public function test_cannot_refresh_tracking_for_a_carrier_shipment_recorded_without_a_label(): void
    {
        [$order, $item] = $this->shipmentOrderWithItem(1);
        StubShippingCarrier::$dispatchResult = null;
        $shipment = $this->shipThroughStub($order, $item, 1, ['tracking_number' => 'HAND-1']);

        $this->assertFalse(ShipmentService::canRefreshTracking(Shipment::find($shipment->id)), 'refresh must not be offered when the carrier holds no label for the shipment');
    }

    public function test_can_refresh_tracking_without_a_label_when_the_carrier_tracks_by_number(): void
    {
        StubShippingCarrier::$tracksWithoutLabel = true;

        $this->assertTrue(
            ShipmentService::canRefreshTracking($this->unsavedShipment(StubShippingCarrier::SLUG, [])),
            'a carrier that tracks by number needs no label it bought'
        );

        StubShippingCarrier::$supportsSync = false;
        $this->assertFalse(
            ShipmentService::canRefreshTracking($this->unsavedShipment(StubShippingCarrier::SLUG, [])),
            'the carrier must still support syncing'
        );
    }
}
