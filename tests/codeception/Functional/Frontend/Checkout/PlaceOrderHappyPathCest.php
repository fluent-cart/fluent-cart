<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use FluentCart\App\Models\OrderTransaction;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesCheckoutOrders;

/**
 * Spec: Cases/checkout-functional.md → "Placing an order — happy path" section, items 1-8.
 *
 * TC-101. Proves that one real `place-order` request writes a correct order:
 * every money assertion reads the raw `fct_*` row and checks an integer cent
 * value against the seeded variation price, never a float or a smoke `!== null`.
 */
class PlaceOrderHappyPathCest
{
    use PlacesCheckoutOrders;

    public function _before(FunctionalTester $I): void
    {
        $I->logout();
        $this->registerStubCheckoutGateway();
    }

    public function _after(FunctionalTester $I): void
    {
        $this->removeStubCheckoutGateway();
    }

    /**
     * Spec: Cases/checkout-functional.md [1]
     *   "A guest who submits a valid cart gets a success response whose redirect identifies
     *    the payment transaction that was recorded for the new order."
     */
    public function guestPlaceOrderResponseIdentifiesTheRecordedTransaction(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1999])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));

        Assert::assertSame(200, $status, 'place-order must answer HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order response must report success');
        Assert::assertNotEmpty($body['redirect_to'] ?? '', 'place-order response must carry the post-payment redirect');

        parse_str((string) parse_url((string) $body['redirect_to'], PHP_URL_QUERY), $query);
        Assert::assertArrayHasKey('trx_hash', $query, 'redirect must carry the transaction hash the receipt page resolves');

        $transaction = OrderTransaction::query()->where('uuid', $query['trx_hash'])->first();
        Assert::assertNotNull($transaction, 'redirect trx_hash must resolve to a stored transaction');
        Assert::assertSame(1, Order::query()->count(), 'a single submission must create exactly one order');
        Assert::assertSame((int) Order::query()->first()->id, (int) $transaction->order_id, 'the redirect transaction must belong to the created order');
    }

    /**
     * Spec: Cases/checkout-functional.md [2]
     *   "The stored order keeps the exact cent totals of the cart, starts on hold with payment
     *    pending, and carries the store currency, order mode, and chosen payment method."
     */
    public function storedOrderKeepsExactCentTotalsAndPendingState(FunctionalTester $I): void
    {
        $storeSettings = get_option('fluent_cart_store_settings', []);
        update_option('fluent_cart_store_settings', array_merge(is_array($storeSettings) ? $storeSettings : [], [
            'currency'   => 'EUR', // not the StoreSettings fallback (USD): a hard-coded default would fail this
            'order_mode' => 'live',
        ]));
        StoreSettings::clearCache();

        $first  = (new ProductFactory())->withVariantAttributes(['item_price' => 1999])->create()->variants->first();
        $second = (new ProductFactory())->withVariantAttributes(['item_price' => 550])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$first, 2], [$second, 1]]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order must succeed before the order row is inspected. Body: ' . wp_json_encode($body));

        global $wpdb;
        $row = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}fct_orders ORDER BY id DESC LIMIT 1", ARRAY_A);
        Assert::assertNotNull($row, 'place-order must persist an fct_orders row');

        foreach (['subtotal', 'total_amount', 'coupon_discount_total', 'manual_discount_total', 'discount_tax', 'shipping_total', 'shipping_tax', 'tax_total', 'fee_total', 'total_paid', 'total_refund'] as $column) {
            Assert::assertMatchesRegularExpression('/^-?\d+$/', (string) $row[$column], "fct_orders.{$column} must be stored as an integer cent value, got: {$row[$column]}");
        }
        Assert::assertSame(4548, (int) $row['subtotal'], 'subtotal must equal 2 × 1999 + 1 × 550 cents');
        Assert::assertSame(4548, (int) $row['total_amount'], 'total must equal the subtotal when nothing is discounted, shipped, or taxed');
        foreach (['coupon_discount_total', 'manual_discount_total', 'discount_tax', 'shipping_total', 'shipping_tax', 'tax_total', 'fee_total', 'total_paid', 'total_refund'] as $column) {
            Assert::assertSame(0, (int) $row[$column], "fct_orders.{$column} must be exactly zero on a fresh, undiscounted, unshipped, untaxed, unpaid order");
        }
        // DECIMAL(24,12) since multi-currency (dev-docs/multi-currency/02-database-schema.md)
        Assert::assertSame('1.000000000000', (string) $row['rate'], 'a single-currency order must be stored at exchange rate 1');
        Assert::assertSame('on-hold', $row['status'], 'a placed order awaiting payment starts on hold');
        Assert::assertSame('pending', $row['payment_status'], 'a placed order awaiting payment starts with payment pending');
        Assert::assertSame('payment', $row['type'], 'a one-time cart produces a payment-type order');
        Assert::assertSame('digital', $row['fulfillment_type'], 'an all-digital cart produces a digital order');
        Assert::assertSame('EUR', $row['currency'], 'the order must carry the configured store currency, not the USD fallback');
        Assert::assertSame('live', $row['mode'], 'the order must carry the store order mode in force at placement');
        Assert::assertSame('wp_browser_stub', $row['payment_method'], 'the order must record the payment method the customer chose');
    }

    /**
     * Spec: Cases/checkout-functional.md [3]
     *   "The stored order receives a short unique order handle automatically and no receipt
     *    or invoice number while payment is still pending."
     */
    public function storedOrderReceivesHandleButNoReceiptWhilePending(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1250])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order must succeed before the order row is inspected. Body: ' . wp_json_encode($body));

        Assert::assertSame(1, Order::query()->count(), 'one submission must leave exactly one order to inspect');
        $order = Order::query()->first();
        Assert::assertMatchesRegularExpression('/^[A-Z0-9]{12}$/', (string) $order->uuid, 'the order handle must be the 12-character uppercase alphanumeric format');
        Assert::assertNull($order->receipt_number, 'a pending order must not be given a receipt number yet');
        Assert::assertSame('', (string) $order->invoice_no, 'a pending order must not be given an invoice number yet');
    }

    /**
     * Spec: Cases/checkout-functional.md [4]
     *   "Every cart line becomes one stored order item with the same product, variation,
     *    quantity, unit price, and line totals in cents."
     */
    public function everyCartLineBecomesOneOrderItemInCents(FunctionalTester $I): void
    {
        $firstProduct  = (new ProductFactory())->withVariantAttributes(['item_price' => 1999])->create();
        $secondProduct = (new ProductFactory())->withVariantAttributes(['item_price' => 550])->create();
        $first  = $firstProduct->variants->first();
        $second = $secondProduct->variants->first();
        $cart = $this->buildInstantCart($I, [[$first, 2], [$second, 3]]);

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order must succeed before items are inspected. Body: ' . wp_json_encode($body));

        Assert::assertSame(1, Order::query()->count(), 'one submission must leave exactly one order to inspect');
        $order = Order::query()->first();
        global $wpdb;
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d ORDER BY object_id ASC",
            $order->id
        ), ARRAY_A);
        Assert::assertCount(2, $items, 'two cart lines must produce exactly two order items');

        $expected = [
            ['post_id' => $firstProduct->ID, 'object_id' => $first->id, 'quantity' => 2, 'unit_price' => 1999, 'subtotal' => 3998, 'line_total' => 3998],
            ['post_id' => $secondProduct->ID, 'object_id' => $second->id, 'quantity' => 3, 'unit_price' => 550, 'subtotal' => 1650, 'line_total' => 1650],
        ];
        usort($expected, function ($a, $b) {
            return $a['object_id'] <=> $b['object_id'];
        });

        foreach ($expected as $index => $line) {
            $item = $items[$index];
            foreach (['unit_price', 'subtotal', 'line_total'] as $moneyColumn) {
                Assert::assertMatchesRegularExpression('/^\d+$/', (string) $item[$moneyColumn], "order item {$moneyColumn} must be stored as integer cents, got: {$item[$moneyColumn]}");
            }
            Assert::assertSame((int) $line['post_id'], (int) $item['post_id'], "item {$index} must reference the ordered product");
            Assert::assertSame((int) $line['object_id'], (int) $item['object_id'], "item {$index} must reference the ordered variation");
            Assert::assertSame($line['quantity'], (int) $item['quantity'], "item {$index} must keep the cart quantity");
            Assert::assertSame($line['unit_price'], (int) $item['unit_price'], "item {$index} must keep the variation price in cents");
            Assert::assertSame($line['subtotal'], (int) $item['subtotal'], "item {$index} subtotal must be quantity × unit price");
            Assert::assertSame($line['line_total'], (int) $item['line_total'], "item {$index} line total must equal its subtotal when undiscounted");
            Assert::assertSame('onetime', $item['payment_type'], "item {$index} must be a one-time line");
            Assert::assertSame('digital', $item['fulfillment_type'], "item {$index} must inherit the variation fulfillment type");
        }
        // Raw column, not the model attribute: fct_orders.subtotal casts to double on the model,
        // so an (int) cast alone would accept a fractional-cent regression.
        $orderSubtotal = $wpdb->get_var($wpdb->prepare("SELECT subtotal FROM {$wpdb->prefix}fct_orders WHERE id = %d", $order->id));
        Assert::assertMatchesRegularExpression('/^-?\d+$/', (string) $orderSubtotal, "fct_orders.subtotal must be stored as integer cents, got: {$orderSubtotal}");
        Assert::assertSame(3998 + 1650, (int) $orderSubtotal, 'order subtotal must equal the sum of its item subtotals');
    }

    /**
     * Spec: Cases/checkout-functional.md [5]
     *   "Placing an order records exactly one pending charge transaction for the full order
     *    total in the order currency."
     */
    public function placingAnOrderRecordsOnePendingChargeForTheFullTotal(FunctionalTester $I): void
    {
        $storeSettings = get_option('fluent_cart_store_settings', []);
        update_option('fluent_cart_store_settings', array_merge(is_array($storeSettings) ? $storeSettings : [], ['currency' => 'EUR']));
        StoreSettings::clearCache();

        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 3275])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 2]]);

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order must succeed before the transaction is inspected. Body: ' . wp_json_encode($body));

        Assert::assertSame(1, Order::query()->count(), 'one submission must leave exactly one order to inspect');
        $order = Order::query()->first();
        global $wpdb;
        $transactions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fct_order_transactions WHERE order_id = %d",
            $order->id
        ), ARRAY_A);
        Assert::assertCount(1, $transactions, 'one submission must record exactly one transaction');

        $transaction = $transactions[0];
        Assert::assertSame('charge', $transaction['transaction_type'], 'the placement transaction must be a charge');
        Assert::assertSame('pending', $transaction['status'], 'the placement transaction must wait for the gateway');
        Assert::assertMatchesRegularExpression('/^\d+$/', (string) $transaction['total'], 'transaction total must be stored as integer cents');
        Assert::assertSame(6550, (int) $transaction['total'], 'transaction total must equal 2 × 3275 cents');
        $orderTotal = $wpdb->get_var($wpdb->prepare("SELECT total_amount FROM {$wpdb->prefix}fct_orders WHERE id = %d", $order->id));
        Assert::assertMatchesRegularExpression('/^-?\d+$/', (string) $orderTotal, "fct_orders.total_amount must be stored as integer cents, got: {$orderTotal}");
        Assert::assertSame((int) $orderTotal, (int) $transaction['total'], 'transaction total must equal the order total');
        Assert::assertSame('EUR', $transaction['currency'], 'transaction must be recorded in the configured store currency, not the USD fallback');
        Assert::assertSame((string) $order->currency, $transaction['currency'], 'transaction currency must equal the order currency');
        Assert::assertSame('wp_browser_stub', $transaction['payment_method'], 'transaction must record the chosen payment method');
        Assert::assertNotEmpty($transaction['uuid'], 'transaction must receive the uuid the receipt page is keyed by');
    }

    /**
     * Spec: Cases/checkout-functional.md [6]
     *   "The submitted billing details are stored as the order's billing address and as the
     *    new customer's profile."
     */
    public function submittedBillingDetailsBecomeOrderAddressAndCustomer(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 800])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, [
            'billing_full_name'  => 'Ada Lovelace',
            'billing_first_name' => 'Ada',
            'billing_last_name'  => 'Lovelace',
            'billing_email'      => 'ada@example.test',
            'billing_address_1'  => '12 Analytical Engine Rd',
            'billing_city'       => 'Boston',
            'billing_state'      => 'MA',
            'billing_postcode'   => '02108',
            'billing_country'    => 'US',
        ]));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order must succeed before addresses are inspected. Body: ' . wp_json_encode($body));

        Assert::assertSame(1, Order::query()->count(), 'one submission must leave exactly one order to inspect');
        $order = Order::query()->first();
        $billing = OrderAddress::query()->where('order_id', $order->id)->where('type', 'billing')->first();
        Assert::assertNotNull($billing, 'the order must store a billing address row');
        Assert::assertSame('Ada Lovelace', (string) $billing->name, 'billing address must keep the submitted name');
        Assert::assertSame('12 Analytical Engine Rd', (string) $billing->address_1, 'billing address must keep the submitted street');
        Assert::assertSame('Boston', (string) $billing->city, 'billing address must keep the submitted city');
        Assert::assertSame('MA', (string) $billing->state, 'billing address must keep the submitted state');
        Assert::assertSame('02108', (string) $billing->postcode, 'billing address must keep the submitted postcode');
        Assert::assertSame('US', (string) $billing->country, 'billing address must keep the submitted country');

        Assert::assertSame(1, Customer::query()->count(), 'a first-time guest checkout must create exactly one customer');
        $customer = Customer::query()->first();
        Assert::assertSame((int) $customer->id, (int) $order->customer_id, 'the order must belong to the created customer');
        Assert::assertSame('ada@example.test', (string) $customer->email, 'the customer must be created with the submitted billing email');
        Assert::assertSame('Ada', (string) $customer->first_name, 'the customer must be created with the submitted first name');
        Assert::assertSame('Lovelace', (string) $customer->last_name, 'the customer must be created with the submitted last name');
    }

    /**
     * Spec: Cases/checkout-functional.md [7]
     *   "Order, item, and transaction timestamps are recorded in UTC regardless of the site's
     *    timezone setting."
     */
    public function placementTimestampsAreRecordedInUtcRegardlessOfSiteTimezone(FunctionalTester $I): void
    {
        // A six-hour-ahead site clock: a local-time write would land ~21600 s away from UTC now.
        update_option('timezone_string', 'Asia/Dhaka');
        update_option('gmt_offset', '');
        wp_timezone_string();

        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1000])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        $before = time();
        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash));
        $after = time();
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order must succeed before timestamps are inspected. Body: ' . wp_json_encode($body));

        Assert::assertSame(1, Order::query()->count(), 'one submission must leave exactly one order to inspect');
        $order = Order::query()->first();

        // Raw column values: a NULL must fail here, never be coerced into "now" by strtotime.
        global $wpdb;
        $stamps = [
            'order'       => $wpdb->get_var($wpdb->prepare("SELECT created_at FROM {$wpdb->prefix}fct_orders WHERE id = %d", $order->id)),
            'order item'  => $wpdb->get_var($wpdb->prepare("SELECT created_at FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d LIMIT 1", $order->id)),
            'transaction' => $wpdb->get_var($wpdb->prepare("SELECT created_at FROM {$wpdb->prefix}fct_order_transactions WHERE order_id = %d LIMIT 1", $order->id)),
        ];
        foreach ($stamps as $label => $raw) {
            Assert::assertNotNull($raw, "{$label} created_at must be written, not left NULL");
            Assert::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $raw, "{$label} created_at must be a plain Y-m-d H:i:s value");
            $parsed = \DateTime::createFromFormat('Y-m-d H:i:s', (string) $raw, new \DateTimeZone('UTC'));
            Assert::assertInstanceOf(\DateTime::class, $parsed, "{$label} created_at must parse strictly as Y-m-d H:i:s");
            $storedUtc = $parsed->getTimestamp();
            Assert::assertGreaterThanOrEqual($before - 5, $storedUtc, "{$label} created_at read as UTC must not predate the request (a local-time write would be ~21600 s off)");
            Assert::assertLessThanOrEqual($after + 5, $storedUtc, "{$label} created_at read as UTC must not postdate the request (a local-time write would be ~21600 s off)");
        }
    }

    /**
     * Spec: Cases/checkout-functional.md [8]
     *   "Placing an order links the cart to the created order and its customer and moves the
     *    cart out of the draft stage."
     *
     * Cart completion itself is gateway-side (Cod's handler and the stub double both
     * mark the cart completed after this point), so the production-owned state is
     * read at the `prepare_other_data` action — after the draft order is created,
     * before the gateway runs — straight from the database row.
     */
    public function placingAnOrderLinksTheCartToTheOrderAndCustomer(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1500])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);
        Assert::assertSame('draft', (string) $cart->stage, 'the seeded cart must start as a draft');
        Assert::assertNull($cart->order_id, 'the seeded cart must start unlinked');

        $beforeGateway = null;
        $capture = function () use (&$beforeGateway, $cart) {
            $beforeGateway = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        };
        add_action('fluent_cart/checkout/prepare_other_data', $capture);

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, [
            'billing_email' => 'cart-link@example.test',
        ]));
        remove_action('fluent_cart/checkout/prepare_other_data', $capture);
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order must succeed before the cart is inspected. Body: ' . wp_json_encode($body));

        Assert::assertSame(1, Order::query()->count(), 'one submission must leave exactly one order to inspect');
        $order = Order::query()->first();
        Assert::assertNotNull($beforeGateway, 'the prepare_other_data action must have fired with the draft order created');
        Assert::assertSame('intended', (string) $beforeGateway->stage, 'place-order itself must move the cart from draft to intended before the gateway runs');
        Assert::assertSame((int) $order->id, (int) $beforeGateway->order_id, 'place-order itself must link the cart to the order it produced');
        Assert::assertSame((int) $order->customer_id, (int) $beforeGateway->customer_id, 'place-order itself must link the cart to the customer the order was created for');
        Assert::assertSame('cart-link@example.test', (string) $beforeGateway->email, 'place-order itself must copy the customer email onto the cart');

        $stored = Cart::query()->where('cart_hash', $cart->cart_hash)->first();
        Assert::assertSame((int) $order->id, (int) $stored->order_id, 'the order link must survive the gateway step');
    }
}
