<?php

namespace Tests\Functional\Rest\Stock;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesStockedOrders;

/**
 * Spec: Cases/Functional/stock.md → "Admin changing the shipping status" section,
 * items 28-30.
 *
 * PUT /fluent-cart/v2/orders/{order}/statuses with action=change_shipping_status
 * → OrderController::updateStatuses → OrderResource::updateStatuses dispatches
 * OrderStatusUpdated → fires fluent_cart/shipping_status_changed →
 * StockManagement::manageStockOnShippingStatusChanged. The listener filters on
 * variant.fulfillment_type='physical', so digital orders no-op regardless of
 * shipping_status transitions.
 */
class ChangeShippingStatusStockEffectsCest
{
    use PlacesStockedOrders;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->armStockListeners();
    }

    public function _after(FunctionalTester $I): void
    {
        $I->disarmStockListeners();
    }

    /**
     * Spec: Cases/Functional/stock.md [28]
     *   "Marking a physical order as delivered moves the order's reserved stock into committed stock."
     */
    public function markingPhysicalOrderAsDeliveredMovesReservedStockIntoCommitted(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-deliver-physical@example.test']);
        $product  = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1, 'fulfillment_type' => 'physical'])
            ->withVariantAttributes([
                'item_price'       => Helper::toCent(50),
                'manage_stock'     => 1,
                'fulfillment_type' => 'physical',
                'stock_status'     => 'in-stock',
                'available'        => 10,
                'total_stock'      => 10,
                'on_hold'          => 0,
                'committed'        => 0,
            ])
            ->create();
        $variant = $product->variants->first();

        [$orderId, $orderItemId] = $this->buildPendingOrderWithReservedStock(
            (int) $customer->id,
            (int) $product->ID,
            (int) $variant->id,
            3,
            Helper::toCent(50),
            'physical'
        );

        [$status, $body] = $I->put('/fluent-cart/v2/orders/' . $orderId . '/statuses', [
            'action'   => 'change_shipping_status',
            'statuses' => ['shipping_status' => Status::SHIPPING_DELIVERED],
        ]);

        Assert::assertSame(200, $status, 'shipping status → delivered must succeed. Body: ' . wp_json_encode($body));

        global $wpdb;
        $afterShipping = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT shipping_status FROM {$wpdb->prefix}fct_orders WHERE id = %d",
            $orderId
        ));
        Assert::assertSame('delivered', $afterShipping, 'precondition: order shipping_status must be delivered so we know the listener fired');

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            7,
            (int) $fresh->available,
            'available must stay at 7 — delivery moves stock between reserves, not back to available'
        );
        Assert::assertSame(
            0,
            (int) $fresh->on_hold,
            'on_hold must drop from 3 to 0 — all reserved units must leave the on_hold bucket on delivery'
        );
        Assert::assertSame(
            3,
            (int) $fresh->committed,
            'committed must grow from 0 to 3 — the reserved units must be promoted into committed once shipped'
        );

        $movement = OrderMeta::query()
            ->where('order_id', $orderId)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');
        Assert::assertIsArray($movement, 'stock_movement meta must persist as an array keyed by order_item id');
        Assert::assertArrayHasKey($orderItemId, $movement, 'stock_movement must still key the entry by the order_item id after the delivery transition');
        Assert::assertSame(
            3,
            (int) ($movement[$orderItemId]['committed'] ?? -1),
            'stock_movement.committed must equal the delivered quantity (3) so a later refund can roll the right amount back to available'
        );
        Assert::assertSame(
            0,
            (int) ($movement[$orderItemId]['on_hold'] ?? -1),
            'stock_movement.on_hold must drop to 0 — the reservation has been promoted to a commitment'
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [29]
     *   "Reverting a physical order's shipping status away from delivered moves the order's committed stock back to reserved."
     */
    public function revertingPhysicalOrderShippingAwayFromDeliveredMovesCommittedBackToReserved(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-revert-delivered@example.test']);
        $product  = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1, 'fulfillment_type' => 'physical'])
            ->withVariantAttributes([
                'item_price'       => Helper::toCent(50),
                'manage_stock'     => 1,
                'fulfillment_type' => 'physical',
                'stock_status'     => 'in-stock',
                'available'        => 10,
                'total_stock'      => 10,
                'on_hold'          => 0,
                'committed'        => 0,
            ])
            ->create();
        $variant = $product->variants->first();

        [$orderId, $orderItemId] = $this->buildDeliveredPhysicalOrderWithCommittedStock(
            (int) $customer->id,
            (int) $product->ID,
            (int) $variant->id,
            4,
            Helper::toCent(50)
        );

        [$status, $body] = $I->put('/fluent-cart/v2/orders/' . $orderId . '/statuses', [
            'action'   => 'change_shipping_status',
            'statuses' => ['shipping_status' => Status::SHIPPING_SHIPPED],
        ]);

        Assert::assertSame(200, $status, 'reverting shipping status → shipped must succeed. Body: ' . wp_json_encode($body));

        global $wpdb;
        $afterShipping = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT shipping_status FROM {$wpdb->prefix}fct_orders WHERE id = %d",
            $orderId
        ));
        Assert::assertSame('shipped', $afterShipping, 'precondition: order shipping_status must move away from delivered so we know the listener fired');

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            6,
            (int) $fresh->available,
            'available must stay at 6 — reverting delivery moves stock between reserves, not back to available'
        );
        Assert::assertSame(
            4,
            (int) $fresh->on_hold,
            'on_hold must grow from 0 to 4 — the committed units must return to on_hold once the order is no longer delivered'
        );
        Assert::assertSame(
            0,
            (int) $fresh->committed,
            'committed must drop from 4 to 0 — committed must mirror the reverted shipping state'
        );

        $movement = OrderMeta::query()
            ->where('order_id', $orderId)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');
        Assert::assertIsArray($movement, 'stock_movement meta must persist as an array keyed by order_item id');
        Assert::assertArrayHasKey($orderItemId, $movement, 'stock_movement must still key the entry by the order_item id after the revert');
        Assert::assertSame(
            4,
            (int) ($movement[$orderItemId]['on_hold'] ?? -1),
            'stock_movement.on_hold must grow to the original reserved quantity (4) so a later cancel/refund/delete can release the correct amount'
        );
        Assert::assertSame(
            0,
            (int) ($movement[$orderItemId]['committed'] ?? -1),
            'stock_movement.committed must drop to 0 — the commit has been demoted back to a reservation'
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [30]
     *   "Shipping status changes on a digital order do not move stock between reserved and committed."
     */
    public function shippingStatusChangeOnDigitalOrderDoesNotMoveStockBetweenReservedAndCommitted(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-ship-digital@example.test']);
        $product  = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1])
            ->withVariantAttributes([
                'item_price'       => Helper::toCent(50),
                'manage_stock'     => 1,
                'fulfillment_type' => 'digital',
                'stock_status'     => 'in-stock',
                'available'        => 10,
                'total_stock'      => 10,
                'on_hold'          => 0,
                'committed'        => 0,
            ])
            ->create();
        $variant = $product->variants->first();

        [$orderId, $orderItemId] = $this->buildPendingOrderWithReservedStock(
            (int) $customer->id,
            (int) $product->ID,
            (int) $variant->id,
            2,
            Helper::toCent(50),
            'digital'
        );

        [$status, $body] = $I->put('/fluent-cart/v2/orders/' . $orderId . '/statuses', [
            'action'   => 'change_shipping_status',
            'statuses' => ['shipping_status' => Status::SHIPPING_DELIVERED],
        ]);

        Assert::assertSame(200, $status, 'shipping status update on a digital order must still succeed. Body: ' . wp_json_encode($body));

        global $wpdb;
        $afterShipping = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT shipping_status FROM {$wpdb->prefix}fct_orders WHERE id = %d",
            $orderId
        ));
        Assert::assertSame('delivered', $afterShipping, 'precondition: order shipping_status must persist as delivered so we know the listener was given the chance to fire');

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            8,
            (int) $fresh->available,
            'available must stay at 8 — digital variants are filtered out by the listener regardless of shipping_status change'
        );
        Assert::assertSame(
            2,
            (int) $fresh->on_hold,
            'on_hold must stay at 2 — digital reservations are not touched by shipping_status transitions'
        );
        Assert::assertSame(
            0,
            (int) $fresh->committed,
            'committed must stay at 0 — digital orders only commit through order_paid, never through shipping_status'
        );

        $movement = OrderMeta::query()
            ->where('order_id', $orderId)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');
        Assert::assertIsArray($movement, 'stock_movement meta must persist as an array keyed by order_item id');
        Assert::assertArrayHasKey($orderItemId, $movement, 'stock_movement must still key the entry by the order_item id');
        Assert::assertSame(
            2,
            (int) ($movement[$orderItemId]['on_hold'] ?? -1),
            'stock_movement.on_hold must stay at 2 — the reservation must persist; shipping transitions on a digital order are a no-op for stock'
        );
        Assert::assertSame(
            0,
            (int) ($movement[$orderItemId]['committed'] ?? 0),
            'stock_movement.committed must remain 0 (or absent) — shipping transitions never commit a digital reservation'
        );
    }
}
