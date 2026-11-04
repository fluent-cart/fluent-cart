<?php

namespace Tests\Integration\Checkout;

use FluentCart\App\Hooks\Handlers\BlockEditors\Checkout\InnerBlocks\InnerBlocks;
use FluentCart\App\Services\Renderer\CheckoutRenderer;
use FluentCart\App\Models\Cart;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;
use ReflectionProperty;

/**
 * Support ticket #3600: the FluentCRM newsletter opt-in checkbox (and any other
 * third-party integration hooked onto `fluent_cart/before_payment_methods` /
 * `fluent_cart/after_payment_methods`, e.g. Turnstile and Pro's saved-card
 * picker) rendered on shortcode checkout ([fluent_cart_checkout]) but silently
 * disappeared on the Checkout block.
 *
 * Root cause: CheckoutRenderer::renderCheckoutForm() (the shortcode path) fires
 * both actions around the payment-methods wrapper, but
 * InnerBlocks::renderCheckoutPaymentMethods() (the block's
 * "Checkout Payment Methods" inner block) called
 * CheckoutRenderer::renderPaymentMethods() directly and never fired either
 * hook — so anything listening on those hooks was invisible on block checkouts.
 *
 * These tests exercise the actual block render callback (not a re-implementation
 * of it) against a real, DB-backed Cart and assert the hooks fire exactly once,
 * in the right position relative to the payment-methods wrapper, and stay
 * consistent across the empty-cart / zero-total edge cases.
 *
 * The block resolves its cart via CartHelper::getCart(), which reads the
 * `fct_cart_hash` URL param through the framework's Request object — a
 * singleton snapshotted from $_GET at bootstrap, so it will not see a $_GET
 * write made mid-test. Reflection injects the Cart directly onto InnerBlocks'
 * private $cart property instead, which exercises the exact same
 * renderCheckoutPaymentMethods() method without fighting that snapshot.
 */
class CheckoutBlockPaymentMethodHooksTest extends DatabaseTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        remove_all_actions('fluent_cart/before_payment_methods');
        remove_all_actions('fluent_cart/after_payment_methods');
        // get_block_wrapper_attributes() (called inside renderCheckoutPaymentMethods())
        // reads the block currently being rendered from here; render_block() normally
        // sets it, but a direct method call must supply it itself.
        \WP_Block_Supports::$block_to_render = ['blockName' => 'fluent-cart/checkout-payment-methods'];
    }

    public function tearDown(): void
    {
        remove_all_actions('fluent_cart/before_payment_methods');
        remove_all_actions('fluent_cart/after_payment_methods');
        \WP_Block_Supports::$block_to_render = null;
        parent::tearDown();
    }

    private function makeCart(array $overrides = []): Cart
    {
        return (new CartFactory())->create($overrides);
    }

    private function innerBlocksFor(Cart $cart): InnerBlocks
    {
        $innerBlocks = new InnerBlocks();
        $property = new ReflectionProperty(InnerBlocks::class, 'cart');
        $property->setAccessible(true);
        $property->setValue($innerBlocks, $cart);
        return $innerBlocks;
    }

    // =========================================================================
    // Reproduces the reported bug: hook fires around the block's payment
    // methods section, exactly once, in the same relative position as the
    // shortcode checkout.
    // =========================================================================

    public function test_before_and_after_payment_methods_hooks_fire_once_around_block_payment_methods(): void
    {
        $cart = $this->makeCart([
            'cart_data' => [CartFactory::makeCartItem(['unit_price' => 2999])],
        ]);

        $beforeCount = 0;
        $afterCount = 0;
        add_action('fluent_cart/before_payment_methods', function ($data) use (&$beforeCount) {
            $beforeCount++;
            echo '<!--MARKER_BEFORE-->';
        });
        add_action('fluent_cart/after_payment_methods', function ($data) use (&$afterCount) {
            $afterCount++;
            echo '<!--MARKER_AFTER-->';
        });

        $html = $this->innerBlocksFor($cart)->renderCheckoutPaymentMethods([], '', null);

        $this->assertSame(1, $beforeCount, 'before_payment_methods must fire exactly once on the block');
        $this->assertSame(1, $afterCount, 'after_payment_methods must fire exactly once on the block');
        $this->assertSame(1, substr_count($html, 'MARKER_BEFORE'), 'the before-hook output must not be duplicated');
        $this->assertSame(1, substr_count($html, 'MARKER_AFTER'), 'the after-hook output must not be duplicated');

        // strpos() against the wrapper's opening-tag attribute string only proves a
        // marker comes after the *opening* tag, not after the wrapper's closing
        // </div> — a hook fired just inside the div would still pass that check.
        // Parse the DOM instead: a marker found as a direct child of __root__ is a
        // sibling of the wrapper div, i.e. genuinely outside it. A marker nested
        // inside the wrapper would instead be its descendant, not root's direct
        // child, so it would never turn up in the loop below at all.
        $dom = new \DOMDocument();
        $previousLibxmlErrorSetting = libxml_use_internal_errors(true);
        $dom->loadHTML('<div id="__root__">' . $html . '</div>');
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlErrorSetting);
        $xpath = new \DOMXPath($dom);

        $wrapper = $xpath->query('//div[@data-fluent-cart-checkout-payment-methods]')->item(0);
        $this->assertNotNull($wrapper, 'payment methods wrapper div must still be rendered');

        $root = $xpath->query('//div[@id="__root__"]')->item(0);
        $wrapperIndex = $beforeIndex = $afterIndex = null;
        foreach (iterator_to_array($root->childNodes) as $index => $node) {
            if ($node === $wrapper) {
                $wrapperIndex = $index;
            }
            if ($node->nodeType === XML_COMMENT_NODE) {
                if (trim($node->nodeValue) === 'MARKER_BEFORE') {
                    $beforeIndex = $index;
                }
                if (trim($node->nodeValue) === 'MARKER_AFTER') {
                    $afterIndex = $index;
                }
            }
        }

        $this->assertNotNull($beforeIndex, 'before_payment_methods output must be a sibling of the wrapper, not nested inside it');
        $this->assertNotNull($afterIndex, 'after_payment_methods output must be a sibling of the wrapper, not nested inside it');
        $this->assertLessThan($wrapperIndex, $beforeIndex, 'before_payment_methods must render before the payment-methods wrapper');
        $this->assertGreaterThan($wrapperIndex, $afterIndex, 'after_payment_methods must render after the payment-methods wrapper, i.e. outside its closing tag');
    }

    /**
     * The hook payload contract (`['cart' => $cart]`) must match what the
     * shortcode renderer sends — third-party listeners (FluentCRM, Turnstile,
     * Pro's saved-card selector) read `$data['cart']`.
     */
    public function test_hook_payload_carries_the_same_cart_the_block_is_rendering(): void
    {
        $cart = $this->makeCart([
            'cart_data' => [CartFactory::makeCartItem(['unit_price' => 1500])],
        ]);

        $receivedBeforeCart = null;
        $receivedAfterCart = null;
        add_action('fluent_cart/before_payment_methods', function ($data) use (&$receivedBeforeCart) {
            $receivedBeforeCart = $data['cart'] ?? null;
        });
        add_action('fluent_cart/after_payment_methods', function ($data) use (&$receivedAfterCart) {
            $receivedAfterCart = $data['cart'] ?? null;
        });

        $this->innerBlocksFor($cart)->renderCheckoutPaymentMethods([], '', null);

        // Cart's primary key is cart_hash (see database/Migrations/CartMigrator.php),
        // not id — the model has no id column at all, so comparing ->id would
        // silently compare null to null. Assert the actual object identity instead.
        $this->assertSame($cart, $receivedBeforeCart);
        $this->assertSame($cart, $receivedAfterCart);
    }

    // =========================================================================
    // Parity with the shortcode checkout path — proves the fix did not change
    // (and the block now matches) CheckoutRenderer::renderCheckoutForm().
    // =========================================================================

    public function test_shortcode_checkout_form_fires_the_same_hooks_exactly_once(): void
    {
        $cart = $this->makeCart([
            'cart_data' => [CartFactory::makeCartItem(['unit_price' => 2999])],
        ]);

        $beforeCount = 0;
        $afterCount = 0;
        add_action('fluent_cart/before_payment_methods', function () use (&$beforeCount) {
            $beforeCount++;
        });
        add_action('fluent_cart/after_payment_methods', function () use (&$afterCount) {
            $afterCount++;
        });

        ob_start();
        (new CheckoutRenderer($cart))->renderCheckoutForm();
        ob_get_clean();

        $this->assertSame(1, $beforeCount, 'shortcode checkout must be unaffected by the block fix');
        $this->assertSame(1, $afterCount, 'shortcode checkout must be unaffected by the block fix');
    }

    // =========================================================================
    // Edge cases called out in the ticket: empty cart, zero-total cart.
    // =========================================================================

    public function test_hooks_do_not_fire_when_cart_has_no_items(): void
    {
        $cart = $this->makeCart(['cart_data' => []]);

        $beforeFired = false;
        $afterFired = false;
        add_action('fluent_cart/before_payment_methods', function () use (&$beforeFired) {
            $beforeFired = true;
        });
        add_action('fluent_cart/after_payment_methods', function () use (&$afterFired) {
            $afterFired = true;
        });

        $html = $this->innerBlocksFor($cart)->renderCheckoutPaymentMethods([], '', null);

        $this->assertSame('', $html, 'an empty cart renders nothing, matching the block\'s other checkout sub-blocks');
        $this->assertFalse($beforeFired, 'no before-hook should fire for a block that rendered nothing');
        $this->assertFalse($afterFired, 'no after-hook should fire for a block that rendered nothing');
    }

    public function test_hooks_still_fire_once_for_a_zero_total_cart(): void
    {
        // A fully-discounted / free item: cart has data, but getEstimatedTotal() is 0.
        $cart = $this->makeCart([
            'cart_data' => [CartFactory::makeCartItem(['unit_price' => 0])],
        ]);

        $beforeCount = 0;
        $afterCount = 0;
        add_action('fluent_cart/before_payment_methods', function () use (&$beforeCount) {
            $beforeCount++;
        });
        add_action('fluent_cart/after_payment_methods', function () use (&$afterCount) {
            $afterCount++;
        });

        $html = $this->innerBlocksFor($cart)->renderCheckoutPaymentMethods([], '', null);

        $this->assertSame(1, $beforeCount, 'a newsletter opt-in must still be offered on a free checkout');
        $this->assertSame(1, $afterCount);
        $this->assertStringContainsString('data-fluent-cart-checkout-payment-methods', $html);
    }

    // =========================================================================
    // Calling the block render callback twice (e.g. an editor preview refresh)
    // must not accumulate duplicate hook output — guards the "fragment refresh
    // preserves the checkbox without duplication" requirement at the render
    // level (the AJAX fragment path itself never touches these hooks: it
    // targets `[data-fluent-cart-checkout-payment-methods]` with innerHTML,
    // which sits *inside* where these hooks fire, so it cannot duplicate them).
    // =========================================================================

    public function test_rendering_the_block_twice_does_not_accumulate_hook_firings(): void
    {
        $cart = $this->makeCart([
            'cart_data' => [CartFactory::makeCartItem(['unit_price' => 2999])],
        ]);

        $beforeCount = 0;
        $afterCount = 0;
        add_action('fluent_cart/before_payment_methods', function () use (&$beforeCount) {
            $beforeCount++;
        });
        add_action('fluent_cart/after_payment_methods', function () use (&$afterCount) {
            $afterCount++;
        });

        $innerBlocks = $this->innerBlocksFor($cart);
        $innerBlocks->renderCheckoutPaymentMethods([], '', null);
        $innerBlocks->renderCheckoutPaymentMethods([], '', null);

        $this->assertSame(2, $beforeCount, 'each independent render must fire once — two renders, two firings, no duplication within a single render');
        $this->assertSame(2, $afterCount, 'each independent render must fire once — two renders, two firings, no duplication within a single render');
    }
}
