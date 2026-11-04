<?php

namespace Tests\Integration\Renderer;

use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\Models\Cart;
use FluentCart\App\Services\Renderer\CartSummaryRender;
use FluentCart\App\Services\Renderer\CheckoutRenderContext;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;

/**
 * The published checkout extension points.
 *
 * Two halves to the contract, and both matter: a listener can genuinely take
 * over, and with no listener registered nothing changes. The second half is the
 * one that protects every store that will never install an add-on, so each
 * filter is asserted in both directions.
 *
 * Documented in dev-docs/checkout-extension-points.md.
 */
class CheckoutExtensionPointsTest extends DatabaseTestCase
{
    public function tearDown(): void
    {
        remove_all_filters('fluent_cart/cart/hash_resolvable_cart_groups');
        remove_all_filters('fluent_cart/checkout/cart_summary_html');
        remove_all_filters('fluent_cart/checkout/route_response');
        CheckoutRenderContext::reset();

        parent::tearDown();
    }

    public function test_cart_hash_resolves_only_instant_carts_by_default()
    {
        $cart = (new CartFactory())->create([
            'cart_group' => 'my_group',
            'cart_data'  => [CartFactory::makeCartItem()],
        ]);

        CartResource::resetCartCache();

        $this->assertNull(
            CartResource::get(['hash' => $cart->cart_hash]),
            'A group core does not ship must not be resolvable by hash without a listener.'
        );
    }

    public function test_a_listener_can_widen_the_hash_resolvable_cart_groups()
    {
        $cart = (new CartFactory())->create([
            'cart_group' => 'my_group',
            'cart_data'  => [CartFactory::makeCartItem()],
        ]);

        add_filter('fluent_cart/cart/hash_resolvable_cart_groups', function ($groups) {
            $groups[] = 'my_group';
            return $groups;
        });

        CartResource::resetCartCache();
        $resolved = CartResource::get(['hash' => $cart->cart_hash]);

        $this->assertNotNull($resolved, 'A listed group must resolve by hash.');
        $this->assertSame(
            $cart->cart_hash,
            $resolved->cart_hash,
            'The resolved cart must be the one the hash names.'
        );
    }

    public function test_a_listener_returning_nothing_falls_back_to_the_shipped_groups()
    {
        $cart = (new CartFactory())->create([
            'cart_group' => 'my_group',
            'cart_data'  => [CartFactory::makeCartItem()],
        ]);

        // An empty list must not be read as "every group is resolvable".
        add_filter('fluent_cart/cart/hash_resolvable_cart_groups', function () {
            return [];
        });

        CartResource::resetCartCache();

        $this->assertNull(
            CartResource::get(['hash' => $cart->cart_hash]),
            'An empty filter result must fall back to core\'s groups, not open every group.'
        );
    }

    public function test_cart_summary_renders_core_markup_without_a_listener()
    {
        $cart = (new CartFactory())->create([
            'cart_data' => [CartFactory::makeCartItem()],
        ]);

        ob_start();
        (new CartSummaryRender($cart))->render(true);
        $html = ob_get_clean();

        $this->assertStringContainsString(
            'data-fluent-cart-checkout-page-cart-items-wrapper',
            $html,
            'With no listener the summary must be core\'s own markup.'
        );
    }

    public function test_a_listener_can_replace_the_whole_cart_summary()
    {
        $cart = (new CartFactory())->create([
            'cart_data' => [CartFactory::makeCartItem()],
        ]);

        add_filter('fluent_cart/checkout/cart_summary_html', function ($html, $args) {
            return $args['context'] === 'summary' ? '<div id="replaced-summary"></div>' : $html;
        }, 10, 2);

        ob_start();
        (new CartSummaryRender($cart))->render(true);
        $html = ob_get_clean();

        $this->assertSame(
            '<div id="replaced-summary"></div>',
            $html,
            'A string returned for the summary context must replace core\'s markup entirely.'
        );
    }

    public function test_a_listener_can_replace_just_the_line_item_list()
    {
        $cart = (new CartFactory())->create([
            'cart_data' => [CartFactory::makeCartItem()],
        ]);

        add_filter('fluent_cart/checkout/cart_summary_html', function ($html, $args) {
            return $args['context'] === 'items' ? '<div id="replaced-items"></div>' : $html;
        }, 10, 2);

        ob_start();
        (new CartSummaryRender($cart))->renderItemsLists();
        $itemsHtml = ob_get_clean();

        $this->assertSame(
            '<div id="replaced-items"></div>',
            $itemsHtml,
            'The items context must be replaceable on its own, for the inner block that renders it directly.'
        );
    }

    public function test_taking_over_the_summary_does_not_also_fire_the_items_context()
    {
        $cart = (new CartFactory())->create([
            'cart_data' => [CartFactory::makeCartItem()],
        ]);

        $contexts = [];
        add_filter('fluent_cart/checkout/cart_summary_html', function ($html, $args) use (&$contexts) {
            $contexts[] = $args['context'];
            return $args['context'] === 'summary' ? '<div id="replaced-summary"></div>' : $html;
        }, 10, 2);

        ob_start();
        (new CartSummaryRender($cart))->render(true);
        ob_get_clean();

        $this->assertSame(
            ['summary'],
            $contexts,
            'Taking over render() must return before renderItemsLists() is reached, so the two never double-fire.'
        );
    }

    public function test_route_response_receives_its_context_as_an_array()
    {
        $seen = null;

        add_filter('fluent_cart/checkout/route_response', function ($result, $args) use (&$seen) {
            $seen = $args;
            return $result;
        }, 10, 2);

        apply_filters('fluent_cart/checkout/route_response', null, ['action' => 'my_verb']);

        $this->assertIsArray(
            $seen,
            'Filter context travels as one read-only array, never as a bare scalar — see hooks-filters.md.'
        );
        $this->assertSame(
            'my_verb',
            $seen['action'] ?? null,
            'The requested fc_checkout_action must reach listeners under the `action` key.'
        );
    }

    public function test_render_context_exposes_the_cart_and_meta_during_the_pass()
    {
        $cart = (new CartFactory())->create();

        $seen = CheckoutRenderContext::rendering(
            $cart,
            ['surface' => 'checkout-shortcode', 'attributes' => ['a' => 'b']],
            function () {
                return [
                    'hash'    => CheckoutRenderContext::cart()->cart_hash,
                    'surface' => CheckoutRenderContext::meta()['surface'],
                ];
            }
        );

        $this->assertSame($cart->cart_hash, $seen['hash'], 'The context must expose the cart it was given.');
        $this->assertSame('checkout-shortcode', $seen['surface'], 'The context must expose its meta.');
    }

    public function test_render_context_is_empty_outside_a_pass()
    {
        $cart = (new CartFactory())->create();

        CheckoutRenderContext::rendering($cart, ['surface' => 'checkout-block'], function () {
            return null;
        });

        $this->assertNull(
            CheckoutRenderContext::cart(),
            'The context must not outlive the render pass that set it.'
        );
    }

    public function test_render_context_is_restored_when_the_callback_throws()
    {
        $outer = (new CartFactory())->create();
        $inner = (new CartFactory())->create();

        $threw = false;

        CheckoutRenderContext::rendering($outer, ['surface' => 'checkout-block'], function () use ($inner, &$threw) {
            try {
                CheckoutRenderContext::rendering($inner, ['surface' => 'checkout-shortcode'], function () {
                    throw new \RuntimeException('boom');
                });
            } catch (\RuntimeException $e) {
                $threw = true;
            }

            return null;
        });

        $this->assertTrue($threw, 'The exception must propagate rather than be swallowed.');
        $this->assertNull(
            CheckoutRenderContext::cart(),
            'A throwing nested pass must still restore the context, or every later render is mislabelled.'
        );
    }
}
