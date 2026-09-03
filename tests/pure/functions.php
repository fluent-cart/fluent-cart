<?php
/**
 * Phase 12 exact-value boundaries, Phase 13 coverage-gap closures, Phase 22
 * provider mappings, plus Phase 24 and Phase 30 mutation-survivor boundaries.
 */

use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Modules\MCP\Support\PaymentProjector;
use FluentCart\App\Modules\MCP\Support\ProductFinancialsCalculator;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\API as PayPalApi;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\DccApplies;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API as StripeApi;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\API\ApiRequest;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\StripeHelper;
use FluentCart\App\Services\Coupon\DiscountService;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Localization\PostcodeVerification;
use FluentCart\App\Services\Renderer\Receipt\TaxSummaryHelper;

class FcPureDiscountProbe extends DiscountService
{
    public function validateCoupon(Coupon $coupon)
    {
        return $this->isCouponValid($coupon);
    }
}

$makeCoupon = function (array $attributes) {
    $coupon = new Coupon();
    $coupon->fill(array_merge([
        'code'       => 'PHASE12',
        'status'     => 'active',
        'type'       => 'fixed',
        'amount'     => 0,
        'conditions' => [],
        'use_count'  => 0,
        'start_date' => null,
        'end_date'   => null,
    ], $attributes));

    return $coupon;
};

return [
    [
        'id'            => 'pure-money-to-cent-boundaries',
        'name'          => 'Money-to-cent conversion rounds ordinary signed boundaries exactly',
        'phase'         => 12,
        'known_failure' => false,
        'targets'       => ['FluentCart\App\Helpers\Helper::toCent'],
        'boundaries'    => ['zero', 'half cent', 'negative', 'large amount', 'non-numeric'],
        'run'           => function () {
            $inputs = [
                'zero'        => 0,
                'half-cent'   => 0.005,
                'negative'    => -19.99,
                'large'       => 999999999.99,
                'non-numeric' => 'not-money',
            ];

            $actual = array_map([Helper::class, 'toCent'], $inputs);

            FcTest::assertSame(
                [
                    'zero'        => 0,
                    'half-cent'   => 1,
                    'negative'    => -1999,
                    'large'       => 99999999999,
                    'non-numeric' => 0,
                ],
                $actual,
                'ordinary decimal amounts become exact integer cents'
            );
        },
    ],
    [
        'id'            => 'pure-money-to-cent-float-precision',
        'name'          => 'Money-to-cent conversion preserves decimal half-cent precision',
        'phase'         => 12,
        'known_failure' => true,
        'targets'       => ['FluentCart\App\Helpers\Helper::toCent'],
        'boundaries'    => ['positive binary-float half cent', 'negative binary-float half cent'],
        'run'           => function () {
            $actual = [
                Helper::toCent('1.005'),
                Helper::toCent('-1.005'),
            ];

            if ($actual === [101, -101]) {
                FcTest::fail(
                    'KNOWN-FAILURE unexpectedly passed; reclassify decimal-safe cent conversion.'
                );
            } elseif ($actual === [100, -100]) {
                FcTest::skip(
                    'KNOWN-FAILURE — Helper.php:577-578 converts through binary float; '
                    . 'observed ["1.005"=>100,"-1.005"=>-100].'
                );
            } else {
                FcTest::fail(
                    'KNOWN-FAILURE behavior drifted from the documented half-cent defect.'
                    . "\n  expected defect: [100,-100]"
                    . "\n  actual: " . wp_json_encode($actual)
                );
            }
        },
    ],
    [
        'id'            => 'pure-money-cent-to-decimal-boundaries',
        'name'          => 'Cent-to-decimal conversion preserves exact sign and scale boundaries',
        'phase'         => 12,
        'known_failure' => false,
        'targets'       => [
            'FluentCart\App\Helpers\Helper::toDecimal',
            'FluentCart\App\Helpers\Helper::toDecimalWithoutComma',
        ],
        'boundaries'    => ['zero', 'negative cent', 'large amount', 'non-numeric passthrough'],
        'run'           => function () {
            $actual = [
                'zero' => Helper::toDecimal(0, false, 'USD', false, true, false, false),
                'negative' => Helper::toDecimal(
                    -12345,
                    false,
                    'USD',
                    false,
                    true,
                    false,
                    false
                ),
                'large' => Helper::toDecimalWithoutComma(99999999999),
                'invalid-formatted-path' => Helper::toDecimal(
                    'not-money',
                    false,
                    'USD',
                    false,
                    true,
                    false,
                    false
                ),
                'invalid-plain-path' => Helper::toDecimalWithoutComma('not-money'),
            ];

            FcTest::assertSame(
                [
                    'zero'                   => 0.0,
                    'negative'               => -123.45,
                    'large'                  => 999999999.99,
                    'invalid-formatted-path' => 'not-money',
                    'invalid-plain-path'     => 0,
                ],
                $actual,
                'cent-to-decimal paths keep their documented exact values'
            );
        },
    ],
    [
        'id'            => 'pure-currency-formatting-boundaries',
        'name'          => 'Currency formatting respects two-decimal and zero-decimal boundaries',
        'phase'         => 12,
        'known_failure' => false,
        'targets'       => [
            'FluentCart\App\Helpers\CurrenciesHelper::centsToDecimal',
            'FluentCart\App\Helpers\CurrenciesHelper::getCurrencySign',
        ],
        'boundaries'    => ['zero', 'negative cent', 'zero-decimal currency', 'unknown symbol'],
        'run'           => function () {
            $actual = [
                'zero-usd'    => CurrenciesHelper::centsToDecimal(0, 'USD'),
                'negative-usd' => CurrenciesHelper::centsToDecimal(-1, 'USD'),
                'positive-usd' => CurrenciesHelper::centsToDecimal(12345, 'USD'),
                'jpy'          => CurrenciesHelper::centsToDecimal(12345, 'JPY'),
                'usd-sign'     => CurrenciesHelper::getCurrencySign('usd'),
                'unknown-sign' => CurrenciesHelper::getCurrencySign('ZZZ'),
            ];

            FcTest::assertSame(
                [
                    'zero-usd'     => '0.00',
                    'negative-usd' => '-0.01',
                    'positive-usd' => '123.45',
                    'jpy'          => 12345,
                    'usd-sign'     => '&#36;',
                    'unknown-sign' => '',
                ],
                $actual,
                'currency exponent and symbol lookups retain exact output types and values'
            );
        },
    ],
    [
        'id'            => 'pure-datetime-gmt-now',
        'name'          => 'GMT now always returns the current UTC instant',
        'phase'         => 12,
        'known_failure' => false,
        'targets'       => ['FluentCart\App\Services\DateTime\DateTime::gmtNow'],
        'boundaries'    => ['UTC timezone', 'current wall-clock lower and upper bound'],
        'run'           => function () {
            $before = time();
            $actual = DateTime::gmtNow();
            $after = time();

            FcTest::assertSame('UTC', $actual->getTimezone()->getName(), 'GMT now timezone');
            FcTest::assert(
                $actual->getTimestamp() >= $before && $actual->getTimestamp() <= $after,
                'GMT now timestamp must fall inside the call boundary'
            );
        },
    ],
    [
        'id'            => 'pure-datetime-timezone-conversions',
        'name'          => 'Timezone conversion preserves epoch and daylight-saving boundaries',
        'phase'         => 12,
        'known_failure' => false,
        'targets'       => [
            'FluentCart\App\Services\DateTime\DateTime::anyTimeToGmt',
            'FluentCart\App\Services\DateTime\DateTime::gmtToTimezone',
        ],
        'boundaries'    => ['Unix epoch zero', 'DST spring-forward instant', 'explicit source timezone'],
        'run'           => function () {
            $epoch = DateTime::anyTimeToGmt(0);
            $preDst = DateTime::anyTimeToGmt(
                '2024-03-10 01:30:00',
                'America/New_York'
            );
            $postDst = DateTime::gmtToTimezone(
                '2024-03-10 07:30:00',
                'America/New_York'
            );

            FcTest::assertSame(
                ['1970-01-01 00:00:00', 'UTC'],
                [$epoch->format('Y-m-d H:i:s'), $epoch->getTimezone()->getName()],
                'Unix epoch remains the zero UTC instant'
            );
            FcTest::assertSame(
                '2024-03-10 06:30:00',
                $preDst->format('Y-m-d H:i:s'),
                'pre-transition New York wall time converts to exact UTC'
            );
            FcTest::assertSame(
                ['2024-03-10 03:30:00', 'America/New_York'],
                [$postDst->format('Y-m-d H:i:s'), $postDst->getTimezone()->getName()],
                'post-transition UTC instant converts across the skipped local hour'
            );
        },
    ],
    [
        'id'            => 'pure-tax-rate-base-map',
        'name'          => 'Tax rate bases aggregate inclusive and exclusive cents exactly',
        'phase'         => 12,
        'known_failure' => false,
        'targets'       => [
            'FluentCart\App\Services\Renderer\Receipt\TaxSummaryHelper::computeRateBaseMap',
        ],
        'boundaries'    => ['empty input', 'inclusive net floor', 'fractional cent', 'shared rate'],
        'run'           => function () {
            $items = [
                [
                    'line_meta' => [
                        'tax_config' => [
                            'inclusive' => true,
                            'rates'     => [
                                [
                                    'rate_id'        => 1,
                                    'tax_amount'     => 125.5,
                                    'taxable_amount' => 1125.5,
                                ],
                                [
                                    'rate_id'        => 3,
                                    'tax_amount'     => 2.0,
                                    'taxable_amount' => 1.0,
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'line_meta' => [
                        'tax_config' => [
                            'inclusive' => false,
                            'rates'     => [
                                [
                                    'rate_id'        => 1,
                                    'tax_amount'     => 50.0,
                                    'taxable_amount' => 500.0,
                                ],
                                [
                                    'rate_id'        => 2,
                                    'tax_amount'     => 0.5,
                                    'taxable_amount' => 10.25,
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $empty = TaxSummaryHelper::computeRateBaseMap([]);
            $actual = TaxSummaryHelper::computeRateBaseMap($items);

            FcTest::assertSame([], $empty, 'empty item set has no tax-rate bases');
            FcTest::assertSame(
                [
                    1 => ['base' => 1500.0, 'tax' => 175.5],
                    3 => ['base' => 0.0, 'tax' => 2.0],
                    2 => ['base' => 10.25, 'tax' => 0.5],
                ],
                $actual,
                'tax bases retain exact inclusive, exclusive, floor, and fractional values'
            );
        },
    ],
    [
        'id'            => 'pure-tax-folded-rate-rounding',
        'name'          => 'Folded tax rows combine shipping and round rate bases exactly',
        'phase'         => 12,
        'known_failure' => false,
        'targets'       => [
            'FluentCart\App\Services\Renderer\Receipt\TaxSummaryHelper::buildFoldedRateRows',
        ],
        'boundaries'    => ['empty input', 'trusted item base', 'shipping-only rate', 'percentage division'],
        'run'           => function () {
            $rateLines = [
                [
                    'rate_id'      => 1,
                    'rate_percent' => 12.5,
                    'order_tax'    => 125,
                    'rate_label'   => 'VAT 12.5%',
                    'inclusive'    => false,
                ],
            ];
            $shippingLines = [
                [
                    'rate_id'      => 1,
                    'rate_percent' => 12.5,
                    'shipping_tax' => 25,
                    'rate_label'   => 'VAT 12.5%',
                ],
                [
                    'rate_id'      => 2,
                    'rate_percent' => 5,
                    'shipping_tax' => 10,
                    'rate_label'   => 'VAT 5%',
                ],
            ];

            $empty = TaxSummaryHelper::buildFoldedRateRows([], [], 'order_tax', false);
            $actual = TaxSummaryHelper::buildFoldedRateRows(
                $rateLines,
                $shippingLines,
                'order_tax',
                true,
                [1 => ['base' => 1000.0, 'tax' => 125.0]]
            );

            FcTest::assertSame([], $empty, 'empty tax and shipping rows stay empty');
            FcTest::assertSame(
                [
                    [
                        'label'     => 'VAT 12.5%',
                        'base'      => 1200,
                        'tax'       => 150,
                        'inclusive' => false,
                    ],
                    [
                        'label'     => 'VAT 5%',
                        'base'      => 200,
                        'tax'       => 10,
                        'inclusive' => true,
                    ],
                ],
                $actual,
                'product and shipping tax fold into exact per-rate cent rows'
            );
        },
    ],
    [
        'id'            => 'pure-discount-fixed-cent-conservation',
        'name'          => 'Fixed coupon distribution conserves one cent across a rounding tie',
        'phase'         => 12,
        'known_failure' => false,
        'targets'       => ['FluentCart\App\Services\Coupon\DiscountService::apply'],
        'boundaries'    => ['one-cent coupon', 'two equal lines', 'rounding excess correction'],
        'run'           => function () use ($makeCoupon) {
            $coupon = $makeCoupon([
                'code'   => 'ONECENT',
                'type'   => 'fixed',
                'amount' => 1,
            ]);
            $items = [
                [
                    'id'         => 1,
                    'post_id'    => 1,
                    'object_id'  => 11,
                    'subtotal'   => 100,
                    'quantity'   => 1,
                    'unit_price' => 100,
                    'other_info' => [],
                ],
                [
                    'id'         => 2,
                    'post_id'    => 2,
                    'object_id'  => 12,
                    'subtotal'   => 100,
                    'quantity'   => 1,
                    'unit_price' => 100,
                    'other_info' => [],
                ],
            ];
            $service = new DiscountService(null, $items);

            $applied = $service->apply($coupon);
            $result = $service->getResult();

            FcTest::assertSame(true, $applied, 'one-cent fixed coupon applies');
            FcTest::assertSame(
                ['ONECENT' => 1],
                $result['per_coupon_discounts'],
                'per-coupon ledger conserves the exact one-cent amount'
            );
            FcTest::assertSame(
                [0, 1],
                array_column($result['cart_items'], 'coupon_discount'),
                'rounding correction leaves exactly one discounted cent'
            );
            FcTest::assertSame(
                [100, 99],
                array_column($result['cart_items'], 'line_total'),
                'line totals lose exactly one cent in aggregate'
            );
        },
    ],
    [
        'id'            => 'pure-coupon-eligibility-boundaries',
        'name'          => 'Coupon eligibility accepts and rejects exact lifecycle and usage boundaries',
        'phase'         => 12,
        'known_failure' => false,
        'targets'       => [
            'FluentCart\App\Services\Coupon\DiscountService::isCouponValid',
        ],
        'boundaries'    => ['active', 'expired', 'scheduled', 'future start', 'one below and at use limit'],
        'run'           => function () use ($makeCoupon) {
            $probe = new FcPureDiscountProbe();
            $eligible = $makeCoupon([
                'use_count'  => 1,
                'conditions' => ['max_uses' => 2],
            ]);
            $atLimit = $makeCoupon([
                'use_count'  => 2,
                'conditions' => ['max_uses' => 2],
            ]);
            $expired = $makeCoupon(['status' => 'expired']);
            $scheduled = $makeCoupon(['status' => 'scheduled']);
            $future = $makeCoupon(['start_date' => '2099-01-01 00:00:00']);

            $eligibleResult = $probe->validateCoupon($eligible);
            $atLimitResult = $probe->validateCoupon($atLimit);
            $expiredResult = $probe->validateCoupon($expired);
            $scheduledResult = $probe->validateCoupon($scheduled);
            $futureResult = $probe->validateCoupon($future);

            FcTest::assert(
                $eligibleResult === $eligible,
                'one use below max remains eligible'
            );
            FcTest::assertSame(
                'coupon_max_uses_exceeded',
                is_wp_error($atLimitResult) ? $atLimitResult->get_error_code() : null,
                'exact use limit is rejected'
            );
            FcTest::assertSame(
                'coupon_expired',
                is_wp_error($expiredResult) ? $expiredResult->get_error_code() : null,
                'expired status is rejected'
            );
            FcTest::assertSame(
                'coupon_not_started',
                is_wp_error($scheduledResult) ? $scheduledResult->get_error_code() : null,
                'scheduled status is rejected'
            );
            FcTest::assertSame(
                'coupon_not_started',
                is_wp_error($futureResult) ? $futureResult->get_error_code() : null,
                'future start boundary is rejected'
            );
        },
    ],
    [
        'id'            => 'pure-postcode-formatting-boundaries',
        'name'          => 'Postcode formatting normalizes separators and country boundaries exactly',
        'phase'         => 12,
        'known_failure' => false,
        'targets'       => [
            'FluentCart\App\Services\Localization\PostcodeVerification::normalizePostcode',
            'FluentCart\App\Services\Localization\PostcodeVerification::formatPostcode',
        ],
        'boundaries'    => ['empty', 'mixed case', 'existing separators', 'short and extended US'],
        'run'           => function () {
            $validator = new PostcodeVerification();

            $actual = [
                'normalized'  => $validator->normalizePostcode(' sw1a-1aa '),
                'gb'          => $validator->formatPostcode(' sw1a-1aa ', 'GB'),
                'us-short'    => $validator->formatPostcode('12345', 'US'),
                'us-extended' => $validator->formatPostcode('12345 6789', 'US'),
                'us-empty'    => $validator->formatPostcode('', 'US'),
            ];

            FcTest::assertSame(
                [
                    'normalized'  => 'SW1A1AA',
                    'gb'          => 'SW1A 1AA',
                    'us-short'    => '12345',
                    'us-extended' => '12345-6789',
                    'us-empty'    => '',
                ],
                $actual,
                'postcode normalization and separator placement are exact'
            );
        },
    ],
    [
        'id'            => 'pure-postcode-validation-boundaries',
        'name'          => 'Postcode validation enforces country-specific edge characters',
        'phase'         => 12,
        'known_failure' => false,
        'targets'       => [
            'FluentCart\App\Services\Localization\PostcodeVerification::isValid',
            'FluentCart\App\Services\Localization\PostcodeVerification::isGBPostcode',
        ],
        'boundaries'    => ['GB special code', 'GB invalid suffix', 'CA forbidden prefix', 'invalid character'],
        'run'           => function () {
            $validator = new PostcodeVerification();

            $actual = [
                'gb-special'       => $validator->isValid('GIR 0AA', 'GB'),
                'gb-invalid'       => $validator->isValid('GIR 0AZ', 'GB'),
                'ca-valid'         => $validator->isValid('K1A 0B1', 'CA'),
                'ca-invalid-start' => $validator->isValid('W1A 0B1', 'CA'),
                'invalid-char'     => $validator->isValid('12345!', 'US'),
            ];

            FcTest::assertSame(
                [
                    'gb-special'       => true,
                    'gb-invalid'       => false,
                    'ca-valid'         => true,
                    'ca-invalid-start' => false,
                    'invalid-char'     => false,
                ],
                $actual,
                'country validators accept and reject exact postcode edges'
            );
        },
    ],
    [
        'id'            => 'pure-json-validator-boundaries',
        'name'          => 'JSON validation accepts containers and rejects scalar or malformed boundaries',
        'phase'         => 12,
        'known_failure' => false,
        'targets'       => ['FluentCart\App\Helpers\Helper::is_valid_json'],
        'boundaries'    => ['empty object', 'empty array', 'scalar JSON', 'malformed container', 'non-string'],
        'run'           => function () {
            $actual = [
                'object'     => Helper::is_valid_json('{}'),
                'array'      => Helper::is_valid_json(' [1, 2] '),
                'scalar'     => Helper::is_valid_json('"value"'),
                'malformed'  => Helper::is_valid_json('{"missing":}'),
                'non-string' => Helper::is_valid_json(['valid' => 'shape']),
            ];

            FcTest::assertSame(
                [
                    'object'     => true,
                    'array'      => true,
                    'scalar'     => false,
                    'malformed'  => false,
                    'non-string' => false,
                ],
                $actual,
                'JSON validator retains its exact container-only contract'
            );
        },
    ],
    [
        'id'            => 'pure-boolean-parser-boundaries',
        'name'          => 'Boolean parsing handles explicit strings and ambiguous defaults exactly',
        'phase'         => 12,
        'known_failure' => false,
        'targets'       => ['FluentCart\App\Helpers\Helper::toBool'],
        'boundaries'    => ['native booleans', 'trimmed mixed case', 'zero string', 'ambiguous fallback'],
        'run'           => function () {
            $actual = [
                'native-true'  => Helper::toBool(true),
                'native-false' => Helper::toBool(false, true),
                'yes'          => Helper::toBool(' YeS '),
                'zero'         => Helper::toBool('0', true),
                'unknown-false-default' => Helper::toBool('maybe'),
                'unknown-true-default'  => Helper::toBool('maybe', true),
            ];

            FcTest::assertSame(
                [
                    'native-true'          => true,
                    'native-false'         => false,
                    'yes'                  => true,
                    'zero'                 => false,
                    'unknown-false-default' => false,
                    'unknown-true-default'  => true,
                ],
                $actual,
                'boolean parser preserves explicit values and caller defaults'
            );
        },
    ],
    [
        'id'            => 'pure-payment-projector-finite-and-perpetual-boundaries',
        'name'          => 'Payment projection separates capped installments from open renewals',
        'phase'         => 13,
        'known_failure' => false,
        'targets'       => [
            'FluentCart\App\Modules\MCP\Support\PaymentProjector::project',
        ],
        'boundaries'    => [
            'shared anchor',
            'finite remaining-bill cap',
            'perpetual horizon',
            '30-day and 90-day cutoffs',
        ],
        'run'           => function () {
            $subscriptions = [
                [
                    'settlement'      => 'finite',
                    'interval'        => 'monthly',
                    'recur'           => 9900,
                    'remaining_bills' => 2,
                    'anchor'          => '2026-07-20 00:00:00',
                ],
                [
                    'settlement'      => 'perpetual',
                    'interval'        => 'monthly',
                    'recur'           => 3000,
                    'remaining_bills' => -1,
                    'anchor'          => '2026-07-20 00:00:00',
                ],
            ];

            $actual = PaymentProjector::project(
                $subscriptions,
                strtotime('2026-07-01 00:00:00 UTC'),
                strtotime('2026-09-30 23:59:59 UTC'),
                'month'
            );

            FcTest::assertSame(
                [
                    '2026-07' => [
                        'finite'          => 9900,
                        'recurring'       => 3000,
                        'finite_count'    => 1,
                        'recurring_count' => 1,
                    ],
                    '2026-08' => [
                        'finite'          => 9900,
                        'recurring'       => 3000,
                        'finite_count'    => 1,
                        'recurring_count' => 1,
                    ],
                    '2026-09' => [
                        'finite'          => 0,
                        'recurring'       => 3000,
                        'finite_count'    => 0,
                        'recurring_count' => 1,
                    ],
                ],
                $actual['buckets'],
                'finite billing stops after two events while perpetual billing reaches the horizon'
            );
            FcTest::assertSame(
                [3000, 9000, ['monthly' => 3000]],
                [
                    $actual['recurring_next_30d'],
                    $actual['recurring_next_90d'],
                    $actual['by_interval_next_30d'],
                ],
                'recurring cutoff totals include exact anchored events'
            );
        },
    ],
    [
        'id'            => 'pure-product-financials-mixed-settlement-boundaries',
        'name'          => 'Product financials isolate currency and roll mixed settlements exactly',
        'phase'         => 13,
        'known_failure' => false,
        'targets'       => [
            'FluentCart\App\Modules\MCP\Support\ProductFinancialsCalculator::filterByCurrency',
            'FluentCart\App\Modules\MCP\Support\ProductFinancialsCalculator::compute',
        ],
        'boundaries'    => [
            'case-insensitive currency',
            'foreign-currency exclusion',
            'finite completion',
            'perpetual MRR',
            'open-ended contract',
        ],
        'run'           => function () {
            $rows = [
                [
                    'currency'          => 'USD',
                    'billing_interval'  => 'monthly',
                    'recurring_total'   => 9900,
                    'bill_count'        => 2,
                    'bill_times'        => 8,
                    'status'            => 'active',
                    'next_billing_date' => '2026-07-10 00:00:00',
                ],
                [
                    'currency'          => 'usd',
                    'billing_interval'  => 'yearly',
                    'recurring_total'   => 30000,
                    'bill_count'        => 1,
                    'bill_times'        => 0,
                    'status'            => 'active',
                    'next_billing_date' => '2026-08-01 00:00:00',
                ],
                [
                    'currency'          => 'EUR',
                    'billing_interval'  => 'monthly',
                    'recurring_total'   => 999999,
                    'bill_count'        => 1,
                    'bill_times'        => 0,
                    'status'            => 'active',
                    'next_billing_date' => '2026-07-10 00:00:00',
                ],
            ];

            list($kept, $otherCurrencies) =
                ProductFinancialsCalculator::filterByCurrency($rows, 'USD');
            $actual = ProductFinancialsCalculator::compute($kept, [
                'as_of'           => '2026-07-07 00:00:00',
                'horizon_months'  => 12,
                'bucket'          => 'month',
                'include_schedule' => true,
            ]);

            FcTest::assertSame(2, count($kept), 'both USD spellings remain in scope');
            FcTest::assertSame(['EUR'], $otherCurrencies, 'foreign currencies are surfaced, not summed');
            FcTest::assertSame(
                [59400, 6, 2500, null, 49800],
                [
                    $actual['subscriptions']['finite']['scheduled_remaining'],
                    $actual['subscriptions']['finite']['remaining_installments'],
                    $actual['subscriptions']['recurring']['mrr'],
                    $actual['totals']['total_contracted'],
                    $actual['totals']['collected_to_date'],
                ],
                'finite commitments and perpetual run-rate retain exact integer cents'
            );

            $scheduled = array_reduce(
                $actual['payment_schedule'],
                function ($totals, $bucket) {
                    $totals[0] += $bucket['finite_installments'];
                    $totals[1] += $bucket['recurring_renewals'];

                    return $totals;
                },
                [0, 0]
            );
            FcTest::assertSame(
                [59400, 30000],
                $scheduled,
                'the schedule conserves six finite installments and one yearly renewal'
            );
        },
    ],
    [
        'id'            => 'pure-phase24-trial-signup-coupon-boundaries',
        'name'          => 'Coupon trial-signup subtotal requires both subscription type and positive trial days',
        'phase'         => 24,
        'known_failure' => false,
        'targets'       => [
            'FluentCart\App\Services\Coupon\DiscountService::apply',
        ],
        'boundaries'    => [
            'subscription with zero trial days',
            'one-time item with stray trial days',
            'exact percentage discount and line total',
        ],
        'run'           => function () use ($makeCoupon) {
            $coupon = $makeCoupon([
                'code'   => 'PHASE24TRIAL',
                'type'   => 'percentage',
                'amount' => 10,
            ]);
            $items = [
                [
                    'id'         => 240001,
                    'post_id'    => 240001,
                    'object_id'  => 240001,
                    'subtotal'   => 1000,
                    'quantity'   => 1,
                    'unit_price' => 1000,
                    'other_info' => [
                        'payment_type' => 'subscription',
                        'trial_days'    => 0,
                        'signup_fee'    => 300,
                    ],
                ],
                [
                    'id'         => 240002,
                    'post_id'    => 240002,
                    'object_id'  => 240002,
                    'subtotal'   => 1000,
                    'quantity'   => 1,
                    'unit_price' => 1000,
                    'other_info' => [
                        'payment_type' => 'onetime',
                        'trial_days'    => 7,
                        'signup_fee'    => 300,
                    ],
                ],
            ];
            $service = new DiscountService(null, $items);

            FcTest::assertSame(true, $service->apply($coupon), 'boundary coupon applies');
            $result = $service->getResult();
            FcTest::assertSame(
                ['PHASE24TRIAL' => 200],
                $result['per_coupon_discounts'],
                'both sibling lines use their 1000-cent subtotal'
            );
            FcTest::assertSame(
                [100, 100],
                array_column($result['cart_items'], 'coupon_discount'),
                'ten-percent coupon removes exactly 100 cents per line'
            );
            FcTest::assertSame(
                [900, 900],
                array_column($result['cart_items'], 'line_total'),
                'sibling metadata cannot replace the normal line subtotal with signup fee'
            );
        },
    ],
    [
        'id'            => 'pure-phase24-non-collecting-subscription-statuses',
        'name'          => 'Pending and intended subscriptions contribute zero collected revenue',
        'phase'         => 24,
        'known_failure' => false,
        'targets'       => [
            'FluentCart\App\Modules\MCP\Support\ProductFinancialsCalculator::compute',
        ],
        'boundaries'    => [
            'active collected amount',
            'pending non-collecting amount',
            'intended non-collecting amount',
            'complete status breakdown',
        ],
        'run'           => function () {
            $rows = [
                [
                    'billing_interval'  => 'monthly',
                    'recurring_total'   => 111,
                    'bill_count'        => 2,
                    'bill_times'        => 4,
                    'status'            => 'active',
                    'next_billing_date' => null,
                ],
                [
                    'billing_interval'  => 'monthly',
                    'recurring_total'   => 222,
                    'bill_count'        => 3,
                    'bill_times'        => 5,
                    'status'            => 'pending',
                    'next_billing_date' => null,
                ],
                [
                    'billing_interval'  => 'monthly',
                    'recurring_total'   => 333,
                    'bill_count'        => 4,
                    'bill_times'        => 6,
                    'status'            => 'intended',
                    'next_billing_date' => null,
                ],
            ];
            $actual = ProductFinancialsCalculator::compute($rows, [
                'as_of'          => '2026-07-31 00:00:00',
                'horizon_months' => 3,
            ]);

            FcTest::assertSame(
                [
                    'active'    => 1,
                    'trialing'  => 0,
                    'paused'    => 0,
                    'canceled'  => 0,
                    'failing'   => 0,
                    'expired'   => 0,
                    'expiring'  => 0,
                    'past_due'  => 0,
                    'intended'  => 1,
                    'pending'   => 1,
                    'completed' => 0,
                ],
                $actual['subscriptions']['status_breakdown'],
                'status breakdown keeps all sibling lifecycle values'
            );
            FcTest::assertSame(
                222,
                $actual['subscriptions']['finite']['collected_to_date'],
                'only the active row contributes collected cents'
            );
            FcTest::assertSame(
                222,
                $actual['totals']['collected_to_date'],
                'pending 666 cents and intended 1332 cents remain uncollected'
            );
        },
    ],
    [
        'id'            => 'pure-phase24-payment-projector-horizon-equality',
        'name'          => 'Payment projection includes the exact horizon and excludes one second after it',
        'phase'         => 24,
        'known_failure' => false,
        'targets'       => [
            'FluentCart\App\Modules\MCP\Support\PaymentProjector::project',
        ],
        'boundaries'    => [
            'event equal to horizon',
            'event one second after horizon',
            'inclusive day bucket',
        ],
        'run'           => function () {
            $actual = PaymentProjector::project(
                [
                    [
                        'settlement'      => 'finite',
                        'interval'        => 'daily',
                        'recur'           => 2401,
                        'remaining_bills' => 1,
                        'anchor'          => '2026-07-31 00:00:00',
                    ],
                    [
                        'settlement'      => 'finite',
                        'interval'        => 'daily',
                        'recur'           => 9901,
                        'remaining_bills' => 1,
                        'anchor'          => '2026-07-31 00:00:01',
                    ],
                ],
                strtotime('2026-07-01 00:00:00 UTC'),
                strtotime('2026-07-31 00:00:00 UTC'),
                'day'
            );

            FcTest::assertSame(
                [
                    '2026-07-31' => [
                        'finite'          => 2401,
                        'recurring'       => 0,
                        'finite_count'    => 1,
                        'recurring_count' => 0,
                    ],
                ],
                $actual['buckets'],
                'only the event exactly equal to the horizon is projected'
            );
        },
    ],
    [
        'id'            => 'provider-stripe-request-payload-capture',
        'name'          => 'Stripe request execution is fixture-served and captures the exact payload',
        'phase'         => 22,
        'known_failure' => false,
        'targets'       => [
            'FluentCart\App\Modules\PaymentMethods\StripeGateway\API\ApiRequest::request',
        ],
        'boundaries'    => [
            'WordPress HTTP seam',
            'POST method',
            'nested form body',
            'canned success',
        ],
        'run'           => function () {
            $transport = new FcProviderHarness();
            $transport->expect(
                'POST',
                'https://api.stripe.com/v1/payment_intents',
                'stripe/payment-intent-success.json'
            );
            ApiRequest::set_secret_key('sk_test_phase22_fixture');
            ApiRequest::set_end_point('https://api.stripe.com/v1/');
            $transport->install();

            try {
                $actual = ApiRequest::request([
                    'amount'   => 12345,
                    'currency' => 'usd',
                    'metadata' => [
                        'fluentcart_tid' => 'phase22-request',
                    ],
                ], 'payment_intents', 'POST');
                $transport->assertComplete();
                $requests = $transport->requests();

                FcTest::assertSame(
                    [
                        'pi_phase22_success',
                        'requires_confirmation',
                        12345,
                    ],
                    [
                        isset($actual->id) ? $actual->id : null,
                        isset($actual->status) ? $actual->status : null,
                        isset($actual->amount) ? $actual->amount : null,
                    ],
                    'the canned Stripe success response reaches the production client unchanged'
                );
                FcTest::assertSame(1, count($requests), 'exactly one provider request is captured');
                FcTest::assertSame(
                    [
                        'POST',
                        'https://api.stripe.com/v1/payment_intents',
                        [
                            'amount'   => 12345,
                            'currency' => 'usd',
                            'metadata' => [
                                'fluentcart_tid' => 'phase22-request',
                            ],
                        ],
                    ],
                    [
                        $requests[0]['method'],
                        $requests[0]['url'],
                        $requests[0]['body'],
                    ],
                    'the provider harness records method, URL, and nested request payload'
                );
            } finally {
                $transport->uninstall();
                ApiRequest::set_secret_key('');
            }
        },
    ],
    [
        'id'            => 'provider-stripe-subscription-mappings',
        'name'          => 'Stripe subscription mappings preserve statuses and zero-decimal minor units',
        'phase'         => 22,
        'known_failure' => false,
        'targets'       => [
            'FluentCart\App\Modules\PaymentMethods\StripeGateway\StripeHelper::transformSubscriptionStatus',
            'FluentCart\App\Modules\PaymentMethods\StripeGateway\StripeHelper::getSubscriptionUpdateData',
        ],
        'boundaries'    => [
            'active',
            'incomplete',
            'failed cancellation',
            'past due',
            'expired local state',
            'JPY zero-decimal amount',
            'USD two-decimal amount',
        ],
        'run'           => function () {
            $expiredLocal = (object) ['status' => Status::SUBSCRIPTION_EXPIRED];
            $actualStatuses = [
                'active' => StripeHelper::transformSubscriptionStatus(['status' => 'ACTIVE']),
                'incomplete' => StripeHelper::transformSubscriptionStatus([
                    'status' => 'incomplete_expired',
                ]),
                'trialing' => StripeHelper::transformSubscriptionStatus(['status' => 'trialing']),
                'failed-cancel' => StripeHelper::transformSubscriptionStatus([
                    'status' => 'canceled',
                    'cancellation_details' => ['reason' => 'payment_failed'],
                ]),
                'unpaid' => StripeHelper::transformSubscriptionStatus(['status' => 'unpaid']),
                'paused' => StripeHelper::transformSubscriptionStatus(['status' => 'paused']),
                'past-due' => StripeHelper::transformSubscriptionStatus(['status' => 'past_due']),
                'past-due-expired-local' => StripeHelper::transformSubscriptionStatus(
                    ['status' => 'past_due'],
                    $expiredLocal
                ),
            ];
            $jpy = StripeHelper::getSubscriptionUpdateData([
                'status' => 'active',
                'plan'   => ['amount' => 123, 'currency' => 'jpy'],
            ]);
            $usd = StripeHelper::getSubscriptionUpdateData([
                'status' => 'active',
                'plan'   => ['amount' => 123, 'currency' => 'usd'],
            ]);

            FcTest::assertSame(
                [
                    'active'                 => Status::SUBSCRIPTION_ACTIVE,
                    'incomplete'             => Status::SUBSCRIPTION_INTENDED,
                    'trialing'               => Status::SUBSCRIPTION_TRIALING,
                    'failed-cancel'          => Status::SUBSCRIPTION_EXPIRED,
                    'unpaid'                 => Status::SUBSCRIPTION_EXPIRED,
                    'paused'                 => Status::SUBSCRIPTION_PAUSED,
                    'past-due'               => Status::SUBSCRIPTION_EXPIRING,
                    'past-due-expired-local' => Status::SUBSCRIPTION_EXPIRED,
                ],
                $actualStatuses,
                'Stripe provider statuses map to exact FluentCart subscription statuses'
            );
            FcTest::assertSame(
                [12300, 123],
                [$jpy['recurring_total'], $usd['recurring_total']],
                'Stripe zero-decimal amounts expand to internal cents while USD remains cents'
            );
        },
    ],
    [
        'id'            => 'provider-stripe-api-error-normalization',
        'name'          => 'Stripe API normalizes card and rate-limit provider failures',
        'phase'         => 22,
        'known_failure' => false,
        'targets'       => [
            'FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API::remoteRequest',
        ],
        'boundaries'    => ['HTTP 402 card error', 'HTTP 429 rate limit', 'provider error payload'],
        'run'           => function () {
            $transport = new FcProviderHarness();
            $transport
                ->expect(
                    'GET',
                    'https://api.stripe.com/v1/payment_intents/pi_phase22_card',
                    'stripe/card-error.json'
                )
                ->expect(
                    'GET',
                    'https://api.stripe.com/v1/payment_intents/pi_phase22_rate',
                    'stripe/rate-limit.json'
                );
            $transport->install();

            try {
                $api = new StripeApi();
                $card = $api->remoteRequest(
                    'payment_intents/pi_phase22_card',
                    [],
                    'sk_test_phase22_fixture',
                    'GET'
                );
                $rate = $api->remoteRequest(
                    'payment_intents/pi_phase22_rate',
                    [],
                    'sk_test_phase22_fixture',
                    'GET'
                );
                $transport->assertComplete();

                FcTest::assertSame(true, is_wp_error($card), 'HTTP 402 becomes WP_Error');
                FcTest::assertSame(
                    ['api_error', 'Your card was declined.', 'card_declined', 'card_error'],
                    [
                        $card->get_error_code(),
                        $card->get_error_message(),
                        $card->get_error_data()['error']['code'],
                        $card->get_error_data()['error']['type'],
                    ],
                    'the exact Stripe card error remains available after normalization'
                );
                FcTest::assertSame(true, is_wp_error($rate), 'HTTP 429 becomes WP_Error');
                FcTest::assertSame(
                    [
                        'api_error',
                        'Too many requests made to the API too quickly.',
                        'rate_limit',
                        'rate_limit_error',
                    ],
                    [
                        $rate->get_error_code(),
                        $rate->get_error_message(),
                        $rate->get_error_data()['error']['code'],
                        $rate->get_error_data()['error']['type'],
                    ],
                    'the exact Stripe rate-limit shape remains available after normalization'
                );
            } finally {
                $transport->uninstall();
            }
        },
    ],
    [
        'id'            => 'provider-paypal-api-response-normalization',
        'name'          => 'PayPal API normalizes success, validation, and rate-limit responses',
        'phase'         => 22,
        'known_failure' => false,
        'targets'       => [
            'FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\API::getRequest',
        ],
        'boundaries'    => [
            'HTTP 200 success',
            'HTTP 422 validation error',
            'HTTP 429 rate limit',
            'canonical name/message/details payload',
        ],
        'run'           => function () {
            $base = 'https://api-m.sandbox.paypal.com/v1/phase22/';
            $transport = new FcProviderHarness();
            $transport
                ->expect('GET', $base . 'success', 'paypal/resource-success.json')
                ->expect('GET', $base . 'validation', 'paypal/unprocessable.json')
                ->expect('GET', $base . 'rate-limit', 'paypal/rate-limit.json');
            $transport->install();

            try {
                $success = PayPalApi::getRequest(
                    $base . 'success',
                    'phase22-provider-token',
                    ''
                );
                $validation = PayPalApi::getRequest(
                    $base . 'validation',
                    'phase22-provider-token',
                    ''
                );
                $rate = PayPalApi::getRequest(
                    $base . 'rate-limit',
                    'phase22-provider-token',
                    ''
                );
                $transport->assertComplete();

                FcTest::assertSame(
                    ['PAYPAL-PHASE22', 'COMPLETED'],
                    [$success['id'], $success['status']],
                    'the canned PayPal success body is returned exactly'
                );
                FcTest::assertSame(true, is_wp_error($validation), 'HTTP 422 becomes WP_Error');
                FcTest::assertSame(
                    [
                        'general_error',
                        'The plan identifier is invalid.',
                        'UNPROCESSABLE_ENTITY',
                    ],
                    [
                        $validation->get_error_code(),
                        $validation->get_error_message(),
                        $validation->get_error_data()['name'],
                    ],
                    'PayPal validation details remain attached to the normalized error'
                );
                FcTest::assertSame(true, is_wp_error($rate), 'HTTP 429 becomes WP_Error');
                FcTest::assertSame(
                    ['general_error', 'Too many requests.', 'RATE_LIMIT_REACHED'],
                    [
                        $rate->get_error_code(),
                        $rate->get_error_message(),
                        $rate->get_error_data()['name'],
                    ],
                    'PayPal rate-limit name and message remain attached to the normalized error'
                );
            } finally {
                $transport->uninstall();
            }
        },
    ],
    [
        'id'            => 'phase30-stripe-ordinary-cancellation-status',
        'name'          => 'Stripe distinguishes ordinary cancellation from payment-failed expiration',
        'phase'         => 30,
        'known_failure' => false,
        'targets'       => [
            'FluentCart\App\Modules\PaymentMethods\StripeGateway\StripeHelper::transformSubscriptionStatus',
        ],
        'boundaries'    => [
            'ordinary canceled status',
            'payment-failed canceled status',
        ],
        'run'           => function () {
            $ordinary = StripeHelper::transformSubscriptionStatus([
                'status'               => 'canceled',
                'cancellation_details' => ['reason' => 'cancellation_requested'],
            ]);
            $paymentFailed = StripeHelper::transformSubscriptionStatus([
                'status'               => 'canceled',
                'cancellation_details' => ['reason' => 'payment_failed'],
            ]);

            FcTest::assertSame(
                [Status::SUBSCRIPTION_CANCELED, Status::SUBSCRIPTION_EXPIRED],
                [$ordinary, $paymentFailed],
                'Stripe cancellation reason preserves the exact local lifecycle distinction'
            );
        },
    ],
    [
        'id'            => 'phase30-stripe-http-300-error-boundary',
        'name'          => 'Stripe treats the exact HTTP 300 boundary as a provider error',
        'phase'         => 30,
        'known_failure' => false,
        'targets'       => [
            'FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API::remoteRequest',
        ],
        'boundaries'    => ['HTTP 300', 'provider detail', 'preserved response payload'],
        'run'           => function () {
            $transport = new FcProviderHarness();
            $transport->expect(
                'GET',
                'https://api.stripe.com/v1/phase30/http-300',
                'stripe/multiple-choice.json'
            );
            $transport->install();

            try {
                $result = (new StripeApi())->remoteRequest(
                    'phase30/http-300',
                    [],
                    'sk_test_phase30_fixture',
                    'GET'
                );
                $transport->assertComplete();

                FcTest::assertSame(true, is_wp_error($result), 'exact HTTP 300 becomes WP_Error');
                FcTest::assertSame(
                    ['api_error', 'Phase 30 Stripe redirect response.'],
                    [$result->get_error_code(), $result->get_error_message()],
                    'HTTP 300 maps to the exact Stripe API error contract'
                );
                FcTest::assertSame(
                    [
                        'detail'     => 'Phase 30 Stripe redirect response.',
                        'request_id' => 'req_phase30_http_300',
                        'redirect'   => 'https://example.invalid/phase30',
                    ],
                    $result->get_error_data(),
                    'HTTP 300 preserves the complete provider payload for diagnostics'
                );
            } finally {
                $transport->uninstall();
            }
        },
    ],
    [
        'id'            => 'provider-paypal-dcc-eligibility',
        'name'          => 'PayPal direct-card eligibility enforces exact country and currency pairs',
        'phase'         => 22,
        'known_failure' => false,
        'targets'       => [
            'FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\DccApplies::forCountryCurrency',
        ],
        'boundaries'    => [
            'supported pair',
            'country-specific currency',
            'unsupported country',
            'missing configuration',
        ],
        'run'           => function () {
            $actual = [
                'us-usd' => (new DccApplies('US', 'USD'))->forCountryCurrency(),
                'mx-mxn' => (new DccApplies('MX', 'MXN'))->forCountryCurrency(),
                'mx-usd' => (new DccApplies('MX', 'USD'))->forCountryCurrency(),
                'br-usd' => (new DccApplies('BR', 'USD'))->forCountryCurrency(),
            ];
            $missingMessage = null;
            try {
                new DccApplies('', 'USD');
            } catch (Exception $e) {
                $missingMessage = $e->getMessage();
            }

            FcTest::assertSame(
                [
                    'us-usd' => true,
                    'mx-mxn' => true,
                    'mx-usd' => false,
                    'br-usd' => false,
                ],
                $actual,
                'DCC eligibility uses the exact provider country/currency matrix'
            );
            FcTest::assertSame(
                'Please set store country and currency first!',
                $missingMessage,
                'missing store configuration fails before provider eligibility is evaluated'
            );
        },
    ],
];
