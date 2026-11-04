<?php
/**
 * Phase 9 Coupon admin REST create/read/one-field update/delete round-trip.
 */

return [
    [
        'id'            => 'crud-coupon-route-round-trip',
        'name'          => 'Coupon admin CRUD preserves every non-notes physical column',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $code = strtoupper(FcCrudFixture::marker('coupon'));
            $createdNotes = FcCrudFixture::marker('coupon-created');
            $updatedNotes = FcCrudFixture::marker('coupon-updated');
            $payload = [
                'title' => FcCrudFixture::marker('coupon-title'),
                'code' => $code,
                'priority' => 7,
                'type' => 'fixed',
                'conditions' => [
                    'min_purchase_amount' => 25,
                    'max_discount_amount' => 0,
                    'min_amount_basis' => 'subtotal',
                    'apply_to_whole_cart' => 'yes',
                    'apply_to_quantity' => 'no',
                    'max_uses' => 20,
                    'max_per_customer' => 2,
                    'excluded_categories' => [],
                    'included_categories' => [],
                    'excluded_products' => [],
                    'included_products' => [],
                    'email_restrictions' => '',
                    'is_recurring' => 'no',
                ],
                'amount' => 12.34,
                'status' => 'active',
                'notes' => $createdNotes,
                'stackable' => 'no',
                'show_on_checkout' => 'yes',
                'start_date' => null,
                'end_date' => null,
            ];

            try {
                $create = FcTest::rest('POST', '/coupons/', $payload);
                FcCrudFixture::requireHealthy($create, 'POST /coupons/');
                $couponId = FcCrudFixture::createdId($create, 'POST /coupons/');
                FcCrudFixture::capture('coupon', $couponId, ['code' => $code]);
                FcCrudFixture::captureCouponActivities($couponId);

                $before = FcCrudFixture::snapshot('coupon', $couponId);
                if ($before === null) {
                    throw new RuntimeException('Coupon disappeared after route create.');
                }

                $read = FcTest::rest('GET', '/coupons/' . $couponId);
                FcCrudFixture::requireHealthy($read, 'GET /coupons/{id}');
                FcTest::assertSame(
                    $couponId,
                    (int) ($read['data']['coupon']['id'] ?? 0),
                    'Coupon read returns the exact route-created ID'
                );
                FcTest::assertSame(
                    $code,
                    (string) ($read['data']['coupon']['code'] ?? ''),
                    'Coupon read returns the exact ownership code'
                );

                $updatePayload = $payload;
                $updatePayload['id'] = $couponId;
                $updatePayload['notes'] = $updatedNotes;
                $update = FcTest::rest(
                    'PUT',
                    '/coupons/' . $couponId,
                    $updatePayload
                );
                FcCrudFixture::requireHealthy($update, 'PUT /coupons/{id}');
                FcCrudFixture::captureCouponActivities($couponId);

                $after = FcCrudFixture::snapshot('coupon', $couponId);
                if ($after === null) {
                    throw new RuntimeException('Coupon disappeared after route update.');
                }
                FcCrudFixture::assertOnlyFieldChanged(
                    $before,
                    $after,
                    'notes',
                    $updatedNotes,
                    'Coupon one-field route update'
                );

                $delete = FcTest::rest('DELETE', '/coupons/' . $couponId, [
                    'id' => $couponId,
                ]);
                FcCrudFixture::requireHealthy($delete, 'DELETE /coupons/{id}');
                FcTest::assertSame(
                    null,
                    FcCrudFixture::snapshot('coupon', $couponId),
                    'Coupon exact route-created row is absent after delete'
                );
            } finally {
                FcCrudFixture::cleanupAll();
            }

            FcTest::assertSame(
                0,
                FcCrudFixture::markerResidueCounts()['coupon'],
                'Coupon route marker is absent after finally cleanup'
            );
        },
    ],
];
