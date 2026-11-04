<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Order;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ShippingClassFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesCheckoutOrders;

/**
 * Spec: Cases/checkout-functional.md → "Shipping, tax, and the checkout summary" section, items 36-46.
 *
 * TC-105. Proves how a chosen shipping method becomes money: the public method lookup, the
 * checkout summary totals, per-order versus per-item rates, shipping classes, and what one
 * real `place-order` stores for shipping and tax. Every amount is asserted as an exact
 * integer cent value read back from the response or the `fct_orders` row.
 *
 * Tax is armed per test through the actor (the plugin boots this suite with tax off, the
 * same boot-time gate as stock); `_after` always disarms so the next Cest starts clean.
 */
class CheckoutShippingTaxTotalsCest
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
        $I->disarmTaxModule();
    }

    /**
     * Spec: Cases/checkout-functional.md [36]
     *   "A shopper asking for a country's shipping methods gets the enabled methods of the zones
     *    covering that country, most expensive first, each with its id, title, type, and amount."
     */
    public function shopperGetsCountryMethodsMostExpensiveFirst(FunctionalTester $I): void
    {
        $cheap = $this->buildShippingMethod(['title' => 'Ground', 'amount' => 5]);
        $dear = $this->buildShippingMethod(['title' => 'Express', 'amount' => 12]);
        $abroad = $this->buildShippingMethod(['title' => 'UK Post', 'amount' => 9], ['region' => 'GB']);

        [$status, $body] = $I->get('/fluent-cart/v2/checkout/get-available-shipping-methods', ['country_code' => 'US']);

        Assert::assertSame(200, $status, 'the public lookup must answer HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('US', (string) ($body['country_code'] ?? ''), 'the lookup must echo the country it resolved');
        $methods = $body['available_shipping_methods'] ?? null;
        Assert::assertIsArray($methods, 'the lookup must return the available_shipping_methods list');
        Assert::assertSame([(int) $dear->id, (int) $cheap->id], array_map('intval', array_column($methods, 'id')), 'US methods must come back most expensive first and without the GB method (id ' . $abroad->id . ')');
        Assert::assertSame('Express', (string) $methods[0]['title'], 'each method must carry its title');
        Assert::assertSame('flat_rate', (string) $methods[0]['type'], 'each method must carry its type');
        Assert::assertIsNumeric($methods[0]['amount'], 'each method must carry a numeric amount');
        Assert::assertSame(12.0, (float) $methods[0]['amount'], 'each method must carry exactly its configured amount');
    }

    /**
     * Spec: Cases/checkout-functional.md [37]
     *   "The checkout summary for a physical cart reports the item subtotal and, with a chosen
     *    shipping method, adds that method's charge to the total in cents and echoes the chosen
     *    method."
     */
    public function summaryAddsChosenMethodChargeToPhysicalCartTotal(FunctionalTester $I): void
    {
        $variation = $this->buildPhysicalVariation(2000);
        $cart = $this->buildInstantCart($I, [[$variation, 2]]);
        $method = $this->buildShippingMethod(['amount' => 5]);

        [$status, $body] = $I->get('/fluent-cart/v2/checkout/get-checkout-summary-view', [
            'fct_cart_hash'      => $cart->cart_hash,
            'shipping_method_id' => (int) $method->id,
        ]);

        Assert::assertSame(200, $status, 'the summary must answer HTTP 200. Body: ' . wp_json_encode($body));
        $items = $body['items'] ?? [];
        Assert::assertSame(Helper::toDecimal(4000, true), (string) ($items['subtotal'] ?? ''), 'the summary subtotal must be 2 × 2000 cents formatted in the store currency');
        Assert::assertArrayHasKey('shipping_charge', $items, 'the summary must carry shipping_charge');
        Assert::assertIsNumeric($items['shipping_charge'], 'shipping_charge must be a number, not a formatted string');
        Assert::assertSame(500.0, (float) $items['shipping_charge'], 'a flat rate of 5 must be reported as exactly 500 cents');
        Assert::assertArrayHasKey('unformatted_total', $items, 'the summary must carry unformatted_total');
        Assert::assertIsNumeric($items['unformatted_total'], 'unformatted_total must be a number, not a formatted string');
        Assert::assertSame(4500.0, (float) $items['unformatted_total'], 'the summary total must be exactly the item total plus the shipping charge, in cents');
        Assert::assertSame(Helper::toDecimal(4500, true), (string) ($items['total'] ?? ''), 'the formatted total must show the same 4500 cents');
        Assert::assertSame((int) $method->id, (int) ($items['shipping_method_id'] ?? 0), 'the summary must echo the chosen shipping method');
        Assert::assertFalse((bool) ($items['has_subscriptions'] ?? true), 'a one-time cart must report no subscriptions');
    }

    /**
     * Spec: Cases/checkout-functional.md [38]
     *   "The checkout summary for an all-digital cart charges nothing for shipping even when a
     *    shipping method is passed."
     */
    public function summaryChargesNoShippingForAnAllDigitalCart(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1500])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);
        $method = $this->buildShippingMethod(['amount' => 5]);

        [$status, $body] = $I->get('/fluent-cart/v2/checkout/get-checkout-summary-view', [
            'fct_cart_hash'      => $cart->cart_hash,
            'shipping_method_id' => (int) $method->id,
        ]);

        Assert::assertSame(200, $status, 'the summary must answer HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertIsNumeric($body['items']['shipping_charge'] ?? null, 'shipping_charge must be a number');
        Assert::assertSame(0.0, (float) $body['items']['shipping_charge'], 'a digital-only cart must never be charged shipping');
        Assert::assertIsNumeric($body['items']['unformatted_total'] ?? null, 'unformatted_total must be a number');
        Assert::assertSame(1500.0, (float) $body['items']['unformatted_total'], 'the digital cart total must equal exactly its item total');
    }

    /**
     * Spec: Cases/checkout-functional.md [39]
     *   "A per-order flat rate is charged once whatever the quantity, while a per-item rate is
     *    multiplied by the number of physical units in the cart."
     */
    public function perOrderRateIsChargedOnceWhilePerItemRateMultipliesByUnits(FunctionalTester $I): void
    {
        $variation = $this->buildPhysicalVariation(1000);
        // Two digital units ride along: they must never be counted as shippable units.
        $digital = (new ProductFactory())->withVariantAttributes(['item_price' => 800])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 3], [$digital, 2]]);
        $perOrder = $this->buildShippingMethod(['amount' => 5, 'settings' => ['configure_rate' => 'per_order']]);
        $perItem = $this->buildShippingMethod(['amount' => 5, 'settings' => ['configure_rate' => 'per_item']]);

        [, $orderBody] = $I->get('/fluent-cart/v2/checkout/get-checkout-summary-view', ['fct_cart_hash' => $cart->cart_hash, 'shipping_method_id' => (int) $perOrder->id]);
        [, $itemBody] = $I->get('/fluent-cart/v2/checkout/get-checkout-summary-view', ['fct_cart_hash' => $cart->cart_hash, 'shipping_method_id' => (int) $perItem->id]);

        foreach (['shipping_charge', 'unformatted_total'] as $key) {
            Assert::assertIsNumeric($orderBody['items'][$key] ?? null, "per-order {$key} must be a number. Body: " . wp_json_encode($orderBody));
            Assert::assertIsNumeric($itemBody['items'][$key] ?? null, "per-item {$key} must be a number. Body: " . wp_json_encode($itemBody));
        }
        Assert::assertSame(500.0, (float) $orderBody['items']['shipping_charge'], 'a per-order rate of 5 must charge exactly 500 cents once, whatever the unit count');
        Assert::assertSame(5100.0, (float) $orderBody['items']['unformatted_total'], 'per-order total must be exactly 3 × 1000 + 2 × 800 + 500 cents');
        Assert::assertSame(1500.0, (float) $itemBody['items']['shipping_charge'], 'a per-item rate of 5 must charge exactly 3 × 500 cents for the three PHYSICAL units — the two digital units must not be counted');
        Assert::assertSame(6100.0, (float) $itemBody['items']['unformatted_total'], 'per-item total must be exactly 3 × 1000 + 2 × 800 + 1500 cents');
    }

    /**
     * Spec: Cases/checkout-functional.md [40]
     *   "A shipping class with a fixed cost on a physical product adds that cost on top of the
     *    method's rate."
     */
    public function shippingClassFixedCostIsAddedOnTopOfTheMethodRate(FunctionalTester $I): void
    {
        $class = (new ShippingClassFactory())->create(['cost' => 5.00, 'type' => 'fixed', 'per_item' => 0]);
        $variation = $this->buildPhysicalVariation(1000, [], ['other_info' => ['shipping_class' => (int) $class->id]]);
        $cart = $this->buildInstantCart($I, [[$variation, 2]]);
        $method = $this->buildShippingMethod(['amount' => 5]);

        [$status, $body] = $I->get('/fluent-cart/v2/checkout/get-checkout-summary-view', ['fct_cart_hash' => $cart->cart_hash, 'shipping_method_id' => (int) $method->id]);

        Assert::assertSame(200, $status, 'the summary must answer HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertIsNumeric($body['items']['shipping_charge'] ?? null, 'shipping_charge must be a number');
        Assert::assertSame(1000.0, (float) $body['items']['shipping_charge'], 'shipping must be exactly the 500-cent method rate plus the 500-cent fixed class cost');
        Assert::assertIsNumeric($body['items']['unformatted_total'] ?? null, 'unformatted_total must be a number');
        Assert::assertSame(3000.0, (float) $body['items']['unformatted_total'], 'the total must be exactly 2 × 1000 + 1000 cents of shipping');
    }

    /**
     * Spec: Cases/checkout-functional.md [41]
     *   "Placing an order with a chosen shipping method stores the shipping charge, the method's
     *    id and title, marks the order physical and unshipped, and includes the charge in the
     *    order total."
     */
    public function placingAnOrderStoresTheChosenShippingOnTheOrder(FunctionalTester $I): void
    {
        $variation = $this->buildPhysicalVariation(2000);
        $cart = $this->buildInstantCart($I, [[$variation, 2]]);
        $method = $this->buildShippingMethod(['title' => 'Ground', 'amount' => 5]);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, $this->shippingChoice((int) $method->id)));

        Assert::assertSame(200, $status, 'place-order must answer HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order must report success before the order is inspected');
        Assert::assertSame(1, Order::query()->count(), 'one submission must leave exactly one order to inspect');

        global $wpdb;
        $row = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}fct_orders ORDER BY id DESC LIMIT 1", ARRAY_A);
        Assert::assertSame(500, (int) $row['shipping_total'], 'the order must store the 500-cent shipping charge');
        Assert::assertSame(4000, (int) $row['subtotal'], 'the order subtotal must stay 2 × 2000 cents');
        Assert::assertSame(4500, (int) $row['total_amount'], 'the order total must be the subtotal plus the shipping charge');
        Assert::assertSame('physical', (string) $row['fulfillment_type'], 'an order with a physical line must be a physical order');
        Assert::assertSame('unshipped', (string) $row['shipping_status'], 'a freshly placed physical order must start unshipped');
        $config = json_decode((string) $row['config'], true);
        Assert::assertIsArray($config, 'the order config must be stored as JSON');
        Assert::assertSame((int) $method->id, (int) ($config['shipping_method_id'] ?? 0), 'the order config must record the chosen shipping method id');
        Assert::assertSame('Ground', (string) ($config['shipping_method_title'] ?? ''), 'the order config must record the chosen shipping method title');
    }

    /**
     * Spec: Cases/checkout-functional.md [42]
     *   "With tax on and prices entered without tax, placing a physical order stores the item tax
     *    and the shipping tax separately and adds both to the order total."
     */
    public function exclusiveTaxIsStoredSeparatelyForItemsAndShippingAndAddedToTheTotal(FunctionalTester $I): void
    {
        $I->armTaxModule(['tax_inclusion' => 'excluded']);
        $this->buildStandardTaxRate('US', '10');
        $variation = $this->buildPhysicalVariation(2000);
        $method = $this->buildShippingMethod(['amount' => 5]);
        $cart = $this->buildInstantCart($I, [[$variation, 2]]);
        [$patchStatus, $patchBody] = $I->checkoutAjax($this->checkoutPatchParams($cart->cart_hash, 'US', 'NY', (int) $method->id));
        Assert::assertSame(200, $patchStatus, 'precondition: the storefront address/shipping patch must succeed. Body: ' . wp_json_encode($patchBody));

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, $this->shippingChoice((int) $method->id)));

        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order must succeed. Body: ' . wp_json_encode($body));
        global $wpdb;
        $row = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}fct_orders ORDER BY id DESC LIMIT 1", ARRAY_A);
        Assert::assertSame(4000, (int) $row['subtotal'], 'the subtotal must stay the untaxed 2 × 2000 cents');
        Assert::assertSame(400, (int) $row['tax_total'], 'item tax must be 10% of 4000 cents');
        Assert::assertSame(500, (int) $row['shipping_total'], 'the shipping charge must stay 500 cents');
        Assert::assertSame(50, (int) $row['shipping_tax'], 'shipping tax must be 10% of the 500-cent charge, stored on its own');
        Assert::assertSame(4950, (int) $row['total_amount'], 'the total must add item tax and shipping tax on top of items and shipping');
        Assert::assertSame(1, (int) $row['tax_behavior'], 'prices entered without tax must be recorded as exclusive');
        $itemTax = $wpdb->get_var($wpdb->prepare("SELECT tax_amount FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d", $row['id']));
        Assert::assertSame(400, (int) $itemTax, 'the order line must carry its own 400-cent tax');
    }

    /**
     * Spec: Cases/checkout-functional.md [43]
     *   "With tax on and prices entered including tax, the item tax and shipping tax are carved
     *    out of the prices and the order total is not increased."
     */
    public function inclusiveTaxIsCarvedOutWithoutRaisingTheTotal(FunctionalTester $I): void
    {
        $I->armTaxModule(['tax_inclusion' => 'included']);
        $this->buildStandardTaxRate('US', '10');
        $variation = $this->buildPhysicalVariation(2000);
        $method = $this->buildShippingMethod(['amount' => 5]);
        $cart = $this->buildInstantCart($I, [[$variation, 2]]);
        [$patchStatus, $patchBody] = $I->checkoutAjax($this->checkoutPatchParams($cart->cart_hash, 'US', 'NY', (int) $method->id));
        Assert::assertSame(200, $patchStatus, 'precondition: the storefront address/shipping patch must succeed. Body: ' . wp_json_encode($patchBody));

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, $this->shippingChoice((int) $method->id)));

        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order must succeed. Body: ' . wp_json_encode($body));
        global $wpdb;
        $row = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}fct_orders ORDER BY id DESC LIMIT 1", ARRAY_A);
        Assert::assertSame(364, (int) $row['tax_total'], 'item tax must be the 10/110 share of 4000 cents, rounded to 364');
        Assert::assertSame(45, (int) $row['shipping_tax'], 'shipping tax must be the 10/110 share of 500 cents, rounded to 45');
        Assert::assertSame(4500, (int) $row['total_amount'], 'inclusive tax must not raise the total above items plus shipping');
        Assert::assertSame(2, (int) $row['tax_behavior'], 'prices entered with tax must be recorded as inclusive');
    }

    /**
     * Spec: Cases/checkout-functional.md [44]
     *   "When a rate carries its own shipping rate, the shipping tax uses that rate while items
     *    keep the base rate."
     */
    public function dedicatedShippingRateTaxesShippingWhileItemsKeepTheBaseRate(FunctionalTester $I): void
    {
        $I->armTaxModule(['tax_inclusion' => 'excluded']);
        $this->buildStandardTaxRate('US', '10', ['for_shipping' => '5']);
        $variation = $this->buildPhysicalVariation(2000);
        $method = $this->buildShippingMethod(['amount' => 5]);
        $cart = $this->buildInstantCart($I, [[$variation, 2]]);
        [$patchStatus, $patchBody] = $I->checkoutAjax($this->checkoutPatchParams($cart->cart_hash, 'US', 'NY', (int) $method->id));
        Assert::assertSame(200, $patchStatus, 'precondition: the storefront address/shipping patch must succeed. Body: ' . wp_json_encode($patchBody));

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, $this->shippingChoice((int) $method->id)));

        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order must succeed. Body: ' . wp_json_encode($body));
        global $wpdb;
        $row = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}fct_orders ORDER BY id DESC LIMIT 1", ARRAY_A);
        Assert::assertSame(400, (int) $row['tax_total'], 'items must keep the 10% base rate');
        Assert::assertSame(25, (int) $row['shipping_tax'], 'shipping must be taxed at the dedicated 5% rate: 25 cents on 500');
        Assert::assertSame(4925, (int) $row['total_amount'], 'the total must reflect 400 item tax and 25 shipping tax');
    }

    /**
     * Spec: Cases/checkout-functional.md [45]
     *   "When the shopper's country has no tax rate, placing the same physical order stores zero
     *    item tax and zero shipping tax."
     */
    public function countryWithoutARateStoresZeroTax(FunctionalTester $I): void
    {
        $I->armTaxModule(['tax_inclusion' => 'excluded']);
        $this->buildStandardTaxRate('US', '10');
        $variation = $this->buildPhysicalVariation(2000);
        $method = $this->buildShippingMethod(['amount' => 5], ['region' => 'CA']);
        $cart = $this->buildInstantCart($I, [[$variation, 2]]);
        [$patchStatus, $patchBody] = $I->checkoutAjax($this->checkoutPatchParams($cart->cart_hash, 'CA', 'ON', (int) $method->id));
        Assert::assertSame(200, $patchStatus, 'precondition: the storefront address/shipping patch must succeed. Body: ' . wp_json_encode($patchBody));

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, $this->shippingChoice((int) $method->id) + [
            'billing_country'    => 'CA',
            'billing_state'      => 'ON',
            'billing_city'       => 'Toronto',
            'billing_postcode'   => 'M5V 2T6',
        ]));

        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order must succeed. Body: ' . wp_json_encode($body));
        global $wpdb;
        $row = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}fct_orders ORDER BY id DESC LIMIT 1", ARRAY_A);
        Assert::assertSame(0, (int) $row['tax_total'], 'a country with no rate must produce zero item tax');
        Assert::assertSame(0, (int) $row['shipping_tax'], 'a country with no rate must produce zero shipping tax');
        Assert::assertSame(500, (int) $row['shipping_total'], 'shipping is still charged without tax');
        Assert::assertSame(4500, (int) $row['total_amount'], 'the total must be items plus shipping only');
    }

    /**
     * Spec: Cases/checkout-functional.md [46]
     *   "The shipping charge on the order comes from the method the address validation accepted;
     *    a request that names an available method in one field and a different, unavailable
     *    method in the other cannot be priced by the second."
     *
     * The checkout form posts the checked radio (`fc_shipping_method`) and a hidden mirror the
     * JS keeps equal (`fc_selected_shipping_method`). Validation reads the mirror. Before TC-070,
     * pricing read the radio, so a crafted POST could pass validation with one method and be
     * priced by a free method from a zone the address is not in.
     */
    public function orderIsPricedFromTheValidatedShippingMethod(FunctionalTester $I): void
    {
        $variation = $this->buildPhysicalVariation(2000);
        $usGround = $this->buildShippingMethod(['title' => 'US Ground', 'amount' => 5]);
        $gbFree = $this->buildShippingMethod(['title' => 'GB Free', 'amount' => 0, 'type' => 'free_shipping'], ['region' => 'GB']);

        // Each leg places its own order from a fresh cart, so an order row can only be the one
        // that leg created: a refused leg leaves the count where it was.
        $shape = function (array $fields) use ($I, $variation) {
            $cart = $this->buildInstantCart($I, [[$variation, 2]]);
            $before = Order::query()->count();

            [$status, $body] = $I->postCapturingJsonDie(
                '/fluent-cart/v2/checkout/place-order',
                $this->checkoutPayload($cart->cart_hash, $fields)
            );

            $order = Order::query()->count() > $before ? Order::query()->orderBy('id', 'DESC')->first() : null;

            return [
                'http'     => $status,
                'status'   => (string) ($body['status'] ?? ''),
                'method'   => $order ? (string) ($order->config['shipping_method_title'] ?? '') : '',
                'shipping' => $order ? (int) $order->shipping_total : null,
                'total'    => $order ? (int) $order->total_amount : null,
                // Which rule refused the leg, so a refusal for some other reason (an empty cart,
                // a missing field) cannot pass as the shipping check doing its job.
                'errors'   => array_map('array_keys', (array) ($body['errors'] ?? [])),
            ];
        };

        Assert::assertSame(
            [
                // The control: both fields agree, and the order carries the US rate.
                'matching' => ['http' => 200, 'status' => 'success', 'method' => 'US Ground', 'shipping' => 500, 'total' => 4500, 'errors' => []],
                // The TC-070 request. The radio names a free GB method; the validated mirror
                // names the US rate, and the mirror is what the order is priced and stamped by.
                'mismatch' => ['http' => 200, 'status' => 'success', 'method' => 'US Ground', 'shipping' => 500, 'total' => 4500, 'errors' => []],
                // The same request the other way round. The validated field names the foreign
                // method, so validation refuses it before any order exists.
                'foreign'  => ['http' => 200, 'status' => 'failed', 'method' => '', 'shipping' => null, 'total' => null, 'errors' => ['shipping_method' => ['invalid']]],
                // A radio with no mirror is not a way around validation: no validated method,
                // no order.
                'radio'    => ['http' => 200, 'status' => 'failed', 'method' => '', 'shipping' => null, 'total' => null, 'errors' => ['shipping_method' => ['required']]],
                // Not a plain id. On PHP 7.4 a loose compare matched "<US id><b><GB id>" to the US
                // method, and sanitize_text_field() then made it the GB id, which priced the order.
                // Only a plain integer is an id, so this is refused on every PHP version.
                'crafted'  => ['http' => 200, 'status' => 'failed', 'method' => '', 'shipping' => null, 'total' => null, 'errors' => ['shipping_method' => ['invalid']]],
            ],
            [
                'matching' => $shape(['fc_selected_shipping_method' => (int) $usGround->id, 'fc_shipping_method' => (int) $usGround->id]),
                'mismatch' => $shape(['fc_selected_shipping_method' => (int) $usGround->id, 'fc_shipping_method' => (int) $gbFree->id]),
                'foreign'  => $shape(['fc_selected_shipping_method' => (int) $gbFree->id, 'fc_shipping_method' => (int) $usGround->id]),
                'radio'    => $shape(['fc_shipping_method' => (int) $gbFree->id]),
                'crafted'  => $shape(['fc_selected_shipping_method' => $usGround->id . '<b>' . $gbFree->id, 'fc_shipping_method' => (int) $usGround->id]),
            ],
            'the order is priced only by the method validation accepted for the address'
        );
    }
}
