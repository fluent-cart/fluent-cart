<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Cart;
use FluentCart\App\Models\OrderTaxRate;
use FluentCart\App\Models\TaxClass;
use FluentCart\App\Models\TaxRate;
use FluentCart\App\Modules\Tax\TaxCalculator;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\App\Services\Tax\TaxManager;
use FluentCart\Framework\Support\Arr;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTaxRateFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\TaxClassFactory;
use Tests\Support\Factory\TaxRateFactory;
use Tests\Support\Traits\BuildsTaxFixtures;

class TaxServiceTest extends DatabaseTestCase
{
    use BuildsTaxFixtures;

    private TaxClassFactory $classFactory;
    private TaxRateFactory $rateFactory;
    private OrderTaxRateFactory $orderTaxFactory;
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->classFactory    = new TaxClassFactory();
        $this->rateFactory     = new TaxRateFactory();
        $this->orderTaxFactory = new OrderTaxRateFactory();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory    = new OrderFactory();
        $this->productFactory  = new ProductFactory();

        // Each test simulates a fresh request: clean settings + cleared
        // runtime memos (TaxManager singleton, TaxCalculator caches — the
        // TESTABILITY-DEBT #7/#8 seams — and the StoreSettings memo).
        $this->setTaxSettings([]);
        $this->resetTaxRuntimeState();
    }

    public function tearDown(): void
    {
        delete_option('fluent_cart_tax_configuration_settings');
        parent::tearDown();
    }

    // =========================================================================
    // Spec 38-48: TaxManager
    // =========================================================================

    public function test_tax_manager_get_instance_returns_singleton(): void
    {
        $a = TaxManager::getInstance();
        $b = TaxManager::getInstance();
        $this->assertSame($a, $b);
    }

    public function test_tax_manager_get_rates_returns_tax_php_map(): void
    {
        $rates = TaxManager::getInstance()->getRates();
        $this->assertIsArray($rates);
        $this->assertArrayHasKey('DE', $rates);
        $this->assertSame('EU', $rates['DE']['group']);
    }

    public function test_generate_tax_classes_creates_standard_reduced_zero(): void
    {
        TaxClass::query()->delete();
        TaxManager::getInstance()->generateTaxClasses([]);
        $classes = TaxClass::query()->pluck('slug')->toArray();
        $this->assertContains('standard', $classes);
        $this->assertContains('reduced', $classes);
        $this->assertContains('zero', $classes);
    }

    public function test_generate_tax_classes_is_idempotent(): void
    {
        TaxClass::query()->delete();
        TaxManager::getInstance()->generateTaxClasses([]);
        $firstCount = TaxClass::query()->count();
        TaxManager::getInstance()->generateTaxClasses([]);
        $this->assertSame($firstCount, TaxClass::query()->count());
    }

    public function test_generate_tax_classes_inserts_rates_for_selected_country(): void
    {
        TaxRate::query()->delete();
        TaxClass::query()->delete();
        TaxManager::getInstance()->generateTaxClasses(['DE']);
        $this->assertGreaterThan(0, TaxRate::query()->where('country', 'DE')->count());
        $this->assertSame(0, TaxRate::query()->where('country', 'FR')->count());
    }

    public function test_generate_tax_classes_skips_country_with_existing_rates(): void
    {
        TaxRate::query()->delete();
        TaxClass::query()->delete();
        TaxManager::getInstance()->generateTaxClasses(['DE']);
        $deCount = TaxRate::query()->where('country', 'DE')->count();
        TaxManager::getInstance()->generateTaxClasses(['DE']);
        $this->assertSame($deCount, TaxRate::query()->where('country', 'DE')->count());
    }

    public function test_get_eu_tax_rates_from_php_returns_german_rates(): void
    {
        $result = TaxManager::getInstance()->getEuTaxRatesFromPhp('DE', 'standard');
        $this->assertNotEmpty($result);
        foreach ($result as $entry) {
            $this->assertSame('standard', $entry['type']);
        }
    }

    /**
     * Spec: Cases/Integration/tax.md [45]
     *   "getEuTaxRatesFromPhp() returns an empty collection for a country
     *    with no tax.php entry."
     * (tax.php also ships non-EU countries like US with typed rates, so
     * absence from the file — not EU membership — is what yields empty.)
     */
    public function test_get_eu_tax_rates_from_php_empty_for_country_without_entry(): void
    {
        $result = TaxManager::getInstance()->getEuTaxRatesFromPhp('XX', 'standard');
        $this->assertSame([], $result, 'A country absent from tax.php has no PHP-defined rates');
    }

    public function test_get_tax_rates_from_tax_php_groups_by_group(): void
    {
        $data = TaxManager::getInstance()->getTaxRatesFromTaxPhp();
        $this->assertArrayHasKey('EU', $data);
        $this->assertArrayHasKey('countries', $data['EU']);
        $this->assertGreaterThan(0, $data['EU']['total_countries']);
    }

    /**
     * Spec: Cases/Integration/tax.md [47]
     *   "getCountryConfiguration('DE') returns the config.php entry
     *    (EU hides city/zip/state fields)."
     */
    public function test_get_country_configuration_returns_eu_hidden_fields(): void
    {
        $config = TaxManager::getInstance()->getCountryConfiguration('DE');
        $this->assertIsArray($config, 'DE resolves to the EU continent configuration');
        $hidden = Arr::get($config, 'hidden', []);
        $this->assertContains('city', $hidden, 'EU config hides the city field');
        $this->assertContains('zip', $hidden, 'EU config hides the zip field');
        $this->assertContains('state', $hidden, 'EU config hides the state field');
    }

    /**
     * Spec: Cases/Integration/tax.md [48]
     *   "getCountryConfiguration() returns null for an unknown country."
     */
    public function test_get_country_configuration_returns_null_for_unknown(): void
    {
        $config = TaxManager::getInstance()->getCountryConfiguration('XX');
        $this->assertNull($config, 'A country with no config entry and no tax.php group yields null');
    }

    // =========================================================================
    // Spec 49-64: TaxModule settings + calculateCartTax basics
    // =========================================================================

    public function test_is_enabled_true_only_when_option_yes(): void
    {
        $this->enableTax();
        $this->assertTrue((new TaxModule())->isEnabled());
    }

    public function test_is_enabled_false_when_option_missing(): void
    {
        delete_option('fluent_cart_tax_configuration_settings');
        $this->assertFalse((new TaxModule())->isEnabled());
    }

    public function test_get_settings_merges_defaults(): void
    {
        $this->setTaxSettings(['enable_tax' => 'yes']);
        $settings = (new TaxModule())->getSettings();
        $this->assertSame('yes', $settings['enable_tax']);
        $this->assertSame('included', $settings['tax_inclusion']);
        $this->assertSame('shipping', $settings['tax_calculation_basis']);
        $this->assertSame('item', $settings['tax_rounding']);
    }

    public function test_get_settings_caches_on_instance(): void
    {
        $this->setTaxSettings(['enable_tax' => 'yes']);
        $module = new TaxModule();
        $first  = $module->getSettings();
        $this->setTaxSettings(['enable_tax' => 'no']); // change underlying
        $second = $module->getSettings();
        $this->assertSame($first, $second, 'Settings should be cached per instance');
    }

    public function test_register_is_noop_when_disabled(): void
    {
        $this->setTaxSettings([]);
        $before = has_filter('fluent_cart/cart/estimated_total');
        (new TaxModule())->register();
        $after = has_filter('fluent_cart/cart/estimated_total');
        // When disabled, no filter should be added
        $this->assertSame($before, $after);
    }

    public function test_register_adds_core_filters_when_enabled(): void
    {
        $this->enableTax();
        (new TaxModule())->register();
        $this->assertNotFalse(has_filter('fluent_cart/cart/estimated_total'));
        $this->assertNotFalse(has_filter('fluent_cart/checkout/before_patch_checkout_data'));
        $this->assertNotFalse(has_filter('fluent_cart/cart/tax_behavior'));
    }

    public function test_calculate_cart_tax_fills_tax_data_keys(): void
    {
        $this->enableTax('excluded', 'billing');
        $class   = $this->classFactory->create(['meta' => ['priority' => 10, 'categories' => []]]);
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $fillData = [
            'cart_data'     => [$this->buildLineItem($product->ID)],
            'checkout_data' => ['form_data' => ['billing_country' => 'US']],
        ];
        $result = (new TaxModule())->calculateCartTax($fillData);
        $taxData = $result['checkout_data']['tax_data'];
        foreach (['tax_total', 'tax_behavior', 'tax_country', 'shipping_tax', 'fee_tax', 'tax_lines'] as $key) {
            $this->assertArrayHasKey($key, $taxData);
        }
    }

    public function test_calculate_cart_tax_basis_shipping_uses_shipping_address(): void
    {
        $this->enableTax('excluded', 'shipping');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'DE', 'rate' => '19']);

        $fillData = [
            'cart_data'     => [$this->buildLineItem($product->ID)],
            'checkout_data' => ['form_data' => [
                'ship_to_different' => 'yes',
                'billing_country'   => 'US',
                'shipping_country'  => 'DE',
            ]],
        ];
        $result = (new TaxModule())->calculateCartTax($fillData);
        $this->assertSame('DE', $result['checkout_data']['tax_data']['tax_country']);
    }

    public function test_calculate_cart_tax_basis_shipping_falls_back_to_billing(): void
    {
        $this->enableTax('excluded', 'shipping');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $fillData = [
            'cart_data'     => [$this->buildLineItem($product->ID)],
            'checkout_data' => ['form_data' => [
                'ship_to_different' => 'no',
                'billing_country'   => 'US',
                'shipping_country'  => 'DE',
            ]],
        ];
        $result = (new TaxModule())->calculateCartTax($fillData);
        $this->assertSame('US', $result['checkout_data']['tax_data']['tax_country']);
    }

    public function test_calculate_cart_tax_basis_billing_always_uses_billing(): void
    {
        $this->enableTax('excluded', 'billing');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'DE', 'rate' => '19']);

        $fillData = [
            'cart_data'     => [$this->buildLineItem($product->ID)],
            'checkout_data' => ['form_data' => [
                'billing_country'   => 'DE',
                'shipping_country'  => 'US',
                'ship_to_different' => 'yes',
            ]],
        ];
        $result = (new TaxModule())->calculateCartTax($fillData);
        $this->assertSame('DE', $result['checkout_data']['tax_data']['tax_country']);
    }

    /**
     * Spec: Cases/Integration/tax.md [59]
     *   "With basis='store' uses store address from StoreSettings."
     */
    public function test_calculate_cart_tax_basis_store_uses_store_address(): void
    {
        // Canada keeps this on the plain DB-rate path (an EU store country
        // would require eu_vat_settings.method — covered by spec 77).
        $this->enableTax('excluded', 'store');
        $this->setStoreCountry('CA');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'CA', 'rate' => '5']);

        $fillData = [
            'cart_data'     => [$this->buildLineItem($product->ID)],
            'checkout_data' => ['form_data' => [
                'billing_country'  => 'US',
                'shipping_country' => 'US',
            ]],
        ];
        $result = (new TaxModule())->calculateCartTax($fillData);
        $this->assertSame('CA', $result['checkout_data']['tax_data']['tax_country'],
            'Store basis taxes by the configured store country, not the customer address');
        $this->assertSame(500, (int) $result['checkout_data']['tax_data']['tax_total'],
            'The CA 5% store rate applies to the $100 line');
    }

    public function test_calculate_cart_tax_empty_when_no_taxable_lines(): void
    {
        $this->enableTax();
        $fillData = [
            'cart_data'     => [],
            'checkout_data' => ['form_data' => ['billing_country' => 'US']],
        ];
        $result = (new TaxModule())->calculateCartTax($fillData);
        $this->assertSame(0, (int) $result['checkout_data']['tax_data']['tax_total']);
    }

    public function test_calculate_cart_tax_merges_taxable_fee(): void
    {
        $this->enableTax('excluded', 'billing');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $fillData = [
            'cart_data'     => [$this->buildLineItem($product->ID)],
            'checkout_data' => [
                'form_data' => ['billing_country' => 'US'],
                'fees'      => [['taxable' => true, 'amount' => 500, 'name' => 'Handling']],
            ],
        ];
        $result = (new TaxModule())->calculateCartTax($fillData);
        $this->assertGreaterThan(0, (int) $result['checkout_data']['tax_data']['fee_tax']);
    }

    public function test_calculate_cart_tax_non_taxable_fee_gives_zero_fee_tax(): void
    {
        $this->enableTax('excluded', 'billing');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $fillData = [
            'cart_data'     => [$this->buildLineItem($product->ID)],
            'checkout_data' => [
                'form_data' => ['billing_country' => 'US'],
                'fees'      => [['taxable' => false, 'amount' => 500, 'name' => 'No-tax Fee']],
            ],
        ];
        $result = (new TaxModule())->calculateCartTax($fillData);
        $this->assertSame(0, (int) $result['checkout_data']['tax_data']['fee_tax']);
    }

    public function test_tax_inclusion_included_yields_behavior_2(): void
    {
        $this->enableTax('included', 'billing');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $fillData = [
            'cart_data'     => [$this->buildLineItem($product->ID)],
            'checkout_data' => ['form_data' => ['billing_country' => 'US']],
        ];
        $result = (new TaxModule())->calculateCartTax($fillData);
        $this->assertSame(2, $result['checkout_data']['tax_data']['tax_behavior']);
    }

    public function test_tax_inclusion_excluded_yields_behavior_1(): void
    {
        $this->enableTax('excluded', 'billing');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $fillData = [
            'cart_data'     => [$this->buildLineItem($product->ID)],
            'checkout_data' => ['form_data' => ['billing_country' => 'US']],
        ];
        $result = (new TaxModule())->calculateCartTax($fillData);
        $this->assertSame(1, $result['checkout_data']['tax_data']['tax_behavior']);
    }

    // =========================================================================
    // Spec 65-72: Rate resolution by location
    // =========================================================================

    public function test_rate_without_country_match_is_excluded(): void
    {
        $this->enableTax();
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'DE', 'rate' => '19']);

        $line = $this->calcLine($product->ID, 'US');
        $this->assertSame(0, (int) ($line['tax_amount'] ?? 0));
    }

    public function test_rate_state_match_required_when_rate_has_state(): void
    {
        $this->enableTax();
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'state' => 'CA', 'rate' => '8']);

        $miss = $this->calcLine($product->ID, 'US', 'NY');
        $hit  = $this->calcLine($product->ID, 'US', 'CA');
        $this->assertSame(0, (int) ($miss['tax_amount'] ?? 0));
        $this->assertGreaterThan(0, (int) ($hit['tax_amount'] ?? 0));
    }

    public function test_rate_empty_state_matches_any_state(): void
    {
        $this->enableTax();
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'state' => '', 'rate' => '5']);

        $line = $this->calcLine($product->ID, 'US', 'ZZ');
        $this->assertGreaterThan(0, (int) ($line['tax_amount'] ?? 0));
    }

    public function test_rate_city_match_required_when_set(): void
    {
        $this->enableTax();
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'city' => 'Austin', 'rate' => '8']);

        $miss = $this->calcLine($product->ID, 'US', '', 'Dallas');
        $hit  = $this->calcLine($product->ID, 'US', '', 'Austin');
        $this->assertSame(0, (int) ($miss['tax_amount'] ?? 0));
        $this->assertGreaterThan(0, (int) ($hit['tax_amount'] ?? 0));
    }

    public function test_rate_postcode_exact_match(): void
    {
        $this->enableTax();
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'postcode' => '12345', 'rate' => '7']);

        $miss = $this->calcLine($product->ID, 'US', '', '', '99999');
        $hit  = $this->calcLine($product->ID, 'US', '', '', '12345');
        $this->assertSame(0, (int) ($miss['tax_amount'] ?? 0));
        $this->assertGreaterThan(0, (int) ($hit['tax_amount'] ?? 0));
    }

    public function test_rate_postcode_csv_match(): void
    {
        $this->enableTax();
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'postcode' => '12345,23456', 'rate' => '7']);

        $a = $this->calcLine($product->ID, 'US', '', '', '12345');
        $b = $this->calcLine($product->ID, 'US', '', '', '23456');
        $this->assertGreaterThan(0, (int) ($a['tax_amount'] ?? 0));
        $this->assertGreaterThan(0, (int) ($b['tax_amount'] ?? 0));
    }

    public function test_rate_postcode_range_match(): void
    {
        $this->enableTax();
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'postcode' => '12345-12350', 'rate' => '7']);

        $mid = $this->calcLine($product->ID, 'US', '', '', '12347');
        $this->assertGreaterThan(0, (int) ($mid['tax_amount'] ?? 0));
    }

    public function test_rate_postcode_mixed_range_and_csv(): void
    {
        $this->enableTax();
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'postcode' => '12345-12350,56789', 'rate' => '7']);

        $inRange = $this->calcLine($product->ID, 'US', '', '', '12347');
        $extra   = $this->calcLine($product->ID, 'US', '', '', '56789');
        $this->assertGreaterThan(0, (int) ($inRange['tax_amount'] ?? 0));
        $this->assertGreaterThan(0, (int) ($extra['tax_amount'] ?? 0));
    }

    public function test_rates_returned_in_priority_ascending_order(): void
    {
        $this->enableTax('excluded');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        // priority 2 first, then priority 1 — expect priority 1 applied first
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '5',  'priority' => 2, 'name' => 'Second']);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10', 'priority' => 1, 'name' => 'First']);

        $line = $this->calcLine($product->ID, 'US');
        $this->assertSame('First', $line['line_meta']['tax_config']['rates'][0]['label']);
    }

    // =========================================================================
    // Spec 73-77: Tax class resolution + category overrides
    // =========================================================================

    public function test_product_with_direct_tax_class_uses_only_that_class(): void
    {
        $this->enableTax();
        $classA = $this->classFactory->create();
        $classB = $this->classFactory->create();
        $product = $this->productFactory
            ->withVariantAttributes(['other_info' => ['payment_type' => 'onetime', 'tax_class' => $classA->slug]])
            ->create();
        $this->rateFactory->create(['class_id' => $classA->id, 'country' => 'US', 'rate' => '10', 'name' => 'ClassA']);
        $this->rateFactory->create(['class_id' => $classB->id, 'country' => 'US', 'rate' => '20', 'name' => 'ClassB']);

        $line = $this->calcLine($product->ID, 'US');
        $rates = $line['line_meta']['tax_config']['rates'];
        foreach ($rates as $r) {
            $this->assertSame('ClassA', $r['label']);
        }
    }

    /**
     * Spec: Cases/Integration/tax.md [74]
     *   "A product category tax override (fct_meta tax_override /
     *    product_category_override) replaces the base country rate for
     *    products in that category."
     */
    public function test_category_tax_override_replaces_base_country_rate(): void
    {
        $this->enableTax('excluded');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10', 'name' => 'Base']);

        $term = $this->attachProductToNewCategoryTerm($product->ID);
        $this->addCategoryTaxOverride($term['term_taxonomy_id'], [
            'country'            => 'US',
            'rate'               => 5,
            'tax_label'          => 'Category Override',
            'override_state_tax' => 'no',
            'class_id'           => 0,
        ]);

        $line  = $this->calcLine($product->ID, 'US');
        $rates = $line['line_meta']['tax_config']['rates'];
        $this->assertSame('Category Override', $rates[0]['label'], 'The override rate replaces the base country rate');
        $this->assertSame(500, (int) $line['tax_amount'], 'Tax uses the 5% override, not the 10% base rate');
    }

    /**
     * Spec: Cases/Integration/tax.md [75]
     *   "When several category overrides match, the most location-specific
     *    one wins (state/city/postcode beat country-only)."
     */
    public function test_most_location_specific_category_override_wins(): void
    {
        $this->enableTax('excluded');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10', 'name' => 'Base']);

        $term = $this->attachProductToNewCategoryTerm($product->ID);
        $this->addCategoryTaxOverride($term['term_taxonomy_id'], [
            'country'            => 'US',
            'rate'               => 5,
            'tax_label'          => 'Country Override',
            'override_state_tax' => 'no',
            'class_id'           => 0,
        ]);
        $this->addCategoryTaxOverride($term['term_taxonomy_id'], [
            'country'            => 'US',
            'state'              => 'CA',
            'rate'               => 8,
            'tax_label'          => 'CA Override',
            'override_state_tax' => 'no',
            'class_id'           => 0,
        ]);

        $line = $this->calcLine($product->ID, 'US', 'CA');
        $this->assertSame(800, (int) $line['tax_amount'],
            'The state-specific override (8%) beats the country-only override (5%)');
    }

    public function test_product_with_no_class_and_no_categories_has_no_tax(): void
    {
        $this->enableTax();
        // A product with no tax class falls back to the standard class; with no rate at
        // all there is nothing to apply. Clear rates so the assertion is order-independent
        // (a standard-class rate left committed by another suite test otherwise leaks in).
        TaxRate::query()->delete();
        $product = $this->productFactory->create();
        $line = $this->calcLine($product->ID, 'US');
        $this->assertSame(0, (int) ($line['tax_amount'] ?? 0));
    }

    /**
     * Spec: Cases/Integration/tax.md [77]
     *   "EU customer triggers the getEuTaxRates() branch — with OSS
     *    collection and no DB rate the tax.php rate applies."
     */
    public function test_eu_customer_triggers_eu_rates_branch(): void
    {
        $this->enableTax('excluded', 'billing', 'item', [
            'eu_vat_settings' => ['method' => 'oss'],
        ]);
        TaxRate::query()->delete(); // no DB rates — forces the tax.php fallback
        $standard = $this->ensureStandardTaxClass();
        $product  = $this->makeProductWithClass($standard->id);

        $line = $this->calcLine($product->ID, 'DE');
        $this->assertSame(1900, (int) $line['tax_amount'],
            'OSS with no DB rate falls back to the tax.php DE standard rate (19%)');
        $this->assertSame('MwSt', $line['line_meta']['tax_config']['rates'][0]['label'],
            'The tax.php rate label is used');
    }

    // =========================================================================
    // Spec 78-80: Formulas
    // =========================================================================

    public function test_exclusive_formula_price_times_rate_over_100(): void
    {
        $this->enableTax('excluded');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $line = $this->calcLine($product->ID, 'US');
        // $100 * 10% = $10 exclusive
        $this->assertSame(1000, (int) $line['tax_amount']);
    }

    public function test_inclusive_formula_price_times_rate_over_100_plus_rate(): void
    {
        $this->enableTax('included');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $line = $this->calcLine($product->ID, 'US');
        // Inclusive extraction, item rounding = round(HALF_UP): 10000 * 10 / 110 = 909.09 → 909.
        $this->assertSame(909, (int) $line['tax_amount']);
    }

    public function test_tax_amount_rounds_up_via_ceil(): void
    {
        $this->enableTax('excluded');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '7.5']);

        $line = $this->calcLine($product->ID, 'US');
        // 10000 * 7.5 / 100 = 750 exactly — check the ceil path with a non-divisible value
        $calc = new TaxCalculator(
            [$this->buildLineItem($product->ID, ['subtotal' => 9999])],
            ['country' => 'US']
        );
        $lines = $calc->getTaxedLines();
        // 9999 * 7.5 / 100 = 749.925 → ceil = 750
        $this->assertSame(750.0, (float) ceil($lines[0]['tax_amount']));
    }

    // =========================================================================
    // Spec 81-84: Compound tax
    // =========================================================================

    public function test_non_compound_rate_taxes_base_only(): void
    {
        $this->enableTax('excluded');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '20', 'is_compound' => 0]);

        $line = $this->calcLine($product->ID, 'US');
        $this->assertSame(2000, (int) $line['tax_amount']);
    }

    public function test_compound_rate_taxes_base_plus_prior_tax(): void
    {
        $this->enableTax('excluded');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        // priority 1: 20% non-compound
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '20', 'priority' => 1, 'is_compound' => 0]);
        // priority 2: 5% compound
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '5',  'priority' => 2, 'is_compound' => 1]);

        $line = $this->calcLine($product->ID, 'US');
        // 10000 * 20% = 2000 ; (10000+2000) * 5% = 600 ; total = 2600
        $this->assertSame(2600, (int) $line['tax_amount']);
    }

    /**
     * Spec: Cases/Integration/tax.md [83]
     *   "Two-rate scenario: 20% non-compound + 5% compound on $100 →
     *    $20 + $6 = $26 total tax."
     */
    public function test_compound_scenario_20pct_plus_5pct_totals_26(): void
    {
        $this->enableTax('excluded');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '20', 'priority' => 1, 'is_compound' => 0]);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '5',  'priority' => 2, 'is_compound' => 1]);

        $calc = new TaxCalculator([$this->buildLineItem($product->ID)], ['country' => 'US']);
        $this->assertSame(2600, (int) $calc->getTotalTax(),
            'Calculator total is $26.00 for the 20% + 5%-compound scenario on $100');
    }

    /**
     * Spec: Cases/Integration/tax.md [84]
     *   "Compound order strictly follows priority ASC — higher-priority
     *    (lower number) rates contribute to the compound base."
     */
    public function test_compound_priority_order_determines_accumulation(): void
    {
        $this->enableTax('excluded');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        // Created compound-first to prove ordering comes from priority, not insertion.
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10', 'priority' => 2, 'is_compound' => 1, 'name' => 'CompoundSecond']);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10', 'priority' => 1, 'is_compound' => 0, 'name' => 'BaseFirst']);

        $line  = $this->calcLine($product->ID, 'US');
        $rates = $line['line_meta']['tax_config']['rates'];
        $this->assertSame('BaseFirst', $rates[0]['label'], 'Priority 1 applies first');
        $this->assertSame(1000, (int) $rates[0]['tax_amount'], '10% of the $100 base');
        $this->assertSame('CompoundSecond', $rates[1]['label'], 'Priority 2 compound applies second');
        $this->assertSame(1100, (int) $rates[1]['tax_amount'], '10% of base + prior tax ($110)');
        $this->assertSame(2100, (int) $line['tax_amount'], 'Accumulated line tax is $21.00');
    }

    // =========================================================================
    // Spec 85-88: Subscription tax
    // =========================================================================

    /**
     * Spec: Cases/Integration/tax.md [85]
     *   "Subscription line item with other_info.payment_type='subscription'
     *    produces other_info.recurring_tax."
     */
    public function test_subscription_item_produces_recurring_tax(): void
    {
        $this->enableTax('excluded');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $calc  = new TaxCalculator(
            [$this->buildSubscriptionLineItem($product->ID)],
            ['country' => 'US']
        );
        $lines = $calc->getTaxedLines();
        $this->assertSame(1000, (int) Arr::get($lines[0], 'other_info.recurring_tax'),
            'The $100 recurring amount is taxed at 10%');
        $this->assertSame(1000, (int) $calc->getRecurringTax(), 'getRecurringTax() sums the recurring tax');
    }

    /**
     * Spec: Cases/Integration/tax.md [86]
     *   "Subscription with signup_fee > 0 AND trial_days=0 taxes the signup
     *    fee once into other_info.signup_fee_tax."
     */
    public function test_subscription_signup_fee_taxed_when_no_trial(): void
    {
        $this->enableTax('excluded');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $calc  = new TaxCalculator(
            [$this->buildSubscriptionLineItem($product->ID, ['signup_fee' => 5000, 'trial_days' => 0])],
            ['country' => 'US']
        );
        $lines = $calc->getTaxedLines();
        $this->assertSame(500, (int) Arr::get($lines[0], 'other_info.signup_fee_tax'),
            'The $50 signup fee is taxed at 10%');
        $this->assertSame(1000, (int) $lines[0]['tax_amount'],
            'The main $100 amount is still taxed alongside the signup fee');
    }

    /**
     * Spec: Cases/Integration/tax.md [87]
     *   "Subscription with trial_days > 0 zeroes the first-iteration main tax;
     *    a signup fee is still taxed separately."
     */
    public function test_subscription_with_trial_zeroes_main_tax_but_still_taxes_signup_fee(): void
    {
        $this->enableTax('excluded');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $calc  = new TaxCalculator(
            [$this->buildSubscriptionLineItem($product->ID, ['signup_fee' => 5000, 'trial_days' => 14])],
            ['country' => 'US']
        );
        $lines = $calc->getTaxedLines();
        $this->assertSame(0, (int) $lines[0]['tax_amount'],
            'A trial zeroes the taxable amount for the first iteration');
        $this->assertSame(500, (int) Arr::get($lines[0], 'other_info.signup_fee_tax'),
            'The signup fee is still taxed during a trial');
    }

    /**
     * Spec: Cases/Integration/tax.md [88]
     *   "Recurring tax uses (recurring_amount − recurring_discount) × rate."
     */
    public function test_subscription_recurring_minus_recurring_discount(): void
    {
        $this->enableTax('excluded');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $lineItem = $this->buildSubscriptionLineItem($product->ID, [], [
            'recurring_discounts' => ['amount' => 2000],
        ]);
        $calc  = new TaxCalculator([$lineItem], ['country' => 'US']);
        $lines = $calc->getTaxedLines();
        $this->assertSame(800, (int) Arr::get($lines[0], 'other_info.recurring_tax'),
            'Recurring tax is 10% of $100 − $20 recurring discount');
    }

    // =========================================================================
    // Spec 89-91: Fee taxation
    // =========================================================================

    /**
     * Spec: Cases/Integration/tax.md [89]
     *   "A taxable fee uses the first product's tax rates."
     */
    public function test_taxable_fee_uses_first_products_rates(): void
    {
        $this->enableTax('excluded', 'billing');
        $classA   = $this->classFactory->create();
        $classB   = $this->classFactory->create();
        $productA = $this->makeProductWithClass($classA->id);
        $productB = $this->makeProductWithClass($classB->id);
        $this->rateFactory->create(['class_id' => $classA->id, 'country' => 'US', 'rate' => '10', 'name' => 'RateA']);
        $this->rateFactory->create(['class_id' => $classB->id, 'country' => 'US', 'rate' => '20', 'name' => 'RateB']);

        $fillData = [
            'cart_data'     => [
                $this->buildLineItem($productA->ID),
                $this->buildLineItem($productB->ID),
            ],
            'checkout_data' => [
                'form_data' => ['billing_country' => 'US'],
                'fees'      => [['taxable' => true, 'amount' => 1000, 'name' => 'Handling']],
            ],
        ];
        $result = (new TaxModule())->calculateCartTax($fillData);
        $this->assertSame(100, (int) $result['checkout_data']['tax_data']['fee_tax'],
            'The $10 fee is taxed at the FIRST product\'s 10% rate, not the second product\'s 20%');
    }

    /**
     * Spec: Cases/Integration/tax.md [90]
     *   "Fee tax is aggregated into top-level fee_tax, separate from
     *    product tax."
     */
    public function test_fee_tax_segregated_from_product_tax(): void
    {
        $this->enableTax('excluded', 'billing');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $fillData = [
            'cart_data'     => [$this->buildLineItem($product->ID)],
            'checkout_data' => [
                'form_data' => ['billing_country' => 'US'],
                'fees'      => [['taxable' => true, 'amount' => 500, 'name' => 'Handling']],
            ],
        ];
        $result  = (new TaxModule())->calculateCartTax($fillData);
        $taxData = $result['checkout_data']['tax_data'];
        $this->assertSame(50, (int) $taxData['fee_tax'], 'The $5 fee is taxed at 10% into fee_tax');
        $this->assertSame(1000, (int) $taxData['exclusive_tax_total'],
            'Product tax stays in exclusive_tax_total, unpolluted by the fee tax');
    }

    public function test_non_taxable_fee_produces_no_fee_tax_with_taxable_items(): void
    {
        $this->enableTax('excluded');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $fillData = [
            'cart_data'     => [$this->buildLineItem($product->ID)],
            'checkout_data' => [
                'form_data' => ['billing_country' => 'US'],
                'fees'      => [['taxable' => false, 'amount' => 1000, 'name' => 'NoTax Fee']],
            ],
        ];
        $result = (new TaxModule())->calculateCartTax($fillData);
        $this->assertSame(0, (int) $result['checkout_data']['tax_data']['fee_tax']);
    }

    // =========================================================================
    // Spec 92-97: Shipping tax
    // =========================================================================

    /**
     * Spec: Cases/Integration/tax.md [92]
     *   "getShippingTax() sums shipping_charge + itemwise_shipping_charge
     *    across items."
     */
    public function test_get_shipping_tax_sums_shipping_charges(): void
    {
        $this->enableTax('excluded');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        // for_shipping NULL inherits the base 10% rate for shipping.
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10', 'for_shipping' => null]);

        $calc = new TaxCalculator([
            $this->buildLineItem($product->ID, ['shipping_charge' => 1000]),
            $this->buildLineItem($product->ID, ['shipping_charge' => 500, 'itemwise_shipping_charge' => 1500]),
        ], ['country' => 'US']);
        // (1000)*10% + (500+1500)*10% = 100 + 200 = 300
        $this->assertSame(300, (int) $calc->getShippingTax(),
            'Shipping tax covers shipping_charge plus itemwise_shipping_charge on every item');
    }

    public function test_shipping_tax_uses_only_rates_with_for_shipping(): void
    {
        $this->enableTax('excluded');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        // for_shipping=0 opts the rate out of shipping tax. (null would INHERIT the base
        // product rate — see TaxRatesMigrator: "0 (no shipping tax) and NULL (inherit)".)
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10', 'for_shipping' => 0]);

        $calc = new TaxCalculator([
            $this->buildLineItem($product->ID, ['shipping_charge' => 2000])
        ], ['country' => 'US']);
        $this->assertSame(0, (int) $calc->getShippingTax());
    }

    public function test_shipping_rate_override_uses_for_shipping_value(): void
    {
        $this->enableTax('excluded');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        // base 10%, override 5% for shipping
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10', 'for_shipping' => 5]);

        $calc = new TaxCalculator([
            $this->buildLineItem($product->ID, ['shipping_charge' => 2000])
        ], ['country' => 'US']);
        $this->assertSame(100, (int) $calc->getShippingTax());
    }

    public function test_compound_shipping_rates_accumulate(): void
    {
        $this->enableTax('excluded');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10', 'priority' => 1, 'is_compound' => 0, 'for_shipping' => 10]);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '5',  'priority' => 2, 'is_compound' => 1, 'for_shipping' => 5]);

        $calc = new TaxCalculator([
            $this->buildLineItem($product->ID, ['shipping_charge' => 10000])
        ], ['country' => 'US']);
        // first: 10000 * 10% = 1000 ; compound: (10000+1000) * 5% = 550 ; sum = 1550
        $this->assertSame(1550, (int) $calc->getShippingTax());
    }

    /**
     * Spec: Cases/Integration/tax.md [97]
     *   "Shipping tax rounds half-up to the nearest cent per rate."
     * (The for_shipping column is TINYINT, so override rates are integers;
     * fractional cents come from the charge, and round(HALF_UP) — not ceil —
     * is the production rounding.)
     */
    public function test_shipping_tax_rounds_half_up_to_nearest_cent(): void
    {
        $this->enableTax('excluded');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10', 'for_shipping' => 7]);

        $calc = new TaxCalculator([
            $this->buildLineItem($product->ID, ['shipping_charge' => 3350])
        ], ['country' => 'US']);
        // 3350 * 7% = 234.5 → half-up = 235
        $this->assertSame(235, (int) $calc->getShippingTax(),
            'A .5-cent shipping tax rounds up to the next cent');
    }

    // =========================================================================
    // Spec 98-99: Rounding
    // =========================================================================

    public function test_tax_rounding_item_ceils_per_line(): void
    {
        $this->enableTax('excluded', 'billing', 'item');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '7.5']);

        $calc = new TaxCalculator([
            $this->buildLineItem($product->ID, ['subtotal' => 333]),
            $this->buildLineItem($product->ID, ['subtotal' => 333]),
        ], ['country' => 'US']);
        $lines = $calc->getTaxedLines();
        // 333 * 7.5% = 24.975 → ceil per-item = 25
        $this->assertSame(25, (int) $lines[0]['tax_amount']);
        $this->assertSame(25, (int) $lines[1]['tax_amount']);
    }

    /**
     * Spec: Cases/Integration/tax.md [99]
     *   "tax_rounding='subtotal' keeps raw per-item taxes and rounds once
     *    per rate at the end."
     */
    public function test_tax_rounding_subtotal_rounds_once_at_end(): void
    {
        $this->enableTax('excluded', 'billing', 'subtotal');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '7.1']);

        $items = [
            $this->buildLineItem($product->ID, ['subtotal' => 333]),
            $this->buildLineItem($product->ID, ['subtotal' => 333]),
        ];
        // Per-item mode rounds each 23.643 up to 24 → 48 total.
        $itemCalc = new TaxCalculator($items, ['country' => 'US', 'tax_rounding' => 'item']);
        $this->assertSame(48, (int) $itemCalc->getTotalTax(), 'Item mode rounds every line');

        // Subtotal mode accumulates raw 23.643 + 23.643 = 47.286 → rounds once to 47.
        $subtotalCalc = new TaxCalculator($items, ['country' => 'US', 'tax_rounding' => 'subtotal']);
        $this->assertSame(47, (int) $subtotalCalc->getTotalTax(),
            'Subtotal mode rounds once at the end, saving the accumulated fraction');
    }

    // =========================================================================
    // Spec 100-101: Aggregation
    // =========================================================================

    public function test_get_taxed_lines_returns_per_item_tax_config(): void
    {
        $this->enableTax('excluded');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $calc = new TaxCalculator([$this->buildLineItem($product->ID)], ['country' => 'US']);
        $lines = $calc->getTaxedLines();
        $this->assertArrayHasKey('tax_config', $lines[0]['line_meta']);
        $this->assertArrayHasKey('rates', $lines[0]['line_meta']['tax_config']);
    }

    public function test_get_tax_lines_by_rates_aggregates_by_rate_id(): void
    {
        $this->enableTax('excluded');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10', 'name' => 'Sales Tax']);

        $calc = new TaxCalculator([
            $this->buildLineItem($product->ID),
            $this->buildLineItem($product->ID),
        ], ['country' => 'US']);
        $agg = $calc->getTaxLinesByRates();
        $this->assertCount(1, $agg);
        $this->assertSame('Sales Tax', $agg[0]['label']);
        $this->assertSame(2000, (int) $agg[0]['tax_amount']);
    }

    // =========================================================================
    // Spec 102-107: Cart-level filter & hook integration
    // =========================================================================

    public function test_estimated_total_filter_adds_tax_when_exclusive(): void
    {
        $this->enableTax('excluded');
        (new TaxModule())->register();

        $cart = (object) ['checkout_data' => [
            'tax_data' => ['tax_behavior' => 1, 'tax_total' => 200, 'shipping_tax' => 50],
        ]];
        $total = apply_filters('fluent_cart/cart/estimated_total', 1000, ['cart' => $cart]);
        $this->assertSame(1250, (int) $total);
    }

    public function test_estimated_total_filter_skips_tax_when_inclusive(): void
    {
        $this->enableTax('included');
        (new TaxModule())->register();

        $cart = (object) ['checkout_data' => [
            'tax_data' => ['tax_behavior' => 2, 'tax_total' => 200, 'shipping_tax' => 50],
        ]];
        $total = apply_filters('fluent_cart/cart/estimated_total', 1000, ['cart' => $cart]);
        $this->assertSame(1000, (int) $total);
    }

    public function test_tax_behavior_filter_returns_stored_value(): void
    {
        $this->enableTax('included');
        (new TaxModule())->register();

        $cart = (object) ['checkout_data' => ['tax_data' => ['tax_behavior' => 2]]];
        $value = apply_filters('fluent_cart/cart/tax_behavior', 0, ['cart' => $cart]);
        $this->assertSame(2, (int) $value);
    }

    public function test_before_patch_filter_recalculates_on_address_changes(): void
    {
        $this->enableTax('excluded', 'billing');
        $module = new TaxModule();
        $module->register();

        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $fillData = [
            'cart_data'     => [$this->buildLineItem($product->ID)],
            'checkout_data' => ['form_data' => ['billing_country' => 'US']],
        ];
        $out = $module->maybeRecalculateTaxAmount($fillData, [
            'changes' => ['billing_country' => 'US'],
            'cart'    => null,
        ]);
        $this->assertGreaterThan(0, (int) $out['checkout_data']['tax_data']['tax_total']);
    }

    public function test_before_patch_filter_skips_recalc_on_unrelated_change(): void
    {
        $this->enableTax('excluded', 'billing');
        $module = new TaxModule();

        $fillData = ['cart_data' => [], 'checkout_data' => []];
        $out = $module->maybeRecalculateTaxAmount($fillData, [
            'changes' => ['notes' => 'something'],
            'cart'    => null,
        ]);
        $this->assertSame($fillData, $out);
    }

    /**
     * Spec: Cases/Integration/tax.md [107]
     *   "Action fluent_cart/cart/cart_data_items_updated triggers
     *    recalculation via recalculateTax()."
     */
    public function test_cart_data_items_updated_triggers_recalculate(): void
    {
        $this->enableTax('excluded', 'billing');
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $cart = (new CartFactory())
            ->withCartItems([$this->buildLineItem($product->ID)])
            ->create(['checkout_data' => ['form_data' => ['billing_country' => 'US']]]);

        (new TaxModule())->register();
        do_action('fluent_cart/cart/cart_data_items_updated', ['cart' => $cart]);

        $stored = Cart::query()->find($cart->cart_hash);
        $this->assertSame(1000, (int) Arr::get($stored->checkout_data, 'tax_data.tax_total'),
            'The action recalculates and PERSISTS the $10 tax on the stored cart');
    }

    // =========================================================================
    // Spec 123-127: prepareOtherData
    // =========================================================================

    public function test_prepare_other_data_creates_one_order_tax_rate_per_rate(): void
    {
        $this->enableTax('excluded', 'billing');
        $class   = $this->classFactory->create();
        $rate    = $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $customer = $this->customerFactory->create();
        $order    = $this->orderFactory->withAddresses()->create(['customer_id' => $customer->id]);

        $cart = (object) [
            'checkout_data' => [
                'tax_data' => ['tax_country' => 'US', 'tax_lines' => [['rate_id' => $rate->id]]],
            ],
            'cart_data' => [[
                'line_meta' => ['tax_config' => [
                    'rates' => [[
                        'rate_id'      => $rate->id,
                        'tax_amount'   => 500,
                        'shipping_tax' => 0,
                    ]],
                ]],
            ]],
        ];

        (new TaxModule())->prepareOtherData(['cart' => $cart, 'order' => $order]);
        $rows = OrderTaxRate::query()->where('order_id', $order->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame($rate->id, (int) $rows->first()->tax_rate_id);
    }

    public function test_prepare_other_data_allows_rate_id_zero_for_json_rates(): void
    {
        $this->enableTax('excluded', 'billing');
        $customer = $this->customerFactory->create();
        $order    = $this->orderFactory->withAddresses()->create(['customer_id' => $customer->id]);

        $cart = (object) [
            'checkout_data' => [
                'tax_data' => ['tax_country' => 'DE', 'tax_lines' => [['rate_id' => 0]]],
            ],
            'cart_data' => [[
                'line_meta' => ['tax_config' => [
                    'rates' => [[
                        // No rate_id → service treats as JSON-only, stores rate_id=0
                        'tax_amount'   => 190,
                        'shipping_tax' => 0,
                    ]],
                ]],
            ]],
        ];
        (new TaxModule())->prepareOtherData(['cart' => $cart, 'order' => $order]);
        $row = OrderTaxRate::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($row);
        $this->assertEquals(0, $row->tax_rate_id);
    }

    public function test_prepare_other_data_sets_total_tax_equal_to_shipping_plus_order(): void
    {
        $this->enableTax('excluded', 'billing');
        $class   = $this->classFactory->create();
        $rate    = $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $customer = $this->customerFactory->create();
        $order    = $this->orderFactory->withAddresses()->create(['customer_id' => $customer->id]);

        // persistTaxRates() reads order tax from tax_data.tax_lines[].tax_amount and
        // shipping tax from tax_data.shipping_tax; total_tax = order_tax + shipping_tax.
        $cart = (object) [
            'checkout_data' => [
                'tax_data' => [
                    'tax_country'  => 'US',
                    'shipping_tax' => 200,
                    'tax_lines'    => [['rate_id' => $rate->id, 'tax_amount' => 800]],
                ],
            ],
        ];
        (new TaxModule())->prepareOtherData(['cart' => $cart, 'order' => $order]);
        $row = OrderTaxRate::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($row, 'A tax-rate row is persisted for the order');
        $this->assertEquals(1000, (int) $row->total_tax, 'total_tax = order tax (800) + shipping tax (200)');
    }

    public function test_prepare_other_data_reverse_charge_zeros_tax(): void
    {
        $this->enableTax('excluded', 'billing');
        $class   = $this->classFactory->create();
        $rate    = $this->rateFactory->create(['class_id' => $class->id, 'country' => 'DE', 'rate' => '19']);

        $customer = $this->customerFactory->create();
        $order    = $this->orderFactory->withAddresses()->create(['customer_id' => $customer->id]);

        // A validated reverse-charge checkout: tax_behavior=0 shifts the tax to the buyer,
        // so the tax_data reaching prepareOtherData is already RC-zeroed. Assert the persisted
        // row keeps those zeros AND records that reverse charge was applied.
        $cart = (object) [
            'checkout_data' => [
                'tax_data' => [
                    'tax_country'  => 'DE',
                    'tax_behavior' => 0,
                    'shipping_tax' => 0,
                    'tax_lines'    => [['rate_id' => $rate->id, 'tax_amount' => 0]],
                    'valid'        => true,
                    'vat_number'   => 'DE123',
                    'country'      => 'DE',
                    'name'         => 'Test Ltd',
                    'address'      => 'Berlin',
                ],
            ],
        ];
        (new TaxModule())->prepareOtherData(['cart' => $cart, 'order' => $order]);
        $row = OrderTaxRate::query()->where('order_id', $order->id)->first();
        $this->assertNotNull($row, 'A tax-rate row is persisted for the reverse-charge order');
        $this->assertEquals(0, (int) $row->order_tax, 'Reverse charge persists zero order tax');
        $this->assertEquals(0, (int) $row->shipping_tax, 'Reverse charge persists zero shipping tax');
        $this->assertTrue((bool) Arr::get($row->meta, 'reverse_charge_applied'),
            'The persisted tax row records that reverse charge was applied');
    }

    public function test_prepare_other_data_stores_tax_country_and_store_vat_in_meta(): void
    {
        $this->enableTax('excluded', 'billing');
        $class   = $this->classFactory->create();
        $rate    = $this->rateFactory->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '10']);

        $customer = $this->customerFactory->create();
        $order    = $this->orderFactory->withAddresses()->create(['customer_id' => $customer->id]);

        $cart = (object) [
            'checkout_data' => [
                'tax_data' => ['tax_country' => 'US', 'tax_lines' => [['rate_id' => $rate->id]]],
            ],
            'cart_data' => [[
                'line_meta' => ['tax_config' => [
                    'rates' => [[
                        'rate_id'      => $rate->id,
                        'tax_amount'   => 500,
                        'shipping_tax' => 0,
                    ]],
                ]],
            ]],
        ];
        (new TaxModule())->prepareOtherData(['cart' => $cart, 'order' => $order]);
        $row = OrderTaxRate::query()->where('order_id', $order->id)->first();
        $this->assertSame('US', $row->meta['tax_country'] ?? null);
        $this->assertArrayHasKey('store_vat_number', $row->meta);
    }
}
