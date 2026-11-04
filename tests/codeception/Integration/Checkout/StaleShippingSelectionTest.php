<?php

namespace Tests\Integration\Checkout;

use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Services\Renderer\CheckoutRenderer;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Shipping\SourcedStubCarrier;
use Tests\Support\Shipping\StubShippingCarrier;

/**
 * FC-SHIPPO-01: a saved shipping pick the rendered address does not offer
 * must not stay charged while no option shows as selected.
 */
class StaleShippingSelectionTest extends DatabaseTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        AddressHelper::resetRateCache();
        StubShippingCarrier::install([
            ['id' => 'stub:ground', 'title' => 'Ground', 'cost' => 1000, 'currency' => 'USD', 'states' => []],
            ['id' => 'stub:express', 'title' => 'Express', 'cost' => 2500, 'currency' => 'USD', 'states' => []],
        ]);
    }

    public function tearDown(): void
    {
        StubShippingCarrier::uninstall();
        SourcedStubCarrier::uninstall();
        AddressHelper::resetRateCache();
        parent::tearDown();
    }

    public function test_a_pick_the_address_does_not_offer_is_cleared_with_its_charge(): void
    {
        $cart = $this->cartWithPick('carrier:shippo:usps_priority', 2096700);

        $html = $this->renderShippingOptions($cart);

        $this->assertNull($cart->checkout_data['shipping_data']['shipping_method_id']);
        $this->assertSame(0, $cart->getShippingTotal(), 'the summary rendered next charges nothing');
        $this->assertSame(0, (int) Cart::query()->where('cart_hash', $cart->cart_hash)->first()->checkout_data['shipping_data']['shipping_charge'], 'saved');
        $this->assertStringContainsString('name="fc_selected_shipping_method" value=""', $html);
        $this->assertStringNotContainsString('checked', $html);
    }

    public function test_a_pick_the_address_offers_is_kept(): void
    {
        $cart = $this->cartWithPick('stub:express', 2500);

        $html = $this->renderShippingOptions($cart);

        $this->assertSame('stub:express', $cart->checkout_data['shipping_data']['shipping_method_id']);
        $this->assertSame(2500, $cart->getShippingTotal());
        $this->assertStringContainsString('name="fc_selected_shipping_method" value="stub:express"', $html);
    }

    public function test_a_pick_is_kept_while_a_carrier_source_is_still_pending(): void
    {
        SourcedStubCarrier::install(['ups' => 'UPS'], ['ups' => [SourcedStubCarrier::rate('carrier:stub_sourced:ups:ground', 1500)]]);
        $cart = $this->cartWithPick('carrier:stub_sourced:ups:ground', 1500);

        $this->renderShippingOptions($cart);

        $this->assertSame('carrier:stub_sourced:ups:ground', $cart->checkout_data['shipping_data']['shipping_method_id']);
        $this->assertSame(1500, $cart->getShippingTotal(), 'the source may still bring this rate');
    }

    private function cartWithPick(string $methodId, int $charge): Cart
    {
        return (new CartFactory())->create([
            'cart_data'     => [CartFactory::makePhysicalItem()],
            'checkout_data' => [
                'form_data'     => [
                    'billing_country'  => 'US',
                    'billing_state'    => 'NY',
                    'billing_postcode' => '10001',
                    'billing_city'     => 'New York',
                ],
                'shipping_data' => ['shipping_method_id' => $methodId, 'shipping_charge' => $charge],
            ],
        ]);
    }

    private function renderShippingOptions(Cart $cart): string
    {
        ob_start();
        (new CheckoutRenderer($cart))->renderShippingOptions();
        return ob_get_clean();
    }
}
