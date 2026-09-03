<?php
/**
 * Phase 6 fct_product_meta ProductMetaResource discriminator collisions.
 */

return [
    [
        'id'            => 'shared-product-meta-discriminator',
        'name'          => 'ProductMetaResource excludes object_type and meta_key decoys',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $resource = 'FluentCart\\Api\\Resource\\ProductMetaResource';
            $objectId = FcFixture::productMetaObjectId();
            $decoyTypeValue = ['owner' => 'decoy-type', 'asset_id' => 6201];
            $decoyKeyValue = ['owner' => 'decoy-key', 'asset_id' => 6202];
            $correctValue = ['owner' => 'thumbnail', 'asset_id' => 6203];

            try {
                // Same object/key wrong type first, then same object/type wrong key.
                $decoyType = FcFixture::productMeta(
                    $objectId,
                    'phase6_decoy',
                    'product_thumbnail',
                    $decoyTypeValue
                );
                $decoyKey = FcFixture::productMeta(
                    $objectId,
                    'product_variant_info',
                    'phase6_decoy_key',
                    $decoyKeyValue
                );
                $correct = FcFixture::productMeta(
                    $objectId,
                    'product_variant_info',
                    'product_thumbnail',
                    $correctValue
                );

                $found = $resource::find($objectId);
                FcTest::assertSame(
                    (int) $correct->id,
                    (int) $found->id,
                    'ProductMetaResource find returns the exact thumbnail row and excludes '
                    . 'same-ID type decoy ' . (int) $decoyType->id
                    . ' and key decoy ' . (int) $decoyKey->id
                );
                FcTest::assertSame(
                    'product_variant_info',
                    (string) $found->object_type,
                    'ProductMetaResource find preserves exact object_type'
                );
                FcTest::assertSame(
                    'product_thumbnail',
                    (string) $found->meta_key,
                    'ProductMetaResource find preserves exact meta_key'
                );
                FcTest::assertSame(
                    $correctValue,
                    $found->meta_value,
                    'ProductMetaResource find returns the exact thumbnail value'
                );

                $batch = $resource::findByIds([$objectId]);
                FcTest::assertSame(
                    1,
                    count($batch),
                    'ProductMetaResource findByIds returns only one discriminator match'
                );
                $batchRow = $batch->first();
                FcTest::assertSame(
                    $objectId,
                    (int) $batchRow->object_id,
                    'ProductMetaResource findByIds preserves the exact object ID'
                );
                FcTest::assertSame(
                    $correctValue,
                    $batchRow->meta_value,
                    'ProductMetaResource findByIds excludes both exact decoy values'
                );

                FcTest::assertSame(
                    $decoyTypeValue,
                    FcFixture::reloadSharedRow(
                        'product_meta',
                        (int) $decoyType->id
                    )->meta_value,
                    'Object-type decoy remains separately identifiable by exact ID/value'
                );
                FcTest::assertSame(
                    $decoyKeyValue,
                    FcFixture::reloadSharedRow(
                        'product_meta',
                        (int) $decoyKey->id
                    )->meta_value,
                    'Meta-key decoy remains separately identifiable by exact ID/value'
                );
            } finally {
                FcFixture::cleanupAll();
            }

            FcTest::assertSame(
                array_fill_keys(array_keys(FcFixture::sharedResidueCounts()), 0),
                FcFixture::sharedResidueCounts(),
                'ProductMeta collision rows have zero exact-ID residue'
            );
        },
    ],
];
