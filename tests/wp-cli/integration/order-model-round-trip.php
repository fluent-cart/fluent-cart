<?php
/**
 * Phase 5 core Order model: exact-value real model/MySQL round-trip.
 */

return [
    [
        'id'            => 'order-model-round-trip',
        'name'          => 'Order model persists and reloads exact owned values',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $identity = FcFixture::identity();
            $createdAt = '2025-05-06 07:08:09';
            $expectedConfig = [
                'fixture_identity' => $identity,
                'fixture_owner'    => 'wp-plugin-test-suite',
                'fixture_case'     => 'order-model-round-trip',
            ];

            try {
                $customer = FcFixture::customer();
                $beforeCreate = gmdate('Y-m-d H:i:s');
                $created = FcFixture::order([
                    'created_at' => $createdAt,
                    'config'     => [
                        'fixture_case' => 'order-model-round-trip',
                    ],
                ]);
                $orderId = (int) $created->id;
                $orderUuid = (string) $created->uuid;
                $afterCreate = gmdate('Y-m-d H:i:s');

                FcTest::assert($orderId > 0, 'Order create returns a positive exact ID');
                FcTest::assert(
                    preg_match('/^[A-Z0-9]{12}$/', $orderUuid) === 1,
                    'Order create hook persists a 12-character uppercase UUID'
                );

                $stored = FcFixture::reloadOrder($orderId);
                FcTest::assertSame($orderId, (int) $stored->id, 'Order exact ID round-trips');
                FcTest::assertSame(
                    (int) $customer->id,
                    (int) $stored->customer_id,
                    'Order exact owned Customer ID round-trips'
                );
                FcTest::assertSame('processing', (string) $stored->status, 'Order status round-trips');
                FcTest::assertSame(
                    'pending',
                    (string) $stored->payment_status,
                    'Order pending payment status round-trips'
                );
                FcTest::assertSame('USD', (string) $stored->currency, 'Order currency round-trips');
                FcTest::assertSame(10000.0, $stored->subtotal, 'Order subtotal cents round-trip');
                FcTest::assertSame(123.0, $stored->tax_total, 'Order tax cents round-trip');
                FcTest::assertSame(10123.0, $stored->total_amount, 'Order total cents round-trip');
                FcTest::assertSame(0, (int) $stored->total_paid, 'Order total_paid stays zero');
                FcTest::assertSame('', (string) $stored->payment_method, 'Order has no gateway method');
                FcTest::assertSame('', (string) $stored->invoice_no, 'Pending Order has no invoice');
                FcTest::assertSame(
                    FcFixture::orderMarker($identity),
                    (string) $stored->note,
                    'Order exact ownership marker round-trips'
                );
                $storedConfig = $stored->config;
                $capturedAt = $storedConfig['fx']['captured_at'] ?? '';
                FcTest::assert(
                    $capturedAt >= $beforeCreate && $capturedAt <= $afterCreate,
                    'Order snapshot captures its creation time in GMT'
                );
                $baseCurrency = \FluentCart\App\Modules\MultiCurrency\Services\BaseCurrency::get();
                $expectedConfig['fx'] = [
                    'currency' => 'USD',
                    'base_currency' => $baseCurrency,
                    'rate' => '1.000000000000',
                    'rate_id' => null,
                    'source' => $baseCurrency === 'USD' ? 'base' : 'unknown',
                    'captured_at' => $capturedAt,
                    'margin' => '0',
                    'rounding' => 'none',
                    'charm' => 'none',
                    'adjustment' => '0',
                    'decimals' => 2,
                ];
                // MySQL JSON objects do not preserve insertion order.
                ksort($expectedConfig['fx']);
                if (isset($storedConfig['fx'])) {
                    ksort($storedConfig['fx']);
                }
                ksort($expectedConfig);
                ksort($storedConfig);
                FcTest::assertSame($expectedConfig, $storedConfig, 'Order JSON config and snapshot round-trip');
                FcTest::assertSame('1.000000000000', (string) $stored->rate, 'Order rate matches its snapshot');
                FcTest::assertSame($createdAt, (string) $stored->created_at, 'Order GMT timestamp round-trips');
                FcTest::assertSame($orderUuid, (string) $stored->uuid, 'Order generated UUID round-trips');
                FcFixture::assertNoForbiddenOrderSideEffects($orderId, 0);
            } finally {
                FcFixture::cleanupAll();
            }

            FcTest::assertSame(
                ['customer' => 0, 'order' => 0],
                FcFixture::residueCounts($identity),
                'Order and Customer exact markers are absent after finally cleanup'
            );
        },
    ],
];
