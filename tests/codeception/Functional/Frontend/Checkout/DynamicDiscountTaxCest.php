<?php

namespace Tests\Functional\Frontend\Checkout;

use FluentCart\App\Models\Cart;
use FluentCart\Framework\Support\Arr;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\PlacesCheckoutOrders;

/**
 * Tax is charged on the price after dynamic pricing rules (DP-001, DP-006).
 *
 * Pro is not loaded in this suite, so the hooks below stand in for its rules:
 * a 10% line discount on `fluent_cart/cart/item_dynamic_discount`, written into
 * the saved cart on `before_order_processing` the way DynamicDiscountPersister
 * does, and a shipping rule on `fluent_cart/checkout/order_shipping_charge`.
 */
class DynamicDiscountTaxCest
{
    use PlacesCheckoutOrders;

    /** @var array<int, array{0: string, 1: callable, 2: int}> */
    private $ownHooks = [];

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->logout();
        $this->registerStubCheckoutGateway();
    }

    public function _after(FunctionalTester $I): void
    {
        foreach ($this->ownHooks as $hook) {
            remove_filter($hook[0], $hook[1], $hook[2]);
        }
        $this->ownHooks = [];

        $this->removeStubCheckoutGateway();
        $I->disarmTaxModule();
        $I->disarmStockListeners();
    }

    public function orderTaxIsChargedOnTheDiscountedItems(FunctionalTester $I): void
    {
        $cart = $this->taxedPhysicalCart($I, 'excluded');
        $this->lineItemRule(10);

        $row = $this->placeOrder($I, $cart);

        Assert::assertSame(400, (int) $row['manual_discount_total'], 'precondition: the rule takes 10% of 4000');
        Assert::assertSame(360, (int) $row['tax_total'], 'item tax must be 10% of the discounted 3600, not of 4000');
        Assert::assertSame(50, (int) $row['shipping_tax'], 'shipping tax is untouched by an item rule');
        Assert::assertSame(4510, (int) $row['total_amount'], '3600 items + 360 tax + 500 shipping + 50 shipping tax');

        $itemTax = $this->orderItemTax((int) $row['id']);
        Assert::assertSame(360, $itemTax, 'the order line carries the tax of its discounted price');
    }

    public function inclusiveTaxIsCarvedFromTheDiscountedItems(FunctionalTester $I): void
    {
        $cart = $this->taxedPhysicalCart($I, 'included');
        $this->lineItemRule(10);

        $row = $this->placeOrder($I, $cart);

        Assert::assertSame(327, (int) $row['tax_total'], 'item tax must be the 10/110 share of the discounted 3600');
        Assert::assertSame(45, (int) $row['shipping_tax'], 'shipping tax stays the 10/110 share of 500');
        Assert::assertSame(4100, (int) $row['total_amount'], 'inclusive tax does not raise the total');
    }

    public function freeShippingFromARuleChargesNoShippingTax(FunctionalTester $I): void
    {
        $cart = $this->taxedPhysicalCart($I, 'excluded');
        $this->shippingRule(100);

        $row = $this->placeOrder($I, $cart);

        Assert::assertSame(0, (int) $row['shipping_total'], 'precondition: the rule makes shipping free');
        Assert::assertSame(0, (int) $row['shipping_tax'], 'free shipping must carry no shipping tax');
        Assert::assertSame(400, (int) $row['tax_total'], 'item tax is untouched by a shipping rule');
        Assert::assertSame(4400, (int) $row['total_amount'], '4000 items + 400 tax, nothing for shipping');
    }

    public function halfPriceShippingIsTaxedOnTheHalf(FunctionalTester $I): void
    {
        $cart = $this->taxedPhysicalCart($I, 'excluded');
        $this->shippingRule(50);

        $row = $this->placeOrder($I, $cart);

        Assert::assertSame(250, (int) $row['shipping_total'], 'precondition: the rule halves shipping');
        Assert::assertSame(25, (int) $row['shipping_tax'], 'shipping tax must be 10% of the 250 charged');
        Assert::assertSame(4675, (int) $row['total_amount'], '4000 + 400 tax + 250 shipping + 25 shipping tax');
    }

    public function checkoutSummaryShowsTheSameTaxAsTheOrder(FunctionalTester $I): void
    {
        $this->lineItemRule(10);
        $this->shippingRule(100);
        // Hooked before the patch, so the tax it saves is the tax the summary shows.
        $cart = $this->taxedPhysicalCart($I, 'excluded');

        // The total row of the checkout summary (CartSummaryRender).
        $preview = (int) Cart::query()->find($cart->cart_hash)->getEstimatedTotal();
        Assert::assertSame(3960, $preview, 'preview total: 3600 items + 360 tax, free shipping with no shipping tax');

        $row = $this->placeOrder($I, $cart);
        Assert::assertSame(3960, (int) $row['total_amount'], 'the order charges what the summary showed');
    }

    /**
     * 2 × 2000 physical items, a 500 flat rate and a 10% US rate, with the
     * address and shipping saved the way the checkout page saves them.
     */
    private function taxedPhysicalCart(FunctionalTester $I, string $inclusion): Cart
    {
        $I->armTaxModule(['tax_inclusion' => $inclusion]);
        $this->buildStandardTaxRate('US', '10');
        $variation = $this->buildPhysicalVariation(2000);
        $method = $this->buildShippingMethod(['amount' => 5]);
        $this->methodId = (int) $method->id;
        $cart = $this->buildInstantCart($I, [[$variation, 2]]);

        [$status, $body] = $I->checkoutAjax($this->checkoutPatchParams($cart->cart_hash, 'US', 'NY', $this->methodId));
        Assert::assertSame(200, $status, 'precondition: the address/shipping patch must succeed. Body: ' . wp_json_encode($body));

        return $cart;
    }

    /** @var int */
    private $methodId = 0;

    private function placeOrder(FunctionalTester $I, Cart $cart): array
    {
        [, $body] = $I->postCapturingJsonDie(
            '/fluent-cart/v2/checkout/place-order',
            $this->checkoutPayload($cart->cart_hash, $this->shippingChoice($this->methodId))
        );
        Assert::assertSame('success', (string) ($body['status'] ?? ''), 'place-order must succeed. Body: ' . wp_json_encode($body));

        global $wpdb;
        return $wpdb->get_row("SELECT * FROM {$wpdb->prefix}fct_orders ORDER BY id DESC LIMIT 1", ARRAY_A);
    }

    private function orderItemTax(int $orderId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT SUM(tax_amount) FROM {$wpdb->prefix}fct_order_items WHERE order_id = %d",
            $orderId
        ));
    }

    /**
     * A percentage line rule, added to manual_discount like Pro's discountItem().
     */
    private function lineItemRule(int $percent): void
    {
        $discount = function ($items) use ($percent) {
            foreach ((array) $items as $key => $item) {
                $subtotal = (int) Arr::get($item, 'subtotal', 0);
                $manual = (int) Arr::get($item, 'manual_discount', 0) + (int) round($subtotal * $percent / 100);
                $total = min($manual + (int) Arr::get($item, 'coupon_discount', 0), $subtotal);

                $item['manual_discount'] = $manual;
                $item['discount_total'] = $total;
                $item['line_total'] = $subtotal - $total;
                $items[$key] = $item;
            }
            return $items;
        };
        $this->hook('fluent_cart/cart/item_dynamic_discount', $discount, 10, 2);

        // Write the discount into the saved cart once, as DynamicDiscountPersister does.
        $persist = function ($data) use ($discount) {
            $cart = Arr::get($data, 'cart');
            $cart->cart_data = $discount($cart->cart_data);
            $cart->save();
        };
        $this->hook('fluent_cart/checkout/before_order_processing', $persist, 10, 1);
    }

    private function shippingRule(int $percent): void
    {
        $this->hook('fluent_cart/checkout/order_shipping_charge', function ($charge) use ($percent) {
            return (int) $charge - (int) round((int) $charge * $percent / 100);
        }, 10, 2);

        // The display side of the same rule, as CartAdjustmentApplier::discountShipping() does.
        $this->hook('fluent_cart/cart/estimated_total', function ($total, $data) use ($percent) {
            $shipping = (int) Arr::get($data, 'cart')->getShippingTotal();
            return max(0, (int) $total - (int) round($shipping * $percent / 100));
        }, 10, 2);
    }

    private function hook(string $name, callable $callback, int $priority, int $args): void
    {
        add_filter($name, $callback, $priority, $args);
        $this->ownHooks[] = [$name, $callback, $priority];
    }
}
