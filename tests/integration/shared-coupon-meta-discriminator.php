<?php
/**
 * Phase 6 generic fct_meta Coupon discriminator collision.
 */

return [
    [
        'id'            => 'shared-coupon-meta-discriminator',
        'name'          => 'Coupon meta reads and updates only its object_type row',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $metaKey = FcFixture::sharedValue('coupon-meta-key');
            $decoyValue = ['owner' => 'decoy', 'value' => 6101];
            $couponValue = ['owner' => 'coupon', 'value' => 6102];
            $updatedValue = ['owner' => 'coupon-updated', 'value' => 6103];

            try {
                $coupon = FcFixture::coupon();

                // Deliberately insert the same-ID/key decoy first.
                $decoy = FcFixture::meta(
                    (int) $coupon->id,
                    'phase6_decoy',
                    $metaKey,
                    $decoyValue
                );
                $correct = FcFixture::meta(
                    (int) $coupon->id,
                    'coupon',
                    $metaKey,
                    $couponValue
                );

                FcTest::assertSame(
                    $couponValue,
                    $coupon->getMeta($metaKey),
                    'Coupon getMeta returns the exact coupon value and excludes decoy ID '
                    . (int) $decoy->id
                );

                $updated = $coupon->updateMeta($metaKey, $updatedValue);
                FcTest::assertSame(
                    (int) $correct->id,
                    (int) $updated->id,
                    'Coupon updateMeta updates the exact owned coupon row'
                );
                FcFixture::expectSharedRowValues(
                    'meta',
                    (int) $correct->id,
                    ['meta_value' => $updatedValue]
                );

                $storedCorrect = FcFixture::reloadSharedRow('meta', (int) $correct->id);
                $storedDecoy = FcFixture::reloadSharedRow('meta', (int) $decoy->id);
                FcTest::assertSame(
                    'coupon',
                    (string) $storedCorrect->object_type,
                    'Updated Coupon meta preserves the coupon discriminator'
                );
                FcTest::assertSame(
                    $updatedValue,
                    $storedCorrect->meta_value,
                    'Updated Coupon meta persists the exact new value'
                );
                FcTest::assertSame(
                    'phase6_decoy',
                    (string) $storedDecoy->object_type,
                    'Coupon meta decoy preserves its exact discriminator'
                );
                FcTest::assertSame(
                    $decoyValue,
                    $storedDecoy->meta_value,
                    'Coupon updateMeta does not touch the same-ID/key decoy value'
                );
            } finally {
                FcFixture::cleanupAll();
            }

            FcTest::assertSame(
                array_fill_keys(array_keys(FcFixture::sharedResidueCounts()), 0),
                FcFixture::sharedResidueCounts(),
                'Coupon meta collision rows and exact Coupon have zero residue'
            );
        },
    ],
];
