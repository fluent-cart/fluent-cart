<?php

namespace Tests\Functional\Rest\Orders;

use FluentCart\App\Helpers\Helper;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/orders.md → "Calculating shipping and tax for an order" section, items 76-79.
 */
class CalculateOrderShippingAndTaxCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/orders.md [76]
     *   "Admin can calculate a shipping charge for a chosen shipping method and a set of items."
     */
    public function adminCanCalculateShippingChargeForChosenMethodAndItems(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        // Customer is unused by /calculate-shipping but seeded so the request body is realistic.
        (new CustomerFactory())->create(['email' => 'calc-shipping@example.test']);

        $product = (new ProductFactory())
            ->withVariantAttributes([
                'fulfillment_type' => 'physical',
                'item_price'       => Helper::toCent(20),
            ])
            ->create();
        $variant = $product->variants->first();

        $zone = (new ShippingZoneFactory())->create(['region' => 'US']);
        $method = (new ShippingMethodFactory())->create([
            'zone_id'  => (int) $zone->id,
            'type'     => 'flat_rate',
            'amount'   => 5,
            'settings' => ['configure_rate' => 'per_order'],
        ]);

        [, $body] = $I->post('/fluent-cart/v2/orders/calculate-shipping', [
            'shipping_id' => (int) $method->id,
            'order_items' => [
                [
                    'id'       => (int) $variant->id,
                    'quantity' => 2,
                ],
            ],
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('shipping_charge', (array) $body, 'response must include the shipping_charge key. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('order_items', (array) $body, 'response must include the order_items key with computed per-item charges');
        Assert::assertSame(
            Helper::toCent(5),
            (int) ($body['shipping_charge'] ?? -1),
            'flat_rate per_order method with amount=5 must produce shipping_charge=500 cents regardless of quantity'
        );
        Assert::assertIsArray($body['order_items'] ?? null, 'order_items in the response must be an array (one entry per submitted variation)');
        Assert::assertArrayHasKey(
            (int) $variant->id,
            (array) ($body['order_items'] ?? []),
            'order_items response must be keyed by the submitted variation id'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [77]
     *   "Admin can list shipping methods available for a chosen country and state, separated from methods that do not apply there."
     */
    public function adminCanListShippingMethodsForCountryAndStateSeparatedFromInapplicable(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        // US zone with NY-specific method — applicable for country=US AND state=NY.
        $usZone = (new ShippingZoneFactory())->create(['region' => 'US']);
        $nyMethod = (new ShippingMethodFactory())->create([
            'zone_id' => (int) $usZone->id,
            'title'   => 'NY-specific US Method',
            'type'    => 'flat_rate',
            'amount'  => 5,
            'states'  => ['NY'],
        ]);

        // Same US zone but TX-specific method — country matches but state does NOT.
        $txMethod = (new ShippingMethodFactory())->create([
            'zone_id' => (int) $usZone->id,
            'title'   => 'TX-specific US Method',
            'type'    => 'flat_rate',
            'amount'  => 7,
            'states'  => ['TX'],
        ]);

        // Different country — inapplicable regardless of state.
        $caZone = (new ShippingZoneFactory())->create(['region' => 'CA']);
        $caMethod = (new ShippingMethodFactory())->create([
            'zone_id' => (int) $caZone->id,
            'title'   => 'CA-only Method',
            'type'    => 'flat_rate',
            'amount'  => 9,
            'states'  => [],
        ]);

        [, $body] = $I->get('/fluent-cart/v2/orders/shipping_methods', [
            'country_code' => 'US',
            'state'        => 'NY',
            'order_items'  => [],
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('shipping_methods', (array) $body, 'response must include applicable shipping_methods key. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('other_shipping_methods', (array) $body, 'response must include other_shipping_methods key for non-applicable methods');

        $applicableIds = array_map('intval', array_column((array) ($body['shipping_methods'] ?? []), 'id'));
        $otherIds      = array_map('intval', array_column((array) ($body['other_shipping_methods'] ?? []), 'id'));

        // State match: NY method appears in applicable.
        Assert::assertContains(
            (int) $nyMethod->id,
            $applicableIds,
            'NY-specific method must appear in applicable list when querying for state=NY'
        );
        // State mismatch in same country: TX method does NOT appear in applicable, despite zone matching.
        Assert::assertNotContains(
            (int) $txMethod->id,
            $applicableIds,
            'TX-specific method must NOT appear in applicable list when querying for state=NY (state filtering must be honored beyond country)'
        );
        Assert::assertContains(
            (int) $txMethod->id,
            $otherIds,
            'TX-specific method must appear in other_shipping_methods because the country zone matches but the state does not'
        );
        // Country mismatch: CA method does NOT appear in applicable.
        Assert::assertNotContains(
            (int) $caMethod->id,
            $applicableIds,
            'CA-only method must NOT appear in applicable list when querying for US'
        );
        Assert::assertContains(
            (int) $caMethod->id,
            $otherIds,
            'CA-only method must appear in other_shipping_methods when querying for US/NY'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [78]
     *   "Admin can preview tax for an admin-built order from an address and a list of items without changing the order."
     */
    public function adminCanPreviewTaxForAdminBuiltOrderFromAddressAndItemsWithoutChangingOrder(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $customer = (new CustomerFactory())->create(['email' => 'preview-tax@example.test']);
        $product  = (new ProductFactory())->create();
        $variant  = $product->variants->first();

        // Enable TaxModule so AdminOrderTaxService runs the real TaxCalculator and the
        // response reflects the submitted address (tax_country) instead of returning the
        // disabled-mode zero payload that ignores input.
        $previousTaxSettings = get_option('fluent_cart_tax_configuration_settings', []);
        update_option('fluent_cart_tax_configuration_settings', array_merge(
            (array) $previousTaxSettings,
            ['enable_tax' => 'yes']
        ));

        global $wpdb;
        $ordersBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders");
        $orderItemsBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_order_items");
        $taxRowsBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_order_tax_rate");

        try {
            [, $body] = $I->post('/fluent-cart/v2/orders/calculate-tax', [
                'country'  => 'US',
                'state'    => 'NY',
                'city'     => 'New York',
                'postcode' => '10001',
                'items'    => [
                    [
                        'post_id'        => (int) $product->ID,
                        'object_id'      => (int) $variant->id,
                        'subtotal'       => Helper::toCent(100),
                        'discount_total' => 0,
                        'quantity'       => 1,
                    ],
                ],
            ]);
        } finally {
            update_option('fluent_cart_tax_configuration_settings', $previousTaxSettings);
        }

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('tax_total', (array) $body, 'response must include the tax_total key. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('shipping_tax', (array) $body, 'response must include the shipping_tax key');
        Assert::assertArrayHasKey('tax_lines', (array) $body, 'response must include the tax_lines key');
        Assert::assertArrayHasKey('tax_behavior', (array) $body, 'response must include the tax_behavior key');
        Assert::assertArrayHasKey('tax_country', (array) $body, 'response must include the tax_country key');
        Assert::assertIsArray($body['tax_lines'] ?? null, 'tax_lines must be an array');

        // tax_country is derived from the submitted address — proving the calculation
        // honors the input rather than returning a static zero payload.
        Assert::assertSame(
            'US',
            (string) ($body['tax_country'] ?? ''),
            'tax_country must reflect the submitted address country (US) when TaxModule is enabled, proving the preview honors the input. Body: ' . wp_json_encode($body)
        );

        // The endpoint must NOT mutate any persisted order/item/tax data — it is a preview.
        $ordersAfter = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders");
        $orderItemsAfter = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_order_items");
        $taxRowsAfter = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_order_tax_rate");

        Assert::assertSame($ordersBefore, $ordersAfter, 'preview tax request must NOT create any fct_orders row');
        Assert::assertSame($orderItemsBefore, $orderItemsAfter, 'preview tax request must NOT create any fct_order_items row');
        Assert::assertSame($taxRowsBefore, $taxRowsAfter, 'preview tax request must NOT create any fct_order_tax_rate row');
    }

    public function unavailableMethodsCannotProduceAShippingQuote(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $zone = (new ShippingZoneFactory())->create(['region' => 'US']);
        $method = (new ShippingMethodFactory())->create(['zone_id' => $zone->id, 'is_enabled' => 0]);
        foreach ([$method->id, 999999] as $methodId) {
            [$status, $body] = $I->post('/fluent-cart/v2/orders/calculate-shipping', [
                'shipping_id' => $methodId,
                'order_items' => [],
            ]);
            Assert::assertSame(422, $status, 'Disabled or missing methods must not return a quote.');
            Assert::assertArrayNotHasKey('shipping_charge', $body, 'Unavailable methods return no shipping charge.');
        }
    }

}
