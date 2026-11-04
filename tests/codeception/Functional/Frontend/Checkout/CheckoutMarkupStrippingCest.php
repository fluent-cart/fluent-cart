<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesCheckoutOrders;

/**
 * Admin screens print order and customer addresses with v-html, so a guest's
 * checkout text must reach the database with no markup in it.
 */
class CheckoutMarkupStrippingCest
{
    use PlacesCheckoutOrders;

    const MARKUP = '<img src=x onerror=alert(1)>';

    public function _before(FunctionalTester $I): void
    {
        $I->logout();
        $this->registerStubCheckoutGateway();
    }

    public function _after(FunctionalTester $I): void
    {
        $this->removeStubCheckoutGateway();
    }

    public function guestCheckoutTextIsStoredWithoutMarkup(FunctionalTester $I): void
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1000])->create()->variants->first();
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, [
            'billing_full_name'  => 'Ann ' . self::MARKUP,
            'billing_first_name' => 'Ann',
            'billing_last_name'  => 'Lee' . self::MARKUP,
            'billing_address_1'  => '1 Main ' . self::MARKUP,
            'billing_address_2'  => '"><svg onload=alert(2)>',
            'billing_city'       => 'Town' . self::MARKUP,
            'billing_postcode'   => '10001',
            'billing_phone'      => '555' . self::MARKUP,
            'order_notes'        => 'Note ' . self::MARKUP,
        ]));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'precondition: order accepted. Body: ' . wp_json_encode($body));

        $stored = wp_json_encode([
            OrderAddress::query()->get()->toArray(),
            CustomerAddresses::query()->get()->toArray(),
            Customer::query()->get(['first_name', 'last_name', 'email'])->toArray(),
            Order::query()->get(['note'])->toArray(),
        ], JSON_UNESCAPED_SLASHES);

        Assert::assertStringNotContainsString('<img', $stored, 'no <img> tag may be stored from guest checkout text');
        Assert::assertStringNotContainsString('<svg', $stored, 'no <svg> tag may be stored from guest checkout text');
    }
}
