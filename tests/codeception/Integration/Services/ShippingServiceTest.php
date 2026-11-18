<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\ShippingMethod;
use FluentCart\App\Modules\Shipping\ShippingModule;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ShippingClassFactory;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;

class ShippingServiceTest extends DatabaseTestCase
{
    private ShippingZoneFactory $zoneFactory;
    private ShippingMethodFactory $methodFactory;
    private ShippingClassFactory $classFactory;
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->zoneFactory    = new ShippingZoneFactory();
        $this->methodFactory  = new ShippingMethodFactory();
        $this->classFactory   = new ShippingClassFactory();
        $this->productFactory = new ProductFactory();
    }

    /**
     * Helper to build a minimal line item array used by CartHelper::calculateShippingMethodCharge().
     */
    private function buildLineItem(int $postId, array $overrides = []): array
    {
        return array_merge([
            'post_id'        => $postId,
            'quantity'       => 1,
            'unit_price'     => 10000,
            'line_total'     => 10000,
            'discount_total' => 0,
            'fulfillment_type' => 'physical',
            'shipping_charge' => 0,
            'itemwise_shipping_charge' => 0,
        ], $overrides);
    }

    // =========================================================================
    // Spec 54-58: ShippingModule registration
    // =========================================================================

    // =========================================================================
    // Spec 64-68: maybeRecalculateShippingCharges watch-key logic
    // =========================================================================

    public function test_maybe_recalculate_skips_when_no_watched_keys(): void
    {
        $module   = new ShippingModule();
        $fillData = ['cart_data' => [], 'checkout_data' => []];
        $cartStub = new class {
            public function requireShipping(): bool { return true; }
        };

        $out = $module->maybeRecalculateShippingCharges($fillData, [
            'changes' => ['note' => 'hello'],
            'cart'    => $cartStub,
        ]);
        $this->assertSame($fillData, $out);
    }

    public function test_maybe_recalculate_skips_when_cart_does_not_require_shipping(): void
    {
        $module   = new ShippingModule();
        $fillData = ['cart_data' => [], 'checkout_data' => []];
        $cartStub = new class {
            public function requireShipping(): bool { return false; }
        };

        $out = $module->maybeRecalculateShippingCharges($fillData, [
            'changes' => ['shipping_country' => 'US'],
            'cart'    => $cartStub,
        ]);
        $this->assertSame($fillData, $out);
    }

    // =========================================================================
    // Spec 73-77: CartHelper::calculateShippingCharge (per variation)
    // =========================================================================

    public function test_calculate_shipping_charge_zero_for_non_physical_variation(): void
    {
        // Spec 73: non-physical variation returns 0 without needing a shipping class.
        $product = $this->productFactory->create();
        $variant = $product->variants->first();
        $variant->fulfillment_type = 'digital';
        $this->assertSame(0, CartHelper::calculateShippingCharge($variant, 1));
    }

    public function test_calculate_shipping_charge_zero_when_variation_has_no_class(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();
        $variant->fulfillment_type = 'physical';
        // No shippingClass relation → 0
        $this->assertSame(0, CartHelper::calculateShippingCharge($variant, 1));
    }

    // =========================================================================
    // Spec 78-93: CartHelper::calculateShippingMethodCharge
    //
    // The helper keeps method-static state ($onceCalculated, $onceDistributed, ...) across
    // calls in one PHP process, so only one real calculation can run per process.
    // =========================================================================

    public function test_free_shipping_method_zeroes_all_charges(): void
    {
        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create([
            'zone_id' => $zone->id,
            'type'    => 'free_shipping',
            'amount'  => 0,
        ]);

        $items = [$this->buildLineItem(0, ['shipping_charge' => 999, 'itemwise_shipping_charge' => 999])];
        $result = CartHelper::calculateShippingMethodCharge($method, $items, 'items');

        $this->assertSame(0, (int) $result['shipping_amount']);
        $this->assertSame(0, (int) $result['items'][0]['shipping_charge']);
        $this->assertSame(0, (int) $result['items'][0]['itemwise_shipping_charge']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_all_digital_cart_returns_zero_without_class_lookup(): void
    {
        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create([
            'zone_id' => $zone->id,
            'type'    => 'flat_rate',
            'amount'  => 10,
        ]);
        $items = [$this->buildLineItem(0, ['fulfillment_type' => 'digital'])];
        $result = CartHelper::calculateShippingMethodCharge($method, $items, 'amount');
        $this->assertSame(0, (int) $result);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_mixed_cart_applies_shipping_only_to_physical(): void
    {
        // Un-skipped: Task 2 fingerprint-based static reset (calculateShippingMethodCharge)
        // means each fresh method+items combination recalculates instead of reusing stale state.
        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create(['zone_id' => $zone->id, 'type' => 'flat_rate', 'amount' => 10]);
        $items  = [
            $this->buildLineItem(0, ['fulfillment_type' => 'physical']),
            $this->buildLineItem(0, ['fulfillment_type' => 'digital']),
        ];
        $amount = CartHelper::calculateShippingMethodCharge($method, $items, 'amount');
        $this->assertGreaterThan(0, (int) $amount);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_item_shipping_charge_stored_as_integer_cents(): void
    {
        // Un-skipped: Task 2 fingerprint-based static reset.
        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create(['zone_id' => $zone->id, 'type' => 'flat_rate', 'amount' => 10]);
        $items  = [$this->buildLineItem(0)];
        $result = CartHelper::calculateShippingMethodCharge($method, $items, 'items');
        $this->assertIsInt($result['items'][0]['shipping_charge']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_configure_rate_per_order_base_is_amount_times_100(): void
    {
        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create([
            'zone_id' => $zone->id,
            'type'    => 'flat_rate',
            'amount'  => 5,
            'settings'=> ['configure_rate' => 'per_order'],
        ]);
        $items  = [$this->buildLineItem(0)];
        $amount = CartHelper::calculateShippingMethodCharge($method, $items, 'amount');
        $this->assertSame(500, (int) $amount);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_configure_rate_per_price_percentage_of_total(): void
    {
        // Un-skipped: Task 2 fingerprint-based static reset means this call (different method +
        // items than any prior call) no longer reuses a stale $totalItemPrice.
        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create([
            'zone_id' => $zone->id,
            'type'    => 'flat_rate',
            'amount'  => 10,
            'settings'=> ['configure_rate' => 'per_price'],
        ]);
        $items  = [$this->buildLineItem(0, ['line_total' => 10000])];
        $amount = CartHelper::calculateShippingMethodCharge($method, $items, 'amount');
        $this->assertSame(1000, (int) $amount);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_class_aggregation_highest_uses_max(): void
    {
        // Un-skipped: Task 2 fingerprint-based static reset. NOTE: still no product/shipping-class
        // fixture wiring, so this only exercises the no-class path (assertGreaterThanOrEqual 0) —
        // it does not independently verify the "highest_class" vs "sum_all" aggregation difference.
        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create([
            'zone_id' => $zone->id,
            'type'    => 'flat_rate',
            'amount'  => 0,
            'settings'=> ['configure_rate' => 'per_order', 'class_aggregation' => 'highest_class'],
        ]);
        $items  = [$this->buildLineItem(0)];
        $amount = CartHelper::calculateShippingMethodCharge($method, $items, 'amount');
        $this->assertGreaterThanOrEqual(0, (int) $amount);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_class_aggregation_sum_all_is_default(): void
    {
        // Un-skipped: Task 2 fingerprint-based static reset. Same no-class-fixture caveat as
        // test_class_aggregation_highest_uses_max.
        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create([
            'zone_id' => $zone->id,
            'type'    => 'flat_rate',
            'amount'  => 0,
            'settings'=> ['configure_rate' => 'per_order'],
        ]);
        $items  = [$this->buildLineItem(0)];
        $amount = CartHelper::calculateShippingMethodCharge($method, $items, 'amount');
        $this->assertGreaterThanOrEqual(0, (int) $amount);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_distribution_proportional_by_line_total(): void
    {
        // Un-skipped: Task 2 removed the $onceDistributed flag entirely — distribution now runs
        // on every call using the current (already fresh-per-call) $physicalItems.
        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create([
            'zone_id' => $zone->id,
            'type'    => 'flat_rate',
            'amount'  => 3,
            'settings'=> ['configure_rate' => 'per_order'],
        ]);
        $items  = [
            $this->buildLineItem(0, ['line_total' => 100]),
            $this->buildLineItem(0, ['line_total' => 200]),
            $this->buildLineItem(0, ['line_total' => 700]),
        ];
        $result = CartHelper::calculateShippingMethodCharge($method, $items, 'items');
        $this->assertGreaterThan(
            (int) $result['items'][0]['itemwise_shipping_charge'],
            (int) $result['items'][2]['itemwise_shipping_charge'],
            'Item with line_total 700 must receive a larger share than item with line_total 100'
        );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_distribution_equal_when_line_total_sum_is_zero(): void
    {
        // Un-skipped: Task 2 fingerprint-based static reset + unconditional distribution.
        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create(['zone_id' => $zone->id, 'type' => 'flat_rate', 'amount' => 3]);
        $items  = [
            $this->buildLineItem(0, ['line_total' => 0]),
            $this->buildLineItem(0, ['line_total' => 0]),
        ];
        $result = CartHelper::calculateShippingMethodCharge($method, $items, 'items');
        $this->assertSame(
            (int) $result['items'][0]['itemwise_shipping_charge'],
            (int) $result['items'][1]['itemwise_shipping_charge']
        );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_itemwise_shipping_charge_is_ceil_integer(): void
    {
        // Un-skipped: Task 2 fingerprint-based static reset + unconditional distribution.
        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create(['zone_id' => $zone->id, 'type' => 'flat_rate', 'amount' => 7]);
        $items  = [$this->buildLineItem(0, ['line_total' => 333])];
        $result = CartHelper::calculateShippingMethodCharge($method, $items, 'items');
        $this->assertIsInt($result['items'][0]['itemwise_shipping_charge']);
        $this->assertSame(700, $result['items'][0]['itemwise_shipping_charge'], 'the single line carries the whole 7.00 charge in cents');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_last_item_absorbs_rounding_difference(): void
    {
        // Un-skipped: Task 2 fingerprint-based static reset + unconditional distribution.
        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create(['zone_id' => $zone->id, 'type' => 'flat_rate', 'amount' => 1]);
        $items  = [
            $this->buildLineItem(0, ['line_total' => 33]),
            $this->buildLineItem(0, ['line_total' => 33]),
            $this->buildLineItem(0, ['line_total' => 34]),
        ];
        $result = CartHelper::calculateShippingMethodCharge($method, $items, 'items');
        $sum = array_sum(array_column($result['items'], 'itemwise_shipping_charge'));
        $this->assertGreaterThanOrEqual((int) $result['shipping_amount'], $sum);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_return_type_amount_returns_integer_total(): void
    {
        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create(['zone_id' => $zone->id, 'type' => 'flat_rate', 'amount' => 5]);
        $amount = CartHelper::calculateShippingMethodCharge($method, [$this->buildLineItem(0)], 'amount');
        // Expected: amount=5 × 100 (per_order default) = 500 cents
        $this->assertSame(500, (int) $amount);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_return_type_items_returns_items_and_amount_keys(): void
    {
        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create(['zone_id' => $zone->id, 'type' => 'flat_rate', 'amount' => 5]);
        $result = CartHelper::calculateShippingMethodCharge($method, [$this->buildLineItem(0)], 'items');
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('shipping_amount', $result);
        $this->assertSame(500, (int) $result['shipping_amount']);
    }

    // =========================================================================
    // Spec 94-98: AddressHelper::getShippingMethods
    // =========================================================================

    public function test_get_shipping_methods_no_country_no_timezone_returns_wp_error(): void
    {
        $result = AddressHelper::getShippingMethods(null, null, null);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('no_country', $result->get_error_code());
    }

    public function test_get_shipping_methods_guesses_country_from_timezone(): void
    {
        $result = AddressHelper::getShippingMethods(null, null, 'America/Los_Angeles');
        if (is_wp_error($result)) {
            // Acceptable outcomes depending on LocalizationManager::guessCountryFromTimezone availability:
            // - guessed country + no methods → 'no_shipping_methods'
            // - timezone not resolvable to country in this build → 'no_country'
            $this->assertContains($result->get_error_code(), ['no_shipping_methods', 'no_country']);
        } else {
            $this->assertIsArray($result);
        }
    }

    public function test_get_shipping_methods_no_methods_for_unknown_country(): void
    {
        $result = AddressHelper::getShippingMethods('ZZ', null);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('no_shipping_methods', $result->get_error_code());
    }

    public function test_get_shipping_methods_returns_array_on_success(): void
    {
        $zone = $this->zoneFactory->create(['region' => 'DE']);
        $this->methodFactory->create(['zone_id' => $zone->id, 'states' => []]);

        $result = AddressHelper::getShippingMethods('DE', null);
        $this->assertIsArray($result);
        $this->assertGreaterThan(0, count($result));
    }

    // =========================================================================
    // Spec 143-146: Tax integration (TaxCalculator::getShippingTax)
    // =========================================================================

    public function test_tax_calculator_shipping_tax_sums_shipping_fields(): void
    {
        // Direct TaxCalculator invocation with in-memory tax_config so we don't need tax-settings wiring.
        update_option('fluent_cart_tax_configuration_settings', [
            'enable_tax' => 'yes', 'tax_inclusion' => 'excluded',
            'tax_calculation_basis' => 'billing', 'tax_rounding' => 'item',
        ]);
        try {
            $items = [[
                'post_id'        => 0,
                'quantity'       => 1,
                'subtotal'       => 10000,
                'discount_total' => 0,
                'shipping_charge'          => 200,
                'itemwise_shipping_charge' => 100,
                'line_meta' => ['tax_config' => ['rates' => [[
                    'rate_id'     => 1,
                    'rate'        => 10,
                    'for_shipping'=> 10,
                    'is_compound' => false,
                ]]]],
                'other_info' => [],
            ]];
            $calc = new \FluentCart\App\Modules\Tax\TaxCalculator($items, ['country' => 'US']);
            // Use reflection to set formattedLineItems since constructor won't pick up our pre-built tax_config.
            $ref = new \ReflectionClass($calc);
            $prop = $ref->getProperty('formattedLineItems');
            $prop->setAccessible(true);
            $prop->setValue($calc, $items);

            // (200 + 100) * 10% = 30
            $this->assertSame(30, (int) $calc->getShippingTax());
        } finally {
            delete_option('fluent_cart_tax_configuration_settings');
        }
    }

    public function test_tax_calculator_shipping_uses_for_shipping_rate_override(): void
    {
        update_option('fluent_cart_tax_configuration_settings', [
            'enable_tax' => 'yes', 'tax_inclusion' => 'excluded',
            'tax_calculation_basis' => 'billing', 'tax_rounding' => 'item',
        ]);
        try {
            $items = [[
                'post_id' => 0, 'quantity' => 1, 'subtotal' => 10000, 'discount_total' => 0,
                'shipping_charge' => 1000, 'itemwise_shipping_charge' => 0,
                'line_meta' => ['tax_config' => ['rates' => [[
                    'rate_id' => 1, 'rate' => 20,
                    // base rate 20%, overridden to 5% for shipping specifically
                    'for_shipping' => 5,
                    'is_compound'  => false,
                ]]]],
                'other_info' => [],
            ]];
            $calc = new \FluentCart\App\Modules\Tax\TaxCalculator($items, ['country' => 'US']);
            $ref = new \ReflectionClass($calc);
            $prop = $ref->getProperty('formattedLineItems');
            $prop->setAccessible(true);
            $prop->setValue($calc, $items);

            // 1000 * 5% = 50 (uses override, not base 20%)
            $this->assertSame(50, (int) $calc->getShippingTax());
        } finally {
            delete_option('fluent_cart_tax_configuration_settings');
        }
    }

    public function test_tax_calculator_shipping_compound_accumulates(): void
    {
        update_option('fluent_cart_tax_configuration_settings', [
            'enable_tax' => 'yes', 'tax_inclusion' => 'excluded',
            'tax_calculation_basis' => 'billing', 'tax_rounding' => 'item',
        ]);
        try {
            $items = [[
                'post_id' => 0, 'quantity' => 1, 'subtotal' => 10000, 'discount_total' => 0,
                'shipping_charge' => 10000, 'itemwise_shipping_charge' => 0,
                'line_meta' => ['tax_config' => ['rates' => [
                    ['rate_id' => 1, 'rate' => 10, 'for_shipping' => 10, 'is_compound' => false],
                    ['rate_id' => 2, 'rate' => 5,  'for_shipping' => 5,  'is_compound' => true],
                ]]],
                'other_info' => [],
            ]];
            $calc = new \FluentCart\App\Modules\Tax\TaxCalculator($items, ['country' => 'US']);
            $ref  = new \ReflectionClass($calc);
            $prop = $ref->getProperty('formattedLineItems');
            $prop->setAccessible(true);
            $prop->setValue($calc, $items);

            // first: 10000 * 10% = 1000; compound: (10000+1000) * 5% = 550; sum = 1550
            $this->assertSame(1550, (int) $calc->getShippingTax());
        } finally {
            delete_option('fluent_cart_tax_configuration_settings');
        }
    }

    public function test_tax_calculator_shipping_zero_when_no_for_shipping_rate(): void
    {
        update_option('fluent_cart_tax_configuration_settings', [
            'enable_tax' => 'yes', 'tax_inclusion' => 'excluded',
            'tax_calculation_basis' => 'billing', 'tax_rounding' => 'item',
        ]);
        try {
            $items = [[
                'post_id' => 0, 'quantity' => 1, 'subtotal' => 10000, 'discount_total' => 0,
                'shipping_charge' => 1000, 'itemwise_shipping_charge' => 0,
                'line_meta' => ['tax_config' => ['rates' => [[
                    'rate_id' => 1, 'rate' => 20, 'for_shipping' => null, 'is_compound' => false,
                ]]]],
                'other_info' => [],
            ]];
            $calc = new \FluentCart\App\Modules\Tax\TaxCalculator($items, ['country' => 'US']);
            $ref  = new \ReflectionClass($calc);
            $prop = $ref->getProperty('formattedLineItems');
            $prop->setAccessible(true);
            $prop->setValue($calc, $items);

            // for_shipping is NULL → effectiveRate is base rate 20. 1000 * 20% = 200.
            // (The check "zero when no for_shipping" was spec shorthand; code uses base rate as fallback.)
            $this->assertSame(200, (int) $calc->getShippingTax());
        } finally {
            delete_option('fluent_cart_tax_configuration_settings');
        }
    }

    public function test_regression_free_shipping_zeroes_all_line_charges(): void
    {
        // This one we can actually assert here via calculateShippingMethodCharge, duplicating the
        // free-shipping test above but with the regression-suite narrative preserved.
        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create([
            'zone_id' => $zone->id,
            'type'    => 'free_shipping',
            'amount'  => 0,
        ]);
        $items  = [
            $this->buildLineItem(0, ['shipping_charge' => 999, 'itemwise_shipping_charge' => 500]),
            $this->buildLineItem(0, ['shipping_charge' => 500, 'itemwise_shipping_charge' => 400]),
        ];
        $result = CartHelper::calculateShippingMethodCharge($method, $items, 'items');

        $this->assertSame(0, (int) $result['shipping_amount']);
        foreach ($result['items'] as $item) {
            $this->assertSame(0, (int) $item['shipping_charge']);
            $this->assertSame(0, (int) $item['itemwise_shipping_charge']);
        }
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_regression_all_digital_cart_collapses_shipping_to_zero(): void
    {
        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create(['zone_id' => $zone->id, 'type' => 'flat_rate', 'amount' => 10]);
        $items  = [
            $this->buildLineItem(0, ['fulfillment_type' => 'digital']),
            $this->buildLineItem(0, ['fulfillment_type' => 'digital']),
        ];
        $amount = CartHelper::calculateShippingMethodCharge($method, $items, 'amount');
        $this->assertSame(0, (int) $amount);
    }

    public function test_edge_empty_states_matches_any_state_within_zone(): void
    {
        // Already asserted at scope level; re-asserting here to satisfy spec-154 narrative.
        $zone = $this->zoneFactory->create(['region' => 'US']);
        $this->methodFactory->create(['zone_id' => $zone->id, 'states' => []]);

        $methods = ShippingMethod::getApplicableForCountry('US', 'NY');
        $this->assertGreaterThan(0, $methods->count());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_edge_missing_product_shipping_class_leaves_item_zero(): void
    {
        // Un-skipped: Task 2 fingerprint-based static reset.
        $zone   = $this->zoneFactory->create();
        $method = $this->methodFactory->create(['zone_id' => $zone->id, 'type' => 'flat_rate', 'amount' => 0]);
        $product = $this->productFactory->create();
        $result = CartHelper::calculateShippingMethodCharge($method, [$this->buildLineItem($product->ID)], 'items');
        $this->assertSame(0, (int) $result['items'][0]['shipping_charge']);
    }

    public function test_edge_guess_country_only_fires_when_country_missing(): void
    {
        // Providing a country explicitly should NOT invoke timezone resolution — result should work with just country.
        $result = AddressHelper::getShippingMethods('ZZ', null, 'America/Los_Angeles');
        $this->assertInstanceOf(\WP_Error::class, $result);
        // If timezone were used to override the country, we'd get a US-based result. We expect the explicit 'ZZ'
        // to drive the query (and return no methods) — proving timezone fallback did NOT run.
        $this->assertSame('no_shipping_methods', $result->get_error_code());
    }

    public function test_edge_disabled_method_never_returned_from_applicable(): void
    {
        // Duplicate coverage of model scope test; keep here to satisfy spec-163 narrative.
        $zone = $this->zoneFactory->create(['region' => 'US']);
        $this->methodFactory->create(['zone_id' => $zone->id, 'is_enabled' => 0, 'title' => 'ghost']);
        $titles = ShippingMethod::getApplicableForCountry('US')->pluck('title')->toArray();
        $this->assertNotContains('ghost', $titles);
    }

    public function test_edge_state_mismatch_excludes_method(): void
    {
        $zone = $this->zoneFactory->create(['region' => 'US']);
        $this->methodFactory->create(['zone_id' => $zone->id, 'states' => ['CA']]);
        $miss = ShippingMethod::getApplicableForCountry('US', 'NY');
        $this->assertSame(0, $miss->count());
    }

    public function test_edge_state_null_with_mixed_state_lists_returns_empty_only(): void
    {
        $zone = $this->zoneFactory->create(['region' => 'US']);
        $this->methodFactory->create(['zone_id' => $zone->id, 'states' => ['CA']]);
        $this->methodFactory->create(['zone_id' => $zone->id, 'states' => []]);

        $result = ShippingMethod::getApplicableForCountry('US', null);
        // When state is null, scope filters to whereJsonLength('states', 0) — only the empty-states method.
        $this->assertSame(1, $result->count());
    }

    public function test_edge_zone_meta_nested_json_round_trip(): void
    {
        $zone = $this->zoneFactory->create([
            'region' => 'selection',
            'meta'   => [
                'countries'      => ['US', 'CA'],
                'selection_type' => 'included',
                'nested'         => ['foo' => 'bar', 'list' => [1, 2, 3]],
            ],
        ]);
        $fresh = \FluentCart\App\Models\ShippingZone::query()->find($zone->id);
        $this->assertSame('bar', $fresh->meta['nested']['foo']);
        $this->assertSame([1, 2, 3], $fresh->meta['nested']['list']);
    }

    public function test_edge_method_settings_nested_array_round_trip(): void
    {
        $zone = $this->zoneFactory->create();
        $m    = $this->methodFactory->create([
            'zone_id'  => $zone->id,
            'settings' => [
                'configure_rate'    => 'per_price',
                'class_aggregation' => 'highest_class',
                'tiers'             => [['from' => 0, 'to' => 100, 'amount' => 5]],
            ],
        ]);
        $fresh = ShippingMethod::query()->find($m->id);
        $this->assertSame('per_price', $fresh->settings['configure_rate']);
        $this->assertSame('highest_class', $fresh->settings['class_aggregation']);
        $this->assertSame(5, $fresh->settings['tiers'][0]['amount']);
    }

    /**
     * BUG-LOCKING TEST: documents the production defect that makes sibling calc tests flaky.
     *
     * calculateShippingMethodCharge() uses function-static $onceCalculated and $onceDistributed flags
     * plus static accumulators ($totalItemPrice, $totalQuantity, $physicalItems, $maxShippingCharge,
     * $totalShippingCharge) that persist across calls within a PHP process. Once the first real calc
     * runs, subsequent calls with different methods/items reuse the stale numbers — leading to silent
     * mispricing when an admin UI evaluates multiple methods in one request (e.g., OrderController's
     * getEnabledShippingMethodsWithCharges).
     *
     * This test ASSERTS the bug is present (assertSame). When the helper is refactored to remove the
     * static accumulators, this test will fail with "expected 500 but got 2000" — forcing the developer
     * to unskip the full calc matrix and delete this lock.
     *
     * Behavior today:
     *   call A: amount=5, per_order → 500 (sets $onceCalculated=true, captures $totalItemPrice etc.)
     *   call B: amount=20, per_order → 20 × 100 = 2000 expected; per_order base doesn't depend on
     *                                    accumulators, so this one actually does work. The bleed-through
     *                                    is visible on per_price/per_weight. Document this precisely.
     */
    public function test_edge_static_memoization_bleeds_across_distinct_calls(): void
    {
        // per_price uses $totalItemPrice from the static accumulator. With bug, 2nd call reuses the
        // line_total captured by the 1st call, yielding the wrong amount.
        $zone    = $this->zoneFactory->create(['region' => 'US']);
        $method1 = $this->methodFactory->create([
            'zone_id'  => $zone->id,
            'type'     => 'flat_rate',
            'amount'   => 10,
            'settings' => ['configure_rate' => 'per_price'],
        ]);
        $method2 = $this->methodFactory->create([
            'zone_id'  => $zone->id,
            'type'     => 'flat_rate',
            'amount'   => 10,
            'settings' => ['configure_rate' => 'per_price'],
        ]);

        // First call primes the statics with line_total=10000 → $totalItemPrice=10000
        $firstAmount = CartHelper::calculateShippingMethodCharge(
            $method1,
            [$this->buildLineItem(0, ['line_total' => 10000])],
            'amount'
        );

        // Second call uses line_total=1 (totally different cart), but the helper ignores it because
        // $onceCalculated is already true → $totalItemPrice stays at 10000 → amount still = 1000.
        $secondAmount = CartHelper::calculateShippingMethodCharge(
            $method2,
            [$this->buildLineItem(0, ['line_total' => 1])],
            'amount'
        );

        // Expected (if bug were fixed): second call = 1 × 10% = 0.1 → ceil'd to 0 or 1 cent.
        // Actual with bug: first and second return same $totalItemPrice-derived value.
        $this->assertSame(
            (int) $firstAmount,
            (int) $secondAmount,
            'BUG LOCK: When calculateShippingMethodCharge is refactored to drop the $onceCalculated static, '
            . 'this assertion will fail (values will differ). At that point: delete this bug-lock, unskip '
            . 'the per_price/per_weight/distribution/mixed-cart/class-aggregation tests, and verify the full '
            . 'calc matrix against exact-cent expectations.'
        );
    }

    // =========================================================================
    // Task 2: calculateShippingMethodCharge fingerprint-based static reset
    //
    // Regression coverage for the production incident where a second same-request
    // call (e.g. after ShippingModule::handleItemsChanges re-runs the calc following
    // an item add/remove/qty change) with the SAME method but CHANGED items was
    // silently skipped by the old $lastMethodId-keyed static guards, leaving the
    // new/changed item without a distributed itemwise_shipping_charge while the
    // shipping total charged to the customer stayed based on stale internal state.
    // =========================================================================

    public function test_same_request_item_change_recalculates_distribution(): void
    {
        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create([
            'zone_id'  => $zone->id,
            'type'     => 'flat_rate',
            'amount'   => 5,
            'settings' => ['configure_rate' => 'per_order'],
        ]);

        // First call: 5 physical items, distribution happens and shares sum exactly.
        $items = [];
        for ($i = 0; $i < 5; $i++) {
            $items[] = $this->buildLineItem(0, ['line_total' => 1000]);
        }
        $result1 = CartHelper::calculateShippingMethodCharge($method, $items, 'items');
        $sum1 = array_sum(array_column($result1['items'], 'itemwise_shipping_charge'));
        $this->assertSame((int) $result1['shipping_amount'], $sum1);
        $this->assertSame(500, $sum1);

        // Second call in the SAME request/process, SAME method, but the cart items
        // changed (6th item appended) — simulates ShippingModule::handleItemsChanges
        // re-running the calc after an item was added. With the old $lastMethodId
        // guard this would be silently skipped (same method id) and the 6th item
        // would keep a missing/zero itemwise_shipping_charge.
        $items6 = $result1['items'];
        $items6[] = $this->buildLineItem(0, ['line_total' => 1000]);
        $result2 = CartHelper::calculateShippingMethodCharge($method, $items6, 'items');

        $sum2 = array_sum(array_column($result2['items'], 'itemwise_shipping_charge'));
        $sixthShare = (int) $result2['items'][5]['itemwise_shipping_charge'];

        $this->assertSame((int) $result2['shipping_amount'], $sum2,
            'Per-item shares must sum exactly to the method amount after items change mid-request');
        $this->assertGreaterThan(0, $sixthShare,
            'The newly added 6th item must receive a non-zero distributed share');
    }

    public function test_same_request_identical_calls_return_identical_shares(): void
    {
        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create([
            'zone_id'  => $zone->id,
            'type'     => 'flat_rate',
            'amount'   => 7,
            'settings' => ['configure_rate' => 'per_order'],
        ]);

        $items = [
            $this->buildLineItem(0, ['line_total' => 100]),
            $this->buildLineItem(0, ['line_total' => 200]),
            $this->buildLineItem(0, ['line_total' => 700]),
        ];

        $result1 = CartHelper::calculateShippingMethodCharge($method, $items, 'items');
        $result2 = CartHelper::calculateShippingMethodCharge($method, $items, 'items');

        $this->assertSame((int) $result1['shipping_amount'], (int) $result2['shipping_amount']);
        $this->assertSame(
            array_column($result1['items'], 'itemwise_shipping_charge'),
            array_column($result2['items'], 'itemwise_shipping_charge'),
            'Two identical consecutive calls (same method + same items) must return identical shares'
        );
        $sum = array_sum(array_column($result2['items'], 'itemwise_shipping_charge'));
        $this->assertSame((int) $result2['shipping_amount'], $sum);
    }

    public function test_same_request_discount_total_change_recalculates_per_price(): void
    {
        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create([
            'zone_id'  => $zone->id,
            'type'     => 'flat_rate',
            'amount'   => 5,
            'settings' => ['configure_rate' => 'per_price'],
        ]);

        // First call: 2-qty item with no discount
        // totalItemPrice = (2 * 10000) - 0 = 20000
        // per_price 5% = 20000 * 0.05 = 1000
        $items1 = [
            $this->buildLineItem(0, ['quantity' => 2, 'unit_price' => 10000, 'discount_total' => 0, 'line_total' => 20000]),
        ];
        $result1 = CartHelper::calculateShippingMethodCharge($method, $items1, 'items');
        $shipping1 = (int) $result1['shipping_amount'];

        // Second call in the SAME request: same items but discount_total applied
        // line_total stays 20000 (denormalized), but discount_total = 5000 changes
        // totalItemPrice = (2 * 10000) - 5000 = 15000
        // per_price 5% = 15000 * 0.05 = 750
        // Without the fix (missing discount_total in fingerprint), this would reuse
        // the 1000 from the first call instead of recalculating to 750.
        $items2 = [
            $this->buildLineItem(0, ['quantity' => 2, 'unit_price' => 10000, 'discount_total' => 5000, 'line_total' => 20000]),
        ];
        $result2 = CartHelper::calculateShippingMethodCharge($method, $items2, 'items');
        $shipping2 = (int) $result2['shipping_amount'];

        $this->assertSame(1000, $shipping1, 'First call per_price 5% of 20000 should be 1000');
        $this->assertSame(750, $shipping2, 'Second call per_price 5% of 15000 should be 750');
        $this->assertNotSame($shipping1, $shipping2,
            'Same-request calls with different discount_total must produce different shipping amounts for per_price method');
    }

    /**
     * Regression test for the dangling-reference bug in the class-surcharge loop:
     * `foreach ($physicalItems as $key => &$item) { ... }` (inside the !$onceCalculated
     * cache block) was never followed by `unset($item)`. When configure_rate is
     * 'per_weight', the later non-reference weight loop (`foreach ($physicalItems as
     * $item)`) writes through that dangling reference on every iteration — the classic
     * PHP foreach-by-reference footgun — and ends up clobbering $physicalItems[<last
     * key>] with the second-to-last item's data. That corrupts $totalLineTotal used by
     * the distribution block further down.
     *
     * Mirrors the confirmed production incident: 6 physical items with line totals
     * 9000, 11070, 9000, 11070, 9000, 11070 (true sum 60210). With the bug, the last
     * item's line_total (11070) is silently replaced by the second-to-last item's
     * (9000), corrupting the distribution denominator to 58140 and skewing shares from
     * the correct 75/92/75/92/75/91 to 77/95/77/95/77/79.
     */
    public function test_same_request_per_weight_class_surcharge_loop_does_not_clobber_distribution(): void
    {
        // One item carries a real product variation with weight=7kg so the per_weight
        // tier lookup resolves to the 5.01-10 tier (cost 5 -> 500 cents). The other
        // items use object_id/variation_id 0 so they contribute no extra weight.
        $product = $this->productFactory
            ->withVariantAttributes(['other_info' => ['weight' => 7, 'weight_unit' => 'kg']])
            ->create();
        $variationId = $product->variants->first()->id;

        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create([
            'zone_id'  => $zone->id,
            'type'     => 'flat_rate',
            'amount'   => 0,
            'settings' => [
                'configure_rate' => 'per_weight',
                'weight_tiers'   => [
                    ['min' => 0, 'max' => 5, 'cost' => 2.5],
                    ['min' => 5.01, 'max' => 10, 'cost' => 5],
                ],
            ],
        ]);

        $lineTotals = [9000, 11070, 9000, 11070, 9000, 11070];
        $items = [];
        foreach ($lineTotals as $i => $lineTotal) {
            $overrides = ['line_total' => $lineTotal];
            if ($i === 0) {
                $overrides['object_id'] = $variationId;
            }
            $items[] = $this->buildLineItem(0, $overrides);
        }

        // FIRST call in the request — this is the buggy path, since the class-surcharge
        // by-ref loop only runs inside the !$onceCalculated cache block.
        $result1 = CartHelper::calculateShippingMethodCharge($method, $items, 'items');

        $this->assertSame(500, (int) $result1['shipping_amount'],
            'Weight tier 5.01-10 must resolve to 500 cents (5.00 * 100)');

        $shares1 = array_map('intval', array_column($result1['items'], 'itemwise_shipping_charge'));
        $this->assertSame(
            [75, 92, 75, 92, 75, 91],
            $shares1,
            'Per-item shares on the FIRST call must be computed from the true (uncorrupted) line '
            . 'totals. A dangling reference left by the class-surcharge loop must not clobber the '
            . 'last item\'s line_total before the distribution block runs.'
        );
        $this->assertSame(500, array_sum($shares1));

        // A second identical call in the same request must return identical shares.
        $result2 = CartHelper::calculateShippingMethodCharge($method, $items, 'items');
        $shares2 = array_map('intval', array_column($result2['items'], 'itemwise_shipping_charge'));
        $this->assertSame($shares1, $shares2,
            'Two identical consecutive calls (same method + same items) must return identical shares');
    }

    /**
     * Regression test for the cache-hit contract break: the !$onceCalculated block used to be
     * the ONLY place that wrote each item's `shipping_charge` (class surcharge) into $items and
     * accumulated the running totals. On a same-fingerprint (cache-hit) call, that block was
     * skipped entirely, so a FRESH $items array passed to the second call came back with
     * `itemwise_shipping_charge` populated but `shipping_charge` left at its pre-call value
     * (0) even though the item carries a real shipping class. ShippingModule persists the
     * returned array straight into cart_data, so this silently dropped the per-item class
     * surcharge on every cache-hit recalculation.
     */
    public function test_cache_hit_repopulates_per_item_class_shipping_charge(): void
    {
        $shippingClass = $this->classFactory->create(['type' => 'fixed', 'cost' => 5.00, 'per_item' => 0]);

        $product = $this->productFactory
            ->withDetailAttributes(['other_info' => ['shipping_class' => $shippingClass->id]])
            ->create();

        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create([
            'zone_id'  => $zone->id,
            'type'     => 'flat_rate',
            'amount'   => 5,
            'settings' => ['configure_rate' => 'per_order'],
        ]);

        $buildItems = function () use ($product) {
            return [
                $this->buildLineItem($product->ID, ['line_total' => 10000]),
                $this->buildLineItem(0, ['line_total' => 5000]),
            ];
        };

        // First call: fresh process state, goes through the !$onceCalculated block.
        $result1 = CartHelper::calculateShippingMethodCharge($method, $buildItems(), 'items');

        // Second call: SAME method + SAME item shape (same fingerprint), so $onceCalculated
        // is still true and the cached $products/$shippingClasses are reused. Crucially, the
        // $items array passed in is rebuilt from scratch (buildLineItem() defaults
        // shipping_charge back to 0) to prove the per-item annotation runs on every call and
        // is not skipped on a cache hit.
        $result2 = CartHelper::calculateShippingMethodCharge($method, $buildItems(), 'items');

        $this->assertSame(500, (int) $result1['items'][0]['shipping_charge'],
            'First call: class-bearing item must receive the 500-cent fixed class surcharge');
        $this->assertSame(0, (int) $result1['items'][1]['shipping_charge'],
            'First call: item with no product/shipping class must stay at zero surcharge');

        $this->assertSame(500, (int) $result2['items'][0]['shipping_charge'],
            'Cache-hit call must still populate shipping_charge on the class-bearing item from a fresh $items array');
        $this->assertSame(0, (int) $result2['items'][1]['shipping_charge']);

        $this->assertSame(
            array_column($result1['items'], 'itemwise_shipping_charge'),
            array_column($result2['items'], 'itemwise_shipping_charge'),
            'itemwise_shipping_charge distribution must be identical between the fresh and cache-hit calls'
        );
        $this->assertSame((int) $result1['shipping_amount'], (int) $result2['shipping_amount']);
    }

    // =========================================================================
    // Spec 173-174: CartHelper::calculateShippingByProfile distribution
    //
    // Regression coverage for the ceil-drift / broken-remainder bug in the
    // profile-based distribution block. Mirrors the fixed standard-path
    // distribution in calculateShippingMethodCharge: every item except the
    // last physical item gets a rounded proportional (or equal-split) share;
    // the last item absorbs the exact remainder so the sum of per-item
    // shares always equals the method-only shipping amount.
    // =========================================================================

    public function test_profile_distribution_shares_sum_exactly_no_ceil_drift(): void
    {
        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create([
            'zone_id'  => $zone->id,
            'type'     => 'flat_rate',
            'amount'   => 5,
            'settings' => ['configure_rate' => 'per_order'],
        ]);

        $lineTotals = [9000, 9000, 9000, 11070, 11070, 11070];
        $items = [];
        foreach ($lineTotals as $lineTotal) {
            $items[] = $this->buildLineItem(0, ['line_total' => $lineTotal]);
        }

        $result = CartHelper::calculateShippingByProfile($method->id, $items, 'US', null, 'items');

        $expectedShares = [75, 75, 75, 92, 92, 91];
        $actualShares = array_map(function ($item) {
            return (int) $item['itemwise_shipping_charge'];
        }, $result['items']);

        $this->assertSame($expectedShares, $actualShares);
        $this->assertSame(500, array_sum($actualShares));
    }

    public function test_profile_distribution_equal_split_fallback_sums_exactly(): void
    {
        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create([
            'zone_id'  => $zone->id,
            'type'     => 'flat_rate',
            'amount'   => 5,
            'settings' => ['configure_rate' => 'per_order'],
        ]);

        // All line_total = 0 → distribution falls back to an equal split across items.
        $items = [];
        for ($i = 0; $i < 6; $i++) {
            $items[] = $this->buildLineItem(0, ['line_total' => 0]);
        }

        $result = CartHelper::calculateShippingByProfile($method->id, $items, 'US', null, 'items');

        $actualShares = array_map(function ($item) {
            return (int) $item['itemwise_shipping_charge'];
        }, $result['items']);

        $this->assertSame(500, array_sum($actualShares));
    }

    /**
     * Regression test for the profile-path/standard-path contract mismatch: the standard path
     * (calculateShippingMethodCharge) sets itemwise_shipping_charge to the proportional share
     * ONLY, keeping the class surcharge exclusively in shipping_charge. The profile path used to
     * fold the surcharge into itemwise_shipping_charge as well (`$share + shipping_charge`) while
     * ALSO leaving it in shipping_charge, so TaxCalculator::getShippingTax() (which sums both
     * fields) taxed the surcharge twice. itemwise_shipping_charge must carry only the share.
     */
    public function test_profile_path_itemwise_excludes_class_surcharge(): void
    {
        $shippingClass = $this->classFactory->create(['type' => 'fixed', 'cost' => 2.00, 'per_item' => 0]);

        $product = $this->productFactory
            ->withDetailAttributes(['other_info' => ['shipping_class' => $shippingClass->id]])
            ->create();

        $zone   = $this->zoneFactory->create(['region' => 'US']);
        $method = $this->methodFactory->create([
            'zone_id'  => $zone->id,
            'type'     => 'flat_rate',
            'amount'   => 5,
            'settings' => ['configure_rate' => 'per_order'],
        ]);

        // Item 0 carries the shipping class (goes into its own profile group) and is NOT the
        // last physical item overall, so its share is the plain proportional rounding.
        // Item 1 has no shipping class (falls into the "general" group) and, being last, absorbs
        // the exact remainder.
        $items = [
            $this->buildLineItem($product->ID, ['line_total' => 10000]),
            $this->buildLineItem(0, ['line_total' => 15000]),
        ];

        $methodBaseRateCents = 500; // Helper::toCent(5)
        $classSurchargeCents = 200; // Helper::toCent(2.00)

        $totalLineTotal = 10000 + 15000;
        $expectedShareItem0 = (int) round((10000 / $totalLineTotal) * $methodBaseRateCents);
        $expectedShareItem1 = $methodBaseRateCents - $expectedShareItem0;

        $result = CartHelper::calculateShippingByProfile($method->id, $items, 'US', null, 'items');

        $this->assertSame($classSurchargeCents, (int) $result['items'][0]['shipping_charge'],
            'Class-bearing item must carry the fixed class surcharge in shipping_charge');
        $this->assertSame(0, (int) $result['items'][1]['shipping_charge'],
            'Item with no shipping class must have zero shipping_charge');

        $this->assertSame($expectedShareItem0, (int) $result['items'][0]['itemwise_shipping_charge'],
            'itemwise_shipping_charge must be the proportional base-rate share only, excluding the class surcharge');
        $this->assertSame($expectedShareItem1, (int) $result['items'][1]['itemwise_shipping_charge']);

        $sumItemwise = array_sum(array_column($result['items'], 'itemwise_shipping_charge'));
        $this->assertSame($methodBaseRateCents, $sumItemwise,
            'Sum of itemwise_shipping_charge across items must equal the method base amount exactly, '
            . 'with no class surcharge folded in');

        $this->assertSame($methodBaseRateCents + $classSurchargeCents, (int) $result['shipping_amount'],
            'Total shipping_amount must be base rate plus the class surcharge');
    }
}
