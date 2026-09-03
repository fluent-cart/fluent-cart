<?php
/**
 * Phase 6 fct_activity morph discriminator collisions.
 */

return [
    [
        'id'            => 'shared-activity-discriminator',
        'name'          => 'Order and Coupon activities exclude same-ID morph decoys',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $orderClass = 'FluentCart\\App\\Models\\Order';
            $couponClass = 'FluentCart\\App\\Models\\Coupon';

            try {
                FcFixture::customer();
                $order = FcFixture::order([
                    'config' => ['fixture_case' => 'shared-activity-discriminator'],
                ]);
                $coupon = FcFixture::coupon();

                $orderCorrect = FcFixture::activity(
                    (int) $order->id,
                    $orderClass,
                    'activity-order-correct'
                );
                $orderDecoy = FcFixture::activity(
                    (int) $order->id,
                    $couponClass,
                    'activity-order-decoy'
                );
                $couponCorrect = FcFixture::activity(
                    (int) $coupon->id,
                    $couponClass,
                    'activity-coupon-correct'
                );
                $couponDecoy = FcFixture::activity(
                    (int) $coupon->id,
                    $orderClass,
                    'activity-coupon-decoy'
                );

                $orderRows = $order->activities()->get();
                $orderIds = array_map('intval', $orderRows->pluck('id')->toArray());
                FcTest::assertSame(
                    [(int) $orderCorrect->id],
                    $orderIds,
                    'Order activities return the exact Order row and exclude same-ID Coupon decoy '
                    . (int) $orderDecoy->id
                );
                FcTest::assertSame(
                    $orderClass,
                    (string) $orderRows->first()->module_type,
                    'Order activity preserves its exact module_type'
                );
                FcTest::assertSame(
                    FcFixture::sharedValue('activity-order-correct'),
                    (string) $orderRows->first()->content,
                    'Order activity preserves its exact marker value'
                );

                $couponRows = $coupon->activities()->get();
                $couponIds = array_map('intval', $couponRows->pluck('id')->toArray());
                FcTest::assertSame(
                    [(int) $couponCorrect->id],
                    $couponIds,
                    'Coupon activities return the exact Coupon row and exclude same-ID Order decoy '
                    . (int) $couponDecoy->id
                );
                FcTest::assertSame(
                    $couponClass,
                    (string) $couponRows->first()->module_type,
                    'Coupon activity preserves its exact module_type'
                );
                FcTest::assertSame(
                    FcFixture::sharedValue('activity-coupon-correct'),
                    (string) $couponRows->first()->content,
                    'Coupon activity preserves its exact marker value'
                );
            } finally {
                FcFixture::cleanupAll();
            }

            FcTest::assertSame(
                array_fill_keys(array_keys(FcFixture::sharedResidueCounts()), 0),
                FcFixture::sharedResidueCounts(),
                'Activity collision rows and exact parents have zero residue'
            );
        },
    ],
];
