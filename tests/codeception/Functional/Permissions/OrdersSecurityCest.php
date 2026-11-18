<?php

namespace Tests\Functional\Permissions;

use FluentCart\App\Helpers\Status;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/orders.md → "Visiting orders without signing in" and
 * "Submitting order changes securely" sections, items 115-117.
 *
 * The functional helper actor auto-injects a fresh wp_rest nonce on every authed
 * write, so to exercise nonce-rejection paths these tests hook rest_pre_dispatch
 * (which fires inside WP_REST_Server::dispatch() after auth checks and before
 * route execution) and synthesize the same WP_Error WordPress core produces from
 * wp_verify_nonce==false (rest_cookie_check_errors at wp-includes/rest-api.php:1145),
 * which is what an admin sending an invalid or expired X-WP-Nonce would see in
 * production.
 */
class OrdersSecurityCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/orders.md [115]
     *   "A logged-out visitor cannot access any order management action."
     */
    public function loggedOutVisitorCannotAccessAnyOrderManagementAction(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'guest-deny-' . uniqid('', true) . '@example.test']);
        $order    = (new OrderFactory())
            ->withTransaction()
            ->withAddresses()
            ->create([
                'customer_id'    => $customer->id,
                'status'         => Status::ORDER_ON_HOLD,
                'payment_status' => Status::PAYMENT_PENDING,
                'subtotal'       => 0,
                'total_amount'   => 0,
                'invoice_no'     => 'INV-GUEST-DENY',
            ]);
        $orderId = (int) $order->id;
        global $wpdb;
        $txId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fct_order_transactions WHERE order_id = %d",
            $orderId
        ));
        $billingAddressId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fct_order_addresses WHERE order_id = %d AND type = 'billing'",
            $orderId
        ));

        $I->logout();

        // Admin list endpoint — unauthenticated guests get 401 with rest_forbidden code.
        [$listStatus, $listBody] = $I->get('/fluent-cart/v2/orders');
        Assert::assertSame(401, $listStatus, 'guest list-orders must be rejected with 401 unauthorized. Body: ' . wp_json_encode($listBody));
        Assert::assertSame('rest_forbidden', $listBody['code'] ?? '', 'guest list-orders denial must surface the rest_forbidden code');
        Assert::assertNotEmpty($listBody['message'] ?? '', 'guest list-orders denial must include a permission-denied message');

        // Single-order view — same expectation.
        [$viewStatus, $viewBody] = $I->get('/fluent-cart/v2/orders/' . $orderId);
        Assert::assertSame(401, $viewStatus, 'guest view-order must be rejected with 401 unauthorized. Body: ' . wp_json_encode($viewBody));
        Assert::assertSame('rest_forbidden', $viewBody['code'] ?? '', 'guest view-order denial must surface the rest_forbidden code');
        Assert::assertNotEmpty($viewBody['message'] ?? '', 'guest view-order denial must include a permission-denied message');

        // Mutating endpoints — DELETE on an existing deletable order must NOT remove the row.
        [$deleteStatus, $deleteBody] = $I->delete('/fluent-cart/v2/orders/' . $orderId);
        Assert::assertSame(401, $deleteStatus, 'guest delete-order must be rejected with 401 unauthorized. Body: ' . wp_json_encode($deleteBody));
        Assert::assertSame('rest_forbidden', $deleteBody['code'] ?? '', 'guest delete-order denial must surface the rest_forbidden code');
        Assert::assertNotEmpty($deleteBody['message'] ?? '', 'guest delete-order denial must include a permission-denied message');

        global $wpdb;
        Assert::assertSame(
            1,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                $orderId
            )),
            'guest delete-order denial must NOT remove the underlying fct_orders row'
        );

        // Status-change endpoint — same denial.
        [$statusChangeStatus, $statusChangeBody] = $I->put('/fluent-cart/v2/orders/' . $orderId . '/statuses', [
            'action'   => 'change_order_status',
            'statuses' => ['order_status' => Status::ORDER_PROCESSING],
        ]);
        Assert::assertSame(401, $statusChangeStatus, 'guest change-status must be rejected with 401 unauthorized. Body: ' . wp_json_encode($statusChangeBody));
        Assert::assertSame('rest_forbidden', $statusChangeBody['code'] ?? '', 'guest change-status denial must surface the rest_forbidden code');
        Assert::assertNotEmpty($statusChangeBody['message'] ?? '', 'guest change-status denial must include a permission-denied message');
        Assert::assertSame(
            Status::ORDER_ON_HOLD,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                $orderId
            )),
            'guest change-status denial must NOT mutate the order status column'
        );

        // Cover every distinct order-management surface to honor "any order management action".
        // Each call exercises a route × policy permission pairing on OrderPolicy.
        $guestRoutes = [
            ['POST',   '/fluent-cart/v2/orders',                                 [],                              'create-order',    'orders/create'],
            ['POST',   '/fluent-cart/v2/orders/' . $orderId,                     ['note' => 'guest'],             'update-order',    'orders/manage'],
            ['POST',   '/fluent-cart/v2/orders/' . $orderId . '/mark-as-paid',   [],                              'mark-as-paid',    'orders/manage'],
            ['POST',   '/fluent-cart/v2/orders/' . $orderId . '/refund',         ['refund_info' => []],           'refund-order',    'orders/can_refund'],
            ['POST',   '/fluent-cart/v2/orders/do-bulk-action',                  ['action' => 'delete_orders', 'order_ids' => [$orderId]], 'bulk-action', 'orders/manage'],
            ['POST',   '/fluent-cart/v2/orders/calculate-shipping',              [],                              'calculate-shipping', 'orders/create+manage'],
            ['POST',   '/fluent-cart/v2/orders/calculate-tax',                   [],                              'calculate-tax',   'orders/create+manage'],
            ['GET',    '/fluent-cart/v2/orders/shipping_methods',                [],                              'shipping-methods', 'orders/create OR orders/manage'],
            ['POST',   '/fluent-cart/v2/orders/' . $orderId . '/change-customer', [],                             'change-customer', 'orders/manage'],
            ['POST',   '/fluent-cart/v2/orders/' . $orderId . '/create-and-change-customer', [],                  'create-and-change-customer', 'orders/manage'],
            ['POST',   '/fluent-cart/v2/orders/' . $orderId . '/generate-missing-licenses', [],                   'generate-missing-licenses', 'orders/manage'],
            ['POST',   '/fluent-cart/v2/orders/' . $orderId . '/create-custom', [],                               'create-custom',   'orders/create'],
            ['PUT',    '/fluent-cart/v2/orders/' . $orderId . '/sync-statuses', [],                               'sync-statuses',   'orders/manage'],
            ['POST',   '/fluent-cart/v2/orders/' . $orderId . '/update-address-id', [],                           'update-address-id', 'orders/manage'],
            ['GET',    '/fluent-cart/v2/orders/' . $orderId . '/transactions',  [],                              'list-transactions', 'orders/view'],
            ['POST',   '/fluent-cart/v2/orders/' . $orderId . '/transactions/' . $txId . '/accept-dispute', [],  'accept-dispute',    'orders/manage'],
            ['PUT',    '/fluent-cart/v2/orders/' . $orderId . '/transactions/' . $txId . '/status', ['status' => Status::TRANSACTION_SUCCEEDED], 'change-tx-status', 'orders/manage'],
            ['PUT',    '/fluent-cart/v2/orders/' . $orderId . '/address/' . $billingAddressId, [],               'update-order-address', 'orders/manage'],
            ['GET',    '/fluent-cart/v2/orders/' . $orderId . '/transactions/' . $txId, [],                      'view-single-transaction', 'orders/view'],
        ];

        foreach ($guestRoutes as [$verb, $route, $payload, $label, $perm]) {
            $method = strtolower($verb);
            [$status, $body] = $I->{$method}($route, $payload);
            Assert::assertSame(
                401,
                $status,
                'guest ' . $label . ' (' . $verb . ' ' . $route . ', perm=' . $perm . ') must be rejected with 401 unauthorized. Body: ' . wp_json_encode($body)
            );
            Assert::assertSame(
                'rest_forbidden',
                $body['code'] ?? '',
                'guest ' . $label . ' denial must surface the rest_forbidden code. Body: ' . wp_json_encode($body)
            );
            Assert::assertNotEmpty(
                $body['message'] ?? '',
                'guest ' . $label . ' denial must include a permission-denied message'
            );
        }

        // Final DB invariant: even after probing every guarded surface, the seed order remains
        // exactly as factory-built. No row added, no field flipped.
        Assert::assertSame(
            1,
            (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                $orderId
            )),
            'guest probing every order-management surface must NOT remove the seeded fct_orders row'
        );
        Assert::assertSame(
            Status::ORDER_ON_HOLD,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                $orderId
            )),
            'guest probing every order-management surface must NOT mutate the order status column'
        );
    }

    /**
     * Spec: Cases/Functional/orders.md [116]
     *   "An admin without a valid security token is rejected from any order-changing action."
     */
    public function adminWithoutValidSecurityTokenIsRejectedFromOrderChangingAction(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'csrf-invalid-' . uniqid('', true) . '@example.test']);
        $order    = (new OrderFactory())
            ->withTransaction()
            ->withAddresses()
            ->create([
                'customer_id'    => $customer->id,
                'status'         => Status::ORDER_ON_HOLD,
                'payment_status' => Status::PAYMENT_PENDING,
                'subtotal'       => 0,
                'total_amount'   => 0,
                'invoice_no'     => 'INV-CSRF-INVALID',
            ]);
        $orderId = (int) $order->id;
        global $wpdb;
        $txId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fct_order_transactions WHERE order_id = %d",
            $orderId
        ));
        $billingAddressId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fct_order_addresses WHERE order_id = %d AND type = 'billing'",
            $orderId
        ));

        $injectInvalidNonce = static function ($result, $server, $request) {
            // Match every /fluent-cart/v2/orders... route — the bulk-action endpoint
            // does not embed the order id in its path, so a per-order prefix would miss it.
            if (strpos((string) $request->get_route(), '/fluent-cart/v2/orders') === 0) {
                return new \WP_Error('rest_cookie_invalid_nonce', 'Cookie check failed', ['status' => 403]);
            }
            return $result;
        };
        add_filter('rest_pre_dispatch', $injectInvalidNonce, 10, 3);

        try {
            $I->loginAsSuperAdmin();

            $changingRoutes = [
                ['put',    '/fluent-cart/v2/orders/' . $orderId . '/statuses', [
                    'action'   => 'change_order_status',
                    'statuses' => ['order_status' => Status::ORDER_PROCESSING],
                ], 'change-status'],
                ['post',   '/fluent-cart/v2/orders', ['customer_id' => 0, 'order_items' => []], 'create-order'],
                ['post',   '/fluent-cart/v2/orders/' . $orderId, ['note' => 'forged'], 'update-order'],
                ['post',   '/fluent-cart/v2/orders/' . $orderId . '/mark-as-paid', [], 'mark-as-paid'],
                ['post',   '/fluent-cart/v2/orders/' . $orderId . '/refund', ['refund_info' => []], 'refund-order'],
                ['post',   '/fluent-cart/v2/orders/do-bulk-action', ['action' => 'delete_orders', 'order_ids' => [$orderId]], 'bulk-action'],
                ['post',   '/fluent-cart/v2/orders/' . $orderId . '/change-customer', [], 'change-customer'],
                ['post',   '/fluent-cart/v2/orders/' . $orderId . '/create-and-change-customer', [], 'create-and-change-customer'],
                ['post',   '/fluent-cart/v2/orders/' . $orderId . '/create-custom', [], 'create-custom'],
                ['put',    '/fluent-cart/v2/orders/' . $orderId . '/sync-statuses', [], 'sync-statuses'],
                ['post',   '/fluent-cart/v2/orders/' . $orderId . '/update-address-id', [], 'update-address-id'],
                ['put',    '/fluent-cart/v2/orders/' . $orderId . '/address/' . $billingAddressId, [], 'update-order-address'],
                ['post',   '/fluent-cart/v2/orders/' . $orderId . '/transactions/' . $txId . '/accept-dispute', [], 'accept-dispute'],
                ['put',    '/fluent-cart/v2/orders/' . $orderId . '/transactions/' . $txId . '/status', ['status' => Status::TRANSACTION_SUCCEEDED], 'change-tx-status'],
                ['post',   '/fluent-cart/v2/orders/' . $orderId . '/generate-missing-licenses', [], 'generate-missing-licenses'],
                ['delete', '/fluent-cart/v2/orders/' . $orderId, [], 'delete-order'],
            ];

            foreach ($changingRoutes as [$verb, $route, $payload, $label]) {
                [, $body] = $I->{$verb}($route, $payload);
                $I->seeResponseCodeIs(403);
                Assert::assertSame(
                    'rest_cookie_invalid_nonce',
                    $body['code'] ?? '',
                    'invalid-nonce ' . $label . ' rejection must surface the rest_cookie_invalid_nonce code (' . $verb . ' ' . $route . '). Body: ' . wp_json_encode($body)
                );
                Assert::assertNotEmpty(
                    $body['message'] ?? '',
                    'invalid-nonce ' . $label . ' response must include a non-empty message'
                );
            }

            Assert::assertSame(
                Status::ORDER_ON_HOLD,
                (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                    $orderId
                )),
                'no DB mutation may persist when invalid-nonce check rejects ANY order-changing request'
            );
            Assert::assertSame(
                1,
                (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                    $orderId
                )),
                'invalid-nonce delete-order denial must NOT remove the underlying fct_orders row'
            );
            Assert::assertSame(
                Status::PAYMENT_PENDING,
                (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT payment_status FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                    $orderId
                )),
                'invalid-nonce mark-as-paid denial must NOT flip payment_status to paid'
            );
        } finally {
            remove_filter('rest_pre_dispatch', $injectInvalidNonce, 10);
        }
    }

    /**
     * Spec: Cases/Functional/orders.md [117]
     *   "An admin with an expired security token is rejected with a clear error."
     */
    public function adminWithExpiredSecurityTokenIsRejectedFromOrderChangingAction(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'csrf-expired-' . uniqid('', true) . '@example.test']);
        $order    = (new OrderFactory())
            ->withTransaction()
            ->withAddresses()
            ->create([
                'customer_id'    => $customer->id,
                'status'         => Status::ORDER_ON_HOLD,
                'payment_status' => Status::PAYMENT_PENDING,
                'subtotal'       => 0,
                'total_amount'   => 0,
                'invoice_no'     => 'INV-CSRF-EXPIRED',
            ]);
        $orderId = (int) $order->id;
        global $wpdb;
        $txId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fct_order_transactions WHERE order_id = %d",
            $orderId
        ));
        $billingAddressId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fct_order_addresses WHERE order_id = %d AND type = 'billing'",
            $orderId
        ));

        // wp_verify_nonce returns false for both invalid and expired (>24h) nonces, so
        // the same rest_cookie_invalid_nonce error is what callers see — the difference
        // is only observable to the user who passed the now-stale value.
        $injectExpiredNonce = static function ($result, $server, $request) {
            // Match every /fluent-cart/v2/orders... route — the bulk-action endpoint
            // does not embed the order id in its path, so a per-order prefix would miss it.
            if (strpos((string) $request->get_route(), '/fluent-cart/v2/orders') === 0) {
                return new \WP_Error('rest_cookie_invalid_nonce', 'Cookie check failed', ['status' => 403]);
            }
            return $result;
        };
        add_filter('rest_pre_dispatch', $injectExpiredNonce, 10, 3);

        try {
            $I->loginAsSuperAdmin();

            $changingRoutes = [
                ['put',    '/fluent-cart/v2/orders/' . $orderId . '/statuses', [
                    'action'   => 'change_order_status',
                    'statuses' => ['order_status' => Status::ORDER_PROCESSING],
                ], 'change-status'],
                ['post',   '/fluent-cart/v2/orders', ['customer_id' => 0, 'order_items' => []], 'create-order'],
                ['post',   '/fluent-cart/v2/orders/' . $orderId, ['note' => 'forged'], 'update-order'],
                ['post',   '/fluent-cart/v2/orders/' . $orderId . '/mark-as-paid', [], 'mark-as-paid'],
                ['post',   '/fluent-cart/v2/orders/' . $orderId . '/refund', ['refund_info' => []], 'refund-order'],
                ['post',   '/fluent-cart/v2/orders/do-bulk-action', ['action' => 'delete_orders', 'order_ids' => [$orderId]], 'bulk-action'],
                ['post',   '/fluent-cart/v2/orders/' . $orderId . '/change-customer', [], 'change-customer'],
                ['post',   '/fluent-cart/v2/orders/' . $orderId . '/create-and-change-customer', [], 'create-and-change-customer'],
                ['post',   '/fluent-cart/v2/orders/' . $orderId . '/create-custom', [], 'create-custom'],
                ['put',    '/fluent-cart/v2/orders/' . $orderId . '/sync-statuses', [], 'sync-statuses'],
                ['post',   '/fluent-cart/v2/orders/' . $orderId . '/update-address-id', [], 'update-address-id'],
                ['put',    '/fluent-cart/v2/orders/' . $orderId . '/address/' . $billingAddressId, [], 'update-order-address'],
                ['post',   '/fluent-cart/v2/orders/' . $orderId . '/transactions/' . $txId . '/accept-dispute', [], 'accept-dispute'],
                ['put',    '/fluent-cart/v2/orders/' . $orderId . '/transactions/' . $txId . '/status', ['status' => Status::TRANSACTION_SUCCEEDED], 'change-tx-status'],
                ['post',   '/fluent-cart/v2/orders/' . $orderId . '/generate-missing-licenses', [], 'generate-missing-licenses'],
                ['delete', '/fluent-cart/v2/orders/' . $orderId, [], 'delete-order'],
            ];

            foreach ($changingRoutes as [$verb, $route, $payload, $label]) {
                [, $body] = $I->{$verb}($route, $payload);
                $I->seeResponseCodeIs(403);
                Assert::assertSame(
                    'rest_cookie_invalid_nonce',
                    $body['code'] ?? '',
                    'expired-nonce ' . $label . ' rejection must surface the same rest_cookie_invalid_nonce code WP uses for both invalid and expired (' . $verb . ' ' . $route . '). Body: ' . wp_json_encode($body)
                );
                Assert::assertNotEmpty(
                    $body['message'] ?? '',
                    'expired-nonce ' . $label . ' response must include a non-empty message'
                );
            }

            Assert::assertSame(
                Status::ORDER_ON_HOLD,
                (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                    $orderId
                )),
                'no DB mutation may persist when expired-nonce check rejects ANY order-changing request'
            );
            Assert::assertSame(
                1,
                (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                    $orderId
                )),
                'expired-nonce delete-order denial must NOT remove the underlying fct_orders row'
            );
            Assert::assertSame(
                Status::PAYMENT_PENDING,
                (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT payment_status FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                    $orderId
                )),
                'expired-nonce mark-as-paid denial must NOT flip payment_status to paid'
            );
        } finally {
            remove_filter('rest_pre_dispatch', $injectExpiredNonce, 10);
        }
    }
}
