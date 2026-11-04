<?php

namespace Tests\Support\Traits;

use FluentCart\App\App;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\TaxClass;
use FluentCart\App\Modules\Tax\TaxCalculator;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\App\Services\Tax\TaxManager;
use FluentCart\Api\Resource\FrontendResource\CartResource;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\ProductFactory;

/**
 * Builds tax settings, tax-classed products, calculator line items, and EU VAT
 * checkout fixtures for the Integration tax suites. Also owns the per-test
 * runtime-cache resets (TESTABILITY-DEBT #7/#8 seams) so tax tests are
 * order-independent. Builds state via factories/options only — no assertions.
 */
trait BuildsTaxFixtures
{
    /**
     * Clear every per-request memo the tax pipeline keeps, simulating the
     * fresh PHP process each production request gets.
     */
    protected function resetTaxRuntimeState(): void
    {
        $ref = new \ReflectionClass(TaxManager::class);
        if ($ref->hasProperty('instance')) {
            $prop = $ref->getProperty('instance');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }
        TaxCalculator::resetCache();
        CartResource::resetCartCache();

        // StoreSettings memoizes the whole option in a class-static; clear it
        // so per-test store_country changes are visible.
        $storeRef = new \ReflectionClass(\FluentCart\Api\StoreSettings::class);
        if ($storeRef->hasProperty('cachedStoreSettings')) {
            $prop = $storeRef->getProperty('cachedStoreSettings');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }

        \FluentCart\App\Services\Renderer\CheckoutFieldsSchema::resetFieldsCache();
    }

    protected function setTaxSettings(array $settings): void
    {
        update_option('fluent_cart_tax_configuration_settings', $settings);
    }

    protected function enableTax(string $inclusion = 'excluded', string $basis = 'billing', string $rounding = 'item', array $extra = []): void
    {
        $this->setTaxSettings(array_merge([
            'enable_tax'            => 'yes',
            'tax_inclusion'         => $inclusion,
            'tax_calculation_basis' => $basis,
            'tax_rounding'          => $rounding,
        ], $extra));
    }

    protected function setStoreCountry(string $country): void
    {
        $settings = get_option('fluent_cart_store_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }
        $settings['store_country'] = $country;
        update_option('fluent_cart_store_settings', $settings);

        // Invalidate the StoreSettings class-static memo so the change is
        // visible even when a StoreSettings was constructed earlier in-test.
        $storeRef = new \ReflectionClass(\FluentCart\Api\StoreSettings::class);
        $prop = $storeRef->getProperty('cachedStoreSettings');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    /** @var array|null Snapshot of fluent_cart_store_settings before a test changed the currency. */
    private $storeSettingsSnapshot = null;

    protected function setStoreCurrency(string $currency): void
    {
        $settings = get_option('fluent_cart_store_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }
        if ($this->storeSettingsSnapshot === null) {
            $this->storeSettingsSnapshot = $settings;
            // tearDown() does not run when a test fatals; the option is real site
            // data, so pin the restore to process shutdown as well.
            $original = $settings;
            register_shutdown_function(function () use ($original) {
                update_option('fluent_cart_store_settings', $original);
            });
        }
        $settings['currency'] = $currency;
        update_option('fluent_cart_store_settings', $settings);

        $this->clearStoreSettingsMemo();
    }

    /**
     * The store settings option is real site data, not a per-test fixture — a
     * leaked currency silently retunes every money assertion in the suite.
     */
    protected function restoreStoreCurrency(): void
    {
        if ($this->storeSettingsSnapshot === null) {
            return;
        }
        update_option('fluent_cart_store_settings', $this->storeSettingsSnapshot);
        $this->storeSettingsSnapshot = null;
        $this->clearStoreSettingsMemo();
    }

    protected function clearStoreSettingsMemo(): void
    {
        $storeRef = new \ReflectionClass(\FluentCart\Api\StoreSettings::class);
        $prop = $storeRef->getProperty('cachedStoreSettings');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    protected function buildLineItem(int $productId, array $overrides = []): array
    {
        // A real order line references a product VARIATION via object_id, and
        // TaxCalculator only reads the variant's tax_class when object_id is a real
        // variation id. Default object_id to the product's variant so class
        // resolution matches production instead of falling back to standard.
        $variantId = (int) (ProductVariation::query()->where('post_id', $productId)->value('id') ?: 0);
        $item = array_merge([
            'post_id'        => $productId,
            'object_id'      => $variantId,
            'quantity'       => 1,
            'subtotal'       => 10000, // $100 in cents
            'discount_total' => 0,
            'tax_amount'     => 0,
            'shipping_charge'=> 0,
            'other_info'     => [],
            'line_meta'      => [],
        ], $overrides);

        // Real cart lines always carry unit_price/line_total; Cart::getFees()
        // (percent-based fees) reads them. Derive from subtotal when not given.
        if (!array_key_exists('unit_price', $item)) {
            $item['unit_price'] = (int) ($item['subtotal'] / max(1, (int) $item['quantity']));
        }
        if (!array_key_exists('line_total', $item)) {
            $item['line_total'] = max(0, (int) $item['subtotal'] - (int) $item['discount_total']);
        }

        return $item;
    }

    protected function buildSubscriptionLineItem(int $productId, array $otherInfo = [], array $overrides = []): array
    {
        return $this->buildLineItem($productId, array_merge($overrides, [
            'other_info' => array_merge(['payment_type' => 'subscription'], $otherInfo),
        ]));
    }

    protected function makeProductWithClass(int $taxClassId): object
    {
        // TaxCalculator resolves a product's tax class from other_info['tax_class']
        // by SLUG (TaxClass::where('slug', ...)), matching how products are stored
        // in production (default 'standard'). Seed the slug, not the id.
        $slug = TaxClass::query()->findOrFail($taxClassId)->slug;
        return (new ProductFactory())
            ->withVariantAttributes(['other_info' => ['payment_type' => 'onetime', 'tax_class' => $slug]])
            ->create();
    }

    /**
     * The 'standard' class is the calculator's fallback for products without a
     * variant-level tax class; migrations don't seed it under WPLoader.
     */
    protected function ensureStandardTaxClass(): TaxClass
    {
        $existing = TaxClass::query()->where('slug', 'standard')->first();
        if ($existing) {
            return $existing;
        }
        return TaxClass::query()->create([
            'title'       => 'Standard',
            'slug'        => 'standard',
            'description' => '',
            'meta'        => ['priority' => 10, 'categories' => []],
        ]);
    }

    protected function calcLine(int $productId, string $country, string $state = '', string $city = '', string $postCode = ''): array
    {
        // Preserve the inclusion setting set by the calling test — do not reset it here.
        $calc = new TaxCalculator(
            [$this->buildLineItem($productId)],
            ['country' => $country, 'state' => $state, 'city' => $city, 'postcode' => $postCode]
        );
        $lines = $calc->getTaxedLines();
        return $lines[0] ?? [];
    }

    /**
     * Attach a product to a fresh category term (raw term_relationships row —
     * TaxCalculator::getTermsByProductId reads that table directly).
     *
     * @return array The created term (term_id / term_taxonomy_id keys).
     */
    protected function attachProductToNewCategoryTerm(int $productId): array
    {
        $term = (new \Tests\Support\Factory\ProductTermFactory())
            ->create(\Tests\Support\Factory\ProductTermFactory::TAXONOMY_CATEGORY);

        global $wpdb;
        $wpdb->insert($wpdb->term_relationships, [
            'object_id'        => $productId,
            'term_taxonomy_id' => $term['term_taxonomy_id'],
            'term_order'       => 0,
        ]);

        return $term;
    }

    /**
     * Store a category tax override (fct_meta object_type=tax_override) for a
     * term, mirroring what the admin "category override" UI persists.
     */
    protected function addCategoryTaxOverride(int $termTaxonomyId, array $overrideMeta): void
    {
        \FluentCart\App\Models\Meta::query()->create([
            'object_id'   => $termTaxonomyId,
            'object_type' => 'tax_override',
            'meta_key'    => 'product_category_override',
            'meta_value'  => $overrideMeta,
        ]);
    }

    // =========================================================================
    // EU VAT checkout fixtures
    // =========================================================================

    /**
     * A TaxModule with its settings memo primed — handleVatValidation and
     * renderTaxField read $this->taxSettings, which only getSettings() fills.
     */
    protected function makeTaxModule(): TaxModule
    {
        $module = new TaxModule();
        $module->getSettings();
        return $module;
    }

    protected function setVatNumberFieldEnabled(bool $enabled): void
    {
        fluent_cart_update_option('_fc_checkout_fields', $enabled ? [
            'business_details' => [
                'vat_number' => ['enabled' => 'yes', 'required' => 'no'],
            ],
        ] : []);
        \FluentCart\App\Services\Renderer\CheckoutFieldsSchema::resetFieldsCache();
    }

    /**
     * A full renderable cart line (title/price/unit_price/...) pointing at a
     * real product + variation so both TaxCalculator and CartSummaryRender
     * can consume it.
     */
    protected function buildCartLineForProduct(int $productId, array $overrides = []): array
    {
        $variantId = (int) (ProductVariation::query()->where('post_id', $productId)->value('id') ?: 0);
        return CartFactory::makeCartItem(array_merge([
            'post_id'    => $productId,
            'object_id'  => $variantId,
            'unit_price' => 10000,
            'quantity'   => 1,
        ], $overrides));
    }

    /**
     * Instant-checkout cart resolvable by hash through CartResource::get()
     * (the path both VAT AJAX handlers use).
     */
    protected function makeVatCheckoutCart(array $cartData, array $checkoutData): Cart
    {
        return (new CartFactory())
            ->withAttributes(['cart_group' => 'instant', 'stage' => 'draft'])
            ->create([
                'cart_data'     => $cartData,
                'checkout_data' => $checkoutData,
            ]);
    }

    /**
     * Prime superglobals + the framework request the way an admin-ajax call
     * would: nonce, cart hash, and (optionally) the submitted VAT number.
     */
    protected function primeVatAjaxRequest(Cart $cart, ?string $vatNumber = null, bool $withNonce = true): void
    {
        unset($_REQUEST['_wpnonce'], $_REQUEST['vat_number'], $_REQUEST['fct_cart_hash']);
        if ($withNonce) {
            $_REQUEST['_wpnonce'] = wp_create_nonce('fluentcart');
        }
        $_REQUEST['fct_cart_hash'] = $cart->cart_hash;
        if ($vatNumber !== null) {
            $_REQUEST['vat_number'] = $vatNumber;
        }
        App::request()->merge(['fct_cart_hash' => $cart->cart_hash]);
    }

    protected function clearVatAjaxRequest(): void
    {
        unset($_REQUEST['_wpnonce'], $_REQUEST['vat_number'], $_REQUEST['fct_cart_hash']);
        App::request()->merge(['fct_cart_hash' => null]);
    }

    /**
     * Mock the VIES SOAP validation through the sanctioned third-party filter
     * (fluent_cart/tax/validate_eu_vat_number). Pass an array result, a
     * WP_Error, or a callable receiving the filter payload. Returns a remover.
     */
    protected function mockVatValidation($result): callable
    {
        $filter = function ($pre, $payload) use ($result) {
            return is_callable($result) ? $result($payload) : $result;
        };
        add_filter('fluent_cart/tax/validate_eu_vat_number', $filter, 10, 2);
        return function () use ($filter) {
            remove_filter('fluent_cart/tax/validate_eu_vat_number', $filter, 10);
        };
    }
}
