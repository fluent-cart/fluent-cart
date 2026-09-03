<?php
/**
 * Phase 4 foundation: one exact-value Customer model/DB round-trip.
 */

return [
    [
        'id'            => 'customer-model-round-trip',
        'name'          => 'Customer model persists and reloads exact owned values',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $identity = FcFixture::identity();
            $expected = [
                'first_name'     => 'Phase Four',
                'last_name'      => 'Fixture',
                'status'         => 'active',
                'purchase_value' => [
                    'currency' => 'USD',
                    'gross'    => 12345,
                ],
                'purchase_count' => 0,
                'ltv'            => 0,
                'notes'          => 'Owned integration fixture ' . $identity,
                'country'        => 'BD',
                'city'           => 'Dhaka',
                'state'          => 'C',
                'postcode'       => '1205',
            ];

            try {
                $created = FcFixture::customer($expected);
                $createdId = (int) $created->id;
                $createdUuid = (string) $created->uuid;

                FcTest::assert($createdId > 0, 'Customer create returns a positive exact ID');
                FcTest::assert(
                    preg_match('/^[a-f0-9]{32}$/', $createdUuid) === 1,
                    'Customer create hook persists a 32-character UUID'
                );

                $stored = FcFixture::reloadCustomer();
                FcTest::assertSame($createdId, (int) $stored->id, 'Customer exact ID round-trips');
                FcTest::assertSame($identity, (string) $stored->email, 'Customer email round-trips');
                FcTest::assertSame(
                    $expected['first_name'],
                    (string) $stored->first_name,
                    'Customer first_name round-trips'
                );
                FcTest::assertSame(
                    $expected['last_name'],
                    (string) $stored->last_name,
                    'Customer last_name round-trips'
                );
                FcTest::assertSame(
                    $expected['status'],
                    (string) $stored->status,
                    'Customer status round-trips'
                );
                FcTest::assertSame(
                    $expected['purchase_value'],
                    $stored->purchase_value,
                    'Customer JSON purchase_value round-trips'
                );
                FcTest::assertSame(
                    $expected['purchase_count'],
                    (int) $stored->purchase_count,
                    'Customer purchase_count round-trips'
                );
                FcTest::assertSame($expected['ltv'], (int) $stored->ltv, 'Customer ltv round-trips');
                FcTest::assertSame(
                    $expected['notes'],
                    (string) $stored->notes,
                    'Customer notes round-trips'
                );
                FcTest::assertSame(
                    $expected['country'],
                    (string) $stored->country,
                    'Customer country round-trips'
                );
                FcTest::assertSame($expected['city'], (string) $stored->city, 'Customer city round-trips');
                FcTest::assertSame(
                    $expected['state'],
                    (string) $stored->state,
                    'Customer state round-trips'
                );
                FcTest::assertSame(
                    $expected['postcode'],
                    (string) $stored->postcode,
                    'Customer postcode round-trips'
                );
                FcTest::assertSame(
                    $createdUuid,
                    (string) $stored->uuid,
                    'Customer generated UUID round-trips exactly'
                );
            } finally {
                FcFixture::cleanupCustomer();
            }

            FcTest::assertSame(
                0,
                FcFixture::residueCount($identity),
                'Customer exact identity is absent after finally cleanup'
            );
        },
    ],
];
