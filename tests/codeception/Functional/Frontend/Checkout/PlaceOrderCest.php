<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderAddress;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use PHPUnit\Framework\Assert;
use Tests\Support\Doubles\CheckoutGateway;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderAddressFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/checkout.md → "Placing an order" section, items 1-7.
 */
class PlaceOrderCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        GatewayManager::getInstance()->register('wp_browser_stub', new CheckoutGateway());
    }

    public function _after(FunctionalTester $I): void
    {
        $gateway = GatewayManager::getInstance('wp_browser_stub');
        if ($gateway) {
            remove_filter('fluent_cart/transaction/url_wp_browser_stub', [$gateway, 'getTransactionUrl'], 10);
            remove_filter('fluent_cart/subscription/url_wp_browser_stub', [$gateway, 'getSubscriptionUrl'], 10);
        }
        GatewayManager::getInstance()->remove('wp_browser_stub');
        $I->disarmStockListeners();
    }

    /**
     * Spec: Cases/Functional/checkout.md [1]
     *   "A guest can place an order from a valid cart and the response and stored order preserve the exact cent totals."
     */
    public function guestCanPlaceOrderWithExactCentTotals(FunctionalTester $I): void
    {
        $I->logout();
        $product = (new ProductFactory())->withVariantAttributes(['item_price' => 2468])->create();
        $variation = $product->variants->first();
        $cart = (new CartFactory())->withCartItems([
            CartHelper::generateCartItemFromVariation($variation, 2),
        ])->create(['cart_group' => 'instant']);
        $I->rebindCheckoutHelperToCart($cart->cart_hash);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', [
            'fct_cart_hash'       => $cart->cart_hash,
            '_fct_pay_method'     => 'wp_browser_stub',
            'billing_full_name'   => 'Guest Buyer',
            'billing_first_name'  => 'Guest',
            'billing_last_name'   => 'Buyer',
            'billing_email'       => 'guest-checkout@example.test',
            'billing_address_1'   => '10 Checkout Way',
            'billing_city'        => 'New York',
            'billing_state'       => 'NY',
            'billing_postcode'    => '10001',
            'billing_country'     => 'US',
            'ship_to_different'   => 'no',
            'agree_terms'         => 'yes',
        ]);

        Assert::assertSame(200, $status, 'guest place-order must return HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'guest place-order response must report success');
        Assert::assertSame(1, Order::query()->count(), 'guest place-order must create exactly one order');

        $order = Order::query()->first();
        Assert::assertSame(4936, (int) $order->subtotal, 'guest order subtotal must be stored as exact cents');
        Assert::assertSame(4936, (int) $order->total_amount, 'guest order total must be stored as exact cents');
        Assert::assertSame(0, (int) $order->coupon_discount_total, 'guest order without coupon must store zero coupon discount cents');
        Assert::assertSame('guest-checkout@example.test', (string) $order->customer->email, 'guest order must belong to the submitted customer email');
        Assert::assertSame(0, (int) $order->customer->user_id, 'guest checkout customer must not be linked to a WordPress user');
    }

    /**
     * Spec: Cases/Functional/checkout.md [2]
     *   "A logged-in customer can place an order and the order is attached to that customer and WordPress user identity."
     */
    public function loggedInCustomerOrderUsesOwnedIdentity(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = (new CustomerFactory())->createForUser($userId, [
            'first_name' => 'Portal',
            'last_name'  => 'Buyer',
        ]);
        $product = (new ProductFactory())->withVariantAttributes(['item_price' => 3100])->create();
        $variation = $product->variants->first();
        $cart = (new CartFactory())->withCartItems([
            CartHelper::generateCartItemFromVariation($variation, 1),
        ])->create([
            'cart_group' => 'instant',
            'user_id'    => $userId,
            'customer_id'=> $customer->id,
            'email'      => $customer->email,
        ]);
        $I->rebindCheckoutHelperToCart($cart->cart_hash);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', [
            'fct_cart_hash'       => $cart->cart_hash,
            '_fct_pay_method'     => 'wp_browser_stub',
            'billing_full_name'   => 'Portal Buyer',
            'billing_first_name'  => 'Portal',
            'billing_last_name'   => 'Buyer',
            'billing_email'       => 'attempted-identity-swap@example.test',
            'billing_address_1'   => '11 Checkout Way',
            'billing_city'        => 'New York',
            'billing_state'       => 'NY',
            'billing_postcode'    => '10001',
            'billing_country'     => 'US',
            'ship_to_different'   => 'no',
            'agree_terms'         => 'yes',
        ]);

        Assert::assertSame(200, $status, 'logged-in place-order must return HTTP 200. Body: ' . wp_json_encode($body));
        $order = Order::query()->first();
        Assert::assertSame((int) $customer->id, (int) $order->customer_id, 'logged-in order must remain attached to the current customer');
        Assert::assertSame($userId, (int) $order->customer->user_id, 'logged-in order customer must retain the current WordPress user link');
        Assert::assertSame((string) get_userdata($userId)->user_email, (string) $order->customer->email, 'logged-in identity must override a submitted alternate billing email');
    }

    /**
     * Spec: Cases/Functional/checkout.md [3]
     *   "Placing an order without the required customer name is rejected and creates no order."
     */
    public function missingCustomerNameIsRejected(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create();
        $variation = $product->variants->first();
        $cart = (new CartFactory())->withCartItems([
            CartHelper::generateCartItemFromVariation($variation, 1),
        ])->create(['cart_group' => 'instant']);
        $I->rebindCheckoutHelperToCart($cart->cart_hash);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', [
            'fct_cart_hash'       => $cart->cart_hash,
            '_fct_pay_method'     => 'wp_browser_stub',
            'billing_email'       => 'missing-name@example.test',
            'billing_address_1'   => '12 Checkout Way',
            'billing_city'        => 'New York',
            'billing_state'       => 'NY',
            'billing_postcode'    => '10001',
            'billing_country'     => 'US',
            'agree_terms'         => 'yes',
        ]);

        Assert::assertSame(200, $status, 'checkout validation currently returns its JSON error envelope with HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('failed', (string) ($body['status'] ?? ''), 'missing-name checkout must report failed');
        Assert::assertNotEmpty($body['errors']['billing_first_name'] ?? $body['errors']['billing_full_name'] ?? null, 'missing-name response must identify the required name field');
        Assert::assertSame(0, Order::query()->count(), 'missing-name rejection must not create an order');
    }

    /**
     * Spec: Cases/Functional/checkout.md [12]
     *   "A checkout field holding only a smartcode is treated as empty and the order is rejected on the required field."
     */
    public function smartcodeOnlyNameFieldIsRejectedAsEmpty(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create();
        $variation = $product->variants->first();
        $cart = (new CartFactory())->withCartItems([
            CartHelper::generateCartItemFromVariation($variation, 1),
        ])->create(['cart_group' => 'instant']);
        $I->rebindCheckoutHelperToCart($cart->cart_hash);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', [
            'fct_cart_hash'       => $cart->cart_hash,
            '_fct_pay_method'     => 'wp_browser_stub',
            'billing_full_name'   => '{{ wp.admin_email }}',
            'billing_first_name'  => '{{wp.admin_email}}',
            'billing_last_name'   => '{{user.user_login}}',
            'billing_email'       => 'smartcode-name@example.test',
            'billing_address_1'   => '12 Checkout Way',
            'billing_city'        => 'New York',
            'billing_state'       => 'NY',
            'billing_postcode'    => '10001',
            'billing_country'     => 'US',
            'agree_terms'         => 'yes',
        ]);

        Assert::assertSame(200, $status, 'checkout validation returns its JSON error envelope with HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('failed', (string) ($body['status'] ?? ''), 'a name made only of smartcode must fail validation as an empty name');
        Assert::assertNotEmpty($body['errors']['billing_first_name'] ?? $body['errors']['billing_full_name'] ?? null, 'the required name field must carry the error');
        Assert::assertSame(0, Order::query()->count(), 'smartcode-only name must not create an order');
    }

    /**
     * Spec: Cases/Functional/checkout.md [13]
     *   "Smartcode syntax typed into checkout fields is stripped before the order's address is stored."
     */
    public function smartcodeSyntaxIsStrippedFromStoredAddress(FunctionalTester $I): void
    {
        $I->logout();
        $product = (new ProductFactory())->create();
        $variation = $product->variants->first();
        $cart = (new CartFactory())->withCartItems([
            CartHelper::generateCartItemFromVariation($variation, 1),
        ])->create(['cart_group' => 'instant']);
        $I->rebindCheckoutHelperToCart($cart->cart_hash);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', [
            'fct_cart_hash'       => $cart->cart_hash,
            '_fct_pay_method'     => 'wp_browser_stub',
            'billing_full_name'   => 'John {{wp.admin_email}} Doe',
            'billing_first_name'  => 'John {{wp.admin_email}}',
            'billing_last_name'   => 'Doe',
            'billing_email'       => 'smartcode-strip@example.test',
            'billing_address_1'   => '12 {{order.customer.email}} Checkout Way ## 3',
            'billing_city'        => 'New York',
            'billing_state'       => 'NY',
            'billing_postcode'    => '10001',
            'billing_country'     => 'US',
            'ship_to_different'   => 'no',
            'agree_terms'         => 'yes',
        ]);

        Assert::assertSame(200, $status, 'place-order with stripped smartcodes must succeed. Body: ' . wp_json_encode($body));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'the order must be placed once the smartcode is removed');

        $order = Order::query()->first();
        Assert::assertNotNull($order, 'the stripped checkout must create an order');
        $billing = OrderAddress::query()->where('order_id', $order->id)->where('type', 'billing')->first();
        Assert::assertNotNull($billing, 'the order must store a billing address');

        $stored = wp_json_encode([$billing->getAttributes(), $order->customer->getAttributes()]);
        Assert::assertStringNotContainsString('{{', $stored, 'no smartcode delimiter may survive into stored order data');
        Assert::assertStringContainsString('John', $stored, 'the legitimate part of the name must survive the strip');
        Assert::assertStringContainsString('##', $stored, 'only the brace form is a smartcode; # and ## are legitimate address text');
    }

    /**
     * Spec: Cases/Functional/checkout.md [4]
     *   "Placing an order with an invalid billing email is rejected and creates no order."
     */
    public function invalidBillingEmailIsRejected(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create();
        $variation = $product->variants->first();
        $cart = (new CartFactory())->withCartItems([
            CartHelper::generateCartItemFromVariation($variation, 1),
        ])->create(['cart_group' => 'instant']);
        $I->rebindCheckoutHelperToCart($cart->cart_hash);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', [
            'fct_cart_hash'       => $cart->cart_hash,
            '_fct_pay_method'     => 'wp_browser_stub',
            'billing_full_name'   => 'Invalid Email',
            'billing_first_name'  => 'Invalid',
            'billing_last_name'   => 'Email',
            'billing_email'       => 'not-an-email',
            'billing_address_1'   => '13 Checkout Way',
            'billing_city'        => 'New York',
            'billing_state'       => 'NY',
            'billing_postcode'    => '10001',
            'billing_country'     => 'US',
            'agree_terms'         => 'yes',
        ]);

        Assert::assertSame(200, $status, 'checkout validation currently returns its JSON error envelope with HTTP 200. Body: ' . wp_json_encode($body));
        Assert::assertSame('failed', (string) ($body['status'] ?? ''), 'invalid-email checkout must report failed');
        Assert::assertNotEmpty($body['errors']['billing_email']['invalid'] ?? null, 'invalid-email response must identify billing_email as invalid');
        Assert::assertSame(0, Order::query()->count(), 'invalid-email rejection must not create an order');
    }

    /**
     * Spec: Cases/Functional/checkout.md [5]
     *   "Placing an order for a stock-managed product decrements available stock by the ordered quantity exactly once."
     */
    public function placeOrderDecrementsStockExactlyOnce(FunctionalTester $I): void
    {
        $I->armStockListeners();
        $product = (new ProductFactory())->withDetailAttributes(['manage_stock' => 1])->withVariantAttributes([
            'item_price'   => 1500,
            'manage_stock' => 1,
            'total_stock'  => 5,
            'available'    => 5,
        ])->create();
        $variation = $product->variants->first();
        $cart = (new CartFactory())->withCartItems([
            CartHelper::generateCartItemFromVariation($variation, 2),
        ])->create(['cart_group' => 'instant']);
        $I->rebindCheckoutHelperToCart($cart->cart_hash);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', [
            'fct_cart_hash'       => $cart->cart_hash,
            '_fct_pay_method'     => 'wp_browser_stub',
            'billing_full_name'   => 'Stock Buyer',
            'billing_first_name'  => 'Stock',
            'billing_last_name'   => 'Buyer',
            'billing_email'       => 'stock-checkout@example.test',
            'billing_address_1'   => '14 Checkout Way',
            'billing_city'        => 'New York',
            'billing_state'       => 'NY',
            'billing_postcode'    => '10001',
            'billing_country'     => 'US',
            'agree_terms'         => 'yes',
        ]);

        Assert::assertSame(200, $status, 'stocked place-order must return HTTP 200. Body: ' . wp_json_encode($body));
        $stored = ProductVariation::query()->find($variation->id);
        Assert::assertSame(3, (int) $stored->available, 'placing quantity two must decrement available stock from five to three');
        Assert::assertSame(2, (int) $stored->on_hold, 'placing quantity two must move exactly two units onto hold');
    }

    /**
     * Spec: Cases/Functional/checkout.md [6]
     *   "Submitting the same completed cart twice does not create a duplicate order."
     */
    public function completedCartCannotCreateDuplicateOrder(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create();
        $variation = $product->variants->first();
        $cart = (new CartFactory())->withCartItems([
            CartHelper::generateCartItemFromVariation($variation, 1),
        ])->create(['cart_group' => 'instant']);
        $I->rebindCheckoutHelperToCart($cart->cart_hash);
        $payload = [
            'fct_cart_hash'       => $cart->cart_hash,
            '_fct_pay_method'     => 'wp_browser_stub',
            'billing_full_name'   => 'Double Submit',
            'billing_first_name'  => 'Double',
            'billing_last_name'   => 'Submit',
            'billing_email'       => 'double-submit@example.test',
            'billing_address_1'   => '15 Checkout Way',
            'billing_city'        => 'New York',
            'billing_state'       => 'NY',
            'billing_postcode'    => '10001',
            'billing_country'     => 'US',
            'agree_terms'         => 'yes',
        ];

        [$firstStatus, $firstBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);
        [$secondStatus, $secondBody] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $payload);

        Assert::assertSame(200, $firstStatus, 'first checkout submission must succeed. Body: ' . wp_json_encode($firstBody));
        Assert::assertSame('success', (string) ($firstBody['status'] ?? ''), 'first checkout submission must report success');
        Assert::assertSame(200, $secondStatus, 'completed-cart rejection currently uses the default wp_send_json HTTP 200. Body: ' . wp_json_encode($secondBody));
        Assert::assertSame('failed', (string) ($secondBody['status'] ?? ''), 'second checkout submission must report failed');
        Assert::assertStringContainsString('already completed', (string) ($secondBody['message'] ?? ''), 'second checkout submission must explain that the cart is completed');
        Assert::assertSame(1, Order::query()->count(), 'double-submit must leave exactly one order in the database');
    }

    /**
     * Spec: Cases/Functional/checkout.md [7]
     *   "A coupon applied to the cart reduces the exact stored order total and is recorded on the order."
     */
    public function couponReducesOrderTotalAndIsRecorded(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create([
            'code'   => 'CHECKOUT500',
            'type'   => 'fixed',
            'amount' => 500,
        ]);
        $product = (new ProductFactory())->withVariantAttributes(['item_price' => 2500])->create();
        $variation = $product->variants->first();
        $cart = (new CartFactory())->withCartItems([
            CartHelper::generateCartItemFromVariation($variation, 1),
        ])->create([
            'cart_group' => 'instant',
            'coupons'    => [$coupon->code],
        ]);
        $cart->applyCoupon([$coupon->code]);
        $I->rebindCheckoutHelperToCart($cart->cart_hash);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', [
            'fct_cart_hash'       => $cart->cart_hash,
            '_fct_pay_method'     => 'wp_browser_stub',
            'billing_full_name'   => 'Coupon Buyer',
            'billing_first_name'  => 'Coupon',
            'billing_last_name'   => 'Buyer',
            'billing_email'       => 'coupon-checkout@example.test',
            'billing_address_1'   => '16 Checkout Way',
            'billing_city'        => 'New York',
            'billing_state'       => 'NY',
            'billing_postcode'    => '10001',
            'billing_country'     => 'US',
            'agree_terms'         => 'yes',
        ]);

        Assert::assertSame(200, $status, 'coupon place-order must return HTTP 200. Body: ' . wp_json_encode($body));
        $order = Order::query()->first();
        Assert::assertSame(2500, (int) $order->subtotal, 'coupon order subtotal must retain the pre-discount cents');
        Assert::assertSame(500, (int) $order->coupon_discount_total, 'coupon order must store the exact coupon discount cents');
        Assert::assertSame(2000, (int) $order->total_amount, 'coupon order total must equal subtotal minus coupon cents');
        Assert::assertSame(1, AppliedCoupon::query()->where('order_id', $order->id)->where('coupon_id', $coupon->id)->count(), 'coupon checkout must persist one applied-coupon row');
        Assert::assertSame(1, (int) $coupon->fresh()->use_count, 'coupon checkout must increment use_count exactly once');
    }

    /**
     * Spec: Cases/Functional/checkout.md [8]
     *   "A guest cannot copy another customer's order billing address into their own order by posting that order's ids."
     */
    public function guestCannotCopyAnotherOrdersBillingAddress(FunctionalTester $I): void
    {
        $I->logout();
        [$victimOrder, $victimBilling] = $this->seedVictimOrder();
        $cart = $this->buildAttackerCart();
        $I->rebindCheckoutHelperToCart($cart->cart_hash);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->attackerPayload($cart->cart_hash, [
            'order_id'           => $victimOrder->id,
            'billing_address_id' => $victimBilling->id,
        ]));

        Assert::assertSame(200, $status, 'place-order must still succeed with the forged ids ignored. Body: ' . wp_json_encode($body));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order must report success. Body: ' . wp_json_encode($body));
        $this->assertAttackerKeptOwnAddress('billing', $victimOrder->id);
    }

    /**
     * Spec: Cases/Functional/checkout.md [9]
     *   "A guest cannot copy another customer's order shipping address into their own order by posting that order's ids."
     */
    public function guestCannotCopyAnotherOrdersShippingAddress(FunctionalTester $I): void
    {
        $I->logout();
        [$victimOrder, , $victimShipping] = $this->seedVictimOrder();
        $cart = $this->buildAttackerCart();
        $I->rebindCheckoutHelperToCart($cart->cart_hash);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->attackerPayload($cart->cart_hash, [
            'order_id'            => $victimOrder->id,
            'shipping_address_id' => $victimShipping->id,
            'ship_to_different'   => 'yes',
            'shipping_full_name'  => 'Evil Attacker',
            'shipping_address_1'  => '9 Attacker Road',
            'shipping_city'       => 'Austin',
            'shipping_state'      => 'TX',
            'shipping_postcode'   => '73301',
            'shipping_country'    => 'US',
        ]));

        Assert::assertSame(200, $status, 'place-order must still succeed with the forged ids ignored. Body: ' . wp_json_encode($body));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order must report success. Body: ' . wp_json_encode($body));
        $this->assertAttackerKeptOwnAddress('shipping', $victimOrder->id);
    }

    /**
     * Spec: Cases/Functional/checkout.md [10]
     *   "A logged-in customer cannot copy another customer's order billing address into their own order by posting that order's ids."
     */
    public function loggedInCustomerCannotCopyAnotherOrdersBillingAddress(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        $customer = (new CustomerFactory())->createForUser($userId);
        [$victimOrder, $victimBilling] = $this->seedVictimOrder();
        $cart = $this->buildAttackerCart([
            'user_id'     => $userId,
            'customer_id' => $customer->id,
            'email'       => $customer->email,
        ]);
        $I->rebindCheckoutHelperToCart($cart->cart_hash);

        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->attackerPayload($cart->cart_hash, [
            'order_id'           => $victimOrder->id,
            'billing_address_id' => $victimBilling->id,
        ]));

        Assert::assertSame(200, $status, 'place-order must still succeed with the forged ids ignored. Body: ' . wp_json_encode($body));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order must report success. Body: ' . wp_json_encode($body));
        $this->assertAttackerKeptOwnAddress('billing', $victimOrder->id);
    }

    /**
     * Spec: Cases/Functional/checkout.md [11]
     *   "Paying a due order through its locked cart still reuses that order's own billing address."
     */
    public function payingDueOrderReusesItsOwnBillingAddress(FunctionalTester $I): void
    {
        $I->logout();
        [$dueOrder, $dueBilling] = $this->seedVictimOrder();
        $cart = $this->buildAttackerCart([
            'order_id'      => $dueOrder->id,
            'checkout_data' => ['is_locked' => 'yes'],
        ]);
        $I->rebindCheckoutHelperToCart($cart->cart_hash);

        // No order_id in the body: the real pay-for-order form never sends one.
        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->attackerPayload($cart->cart_hash, [
            'billing_address_id' => $dueBilling->id,
        ]));

        Assert::assertSame(200, $status, 'paying a due order must succeed. Body: ' . wp_json_encode($body));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'paying a due order must report success. Body: ' . wp_json_encode($body));
        Assert::assertSame(1, Order::query()->count(), 'paying a due order must reuse it, not create a second order');

        $address = OrderAddress::query()->where('order_id', $dueOrder->id)->where('type', 'billing')->first();
        Assert::assertSame('1 Victim Lane', (string) $address->address_1, "the due order's own stored billing address must be reused");
        Assert::assertSame('Los Angeles', (string) $address->city, "the due order's own stored billing city must be reused");
    }

    /**
     * @return array{0: Order, 1: OrderAddress, 2: OrderAddress}
     */
    private function seedVictimOrder(): array
    {
        $victim = (new CustomerFactory())->create(['email' => 'victim@example.test']);
        $order = (new OrderFactory())->create(['customer_id' => $victim->id]);
        $victimAddress = [
            'name'      => 'Victim Person',
            'address_1' => '1 Victim Lane',
            'city'      => 'Los Angeles',
            'state'     => 'CA',
            'postcode'  => '90001',
            'country'   => 'US',
            'meta'      => ['other_data' => ['phone' => '+13105550100']],
        ];
        $billing = (new OrderAddressFactory())->create($order->id, array_merge($victimAddress, ['type' => 'billing']));
        $shipping = (new OrderAddressFactory())->create($order->id, array_merge($victimAddress, ['type' => 'shipping']));

        return [$order, $billing, $shipping];
    }

    private function buildAttackerCart(array $overrides = []): \FluentCart\App\Models\Cart
    {
        $variation = (new ProductFactory())->withVariantAttributes(['item_price' => 1000])->create()->variants->first();

        return (new CartFactory())->withCartItems([
            CartHelper::generateCartItemFromVariation($variation, 1),
        ])->create(array_merge(['cart_group' => 'instant'], $overrides));
    }

    private function attackerPayload(string $cartHash, array $overrides): array
    {
        return array_merge([
            'fct_cart_hash'      => $cartHash,
            '_fct_pay_method'    => 'wp_browser_stub',
            'billing_full_name'  => 'Evil Attacker',
            'billing_first_name' => 'Evil',
            'billing_last_name'  => 'Attacker',
            'billing_email'      => 'attacker@example.test',
            'billing_address_1'  => '9 Attacker Road',
            'billing_city'       => 'Austin',
            'billing_state'      => 'TX',
            'billing_postcode'   => '73301',
            'billing_country'    => 'US',
            'ship_to_different'  => 'no',
            'agree_terms'        => 'yes',
        ], $overrides);
    }

    private function assertAttackerKeptOwnAddress(string $type, int $victimOrderId): void
    {
        $order = Order::query()->where('id', '!=', $victimOrderId)->orderBy('id', 'desc')->first();
        Assert::assertNotNull($order, 'the attacker order must have been created');

        $address = OrderAddress::query()->where('order_id', $order->id)->where('type', $type)->first();
        Assert::assertNotNull($address, "the attacker order must store a {$type} address");
        Assert::assertSame('9 Attacker Road', (string) $address->address_1, "{$type} address_1 must be the submitted one, not the victim's");
        Assert::assertSame('Austin', (string) $address->city, "{$type} city must be the submitted one, not the victim's");
        Assert::assertSame('73301', (string) $address->postcode, "{$type} postcode must be the submitted one, not the victim's");
        Assert::assertStringNotContainsString('+13105550100', (string) wp_json_encode($address->meta), "the victim's phone must not reach the attacker's {$type} address");
    }
}

