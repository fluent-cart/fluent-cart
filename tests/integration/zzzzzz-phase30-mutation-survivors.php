<?php
/**
 * Phase 30 closures for the five survivors recorded by the Phase 28 audit.
 */

use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\TaxClass;
use FluentCart\App\Modules\Tax\TaxCalculator;
use FluentCart\App\Services\Coupon\CouponServiceAdmin;
use FluentCart\App\Services\Payments\Refund;

$phase30RestoreOption = function ($key, $previous, $missing) {
    if ($previous === $missing) {
        delete_option($key);
        return;
    }

    update_option($key, $previous, false);
};

return [
    [
        'id'            => 'phase30-coupon-independent-maximum-cap',
        'name'          => 'Coupon maximum caps a line below both face value and eligible subtotal',
        'kind'          => 'behavior',
        'phase'         => 30,
        'known_failure' => false,
        'run'           => function () {
            $requestKey = Helper::INSTANT_CHECKOUT_URL_PARAM;
            $previousHash = App::request()->get($requestKey);

            try {
                $product = FcDomainFixture::product(['item_price' => 5000]);
                FcFixture::customer();
                $inertOrder = FcFixture::order([
                    'subtotal'     => 5000,
                    'tax_total'    => 0,
                    'total_amount' => 5000,
                ]);
                $coupon = FcFixture::coupon([
                    'status'     => 'active',
                    'type'       => 'fixed',
                    'amount'     => 4000,
                    'stackable'  => 'yes',
                    'conditions' => ['max_discount_amount' => 1001],
                ]);
                $line = [
                    'id'             => (int) $product['variation']->id,
                    'post_id'        => (int) $product['post']->ID,
                    'quantity'       => 1,
                    'unit_price'     => 5000,
                    'subtotal'       => 5000,
                    'discount_total' => 0,
                    'other_info'     => [],
                ];

                App::request()->set($requestKey, null);
                CartResource::resetCartCache();
                $service = new CouponServiceAdmin([$line]);
                $service->applyCoupon((string) $coupon->code);
                $calculated = $service->getCalculatedLineItems();
                $discount = $service->getDiscountData();
                $calculatedLine = $calculated[(int) $product['variation']->id];
                $storedOrder = FcFixture::reloadOrder((int) $inertOrder->id);

                FcTest::assertSame(
                    [5000, 4000, 1001],
                    [(int) $line['subtotal'], (int) $coupon->amount, (int) $coupon->conditions['max_discount_amount']],
                    'subtotal, face value, and maximum are three independent boundaries'
                );
                FcTest::assertSame(1001, (int) $calculatedLine['coupon_discount'], 'line discount stops at the independent maximum');
                FcTest::assertSame(
                    3999,
                    (int) $calculatedLine['subtotal'] - (int) $calculatedLine['coupon_discount'],
                    'line retains the exact post-cap payable cents'
                );
                FcTest::assertSame(1001, (int) $discount[$coupon->code]['discount'], 'discount ledger stops at the same maximum');
                FcTest::assertSame(
                    [Status::PAYMENT_PENDING, 0, ''],
                    [(string) $storedOrder->payment_status, (int) $storedOrder->total_paid, (string) $storedOrder->payment_method],
                    'coupon calculation leaves the owned Order inert'
                );
            } finally {
                App::request()->set($requestKey, $previousHash);
                CartResource::resetCartCache();
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase30-local-refund-amount-reconciliation',
        'name'          => 'Provider refund identity attaches only to the same-parent local refund with matching amount',
        'kind'          => 'behavior',
        'phase'         => 30,
        'known_failure' => false,
        'run'           => function () {
            try {
                FcFixture::customer();
                $order = FcFixture::order([
                    'subtotal'     => 5000,
                    'tax_total'    => 0,
                    'total_amount' => 5000,
                ]);
                $base = [
                    'order_id'            => (int) $order->id,
                    'order_type'          => (string) $order->type,
                    'payment_method'      => 'stripe',
                    'payment_mode'        => 'test',
                    'payment_method_type' => '',
                    'currency'            => 'USD',
                    'rate'                => 1,
                ];
                $parent = OrderTransaction::query()->create(array_merge($base, [
                    'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
                    'status'           => Status::TRANSACTION_PENDING,
                    'total'            => 5000,
                    'meta'             => [],
                ]));
                $matching = OrderTransaction::query()->create(array_merge($base, [
                    'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
                    'vendor_charge_id' => '',
                    'status'           => Status::TRANSACTION_REFUNDED,
                    'total'            => 1001,
                    'meta'             => ['parent_id' => (int) $parent->id, 'reason' => 'matching local refund'],
                ]));
                $decoy = OrderTransaction::query()->create(array_merge($base, [
                    'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
                    'vendor_charge_id' => '',
                    'status'           => Status::TRANSACTION_REFUNDED,
                    'total'            => 2002,
                    'meta'             => ['parent_id' => (int) $parent->id, 'reason' => 'different amount decoy'],
                ]));
                foreach ([$parent, $matching, $decoy] as $transaction) {
                    FcTest::assert((int) $transaction->id > 0, 'exact owned transaction ID is captured');
                }

                $result = Refund::createOrRecordRefund([
                    'vendor_charge_id' => 're_phase30_matching_1001',
                    'total'            => 1001,
                    'status'           => Status::TRANSACTION_REFUNDED,
                    'reason'           => 'provider reconciliation',
                ], $parent);
                $storedMatching = OrderTransaction::query()->find((int) $matching->id);
                $storedDecoy = OrderTransaction::query()->find((int) $decoy->id);
                $storedParent = OrderTransaction::query()->find((int) $parent->id);
                $storedOrder = FcFixture::reloadOrder((int) $order->id);
                $transactionCount = OrderTransaction::query()->where('order_id', (int) $order->id)->count();

                FcTest::assertSame((int) $matching->id, (int) $result->id, 'matching local refund is reconciled');
                FcTest::assertSame(3, (int) $transactionCount, 'reconciliation creates no duplicate transaction');
                FcTest::assertSame(
                    ['re_phase30_matching_1001', '', 1001, 2002, 5000],
                    [
                        (string) $storedMatching->vendor_charge_id,
                        (string) $storedDecoy->vendor_charge_id,
                        (int) $storedMatching->total,
                        (int) $storedDecoy->total,
                        (int) $storedParent->total,
                    ],
                    'provider identity attaches only to the exact matching amount and leaves all money unchanged'
                );
                FcTest::assertSame(
                    [Status::PAYMENT_PENDING, 0, 0, ''],
                    [
                        (string) $storedOrder->payment_status,
                        (int) $storedOrder->total_paid,
                        (int) $storedOrder->total_refund,
                        (string) $storedOrder->payment_method,
                    ],
                    'local reconciliation leaves the owned Order inert and its refund ledger untouched'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
    [
        'id'            => 'phase30-tax-postcode-upper-endpoint',
        'name'          => 'Tax postcode ranges include the upper endpoint and exclude the next value',
        'kind'          => 'behavior',
        'phase'         => 30,
        'known_failure' => false,
        'run'           => function () use ($phase30RestoreOption) {
            $option = 'fluent_cart_tax_configuration_settings';
            $missing = '__phase30_missing_' . wp_generate_password(20, false, false);
            $previous = get_option($option, $missing);

            try {
                $product = FcDomainFixture::product([
                    'item_price' => 100,
                    'other_info' => [],
                ]);
                FcFixture::customer();
                $inertOrder = FcDomainFixture::orderWithItem(
                    (int) $product['post']->ID,
                    (int) $product['variation']->id,
                    1,
                    ['subtotal' => 100, 'tax_total' => 0, 'total_amount' => 100]
                );
                $standard = TaxClass::query()->where('slug', 'standard')->first();
                FcTest::assert($standard !== null, 'standard TaxClass exists');
                FcFixture::taxRate((int) $standard->id, ['postcode' => '1000-1999', 'rate' => 10]);
                update_option($option, ['enable_tax' => 'yes', 'tax_inclusion' => 'excluded'], false);

                $line = [
                    'id'               => 300001,
                    'post_id'          => (int) $product['post']->ID,
                    'object_id'        => (int) $product['variation']->id,
                    'quantity'         => 1,
                    'unit_price'       => 100,
                    'subtotal'         => 100,
                    'discount_total'   => 0,
                    'shipping_charge'  => 0,
                    'other_info'       => ['payment_type' => 'onetime'],
                    'line_meta'        => [],
                ];
                $location = [
                    'country'      => 'XZ',
                    'state'        => 'ST',
                    'tax_rounding' => 'item',
                ];

                TaxCalculator::resetCache();
                $endpoint = new TaxCalculator([$line], array_merge($location, ['postcode' => '1999']));
                TaxCalculator::resetCache();
                $afterEndpoint = new TaxCalculator([$line], array_merge($location, ['postcode' => '2000']));
                $storedOrder = FcFixture::reloadOrder((int) $inertOrder->id);

                FcTest::assertSame(10, $endpoint->getTotalTax(), 'numeric upper endpoint remains inside the configured range');
                FcTest::assertSame(0, $afterEndpoint->getTotalTax(), 'one value after the endpoint is excluded');
                FcTest::assertSame(
                    [Status::PAYMENT_PENDING, 0, ''],
                    [(string) $storedOrder->payment_status, (int) $storedOrder->total_paid, (string) $storedOrder->payment_method],
                    'tax boundary calculation leaves the owned Order inert'
                );
            } finally {
                $phase30RestoreOption($option, $previous, $missing);
                TaxCalculator::resetCache();
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
