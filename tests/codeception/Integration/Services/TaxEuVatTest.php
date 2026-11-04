<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Cart;
use FluentCart\App\Services\Renderer\ModalCheckoutRenderer;
use FluentCart\App\Services\Renderer\VatFieldRenderer;
use FluentCart\Framework\Support\Arr;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\TaxClassFactory;
use Tests\Support\Factory\TaxRateFactory;
use Tests\Support\Traits\BuildsTaxFixtures;
use Tests\Support\WpDieCapture;

/**
 * EU VAT validation, reverse charge, VAT removal, and checkout-field
 * rendering (spec Cases/Integration/tax.md [108]-[122]). The VIES SOAP
 * service is mocked through the fluent_cart/tax/validate_eu_vat_number
 * filter; wp_send_json exit paths are captured via WpDieCapture
 * (TESTABILITY-DEBT #4).
 */
class TaxEuVatTest extends DatabaseTestCase
{
    use BuildsTaxFixtures;

    private TaxClassFactory $classFactory;
    private TaxRateFactory $rateFactory;
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->classFactory   = new TaxClassFactory();
        $this->rateFactory    = new TaxRateFactory();
        $this->productFactory = new ProductFactory();

        $this->setTaxSettings([]);
        $this->resetTaxRuntimeState();
        // fluent_cart_get_option keeps a function-static memo; write the
        // baseline explicitly so no earlier test's field settings leak in.
        $this->setVatNumberFieldEnabled(false);
    }

    public function tearDown(): void
    {
        $this->clearVatAjaxRequest();
        delete_option('fluent_cart_tax_configuration_settings');
        parent::tearDown();
    }

    // =========================================================================
    // Spec 108-112: handleVatValidation flow
    // =========================================================================

    /**
     * Spec: Cases/Integration/tax.md [108]
     *   "handleVatValidation fails when tax is not enabled."
     */
    public function test_handle_vat_validation_fails_when_tax_disabled(): void
    {
        $cart = $this->makeVatCheckoutCart([], ['form_data' => ['billing_country' => 'DE']]);
        $this->primeVatAjaxRequest($cart, 'DE123456789');

        $module = $this->makeTaxModule();
        $result = WpDieCapture::capture(function () use ($module) {
            $module->handleVatValidation();
        });

        $this->assertTrue($result['died'], 'The handler terminates via wp_send_json');
        $this->assertSame(422, $result['status'], 'Disabled tax rejects VAT validation with 422');
        $this->assertSame('Tax is not enabled.', Arr::get($result['json'], 'message'));
    }

    /**
     * Spec: Cases/Integration/tax.md [109]
     *   "handleVatValidation fails without a valid nonce."
     */
    public function test_handle_vat_validation_fails_without_nonce(): void
    {
        $this->enableTax();
        $cart = $this->makeVatCheckoutCart([], ['form_data' => ['billing_country' => 'DE']]);
        $this->primeVatAjaxRequest($cart, 'DE123456789', false);

        $module = $this->makeTaxModule();
        $result = WpDieCapture::capture(function () use ($module) {
            $module->handleVatValidation();
        });

        $this->assertSame(403, $result['status'], 'A missing nonce is rejected with 403');
        $this->assertSame('Security check failed', Arr::get($result['json'], 'message'));
    }

    /**
     * Spec: Cases/Integration/tax.md [110]
     *   "handleVatValidation requires an EU country and a VAT number."
     */
    public function test_handle_vat_validation_requires_country_and_vat(): void
    {
        $this->enableTax('excluded', 'billing');
        $this->setVatNumberFieldEnabled(true);

        // Missing VAT number → rejected.
        $cart = $this->makeVatCheckoutCart([], ['form_data' => ['billing_country' => 'DE']]);
        $this->primeVatAjaxRequest($cart, null);
        $module = $this->makeTaxModule();
        $noVat  = WpDieCapture::capture(function () use ($module) {
            $module->handleVatValidation();
        });
        $this->assertSame(422, $noVat['status'], 'A missing VAT number is rejected');
        $this->assertSame('Missing required data', Arr::get($noVat['json'], 'message'));

        // Non-EU country → rejected before any validation.
        $this->resetTaxRuntimeState();
        $usCart = $this->makeVatCheckoutCart([], ['form_data' => ['billing_country' => 'US']]);
        $this->primeVatAjaxRequest($usCart, 'US12345');
        $module = $this->makeTaxModule();
        $nonEu  = WpDieCapture::capture(function () use ($module) {
            $module->handleVatValidation();
        });
        $this->assertSame(422, $nonEu['status'], 'A non-EU country is rejected');
        $this->assertSame('VAT validation is only available for EU countries.', Arr::get($nonEu['json'], 'message'));
    }

    /**
     * Spec: Cases/Integration/tax.md [112a]
     *   "A visitor gets at most 10 VAT checks in 10 minutes; the 11th is
     *    refused before the store calls the VIES service." (SCAN-09)
     */
    public function test_vat_validation_is_limited_per_visitor(): void
    {
        $this->enableTax('excluded', 'billing');
        $this->setVatNumberFieldEnabled(true);
        $this->setStoreCountry('FR');
        delete_transient('fct_vat_checks_' . md5('127.0.0.1'));

        $cart = $this->makeVatCheckoutCart([], ['form_data' => ['billing_country' => 'DE']]);

        $viesCalls = 0;
        $remove = $this->mockVatValidation(function () use (&$viesCalls) {
            $viesCalls++;
            return new \WP_Error('invalid', 'Invalid VAT number');
        });

        $module = $this->makeTaxModule();
        $statuses = [];
        for ($i = 0; $i < 11; $i++) {
            $this->primeVatAjaxRequest($cart, 'DE12345678' . $i);
            $statuses[] = WpDieCapture::capture(function () use ($module) {
                $module->handleVatValidation();
            })['status'];
        }
        $remove();
        delete_transient('fct_vat_checks_' . md5('127.0.0.1'));

        $this->assertSame(10, $viesCalls, 'only 10 checks may reach the VAT service');
        $this->assertSame(429, end($statuses), 'the 11th check must be refused as too many requests');
    }

    /**
     * Spec: Cases/Integration/tax.md [112b]
     *   "Parallel VAT checks from one visitor update the counter one at a time;
     *    a check that cannot get its turn is refused before VIES." (SCAN-09)
     */
    public function test_vat_check_waits_for_a_parallel_check_to_update_the_count(): void
    {
        global $wpdb;
        $this->enableTax('excluded', 'billing');
        $this->setVatNumberFieldEnabled(true);
        $this->setStoreCountry('FR');
        delete_transient('fct_vat_checks_' . md5('127.0.0.1'));

        $cart = $this->makeVatCheckoutCart([], ['form_data' => ['billing_country' => 'DE']]);
        $this->primeVatAjaxRequest($cart, 'DE123456789');

        $viesCalls = 0;
        $remove = $this->mockVatValidation(function () use (&$viesCalls) {
            $viesCalls++;
            return new \WP_Error('invalid', 'Invalid VAT number');
        });

        // A parallel request from the same visitor is updating the count right now.
        $lockName = 'fct_vat_' . md5($wpdb->prefix . '127.0.0.1');
        $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $other->get_var($other->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 0));

        $module = $this->makeTaxModule();
        try {
            $whileBusy = WpDieCapture::capture(function () use ($module) {
                $module->handleVatValidation();
            });
        } finally {
            $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
            $other->close();
        }

        $afterRelease = WpDieCapture::capture(function () use ($module) {
            $module->handleVatValidation();
        });
        $remove();
        delete_transient('fct_vat_checks_' . md5('127.0.0.1'));

        $this->assertSame(429, $whileBusy['status'], 'a check that cannot update the count must be refused');
        $this->assertSame(1, $viesCalls, 'only the check that got its turn may reach the VAT service');
        $this->assertSame(422, $afterRelease['status'], 'once the parallel check is done, the next check runs normally');
    }

    /**
     * Spec: Cases/Integration/tax.md [111]
     *   "Strips the leading country prefix from the VAT number
     *    (DE123456789 → 123456789) before validation."
     */
    public function test_vat_number_prefix_stripped(): void
    {
        $this->enableTax('excluded', 'billing');
        $this->setVatNumberFieldEnabled(true);
        $this->setStoreCountry('FR');

        $cart = $this->makeVatCheckoutCart([], ['form_data' => ['billing_country' => 'DE']]);
        $this->primeVatAjaxRequest($cart, 'DE123456789');

        $seenPayload = null;
        $remove = $this->mockVatValidation(function ($payload) use (&$seenPayload) {
            $seenPayload = $payload;
            return [
                'country'    => 'DE',
                'vat_number' => $payload['vat_number'],
                'valid'      => true,
                'name'       => 'Acme GmbH',
                'address'    => 'Berlin',
            ];
        });

        $module = $this->makeTaxModule();
        $result = WpDieCapture::capture(function () use ($module) {
            $module->handleVatValidation();
        });
        $remove();

        $this->assertSame(200, $result['status'], 'The mocked validation succeeds');
        $this->assertSame('123456789', Arr::get($seenPayload, 'vat_number'),
            'The DE prefix is stripped before the validator sees the number');
        $this->assertSame('DE', Arr::get($seenPayload, 'country_code'));
    }

    /**
     * Spec: Cases/Integration/tax.md [112]
     *   "Stores the VIES-returned name and address on
     *    cart.checkout_data.tax_data."
     */
    public function test_vies_name_address_stored_on_tax_data(): void
    {
        $this->enableTax('excluded', 'billing');
        $this->setVatNumberFieldEnabled(true);
        $this->setStoreCountry('FR');

        $cart = $this->makeVatCheckoutCart([], ['form_data' => ['billing_country' => 'DE']]);
        $this->primeVatAjaxRequest($cart, 'DE123456789');

        $remove = $this->mockVatValidation([
            'country'    => 'DE',
            'vat_number' => '123456789',
            'valid'      => true,
            'name'       => 'Acme GmbH',
            'address'    => 'Musterstr. 1, Berlin',
        ]);

        $module = $this->makeTaxModule();
        $result = WpDieCapture::capture(function () use ($module) {
            $module->handleVatValidation();
        });
        $remove();

        $this->assertSame(200, $result['status']);
        $stored  = Cart::query()->find($cart->cart_hash);
        $taxData = Arr::get($stored->checkout_data, 'tax_data', []);
        $this->assertTrue((bool) Arr::get($taxData, 'valid'), 'The validation result is persisted');
        $this->assertSame('Acme GmbH', Arr::get($taxData, 'name'), 'VIES company name is stored');
        $this->assertSame('Musterstr. 1, Berlin', Arr::get($taxData, 'address'), 'VIES address is stored');
        $this->assertSame('123456789', Arr::get($taxData, 'vat_number'), 'The validated VAT number is stored');
    }

    // =========================================================================
    // Spec 113-117: Reverse charge rules
    // =========================================================================

    /**
     * Spec: Cases/Integration/tax.md [113]
     *   "Reverse charge does NOT apply when the cart contains a product in
     *    vat_reverse_excluded_categories."
     */
    public function test_reverse_charge_excluded_when_cart_has_excluded_category(): void
    {
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'DE', 'rate' => '19']);
        $term = $this->attachProductToNewCategoryTerm($product->ID);

        $this->setVatNumberFieldEnabled(true);
        $this->enableTax('excluded', 'billing', 'item', [
            'eu_vat_settings' => [
                'method'                          => 'oss',
                'local_reverse_charge'            => 'yes',
                'reverse_charge_price_mode'       => 'fixed',
                'vat_reverse_excluded_categories' => [$term['term_taxonomy_id']],
            ],
        ]);
        $this->setStoreCountry('DE');

        $cart = $this->makeVatCheckoutCart(
            [$this->buildCartLineForProduct($product->ID)],
            ['form_data' => ['billing_country' => 'DE']]
        );
        $this->primeVatAjaxRequest($cart, 'DE123456789');
        $remove = $this->mockVatValidation([
            'country' => 'DE', 'vat_number' => '123456789', 'valid' => true,
            'name' => 'Acme GmbH', 'address' => 'Berlin',
        ]);

        $module = $this->makeTaxModule();
        $result = WpDieCapture::capture(function () use ($module) {
            $module->handleVatValidation();
        });
        $remove();

        $this->assertSame(200, $result['status'], 'Validation itself still succeeds');
        $stored  = Cart::query()->find($cart->cart_hash);
        $taxData = Arr::get($stored->checkout_data, 'tax_data', []);
        $this->assertSame(1900, (int) Arr::get($taxData, 'tax_total'),
            'Tax is still charged — the excluded category blocks reverse charge');
        $this->assertNotSame(0, (int) Arr::get($taxData, 'tax_behavior'),
            'tax_behavior is not the reverse-charge 0');
    }

    /**
     * Spec: Cases/Integration/tax.md [114]
     *   "Reverse charge applies when local_reverse_charge='yes' AND the VAT
     *    number is valid (same-country B2B)."
     */
    public function test_reverse_charge_applies_on_local_reverse_charge_yes(): void
    {
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'DE', 'rate' => '19']);

        $this->setVatNumberFieldEnabled(true);
        $this->enableTax('excluded', 'billing', 'item', [
            'eu_vat_settings' => [
                'method'                    => 'oss',
                'local_reverse_charge'      => 'yes',
                'reverse_charge_price_mode' => 'fixed',
            ],
        ]);
        $this->setStoreCountry('DE');

        $cart = $this->makeVatCheckoutCart(
            [$this->buildCartLineForProduct($product->ID)],
            ['form_data' => ['billing_country' => 'DE']]
        );
        $this->primeVatAjaxRequest($cart, 'DE123456789');
        $remove = $this->mockVatValidation([
            'country' => 'DE', 'vat_number' => '123456789', 'valid' => true,
            'name' => 'Acme GmbH', 'address' => 'Berlin',
        ]);

        $module = $this->makeTaxModule();
        $result = WpDieCapture::capture(function () use ($module) {
            $module->handleVatValidation();
        });
        $remove();

        $this->assertSame(200, $result['status']);
        $stored  = Cart::query()->find($cart->cart_hash);
        $taxData = Arr::get($stored->checkout_data, 'tax_data', []);
        $this->assertArrayHasKey('tax_behavior', $taxData, 'The recalculated tax_data is persisted');
        $this->assertSame(0, (int) $taxData['tax_behavior'],
            'Same-country B2B with local_reverse_charge=yes reverse-charges the order');
        $this->assertGreaterThan(0, (int) Arr::get($taxData, 'reverse_charge_tax_total'),
            'The reversed VAT amount is recorded');
    }

    /**
     * Spec: Cases/Integration/tax.md [115]
     *   "Reverse charge applies when the customer country differs from the
     *    store country AND the VAT number is valid."
     */
    public function test_reverse_charge_applies_cross_border(): void
    {
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'DE', 'rate' => '19']);

        $this->setVatNumberFieldEnabled(true);
        $this->enableTax('excluded', 'billing', 'item', [
            'eu_vat_settings' => [
                'method'                    => 'oss',
                'local_reverse_charge'      => 'no',
                'reverse_charge_price_mode' => 'fixed',
            ],
        ]);
        $this->setStoreCountry('FR');

        $cart = $this->makeVatCheckoutCart(
            [$this->buildCartLineForProduct($product->ID)],
            ['form_data' => ['billing_country' => 'DE']]
        );
        $this->primeVatAjaxRequest($cart, 'DE123456789');
        $remove = $this->mockVatValidation([
            'country' => 'DE', 'vat_number' => '123456789', 'valid' => true,
            'name' => 'Acme GmbH', 'address' => 'Berlin',
        ]);

        $module = $this->makeTaxModule();
        $result = WpDieCapture::capture(function () use ($module) {
            $module->handleVatValidation();
        });
        $remove();

        $this->assertSame(200, $result['status']);
        $stored  = Cart::query()->find($cart->cart_hash);
        $taxData = Arr::get($stored->checkout_data, 'tax_data', []);
        $this->assertArrayHasKey('tax_behavior', $taxData, 'The recalculated tax_data is persisted');
        $this->assertSame(0, (int) $taxData['tax_behavior'],
            'Cross-border B2B with a valid VAT number reverse-charges the order');
        $this->assertGreaterThan(0, (int) Arr::get($taxData, 'reverse_charge_tax_total'),
            'The reversed VAT amount is recorded');
    }

    /**
     * Spec: Cases/Integration/tax.md [116]
     *   "Reverse charge sets tax_total=0, shipping_tax=0, tax_behavior=0
     *    and records the reversed total."
     */
    public function test_reverse_charge_zeros_tax_and_sets_behavior_0(): void
    {
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'DE', 'rate' => '19', 'for_shipping' => 19]);

        $this->setVatNumberFieldEnabled(true);
        $this->enableTax('excluded', 'billing', 'item', [
            'eu_vat_settings' => [
                'method'                    => 'oss',
                'local_reverse_charge'      => 'no',
                'reverse_charge_price_mode' => 'fixed',
            ],
        ]);
        $this->setStoreCountry('FR');

        $cart = $this->makeVatCheckoutCart(
            [$this->buildCartLineForProduct($product->ID, ['shipping_charge' => 1000])],
            ['form_data' => ['billing_country' => 'DE']]
        );
        $this->primeVatAjaxRequest($cart, 'DE123456789');
        $remove = $this->mockVatValidation([
            'country' => 'DE', 'vat_number' => '123456789', 'valid' => true,
            'name' => 'Acme GmbH', 'address' => 'Berlin',
        ]);

        $module = $this->makeTaxModule();
        WpDieCapture::capture(function () use ($module) {
            $module->handleVatValidation();
        });
        $remove();

        $stored  = Cart::query()->find($cart->cart_hash);
        $taxData = Arr::get($stored->checkout_data, 'tax_data', []);
        $this->assertSame(0, (int) Arr::get($taxData, 'tax_total'), 'Order tax is zeroed');
        $this->assertSame(0, (int) Arr::get($taxData, 'shipping_tax'), 'Shipping tax is zeroed');
        $this->assertSame(0, (int) Arr::get($taxData, 'tax_behavior'), 'Behavior 0 marks reverse charge');
        $this->assertGreaterThan(0, (int) Arr::get($taxData, 'reverse_charge_tax_total'),
            'The reversed amount is recorded for display');
    }

    /**
     * Spec: Cases/Integration/tax.md [117]
     *   "Reverse charge does not fire when neither condition holds — VAT is
     *    charged normally."
     */
    public function test_reverse_charge_does_not_fire_when_conditions_fail(): void
    {
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'DE', 'rate' => '19']);

        $this->setVatNumberFieldEnabled(true);
        $this->enableTax('excluded', 'billing', 'item', [
            'eu_vat_settings' => [
                'method'                    => 'oss',
                'local_reverse_charge'      => 'no',
                'reverse_charge_price_mode' => 'fixed',
            ],
        ]);
        $this->setStoreCountry('DE'); // same country + local RC off → no reverse charge

        $cart = $this->makeVatCheckoutCart(
            [$this->buildCartLineForProduct($product->ID)],
            ['form_data' => ['billing_country' => 'DE']]
        );
        $this->primeVatAjaxRequest($cart, 'DE123456789');
        $remove = $this->mockVatValidation([
            'country' => 'DE', 'vat_number' => '123456789', 'valid' => true,
            'name' => 'Acme GmbH', 'address' => 'Berlin',
        ]);

        $module = $this->makeTaxModule();
        $result = WpDieCapture::capture(function () use ($module) {
            $module->handleVatValidation();
        });
        $remove();

        $this->assertSame(200, $result['status'], 'The VAT number is still validated');
        $stored  = Cart::query()->find($cart->cart_hash);
        $taxData = Arr::get($stored->checkout_data, 'tax_data', []);
        $this->assertSame(1900, (int) Arr::get($taxData, 'tax_total'), 'Tax is charged normally');
        $this->assertNotSame(0, (int) Arr::get($taxData, 'tax_behavior'), 'No reverse-charge behavior');
    }

    // =========================================================================
    // Spec 118-120: VAT removal
    // =========================================================================

    /**
     * Spec: Cases/Integration/tax.md [118]
     *   "removeVat clears valid/name/address/vat_number/country from
     *    tax_data."
     */
    public function test_remove_vat_clears_fields(): void
    {
        $this->enableTax('excluded', 'billing');
        $cart = $this->makeVatCheckoutCart([], [
            'form_data' => ['billing_country' => 'DE'],
            'tax_data'  => [
                'valid'      => true,
                'name'       => 'Acme GmbH',
                'address'    => 'Berlin',
                'vat_number' => '123456789',
                'country'    => 'DE',
            ],
        ]);
        $this->primeVatAjaxRequest($cart);

        $module = $this->makeTaxModule();
        $result = WpDieCapture::capture(function () use ($module) {
            $module->removeVat();
        });

        // removeVat's wp_send_json sends no explicit status code — assert the
        // successful JSON body instead.
        $this->assertTrue($result['died'], 'Removal responds via wp_send_json');
        $this->assertTrue((bool) Arr::get($result['json'], 'success'), 'Removal reports success');
        $stored  = Cart::query()->find($cart->cart_hash);
        $taxData = Arr::get($stored->checkout_data, 'tax_data', []);
        foreach (['valid', 'name', 'address', 'vat_number', 'country'] as $key) {
            $this->assertArrayNotHasKey($key, $taxData, "removeVat clears tax_data.{$key}");
        }
    }

    /**
     * Spec: Cases/Integration/tax.md [119]
     *   "removeVat triggers the fluent_cart/checkout/cart_amount_updated
     *    action."
     */
    public function test_remove_vat_fires_cart_amount_updated(): void
    {
        $this->enableTax('excluded', 'billing');
        $cart = $this->makeVatCheckoutCart([], [
            'form_data' => ['billing_country' => 'DE'],
            'tax_data'  => ['valid' => true, 'vat_number' => '123456789', 'country' => 'DE'],
        ]);
        $this->primeVatAjaxRequest($cart);

        $before = did_action('fluent_cart/checkout/cart_amount_updated');
        $module = $this->makeTaxModule();
        WpDieCapture::capture(function () use ($module) {
            $module->removeVat();
        });

        $this->assertSame($before + 1, did_action('fluent_cart/checkout/cart_amount_updated'),
            'removeVat dispatches the cart_amount_updated action exactly once');
    }

    /**
     * Spec: Cases/Integration/tax.md [120]
     *   "removeVat returns fragment replacements (cart summary) in the JSON
     *    response."
     */
    public function test_remove_vat_returns_fragment_replacements(): void
    {
        $this->enableTax('excluded', 'billing');
        $cart = $this->makeVatCheckoutCart([], [
            'form_data' => ['billing_country' => 'DE'],
            'tax_data'  => ['valid' => true, 'vat_number' => '123456789', 'country' => 'DE'],
        ]);
        $this->primeVatAjaxRequest($cart);

        $module = $this->makeTaxModule();
        $result = WpDieCapture::capture(function () use ($module) {
            $module->removeVat();
        });

        $this->assertTrue($result['died'], 'Removal responds via wp_send_json');
        $this->assertTrue((bool) Arr::get($result['json'], 'success'));
        $fragments = Arr::get($result['json'], 'fragments', []);
        $this->assertNotEmpty($fragments, 'The response carries replacement fragments');
        $this->assertSame('[data-fluent-cart-checkout-page-cart-items-wrapper]',
            Arr::get($fragments, '0.selector'), 'The cart summary fragment targets its wrapper');
        $this->assertSame('replace', Arr::get($fragments, '0.type'));
    }

    /**
     * Spec: Cases/Integration/tax.md [191]
     *   "handleVatValidation also returns the modal checkout summary fragments
     *    rendered from the recalculated cart."
     */
    public function test_vat_validation_returns_modal_checkout_fragments(): void
    {
        $class   = $this->classFactory->create();
        $product = $this->makeProductWithClass($class->id);
        $this->rateFactory->create(['class_id' => $class->id, 'country' => 'DE', 'rate' => '19']);

        $this->setVatNumberFieldEnabled(true);
        $this->enableTax('excluded', 'billing', 'item', [
            'eu_vat_settings' => [
                'method'                    => 'oss',
                'local_reverse_charge'      => 'no',
                'reverse_charge_price_mode' => 'fixed',
            ],
        ]);
        $this->setStoreCountry('FR');

        $cart = $this->makeVatCheckoutCart(
            [$this->buildCartLineForProduct($product->ID)],
            ['form_data' => ['billing_country' => 'DE']]
        );
        $this->primeVatAjaxRequest($cart, 'DE123456789');
        $remove = $this->mockVatValidation([
            'country' => 'DE', 'vat_number' => '123456789', 'valid' => true,
            'name' => 'Acme GmbH', 'address' => 'Berlin',
        ]);

        $module = $this->makeTaxModule();
        $result = WpDieCapture::capture(function () use ($module) {
            $module->handleVatValidation();
        });
        $remove();

        $this->assertSame(200, $result['status']);
        $this->assertModalSummaryFragments($result['json'], Cart::query()->find($cart->cart_hash));
    }

    /**
     * Spec: Cases/Integration/tax.md [192]
     *   "removeVat also returns the modal checkout summary fragments rendered
     *    from the recalculated cart."
     */
    public function test_remove_vat_returns_modal_checkout_fragments(): void
    {
        $this->enableTax('excluded', 'billing');
        $cart = $this->makeVatCheckoutCart([], [
            'form_data' => ['billing_country' => 'DE'],
            'tax_data'  => ['valid' => true, 'vat_number' => '123456789', 'country' => 'DE'],
        ]);
        $this->primeVatAjaxRequest($cart);

        $module = $this->makeTaxModule();
        $result = WpDieCapture::capture(function () use ($module) {
            $module->removeVat();
        });

        $this->assertTrue((bool) Arr::get($result['json'], 'success'));
        $this->assertModalSummaryFragments($result['json'], Cart::query()->find($cart->cart_hash));
    }

    // The modal checkout has no full-page summary wrapper, so it only refreshes from these two fragments
    private function assertModalSummaryFragments(array $json, Cart $storedCart): void
    {
        $fragments = array_column(Arr::get($json, 'fragments', []), 'content', 'selector');
        $renderer  = new ModalCheckoutRenderer($storedCart);

        $this->assertArrayHasKey('[data-fct-modal-checkout-summary-group]', $fragments,
            'The modal summary group fragment is returned');
        $this->assertArrayHasKey('[data-fct-modal-checkout-summary]', $fragments,
            'The modal checkout summary fragment is returned');
        $this->assertSame($renderer->getFragment('summary_group'),
            $fragments['[data-fct-modal-checkout-summary-group]'],
            'The modal summary group reflects the recalculated cart');
        $this->assertSame($renderer->getFragment('checkout_summary'),
            $fragments['[data-fct-modal-checkout-summary]'],
            'The modal checkout summary reflects the recalculated cart');
    }

    // =========================================================================
    // Spec 121-122: Rendering
    // =========================================================================

    /**
     * Spec: Cases/Integration/tax.md [121]
     *   "renderTaxField outputs the VAT input when the vat_number checkout
     *    field is enabled; the Apply button renders only for EU tax
     *    countries; nothing renders when the field is disabled."
     */
    public function test_render_tax_field_outputs_input_and_eu_apply_button(): void
    {
        $this->enableTax('excluded', 'billing');

        // Field disabled → no output at all.
        $cart = $this->makeVatCheckoutCart([], ['form_data' => ['billing_country' => 'DE']]);
        $module = $this->makeTaxModule();
        ob_start();
        $module->renderTaxField(['cart' => $cart]);
        $disabledOutput = ob_get_clean();
        $this->assertSame('', trim($disabledOutput), 'A disabled vat_number field renders nothing');

        // Enabled + EU billing country → input and Apply button.
        $this->setVatNumberFieldEnabled(true);
        ob_start();
        $module->renderTaxField(['cart' => $cart]);
        $euOutput = ob_get_clean();
        $this->assertStringContainsString('fct_billing_tax_id', $euOutput, 'The VAT input renders');
        $this->assertStringContainsString('data-fluent-cart-checkout-page-tax-apply-btn', $euOutput,
            'An EU tax country gets the Apply button');

        // Enabled + non-EU billing country → input but no Apply button.
        $usCart = $this->makeVatCheckoutCart([], ['form_data' => ['billing_country' => 'US']]);
        ob_start();
        $module->renderTaxField(['cart' => $usCart]);
        $usOutput = ob_get_clean();
        $this->assertStringContainsString('fct_billing_tax_id', $usOutput,
            'The input still renders for non-EU countries');
        $this->assertStringNotContainsString('data-fluent-cart-checkout-page-tax-apply-btn', $usOutput,
            'The EU Apply button does not render for a non-EU tax country');
    }

    /**
     * Spec: Cases/Integration/tax.md [122]
     *   "The validation note includes the company name and '(Reverse Charge
     *    not applied)' when tax_total != 0 even after validation."
     */
    public function test_valid_note_includes_reverse_charge_not_applied_when_tax_non_zero(): void
    {
        $this->enableTax('excluded', 'billing');

        $checkoutData = [
            'tax_data' => [
                'valid'     => true,
                'name'      => 'Acme GmbH',
                'tax_total' => 500,
            ],
        ];
        ob_start();
        (new VatFieldRenderer('DE'))->renderValidNote($checkoutData);
        $withTax = ob_get_clean();
        $this->assertStringContainsString('data-vat-name="Acme GmbH"', $withTax,
            'The note carries the validated company name');
        $this->assertStringContainsString('(Reverse Charge not applied)', $withTax,
            'Non-zero tax after validation warns that reverse charge was not applied');

        $checkoutData['tax_data']['tax_total'] = 0;
        ob_start();
        (new VatFieldRenderer('DE'))->renderValidNote($checkoutData);
        $zeroTax = ob_get_clean();
        $this->assertStringNotContainsString('(Reverse Charge not applied)', $zeroTax,
            'A fully reverse-charged (zero-tax) order shows no warning');
    }
}
