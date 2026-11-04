<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesCheckoutOrders;

/**
 * Shipping is taxed by the store-level inclusion setting, even when every product
 * overrides it. Whatever the mix, the placed order total must equal the checkout total.
 * One $10 physical item, $10 flat shipping, one US 10% rate.
 */
class InclusivePricesExclusiveShippingTaxCest
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
     * Exclusive store, product priced with tax included: the item tax is carved out,
     * the shipping tax is added on top, and the order total keeps that added tax.
     */
    public function orderTotalAddsShippingTaxWhenOnlyTheProductIsTaxInclusive(FunctionalTester $I): void
    {
        $row = $this->placeOrder($I, 'excluded', 'included');

        Assert::assertSame(2, (int) $row['tax_behavior'], 'every line is tax-inclusive');
        Assert::assertSame(91, (int) $row['tax_total'], 'item tax is the 10/110 share of 1000 cents');
        Assert::assertSame(100, (int) $row['shipping_tax'], 'shipping is taxed 10% on top of 1000 cents');
        Assert::assertSame(2100, (int) $row['total_amount'], 'the total must add the 100-cent shipping tax');
        Assert::assertSame((int) $row['_checkout_total'], (int) $row['total_amount'], 'the order total must equal the checkout total');

        global $wpdb;
        $charge = $wpdb->get_var($wpdb->prepare("SELECT total FROM {$wpdb->prefix}fct_order_transactions WHERE order_id = %d", $row['id']));
        Assert::assertSame(2100, (int) $charge, 'the gateway is asked to charge the same total');
    }

    /** Control: exclusive store, exclusive product — item and shipping tax both added. */
    public function exclusiveStoreTotalIsUnchanged(FunctionalTester $I): void
    {
        $row = $this->placeOrder($I, 'excluded', '');

        Assert::assertSame(2200, (int) $row['total_amount'], 'items + shipping + 100 item tax + 100 shipping tax');
        Assert::assertSame((int) $row['_checkout_total'], (int) $row['total_amount'], 'the order total must equal the checkout total');
    }

    /** Control: inclusive store whose rate does not tax shipping — nothing is added. */
    public function inclusiveStoreWithUntaxedShippingTotalIsUnchanged(FunctionalTester $I): void
    {
        $row = $this->placeOrder($I, 'included', '', ['for_shipping' => '0']);

        Assert::assertSame(0, (int) $row['shipping_tax'], 'a 0% shipping rate taxes nothing');
        Assert::assertSame(2000, (int) $row['total_amount'], 'items + shipping only');
        Assert::assertSame((int) $row['_checkout_total'], (int) $row['total_amount'], 'the order total must equal the checkout total');
    }

    /** Control: inclusive store, shipping tax carved out of the shipping price. */
    public function allInclusiveTotalIsUnchanged(FunctionalTester $I): void
    {
        $row = $this->placeOrder($I, 'included', '');

        Assert::assertSame(91, (int) $row['shipping_tax'], 'shipping tax is the 10/110 share of 1000 cents');
        Assert::assertSame(2000, (int) $row['total_amount'], 'inclusive tax must not raise the total');
        Assert::assertSame((int) $row['_checkout_total'], (int) $row['total_amount'], 'the order total must equal the checkout total');
    }

    /**
     * Places one order and returns its fct_orders row plus `_checkout_total`, the cart's
     * estimated total read just before place-order.
     */
    private function placeOrder(FunctionalTester $I, string $storeInclusion, string $productInclusion, array $rateOverrides = []): array
    {
        $I->armTaxModule(['tax_inclusion' => $storeInclusion]);
        $this->buildStandardTaxRate('US', '10', $rateOverrides);

        $otherInfo = ['payment_type' => 'onetime'];
        if ($productInclusion) {
            $otherInfo['tax_inclusion'] = $productInclusion;
        }
        $variation = $this->buildPhysicalVariation(1000, ['other_info' => $otherInfo]);
        $method = $this->buildShippingMethod(['amount' => 10]);
        $cart = $this->buildInstantCart($I, [[$variation, 1]]);

        [$patchStatus, $patchBody] = $I->checkoutAjax($this->checkoutPatchParams($cart->cart_hash, 'US', 'NY', (int) $method->id));
        Assert::assertSame(200, $patchStatus, 'precondition: the checkout patch must succeed. Body: ' . wp_json_encode($patchBody));
        $checkoutTotal = (int) Cart::query()->find($cart->cart_hash)->getEstimatedTotal();

        [, $body] = $I->postCapturingJsonDie('/fluent-cart/v2/checkout/place-order', $this->checkoutPayload($cart->cart_hash, $this->shippingChoice((int) $method->id)));
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order must succeed. Body: ' . wp_json_encode($body));
        Assert::assertSame(1, Order::query()->count(), 'one submission leaves exactly one order');

        global $wpdb;
        $row = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}fct_orders ORDER BY id DESC LIMIT 1", ARRAY_A);
        $row['_checkout_total'] = $checkoutTotal;

        return $row;
    }
}
