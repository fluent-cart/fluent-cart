<?php

namespace Tests\Functional\Rest\Stock;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesStockedOrders;

/**
 * Spec: Cases/Functional/stock.md → "Admin refunding an order" section,
 * items 34-37.
 *
 * POST /fluent-cart/v2/orders/{order_id}/refund → OrderController::refundOrder
 * → Refund::processRefund dispatches OrderRefund → fluent_cart/order_refunded
 * hook → StockManagement::manageStockOnOrderRefunded. The handler is GATED on
 * the payload's manage_stock=true flag — passing manageStock='false' (or
 * omitting it) leaves the variant counters untouched (see
 * app/Modules/StockManagement/StockManagement.php manageStockOnOrderRefunded).
 * When opted in, the refund returns refunded_items[].restore_quantity to
 * available and shrinks the matching on_hold or committed bucket (priority:
 * committed-first for delivered/digital-paid, on_hold-first otherwise).
 */
class RefundOrderStockEffectsCest
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
     * Spec: Cases/Functional/stock.md [36]
     *   "Admin refunding an order without opting in to stock restoration leaves stock counts unchanged."
     */
    public function refundingOrderWithoutOptingInToStockRestorationLeavesStockUnchanged(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-refund-no-restore@example.test']);
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

        [$orderId, $orderItemId] = $this->buildPaidDigitalOrderWithCommittedStock(
            (int) $customer->id,
            (int) $product->ID,
            (int) $variant->id,
            4,
            Helper::toCent(50)
        );
        $transactionId = $this->attachSucceededTransaction($orderId, Helper::toCent(200));

        // manageStock omitted — defaults to false at the Refund::processRefund
        // boundary, so the order_refunded listener early-returns and the
        // variant counters must stay untouched.
        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . $orderId . '/refund', [
            'refund_info' => [
                'transaction_id' => $transactionId,
                'amount'         => 100,
            ],
        ]);

        Assert::assertSame(200, $status, 'refund without manageStock must still succeed for the financial side. Body: ' . wp_json_encode($body));

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            6,
            (int) $fresh->available,
            'available must stay at 6 — refund without opt-in must not return units to available'
        );
        Assert::assertSame(
            4,
            (int) $fresh->committed,
            'committed must stay at 4 — refund without opt-in must not release the commitment'
        );
        Assert::assertSame(
            0,
            (int) $fresh->on_hold,
            'on_hold must stay at 0 — no reservation existed on this paid order, none should appear'
        );

        $movement = OrderMeta::query()
            ->where('order_id', $orderId)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');
        Assert::assertIsArray($movement, 'stock_movement meta must persist as an array');
        Assert::assertArrayHasKey($orderItemId, $movement, 'stock_movement entry for the order_item must still be present (refund did not run the stock branch)');
        Assert::assertSame(
            4,
            (int) ($movement[$orderItemId]['committed'] ?? -1),
            'stock_movement.committed must stay at 4 — refund without opt-in must not shrink the recorded commitment'
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [37]
     *   "A product whose stock returns above zero after a refund is shown as in-stock again to shoppers."
     */
    public function productWhoseStockReturnsAboveZeroAfterRefundIsShownAsInStockToShoppers(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-refund-restore-visibility@example.test']);
        // Seed a variant that gets fully consumed by the buildPaidDigitalOrderWithCommittedStock call —
        // start available=2, paid qty=2 → variant after seed: available=0,
        // committed=2, stock_status=out-of-stock. Detail's stock_availability
        // is seeded out-of-stock so the shop hides the product.
        $product = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1, 'stock_availability' => 'out-of-stock'])
            ->withVariantAttributes([
                'item_price'       => Helper::toCent(50),
                'manage_stock'     => 1,
                'fulfillment_type' => 'digital',
                'stock_status'     => 'in-stock',
                'available'        => 2,
                'total_stock'      => 2,
                'on_hold'          => 0,
                'committed'        => 0,
            ])
            ->create();
        $variant = $product->variants->first();

        [$orderId, $orderItemId] = $this->buildPaidDigitalOrderWithCommittedStock(
            (int) $customer->id,
            (int) $product->ID,
            (int) $variant->id,
            2,
            Helper::toCent(50)
        );
        $transactionId = $this->attachSucceededTransaction($orderId, Helper::toCent(100));

        $beforeVariant = ProductVariation::query()->find($variant->id);
        Assert::assertSame(0, (int) $beforeVariant->available, 'precondition: variant must be fully depleted before the refund test');
        Assert::assertSame('out-of-stock', (string) $beforeVariant->stock_status, 'precondition: variant must be out-of-stock before the refund test');

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . $orderId . '/refund', [
            'refund_info' => [
                'transaction_id'  => $transactionId,
                'amount'          => 100,
                'manageStock'     => 'true',
                'item_ids'        => [$orderItemId],
                'refunded_items'  => [[
                    'id'               => $orderItemId,
                    'variation_id'     => (int) $variant->id,
                    'restore_quantity' => 2,
                ]],
            ],
        ]);

        Assert::assertSame(200, $status, 'full-quantity refund with stock restore must succeed. Body: ' . wp_json_encode($body));

        $freshVariant = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            2,
            (int) $freshVariant->available,
            'available must rise from 0 to 2 — the refunded units must return to available'
        );
        Assert::assertSame(
            'in-stock',
            (string) $freshVariant->stock_status,
            'variant stock_status must flip back to in-stock once available rises above 0'
        );

        $freshDetail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame(
            'in-stock',
            (string) $freshDetail->stock_availability,
            'product-level stock_availability must flip back to in-stock so the shop listing surfaces the product again'
        );

        // Shopper-facing surface: public listing must show the product again.
        $I->logout();
        [$publicStatus, $publicBody] = $I->get('/fluent-cart/v2/public/products', [
            'selected_status' => 1,
            'status'          => 'publish',
        ]);
        Assert::assertSame(
            200,
            $publicStatus,
            'public products endpoint must respond 200 for a guest shopper. Body: ' . wp_json_encode($publicBody)
        );

        $productIds = [];
        $stack = [$publicBody];
        while (!empty($stack)) {
            $node = array_pop($stack);
            if (!is_array($node)) {
                continue;
            }
            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $stack[] = $value;
                    continue;
                }
                if (in_array($key, ['ID', 'id', 'post_id', 'product_id'], true) && is_numeric($value)) {
                    $productIds[(int) $value] = true;
                }
            }
        }
        $productIds = array_keys($productIds);

        Assert::assertContains(
            (int) $product->ID,
            $productIds,
            'restored product must reappear in the guest-visible shop listing after a refund returns its stock. Listing: '
            . wp_json_encode($productIds)
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [53]
     *   "Admin refunding a canceled order with stock restoration does not return stock a second time, because cancelling already returned it."
     */
    public function refundingCanceledOrderDoesNotRestoreStockAgain(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-refund-after-cancel@example.test']);
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

        [$orderId, $orderItemId] = $this->buildPaidDigitalOrderWithCommittedStock(
            (int) $customer->id,
            (int) $product->ID,
            (int) $variant->id,
            3,
            Helper::toCent(50)
        );
        $transactionId = $this->attachSucceededTransaction($orderId, Helper::toCent(150));

        [$status, $body] = $I->put('/fluent-cart/v2/orders/' . $orderId . '/statuses', [
            'action'   => 'change_order_status',
            'statuses' => ['order_status' => Status::ORDER_CANCELED],
        ]);
        Assert::assertSame(200, $status, 'cancelling a paid order must succeed. Body: ' . wp_json_encode($body));
        Assert::assertSame(10, (int) ProductVariation::query()->find($variant->id)->available, 'precondition: cancel returns the 3 units (7 + 3 = 10)');

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . $orderId . '/refund', [
            'refund_info' => [
                'transaction_id' => $transactionId,
                'amount'         => 150,
                'manageStock'    => 'true',
                'item_ids'       => [$orderItemId],
                'refunded_items' => [[
                    'id'               => $orderItemId,
                    'variation_id'     => (int) $variant->id,
                    'restore_quantity' => 3,
                ]],
            ],
        ]);
        Assert::assertSame(200, $status, 'refunding a canceled paid order must succeed. Body: ' . wp_json_encode($body));

        Assert::assertSame(
            10,
            (int) ProductVariation::query()->find($variant->id)->available,
            'available must stay 10 — cancel already returned the 3 units, the refund must not add them again (13)'
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [54], [55]
     *   "Admin cannot cancel an order while one of its refunds is waiting for re-sync."
     *   "Once that refund is no longer waiting for re-sync, the admin can cancel the order."
     */
    public function cancellingOrderWithRefundAwaitingResyncIsRefused(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-cancel-pending-resync@example.test']);
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

        [$orderId] = $this->buildPaidDigitalOrderWithCommittedStock(
            (int) $customer->id,
            (int) $product->ID,
            (int) $variant->id,
            3,
            Helper::toCent(50)
        );
        $this->attachSucceededTransaction($orderId, Helper::toCent(150));
        $refund = OrderTransaction::query()->create([
            'order_id'            => $orderId,
            'order_type'          => Status::ORDER_TYPE_PAYMENT,
            'transaction_type'    => Status::TRANSACTION_TYPE_REFUND,
            'status'              => Status::TRANSACTION_REFUNDED,
            'payment_method'      => 'offline_payment',
            'payment_mode'        => Status::ORDER_MODE_LIVE,
            'payment_method_type' => 'offline_payment',
            'currency'            => 'USD',
            'total'               => Helper::toCent(50),
            'uuid'                => md5((string) mt_rand()),
            'meta'                => ['local_sync' => 'pending', 'refund_intent' => ['items' => [], 'shipping_amount' => 0, 'manage_stock' => true]],
        ]);

        [$status, $body] = $I->put('/fluent-cart/v2/orders/' . $orderId . '/statuses', [
            'action'   => 'change_order_status',
            'statuses' => ['order_status' => Status::ORDER_CANCELED],
        ]);

        Assert::assertSame(423, $status, 'cancel must be refused while a refund awaits re-sync. Body: ' . wp_json_encode($body));
        Assert::assertNotSame(Status::ORDER_CANCELED, Order::query()->find($orderId)->status, 'order must not be canceled');
        Assert::assertSame(7, (int) ProductVariation::query()->find($variant->id)->available, 'available must stay 7 — a refused cancel returns no stock');

        $refund->meta = ['local_sync' => 'done'];
        $refund->save();

        [$status, $body] = $I->put('/fluent-cart/v2/orders/' . $orderId . '/statuses', [
            'action'   => 'change_order_status',
            'statuses' => ['order_status' => Status::ORDER_CANCELED],
        ]);

        Assert::assertSame(200, $status, 'cancel must succeed once the refund is re-synced. Body: ' . wp_json_encode($body));
        Assert::assertSame(Status::ORDER_CANCELED, Order::query()->find($orderId)->status);
        Assert::assertSame(10, (int) ProductVariation::query()->find($variant->id)->available, 'cancel returns the 3 units (7 + 3 = 10)');
    }

    /**
     * Spec: Cases/Functional/stock.md [56]
     *   "A refund waiting for re-sync whose units are still on the order does not block cancelling."
     */
    public function cancellingOrderWithUnwrittenRefundAwaitingResyncIsAllowed(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-cancel-unwritten-resync@example.test']);
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

        [$orderId, $orderItemId] = $this->buildPaidDigitalOrderWithCommittedStock(
            (int) $customer->id,
            (int) $product->ID,
            (int) $variant->id,
            3,
            Helper::toCent(50)
        );
        $this->attachSucceededTransaction($orderId, Helper::toCent(150));
        OrderTransaction::query()->create([
            'order_id'            => $orderId,
            'order_type'          => Status::ORDER_TYPE_PAYMENT,
            'transaction_type'    => Status::TRANSACTION_TYPE_REFUND,
            'status'              => Status::TRANSACTION_REFUNDED,
            'payment_method'      => 'offline_payment',
            'payment_mode'        => Status::ORDER_MODE_LIVE,
            'payment_method_type' => 'offline_payment',
            'currency'            => 'USD',
            'total'               => Helper::toCent(50),
            'uuid'                => md5((string) mt_rand()),
            'meta'                => ['local_sync' => 'pending', 'refund_intent' => [
                'items'           => [['id' => $orderItemId, 'refund_qty' => 1, 'pre_refund_qty' => 3]],
                'shipping_amount' => 0,
                'manage_stock'    => true,
            ]],
        ]);

        [$status, $body] = $I->put('/fluent-cart/v2/orders/' . $orderId . '/statuses', [
            'action'   => 'change_order_status',
            'statuses' => ['order_status' => Status::ORDER_CANCELED],
        ]);

        Assert::assertSame(200, $status, 'units not yet written are restored by cancel, so cancel must not be blocked. Body: ' . wp_json_encode($body));
        Assert::assertSame(Status::ORDER_CANCELED, Order::query()->find($orderId)->status);
        Assert::assertSame(10, (int) ProductVariation::query()->find($variant->id)->available, 'cancel returns all 3 units (7 + 3 = 10)');
    }
}
