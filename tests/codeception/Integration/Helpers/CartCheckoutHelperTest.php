<?php

namespace Tests\Integration\Helpers;

use FluentCart\App\Helpers\CartCheckoutHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Cart;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\CustomerFactory;

class CartCheckoutHelperTest extends DatabaseTestCase
{
    private CartFactory $cartFactory;
    private CustomerFactory $customerFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->cartFactory = new CartFactory();
        $this->customerFactory = new CustomerFactory();
    }

    // =========================================================================
    // Spec 150: getItems returns cart_data
    // =========================================================================

    public function test_get_items_returns_empty_array_when_no_cart(): void
    {
        $helper = new CartCheckoutHelper();
        // No cart set — getItems should return empty array
        $this->assertSame([], $helper->getItems());
    }

    // =========================================================================
    // Spec 151: hasSubscription
    // =========================================================================

    public function test_has_subscription_returns_yes_with_subscription_item(): void
    {
        $subItem = CartFactory::makeSubscriptionItem(['unit_price' => 2000, 'subtotal' => 2000]);
        $cart = $this->cartFactory->withCartItems([$subItem])->create();

        $helper = new CartCheckoutHelper();
        $helper->setCart($cart);

        $this->assertSame('yes', $helper->hasSubscription());
    }

    public function test_has_subscription_returns_no_for_onetime_only(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 1000, 'subtotal' => 1000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();

        $helper = new CartCheckoutHelper();
        $helper->setCart($cart);

        $this->assertSame('no', $helper->hasSubscription());
    }

    // =========================================================================
    // Spec 152: isZeroPayment
    // =========================================================================

    public function test_is_zero_payment_true_for_empty_cart(): void
    {
        $cart = $this->cartFactory->create(['cart_data' => []]);
        $helper = new CartCheckoutHelper();
        $helper->setCart($cart);

        $this->assertTrue($helper->isZeroPayment());
    }

    public function test_is_zero_payment_false_with_subscription(): void
    {
        $subItem = CartFactory::makeSubscriptionItem(['unit_price' => 0, 'subtotal' => 0, 'line_total' => 0]);
        $cart = $this->cartFactory->withCartItems([$subItem])->create();

        $helper = new CartCheckoutHelper();
        $helper->setCart($cart);

        $this->assertFalse($helper->isZeroPayment());
    }

    // =========================================================================
    // Spec 153: requireShippingAddress
    // =========================================================================

    public function test_require_shipping_address_true_for_physical_item(): void
    {
        $item = CartFactory::makePhysicalItem(['unit_price' => 1000, 'subtotal' => 1000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();

        $helper = new CartCheckoutHelper();
        $helper->setCart($cart);

        $this->assertTrue($helper->requireShippingAddress());
    }

    public function test_require_shipping_address_false_for_digital_only(): void
    {
        $item = CartFactory::makeCartItem(['fulfillment_type' => 'digital']);
        $cart = $this->cartFactory->withCartItems([$item])->create();

        $helper = new CartCheckoutHelper();
        $helper->setCart($cart);

        $this->assertFalse($helper->requireShippingAddress());
    }

    // =========================================================================
    // Spec 154-155: getItemsAmountTotal and getItemsAmountSubtotal
    // =========================================================================

    public function test_get_items_amount_total_returns_raw_cents(): void
    {
        $item = CartFactory::makeCartItem(['unit_price' => 5000, 'subtotal' => 5000, 'line_total' => 5000]);
        $cart = $this->cartFactory->withCartItems([$item])->create();

        $helper = new CartCheckoutHelper();
        $helper->setCart($cart);

        $total = $helper->getItemsAmountTotal(false, false);
        $this->assertEquals(5000, $total);
    }

    public function test_get_items_amount_subtotal_returns_raw_cents(): void
    {
        $item1 = CartFactory::makeCartItem(['unit_price' => 3000, 'subtotal' => 3000, 'line_total' => 2500, 'discount_total' => 500]);
        $item2 = CartFactory::makeCartItem(['unit_price' => 2000, 'subtotal' => 2000, 'line_total' => 2000]);
        $cart = $this->cartFactory->withCartItems([$item1, $item2])->create();

        $helper = new CartCheckoutHelper();
        $helper->setCart($cart);

        $subtotal = $helper->getItemsAmountSubtotal(false, false);
        // Subtotal is before discounts: 3000 + 2000 = 5000
        $this->assertEquals(5000, $subtotal);
    }

    // =========================================================================
    // Spec 157-158: getFirstName, getLastName from cart
    // =========================================================================

    public function test_get_first_name_from_cart(): void
    {
        $cart = $this->cartFactory->create(['first_name' => 'Alice']);
        $helper = new CartCheckoutHelper();
        $helper->setCart($cart);

        $this->assertSame('Alice', $helper->getFirstName());
    }

    public function test_get_last_name_from_cart(): void
    {
        $cart = $this->cartFactory->create(['last_name' => 'Smith']);
        $helper = new CartCheckoutHelper();
        $helper->setCart($cart);

        $this->assertSame('Smith', $helper->getLastName());
    }

    // =========================================================================
    // Spec 160: getUtmData
    // =========================================================================

    public function test_get_utm_data_extracts_utm_keys(): void
    {
        $cart = $this->cartFactory->create();
        $helper = new CartCheckoutHelper();
        $helper->setCart($cart);

        $utmData = $helper->getUtmData([
            'utm_campaign' => 'spring_sale',
            'utm_medium'   => 'email',
            'utm_source'   => 'google',
            'irrelevant'   => 'ignored',
        ]);

        $this->assertSame('spring_sale', $utmData['utm_campaign']);
        $this->assertSame('email', $utmData['utm_medium']);
        $this->assertSame('google', $utmData['utm_source']);
        $this->assertArrayNotHasKey('irrelevant', $utmData);
    }
}
