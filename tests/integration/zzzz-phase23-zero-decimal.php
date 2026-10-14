<?php

use FluentCart\App\Helpers\CheckoutProcessor;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\TaxClass;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\App\Modules\Tax\TaxCalculator;
use FluentCart\App\Services\Coupon\DiscountService;

return [[
    'id' => 'phase23-zero-decimal-stored-amounts',
    'name' => 'JPY coupon and tax calculations persist whole units on every money row',
    'phase' => 23,
    'known_failure' => false,
    'run' => function () {
        $taxSettings = function () {
            return ['enable_tax' => 'yes', 'tax_inclusion' => 'excluded', 'tax_calculation_basis' => 'billing', 'tax_rounding' => 'item'];
        };
        add_filter('pre_option_fluent_cart_tax_configuration_settings', $taxSettings);
        TaxCalculator::resetCache();
        try {
            CurrencyContext::withCurrency('JPY', function () {
                $customer = FcFixture::customer();
                $order = FcFixture::order(['currency' => 'JPY', 'subtotal' => 30000, 'total_amount' => 30000]);
                $standard = TaxClass::query()->where('slug', 'standard')->first();
                FcTest::assert($standard !== null, 'standard tax class exists');
                FcFixture::taxRate((int) $standard->id, ['country' => 'XZ', 'state' => 'ST', 'postcode' => '99123', 'rate' => 8]);
                $items = [];
                $product = FcDomainFixture::product(['item_price' => 10000, 'other_info' => ['tax_class' => 'standard', 'tax_exempt' => 'no']]);
                foreach ([10000, 20000] as $index => $amount) {
                    $items[] = [
                        'id' => $index + 1, 'object_id' => (int) $product['variation']->id,
                        'post_id' => (int) $product['post']->ID, 'quantity' => 1,
                        'post_title' => 'Owned JPY item', 'title' => 'Owned JPY item', 'fulfillment_type' => 'digital',
                        'unit_price' => $amount, 'price' => $amount, 'subtotal' => $amount, 'line_total' => $amount,
                        'discount_total' => 0, 'coupon_discount' => 0, 'manual_discount' => 0,
                        'other_info' => ['payment_type' => 'onetime'],
                    ];
                }
                $discounts = new DiscountService(null, $items);
                $result = $discounts->apply(new Coupon([
                    'code' => 'INERT-JPY', 'type' => 'percentage', 'amount' => 50,
                    'conditions' => ['max_discount_amount' => 1000],
                ]));
                FcTest::assertSame(true, $result, 'in-memory coupon applies');
                $calculator = new TaxCalculator($discounts->getCartItems(), ['country' => 'XZ', 'state' => 'ST', 'postcode' => '99123']);
                $processor = new CheckoutProcessor($calculator->getTaxedLines(), [
                    'customer_id' => (int) $customer->id, 'tax_total' => $calculator->getTotalTax(), 'tax_behavior' => 1,
                    'payment_method' => '', 'note' => FcFixture::orderMarker(), 'is_locked' => false,
                    'currency_snapshot' => CurrencySnapshot::legacy('JPY', 150),
                ]);
                $processor->createDraftOrder($order);
                $stored = FcFixture::reloadOrder((int) $order->id);
                $lines = OrderItem::query()->where('order_id', $order->id)->get();
                $transaction = OrderTransaction::query()->where('order_id', $order->id)->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)->first();
                FcTest::assertSame('JPY', $stored->currency, 'stored currency');
                FcTest::assertSame(1000, (int) $stored->coupon_discount_total, 'coupon cap is conserved');
                FcTest::assertSame(2400, (int) $stored->tax_total, 'tax rounds per line to whole yen');
                FcTest::assertSame(31400, (int) $stored->total_amount, 'payable equals net lines plus tax');
                foreach (['subtotal', 'coupon_discount_total', 'manual_discount_total', 'tax_total', 'shipping_tax', 'shipping_total', 'total_amount', 'total_paid', 'total_refund'] as $field) {
                    FcTest::assert(array_key_exists($field, $stored->getAttributes()), 'Order.' . $field . ' exists');
                    FcTest::assertSame(0.0, fmod((float) $stored->$field, 100), 'Order.' . $field . ' is whole yen');
                }
                FcTest::assertSame(2, $lines->count(), 'both lines persist');
                foreach ($lines as $line) {
                    foreach (['unit_price', 'subtotal', 'discount_total', 'tax_amount', 'shipping_charge', 'line_total', 'refund_total'] as $field) {
                        FcTest::assert(array_key_exists($field, $line->getAttributes()), 'OrderItem.' . $field . ' exists');
                        FcTest::assertSame(0.0, fmod((float) $line->$field, 100), 'OrderItem.' . $field . ' is whole yen');
                    }
                }
                FcTest::assert($transaction !== null, 'pending charge is stored');
                FcTest::assertSame(31400, (int) $transaction->total, 'charge matches stored payable');
                FcTest::assertSame(Status::PAYMENT_PENDING, $stored->payment_status, 'no payment completes');
            });
        } finally {
            remove_filter('pre_option_fluent_cart_tax_configuration_settings', $taxSettings);
            TaxCalculator::resetCache();
            FcDomainFixture::cleanupAll();
        }
    },
]];
