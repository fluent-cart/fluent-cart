<?php

namespace Tests\Integration\Services\Renderer;

use FluentCart\App\Helpers\Helper;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\RendersProductMarkup;

/**
 * RenderGate lets an integration suppress a product display region without
 * overriding a template or hiding it with CSS.
 *
 * The distinction that matters throughout: the existing *_text filters can only
 * blank a label, which leaves an empty styled button in the DOM — a wasted click
 * target and a hole in the layout. These gates OMIT the markup, so every
 * assertion here checks for the absence of the element, not of its text.
 *
 * One hook name per region serves every surface; listeners narrow by the payload
 * (`scope` = product_card | single_product) rather than by hook name.
 *
 * Spec: Cases/product-render-gates.md
 */
class ProductRenderGateTest extends WPTestCase
{
    use RendersProductMarkup;

    protected function tearDown(): void
    {
        $this->resetProductGates();
        parent::tearDown();
    }

    /**
     * Spec: Cases/product-render-gates.md [1]
     *   "A shop product card leaves out the price when the price region is gated off."
     */
    public function test_card_omits_the_price_when_the_price_region_is_gated_off(): void
    {
        $product = (new ProductFactory())->create();

        $this->assertStringContainsString(
            'fct-product-card-prices',
            $this->renderProductCard($product),
            'the card should render a price element before any gate is applied'
        );

        $this->gateOff('price');

        $this->assertStringNotContainsString(
            'fct-product-card-prices',
            $this->renderProductCard($product),
            'show_price returned false but the price element is still in the card markup'
        );
    }

    /**
     * Spec: Cases/product-render-gates.md [2]
     *   "A shop product card leaves out the title when the title region is gated off."
     */
    public function test_card_omits_the_title_when_the_title_region_is_gated_off(): void
    {
        $product = (new ProductFactory())->create();

        $this->gateOff('title');

        $this->assertStringNotContainsString(
            'fct-product-card-title',
            $this->renderProductCard($product),
            'show_title returned false but the title element is still in the card markup'
        );
    }

    /**
     * Spec: Cases/product-render-gates.md [3]
     *   "A shop product card leaves out the image when the image region is gated off."
     */
    public function test_card_omits_the_image_when_the_image_region_is_gated_off(): void
    {
        $product = (new ProductFactory())->create();

        $this->gateOff('image');

        $this->assertStringNotContainsString(
            'fct-product-card-image',
            $this->renderProductCard($product),
            'show_image returned false but the image element is still in the card markup'
        );
    }

    /**
     * Spec: Cases/product-render-gates.md [4]
     *   "A shop product card leaves out the Add to Cart button when that button is gated off."
     */
    public function test_card_omits_the_add_to_cart_button_when_that_button_is_gated_off(): void
    {
        $product = (new ProductFactory())->create();

        $this->assertStringContainsString(
            'fluent-cart-add-to-cart-button',
            $this->renderProductCard($product),
            'a simple product card should render an Add to Cart button before any gate is applied'
        );

        $this->gateOff('add_to_cart_button');

        $this->assertStringNotContainsString(
            'fluent-cart-add-to-cart-button',
            $this->renderProductCard($product),
            'show_add_to_cart_button returned false but the button is still in the card markup'
        );
    }

    /**
     * Spec: Cases/product-render-gates.md [5]
     *   "Gating off the whole action row removes the buttons inside it."
     */
    public function test_card_action_row_gate_removes_the_buttons_inside_it(): void
    {
        $product = (new ProductFactory())->create();

        $this->gateOff('actions');

        $html = $this->renderProductCard($product);

        $this->assertStringNotContainsString(
            'fluent-cart-add-to-cart-button',
            $html,
            'show_actions returned false but the Add to Cart button survived'
        );
        $this->assertStringContainsString(
            'fct-product-card-title',
            $html,
            'show_actions should gate only the action row, not the rest of the card'
        );
    }

    /**
     * Spec: Cases/product-render-gates.md [6]
     *   "The catch-all gate hides every region of a product card at once."
     */
    public function test_catch_all_gate_hides_every_card_region_at_once(): void
    {
        $product = (new ProductFactory())->create();

        $this->gateWith('fluent_cart/product/show_section', '__return_false');

        $html = $this->renderProductCard($product);

        foreach ([
            'fct-product-card-prices',
            'fct-product-card-title',
            'fct-product-card-image',
            'fluent-cart-add-to-cart-button',
        ] as $marker) {
            $this->assertStringNotContainsString(
                $marker,
                $html,
                'show_section returned false but ' . $marker . ' is still in the card markup'
            );
        }
    }

    /**
     * Spec: Cases/product-render-gates.md [7]
     *   "A gate can be narrowed to the shop card while the single product page keeps the region."
     */
    public function test_a_gate_can_be_narrowed_to_the_card_scope_only(): void
    {
        $product = (new ProductFactory())->create();

        $this->gateWith('fluent_cart/product/show_price', function ($show, $context) {
            return $context['scope'] === 'product_card' ? false : $show;
        });

        $this->assertStringNotContainsString(
            'fct-product-card-prices',
            $this->renderProductCard($product),
            'the card-scoped listener should have removed the card price'
        );
        $this->assertStringContainsString(
            'fct-product-prices',
            $this->renderSingleProduct($product),
            'the same listener should leave the single product page price alone'
        );
    }

    /**
     * Spec: Cases/product-render-gates.md [8]
     *   "Hiding the purchase buttons still leaves a variable product card linking through to its options."
     */
    public function test_hiding_purchase_buttons_keeps_the_view_options_link_on_a_variable_card(): void
    {
        $product = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => Helper::PRODUCT_TYPE_SIMPLE_VARIATION])
            ->createWithVariants([
                ['variation_title' => 'Small', 'item_price' => Helper::toCent(10)],
                ['variation_title' => 'Large', 'item_price' => Helper::toCent(20)],
            ]);

        $this->gateOff('add_to_cart_button');
        $this->gateOff('buy_now_button');

        $this->assertStringContainsString(
            'fct-single-product-card-view-button',
            $this->renderProductCard($product),
            'gating the purchase buttons should hide the buying, not the browsing — the '
            . 'View Options link through to the product page must survive'
        );
    }

    /**
     * Spec: Cases/product-render-gates.md [9]
     *   "The single product page leaves out the price when the price region is gated off."
     */
    public function test_single_product_page_omits_the_price_when_gated_off(): void
    {
        $product = (new ProductFactory())->create();

        $this->assertStringContainsString(
            'fct-product-prices',
            $this->renderSingleProduct($product),
            'the single product page should render a price before any gate is applied'
        );

        $this->gateOff('price');

        $this->assertStringNotContainsString(
            'fct-product-prices',
            $this->renderSingleProduct($product),
            'show_price returned false but the single product page price survived'
        );
    }

    /**
     * Spec: Cases/product-render-gates.md [10]
     *   "The single product page leaves out the quantity picker when it is gated off."
     */
    public function test_single_product_page_omits_the_quantity_picker_when_gated_off(): void
    {
        $product = (new ProductFactory())->create();

        $this->gateOff('quantity');

        $this->assertStringNotContainsString(
            'fct-product-quantity-container',
            $this->renderSingleProduct($product),
            'show_quantity returned false but the quantity picker survived'
        );
    }

    /**
     * Spec: Cases/product-render-gates.md [11]
     *   "Gating off the action row on the single product page removes both purchase buttons."
     */
    public function test_single_product_action_row_gate_removes_both_purchase_buttons(): void
    {
        $product = (new ProductFactory())->create();

        $this->gateOff('actions');

        $html = $this->renderSingleProduct($product);

        $this->assertStringNotContainsString(
            'fluent-cart-add-to-cart-button',
            $html,
            'show_actions returned false but the Add to Cart button survived'
        );
        $this->assertStringNotContainsString(
            'fluent-cart-direct-checkout-button',
            $html,
            'show_actions returned false but the Buy Now button survived'
        );
    }

    /**
     * Spec: Cases/product-render-gates.md [12]
     *   "A listener cannot re-enable one button once the whole action row is gated off."
     */
    public function test_action_row_gate_overrules_a_listener_re_enabling_one_button(): void
    {
        $product = (new ProductFactory())->create();

        $this->gateOff('actions');
        $this->gateOn('add_to_cart_button');

        $this->assertStringNotContainsString(
            'fluent-cart-add-to-cart-button',
            $this->renderSingleProduct($product),
            'a button was resurrected into an action row that is not being rendered'
        );
    }

    /**
     * Spec: Cases/product-render-gates.md [13]
     *   "Gating off the buy section removes the purchase buttons and the quantity picker together."
     */
    public function test_buy_section_gate_removes_the_buttons_and_quantity_together(): void
    {
        $product = (new ProductFactory())->create();

        $this->gateOff('buy_section');

        $html = $this->renderSingleProduct($product);

        $this->assertStringNotContainsString(
            'fct_buy_section',
            $html,
            'show_buy_section returned false but the buy section wrapper survived'
        );
        $this->assertStringNotContainsString(
            'fct-product-quantity-container',
            $html,
            'the quantity picker lives inside the buy section and should have gone with it'
        );
        $this->assertStringContainsString(
            'fct-product-title',
            $html,
            'show_buy_section should gate the purchase container, not the whole page'
        );
    }

    /**
     * Spec: Cases/product-render-gates.md [14]
     *   "The card wrapper announces itself before and after rendering, carrying the product."
     */
    public function test_card_wrapper_fires_before_and_after_actions_carrying_the_product(): void
    {
        $product = (new ProductFactory())->create();
        $seen = [];

        foreach (['before_card', 'after_card'] as $point) {
            add_action('fluent_cart/product/group/' . $point, function ($data) use ($point, &$seen) {
                $seen[$point] = isset($data['product']) ? $data['product']->ID : null;
            });
        }

        $this->renderProductCard($product);

        $this->assertSame(
            ['before_card' => $product->ID, 'after_card' => $product->ID],
            $seen,
            'both card wrapper actions should fire once with the rendered product in the payload'
        );
    }

    /**
     * Spec: Cases/product-render-gates.md [15]
     *   "The title region announces itself before and after rendering on both surfaces."
     */
    public function test_title_region_fires_before_and_after_actions_on_both_surfaces(): void
    {
        $product = (new ProductFactory())->create();
        $seen = [];

        foreach ([
            'fluent_cart/product/group/before_title_block',
            'fluent_cart/product/group/after_title_block',
            'fluent_cart/product/single/before_title_block',
            'fluent_cart/product/single/after_title_block',
        ] as $tag) {
            add_action($tag, function ($data) use ($tag, &$seen) {
                $seen[$tag] = isset($data['scope']) ? $data['scope'] : null;
            });
        }

        $this->renderProductCard($product);
        $this->renderSingleProduct($product);

        $this->assertSame([
            'fluent_cart/product/group/before_title_block'  => 'product_card',
            'fluent_cart/product/group/after_title_block'   => 'product_card',
            'fluent_cart/product/single/before_title_block' => 'single_product',
            'fluent_cart/product/single/after_title_block'  => 'single_product',
        ], $seen, 'each title action should fire with the scope of the surface that rendered it');
    }

    /**
     * Spec: Cases/product-render-gates.md [16]
     *   "Custom CSS classes added by a listener reach the product card wrapper."
     */
    public function test_custom_css_classes_reach_the_product_card_wrapper(): void
    {
        $product = (new ProductFactory())->create();

        add_filter('fluent_cart/product/card_classes', function ($classes) {
            $classes[] = 'my-custom-card';
            $classes[] = 'sale badge';

            return $classes;
        });

        preg_match('/<article[^>]*class="([^"]*)"/', $this->renderProductCard($product), $matches);
        $rendered = isset($matches[1]) ? explode(' ', $matches[1]) : [];

        $this->assertContains('fct-product-card', $rendered, 'the core card class should survive the filter');
        $this->assertContains('my-custom-card', $rendered, 'the listener class should reach the wrapper');
        $this->assertContains('sale', $rendered, 'a space-separated string should become separate classes');
        $this->assertContains('badge', $rendered, 'a space-separated string should become separate classes');
    }

    /**
     * Spec: Cases/product-render-gates.md [17]
     *   "A class name containing markup is neutralised before it reaches the card wrapper."
     */
    public function test_a_class_name_containing_markup_is_neutralised(): void
    {
        $product = (new ProductFactory())->create();

        add_filter('fluent_cart/product/card_classes', function ($classes) {
            $classes[] = '"><script>alert(1)</script>';

            return $classes;
        });

        $html = $this->renderProductCard($product);

        $this->assertStringNotContainsString(
            '<script',
            $html,
            'a card class escaped its attribute and opened a new element'
        );
    }
}
