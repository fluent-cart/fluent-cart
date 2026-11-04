<?php

namespace Tests\Functional\Rest\Stock;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderMeta;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesStockedOrders;

/**
 * Spec: Cases/Functional/stock.md → "Admin cancelling an order" section,
 * items 31-33.
 *
 * PUT /fluent-cart/v2/orders/{order}/statuses with action=change_order_status
 * → fires fluent_cart/order_status_changed →
 * StockManagement::manageStockOnOrderStatusChanged. The listener early-returns
 * unless new_status === 'canceled' (app/Modules/StockManagement/StockManagement.php).
 * On cancel it walks order_items, returns the seeded on_hold + committed
 * amount back to available, and decrements on_hold/committed in priority
 * order (committed first for delivered/digital-paid, on_hold first otherwise).
 */
class CancelOrderStockEffectsCest
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
     * Spec: Cases/Functional/stock.md [31]
     *   "Admin cancelling an order whose stock is still reserved returns the reserved quantities to the available stock."
     */
    public function cancellingOrderWithReservedStockReturnsReservedQuantitiesToAvailable(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-cancel-reserved@example.test']);
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

        [$orderId, $orderItemId] = $this->buildPendingOrderWithReservedStock(
            (int) $customer->id,
            (int) $product->ID,
            (int) $variant->id,
            3,
            Helper::toCent(50)
        );

        [$status, $body] = $I->put('/fluent-cart/v2/orders/' . $orderId . '/statuses', [
            'action'   => 'change_order_status',
            'statuses' => ['order_status' => Status::ORDER_CANCELED],
        ]);

        Assert::assertSame(200, $status, 'cancelling an order must succeed. Body: ' . wp_json_encode($body));

        global $wpdb;
        $afterStatus = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}fct_orders WHERE id = %d",
            $orderId
        ));
        Assert::assertSame('canceled', $afterStatus, 'precondition: order status must persist as canceled so we know the listener fired');

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            10,
            (int) $fresh->available,
            'available must grow from 7 back to 10 — all 3 reserved units must return to available on cancel'
        );
        Assert::assertSame(
            0,
            (int) $fresh->on_hold,
            'on_hold must drop from 3 to 0 — cancellation must clear the reservation'
        );
        Assert::assertSame(
            0,
            (int) $fresh->committed,
            'committed must stay at 0 — pending order had nothing committed'
        );

        $movement = OrderMeta::query()
            ->where('order_id', $orderId)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');
        Assert::assertIsArray($movement, 'stock_movement meta must persist as an array keyed by order_item id');
        Assert::assertArrayHasKey($orderItemId, $movement, 'stock_movement entry for the order_item must still be present after cancel');
        Assert::assertSame(
            0,
            (int) ($movement[$orderItemId]['on_hold'] ?? -1),
            'stock_movement.on_hold must drop to 0 — the reservation has been returned to the available pool'
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [32]
     *   "Admin cancelling a delivered order returns the committed quantities to the available stock."
     */
    public function cancellingDeliveredOrderReturnsCommittedQuantitiesToAvailable(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-cancel-delivered@example.test']);
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
            'action'   => 'change_order_status',
            'statuses' => ['order_status' => Status::ORDER_CANCELED],
        ]);

        Assert::assertSame(200, $status, 'cancelling a delivered order must succeed. Body: ' . wp_json_encode($body));

        global $wpdb;
        $afterStatus = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}fct_orders WHERE id = %d",
            $orderId
        ));
        Assert::assertSame('canceled', $afterStatus, 'precondition: order status must persist as canceled so we know the listener fired');

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            10,
            (int) $fresh->available,
            'available must grow from 6 back to 10 — all 4 committed units must return to available on cancel of a delivered order'
        );
        Assert::assertSame(
            0,
            (int) $fresh->committed,
            'committed must drop from 4 to 0 — cancellation must clear the commitment'
        );
        Assert::assertSame(
            0,
            (int) $fresh->on_hold,
            'on_hold must stay at 0 — delivered orders held nothing in on_hold; nothing should leak back there'
        );

        $movement = OrderMeta::query()
            ->where('order_id', $orderId)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');
        Assert::assertIsArray($movement, 'stock_movement meta must persist as an array keyed by order_item id');
        Assert::assertArrayHasKey($orderItemId, $movement, 'stock_movement entry for the order_item must still be present after cancel');
        Assert::assertSame(
            0,
            (int) ($movement[$orderItemId]['committed'] ?? -1),
            'stock_movement.committed must drop to 0 — the commitment has been returned to the available pool'
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [33]
     *   "A product whose stock returns above zero after a cancellation is shown as in-stock again to shoppers."
     */
    public function productWhoseStockReturnsAboveZeroAfterCancellationIsShownInStockToShoppers(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-cancel-restore@example.test']);
        // Seed a variant where the entire stock is about to be reserved by the
        // order built via the trait — buildPendingOrderWithReservedStock
        // decrements available by quantity and grows on_hold by the same, so
        // starting available=2 quantity=2 lands at available=0/on_hold=2/
        // stock_status=out-of-stock. The detail's stock_availability is set
        // out-of-stock up-front to mirror the "shop hides this product" state
        // that the cancellation under test must reverse.
        $product = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1, 'stock_availability' => 'out-of-stock'])
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

        [$orderId] = $this->buildPendingOrderWithReservedStock(
            (int) $customer->id,
            (int) $product->ID,
            (int) $variant->id,
            2,
            Helper::toCent(50)
        );

        // Precondition check: trait must have driven the variant to fully
        // depleted (available=0) and detail to out-of-stock before cancel.
        $beforeVariant = ProductVariation::query()->find($variant->id);
        Assert::assertSame(0, (int) $beforeVariant->available, 'precondition: variant available must be 0 after the trait seeds the reservation');
        Assert::assertSame('out-of-stock', (string) $beforeVariant->stock_status, 'precondition: variant must be out-of-stock after the trait seeds the reservation');

        [$status, $body] = $I->put('/fluent-cart/v2/orders/' . $orderId . '/statuses', [
            'action'   => 'change_order_status',
            'statuses' => ['order_status' => Status::ORDER_CANCELED],
        ]);

        Assert::assertSame(200, $status, 'cancelling the order that consumed the last stock must succeed. Body: ' . wp_json_encode($body));

        $freshVariant = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            2,
            (int) $freshVariant->available,
            'available must rise from 0 back to 2 — cancellation must return the reserved units to available'
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

        // Recursive walk of the renderer's wrapped response.
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
            'restored product must reappear in the guest-visible shop listing after stock returns above zero. Listing: '
            . wp_json_encode($productIds)
        );
    }
}
