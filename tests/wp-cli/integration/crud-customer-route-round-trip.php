<?php
/**
 * Phase 9 Customer admin REST create/read/one-field update/delete round-trip.
 */

return [
    [
        'id'            => 'crud-customer-route-round-trip',
        'name'          => 'Customer admin CRUD preserves every non-notes physical column',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $email = FcCrudFixture::customerEmail();
            $createdNotes = FcCrudFixture::marker('customer-created');
            $updatedNotes = FcCrudFixture::marker('customer-updated');
            $payload = [
                'full_name' => 'Phase Nine Customer',
                'first_name' => 'Phase Nine',
                'last_name' => 'Customer',
                'email' => $email,
                'status' => 'active',
                'notes' => $createdNotes,
                'country' => 'BD',
                'city' => 'Dhaka',
                'state' => 'C',
                'postcode' => '1205',
                'wp_user' => 'no',
            ];

            try {
                $create = FcTest::rest('POST', '/customers/', $payload);
                FcCrudFixture::requireHealthy($create, 'POST /customers/');
                $customerId = FcCrudFixture::createdId($create, 'POST /customers/');
                FcCrudFixture::capture('customer', $customerId, ['email' => $email]);

                $before = FcCrudFixture::snapshot('customer', $customerId);
                if ($before === null) {
                    throw new RuntimeException('Customer disappeared after route create.');
                }

                $read = FcTest::rest('GET', '/customers/' . $customerId, [
                    'params' => ['customer_only' => 'yes'],
                ]);
                FcCrudFixture::requireHealthy($read, 'GET /customers/{customerId}');
                FcTest::assertSame(
                    $customerId,
                    (int) ($read['data']['customer']['id'] ?? 0),
                    'Customer read returns the exact route-created ID'
                );
                FcTest::assertSame(
                    $email,
                    (string) ($read['data']['customer']['email'] ?? ''),
                    'Customer read returns the exact ownership email'
                );

                $updatePayload = $payload;
                $updatePayload['id'] = $customerId;
                $updatePayload['notes'] = $updatedNotes;
                $update = FcTest::rest(
                    'PUT',
                    '/customers/' . $customerId,
                    $updatePayload
                );
                FcCrudFixture::requireHealthy($update, 'PUT /customers/{customerId}');

                $after = FcCrudFixture::snapshot('customer', $customerId);
                if ($after === null) {
                    throw new RuntimeException('Customer disappeared after route update.');
                }
                FcCrudFixture::assertOnlyFieldChanged(
                    $before,
                    $after,
                    'notes',
                    $updatedNotes,
                    'Customer one-field route update'
                );

                $delete = FcTest::rest('POST', '/customers/do-bulk-action', [
                    'action' => 'delete_customers',
                    'customer_ids' => [$customerId],
                ]);
                FcCrudFixture::requireHealthy(
                    $delete,
                    'POST /customers/do-bulk-action delete_customers'
                );
                FcTest::assertSame(
                    null,
                    FcCrudFixture::snapshot('customer', $customerId),
                    'Customer exact route-created row is absent after delete'
                );
            } finally {
                FcCrudFixture::cleanupAll();
            }

            FcTest::assertSame(
                0,
                FcCrudFixture::markerResidueCounts()['customer'],
                'Customer route marker is absent after finally cleanup'
            );
        },
    ],
];
