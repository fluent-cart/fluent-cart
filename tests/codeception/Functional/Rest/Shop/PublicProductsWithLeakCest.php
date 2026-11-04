<?php

namespace Tests\Functional\Rest\Shop;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Security regression: GET public/products is registered under PublicPolicy
 * (app/Http/Routes/frontend_routes.php), i.e. NO authentication at all, and
 * ShopController::getProducts() forwarded the request's `with` straight into
 * ShopResource::get() -> Product::with().
 *
 * `Product::orderItems()` is a real relation, so an anonymous caller could walk
 * `orderItems.order.customer` off the public storefront and read customer email
 * addresses and order history — and `...customer.wpUser` reached the WordPress
 * users table for the password hash.
 *
 * The storefront never chooses its own eager loads: the controller now passes a
 * fixed relation list and ignores the request entirely.
 */
class PublicProductsWithLeakCest
{
    private const ROUTE = '/fluent-cart/v2/public/products';

    /**
     * Relation chains an anonymous caller must never be able to request.
     */
    private const HOSTILE_WITHS = [
        'orderItems',
        'orderItems.order',
        'orderItems.order.customer',
        'orderItems.order.customer.wpUser',
        'orderItems.order.transactions',
        'downloadable_files',
    ];

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * The headline case. Seeds a real product -> order item -> order -> customer
     * chain so the relation would genuinely resolve if it were honoured, then
     * asserts the customer's email never reaches an anonymous caller.
     */
    public function anonymousCallerCannotWalkToCustomerData(FunctionalTester $I): void
    {
        $email = 'storefront-leak@example.test';
        $this->seedPurchasedProduct($email);

        $I->logout();

        foreach (self::HOSTILE_WITHS as $with) {
            [$status, $body] = $I->get(self::ROUTE, ['with' => [$with]]);

            Assert::assertSame(
                200,
                $status,
                "public/products must still answer for with[]={$with}, not error"
            );

            $encoded = json_encode($body);

            Assert::assertStringNotContainsString(
                $email,
                $encoded,
                "with[]={$with} must not expose customer data to an anonymous caller"
            );
            Assert::assertStringNotContainsString(
                'user_pass',
                $encoded,
                "with[]={$with} must not reach the WordPress users table"
            );
            Assert::assertStringNotContainsString(
                'order_items',
                $encoded,
                "with[]={$with} must not attach order data to a storefront product"
            );
        }
    }

    /**
     * The column-select suffix shape — a naive guard that matches on the relation
     * name alone still lets `relation:col,col` through, and the suffix names the
     * columns explicitly.
     */
    public function columnSelectSuffixIsNotHonoured(FunctionalTester $I): void
    {
        $email = 'storefront-suffix@example.test';
        $this->seedPurchasedProduct($email);

        $I->logout();

        [$status, $body] = $I->get(self::ROUTE, [
            'with' => ['orderItems.order.customer:id,email'],
        ]);

        Assert::assertSame(200, $status);
        Assert::assertStringNotContainsString($email, json_encode($body));
    }

    /**
     * The storefront's own payload must be unchanged: `detail` and `variants` are
     * what the `thumbnail` and `has_subscription` appends read, and they were
     * already present via lazy loading before the fix.
     */
    public function storefrontPayloadStillCarriesItsOwnRelations(FunctionalTester $I): void
    {
        $this->seedPurchasedProduct('storefront-shape@example.test');

        $I->logout();

        [$status, $body] = $I->get(self::ROUTE);

        Assert::assertSame(200, $status);

        $products = $body['products']['products']['data'] ?? [];

        Assert::assertNotEmpty($products, 'The public storefront still returns products');
        Assert::assertArrayHasKey(
            'detail',
            $products[0],
            'thumbnail reads through detail, so it must still be loaded'
        );
        Assert::assertArrayHasKey(
            'variants',
            $products[0],
            'has_subscription reads through variants, so it must still be loaded'
        );
    }

    /**
     * Product -> OrderItem -> Order -> Customer, so every hostile chain above has
     * real rows to resolve to. A test that cannot leak proves nothing.
     */
    private function seedPurchasedProduct(string $email): void
    {
        $product = (new ProductFactory())->create();
        $customer = (new CustomerFactory())->create(['email' => $email]);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);

        // Product::orderItems() keys on post_id, not object_id — seeding the wrong
        // column leaves the chain empty and the leak assertions unfireable.
        (new OrderItemFactory())->create((int) $order->id, [
            'post_id'   => $product->ID,
            'object_id' => $product->ID,
        ]);
    }
}
