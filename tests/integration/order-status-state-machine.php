<?php
/**
 * Phase 5 Order status state machine through OrderResource::updateStatuses().
 */

return [
    [
        'id'            => 'order-status-state-machine',
        'name'          => 'Order status machine rejects invalid and terminal transitions',
        'kind'          => 'behavior',
        'known_failure' => false,
        'run'           => function () {
            $identity = FcFixture::identity();

            try {
                FcFixture::customer();
                $primary = FcFixture::order([
                    'status' => 'processing',
                    'config' => ['fixture_case' => 'status-primary'],
                ]);
                $terminal = FcFixture::order([
                    'status' => 'canceled',
                    'config' => ['fixture_case' => 'status-terminal'],
                ]);
                $primaryId = (int) $primary->id;
                $terminalId = (int) $terminal->id;

                // Invalid status: reject before persistence or dispatch.
                $invalid = FcFixture::updateOrderStatus($primary, 'phase5-invalid-status');
                FcTest::assert(is_wp_error($invalid), 'Invalid Order status returns WP_Error');
                FcTest::assertSame(
                    'Provided status is not valid',
                    $invalid->get_error_message(),
                    'Invalid Order status returns the real resource rejection'
                );
                $stored = FcFixture::reloadOrder($primaryId);
                FcTest::assertSame(
                    'processing',
                    (string) $stored->status,
                    'Invalid Order status is not persisted'
                );
                FcTest::assertSame(
                    'pending',
                    (string) $stored->payment_status,
                    'Invalid Order status leaves payment pending'
                );
                FcTest::assertSame(
                    '',
                    (string) $stored->completed_at,
                    'Invalid Order status leaves completed_at empty'
                );
                FcFixture::assertNoForbiddenOrderSideEffects($primaryId, 0);

                // Processing -> completed: the real resource/model/event path.
                FcFixture::assertOrderStatusHooksSafe('completed');
                $completedLowerBound = gmdate('Y-m-d H:i:s');
                $completed = FcFixture::updateOrderStatus($stored, 'completed');
                $completedUpperBound = gmdate('Y-m-d H:i:s');
                FcTest::assert(is_array($completed), 'Processing to completed returns success data');
                FcTest::assertSame(
                    'Status has been updated',
                    isset($completed['message']) ? $completed['message'] : null,
                    'Processing to completed returns the real resource message'
                );
                FcTest::assertSame(
                    'completed',
                    isset($completed['data']) ? (string) $completed['data']->status : null,
                    'Processing to completed returns the updated Order'
                );

                $stored = FcFixture::reloadOrder($primaryId);
                $completedAt = (string) $stored->completed_at;
                FcTest::assertSame(
                    'completed',
                    (string) $stored->status,
                    'Processing to completed persists completed'
                );
                FcTest::assert(
                    preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $completedAt) === 1
                    && $completedAt >= $completedLowerBound
                    && $completedAt <= $completedUpperBound,
                    'Processing to completed persists a current GMT completed_at timestamp'
                );
                FcTest::assertSame(
                    'pending',
                    (string) $stored->payment_status,
                    'Completed Order fixture keeps payment strictly pending'
                );
                FcFixture::assertNoForbiddenOrderSideEffects($primaryId, 1);

                // Applying completed again: reject and preserve the timestamp.
                $sameStatus = FcFixture::updateOrderStatus($stored, 'completed');
                FcTest::assert(is_wp_error($sameStatus), 'Repeated completed status returns WP_Error');
                FcTest::assertSame(
                    'Order already has the same status',
                    $sameStatus->get_error_message(),
                    'Repeated completed status returns the idempotence rejection'
                );
                $stored = FcFixture::reloadOrder($primaryId);
                FcTest::assertSame(
                    'completed',
                    (string) $stored->status,
                    'Repeated completed status leaves persisted state unchanged'
                );
                FcTest::assertSame(
                    $completedAt,
                    (string) $stored->completed_at,
                    'Repeated completed status does not rewrite completed_at'
                );
                FcFixture::assertNoForbiddenOrderSideEffects($primaryId, 1);

                // A fixture created canceled is terminal; no canceled hook dispatches.
                $terminalResult = FcFixture::updateOrderStatus($terminal, 'processing');
                FcTest::assert(is_wp_error($terminalResult), 'Canceled Order transition returns WP_Error');
                FcTest::assertSame(
                    'You cannot change the order status once it has been canceled.',
                    $terminalResult->get_error_message(),
                    'Canceled Order returns the terminal-state rejection'
                );
                $storedTerminal = FcFixture::reloadOrder($terminalId);
                FcTest::assertSame(
                    'canceled',
                    (string) $storedTerminal->status,
                    'Canceled Order remains terminal in the database'
                );
                FcTest::assertSame(
                    'pending',
                    (string) $storedTerminal->payment_status,
                    'Canceled Order fixture keeps payment strictly pending'
                );
                FcTest::assertSame(
                    '',
                    (string) $storedTerminal->completed_at,
                    'Canceled terminal rejection leaves completed_at empty'
                );
                FcFixture::assertNoForbiddenOrderSideEffects($terminalId, 0);
            } finally {
                FcFixture::cleanupAll();
            }

            FcTest::assertSame(
                ['customer' => 0, 'order' => 0],
                FcFixture::residueCounts($identity),
                'Status-machine Order and Customer markers are absent after finally cleanup'
            );
        },
    ],
];
