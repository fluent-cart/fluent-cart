<?php

namespace Tests\Integration\Services\Renderer;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\App\Services\Renderer\ProductRenderer;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\BuildsTaxFixtures;
use Tests\Support\Traits\RendersProductMarkup;

/**
 * Ticket #3579 — the configured tax price suffix (e.g. "+ GST") was missing
 * from the main price on several single-product rendering paths.
 *
 * Root cause: ProductRenderer::renderPrices()'s simple-product branch (one
 * confirmed price, one variant) fired `fluent_cart/product/after_price` with
 * scope `price_range` but never passed a `variant` and never called
 * RenderHelper::renderPriceSuffix() to consume it, so the suffix never
 * rendered. TaxModule's `after_price` listener also unconditionally skipped
 * `scope === 'price_range'` (added in PR #1767, reinstated in PR #2271 when
 * the suffix mechanism moved to self-removing transient filters) to keep the
 * non-simple price summary — min/max, or a single value when every variant
 * happens to price the same — from showing a suffix that can't be
 * attributed to one variant's tax_inclusion; that summary never passes a
 * variant, and that part is a deliberate, documented decision this fix does
 * NOT reverse. Both things collided: the simple product's single confirmed
 * price shares that same scope string, so it was silently caught by a guard
 * meant only for the variant-less summary case.
 *
 * ProductCardRender (shop grid / Gutenberg "Price Range" block) never had
 * this bug — it already calls renderPriceSuffix() for both cases. Note it is
 * NOT gated by TaxModule's price_range guard (its scope is 'product_card'),
 * so a genuine range on a product card DOES show the store-default suffix —
 * unlike the single-product page's range block, which still shows none.
 * That pre-existing card/single-product inconsistency for genuine ranges is
 * untouched by this fix; it is not part of ticket #3579.
 *
 * Not every single-product main price goes through ProductRenderer — a
 * page built with FluentCart's own Gutenberg "Price Range" block renders its
 * main price via ProductCardRender instead (see PriceRangeBlockEditor),
 * which is why that path was already correct. But every OTHER main-price
 * surface funnels through ProductRenderer::renderPrices(): the default/Core
 * single-product template (TemplateActions::renderProductHeader), the
 * [fluent_cart_product] shortcode, the quick-view modal, the Bricks Price
 * Range element, and the Elementor Product Price / Product Info widgets —
 * exercising ProductRenderer directly covers all of those.
 */
class ProductPriceSuffixTest extends WPTestCase
{
    use BuildsTaxFixtures;
    use RendersProductMarkup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetTaxRuntimeState();
    }

    protected function tearDown(): void
    {
        $this->resetProductGates();
        $this->resetTaxRuntimeState();
        parent::tearDown();
    }

    private function registerTax(string $inclusion, array $extra = []): void
    {
        $this->enableTax($inclusion, 'billing', 'item', $extra);
        (new TaxModule())->register();
    }

    private function renderPricesOnly($product): string
    {
        ob_start();
        (new ProductRenderer($product))->renderPrices();
        return ob_get_clean();
    }

    private function renderItemPriceOnly($product): string
    {
        ob_start();
        (new ProductRenderer($product))->renderItemPrice();
        return ob_get_clean();
    }

    // =========================================================================
    // Simple one-time product — the main defect from the ticket
    // =========================================================================

    public function test_simple_onetime_product_main_price_shows_the_configured_excluded_suffix(): void
    {
        $this->registerTax('excluded', [
            'price_suffix_excluded' => '+ GST',
            'price_suffix_included' => 'Inc. Tax',
        ]);

        $product = (new ProductFactory())->create();

        $html = $this->renderPricesOnly($product);

        $this->assertStringContainsString('fct-item-price', $html);
        $this->assertStringContainsString(
            '<span class="fct_price_suffix">+ GST</span>',
            $html,
            'renderPrices() main price must carry the configured excluded-tax suffix'
        );
        $this->assertSame(1, substr_count($html, 'fct_price_suffix'), 'the suffix must render exactly once, not duplicated');
    }

    public function test_simple_onetime_product_main_price_shows_the_configured_included_suffix(): void
    {
        $this->registerTax('included', [
            'price_suffix_included' => 'Inc. Tax',
        ]);

        $product = (new ProductFactory())->create();

        $html = $this->renderPricesOnly($product);

        $this->assertStringContainsString('<span class="fct_price_suffix">Inc. Tax</span>', $html);
    }

    public function test_no_suffix_when_tax_is_disabled(): void
    {
        $this->setTaxSettings(['enable_tax' => 'no']);
        (new TaxModule())->register();

        $product = (new ProductFactory())->create();

        $html = $this->renderPricesOnly($product);

        $this->assertStringNotContainsString('fct_price_suffix', $html);
    }

    public function test_no_suffix_span_when_the_configured_suffix_is_empty(): void
    {
        $this->registerTax('excluded', [
            'price_suffix_excluded' => '',
            'price_suffix'          => '',
        ]);

        $product = (new ProductFactory())->create();

        $html = $this->renderPricesOnly($product);

        $this->assertStringNotContainsString('fct_price_suffix', $html);
    }

    // =========================================================================
    // Per-variant tax_inclusion override — both directions
    // =========================================================================

    public function test_variant_override_wins_over_store_default_included_over_excluded(): void
    {
        $this->registerTax('excluded', [
            'price_suffix_excluded' => '+ GST',
            'price_suffix_included' => 'Inc. Tax',
        ]);

        $product = (new ProductFactory())
            ->withVariantAttributes(['other_info' => ['payment_type' => 'onetime', 'tax_inclusion' => 'included']])
            ->create();

        $html = $this->renderPricesOnly($product);

        $this->assertStringContainsString('<span class="fct_price_suffix">Inc. Tax</span>', $html);
        $this->assertStringNotContainsString('+ GST', $html);
    }

    public function test_variant_override_wins_over_store_default_excluded_over_included(): void
    {
        $this->registerTax('included', [
            'price_suffix_excluded' => '+ GST',
            'price_suffix_included' => 'Inc. Tax',
        ]);

        $product = (new ProductFactory())
            ->withVariantAttributes(['other_info' => ['payment_type' => 'onetime', 'tax_inclusion' => 'excluded']])
            ->create();

        $html = $this->renderPricesOnly($product);

        $this->assertStringContainsString('<span class="fct_price_suffix">+ GST</span>', $html);
        $this->assertStringNotContainsString('Inc. Tax', $html);
    }

    // =========================================================================
    // Sale / compare price
    // =========================================================================

    public function test_sale_price_still_shows_the_suffix_exactly_once(): void
    {
        $this->registerTax('excluded', ['price_suffix_excluded' => '+ GST']);

        $product = (new ProductFactory())
            ->withVariantAttributes(['item_price' => Helper::toCent(79), 'compare_price' => Helper::toCent(99)])
            ->create();

        $html = $this->renderPricesOnly($product);

        $this->assertStringContainsString('fct-compare-price', $html);
        $this->assertStringContainsString('<span class="fct_price_suffix">+ GST</span>', $html);
        $this->assertSame(1, substr_count($html, 'fct_price_suffix'));
    }

    // =========================================================================
    // Variable product — genuine price range (mixed variant tax settings)
    // and selected-variant price
    // =========================================================================

    /**
     * Not part of this ticket's bug and deliberately unchanged: PR #1767 and
     * PR #2271 both document that a genuine multi-variant min/max range never
     * gets a suffix, because no single tax_inclusion applies to the whole
     * range. This test locks in that this fix does not disturb it.
     */
    public function test_variable_product_price_range_shows_no_suffix_for_mixed_variant_tax_settings(): void
    {
        $this->registerTax('excluded', [
            'price_suffix_excluded' => '+ GST',
            'price_suffix_included' => 'Inc. Tax',
        ]);

        $product = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => Helper::PRODUCT_TYPE_SIMPLE_VARIATION])
            ->createWithVariants([
                ['variation_title' => 'Small', 'item_price' => Helper::toCent(50), 'other_info' => ['payment_type' => 'onetime', 'tax_inclusion' => 'included']],
                ['variation_title' => 'Large', 'item_price' => Helper::toCent(100), 'other_info' => ['payment_type' => 'onetime', 'tax_inclusion' => 'excluded']],
            ]);

        $html = $this->renderPricesOnly($product);

        $this->assertStringContainsString('fct-min-price', $html, 'a genuine min/max range must render');
        $this->assertStringNotContainsString(
            'fct_price_suffix',
            $html,
            'a genuine range spans variants with different tax_inclusion — no single suffix applies, so none must render (unchanged, deliberate behavior)'
        );
    }

    public function test_variable_product_selected_variant_price_shows_its_own_variant_suffix(): void
    {
        $this->registerTax('excluded', [
            'price_suffix_excluded' => '+ GST',
            'price_suffix_included' => 'Inc. Tax',
        ]);

        $product = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => Helper::PRODUCT_TYPE_SIMPLE_VARIATION])
            ->createWithVariants([
                ['variation_title' => 'Small', 'item_price' => Helper::toCent(50), 'other_info' => ['payment_type' => 'onetime', 'tax_inclusion' => 'included']],
                ['variation_title' => 'Large', 'item_price' => Helper::toCent(100), 'other_info' => ['payment_type' => 'onetime']],
            ]);

        $html = $this->renderItemPriceOnly($product);

        $this->assertStringContainsString('fct-product-item-price', $html);
        // One variant overrides to "included", the other uses the store default
        // ("excluded") — each row must reflect its own variant, not a leaked value.
        $this->assertStringContainsString('Inc. Tax', $html);
        $this->assertStringContainsString('+ GST', $html);
    }

    // =========================================================================
    // Simple subscription — main price (renderPrices) + subscription terms
    // line (renderItemPrice), matching the "Premium Care" ticket example
    // =========================================================================

    public function test_simple_subscription_product_main_price_shows_suffix(): void
    {
        $this->registerTax('excluded', ['price_suffix_excluded' => '+ GST']);

        $product = (new ProductFactory())
            ->withVariantAttributes([
                'payment_type' => 'subscription',
                'item_price'   => Helper::toCent(79),
                'other_info'   => [
                    'payment_type'    => 'subscription',
                    'repeat_interval' => 'month',
                    'interval_count'  => 1,
                ],
            ])
            ->create();

        $mainPriceHtml = $this->renderPricesOnly($product);
        $this->assertStringContainsString(
            '<span class="fct_price_suffix">+ GST</span>',
            $mainPriceHtml,
            'the main price block must carry the suffix for a simple subscription product too'
        );

        $termsLineHtml = $this->renderItemPriceOnly($product);
        $this->assertStringContainsString('fct-product-item-price', $termsLineHtml);
        $this->assertStringContainsString('<span class="fct_price_suffix">+ GST</span>', $termsLineHtml);
    }

    // =========================================================================
    // Filter leakage across sequential renders
    // =========================================================================

    public function test_sequential_product_renders_do_not_leak_a_variants_tax_suffix_into_the_next_product(): void
    {
        $this->registerTax('excluded', [
            'price_suffix_excluded' => '+ GST',
            'price_suffix_included' => 'Inc. Tax',
        ]);

        $overridden = (new ProductFactory())
            ->withVariantAttributes(['other_info' => ['payment_type' => 'onetime', 'tax_inclusion' => 'included']])
            ->create();
        $plain = (new ProductFactory())->create();

        $firstHtml = $this->renderSingleProduct($overridden);
        $secondHtml = $this->renderSingleProduct($plain);

        $this->assertStringContainsString('Inc. Tax', $firstHtml);
        $this->assertStringContainsString('+ GST', $secondHtml);
        $this->assertStringNotContainsString(
            'Inc. Tax',
            $secondHtml,
            "the first product's per-variant override must not leak into the next product's suffix"
        );
    }

    // =========================================================================
    // Shop card — must remain unaffected by this fix
    // =========================================================================

    public function test_product_card_suffix_behavior_is_unchanged(): void
    {
        $this->registerTax('excluded', ['price_suffix_excluded' => '+ GST']);

        $product = (new ProductFactory())->create();

        $html = $this->renderProductCard($product);

        $this->assertStringContainsString('<span class="fct_price_suffix">+ GST</span>', $html);
    }
}
