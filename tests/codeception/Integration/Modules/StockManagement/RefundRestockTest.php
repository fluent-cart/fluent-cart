<?php

namespace Tests\Integration\Modules\StockManagement;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\Shipment;
use FluentCart\App\Modules\StockManagement\StockManagement;
use FluentCart\App\Services\Payments\Refund;
use FluentCart\App\Services\Payments\RefundPlanner;
use FluentCart\App\Services\ShipmentService;
use FluentCart\Framework\Support\Arr;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Doubles\FailingQueries;
use Tests\Support\Doubles\FakeRefundGateway;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\HealsCommittedRows;

/**
 * A refund restocks only units that never shipped. Shipped units count as delivered.
 */
class RefundRestockTest extends DatabaseTestCase
{
    // ShipmentService commits its own transaction.
    use HealsCommittedRows;

    private $module;

    public function setUp(): void
    {
        parent::setUp();
        $this->captureRowWatermarks();
        FakeRefundGateway::register();
        $this->module = new StockManagement();
        add_action('fluent_cart/order_refunded', [$this->module, 'manageStockOnOrderRefunded']);
        add_action('fluent_cart/shipping_status_changed', [$this->module, 'manageStockOnShippingStatusChanged']);
        add_filter('pre_option_' . ModuleSettings::MODULE_SETTINGS_OPTION, [$this, 'stockModuleActive']);
        ModuleSettings::getAllSettings(false);
    }

    public function tearDown(): void
    {
        remove_action('fluent_cart/order_refunded', [$this->module, 'manageStockOnOrderRefunded']);
        remove_action('fluent_cart/shipping_status_changed', [$this->module, 'manageStockOnShippingStatusChanged']);
        remove_filter('pre_option_' . ModuleSettings::MODULE_SETTINGS_OPTION, [$this, 'stockModuleActive']);
        ModuleSettings::getAllSettings(false);
        FakeRefundGateway::remove();
        $this->healCommittedRows();
        parent::tearDown();
    }

    public function test_restock_ticked_returns_an_unshipped_unit_to_the_shelf(): void
    {
        [$variant, $item, $txn] = $this->heldOrder(2);

        $this->refund($txn, $item, 1, 0, true);

        $this->assertStock($variant, 9, 1);
        $this->assertSame(1, $this->ledger($item, 'on_hold'));
    }

    public function test_restock_unticked_releases_the_hold_without_restocking(): void
    {
        [$variant, $item, $txn] = $this->heldOrder(2);

        $this->refund($txn, $item, 1, 0, false);

        $this->assertStock($variant, 8, 1);
        $this->assertSame(1, $this->ledger($item, 'on_hold'));
    }

    public function test_shipped_unit_moves_to_committed(): void
    {
        [$variant, $item, $txn] = $this->heldOrder(2);
        $this->ship($item, 2);

        $this->refund($txn, $item, 1, 1, true);

        $this->assertStock($variant, 8, 1, 1);
        $this->assertSame(1, $this->ledger($item, 'on_hold'));
        $this->assertSame(1, $this->ledger($item, 'committed'));
    }

    public function test_mixed_item_restocks_only_its_unshipped_units(): void
    {
        [$variant, $item, $txn] = $this->heldOrder(3);
        $this->ship($item, 1);

        $this->refund($txn, $item, 2, 1, true);

        $this->assertStock($variant, 8, 1, 1);
        $this->assertSame(1, $this->ledger($item, 'on_hold'));
        $this->assertSame(1, $this->ledger($item, 'committed'));
    }

    public function test_delivery_after_a_shipped_refund_leaves_no_hold(): void
    {
        [$variant, $item, $txn] = $this->heldOrder(2);
        $shipment = $this->ship($item, 2);
        $this->refund($txn, $item, 1, 1, true);

        ShipmentService::updateShipmentStatus(Shipment::find($shipment->id), Shipment::STATUS_DELIVERED);

        $this->assertSame('delivered', Order::find($item->order_id)->shipping_status);
        $this->assertStock($variant, 8, 0, 2);
    }

    public function test_refund_that_completes_delivery_commits_only_the_shipped_unit(): void
    {
        [$variant, $item, $txn] = $this->heldOrder(3);
        $shipment = $this->ship($item, 2);
        ShipmentService::updateShipmentStatus(Shipment::find($shipment->id), Shipment::STATUS_DELIVERED);

        $this->refund($txn, $item, 2, 1, true);

        $this->assertSame('delivered', Order::find($item->order_id)->shipping_status);
        $this->assertStock($variant, 8, 0, 2);
        $this->assertSame(0, $this->ledger($item, 'on_hold'));
        $this->assertSame(2, $this->ledger($item, 'committed'));
    }

    public function test_shipped_refund_after_delivery_moves_nothing(): void
    {
        [$variant, $item, $txn] = $this->heldOrder(2);
        $shipment = $this->ship($item, 2);
        ShipmentService::updateShipmentStatus(Shipment::find($shipment->id), Shipment::STATUS_DELIVERED);

        $this->refund($txn, $item, 1, 1, true);

        $this->assertStock($variant, 8, 0, 2);
    }

    public function test_deleted_shipment_releases_a_refunded_unit_that_never_shipped(): void
    {
        [$variant, $item, $txn] = $this->heldOrder(2);
        $shipment = $this->ship($item, 2);
        $this->refund($txn, $item, 1, 1, true);

        ShipmentService::deleteShipment(Shipment::find($shipment->id));

        $this->assertSame(0, (int) Arr::get(OrderItem::find($item->id)->line_meta, 'refunded_shipped_quantity', 0));
        $this->assertStock($variant, 8, 1, 0);
        $this->assertSame(1, $this->ledger($item, 'on_hold'));
        $this->assertSame(0, $this->ledger($item, 'committed'));
    }

    public function test_deleted_delivered_shipment_releases_the_refunded_unit(): void
    {
        [$variant, $item, $txn] = $this->heldOrder(2);
        $shipment = $this->ship($item, 2);
        ShipmentService::updateShipmentStatus(Shipment::find($shipment->id), Shipment::STATUS_DELIVERED);
        $this->refund($txn, $item, 1, 1, true);

        ShipmentService::deleteShipment(Shipment::find($shipment->id));

        $this->assertStock($variant, 8, 1, 0);
        $this->assertSame(1, $this->ledger($item, 'on_hold'));
        $this->assertSame(0, $this->ledger($item, 'committed'));
    }

    public function test_failed_stock_release_keeps_the_shipment(): void
    {
        [$variant, $item, $txn] = $this->heldOrder(2);
        $shipment = $this->ship($item, 2);
        $this->refund($txn, $item, 1, 1, true);

        $breakMetaWrite = FailingQueries::matching('/^\s*(update|insert)\b.*fct_order_meta/is');
        try {
            $result = ShipmentService::deleteShipment(Shipment::find($shipment->id));
        } catch (\Throwable $e) {
            $result = $e;
        } finally {
            $breakMetaWrite->remove();
        }

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertNotNull(Shipment::find($shipment->id));
        $fresh = OrderItem::find($item->id);
        $this->assertSame(2, (int) $fresh->fulfilled_quantity);
        $this->assertSame(1, (int) Arr::get($fresh->line_meta, 'refunded_shipped_quantity', 0));
        $this->assertStock($variant, 8, 1, 1);
    }

    public function test_units_of_a_deleted_shipment_count_as_unshipped(): void
    {
        [$variant, $item, $txn] = $this->heldOrder(2);
        $shipment = $this->ship($item, 2);
        ShipmentService::deleteShipment(Shipment::find($shipment->id));

        $this->refund($txn, $item, 1, 0, true);

        $this->assertStock($variant, 9, 1);
    }

    public function test_unit_shipped_after_the_plan_is_not_restocked(): void
    {
        [$variant, $item, $txn] = $this->heldOrder(2);
        $plan = RefundPlanner::plan(Order::find($item->order_id), ['transaction_id' => $txn->id, 'manageStock' => true], [
            ['id' => $item->id, 'refund_qty' => 1, 'shipped_qty' => 0],
        ]);
        $this->ship($item, 2);

        $result = (new Refund())->processRefund(OrderTransaction::find($txn->id), $plan['refund_amount'], $plan['refund_info'], $plan['refund_intent']);

        $this->assertIsArray($result);
        $this->assertSame(1, (int) Arr::get(OrderItem::find($item->id)->line_meta, 'refunded_shipped_quantity', 0));
        $this->assertStock($variant, 8, 1, 1);

        // A redelivery recounts from the row, so the row must hold the fitted values.
        $row = OrderTransaction::query()->where('order_id', $item->order_id)->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)->first();
        $this->assertSame(0, (int) Arr::get($row->meta, 'refunded_items.0.restore_quantity'));
    }

    public function stockModuleActive()
    {
        return ['stock_management' => ['active' => 'yes']];
    }

    /**
     * A paid order whose units sit on hold: 10 in stock, $qty held by this order.
     */
    private function heldOrder(int $qty): array
    {
        $product = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1, 'stock_availability' => Helper::IN_STOCK])
            ->withVariantAttributes([
                'manage_stock'     => 1,
                'fulfillment_type' => 'physical',
                'total_stock'      => 10,
                'available'        => 10 - $qty,
                'committed'        => 0,
                'on_hold'          => $qty,
                'stock_status'     => Helper::IN_STOCK,
            ])
            ->create();
        $variant = $product->variants->first();

        $order = (new OrderFactory())->withAddresses()->create([
            'customer_id'     => (new CustomerFactory())->create()->id,
            'total_amount'    => 10000 * $qty,
            'total_paid'      => 10000 * $qty,
            'payment_status'  => Status::PAYMENT_PAID,
            'payment_method'  => FakeRefundGateway::SLUG,
            'shipping_status' => 'unshipped',
        ]);
        $item = (new OrderItemFactory())->create($order->id, [
            'post_id'            => $product->ID,
            'object_id'          => $variant->id,
            'fulfillment_type'   => 'physical',
            'quantity'           => $qty,
            'fulfilled_quantity' => 0,
            'unit_price'         => 10000,
            'subtotal'           => 10000 * $qty,
            'line_total'         => 10000 * $qty,
        ]);
        OrderMeta::query()->create([
            'order_id'   => $order->id,
            'meta_key'   => 'stock_movement',
            'meta_value' => [$item->id => ['on_hold' => $qty, 'committed' => 0]],
        ]);
        $txn = (new OrderTransactionFactory())->create((int) $order->id, [
            'status'           => Status::TRANSACTION_SUCCEEDED,
            'total'            => 10000 * $qty,
            'payment_method'   => FakeRefundGateway::SLUG,
            'vendor_charge_id' => 'ch_fake_1',
        ]);

        return [$variant, $item, $txn];
    }

    private function ship(OrderItem $item, int $qty)
    {
        return ShipmentService::createShipment(Order::find($item->order_id), [
            'tracking_number' => 'TRK-' . wp_generate_password(6, false),
            'items'           => [['order_item_id' => $item->id, 'qty' => $qty]],
        ]);
    }

    private function refund(OrderTransaction $txn, OrderItem $item, int $qty, int $shippedQty, bool $restock): void
    {
        $plan = RefundPlanner::plan(Order::find($txn->order_id), ['transaction_id' => $txn->id, 'manageStock' => $restock], [
            ['id' => $item->id, 'refund_qty' => $qty, 'shipped_qty' => $shippedQty],
        ]);
        $this->assertIsArray($plan);

        $result = (new Refund())->processRefund(OrderTransaction::find($txn->id), $plan['refund_amount'], $plan['refund_info'], $plan['refund_intent']);
        $this->assertIsArray($result);
    }

    private function assertStock(ProductVariation $variant, int $available, int $onHold, int $committed = 0): void
    {
        $fresh = ProductVariation::query()->find($variant->id);
        $this->assertSame($available, (int) $fresh->available, 'available');
        $this->assertSame($onHold, (int) $fresh->on_hold, 'on_hold');
        $this->assertSame($committed, (int) $fresh->committed, 'committed');
    }

    private function ledger(OrderItem $item, string $place): int
    {
        $value = OrderMeta::query()->where('order_id', $item->order_id)->where('meta_key', 'stock_movement')->value('meta_value');
        $value = is_array($value) ? $value : (json_decode((string) $value, true) ?: []);

        return (int) Arr::get($value, $item->id . '.' . $place, 0);
    }
}
