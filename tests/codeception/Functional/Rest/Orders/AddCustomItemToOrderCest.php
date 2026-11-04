<?php

namespace Tests\Functional\Rest\Orders;

use FluentCart\App\Helpers\Helper;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderAddressFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\TaxRateFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsTaxFixtures;

/**
 * Spec: Cases/Functional/orders.md → "Adding a custom item to an order" section, item 32.
 */
class AddCustomItemToOrderCest
{
    use BuildsTaxFixtures;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/orders.md [32]
     *   "Admin can add a custom line item to an existing order, and the order reflects the new item."
     */
    public function adminCanAddCustomLineItemToExistingOrderAndOrderReflectsNewItem(FunctionalTester $I): void
    {

        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'add-custom-item@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'subtotal'    => Helper::toCent(50),
            'total_amount' => Helper::toCent(50),
            'invoice_no'  => 'INV-ADD-CUSTOM',
        ]);

        global $wpdb;
        $itemsBefore = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d", (int) $order->id)
        );
        Assert::assertSame(0, $itemsBefore, 'precondition: target order must start with no order_items so the new line is unambiguously the freshly added one');

        [, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/create-custom', [
            'product' => [
                'item_name'        => 'Custom embroidery service',
                'item_price'       => Helper::toCent(15.50),
                'quantity'         => 2,
                'fulfillment_type' => 'physical',
            ],
        ]);

        $I->seeResponseCodeIs(200);

        $persistedItem = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, title, quantity, unit_price, line_total, fulfillment_type FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d ORDER BY id DESC LIMIT 1",
                (int) $order->id
            ),
            ARRAY_A
        );
        Assert::assertNotEmpty(
            $persistedItem,
            'order must reflect the new custom item — a row must exist in fct_order_items for the target order after the create-custom request. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            'Custom embroidery service',
            (string) $persistedItem['title'],
            'persisted custom title must match the submitted item_name'
        );
        Assert::assertSame(
            2,
            (int) $persistedItem['quantity'],
            'persisted custom quantity must match the submitted quantity'
        );
        Assert::assertSame(
            Helper::toCent(15.50),
            (int) $persistedItem['unit_price'],
            'persisted custom unit_price must equal the submitted item_price in cents (1550)'
        );
        Assert::assertSame(
            Helper::toCent(31.00),
            (int) $persistedItem['line_total'],
            'persisted custom line_total must equal item_price * quantity in cents (3100 = 1550 * 2)'
        );
        Assert::assertSame(
            'physical',
            (string) $persistedItem['fulfillment_type'],
            'persisted custom fulfillment_type must reflect the submitted fulfillment_type'
        );

        $orderTotals = $wpdb->get_row(
            $wpdb->prepare("SELECT subtotal, total_amount FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $order->id),
            ARRAY_A
        );
        Assert::assertSame(
            Helper::toCent(31.00),
            (int) $orderTotals['subtotal'],
            'order subtotal must equal the sum of its persisted line items (3100) — a line saved without refreshing the aggregate leaves receipts, invoices and reports disagreeing with the items'
        );
        Assert::assertSame(
            Helper::toCent(31.00),
            (int) $orderTotals['total_amount'],
            'order total_amount must be derived from the refreshed subtotal (3100 — this order has no shipping, fees, discounts or tax)'
        );
    }

    /**
     * The published spec (openapi/orders/create-custom-order-item.json) documents
     * the payload as {title, price, quantity}, but the implementation has only
     * ever read item_name/item_price — an integrator following the docs got
     * "Item must have a name!". Both spellings must work.
     */
    public function createCustomAcceptsTheParameterNamesTheApiDocsPublish(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'custom-item-contract@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id'  => $customer->id,
            'subtotal'     => Helper::toCent(50),
            'total_amount' => Helper::toCent(50),
            'invoice_no'   => 'INV-CUSTOM-CONTRACT',
        ]);

        [, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/create-custom', [
            'product' => [
                'title'    => 'Custom Service',
                'price'    => Helper::toCent(15.00),
                'quantity' => 1,
            ],
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $persistedItem = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT title, quantity, unit_price FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d ORDER BY id DESC LIMIT 1",
                (int) $order->id
            ),
            ARRAY_A
        );

        Assert::assertNotEmpty(
            $persistedItem,
            'the documented {title, price, quantity} payload must persist a line item, not be rejected. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame('Custom Service', (string) $persistedItem['title'], 'documented `title` must map to the stored title');
        Assert::assertSame(Helper::toCent(15.00), (int) $persistedItem['unit_price'], 'documented `price` must map to the stored unit_price in cents');
        Assert::assertSame(1, (int) $persistedItem['quantity'], 'documented `quantity` must map to the stored quantity');

        Assert::assertArrayHasKey(
            'message',
            $body,
            'the spec documents a {message} success body. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            Helper::toCent(15.00),
            (int) $body['order_item']['unit_price'],
            'order_item must expose the persisted row using real fct_order_items columns'
        );
    }

    /**
     * Same rule as the whole-order edit (OrderController::updateOrder): a
     * subscription order's total is bound to its pending charge, which nothing
     * on the create-custom path resynchronizes — so the line must be refused,
     * not silently added with a stale charge amount.
     */
    public function createCustomRefusesSubscriptionOrdersLikeTheOrderEditPathDoes(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'custom-item-subscription@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id'  => $customer->id,
            'type'         => 'subscription',
            'subtotal'     => Helper::toCent(50),
            'total_amount' => Helper::toCent(50),
            'invoice_no'   => 'INV-CUSTOM-SUB',
        ]);

        global $wpdb;
        // isSubscription() resolves from line payment_type, not order.type.
        $wpdb->insert($wpdb->prefix . 'fct_order_items', [
            'order_id'         => (int) $order->id,
            'payment_type'     => 'subscription',
            'fulfillment_type' => 'digital',
            'title'            => 'Monthly plan',
            'post_title'       => 'Monthly plan',
            'quantity'         => 1,
            'unit_price'       => Helper::toCent(50),
            'subtotal'         => Helper::toCent(50),
            'line_total'       => Helper::toCent(50),
        ]);

        [$code, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/create-custom', [
            'product' => [
                'title'    => 'Custom Service',
                'price'    => Helper::toCent(15.00),
                'quantity' => 1,
            ],
        ]);

        Assert::assertSame(423, (int) $code, 'a subscription order must refuse custom lines, mirroring updateOrder. Body: ' . wp_json_encode($body));

        $itemCount = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d",
                (int) $order->id
            )
        );
        Assert::assertSame(1, $itemCount, 'no custom line may be persisted for a refused subscription order');

        $orderTotals = $wpdb->get_row(
            $wpdb->prepare("SELECT subtotal, total_amount FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $order->id),
            ARRAY_A
        );
        Assert::assertSame(Helper::toCent(50), (int) $orderTotals['subtotal'], 'a refused request must leave the subscription order subtotal untouched');
        Assert::assertSame(Helper::toCent(50), (int) $orderTotals['total_amount'], 'a refused request must leave the subscription order total_amount untouched');
    }

    /**
     * The tax pass early-returns for refund-type orders before syncing the
     * pending charge transaction, so a custom line would leave the totals
     * desynchronized from the recorded charge — refuse, and roll the line back.
     */
    public function createCustomRefusesRefundOrdersAndRollsTheLineBack(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'custom-item-refund@example.test']);

        $order = (new OrderFactory())->create([
            'customer_id'  => $customer->id,
            'type'         => 'refund',
            'subtotal'     => Helper::toCent(50),
            'total_amount' => Helper::toCent(50),
            'invoice_no'   => 'INV-CUSTOM-REFUND',
        ]);

        [$code, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/create-custom', [
            'product' => [
                'title'    => 'Custom Service',
                'price'    => Helper::toCent(15.00),
                'quantity' => 1,
            ],
        ]);

        Assert::assertSame(423, (int) $code, 'a refund order must refuse custom lines. Body: ' . wp_json_encode($body));

        global $wpdb;
        $itemCount = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d",
                (int) $order->id
            )
        );
        Assert::assertSame(0, $itemCount, 'the refused line must be rolled back out of fct_order_items, not left behind by the failed totals pass');

        $orderTotals = $wpdb->get_row(
            $wpdb->prepare("SELECT subtotal, total_amount FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $order->id),
            ARRAY_A
        );
        Assert::assertSame(Helper::toCent(50), (int) $orderTotals['subtotal'], 'a refused request must leave the refund order subtotal untouched');
        Assert::assertSame(Helper::toCent(50), (int) $orderTotals['total_amount'], 'a refused request must leave the refund order total_amount untouched');
    }

    /**
     * The parent's fulfillment fields are item-derived (AdminOrderProcessor sets
     * fulfillment_type/shipping_status from whether any line is physical), so
     * adding a physical custom line to a digital order must pull the order into
     * the shipping workflow — and a digital line must not, and is born fulfilled.
     */
    public function addingPhysicalLineFlipsDigitalOrderIntoShippingWorkflow(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'custom-item-fulfillment@example.test']);

        $physicalTarget = (new OrderFactory())->create([
            'customer_id'      => $customer->id,
            'fulfillment_type' => 'digital',
            'shipping_status'  => '',
            'subtotal'         => Helper::toCent(50),
            'total_amount'     => Helper::toCent(50),
            'invoice_no'       => 'INV-CUSTOM-FULFIL-P',
        ]);

        [, $body] = $I->post('/fluent-cart/v2/orders/' . (int) $physicalTarget->id . '/create-custom', [
            'product' => [
                'title'            => 'Engraved plaque',
                'price'            => Helper::toCent(20.00),
                'quantity'         => 1,
                'fulfillment_type' => 'physical',
            ],
        ]);

        $I->seeResponseCodeIs(200);

        global $wpdb;
        $parent = $wpdb->get_row(
            $wpdb->prepare("SELECT fulfillment_type, shipping_status FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $physicalTarget->id),
            ARRAY_A
        );
        Assert::assertSame('physical', (string) $parent['fulfillment_type'], 'a physical line must flip the parent order to physical. Body: ' . wp_json_encode($body));
        Assert::assertSame('unshipped', (string) $parent['shipping_status'], 'a physical line on a previously digital order must mark it unshipped so it enters the shipping workflow');

        $physicalLine = $wpdb->get_row(
            $wpdb->prepare("SELECT fulfilled_quantity FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d ORDER BY id DESC LIMIT 1", (int) $physicalTarget->id),
            ARRAY_A
        );
        Assert::assertSame(0, (int) $physicalLine['fulfilled_quantity'], 'a physical line starts unfulfilled');

        $digitalTarget = (new OrderFactory())->create([
            'customer_id'      => $customer->id,
            'fulfillment_type' => 'digital',
            'shipping_status'  => '',
            'subtotal'         => Helper::toCent(50),
            'total_amount'     => Helper::toCent(50),
            'invoice_no'       => 'INV-CUSTOM-FULFIL-D',
        ]);

        $I->post('/fluent-cart/v2/orders/' . (int) $digitalTarget->id . '/create-custom', [
            'product' => [
                'title'            => 'License add-on',
                'price'            => Helper::toCent(10.00),
                'quantity'         => 3,
                'fulfillment_type' => 'digital',
            ],
        ]);

        $I->seeResponseCodeIs(200);

        $parent = $wpdb->get_row(
            $wpdb->prepare("SELECT fulfillment_type, shipping_status FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $digitalTarget->id),
            ARRAY_A
        );
        Assert::assertSame('digital', (string) $parent['fulfillment_type'], 'a digital line must leave a digital order digital');
        Assert::assertSame('', (string) $parent['shipping_status'], 'a digital line must not push the order into the shipping workflow');

        $digitalLine = $wpdb->get_row(
            $wpdb->prepare("SELECT fulfilled_quantity FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d ORDER BY id DESC LIMIT 1", (int) $digitalTarget->id),
            ARRAY_A
        );
        Assert::assertSame(3, (int) $digitalLine['fulfilled_quantity'], 'a digital line needs no shipment, so it is born fulfilled (fulfilled_quantity = quantity) — same rule as the order-edit insert path');
    }

    /**
     * With tax enabled, the recalculation after adding a custom line must keep
     * each row's own tax: the product line keeps the tax computed for it, and
     * the custom line stays untaxed (post_id 0 resolves no tax class, so the
     * calculator assigns it no rates). Also pins the per-line identity in the
     * patch step — saved rows are matched by order-item id, not by the
     * post_id:object_id composite that every custom line shares as 0:0.
     */
    public function taxRecalculationKeepsPerLineTaxWhenCustomLineIsAdded(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'custom-item-tax-identity@example.test']);

        $standardClass = $this->ensureStandardTaxClass();
        (new TaxRateFactory())->create(['class_id' => $standardClass->id, 'country' => 'US', 'rate' => '10']);
        $this->enableTax('excluded', 'billing');
        $this->resetTaxRuntimeState();

        $product   = $this->makeProductWithClass((int) $standardClass->id);
        $variantId = (int) \FluentCart\App\Models\ProductVariation::query()->where('post_id', $product->ID)->value('id');

        $order = (new OrderFactory())->create([
            'customer_id'  => $customer->id,
            'subtotal'     => 1000,
            'total_amount' => 1100,
            'invoice_no'   => 'INV-CUSTOM-TAX-ID',
        ]);
        (new OrderAddressFactory())->create((int) $order->id, ['type' => 'billing', 'country' => 'US']);

        global $wpdb;
        $wpdb->insert("{$wpdb->prefix}fct_order_items", [
            'order_id'   => (int) $order->id,
            'post_id'    => (int) $product->ID,
            'object_id'  => $variantId,
            'title'      => 'Taxable product',
            'post_title' => 'Taxable product',
            'quantity'   => 1,
            'unit_price' => 1000,
            'subtotal'   => 1000,
            'line_total' => 1000,
            'tax_amount' => 100,
        ]);

        $I->post('/fluent-cart/v2/orders/' . (int) $order->id . '/create-custom', [
            'product' => ['title' => 'Custom line', 'price' => 2000, 'quantity' => 1],
        ]);
        $I->seeResponseCodeIs(200);

        $lines = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT title, tax_amount FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d ORDER BY id ASC",
                (int) $order->id
            ),
            ARRAY_A
        );

        Assert::assertCount(2, $lines, 'both lines must exist');
        Assert::assertSame(100, (int) $lines[0]['tax_amount'], 'the product line must keep ITS OWN recalculated tax (10% of 1000) after the custom line is added');
        Assert::assertSame(0, (int) $lines[1]['tax_amount'], 'the custom line resolves no tax class (post_id 0), so it must stay untaxed');

        $orderRow = $wpdb->get_row(
            $wpdb->prepare("SELECT subtotal, total_amount FROM {$wpdb->prefix}fct_orders WHERE id = %d", (int) $order->id),
            ARRAY_A
        );
        Assert::assertSame(3000, (int) $orderRow['subtotal'], 'subtotal must be rebuilt from both lines');
        Assert::assertSame(3100, (int) $orderRow['total_amount'], 'order total must be subtotal 3000 + 100 tax, matching the sum of the per-line tax amounts');
    }
}
