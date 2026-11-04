<?php

namespace Tests\Integration\Checkout;

use FluentCart\App\Models\Cart;
use FluentCart\App\Services\Renderer\CheckoutRenderer;
use FluentCart\App\Services\Renderer\ModalCheckoutRenderer;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Doubles\CheckoutGateway;
use Tests\Support\Factory\CartFactory;

/**
 * `fluent_cart/checkout/selected_payment_method` decides which gateway radio
 * the payment-methods list renders as checked.
 *
 * Why it exists: Pro's saved-card picker renders its own `_fct_pay_method`
 * radio above this list. Core always checked the first gateway, and that later
 * radio won the shared group — so the page loaded with a gateway selected and
 * JS switched to the saved card after window load (a visible blink, and a
 * fragment refresh re-checked the gateway). Returning a route that is not in
 * the list leaves every gateway unchecked, so the add-on's server-checked radio
 * stands.
 *
 * Both renderers pick the selection independently (shortcode/block page and the
 * modal), so both are exercised.
 */
class CheckoutSelectedPaymentMethodFilterTest extends DatabaseTestCase
{
    private $methodsFilter;

    public function setUp(): void
    {
        parent::setUp();
        // One deterministic gateway, whatever the test store has active.
        $this->methodsFilter = function () {
            return [new CheckoutGateway()];
        };
        add_filter('fluent_cart/checkout_active_payment_methods', $this->methodsFilter, 999);
    }

    public function tearDown(): void
    {
        remove_filter('fluent_cart/checkout_active_payment_methods', $this->methodsFilter, 999);
        remove_all_filters('fluent_cart/checkout/selected_payment_method');
        parent::tearDown();
    }

    private function makeCart(): Cart
    {
        return (new CartFactory())->create([
            'cart_data' => [CartFactory::makeCartItem(['unit_price' => 2999])],
        ]);
    }

    /**
     * Routes of the `_fct_pay_method` radios rendered with a real `checked`
     * attribute (not `aria-checked`).
     */
    private function checkedRoutes(string $html): array
    {
        preg_match_all('/<input[^>]*name="_fct_pay_method"[^>]*>/', $html, $inputs);

        $routes = [];
        foreach ($inputs[0] as $input) {
            if (preg_match('/\schecked=/', $input) && preg_match('/value="([^"]+)"/', $input, $value)) {
                $routes[] = $value[1];
            }
        }

        return $routes;
    }

    private function renderPage(Cart $cart): string
    {
        ob_start();
        (new CheckoutRenderer($cart))->renderPaymentMethods();
        return ob_get_clean();
    }

    private function renderModal(Cart $cart): string
    {
        ob_start();
        (new ModalCheckoutRenderer($cart))->renderPaymentMethods();
        return ob_get_clean();
    }

    public function test_without_a_listener_the_first_gateway_is_checked(): void
    {
        $cart = $this->makeCart();

        $this->assertSame(['wp_browser_stub'], $this->checkedRoutes($this->renderPage($cart)));
        $this->assertSame(['wp_browser_stub'], $this->checkedRoutes($this->renderModal($cart)));
    }

    public function test_an_unlisted_route_leaves_every_gateway_unchecked_on_the_page(): void
    {
        add_filter('fluent_cart/checkout/selected_payment_method', function () {
            return 'addon_owned_method';
        });

        $html = $this->renderPage($this->makeCart());

        $this->assertStringContainsString('value="wp_browser_stub"', $html, 'the gateway itself must still render');
        $this->assertSame([], $this->checkedRoutes($html), 'no gateway may compete with the add-on\'s checked radio');
    }

    public function test_an_unlisted_route_leaves_every_gateway_unchecked_in_the_modal(): void
    {
        add_filter('fluent_cart/checkout/selected_payment_method', function () {
            return 'addon_owned_method';
        });

        $html = $this->renderModal($this->makeCart());

        $this->assertStringContainsString('value="wp_browser_stub"', $html, 'the gateway itself must still render');
        $this->assertSame([], $this->checkedRoutes($html), 'no gateway may compete with the add-on\'s checked radio');
    }

    public function test_listener_receives_the_default_selection_and_the_render_context(): void
    {
        $cart = $this->makeCart();

        $received = null;
        add_filter('fluent_cart/checkout/selected_payment_method', function ($selected, $context) use (&$received) {
            $received = [$selected, $context];
            return $selected;
        }, 10, 2);

        $this->renderPage($cart);

        $this->assertSame('wp_browser_stub', $received[0], 'the value passed in is the route core would have checked');
        $this->assertSame($cart, $received[1]['cart']);
        $this->assertSame(
            ['wp_browser_stub'],
            array_map(function ($method) {
                return $method->getMeta('route');
            }, array_values($received[1]['payment_methods']))
        );
    }
}
