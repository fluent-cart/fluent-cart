<?php

namespace Tests\Functional\Rest\Stock;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesStockedOrders;

/**
 * Spec: Cases/Functional/stock.md → "Admin paying an order" section, items 26-27.
 *
 * POST /fluent-cart/v2/orders/{id}/mark-as-paid → OrderController::markAsPaid
 * dispatches OrderPaid → fluent_cart/order_paid hook → StockManagement's
 * manageStockOnOrderPaid handler. The handler filters variants on
 * fulfillment_type='digital' (app/Modules/StockManagement/StockManagement.php),
 * so digital reservations move on_hold → committed while physical reservations
 * stay in on_hold until delivery.
 */
class PayOrderStockEffectsCest
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
     * Spec: Cases/Functional/stock.md [26]
     *   "Marking a digital order as paid moves the order's reserved stock into committed stock."
     */
    public function markingDigitalOrderAsPaidMovesReservedStockIntoCommittedStock(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-pay-digital@example.test']);
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
            3,
            Helper::toCent(50),
            'digital'
        );

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . $orderId . '/mark-as-paid', [
            'payment_method'   => 'offline_payment',
            'transaction_type' => 'charge',
        ]);

        Assert::assertSame(200, $status, 'mark-as-paid must succeed for a pending digital order. Body: ' . wp_json_encode($body));

        global $wpdb;
        $afterPaymentStatus = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT payment_status FROM {$wpdb->prefix}fct_orders WHERE id = %d",
            $orderId
        ));
        Assert::assertSame('paid', $afterPaymentStatus, 'precondition: order payment_status must be paid so we know the order_paid hook fired');

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            7,
            (int) $fresh->available,
            'available must stay at 7 — paying an order moves stock between reserves but does not return units to available'
        );
        Assert::assertSame(
            0,
            (int) $fresh->on_hold,
            'on_hold must drop from 3 to 0 — all reserved units must move out of the on_hold bucket on payment of a digital order'
        );
        Assert::assertSame(
            3,
            (int) $fresh->committed,
            'committed must grow from 0 to 3 — the units the buyer paid for must be transferred into committed stock'
        );

        $movement = OrderMeta::query()
            ->where('order_id', $orderId)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');
        Assert::assertIsArray($movement, 'stock_movement meta must persist as an array keyed by order_item id');
        Assert::assertArrayHasKey($orderItemId, $movement, 'stock_movement must still key the entry by the order_item id after the payment transition');
        Assert::assertSame(
            3,
            (int) ($movement[$orderItemId]['committed'] ?? -1),
            'stock_movement.committed must equal the paid quantity (3) so a later refund can roll the right amount back to available'
        );
        Assert::assertSame(
            0,
            (int) ($movement[$orderItemId]['on_hold'] ?? -1),
            'stock_movement.on_hold must drop to 0 — the reservation has been promoted to a commitment'
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [27]
     *   "Marking a physical order as paid does not move reserved stock into committed stock; that only happens on delivery."
     */
    public function markingPhysicalOrderAsPaidDoesNotMoveReservedStockIntoCommittedStock(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-pay-physical@example.test']);
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
            4,
            Helper::toCent(50),
            'physical'
        );

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . $orderId . '/mark-as-paid', [
            'payment_method'   => 'offline_payment',
            'transaction_type' => 'charge',
        ]);

        Assert::assertSame(200, $status, 'mark-as-paid must succeed for a pending physical order. Body: ' . wp_json_encode($body));

        global $wpdb;
        $afterPaymentStatus = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT payment_status FROM {$wpdb->prefix}fct_orders WHERE id = %d",
            $orderId
        ));
        Assert::assertSame('paid', $afterPaymentStatus, 'precondition: order payment_status must be paid so we know the order_paid hook fired');

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            6,
            (int) $fresh->available,
            'available must stay at 6 — payment of a physical order must not return any units to available'
        );
        Assert::assertSame(
            4,
            (int) $fresh->on_hold,
            'on_hold must stay at 4 — physical units stay reserved until delivery, not on payment'
        );
        Assert::assertSame(
            0,
            (int) $fresh->committed,
            'committed must stay at 0 — physical orders only commit stock on shipping-status=delivered, not on payment'
        );

        $movement = OrderMeta::query()
            ->where('order_id', $orderId)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');
        Assert::assertIsArray($movement, 'stock_movement meta must persist as an array keyed by order_item id');
        Assert::assertArrayHasKey($orderItemId, $movement, 'stock_movement must still key the entry by the order_item id after the payment transition');
        Assert::assertSame(
            4,
            (int) ($movement[$orderItemId]['on_hold'] ?? -1),
            'stock_movement.on_hold must stay at 4 — the reservation must persist against the order until shipping marks it delivered'
        );
        Assert::assertSame(
            0,
            (int) ($movement[$orderItemId]['committed'] ?? 0),
            'stock_movement.committed must remain 0 (or absent) — committing happens only on delivery for a physical order'
        );
    }
}
