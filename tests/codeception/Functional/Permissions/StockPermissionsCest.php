<?php

namespace Tests\Functional\Permissions;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/stock.md → "Permissions" section, items 50-51.
 *
 * Stock has no admin endpoints of its own — its decrement listener is
 * fluent_cart/order_created, fired by OrderController::store. These tests arm
 * the listener so the side-effect chain is wired, then probe denied callers
 * (worker without orders/create, logged-out guest) at the order-create
 * endpoint and assert (a) the policy gate rejected the request before any
 * listener could fire, and (b) the variant counters did not move. Without
 * arming the listener the assertion that "denial prevents the stock
 * decrement" would be tautological — the decrement would be absent even on
 * an allowed call.
 */
class StockPermissionsCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->armStockListeners();
    }

    public function _after(FunctionalTester $I): void
    {
        $I->disarmStockListeners();
    }

    private function seededVariantId(): int
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
        return (int) $product->variants->first()->id;
    }

    private function assertVariantCountersUnchanged(int $variantId, string $caller): void
    {
        $fresh = ProductVariation::query()->find($variantId);
        Assert::assertNotNull($fresh, "variant row must still exist after a denied {$caller} create-order attempt");
        Assert::assertSame(10, (int) $fresh->available,   "available must remain 10 — denied {$caller} request must not reach the stock listener");
        Assert::assertSame(0,  (int) $fresh->on_hold,     "on_hold must remain 0 — denied {$caller} request must not reserve stock");
        Assert::assertSame(0,  (int) $fresh->committed,   "committed must remain 0 — denied {$caller} request must not commit stock");
        Assert::assertSame(10, (int) $fresh->total_stock, "total_stock must remain 10 — denied {$caller} request must not change inventory totals");
    }

    /**
     * Spec: Cases/Functional/stock.md [50]
     *   "A worker without the \"create orders\" permission cannot trigger the stock decrement that would follow from creating an order."
     */
    public function workerWithoutCreateOrdersPermissionCannotTriggerStockDecrement(FunctionalTester $I): void
    {
        $customer  = (new CustomerFactory())->create(['email' => 'stock-perm-50@example.test']);
        $variantId = $this->seededVariantId();
        $product   = ProductVariation::query()->find($variantId);
        $productId = (int) $product->post_id;

        $I->loginAsWorker();

        [$status, $body] = $I->post('/fluent-cart/v2/orders', [
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

        Assert::assertSame(403, $status, 'worker-without-create create-order must be rejected with 403. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'worker-without-create denial must surface rest_forbidden code. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['message'] ?? '', 'worker-without-create denial must include a permission-denied message');

        global $wpdb;
        Assert::assertSame(
            0,
            (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders"),
            'worker-without-create denial must NOT persist any fct_orders row'
        );
        $this->assertVariantCountersUnchanged($variantId, 'worker-without-create');
    }

    /**
     * Spec: Cases/Functional/stock.md [51]
     *   "A logged-out visitor cannot trigger any admin-side stock transition because they cannot access the admin order endpoints."
     */
    public function loggedOutVisitorCannotTriggerAdminSideStockTransition(FunctionalTester $I): void
    {
        $customer  = (new CustomerFactory())->create(['email' => 'stock-perm-51@example.test']);
        $variantId = $this->seededVariantId();
        $product   = ProductVariation::query()->find($variantId);
        $productId = (int) $product->post_id;

        $I->logout();

        [$status, $body] = $I->post('/fluent-cart/v2/orders', [
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

        Assert::assertSame(401, $status, 'guest create-order must be rejected with 401 unauthorized. Body: ' . wp_json_encode($body));
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'guest create-order denial must surface rest_forbidden code. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['message'] ?? '', 'guest create-order denial must include a permission-denied message');

        global $wpdb;
        Assert::assertSame(
            0,
            (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders"),
            'guest create-order denial must NOT persist any fct_orders row'
        );
        $this->assertVariantCountersUnchanged($variantId, 'guest');
    }
}
