<?php
/**
 * Phase 6 fct_label_relationships morph discriminator collisions.
 */

return [
    [
        'id'            => 'shared-label-discriminator',
        'name'          => 'Order and Customer labels exclude same-ID morph decoys',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $orderClass = 'FluentCart\\App\\Models\\Order';
            $customerClass = 'FluentCart\\App\\Models\\Customer';

            try {
                $customer = FcFixture::customer();
                $order = FcFixture::order([
                    'config' => ['fixture_case' => 'shared-label-discriminator'],
                ]);
                $orderLabel = FcFixture::label('label-order');
                $customerLabel = FcFixture::label('label-customer');

                $orderCorrect = FcFixture::labelRelationship(
                    (int) $orderLabel->id,
                    (int) $order->id,
                    $orderClass
                );
                $orderDecoy = FcFixture::labelRelationship(
                    (int) $orderLabel->id,
                    (int) $order->id,
                    $customerClass
                );
                $customerCorrect = FcFixture::labelRelationship(
                    (int) $customerLabel->id,
                    (int) $customer->id,
                    $customerClass
                );
                $customerDecoy = FcFixture::labelRelationship(
                    (int) $customerLabel->id,
                    (int) $customer->id,
                    $orderClass
                );

                $orderRows = $order->labels()->get();
                FcTest::assertSame(
                    [(int) $orderCorrect->id],
                    array_map('intval', $orderRows->pluck('id')->toArray()),
                    'Order labels return the exact Order relationship and exclude same-ID '
                    . 'Customer decoy ' . (int) $orderDecoy->id
                );
                FcTest::assertSame(
                    $orderClass,
                    (string) $orderRows->first()->labelable_type,
                    'Order label relationship preserves its exact labelable_type'
                );
                FcTest::assertSame(
                    (int) $orderLabel->id,
                    (int) $orderRows->first()->label_id,
                    'Order label relationship preserves its exact Label ID'
                );

                $customerRows = $customer->labels()->get();
                FcTest::assertSame(
                    [(int) $customerCorrect->id],
                    array_map('intval', $customerRows->pluck('id')->toArray()),
                    'Customer labels return the exact Customer relationship and exclude same-ID '
                    . 'Order decoy ' . (int) $customerDecoy->id
                );
                FcTest::assertSame(
                    $customerClass,
                    (string) $customerRows->first()->labelable_type,
                    'Customer label relationship preserves its exact labelable_type'
                );
                FcTest::assertSame(
                    (int) $customerLabel->id,
                    (int) $customerRows->first()->label_id,
                    'Customer label relationship preserves its exact Label ID'
                );
            } finally {
                FcFixture::cleanupAll();
            }

            FcTest::assertSame(
                array_fill_keys(array_keys(FcFixture::sharedResidueCounts()), 0),
                FcFixture::sharedResidueCounts(),
                'Label collision relationships and exact parents have zero residue'
            );
        },
    ],
];
