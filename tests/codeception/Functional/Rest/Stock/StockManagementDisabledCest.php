<?php

namespace Tests\Functional\Rest\Stock;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/stock.md → "When stock management is disabled"
 * section, items 48-49.
 *
 * The plugin's boot-time gate at
 * app/Modules/StockManagement/StockManagement.php:52-54 short-circuits
 * StockManagement::register before binding the six order/shipping/refund/pay
 * /update listeners, so when the module is off NO event handler ever fires on
 * the order lifecycle. The shop_query filter is registered unconditionally but
 * the OOS hide branch (lines 97-106) is gated on ModuleSettings::isActive, so
 * with the module off the public listing returns OOS products without any
 * extra opt-in. These tests deliberately skip armStockListeners() — they
 * exercise the suite's default-off state, and to keep the assertions
 * non-tautological they seed stock_movement metadata + test-mode orders so a
 * regression that wrongly restored stock during delete/refund would visibly
 * move the variant counters.
 */
class StockManagementDisabledCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    public function _after(FunctionalTester $I): void
    {
        delete_option(ModuleSettings::MODULE_SETTINGS_OPTION);
        ModuleSettings::getAllSettings(false);
    }

    private function seededVariant(): ProductVariation
    {
        $product = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1])
            ->withVariantAttributes([
                'item_price'   => Helper::toCent(50),
                'manage_stock' => 1,
                'stock_status' => 'in-stock',
                'available'    => 10,
                'total_stock'  => 10,
                'on_hold'      => 0,
                'committed'    => 0,
            ])
            ->create();
        return $product->variants->first();
    }

    private function assertVariantCountersUnchanged(int $variantId, string $stage): void
    {
        $fresh = ProductVariation::query()->find($variantId);
        Assert::assertNotNull($fresh, "variant row must still exist after {$stage} when stock_management is disabled");
        Assert::assertSame(10, (int) $fresh->available,   "available must remain 10 after {$stage} — module-off must leave variant counters frozen");
        Assert::assertSame(0,  (int) $fresh->on_hold,     "on_hold must remain 0 after {$stage} — module-off must not reserve any stock");
        Assert::assertSame(0,  (int) $fresh->committed,   "committed must remain 0 after {$stage} — module-off must not commit any stock");
        Assert::assertSame(10, (int) $fresh->total_stock, "total_stock must remain 10 after {$stage} — module-off must not change total inventory");
    }

    /**
     * Spec: Cases/Functional/stock.md [48]
     *   "With stock management disabled, creating, paying, cancelling, refunding, or deleting an order makes no changes to stock counts."
     */
    public function withStockManagementDisabledCreatingPayingCancellingRefundingOrDeletingAnOrderMakesNoChangesToStockCounts(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer  = (new CustomerFactory())->create(['email' => 'stock-disabled-48@example.test']);
        $variant   = $this->seededVariant();
        $variantId = (int) $variant->id;
        $productId = (int) $variant->post_id;

        // 1. CREATE — fires fluent_cart/order_created. With the listener unbound, the
        // variant must not decrement, reserve, or commit.
        [, $createBody] = $I->createOrder([
            'customer_id' => (int) $customer->id,
            'order_items' => [
                [
                    'post_id'          => $productId,
                    'object_id'        => $variantId,
                    'quantity'         => 3,
                    'unit_price'       => Helper::toCent(50),
                    'fulfillment_type' => 'digital',
                    'payment_type'     => 'onetime',
                    'other_info'       => ['payment_type' => 'onetime'],
                ],
            ],
        ]);
        $I->seeResponseCodeIs(200);
        $createdOrderId = (int) ($createBody['order_id'] ?? 0);
        Assert::assertGreaterThan(0, $createdOrderId, 'create response must include the new order id');
        $this->assertVariantCountersUnchanged($variantId, 'order-create');

        // 2. PAY — fires fluent_cart/order_paid. Seed a pending digital order with an item
        // referencing the same variant so the listener path would otherwise move on_hold→committed.
        $payOrder = (new OrderFactory())->create([
            'customer_id'      => (int) $customer->id,
            'status'           => Status::ORDER_ON_HOLD,
            'payment_status'   => Status::PAYMENT_PENDING,
            'shipping_status'  => Status::SHIPPING_UNSHIPPED,
            'fulfillment_type' => 'digital',
            'subtotal'         => Helper::toCent(50),
            'total_amount'     => Helper::toCent(50),
            'invoice_no'       => 'INV-DISABLED-48-PAY',
        ]);
        (new OrderItemFactory())->create((int) $payOrder->id, [
            'post_id'          => $productId,
            'object_id'        => $variantId,
            'quantity'         => 1,
            'unit_price'       => Helper::toCent(50),
            'subtotal'         => Helper::toCent(50),
            'line_total'       => Helper::toCent(50),
            'fulfillment_type' => 'digital',
            'payment_type'     => 'onetime',
            'other_info'       => ['payment_type' => 'onetime'],
        ]);
        [$payStatus, $payBody] = $I->post('/fluent-cart/v2/orders/' . (int) $payOrder->id . '/mark-as-paid', []);
        Assert::assertSame(200, $payStatus, 'mark-as-paid must succeed when stock_management is disabled. Body: ' . wp_json_encode($payBody));
        $this->assertVariantCountersUnchanged($variantId, 'mark-as-paid');

        // 3. CANCEL — fires fluent_cart/order_status_changed (status→canceled). Seed a
        // separate on-hold order and flip its status through the status route.
        $cancelOrder = (new OrderFactory())->create([
            'customer_id'      => (int) $customer->id,
            'status'           => Status::ORDER_ON_HOLD,
            'payment_status'   => Status::PAYMENT_PENDING,
            'shipping_status'  => Status::SHIPPING_UNSHIPPED,
            'fulfillment_type' => 'digital',
            'subtotal'         => Helper::toCent(50),
            'total_amount'     => Helper::toCent(50),
            'invoice_no'       => 'INV-DISABLED-48-CANCEL',
        ]);
        (new OrderItemFactory())->create((int) $cancelOrder->id, [
            'post_id'          => $productId,
            'object_id'        => $variantId,
            'quantity'         => 1,
            'unit_price'       => Helper::toCent(50),
            'subtotal'         => Helper::toCent(50),
            'line_total'       => Helper::toCent(50),
            'fulfillment_type' => 'digital',
            'payment_type'     => 'onetime',
            'other_info'       => ['payment_type' => 'onetime'],
        ]);
        [$cancelStatus, $cancelBody] = $I->put('/fluent-cart/v2/orders/' . (int) $cancelOrder->id . '/statuses', [
            'action'   => 'change_order_status',
            'statuses' => ['order_status' => Status::ORDER_CANCELED],
        ]);
        Assert::assertSame(200, $cancelStatus, 'cancel via status route must succeed when stock_management is disabled. Body: ' . wp_json_encode($cancelBody));
        $this->assertVariantCountersUnchanged($variantId, 'cancel');

        // 4. REFUND — fires fluent_cart/order_refunded. Seed a paid order with a succeeded
        // charge transaction so the refund endpoint accepts the request. The payload
        // mirrors RefundOrderStockEffectsCest's full-refund shape (manageStock=true +
        // refunded_items.restore_quantity) so a buggy production would actually try to
        // restore stock if it ignored the module-off gate — the assertion then meaningfully
        // probes that gate.
        $refundOrder = (new OrderFactory())->create([
            'customer_id'      => (int) $customer->id,
            'status'           => Status::ORDER_PROCESSING,
            'payment_status'   => Status::PAYMENT_PAID,
            'shipping_status'  => Status::SHIPPING_UNSHIPPED,
            'fulfillment_type' => 'digital',
            'subtotal'         => Helper::toCent(50),
            'total_amount'     => Helper::toCent(50),
            'total_paid'       => Helper::toCent(50),
            'invoice_no'       => 'INV-DISABLED-48-REFUND',
        ]);
        $refundOrderItem = (new OrderItemFactory())->create((int) $refundOrder->id, [
            'post_id'          => $productId,
            'object_id'        => $variantId,
            'quantity'         => 1,
            'unit_price'       => Helper::toCent(50),
            'subtotal'         => Helper::toCent(50),
            'line_total'       => Helper::toCent(50),
            'fulfillment_type' => 'digital',
            'payment_type'     => 'onetime',
            'other_info'       => ['payment_type' => 'onetime'],
        ]);
        $refundTxn = (new OrderTransactionFactory())->create((int) $refundOrder->id, [
            'status' => Status::TRANSACTION_SUCCEEDED,
            'total'  => Helper::toCent(50),
        ]);
        [$refundStatus, $refundBody] = $I->post('/fluent-cart/v2/orders/' . (int) $refundOrder->id . '/refund', [
            'refund_info' => [
                'transaction_id'  => (int) $refundTxn->id,
                'amount'          => 50,
                'manageStock'     => 'true',
                'item_ids'        => [(int) $refundOrderItem->id],
                'refunded_items'  => [[
                    'id'               => (int) $refundOrderItem->id,
                    'variation_id'     => $variantId,
                    'restore_quantity' => 1,
                ]],
                'note'            => 'stock-disabled refund probe',
            ],
        ]);
        Assert::assertSame(200, $refundStatus, 'refund must succeed when stock_management is disabled. Body: ' . wp_json_encode($refundBody));
        $this->assertVariantCountersUnchanged($variantId, 'refund');

        // 5. DELETE — fires fluent_cart/order_status_changed and OrderDeleting. Seed the
        // deletable order in test mode AND attach stock_movement metadata that records a
        // reservation. With module-on this state would trigger the OrderDeleting listener's
        // restore-on-delete path; with module-off the listener is unbound so the metadata
        // is harmless and the variant counters must stay frozen.
        $deleteOrder = (new OrderFactory())->create([
            'customer_id'      => (int) $customer->id,
            'status'           => Status::ORDER_ON_HOLD,
            'payment_status'   => Status::PAYMENT_PENDING,
            'shipping_status'  => Status::SHIPPING_UNSHIPPED,
            'fulfillment_type' => 'digital',
            'mode'             => Status::ORDER_MODE_TEST,
            'subtotal'         => Helper::toCent(50),
            'total_amount'     => Helper::toCent(50),
            'invoice_no'       => 'INV-DISABLED-48-DELETE',
        ]);
        $deleteOrderItem = (new OrderItemFactory())->create((int) $deleteOrder->id, [
            'post_id'          => $productId,
            'object_id'        => $variantId,
            'quantity'         => 1,
            'unit_price'       => Helper::toCent(50),
            'subtotal'         => Helper::toCent(50),
            'line_total'       => Helper::toCent(50),
            'fulfillment_type' => 'digital',
            'payment_type'     => 'onetime',
            'other_info'       => ['payment_type' => 'onetime'],
        ]);
        OrderMeta::query()->create([
            'order_id'   => (int) $deleteOrder->id,
            'meta_key'   => 'stock_movement',
            'meta_value' => [(int) $deleteOrderItem->id => ['on_hold' => 1]],
        ]);
        [$deleteStatus, $deleteBody] = $I->delete('/fluent-cart/v2/orders/' . (int) $deleteOrder->id);
        Assert::assertSame(200, $deleteStatus, 'delete must succeed when stock_management is disabled. Body: ' . wp_json_encode($deleteBody));
        $this->assertVariantCountersUnchanged($variantId, 'delete');
    }

    /**
     * Spec: Cases/Functional/stock.md [49]
     *   "With stock management disabled, the shop listing shows every product regardless of stock status."
     */
    public function shopListingShowsEveryProductRegardlessOfStockStatusWhenStockManagementDisabled(FunctionalTester $I): void
    {
        // module-off precondition is the suite default — no enable, no listener arm.
        $I->logout();

        $inStock = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1, 'stock_availability' => 'in-stock'])
            ->withVariantAttributes([
                'manage_stock' => 1,
                'stock_status' => 'in-stock',
                'available'    => 5,
                'total_stock'  => 5,
                'item_price'   => Helper::toCent(10),
            ])
            ->create(['post_title' => 'StockDisabledInStock49']);

        $outOfStock = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1, 'stock_availability' => 'out-of-stock'])
            ->withVariantAttributes([
                'manage_stock' => 1,
                'stock_status' => 'out-of-stock',
                'available'    => 0,
                'total_stock'  => 0,
                'item_price'   => Helper::toCent(20),
            ])
            ->create(['post_title' => 'StockDisabledOOS49']);

        // Scope the listing to the two fixture ids so stale wp_posts left over from
        // earlier Cests (resetFluentCartTables only truncates fct_* tables) cannot
        // push our fixtures out of the default first page.
        [$status, $body] = $I->get('/fluent-cart/v2/public/products', [
            'include_ids' => [(int) $inStock->ID, (int) $outOfStock->ID],
        ]);
        Assert::assertSame(200, $status, 'public products endpoint must respond 200 when stock_management is disabled. Body: ' . wp_json_encode($body));

        $ids = array_map(static fn ($r) => (int) ($r['ID'] ?? 0), (array) ($body['products']['products']['data'] ?? []));
        Assert::assertContains((int) $inStock->ID, $ids, 'in-stock product must appear in the public listing when stock_management is disabled');
        Assert::assertContains(
            (int) $outOfStock->ID,
            $ids,
            'out-of-stock product must ALSO appear in the public listing when stock_management is disabled — the OOS hide branch in filterShopQuery is gated on ModuleSettings::isActive(stock_management)=true'
        );
    }
}
