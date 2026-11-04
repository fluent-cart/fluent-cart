<?php

namespace Tests\Functional\Rest\Stock;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesStockedOrders;

/**
 * Spec: Cases/Functional/stock.md → "Items that are not stock-managed" section,
 * items 44-46.
 *
 * StockManagement listeners filter out non-purchasable line types at the head
 * of each handler:
 *
 *   $orderItems = $orderItems->filter(function ($item) {
 *       return !in_array($item->payment_type, ['signup_fee', 'fee']);
 *   });
 *
 * The same filtering also lives behind a `where('manage_stock', 1)` clause on
 * the variation query, so variants with manage_stock=0 are skipped regardless
 * of payment_type. This Cest exercises both filter dimensions through real
 * REST flows (order create + order update) so the spec's "any order action"
 * wording is honored.
 */
class NonStockManagedItemsCest
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
     * Spec: Cases/Functional/stock.md [44]
     *   "Adding a subscription signup fee to an order never changes any stock counts."
     * Fee lines are never posted as their own catalog line, so a catalog product
     * labelled as one is rejected before any stock moves.
     */
    public function addingSubscriptionSignupFeeToOrderNeverChangesStockCounts(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-signup-fee@example.test']);
        // Stock-managed variant — if the filter dropped, the listener WOULD
        // decrement its counters. Holding manage_stock=1 makes the assertion
        // meaningful: it proves the filter ran.
        $product = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1])
            ->withVariantAttributes([
                'item_price'   => Helper::toCent(25),
                'manage_stock' => 1,
                'stock_status' => 'in-stock',
                'available'    => 9,
                'total_stock'  => 9,
                'on_hold'      => 0,
                'committed'    => 0,
            ])
            ->create();
        $variant = $product->variants->first();

        [, $body] = $I->createOrder([
            'customer_id' => (int) $customer->id,
            'order_items' => [
                [
                    'post_id'          => (int) $product->ID,
                    'object_id'        => (int) $variant->id,
                    'quantity'         => 2,
                    'unit_price'       => Helper::toCent(25),
                    'fulfillment_type' => 'digital',
                    'payment_type'     => 'signup_fee',
                    'other_info'       => ['payment_type' => 'signup_fee'],
                ],
            ],
        ]);

        $I->seeResponseCodeIs(400);
        Assert::assertSame('One time product cannot be placed as a fee item.', $body['message'] ?? null, 'a catalog product posted as a signup_fee line must be rejected');

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            9,
            (int) $fresh->available,
            'available must stay at 9 — the request is rejected before any stock moves'
        );
        Assert::assertSame(
            0,
            (int) $fresh->on_hold,
            'on_hold must stay at 0 — a rejected request must not reserve stock'
        );
        Assert::assertSame(
            0,
            (int) $fresh->committed,
            'committed must stay at 0 — a rejected request must not commit stock'
        );

    }

    /**
     * Spec: Cases/Functional/stock.md [45]
     *   "Adding a standalone fee item to an order never changes any stock counts."
     */
    public function addingStandaloneFeeItemToOrderNeverChangesStockCounts(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-fee-line@example.test']);
        $product = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1])
            ->withVariantAttributes([
                'item_price'   => Helper::toCent(15),
                'manage_stock' => 1,
                'stock_status' => 'in-stock',
                'available'    => 7,
                'total_stock'  => 7,
                'on_hold'      => 0,
                'committed'    => 0,
            ])
            ->create();
        $variant = $product->variants->first();

        [, $body] = $I->createOrder([
            'customer_id' => (int) $customer->id,
            'order_items' => [
                [
                    'post_id'          => (int) $product->ID,
                    'object_id'        => (int) $variant->id,
                    'quantity'         => 1,
                    'unit_price'       => Helper::toCent(15),
                    'fulfillment_type' => 'digital',
                    'payment_type'     => 'fee',
                    'other_info'       => ['payment_type' => 'fee'],
                ],
            ],
        ]);

        $I->seeResponseCodeIs(400);
        Assert::assertSame('One time product cannot be placed as a fee item.', $body['message'] ?? null, 'a catalog product posted as a fee line must be rejected');

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            7,
            (int) $fresh->available,
            'available must stay at 7 — the request is rejected before any stock moves'
        );
        Assert::assertSame(
            0,
            (int) $fresh->on_hold,
            'on_hold must stay at 0 — a rejected request must not reserve stock'
        );
        Assert::assertSame(
            0,
            (int) $fresh->committed,
            'committed must stay at 0 — a rejected request must not commit stock'
        );

    }

    /**
     * Spec: Cases/Functional/stock.md [46]
     *   "Any order action involving a product whose stock management is turned off leaves stock counts unchanged."
     */
    public function anyOrderActionInvolvingNonStockManagedProductLeavesStockUnchanged(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-unmanaged@example.test']);
        $product = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 0])
            ->withVariantAttributes([
                'item_price'   => Helper::toCent(20),
                'manage_stock' => 0,
                'stock_status' => 'in-stock',
                'available'    => 5,
                'total_stock'  => 5,
                'on_hold'      => 0,
                'committed'    => 0,
            ])
            ->create();
        $variant = $product->variants->first();

        // Action 1: create order with non-managed variant.
        [, $createBody] = $I->createOrder([
            'customer_id' => (int) $customer->id,
            'order_items' => [
                [
                    'post_id'          => (int) $product->ID,
                    'object_id'        => (int) $variant->id,
                    'quantity'         => 2,
                    'unit_price'       => Helper::toCent(20),
                    'fulfillment_type' => 'digital',
                    'payment_type'     => 'onetime',
                    'other_info'       => ['payment_type' => 'onetime'],
                ],
            ],
        ]);

        $I->seeResponseCodeIs(200);
        $orderId = (int) ($createBody['order_id'] ?? 0);
        Assert::assertGreaterThan(0, $orderId, 'create response must include a positive order id');

        $afterCreate = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            5,
            (int) $afterCreate->available,
            'available must stay at 5 after create — variant.manage_stock=0 means the listener\'s `where(manage_stock, 1)` filter excludes this row'
        );
        Assert::assertSame(
            0,
            (int) $afterCreate->on_hold,
            'on_hold must stay at 0 after create — non-stock-managed variant must not reserve anything'
        );

        // Action 2: update the order's quantity. The order_updated listener
        // re-applies the same `where(manage_stock, 1)` filter, so this is the
        // second action covered by the spec's "any order action" wording.
        global $wpdb;
        $orderItemId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d AND object_id = %d",
            $orderId,
            (int) $variant->id
        ));
        Assert::assertGreaterThan(0, $orderItemId, 'precondition: an fct_order_items row must exist for the variant');

        [$updateStatus, $updateBody] = $I->post('/fluent-cart/v2/orders/' . $orderId, [
            'customer_id' => (int) $customer->id,
            'order_items' => [
                [
                    'id'         => $orderItemId,
                    'post_id'    => (int) $product->ID,
                    'object_id'  => (int) $variant->id,
                    'quantity'   => 4,
                    'unit_price' => Helper::toCent(20),
                    'subtotal'   => Helper::toCent(80),
                    'line_total' => Helper::toCent(80),
                ],
            ],
        ]);

        Assert::assertSame(200, $updateStatus, 'updating an order with a non-managed variant must succeed. Body: ' . wp_json_encode($updateBody));

        $afterUpdate = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            5,
            (int) $afterUpdate->available,
            'available must stay at 5 after update — non-stock-managed variant must skip the listener on quantity change too'
        );
        Assert::assertSame(
            0,
            (int) $afterUpdate->on_hold,
            'on_hold must stay at 0 after update — the order_updated listener\'s manage_stock filter must skip this row'
        );
        Assert::assertSame(
            0,
            (int) $afterUpdate->committed,
            'committed must stay at 0 — no payment or shipping transition occurred'
        );
    }
}
