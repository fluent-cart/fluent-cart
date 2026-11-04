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
 * Spec: Cases/Functional/stock.md → "Bundle products" section, items 41-43.
 *
 * The bundle stock listener walks `variation->bundleChildren()` inside
 * manageStockOnOrderCreated / manageStockOnOrderRefunded
 * (app/Modules/StockManagement/StockManagement.php) and applies the same
 * delta to every stock-managed child variant. This Cest exercises the order
 * create + refund REST paths for a bundle and asserts both the parent and
 * child counter updates.
 */
class BundleStockEffectsCest
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
     * Spec: Cases/Functional/stock.md [42]
     *   "Creating an order containing a bundle reduces the available stock of each stock-managed child product by the ordered bundle quantity."
     */
    public function creatingOrderWithBundleReducesAvailableOfEachStockManagedChild(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-bundle-create@example.test']);

        $childA = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1])
            ->withVariantAttributes([
                'item_price'   => Helper::toCent(30),
                'manage_stock' => 1,
                'stock_status' => 'in-stock',
                'available'    => 8,
                'total_stock'  => 8,
                'on_hold'      => 0,
                'committed'    => 0,
            ])
            ->create();
        $childB = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1])
            ->withVariantAttributes([
                'item_price'   => Helper::toCent(20),
                'manage_stock' => 1,
                'stock_status' => 'in-stock',
                'available'    => 6,
                'total_stock'  => 6,
                'on_hold'      => 0,
                'committed'    => 0,
            ])
            ->create();
        $childAVariant = $childA->variants->first();
        $childBVariant = $childB->variants->first();

        $bundle = (new ProductFactory())->createBundle([
            (int) $childAVariant->id,
            (int) $childBVariant->id,
        ]);
        $bundleVariant = $bundle->variants->first();
        // Bundle parent is itself stock-managed so the listener processes it.
        ProductVariation::query()->where('id', $bundleVariant->id)->update([
            'manage_stock' => 1,
            'available'    => 5,
            'total_stock'  => 5,
            'stock_status' => 'in-stock',
        ]);

        [, $body] = $I->createOrder([
            'customer_id' => (int) $customer->id,
            'order_items' => [
                [
                    'post_id'          => (int) $bundle->ID,
                    'object_id'        => (int) $bundleVariant->id,
                    'quantity'         => 2,
                    'unit_price'       => Helper::toCent(50),
                    'fulfillment_type' => 'digital',
                    'payment_type'     => 'onetime',
                    'other_info'       => ['payment_type' => 'onetime'],
                ],
            ],
        ]);

        $I->seeResponseCodeIs(200);
        $orderId = (int) ($body['order_id'] ?? 0);
        Assert::assertGreaterThan(0, $orderId, 'create response must include a positive order id');

        $freshA = ProductVariation::query()->find($childAVariant->id);
        Assert::assertSame(
            6,
            (int) $freshA->available,
            'child A available must drop from 8 to 6 — the bundle order quantity of 2 must consume 2 units of every stock-managed child'
        );
        Assert::assertSame(
            2,
            (int) $freshA->on_hold,
            'child A on_hold must grow from 0 to 2 — the bundle reservation must apply to every stock-managed child'
        );

        $freshB = ProductVariation::query()->find($childBVariant->id);
        Assert::assertSame(
            4,
            (int) $freshB->available,
            'child B available must drop from 6 to 4 — same bundle quantity must decrement every child equally'
        );
        Assert::assertSame(
            2,
            (int) $freshB->on_hold,
            'child B on_hold must grow from 0 to 2 — same bundle quantity must reserve against every child equally'
        );

        $freshBundle = ProductVariation::query()->find($bundleVariant->id);
        Assert::assertSame(
            3,
            (int) $freshBundle->available,
            'bundle parent available must drop from 5 to 3 — the bundle variant itself is stock-managed and the order consumed 2 units'
        );
        Assert::assertSame(
            2,
            (int) $freshBundle->on_hold,
            'bundle parent on_hold must grow from 0 to 2 — reservation applies to the parent too'
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [43]
     *   "Refunding a bundle product returns stock to each of its stock-managed child products."
     */
    public function refundingBundleReturnsStockToEachStockManagedChild(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-bundle-refund@example.test']);

        // Seed two stock-managed children + a bundle parent in the
        // post-paid state (committed=quantity on every stock-managed variant)
        // by walking the trait + direct counter prep. The trait covers the
        // bundle parent; the children need parallel counter prep because the
        // trait builds one stock_movement entry per order_item, but the
        // listener iterates bundleChildren() at refund time and reads its
        // counters from the DB directly.
        $childA = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1])
            ->withVariantAttributes([
                'item_price'   => Helper::toCent(30),
                'manage_stock' => 1,
                'stock_status' => 'in-stock',
                'available'    => 7,
                'total_stock'  => 10,
                'on_hold'      => 0,
                'committed'    => 3,
            ])
            ->create();
        $childB = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1])
            ->withVariantAttributes([
                'item_price'   => Helper::toCent(20),
                'manage_stock' => 1,
                'stock_status' => 'in-stock',
                'available'    => 5,
                'total_stock'  => 8,
                'on_hold'      => 0,
                'committed'    => 3,
            ])
            ->create();
        $childAVariant = $childA->variants->first();
        $childBVariant = $childB->variants->first();

        $bundle = (new ProductFactory())->createBundle([
            (int) $childAVariant->id,
            (int) $childBVariant->id,
        ]);
        $bundleVariant = $bundle->variants->first();
        ProductVariation::query()->where('id', $bundleVariant->id)->update([
            'manage_stock' => 1,
            'available'    => 7,
            'total_stock'  => 10,
            'committed'    => 3,
            'stock_status' => 'in-stock',
        ]);

        [$orderId, $bundleOrderItemId] = $this->buildPaidDigitalOrderWithCommittedStock(
            (int) $customer->id,
            (int) $bundle->ID,
            (int) $bundleVariant->id,
            3,
            Helper::toCent(50)
        );
        $transactionId = $this->attachSucceededTransaction($orderId, Helper::toCent(150));

        // Trait subtracted 3 more from bundle parent's available (7 → 4) and
        // added 3 to committed (3 → 6). Renormalize so the parent matches the
        // children's "committed=3" state for the refund delta math to read
        // cleanly.
        ProductVariation::query()->where('id', $bundleVariant->id)->update([
            'available' => 7,
            'committed' => 3,
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . $orderId . '/refund', [
            'refund_info' => [
                'transaction_id'  => $transactionId,
                'amount'          => 150,
                'manageStock'     => 'true',
                'item_ids'        => [$bundleOrderItemId],
                'refunded_items'  => [[
                    'id'               => $bundleOrderItemId,
                    'variation_id'     => (int) $bundleVariant->id,
                    'restore_quantity' => 3,
                ]],
            ],
        ]);

        Assert::assertSame(200, $status, 'bundle refund must succeed. Body: ' . wp_json_encode($body));

        $freshA = ProductVariation::query()->find($childAVariant->id);
        Assert::assertSame(
            10,
            (int) $freshA->available,
            'child A available must grow from 7 to 10 — the 3 refunded bundle units must return stock to each child (7 + 3 = 10)'
        );
        Assert::assertSame(
            0,
            (int) $freshA->committed,
            'child A committed must drop from 3 to 0 — the refund releases the child commitment'
        );

        $freshB = ProductVariation::query()->find($childBVariant->id);
        Assert::assertSame(
            8,
            (int) $freshB->available,
            'child B available must grow from 5 to 8 — refund of bundle quantity 3 must restore 3 units to every stock-managed child equally'
        );
        Assert::assertSame(
            0,
            (int) $freshB->committed,
            'child B committed must drop from 3 to 0 — the refund releases the child commitment'
        );
    }
}
