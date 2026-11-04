<?php

namespace Tests\Integration\Renderer;

use FluentCart\App\Models\Cart;
use FluentCart\App\Services\Renderer\CartSummaryRender;
use FluentCart\App\Services\Renderer\CheckoutRenderer;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;

/**
 * Checkout panels that open and close use the shared .fct-collapse markup,
 * so the CSS can animate them. The hidden attribute cannot animate.
 */
class CheckoutCollapseMarkupTest extends DatabaseTestCase
{
    public function test_the_coupon_panel_renders_closed_as_a_collapse()
    {
        ob_start();
        (new CartSummaryRender(new Cart()))->showCouponField();
        $dom = $this->dom(ob_get_clean());
        $xpath = new \DOMXPath($dom);

        $panel = $dom->getElementById('coupon_section');
        $this->assertNotNull($panel, 'The toggle still controls #coupon_section');
        $this->assertSame('fct-collapse', $panel->getAttribute('class'), 'It renders closed: no is-open');
        $this->assertFalse($panel->hasAttribute('hidden'), 'The hidden attribute would stop the animation');
        $this->assertTrue($panel->hasAttribute('data-fluent-cart-checkout-page-coupon-container'), 'The JS hook stays');

        $inner = $xpath->query('./div[@class="fct-collapse__inner"]/div[contains(@class,"fct_coupon_field")]', $panel);
        $this->assertSame(1, $inner->length, 'The spaced coupon field sits inside __inner, so the closed panel has no height');
        $this->assertSame(1, $xpath->query('.//input[@id="coupon"]', $inner->item(0))->length);

        $toggle = $xpath->query('//*[@data-fluent-cart-checkout-page-coupon-field-toggle]')->item(0);
        $this->assertSame('false', $toggle->getAttribute('aria-expanded'));
        $this->assertSame('coupon_section', $toggle->getAttribute('aria-controls'));
    }

    public function test_the_payment_methods_root_is_a_collapse_inner()
    {
        $cart = (new CartFactory())->create([
            'cart_data' => [CartFactory::makeCartItem()],
        ]);

        ob_start();
        (new CheckoutRenderer($cart))->renderPaymentMethods();
        $root = $this->dom(ob_get_clean())->getElementById('fluent_payment_methods');

        // An add-on that hides the methods (Pro's "Pay another way") makes the container a .fct-collapse.
        $this->assertNotNull($root);
        $this->assertStringContainsString('fct-collapse__inner', $root->getAttribute('class'));
    }

    private function dom($html)
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>');
        libxml_clear_errors();

        return $dom;
    }
}
