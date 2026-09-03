<?php
/**
 * Phase 9 safe Product REST read/update/delete legs.
 *
 * Product creation and post-field update are inventory exclusions because
 * their WordPress post APIs reach cron mutation hooks on this install.
 */

return [
    [
        'id'            => 'crud-product-safe-route-round-trip',
        'name'          => 'Safe Product routes preserve every non-editor ProductDetail column',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $editorMode = 'block-editor';

            try {
                $product = FcDomainFixture::product(['manage_stock' => 0]);
                $productId = (int) $product['post']->ID;
                $detailId = (int) $product['detail']->id;
                $productTitle = (string) $product['post']->post_title;

                $read = FcTest::rest(
                    'GET',
                    '/products/' . $productId . '/pricing'
                );
                FcCrudFixture::requireHealthy(
                    $read,
                    'GET /products/{productId}/pricing'
                );
                FcTest::assertSame(
                    $productId,
                    (int) ($read['data']['product']['ID'] ?? 0),
                    'Product read returns the exact model-seeded ID'
                );

                $before = FcCrudFixture::snapshot('product_detail', $detailId);
                if ($before === null) {
                    throw new RuntimeException('ProductDetail disappeared before route update.');
                }

                $update = FcTest::rest(
                    'POST',
                    '/products/' . $productId . '/update-long-desc-editor-mode',
                    ['active_editor' => $editorMode]
                );
                FcCrudFixture::requireHealthy(
                    $update,
                    'POST /products/{postId}/update-long-desc-editor-mode'
                );

                $after = FcCrudFixture::snapshot('product_detail', $detailId);
                if ($after === null) {
                    throw new RuntimeException('ProductDetail disappeared after route update.');
                }
                FcCrudFixture::assertOnlyFieldChanged(
                    $before,
                    $after,
                    'other_info',
                    wp_json_encode(['active_editor' => $editorMode]),
                    'ProductDetail one-column route update'
                );

                $activityHighWater = FcCrudFixture::activityHighWater();
                $delete = FcTest::rest('DELETE', '/products/' . $productId);
                FcCrudFixture::requireHealthy(
                    $delete,
                    'DELETE /products/{product}'
                );
                FcCrudFixture::captureMarkerActivitiesAfter(
                    $activityHighWater,
                    $productTitle,
                    'FluentCart\\App\\Models\\Product'
                );
                FcTest::assertSame(
                    null,
                    FcCrudFixture::snapshot('product_detail', $detailId),
                    'ProductDetail exact row is absent after Product delete'
                );
                FcTest::assertSame(
                    null,
                    \FluentCart\App\Models\Product::query()->find($productId),
                    'Product exact post row is absent after Product delete'
                );
            } finally {
                FcCrudFixture::cleanupAll();
                FcDomainFixture::cleanupAll();
            }
        },
    ],
];
