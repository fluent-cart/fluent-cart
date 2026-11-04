<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Hooks\Cart\WebCheckoutHandler;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\ShippingMethod;
use FluentCart\Framework\Support\Arr;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesCheckoutOrders;

/**
 * Spec: Cases/checkout-functional.md → "Shipping tax after the gateway's order-info call" section, items 66-72.
 *
 * Ticket #2848. A signed-in customer whose saved billing address fills the checkout has
 * no shipping_country (shipping = billing). The page load saves the first shipping method
 * and its per-item share; Stripe/PayPal/Paddle then ask `getShippingChargeData()` for the
 * shipping charge to size their payment amount. That lookup read only shipping_country,
 * found no country, and reset the method. `place-order` priced shipping from the submitted
 * method but taxed it from the reset cart, so the order kept the shipping charge with zero
 * shipping tax.
 *
 * The lookup is now read-only and resolves the address the order ships to. Every cart below
 * is left exactly as the storefront leaves it (the real `loading` scope or the real address
 * patch), and the order-info call goes through the real AJAX ingress with method=stripe.
 * Every amount is an exact cent value.
 */
class ShippingTaxAfterOrderInfoCest
{
    use PlacesCheckoutOrders;

    public function _before(FunctionalTester $I): void
    {
        $I->logout();
        $this->registerStubCheckoutGateway();
    }

    public function _after(FunctionalTester $I): void
    {
        $this->removeStubCheckoutGateway();
        $I->disarmTaxModule();
    }

    /**
     * Spec: Cases/checkout-functional.md [66]
     *   "A signed-in customer whose saved billing address fills the checkout, with two methods
     *    for that address, keeps the method the page selected through the gateway's order-info
     *    call, the payment amount includes shipping, and the placed order carries that method's
     *    shipping tax."
     */
    public function orderInfoKeepsTheMethodWhenShippingIsTheBillingAddress(FunctionalTester $I): void
    {
        [$variation, $percent] = $this->arm($I, ['two_methods' => true]);
        $cart = $this->signedInCartAsTheCheckoutPageLeavesIt($I, $variation);

        Assert::assertSame(
            ['method' => (int) $percent->id, 'charge' => 190, 'shares' => [190], 'shipping_tax' => 19],
            $this->shippingState($cart),
            'precondition: the page load saves the first method, its share, and its tax'
        );

        $this->loadThePaymentMethod($I, $cart);

        Assert::assertSame(
            ['method' => (int) $percent->id, 'charge' => 190, 'shares' => [190], 'shipping_tax' => 19],
            $this->shippingState($cart),
            'the order-info call must not reset the method the page selected for the billing address'
        );
        Assert::assertSame(190, $this->paymentShippingCharge($cart), 'the charge gateways add to their payment amount must include shipping');

        Assert::assertSame(
            ['shipping_total' => 190, 'shipping_tax' => 19, 'tax_total' => 100, 'total_amount' => 1309],
            $this->placeAuOrder($I, $cart, (int) $percent->id),
            'the order must carry the shipping tax of the shipping it charges'
        );
    }

    /**
     * Spec: Cases/checkout-functional.md [67]
     *   "The same signed-in checkout with a single method for the address places an order with
     *    that method's shipping tax."
     */
    public function oneMethodCheckoutKeepsShippingTax(FunctionalTester $I): void
    {
        [$variation, $percent] = $this->arm($I, ['two_methods' => false]);
        $cart = $this->signedInCartAsTheCheckoutPageLeavesIt($I, $variation);

        $this->loadThePaymentMethod($I, $cart);

        Assert::assertSame(
            ['shipping_total' => 190, 'shipping_tax' => 19, 'tax_total' => 100, 'total_amount' => 1309],
            $this->placeAuOrder($I, $cart, (int) $percent->id),
            'the single-method case must stay correct'
        );
    }

    /**
     * Spec: Cases/checkout-functional.md [68]
     *   "When shipping is not taxable, the same signed-in checkout charges the shipping and adds
     *    no shipping tax."
     */
    public function nonTaxableShippingGetsNoShippingTax(FunctionalTester $I): void
    {
        [$variation, $percent] = $this->arm($I, ['two_methods' => true, 'rate' => ['for_shipping' => '0']]);
        $cart = $this->signedInCartAsTheCheckoutPageLeavesIt($I, $variation);

        $this->loadThePaymentMethod($I, $cart);

        Assert::assertSame(
            ['shipping_total' => 190, 'shipping_tax' => 0, 'tax_total' => 100, 'total_amount' => 1290],
            $this->placeAuOrder($I, $cart, (int) $percent->id),
            'a 0% shipping rate must still produce zero shipping tax'
        );
    }

    /**
     * Spec: Cases/checkout-functional.md [69]
     *   "A guest who types the address keeps the chosen method through the order-info call and
     *    gets the shipping tax on the order."
     */
    public function guestWhoTypesTheAddressGetsShippingTax(FunctionalTester $I): void
    {
        [$variation, $percent] = $this->arm($I, ['two_methods' => true]);
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);
        [$patchStatus] = $I->checkoutAjax($this->checkoutPatchParams($cart->cart_hash, 'AU', 'NSW', (int) $percent->id));
        Assert::assertSame(200, $patchStatus, 'precondition: the storefront address patch must succeed');

        $this->loadThePaymentMethod($I, $cart);

        Assert::assertSame((int) $percent->id, $this->shippingState($cart)['method'], 'the guest cart must keep its method');
        Assert::assertSame(
            ['shipping_total' => 190, 'shipping_tax' => 19, 'tax_total' => 100, 'total_amount' => 1309],
            $this->placeAuOrder($I, $cart, (int) $percent->id),
            'the guest order must carry the shipping tax'
        );
    }

    /**
     * Spec: Cases/checkout-functional.md [70]
     *   "With ship-to-different on, the payment amount's shipping comes from the shipping
     *    address: a complete one is charged its method, and an empty one is not filled in from
     *    the billing address."
     */
    public function shipToDifferentUsesOnlyTheShippingAddress(FunctionalTester $I): void
    {
        [$variation, $percent] = $this->arm($I, ['two_methods' => true]);

        $shape = function (array $formData) use ($I, $variation, $percent) {
            $cart = $this->cartWithSavedMethod($I, $variation, $percent, $formData);

            $this->loadThePaymentMethod($I, $cart);

            return $this->paymentShippingCharge($cart);
        };

        Assert::assertSame(
            [
                // Billing is in a country with no zone; the separate AU address is served.
                'separate_complete' => 190,
                // A separate address with no country is not filled in from billing.
                'separate_empty'    => 0,
            ],
            [
                'separate_complete' => $shape([
                    'billing_country'   => 'GB',
                    'ship_to_different' => 'yes',
                    'shipping_country'  => 'AU',
                    'shipping_state'    => 'NSW',
                ]),
                'separate_empty'    => $shape([
                    'billing_country'   => 'AU',
                    'billing_state'     => 'NSW',
                    'ship_to_different' => 'yes',
                ]),
            ],
            'with ship-to-different on, only the shipping address decides the payment amount\'s shipping'
        );
    }

    /**
     * Spec: Cases/checkout-functional.md [71]
     *   "With ship-to-different off, shipping fields left over from an earlier toggle do not
     *    decide the payment amount's shipping; the billing address does, the same address
     *    placement validates."
     */
    public function shipToDifferentOffIgnoresLeftoverShippingFields(FunctionalTester $I): void
    {
        [$variation, $percent] = $this->arm($I, ['two_methods' => true]);
        $cart = $this->cartWithSavedMethod($I, $variation, $percent, [
            'billing_country'   => 'AU',
            'billing_state'     => 'NSW',
            'ship_to_different' => 'no',
            'shipping_country'  => 'GB',
        ]);

        $this->loadThePaymentMethod($I, $cart);

        Assert::assertSame(190, $this->paymentShippingCharge($cart), 'the billing address must decide the shipping when ship-to-different is off');
    }

    /**
     * Spec: Cases/checkout-functional.md [72]
     *   "Loading the payment method never changes the cart, even when the saved method does not
     *    serve the address; it only leaves shipping out of the payment amount."
     */
    public function loadingThePaymentMethodNeverChangesTheCart(FunctionalTester $I): void
    {
        [$variation, $percent] = $this->arm($I, ['two_methods' => true]);
        $shape = function (array $formData) use ($I, $variation, $percent) {
            $cart = $this->cartWithSavedMethod($I, $variation, $percent, $formData);
            $before = $this->shippingState($cart);

            $this->loadThePaymentMethod($I, $cart);

            return ['unchanged' => $this->shippingState($cart) === $before, 'payment_shipping' => $this->paymentShippingCharge($cart)];
        };

        Assert::assertSame(
            [
                'served'      => ['unchanged' => true, 'payment_shipping' => 190],
                // GB has no zone: no shipping in the payment amount, and the cart is left alone.
                'not_served'  => ['unchanged' => true, 'payment_shipping' => 0],
                'no_address'  => ['unchanged' => true, 'payment_shipping' => 0],
            ],
            [
                'served'      => $shape(['billing_country' => 'AU', 'billing_state' => 'NSW']),
                'not_served'  => $shape(['billing_country' => 'GB']),
                'no_address'  => $shape([]),
            ],
            'the order-info call must read shipping, never write it'
        );
    }

    /**
     * Tax on, 10% on the standard class for AU, a 10.00 physical product, and a 19% (per
     * price) method for AU. With `two_methods`, a cheaper 10.00 flat AU method too, so the
     * single-method fallback in the tax module cannot recover a lost selection.
     *
     * @return array{0: ProductVariation, 1: ShippingMethod}
     */
    private function arm(FunctionalTester $I, array $options): array
    {
        $I->armTaxModule(['tax_inclusion' => 'excluded']);
        $this->buildStandardTaxRate('AU', '10', $options['rate'] ?? []);
        $variation = $this->buildPhysicalVariation(1000);
        $percent = $this->buildShippingMethod(
            ['title' => 'Nineteen percent', 'amount' => 19, 'settings' => ['configure_rate' => 'per_price']],
            ['region' => 'AU']
        );
        if (!empty($options['two_methods'])) {
            $this->buildShippingMethod(['title' => 'AU Flat', 'amount' => 10], ['region' => 'AU']);
        }

        return [$variation, $percent];
    }

    /**
     * A signed-in customer's cart as `CheckoutPageHandler::render()` leaves it: the saved
     * billing address pushed into form_data (no shipping fields, ship-to-different unset),
     * then the `loading` scope that saves the first method and its per-item share.
     */
    private function signedInCartAsTheCheckoutPageLeavesIt(FunctionalTester $I, ProductVariation $variation): Cart
    {
        $userId = $I->loginAs('customer');
        $customer = (new CustomerFactory())->createForUser($userId, ['first_name' => 'Ticket', 'last_name' => 'Buyer']);
        $cart = $this->buildInstantCart($I, [[$variation, 1]], [
            'user_id'       => $userId,
            'customer_id'   => $customer->id,
            'email'         => $customer->email,
            'checkout_data' => [
                'form_data' => [
                    'billing_full_name' => 'Ticket Buyer',
                    'billing_address_1' => '1 George St',
                    'billing_city'      => 'Sydney',
                    'billing_state'     => 'NSW',
                    'billing_postcode'  => '2000',
                    'billing_country'   => 'AU',
                ],
            ],
        ]);

        $cart = $cart->fresh();
        do_action('fluent_cart/cart/cart_data_items_updated', [
            'cart'       => $cart,
            'scope'      => 'loading',
            'scope_data' => '',
        ]);

        return $cart;
    }

    /**
     * A cart holding the given address fields with the 19% method saved and its 190-cent share
     * on the item, as the shipping module leaves it after selecting that method.
     */
    private function cartWithSavedMethod(FunctionalTester $I, ProductVariation $variation, ShippingMethod $method, array $formData): Cart
    {
        $cart = $this->buildInstantCart($I, [[$variation, 1]], [
            'checkout_data' => [
                'form_data'     => $formData,
                'shipping_data' => ['shipping_method_id' => (int) $method->id, 'shipping_charge' => 190],
            ],
        ]);
        $items = $cart->cart_data;
        foreach ($items as $key => $item) {
            $items[$key]['itemwise_shipping_charge'] = 190;
        }
        $cart->cart_data = $items;
        $cart->save();

        return $cart;
    }

    /**
     * What embedded Stripe does on page load: the real `get_order_info` AJAX ingress.
     */
    private function loadThePaymentMethod(FunctionalTester $I, Cart $cart): void
    {
        $I->checkoutAjax(['fc_checkout_action' => 'get_order_info', 'method' => 'stripe', 'fct_cart_hash' => $cart->cart_hash]);
    }

    /**
     * The shipping charge Stripe, PayPal, and Paddle add to their payment amount.
     */
    private function paymentShippingCharge(Cart $cart): int
    {
        return (int) Arr::get((new WebCheckoutHandler())->getShippingChargeData($cart->fresh()), 'charge');
    }

    /**
     * @return array{method: int|null, charge: int, shares: int[], shipping_tax: int}
     */
    private function shippingState(Cart $cart): array
    {
        $cart = $cart->fresh();
        $method = Arr::get($cart->checkout_data, 'shipping_data.shipping_method_id');

        return [
            'method'       => $method === null ? null : (int) $method,
            'charge'       => (int) Arr::get($cart->checkout_data, 'shipping_data.shipping_charge', 0),
            'shares'       => array_map(function ($item) {
                return (int) Arr::get($item, 'itemwise_shipping_charge', 0);
            }, array_values((array) $cart->cart_data)),
            'shipping_tax' => (int) Arr::get($cart->checkout_data, 'tax_data.shipping_tax', 0),
        ];
    }

    /**
     * Submit the cart with an AU billing address, ship-to-different off, and the given method
     * in both shipping fields. Returns the stored order's money columns.
     *
     * @return array{shipping_total: int, shipping_tax: int, tax_total: int, total_amount: int}
     */
    private function placeAuOrder(FunctionalTester $I, Cart $cart, int $methodId): array
    {
        [$status, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, $this->shippingChoice($methodId) + [
            'billing_full_name'  => 'Ticket Buyer',
            'billing_first_name' => 'Ticket',
            'billing_last_name'  => 'Buyer',
            'billing_address_1'  => '1 George St',
            'billing_city'       => 'Sydney',
            'billing_state'      => 'NSW',
            'billing_postcode'   => '2000',
            'billing_country'    => 'AU',
        ]));

        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order must succeed. HTTP ' . $status . ' Body: ' . wp_json_encode($body));
        $order = Order::query()->orderBy('id', 'DESC')->first();

        return [
            'shipping_total' => (int) $order->shipping_total,
            'shipping_tax'   => (int) $order->shipping_tax,
            'tax_total'      => (int) $order->tax_total,
            'total_amount'   => (int) $order->total_amount,
        ];
    }
}
