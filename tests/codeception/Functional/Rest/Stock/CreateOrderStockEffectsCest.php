<?php

namespace Tests\Functional\Rest\Stock;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/stock.md → "Admin creating an order" section, items 12-18.
 *
 * Stock has no REST endpoints of its own. The decrement/reserve side effects
 * ride on top of OrderController::store via the StockManagement listener bound
 * to fluent_cart/order_created. armStockListeners() in _before re-binds those
 * handlers because the plugin's boot-time gate (ModuleSettings::isActive) is
 * permanently locked to its first-read value within a single PHP process.
 */
class CreateOrderStockEffectsCest
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

    /**
     * Spec: Cases/Functional/stock.md [12]
     *   "Admin creating an order for an in-stock product reduces that product's available stock by the ordered quantity."
     */
    public function adminCreatingOrderForInStockProductReducesAvailableStock(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-create-available@example.test']);
        $product  = (new ProductFactory())
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
        $variant = $product->variants->first();

        [, $body] = $I->createOrder([
            'customer_id' => (int) $customer->id,
            'order_items' => [
                [
                    'post_id'          => (int) $product->ID,
                    'object_id'        => (int) $variant->id,
                    'quantity'         => 3,
                    'unit_price'       => Helper::toCent(50),
                    'fulfillment_type' => 'digital',
                    'payment_type'     => 'onetime',
                    'other_info'       => ['payment_type' => 'onetime'],
                ],
            ],
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('order_id', (array) $body, 'create response must include the new order id');

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertNotNull($fresh, 'variant row must still exist after order create');
        Assert::assertSame(
            7,
            (int) $fresh->available,
            'available stock must decrement from 10 to 7 after a quantity-3 line is purchased (10 - 3 = 7)'
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [13]
     *   "Admin creating an order for an in-stock product holds the ordered quantity as reserved stock against that order."
     */
    public function adminCreatingOrderHoldsOrderedQuantityAsReservedStock(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-create-reserved@example.test']);
        $product  = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1])
            ->withVariantAttributes([
                'item_price'   => Helper::toCent(50),
                'manage_stock' => 1,
                'stock_status' => 'in-stock',
                'available'    => 8,
                'total_stock'  => 8,
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

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            2,
            (int) $fresh->on_hold,
            'ordered quantity must be reserved on the variant on_hold counter (2 units held)'
        );
        Assert::assertSame(
            0,
            (int) $fresh->committed,
            'committed stock must stay at 0 until the order is paid (digital) or delivered (physical)'
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [14]
     *   "Admin creating an order for a stock-managed product whose stock has run out is rejected with a clear out-of-stock message."
     */
    public function creatingOrderForOutOfStockProductIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-create-empty@example.test']);
        $product  = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1, 'stock_availability' => 'out-of-stock'])
            ->withVariantAttributes([
                'item_price'   => Helper::toCent(50),
                'manage_stock' => 1,
                'stock_status' => 'out-of-stock',
                'available'    => 0,
                'total_stock'  => 0,
                'on_hold'      => 0,
                'committed'    => 0,
            ])
            ->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->createOrder([
            'customer_id' => (int) $customer->id,
            'order_items' => [
                [
                    'post_id'          => (int) $product->ID,
                    'object_id'        => (int) $variant->id,
                    'quantity'         => 1,
                    'unit_price'       => Helper::toCent(50),
                    'fulfillment_type' => 'digital',
                    'payment_type'     => 'onetime',
                    'other_info'       => ['payment_type' => 'onetime'],
                ],
            ],
        ]);

        // The admin route answers a stock rejection as a validation error, as checkout does.
        Assert::assertSame(
            422,
            $status,
            'out-of-stock rejection must be a 422 validation error, not a server error. Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            '[' . $product->post_title . ']',
            (string) ($body['message'] ?? ''),
            'rejection message must name the product. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey('message', (array) $body, 'rejection envelope must carry a human-readable message. Body: ' . wp_json_encode($body));
        $message = (string) ($body['message'] ?? '');
        Assert::assertStringContainsString(
            'out of stock',
            strtolower($message),
            'rejection message must phrase the failure as an out-of-stock condition. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $orderCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders");
        Assert::assertSame(0, $orderCount, 'no order row may be created when the variant is out of stock');

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(0, (int) $fresh->available, 'available stock must remain 0 — nothing decremented from a rejected order');
        Assert::assertSame(0, (int) $fresh->on_hold, 'on_hold must remain 0 — nothing reserved from a rejected order');
    }

    /**
     * Spec: Cases/Functional/stock.md [15]
     *   "Admin creating an order whose quantity exceeds the available stock is rejected with a message naming the quantity that is still available."
     */
    public function creatingOrderExceedingStockIsRejectedWithMessageNamingAvailableQuantity(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-create-exceed@example.test']);
        $product  = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1])
            ->withVariantAttributes([
                'item_price'   => Helper::toCent(50),
                'manage_stock' => 1,
                'stock_status' => 'in-stock',
                'available'    => 4,
                'total_stock'  => 4,
                'on_hold'      => 0,
                'committed'    => 0,
            ])
            ->create();
        $variant = $product->variants->first();

        [$status, $body] = $I->createOrder([
            'customer_id' => (int) $customer->id,
            'order_items' => [
                [
                    'post_id'          => (int) $product->ID,
                    'object_id'        => (int) $variant->id,
                    'quantity'         => 10,
                    'unit_price'       => Helper::toCent(50),
                    'fulfillment_type' => 'digital',
                    'payment_type'     => 'onetime',
                    'other_info'       => ['payment_type' => 'onetime'],
                ],
            ],
        ]);

        // The admin route answers a stock rejection as a validation error, as checkout does.
        Assert::assertSame(
            422,
            $status,
            'qty-exceeds-stock rejection must be a 422 validation error, not a server error. Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            '[' . $product->post_title . ']',
            (string) ($body['message'] ?? ''),
            'rejection message must name the product. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey('message', (array) $body, 'rejection envelope must carry a human-readable message. Body: ' . wp_json_encode($body));
        $message = (string) ($body['message'] ?? '');
        Assert::assertStringContainsString(
            '4',
            $message,
            'rejection message must name the still-available quantity (4) so the buyer knows the cap. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        $orderCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders");
        Assert::assertSame(0, $orderCount, 'no order row may be created when qty > available');

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(4, (int) $fresh->available, 'available stock must stay at 4 — rejected qty must not decrement anything');
        Assert::assertSame(0, (int) $fresh->on_hold, 'on_hold must stay at 0 — rejected qty must not reserve anything');
    }

    /**
     * Spec: Cases/Functional/stock.md [16]
     *   "After an order consumes the last of a product's stock, the product is shown as out-of-stock to shoppers."
     */
    public function consumingLastOfStockShowsProductAsOutOfStockToShoppers(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-create-last@example.test']);
        $product  = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1, 'stock_availability' => 'in-stock'])
            ->withVariantAttributes([
                'item_price'   => Helper::toCent(50),
                'manage_stock' => 1,
                'stock_status' => 'in-stock',
                'available'    => 2,
                'total_stock'  => 2,
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
                    'unit_price'       => Helper::toCent(50),
                    'fulfillment_type' => 'digital',
                    'payment_type'     => 'onetime',
                    'other_info'       => ['payment_type' => 'onetime'],
                ],
            ],
        ]);

        $I->seeResponseCodeIs(200);

        $freshVariant = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            'out-of-stock',
            (string) $freshVariant->stock_status,
            'variant stock_status must flip to out-of-stock once available reaches 0'
        );

        $freshDetail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame(
            'out-of-stock',
            (string) $freshDetail->stock_availability,
            'product-level stock_availability must flip to out-of-stock so the shop listing hides the product'
        );

        // Shopper-facing surface: GET /fluent-cart/v2/public/products honors
        // the shop_query filter (filterShopQuery in
        // app/Modules/StockManagement/StockManagement.php:88-114) which hides
        // products with stock_availability != 'in-stock' when stock_management
        // is active and the caller did not opt in to seeing out-of-stock items.
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

        // Recursive walk of the renderer's wrapped response — pulls every
        // ID-like leaf so the assertion does not break if pagination wrapping
        // around `products.data` / `products.products` shifts.
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

        Assert::assertNotContains(
            (int) $product->ID,
            $productIds,
            'consumed product must be hidden from the guest-visible shop listing once its variants are all out of stock. Listing: '
            . wp_json_encode($productIds)
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [17]
     *   "Creating an order that contains a product which is not stock-managed leaves stock counts unchanged."
     */
    public function creatingOrderWithNonStockManagedProductLeavesStockUnchanged(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-create-unmanaged@example.test']);
        $product  = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 0])
            ->withVariantAttributes([
                'item_price'   => Helper::toCent(50),
                'manage_stock' => 0,
                'stock_status' => 'in-stock',
                'available'    => 5,
                'total_stock'  => 5,
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
                    'quantity'         => 3,
                    'unit_price'       => Helper::toCent(50),
                    'fulfillment_type' => 'digital',
                    'payment_type'     => 'onetime',
                    'other_info'       => ['payment_type' => 'onetime'],
                ],
            ],
        ]);

        $I->seeResponseCodeIs(200);

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            5,
            (int) $fresh->available,
            'available stock must stay at 5 — manage_stock=0 means the listener must skip this variant'
        );
        Assert::assertSame(
            0,
            (int) $fresh->on_hold,
            'on_hold must stay at 0 — non-stock-managed variant must not reserve anything'
        );
        Assert::assertSame(
            0,
            (int) $fresh->committed,
            'committed must stay at 0 — non-stock-managed variant must not commit anything'
        );
        Assert::assertSame(
            5,
            (int) $fresh->total_stock,
            'total_stock must stay at 5 — non-stock-managed variant must not lose total inventory'
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [18]
     *   "Creating an order records the resulting stock change on the order so later cancellations, refunds, or deletions can roll it back."
     */
    public function creatingOrderRecordsStockChangeOnOrderForRollback(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-create-meta@example.test']);
        $product  = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1])
            ->withVariantAttributes([
                'item_price'   => Helper::toCent(50),
                'manage_stock' => 1,
                'stock_status' => 'in-stock',
                'available'    => 6,
                'total_stock'  => 6,
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
                    'quantity'         => 4,
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

        $meta = OrderMeta::query()
            ->where('order_id', $orderId)
            ->where('meta_key', 'stock_movement')
            ->first();
        Assert::assertNotNull($meta, 'an order_meta row with meta_key=stock_movement must persist after order create');

        $movement = is_array($meta->meta_value) ? $meta->meta_value : json_decode((string) $meta->meta_value, true);
        Assert::assertIsArray($movement, 'stock_movement meta must decode to an array keyed by order_item id');

        global $wpdb;
        $orderItemId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d AND object_id = %d",
            $orderId,
            (int) $variant->id
        ));
        Assert::assertGreaterThan(0, $orderItemId, 'an fct_order_items row must exist linking the order to the purchased variant');

        Assert::assertArrayHasKey(
            $orderItemId,
            $movement,
            'stock_movement must key the entry by the actual order_item id so a later cancel/refund/delete can find and reverse it. Movement: '
            . wp_json_encode($movement)
        );
        Assert::assertArrayHasKey(
            'on_hold',
            (array) $movement[$orderItemId],
            'stock_movement entry must carry an on_hold key so rollback can restore the reserved amount'
        );
        Assert::assertSame(
            4,
            (int) $movement[$orderItemId]['on_hold'],
            'stock_movement.on_hold must equal the ordered quantity (4) so a later cancel/refund/delete can return exactly that to available'
        );
    }
}
