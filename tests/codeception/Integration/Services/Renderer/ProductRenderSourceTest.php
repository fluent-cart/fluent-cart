<?php

namespace Tests\Integration\Services\Renderer;

use FluentCart\App\Services\Renderer\RenderContext;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\RendersProductMarkup;

/**
 * Every product hook payload says which builder asked for the render.
 *
 * RenderGate's `scope` answers "which region of the page"; this answers "which
 * builder is drawing it", so a listener can gate a region on the shop grid
 * without touching the same region elsewhere.
 *
 * Gutenberg is detected rather than declared — WordPress already tracks the
 * block being rendered — so no block, and no block handler, had to be touched
 * for it to work. Shortcodes declare themselves in the single closure they all
 * register through. Anything else reports `unknown` until it opts in through
 * the `fluent_cart/product/render_source` filter, which is what Bricks and Divi
 * would use. Nothing inspects the call stack.
 *
 * Spec: Cases/product-render-gates.md
 */
class ProductRenderSourceTest extends WPTestCase
{
    use RendersProductMarkup;

    protected function tearDown(): void
    {
        $this->resetProductGates();
        \WP_Block_Supports::$block_to_render = null;
        parent::tearDown();
    }

    /**
     * Spec: Cases/product-render-gates.md [18]
     *   "A render whose caller cannot be identified is reported as unknown rather than guessed at."
     */
    public function test_an_unidentifiable_caller_is_reported_as_unknown(): void
    {
        $product = (new ProductFactory())->create();
        $recorded = &$this->recordRenderSources();

        $this->renderProductCard($product);

        $this->assertSame(
            ['unknown/'],
            $recorded,
            'a direct render should report unknown rather than guessing from the call stack'
        );
    }

    /**
     * Spec: Cases/product-render-gates.md [19]
     *   "A Gutenberg block reports itself by block name without any block having to say so."
     */
    public function test_a_gutenberg_block_reports_itself_without_being_told_to(): void
    {
        $product = (new ProductFactory())->create();
        $recorded = &$this->recordRenderSources();

        $output = do_blocks(
            '<!-- wp:fluent-cart/product-card {"query_type":"custom","product_id":' . $product->ID . '} /-->'
        );

        $this->assertNotEmpty($output, 'the block should have rendered a card to report on');
        $this->assertSame(
            ['gutenberg/fluent-cart/product-card'],
            $recorded,
            'WordPress already tracks the rendering block, so no block handler needs wiring'
        );
    }

    /**
     * Spec: Cases/product-render-gates.md [20]
     *   "A shortcode reports itself by name."
     */
    public function test_a_shortcode_reports_itself_by_name(): void
    {
        $product = (new ProductFactory())->create();
        $recorded = &$this->recordRenderSources();

        $output = do_shortcode('[fluent_cart_product_card product_id="' . $product->ID . '"]');

        $this->assertNotEmpty($output, 'the shortcode should have rendered a card to report on');
        $this->assertSame(
            ['shortcode/fluent_cart_product_card'],
            $recorded,
            'every shortcode registers through one closure, which is what declares the source'
        );
    }

    /**
     * Spec: Cases/product-render-gates.md [21]
     *   "A shortcode rendering inside a block is reported as the shortcode, and the block resumes afterwards."
     */
    public function test_a_shortcode_inside_a_block_wins_over_the_block(): void
    {
        \WP_Block_Supports::$block_to_render = ['blockName' => 'fluent-cart/product-card'];

        $this->assertSame(
            'gutenberg',
            RenderContext::current()['source'],
            'a block on its own should be detected'
        );

        RenderContext::declaring('shortcode', 'fluent_cart_shop', function () {
            $this->assertSame(
                'shortcode',
                RenderContext::current()['source'],
                'the shortcode is the more useful answer while it is the one rendering'
            );
        });

        $this->assertSame(
            'gutenberg',
            RenderContext::current()['source'],
            'the block should resume once the nested shortcode returns'
        );
    }

    /**
     * Spec: Cases/product-render-gates.md [22]
     *   "A render that throws still leaves the next render correctly labelled."
     */
    public function test_a_throwing_render_leaves_the_caller_balanced(): void
    {
        try {
            RenderContext::declaring('shortcode', 'fluent_cart_shop', function () {
                throw new \RuntimeException('render blew up');
            });
        } catch (\RuntimeException $exception) {
            // expected — the point is what the next render sees
        }

        $this->assertSame(
            RenderContext::SOURCE_UNKNOWN,
            RenderContext::current()['source'],
            'a leaked declaration would mislabel every later render in the request'
        );
    }

    /**
     * Spec: Cases/product-render-gates.md [23]
     *   "A builder core cannot detect can opt in to being reported correctly."
     */
    public function test_an_undetectable_builder_can_opt_in(): void
    {
        $product = (new ProductFactory())->create();
        $recorded = &$this->recordRenderSources();

        // What Bricks or Divi would add from their own bootstrap.
        $this->gateWith('fluent_cart/product/render_source', function () {
            return ['source' => RenderContext::SOURCE_BRICKS, 'name' => 'fct-products'];
        });

        $this->renderProductCard($product);

        $this->assertSame(
            ['bricks/fct-products'],
            $recorded,
            'one add_filter should be the entire cost of opting in'
        );
    }

    /**
     * Spec: Cases/product-render-gates.md [24]
     *   "The injection actions carry the calling builder too, not just the visibility filters."
     */
    public function test_injection_actions_carry_the_calling_builder_too(): void
    {
        $product = (new ProductFactory())->create();
        $seen = [];

        foreach (['before_card', 'before_image_block', 'before_title_block'] as $point) {
            add_action('fluent_cart/product/group/' . $point, function ($data) use ($point, &$seen) {
                $seen[$point] = $data['source'] . '/' . $data['source_name'];
            });
        }

        RenderContext::declaring(RenderContext::SOURCE_DIVI, 'fc_product_card', function () use ($product) {
            $this->renderProductCard($product);
        });

        $this->assertSame([
            'before_card'        => 'divi/fc_product_card',
            'before_image_block' => 'divi/fc_product_card',
            'before_title_block' => 'divi/fc_product_card',
        ], $seen, 'a listener injecting markup needs the same caller information as one gating it');
    }

    /**
     * Spec: Cases/product-render-gates.md [25]
     *   "A caller that already knows better is not overwritten."
     */
    public function test_an_explicit_source_is_not_overwritten(): void
    {
        RenderContext::declaring(RenderContext::SOURCE_SHORTCODE, 'fluent_cart_shop', function () {
            $decorated = RenderContext::decorate([
                'source'      => RenderContext::SOURCE_DIVI,
                'source_name' => 'fc_product_card',
            ]);

            $this->assertSame(
                RenderContext::SOURCE_DIVI,
                $decorated['source'],
                'decorate() should fill gaps, not overrule a caller that set the key itself'
            );
        });
    }
}
