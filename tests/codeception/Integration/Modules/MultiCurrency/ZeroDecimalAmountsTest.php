<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\StripeHelper;
use FluentCart\App\Modules\Tax\TaxCalculator;
use FluentCart\App\Services\Coupon\DiscountService;
use FluentCart\App\Services\Payments\Refund;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CartFactory;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\ShippingMethodFactory;
use Tests\Support\Factory\ShippingZoneFactory;
use Tests\Support\Factory\TaxClassFactory;
use Tests\Support\Factory\TaxRateFactory;
use Tests\Support\Traits\BuildsTaxFixtures;

class ZeroDecimalAmountsTest extends DatabaseTestCase
{
    use BuildsTaxFixtures;
    use \Tests\Support\Traits\ConfiguresStripeTestMode;

    public function tearDown(): void
    {
        $this->restoreStoreCurrency();
        $this->resetTaxRuntimeState();
        parent::tearDown();
    }

    public function test_capped_coupon_shares_and_recurring_discounts_keep_whole_yen(): void
    {
        $this->setStoreCurrency('JPY');
        $items = [];
        foreach ([10000, 20000] as $index => $amount) {
            $items[] = CartFactory::makeCartItem([
                'id' => $index + 1, 'unit_price' => $amount, 'subtotal' => $amount,
                'line_total' => $amount, 'other_info' => ['payment_type' => 'subscription'],
            ]);
        }
        $cart = (new CartFactory())->withCartItems($items)->create();
        $coupon = (new CouponFactory())->create([
            'type' => 'percentage', 'amount' => 50,
            'conditions' => ['max_discount_amount' => 1000, 'is_recurring' => 'yes'],
        ]);
        $result = (new DiscountService($cart))->applyCouponCodes([$coupon->code]);
        $this->assertIsArray($result, 'The coupon applies successfully');
        $this->assertSame(1000, array_sum(array_column($result['cart_items'], 'coupon_discount')), 'The cap is conserved');
        $this->assertSame(1000, array_sum(array_column(array_column($result['cart_items'], 'recurring_discounts'), 'amount')), 'Renewal shares also conserve the cap');
        foreach ($result['cart_items'] as $item) {
            foreach (['coupon_discount', 'discount_total', 'line_total'] as $field) {
                $this->assertSame(0, $item[$field] % 100, $field . ' contains whole yen');
            }
            $this->assertSame(0, $item['recurring_discounts']['amount'] % 100, 'Renewal discounts contain whole yen');
        }
    }

    public function test_fixed_coupon_preserves_the_full_discount_in_whole_yen(): void
    {
        $this->setStoreCurrency('JPY');
        $items = [];
        foreach ([747000, 759500] as $index => $amount) {
            $items[] = CartFactory::makeCartItem(['id' => $index + 1, 'unit_price' => $amount, 'subtotal' => $amount, 'line_total' => $amount]);
        }
        $cart = (new CartFactory())->withCartItems($items)->create();
        $coupon = (new CouponFactory())->create(['amount' => 75000]);
        $result = (new DiscountService($cart))->applyCouponCodes([$coupon->code]);
        $this->assertSame(75000, array_sum(array_column($result['cart_items'], 'coupon_discount')), 'The entire fixed discount is allocated');
        foreach ($result['cart_items'] as $item) {
            $this->assertSame(0, $item['coupon_discount'] % 100, 'Coupon shares contain whole yen');
            $this->assertSame(0, $item['line_total'] % 100, 'Net lines contain whole yen');
        }
    }

    /** @dataProvider shippingPaths */
    public function test_shipping_shares_keep_whole_yen_and_conserve_the_charge(bool $byProfile): void
    {
        $this->setStoreCurrency('JPY');
        $zone = (new ShippingZoneFactory())->create(['region' => 'US']);
        $method = (new ShippingMethodFactory())->create([
            'zone_id' => $zone->id, 'type' => 'flat_rate', 'amount' => 1,
            'settings' => ['configure_rate' => 'per_order'],
        ]);
        $items = [];
        foreach ([10000, 20000, 30000] as $amount) {
            $items[] = ['post_id' => 0, 'quantity' => 1, 'unit_price' => $amount,
                'line_total' => $amount, 'discount_total' => 0, 'fulfillment_type' => 'physical',
                'shipping_charge' => 0, 'itemwise_shipping_charge' => 0];
        }
        $shipping = $byProfile
            ? CartHelper::calculateShippingByProfile($method->id, $items, 'US', null, 'items')
            : CartHelper::calculateShippingMethodCharge($method, $items, 'items');
        $total = $shipping['shipping_amount'];
        $items = $shipping['items'];
        $this->assertEquals(100, $total, 'The customer pays one yen shipping');
        $this->assertSame(100, array_sum(array_column($items, 'itemwise_shipping_charge')), 'Shares conserve the charge');
        foreach ($items as $item) {
            $this->assertSame(0, $item['itemwise_shipping_charge'] % 100, 'Each shipping share is whole yen');
            $this->assertGreaterThanOrEqual(0, $item['itemwise_shipping_charge'], 'No negative remainder');
        }
    }

    public static function shippingPaths(): array
    {
        return [[false], [true]];
    }

    public function test_tax_below_half_yen_does_not_round_up_twice(): void
    {
        $this->setStoreCurrency('JPY');
        $this->setTaxSettings([]);
        $this->resetTaxRuntimeState();
        $this->enableTax('excluded', 'billing', 'item');
        $class = (new TaxClassFactory())->create();
        $product = $this->makeProductWithClass($class->id);
        (new TaxRateFactory())->create(['class_id' => $class->id, 'country' => 'US', 'rate' => '8']);
        $calculator = new TaxCalculator([$this->buildLineItem($product->ID, ['subtotal' => 12400])], ['country' => 'US']);
        $this->assertSame(1000, $calculator->getTotalTax(), '9.92 yen rounds to ten yen');
        (new TaxRateFactory())->create(['class_id' => $class->id, 'country' => 'CA', 'rate' => '8.0045']);
        $calculator = new TaxCalculator([$this->buildLineItem($product->ID, ['subtotal' => 124300])], ['country' => 'CA']);
        $this->assertSame(9900, $calculator->getTotalTax(), '99.495935 yen rounds down once');
    }

    /** @dataProvider refundCurrencies */
    public function test_fractional_refund_is_rejected_before_any_write_or_transport(string $currency): void
    {
        $order = (new OrderFactory())->create([
            'customer_id' => (new CustomerFactory())->create()->id, 'currency' => $currency,
            'total_amount' => 20000, 'total_paid' => 20000, 'payment_status' => Status::PAYMENT_PAID,
        ]);
        $transaction = (new OrderTransactionFactory())->create($order->id, [
            'currency' => $currency, 'total' => 20000, 'status' => Status::TRANSACTION_SUCCEEDED,
            'payment_method' => 'stripe', 'vendor_charge_id' => 'pi_zero_decimal_test',
        ]);
        $calls = 0;
        $block = function () use (&$calls) { $calls++; return new \WP_Error('blocked', 'Unexpected HTTP'); };
        add_filter('pre_http_request', $block);
        try {
            $result = (new Refund())->processRefund($transaction, 10050);
            $this->assertWPError($result, 'A fraction of a currency unit must be rejected');
            $this->assertSame(1, OrderTransaction::query()->where('order_id', $order->id)->count(), 'No local refund was created');
            $this->assertEquals(0, $order->fresh()->total_refund, 'The order refund balance is unchanged');
            $result = StripeHelper::processRemoteRefund($transaction, 10050, []);
            $this->assertWPError($result, 'Direct gateway callers cannot truncate the refund');
            $this->assertSame(0, $calls, 'Invalid refunds never reach HTTP');
        } finally {
            remove_filter('pre_http_request', $block);
        }
    }

    /** @dataProvider validRefundAmounts */
    public function test_valid_refund_records_the_amount_sent_to_stripe(string $currency, int $amount, int $wire): void
    {
        $this->enableStripeTestMode();
        $order = (new OrderFactory())->create([
            'customer_id' => (new CustomerFactory())->create()->id, 'currency' => $currency,
            'total_amount' => 30000, 'total_paid' => 30000, 'payment_status' => Status::PAYMENT_PAID,
        ]);
        $transaction = (new OrderTransactionFactory())->create($order->id, [
            'currency' => $currency, 'total' => 30000, 'status' => Status::TRANSACTION_SUCCEEDED,
            'payment_method' => 'stripe', 'payment_mode' => 'test', 'vendor_charge_id' => 'pi_zero_decimal_test',
        ]);
        $requests = [];
        $mock = function ($pre, $args, $url) use (&$requests) {
            $requests[] = [$url, $args];
            return ['headers' => [], 'response' => ['code' => 200],
                'body' => wp_json_encode(['id' => 're_whole_unit', 'status' => 'succeeded'])];
        };
        add_filter('pre_http_request', $mock, 10, 3);
        try {
            $result = (new Refund())->processRefund($transaction, $amount);
            $this->assertIsArray($result, 'A valid refund succeeds');
            $this->assertSame('re_whole_unit', $result['vendor_refund_id'], 'Stripe accepted the refund');
            $this->assertCount(1, $requests, 'Exactly one fake HTTP request');
            $this->assertSame('https://api.stripe.com/v1/refunds', $requests[0][0], 'The request creates a refund');
            parse_str($requests[0][1]['body'], $body);
            $this->assertSame($wire, (int) $body['amount'], 'Stripe receives the exact currency unit');
            $this->assertEquals($amount, $result['refund_transaction']->fresh()->total, 'Stored refund matches the requested amount');
            $this->assertEquals($amount, $order->fresh()->total_refund, 'The order balance matches the refund');
        } finally {
            remove_filter('pre_http_request', $mock, 10);
        }
    }

    public static function validRefundAmounts(): array
    {
        return [['JPY', 10000, 100], ['UGX', 10000, 10000], ['USD', 10050, 10050]];
    }

    public static function refundCurrencies(): array
    {
        return [['JPY'], ['UGX'], ['ISK']];
    }
}
