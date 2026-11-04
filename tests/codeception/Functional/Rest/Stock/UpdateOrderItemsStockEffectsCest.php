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
 * Spec: Cases/Functional/stock.md → "Admin updating an order's items" section,
 * items 19-25.
 *
 * OrderResource::update dispatches OrderUpdated (api/Resource/OrderResource.php:784)
 * which fires the fluent_cart/order_updated hook. StockManagement's
 * manageStockOnOrderUpdated handler reads the diff between old and new
 * order_items.quantity and either grows on_hold (pending) / committed
 * (delivered physical OR paid digital), or shrinks them on a negative diff.
 *
 * armStockListeners() in _before re-binds the handler because the module's
 * boot-time gate is locked at first read. PlacesStockedOrders trait seeds the
 * post-create state directly so each method can focus on the UPDATE behavior.
 */
class UpdateOrderItemsStockEffectsCest
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
     * Spec: Cases/Functional/stock.md [19]
     *   "Admin increasing the quantity of an item on an existing pending order reduces the available stock by the added units and grows the reserved stock against the order."
     */
    public function increasingQuantityOnPendingOrderReducesAvailableAndGrowsOnHold(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-update-pending-up@example.test']);
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
            2,
            Helper::toCent(50)
        );

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . $orderId, [
            'customer_id' => (int) $customer->id,
            'order_items' => [
                [
                    'id'         => $orderItemId,
                    'post_id'    => (int) $product->ID,
                    'object_id'  => (int) $variant->id,
                    'quantity'   => 5,
                    'unit_price' => Helper::toCent(50),
                    'subtotal'   => Helper::toCent(250),
                    'line_total' => Helper::toCent(250),
                ],
            ],
        ]);

        Assert::assertSame(200, $status, 'updating qty on a pending order must succeed. Body: ' . wp_json_encode($body));

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            5,
            (int) $fresh->available,
            'available must drop from 8 to 5 — the 3 added units must be deducted from available (8 - (5 - 2) = 5)'
        );
        Assert::assertSame(
            5,
            (int) $fresh->on_hold,
            'on_hold must grow from 2 to 5 — the 3 added units must enlarge the reservation against this pending order'
        );
        Assert::assertSame(
            0,
            (int) $fresh->committed,
            'committed must stay at 0 — pending order must not commit anything'
        );

        $movement = OrderMeta::query()
            ->where('order_id', $orderId)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');
        Assert::assertIsArray($movement, 'stock_movement meta must persist as an array keyed by order_item id');
        Assert::assertArrayHasKey($orderItemId, $movement, 'stock_movement must still key the entry by the order_item id after update');
        Assert::assertSame(
            5,
            (int) ($movement[$orderItemId]['on_hold'] ?? -1),
            'stock_movement.on_hold against this order must mirror the new ordered quantity (5) so a later rollback can restore the right amount'
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [20]
     *   "Admin decreasing the quantity of an item on an existing pending order returns the removed units to the available stock and shrinks the reserved stock against the order."
     */
    public function decreasingQuantityOnPendingOrderRestoresAvailableAndShrinksOnHold(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-update-pending-down@example.test']);
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
            5,
            Helper::toCent(50)
        );

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . $orderId, [
            'customer_id' => (int) $customer->id,
            'order_items' => [
                [
                    'id'         => $orderItemId,
                    'post_id'    => (int) $product->ID,
                    'object_id'  => (int) $variant->id,
                    'quantity'   => 2,
                    'unit_price' => Helper::toCent(50),
                    'subtotal'   => Helper::toCent(100),
                    'line_total' => Helper::toCent(100),
                ],
            ],
        ]);

        Assert::assertSame(200, $status, 'updating qty on a pending order must succeed. Body: ' . wp_json_encode($body));

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            8,
            (int) $fresh->available,
            'available must grow from 5 to 8 — the 3 removed units must be returned (5 + (5 - 2) = 8)'
        );
        Assert::assertSame(
            2,
            (int) $fresh->on_hold,
            'on_hold must shrink from 5 to 2 — the reservation must follow the new ordered quantity'
        );
        Assert::assertSame(
            0,
            (int) $fresh->committed,
            'committed must stay at 0 — pending order must not commit anything'
        );

        $movement = OrderMeta::query()
            ->where('order_id', $orderId)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');
        Assert::assertIsArray($movement, 'stock_movement meta must persist as an array keyed by order_item id');
        Assert::assertArrayHasKey($orderItemId, $movement, 'stock_movement must still key the entry by the order_item id after update');
        Assert::assertSame(
            2,
            (int) ($movement[$orderItemId]['on_hold'] ?? -1),
            'stock_movement.on_hold against this order must shrink to the new ordered quantity (2) so a later rollback restores the right amount'
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [21]
     *   "Admin increasing the quantity of an item on a delivered physical order reduces the available stock by the added units and grows the committed stock against the order."
     */
    public function increasingQuantityOnDeliveredPhysicalOrderReducesAvailableAndGrowsCommitted(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-update-delivered-up@example.test']);
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
            3,
            Helper::toCent(50)
        );

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . $orderId, [
            'customer_id' => (int) $customer->id,
            'order_items' => [
                [
                    'id'         => $orderItemId,
                    'post_id'    => (int) $product->ID,
                    'object_id'  => (int) $variant->id,
                    'quantity'   => 5,
                    'unit_price' => Helper::toCent(50),
                    'subtotal'   => Helper::toCent(250),
                    'line_total' => Helper::toCent(250),
                ],
            ],
        ]);

        Assert::assertSame(200, $status, 'updating qty on a delivered physical order must succeed. Body: ' . wp_json_encode($body));

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            5,
            (int) $fresh->available,
            'available must drop from 7 to 5 — the 2 added units must come out of available (7 - (5 - 3) = 5)'
        );
        Assert::assertSame(
            5,
            (int) $fresh->committed,
            'committed must grow from 3 to 5 — the 2 added units on a delivered physical order route to committed, not on_hold'
        );
        Assert::assertSame(
            0,
            (int) $fresh->on_hold,
            'on_hold must stay at 0 — delivered orders no longer hold anything in reserve'
        );

        $movement = OrderMeta::query()
            ->where('order_id', $orderId)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');
        Assert::assertIsArray($movement, 'stock_movement meta must persist as an array keyed by order_item id');
        Assert::assertArrayHasKey($orderItemId, $movement, 'stock_movement must still key the entry by the order_item id after update');
        Assert::assertSame(
            5,
            (int) ($movement[$orderItemId]['committed'] ?? -1),
            'stock_movement.committed against this delivered physical order must grow to the new ordered quantity (5) so a later refund can roll back the right amount'
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [22]
     *   "Admin decreasing the quantity of an item on a delivered physical order returns the removed units to the available stock and shrinks the committed stock against the order."
     */
    public function decreasingQuantityOnDeliveredPhysicalOrderRestoresAvailableAndShrinksCommitted(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-update-delivered-down@example.test']);
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
            5,
            Helper::toCent(50)
        );

        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . $orderId, [
            'customer_id' => (int) $customer->id,
            'order_items' => [
                [
                    'id'         => $orderItemId,
                    'post_id'    => (int) $product->ID,
                    'object_id'  => (int) $variant->id,
                    'quantity'   => 2,
                    'unit_price' => Helper::toCent(50),
                    'subtotal'   => Helper::toCent(100),
                    'line_total' => Helper::toCent(100),
                ],
            ],
        ]);

        Assert::assertSame(200, $status, 'updating qty on a delivered physical order must succeed. Body: ' . wp_json_encode($body));

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            8,
            (int) $fresh->available,
            'available must grow from 5 to 8 — the 3 removed units return to available (5 + (5 - 2) = 8)'
        );
        Assert::assertSame(
            2,
            (int) $fresh->committed,
            'committed must shrink from 5 to 2 — committed must mirror the new ordered quantity on a delivered physical order'
        );
        Assert::assertSame(
            0,
            (int) $fresh->on_hold,
            'on_hold must stay at 0 — delivered orders never re-enter the on_hold pool on a quantity decrease'
        );

        $movement = OrderMeta::query()
            ->where('order_id', $orderId)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');
        Assert::assertIsArray($movement, 'stock_movement meta must persist as an array keyed by order_item id');
        Assert::assertArrayHasKey($orderItemId, $movement, 'stock_movement must still key the entry by the order_item id after update');
        Assert::assertSame(
            2,
            (int) ($movement[$orderItemId]['committed'] ?? -1),
            'stock_movement.committed against this delivered physical order must shrink to the new ordered quantity (2) so a later refund only releases the remaining commit'
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [23]
     *   "Admin increasing the quantity of an item on a paid digital order reduces the available stock by the added units and grows the committed stock against the order."
     */
    public function increasingQuantityOnPaidDigitalOrderReducesAvailableAndGrowsCommitted(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-update-paid-digital-up@example.test']);
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
            2,
            Helper::toCent(50)
        );

        // The admin update endpoint silently wipes any rules-listed field
        // (`payment_status` is in OrderRequest::rules) that the payload omits.
        // Real admin clients echo back the full order state, so the test does
        // the same — otherwise payment_status drops to '' and the
        // paid-digital → committed branch in
        // app/Modules/StockManagement/StockManagement.php:973-988 cannot fire.
        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . $orderId, [
            'customer_id'    => (int) $customer->id,
            'payment_status' => 'paid',
            'order_items'    => [
                [
                    'id'         => $orderItemId,
                    'post_id'    => (int) $product->ID,
                    'object_id'  => (int) $variant->id,
                    'quantity'   => 4,
                    'unit_price' => Helper::toCent(50),
                    'subtotal'   => Helper::toCent(200),
                    'line_total' => Helper::toCent(200),
                ],
            ],
        ]);

        Assert::assertSame(200, $status, 'updating qty on a paid digital order must succeed. Body: ' . wp_json_encode($body));

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            6,
            (int) $fresh->available,
            'available must drop from 8 to 6 — the 2 added units come out of available (8 - (4 - 2) = 6)'
        );
        Assert::assertSame(
            4,
            (int) $fresh->committed,
            'committed must grow from 2 to 4 — added units on a paid digital order route to committed, not on_hold'
        );
        Assert::assertSame(
            0,
            (int) $fresh->on_hold,
            'on_hold must stay at 0 — paid digital orders do not return units to the on_hold pool'
        );

        $movement = OrderMeta::query()
            ->where('order_id', $orderId)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');
        Assert::assertIsArray($movement, 'stock_movement meta must persist as an array keyed by order_item id');
        Assert::assertArrayHasKey($orderItemId, $movement, 'stock_movement must still key the entry by the order_item id after update');
        Assert::assertSame(
            4,
            (int) ($movement[$orderItemId]['committed'] ?? -1),
            'stock_movement.committed against this paid digital order must grow to the new ordered quantity (4) so a later refund can roll back the right amount'
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [24]
     *   "Admin decreasing the quantity of an item on a paid digital order returns the removed units to the available stock and shrinks the committed stock against the order."
     */
    public function decreasingQuantityOnPaidDigitalOrderRestoresAvailableAndShrinksCommitted(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-update-paid-digital-down@example.test']);
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

        // Echo payment_status back so the admin update endpoint does not silently
        // wipe it (see comment in [23] sibling test for the wiping mechanism).
        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . $orderId, [
            'customer_id'    => (int) $customer->id,
            'payment_status' => 'paid',
            'order_items'    => [
                [
                    'id'         => $orderItemId,
                    'post_id'    => (int) $product->ID,
                    'object_id'  => (int) $variant->id,
                    'quantity'   => 1,
                    'unit_price' => Helper::toCent(50),
                    'subtotal'   => Helper::toCent(50),
                    'line_total' => Helper::toCent(50),
                ],
            ],
        ]);

        Assert::assertSame(200, $status, 'updating qty on a paid digital order must succeed. Body: ' . wp_json_encode($body));

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            9,
            (int) $fresh->available,
            'available must grow from 6 to 9 — the 3 removed units return to available (6 + (4 - 1) = 9)'
        );
        Assert::assertSame(
            1,
            (int) $fresh->committed,
            'committed must shrink from 4 to 1 — committed must mirror the new ordered quantity on a paid digital order'
        );
        Assert::assertSame(
            0,
            (int) $fresh->on_hold,
            'on_hold must stay at 0 — paid digital orders never re-enter the on_hold pool'
        );

        $movement = OrderMeta::query()
            ->where('order_id', $orderId)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');
        Assert::assertIsArray($movement, 'stock_movement meta must persist as an array keyed by order_item id');
        Assert::assertArrayHasKey($orderItemId, $movement, 'stock_movement must still key the entry by the order_item id after update');
        Assert::assertSame(
            1,
            (int) ($movement[$orderItemId]['committed'] ?? -1),
            'stock_movement.committed against this paid digital order must shrink to the new ordered quantity (1) so a later refund only releases the remaining commit'
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [25]
     *   "Admin updating an order without changing any quantity leaves stock counts unchanged."
     */
    public function updatingOrderWithoutChangingQuantityLeavesStockUnchanged(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-update-no-change@example.test']);
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

        // Same quantity, different note. The OrderUpdated handler must see
        // diff=0 for every variation and no-op.
        [$status, $body] = $I->post('/fluent-cart/v2/orders/' . $orderId, [
            'customer_id' => (int) $customer->id,
            'note'        => 'Customer asked for gift wrapping.',
            'order_items' => [
                [
                    'id'         => $orderItemId,
                    'post_id'    => (int) $product->ID,
                    'object_id'  => (int) $variant->id,
                    'quantity'   => 3,
                    'unit_price' => Helper::toCent(50),
                    'subtotal'   => Helper::toCent(150),
                    'line_total' => Helper::toCent(150),
                ],
            ],
        ]);

        Assert::assertSame(200, $status, 'updating an order without quantity change must succeed. Body: ' . wp_json_encode($body));

        // Confirm the update endpoint actually persisted SOMETHING on the order
        // (the note) — without this, an endpoint that silently no-ops the whole
        // request would still appear to leave stock unchanged.
        global $wpdb;
        $persistedNote = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT note FROM {$wpdb->prefix}fct_orders WHERE id = %d",
            $orderId
        ));
        Assert::assertSame(
            'Customer asked for gift wrapping.',
            $persistedNote,
            'precondition: the non-quantity field (note) must persist so we know the update endpoint actually ran the path under test'
        );

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            7,
            (int) $fresh->available,
            'available must stay at 7 — diff=0 means no stock motion'
        );
        Assert::assertSame(
            3,
            (int) $fresh->on_hold,
            'on_hold must stay at 3 — diff=0 means the reservation count cannot drift'
        );
        Assert::assertSame(
            0,
            (int) $fresh->committed,
            'committed must stay at 0 — no payment or shipping transition occurred'
        );

        $movement = OrderMeta::query()
            ->where('order_id', $orderId)
            ->where('meta_key', 'stock_movement')
            ->value('meta_value');
        Assert::assertIsArray($movement, 'stock_movement meta must still persist as an array');
        Assert::assertArrayHasKey($orderItemId, $movement, 'stock_movement entry for the order_item must still be keyed by the original id');
        Assert::assertSame(
            3,
            (int) ($movement[$orderItemId]['on_hold'] ?? -1),
            'stock_movement.on_hold must stay at 3 — diff=0 means the reservation record cannot drift'
        );
    }
}
