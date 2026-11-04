<?php
/**
 * Phase 8 Pro license issuance invariants.
 */

return [
    [
        'id'            => 'domain-license-issuance-idempotent',
        'name'          => 'Purchase-success license issuance creates one exact owned license',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            try {
                $handlerClass = FcDomainFixture::licenseHandlerClass();
                if ($handlerClass === null) {
                    FcTest::skip(
                        'FluentCart Pro licensing handler is inactive on this local install.'
                    );
                    return;
                }

                $product = FcDomainFixture::product([
                    'payment_type' => 'onetime',
                    'total_stock'  => 10,
                ]);
                $productId = (int) $product['post']->ID;
                $variationId = (int) $product['variation']->id;
                $order = FcDomainFixture::orderWithItem($productId, $variationId, 2);
                $prefix = 'P8-' . strtoupper(substr(
                    hash('sha256', FcFixture::identity()),
                    0,
                    12
                )) . '-';
                FcFixture::productMeta(
                    $productId,
                    'product',
                    'license_settings',
                    [
                        'enabled'    => 'yes',
                        'prefix'     => $prefix,
                        'variations' => [
                            [
                                'variation_id'     => $variationId,
                                'activation_limit' => 2,
                                'validity'         => [
                                    'unit'  => 'lifetime',
                                    'value' => 0,
                                ],
                            ],
                        ],
                    ]
                );

                $handler = new $handlerClass();
                $firstResult = $handler->maybeGenerateLicensesOnPurchaseSuccess([
                    'order' => $order,
                ]);
                $firstRows = FcDomainFixture::captureLicensesForOrder((int) $order->id);
                $secondResult = $handler->maybeGenerateLicensesOnPurchaseSuccess([
                    'order' => $order,
                ]);
                $secondRows = FcDomainFixture::captureLicensesForOrder((int) $order->id);

                FcTest::assertSame(true, $firstResult, 'first issuance reports creation');
                FcTest::assertSame(null, $secondResult, 'repeat issuance exits before creation');
                FcTest::assertSame(1, count($firstRows), 'first issuance creates one license');
                FcTest::assertSame(1, count($secondRows), 'repeat issuance does not duplicate');

                $license = $secondRows[0];
                FcTest::assertSame('inactive', (string) $license->status, 'new license status');
                FcTest::assertSame(4, (int) $license->limit, 'activation limit scales by quantity');
                FcTest::assertSame(0, (int) $license->activation_count, 'activation count starts zero');
                FcTest::assertSame($productId, (int) $license->product_id, 'license product ID');
                FcTest::assertSame($variationId, (int) $license->variation_id, 'license variation ID');
                FcTest::assertSame((int) $order->id, (int) $license->order_id, 'license Order ID');
                FcTest::assertSame(
                    (int) $order->customer_id,
                    (int) $license->customer_id,
                    'license Customer ID'
                );
                FcTest::assertSame(null, $license->expiration_date, 'one-time license is lifetime');
                FcTest::assertSame([], $license->config, 'license config starts empty');
                FcTest::assert(
                    strpos((string) $license->license_key, $prefix) === 0,
                    'license key preserves the configured exact prefix'
                );
                FcTest::assertSame(
                    32 + strlen($prefix),
                    strlen((string) $license->license_key),
                    'license key contains one MD5 payload after the prefix'
                );
            } finally {
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
