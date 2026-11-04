<?php

namespace Tests\Functional\Rest\Stock;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesStockedOrders;

/**
 * Spec: Cases/Functional/stock.md → "Admin deleting an order" section,
 * items 38-40.
 *
 * DELETE /fluent-cart/v2/orders/{order_id} → OrderController::deleteOrder →
 * OrderResource::delete dispatches OrderDeleting → its built-in
 * Listeners\Order\OrderDeleting handler restores stock when both:
 *   (a) ModuleSettings::isActive('stock_management') is true (armed via
 *       armStockListeners), and
 *   (b) the fluent_cart/order/should_restore_stock_on_delete filter returns
 *       true. Production default is `$event->isTestMode === true`, derived
 *       from `$order->mode === Status::ORDER_MODE_TEST` in
 *       api/Resource/OrderResource.php:883.
 *
 * Items [38]/[39] therefore use test-mode orders so the listener's default
 * gate evaluates true. Item [40] uses a live-mode order — the same default
 * gate evaluates false, modelling the "site has turned off the restore
 * behaviour" spec wording.
 */
class DeleteOrderStockEffectsCest
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
     * Spec: Cases/Functional/stock.md [38]
     *   "Deleting an order whose stock is still reserved returns the reserved quantities to the available stock."
     */
    public function deletingOrderWithReservedStockReturnsReservedQuantitiesToAvailable(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-delete-reserved@example.test']);
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

        [$orderId] = $this->buildPendingOrderWithReservedStock(
            (int) $customer->id,
            (int) $product->ID,
            (int) $variant->id,
            3,
            Helper::toCent(50),
            'digital',
            Status::ORDER_MODE_TEST
        );

        [$status, $body] = $I->delete('/fluent-cart/v2/orders/' . $orderId);

        Assert::assertSame(200, $status, 'deleting a test-mode order must succeed. Body: ' . wp_json_encode($body));

        global $wpdb;
        $orderRow = $wpdb->get_row(
            $wpdb->prepare("SELECT id FROM {$wpdb->prefix}fct_orders WHERE id = %d", $orderId),
            ARRAY_A
        );
        Assert::assertNull($orderRow, 'fct_orders row must be gone after a successful delete');

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            10,
            (int) $fresh->available,
            'available must grow from 7 back to 10 — the 3 reserved units must return to available on delete of a test-mode order'
        );
        Assert::assertSame(
            0,
            (int) $fresh->on_hold,
            'on_hold must drop from 3 to 0 — the reservation against the deleted order must clear'
        );
        Assert::assertSame(
            0,
            (int) $fresh->committed,
            'committed must stay at 0 — pending order held nothing committed'
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [39]
     *   "Deleting an order whose stock has already been committed returns the committed quantities to the available stock."
     */
    public function deletingOrderWithCommittedStockReturnsCommittedQuantitiesToAvailable(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-delete-committed@example.test']);
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

        [$orderId] = $this->buildDeliveredPhysicalOrderWithCommittedStock(
            (int) $customer->id,
            (int) $product->ID,
            (int) $variant->id,
            4,
            Helper::toCent(50),
            Status::ORDER_MODE_TEST
        );

        [$status, $body] = $I->delete('/fluent-cart/v2/orders/' . $orderId);

        Assert::assertSame(200, $status, 'deleting a test-mode delivered order must succeed. Body: ' . wp_json_encode($body));

        global $wpdb;
        $orderRow = $wpdb->get_row(
            $wpdb->prepare("SELECT id FROM {$wpdb->prefix}fct_orders WHERE id = %d", $orderId),
            ARRAY_A
        );
        Assert::assertNull($orderRow, 'fct_orders row must be gone after a successful delete');

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            10,
            (int) $fresh->available,
            'available must grow from 6 back to 10 — the 4 committed units must return to available on delete of a test-mode delivered order'
        );
        Assert::assertSame(
            0,
            (int) $fresh->committed,
            'committed must drop from 4 to 0 — the commitment against the deleted order must clear'
        );
        Assert::assertSame(
            0,
            (int) $fresh->on_hold,
            'on_hold must stay at 0 — delivered orders held nothing in on_hold; nothing should leak there'
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [40]
     *   "When a site has turned off the restore-stock-on-delete behaviour, deleting an order leaves stock counts unchanged."
     */
    public function withRestoreStockOnDeleteOffDeletingOrderLeavesStockUnchanged(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'stock-delete-no-restore@example.test']);
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

        // Live-mode order — the production default for
        // fluent_cart/order/should_restore_stock_on_delete is
        // `$event->isTestMode === true`, so a live order means the filter
        // returns false and the OrderDeleting listener early-returns. This
        // models the "restore-stock-on-delete behaviour turned off" spec
        // wording without monkey-patching the filter.
        [$orderId] = $this->buildPendingOrderWithReservedStock(
            (int) $customer->id,
            (int) $product->ID,
            (int) $variant->id,
            3,
            Helper::toCent(50),
            'digital',
            Status::ORDER_MODE_LIVE
        );

        [$status, $body] = $I->delete('/fluent-cart/v2/orders/' . $orderId);

        Assert::assertSame(200, $status, 'deleting a live-mode order must succeed. Body: ' . wp_json_encode($body));

        global $wpdb;
        $orderRow = $wpdb->get_row(
            $wpdb->prepare("SELECT id FROM {$wpdb->prefix}fct_orders WHERE id = %d", $orderId),
            ARRAY_A
        );
        Assert::assertNull($orderRow, 'fct_orders row must be gone after a successful delete');

        $fresh = ProductVariation::query()->find($variant->id);
        Assert::assertSame(
            7,
            (int) $fresh->available,
            'available must stay at 7 — restore-on-delete is off, so the variant counters must not drift'
        );
        Assert::assertSame(
            3,
            (int) $fresh->on_hold,
            'on_hold must stay at 3 — restore-on-delete is off, so the reservation count must not change'
        );
        Assert::assertSame(
            0,
            (int) $fresh->committed,
            'committed must stay at 0 — no commitment existed; nothing should appear'
        );
    }
}
