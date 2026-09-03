<?php
/**
 * Phase 9 ProductVariation admin REST create/read/update/delete round-trip.
 */

return [
    [
        'id'            => 'crud-variant-route-round-trip',
        'name'          => 'Variant admin CRUD preserves every non-title physical column',
        'kind'          => 'behavior',
        'known_failure' => true,
        'run'           => function () {
            $createdTitle = FcCrudFixture::marker('route-variant-created');
            $updatedTitle = FcCrudFixture::marker('route-variant-updated');
            $sku = 'P9' . strtoupper(substr(hash('sha256', FcFixture::identity()), 0, 24));

            try {
                $product = FcDomainFixture::product(['manage_stock' => 0]);
                $productId = (int) $product['post']->ID;
                $payload = [
                    'variants' => [
                        'post_id' => $productId,
                        'variation_title' => $createdTitle,
                        'sku' => $sku,
                        'item_price' => 12.34,
                        'compare_price' => 20.00,
                        'manage_cost' => 'false',
                        'item_cost' => 0,
                        'fulfillment_type' => 'digital',
                        'manage_stock' => 0,
                        'stock_status' => 'in-stock',
                        'total_stock' => 0,
                        'available' => 0,
                        'committed' => 0,
                        'on_hold' => 0,
                        'serial_index' => 2,
                        'downloadable' => 'no',
                        'other_info' => [
                            'description' => FcCrudFixture::marker('variant-description'),
                            'payment_type' => 'onetime',
                            'tax_class' => 'standard',
                            'tax_exempt' => 'no',
                        ],
                    ],
                ];

                $create = FcTest::rest('POST', '/products/variants', $payload);
                FcCrudFixture::requireHealthy($create, 'POST /products/variants');
                $variantId = FcCrudFixture::createdId(
                    $create,
                    'POST /products/variants'
                );
                FcCrudFixture::capture('product_variation', $variantId, [
                    'post_id' => $productId,
                    'sku' => $sku,
                ]);

                $before = FcCrudFixture::snapshot('product_variation', $variantId);
                if ($before === null) {
                    throw new RuntimeException('Variant disappeared after route create.');
                }

                $read = FcTest::rest('GET', '/products/variants', [
                    'params' => [
                        'variant_ids' => [$variantId],
                    ],
                ]);
                FcCrudFixture::requireHealthy($read, 'GET /products/variants');
                $variants = $read['data']['variants'] ?? [];
                if (is_object($variants) && method_exists($variants, 'toArray')) {
                    $variants = $variants->toArray();
                }
                $variants = is_array($variants) ? array_values($variants) : [];
                FcTest::assertSame(
                    1,
                    count($variants),
                    'Variant read returns exactly the selected route-created row'
                );
                FcTest::assertSame(
                    $variantId,
                    (int) ($variants[0]['id'] ?? 0),
                    'Variant read returns the exact route-created ID'
                );

                $updatePayload = $payload;
                $updatePayload['variants']['id'] = $variantId;
                $updatePayload['variants']['variation_title'] = $updatedTitle;
                $update = FcTest::rest(
                    'POST',
                    '/products/variants/' . $variantId,
                    $updatePayload
                );
                FcCrudFixture::requireHealthy(
                    $update,
                    'POST /products/variants/{variantId}'
                );

                $after = FcCrudFixture::snapshot('product_variation', $variantId);
                if ($after === null) {
                    throw new RuntimeException('Variant disappeared after route update.');
                }
                FcTest::assertSame(
                    $updatedTitle,
                    $after['variation_title'],
                    'Variant route stores the exact requested title'
                );
                $expectedOtherInfo = json_decode($before['other_info'], true);
                $expectedOtherInfo['is_bundle_product'] = 'no';
                $expectedOtherInfo['bundle_child_ids'] = [];
                $expectedDefect = $before;
                $expectedDefect['variation_title'] = $updatedTitle;
                $expectedDefect['other_info'] = wp_json_encode($expectedOtherInfo);
                if (
                    array_key_exists('updated_at', $before)
                    && array_key_exists('updated_at', $after)
                ) {
                    $expectedDefect['updated_at'] = $after['updated_at'];
                }

                if ($after === $expectedDefect) {
                    FcTest::skip(
                        'KNOWN-FAILURE — ProductVariationResource.php:241-281 appends '
                        . 'is_bundle_product and bundle_child_ids during an unrelated '
                        . 'title update; full-row diff='
                        . wp_json_encode(FcCrudFixture::rowDifferences($before, $after))
                    );
                } else {
                    $repaired = $before;
                    $repaired['variation_title'] = $updatedTitle;
                    if (
                        array_key_exists('updated_at', $before)
                        && array_key_exists('updated_at', $after)
                    ) {
                        $repaired['updated_at'] = $after['updated_at'];
                    }
                    if ($after === $repaired) {
                        FcTest::fail(
                            'KNOWN-FAILURE unexpectedly passed; reclassify the Variant '
                            . 'full-row preservation round-trip.'
                        );
                    } else {
                        FcTest::fail(
                            'KNOWN-FAILURE Variant row mutation drifted from the documented defect.'
                            . "\n  expected defect: " . wp_json_encode($expectedDefect)
                            . "\n  actual: " . wp_json_encode($after)
                        );
                    }
                }

                $activityHighWater = FcCrudFixture::activityHighWater();
                $delete = FcTest::rest(
                    'DELETE',
                    '/products/variants/' . $variantId
                );
                FcCrudFixture::requireHealthy(
                    $delete,
                    'DELETE /products/variants/{variantId}'
                );
                FcCrudFixture::captureMarkerActivitiesAfter(
                    $activityHighWater,
                    $updatedTitle,
                    'FluentCart\\App\\Models\\ProductVariation'
                );
                FcTest::assertSame(
                    null,
                    FcCrudFixture::snapshot('product_variation', $variantId),
                    'Variant exact route-created row is absent after delete'
                );
            } finally {
                FcCrudFixture::cleanupAll();
                FcDomainFixture::cleanupAll();
            }

            FcTest::assertSame(
                0,
                FcCrudFixture::markerResidueCounts()['product_variation'],
                'Variant route marker is absent after finally cleanup'
            );
        },
    ],
];
