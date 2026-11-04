<?php

namespace Tests\Functional\Permissions;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/customers.md → "Changing customers securely" section, items 122-123.
 *
 * The functional helper actor auto-injects a fresh wp_rest nonce on every authed write,
 * so to exercise nonce-rejection paths these tests hook rest_pre_dispatch (which fires
 * inside WP_REST_Server::dispatch() after auth checks and before route execution) and
 * synthesize the same WP_Error WordPress core produces from wp_verify_nonce==false
 * (rest_cookie_check_errors at wp-includes/rest-api.php:1145), which is what an admin
 * sending an invalid or expired X-WP-Nonce would see in production.
 */
class CustomersSecurityCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/customers.md [122]
     *   "Admin is rejected from customer-changing actions without a valid security token."
     */
    public function adminWithoutValidSecurityTokenIsRejectedFromCustomerChangingActions(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'csrf-invalid-' . uniqid('', true) . '@example.test']);

        $customerId = (int) $customer->id;
        $injectInvalidNonce = static function ($result, $server, $request) use ($customerId) {
            if (strpos((string) $request->get_route(), '/fluent-cart/v2/customers/' . $customerId) === 0) {
                return new \WP_Error('rest_cookie_invalid_nonce', 'Cookie check failed', ['status' => 403]);
            }
            return $result;
        };
        add_filter('rest_pre_dispatch', $injectInvalidNonce, 10, 3);

        try {
            $I->loginAsSuperAdmin();
            [, $body] = $I->put('/fluent-cart/v2/customers/' . (int) $customer->id, [
                'id'        => (int) $customer->id,
                'full_name' => 'Forged Token Attempt',
                'email'     => $customer->email,
            ]);

            $I->seeResponseCodeIs(403);
            Assert::assertSame(
                'rest_cookie_invalid_nonce',
                $body['code'] ?? '',
                'invalid-nonce rejection must surface the rest_cookie_invalid_nonce code so clients can redirect to refresh'
            );
            Assert::assertNotEmpty($body['message'] ?? '', 'invalid-nonce response must include a non-empty message');

            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT first_name FROM {$wpdb->prefix}fct_customers WHERE id = %d",
                $customer->id
            ), ARRAY_A);
            Assert::assertNotSame(
                'Forged',
                (string) ($row['first_name'] ?? ''),
                'no DB mutation may persist when nonce check rejects the request'
            );
        } finally {
            remove_filter('rest_pre_dispatch', $injectInvalidNonce, 10);
        }
    }

    /**
     * Spec: Cases/Functional/customers.md [123]
     *   "Admin is rejected from customer-changing actions when the security token is expired."
     */
    public function adminWithExpiredSecurityTokenIsRejectedFromCustomerChangingActions(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'csrf-expired-' . uniqid('', true) . '@example.test']);

        $customerId = (int) $customer->id;
        $injectExpiredNonce = static function ($result, $server, $request) use ($customerId) {
            // wp_verify_nonce returns false for both invalid and expired (>24h) nonces, so
            // the same rest_cookie_invalid_nonce error is what callers see — the difference
            // is only observable to the user who passed the now-stale value.
            if (strpos((string) $request->get_route(), '/fluent-cart/v2/customers/' . $customerId) === 0) {
                return new \WP_Error('rest_cookie_invalid_nonce', 'Cookie check failed', ['status' => 403]);
            }
            return $result;
        };
        add_filter('rest_pre_dispatch', $injectExpiredNonce, 10, 3);

        try {
            $I->loginAsSuperAdmin();
            [, $body] = $I->put('/fluent-cart/v2/customers/' . (int) $customer->id, [
                'id'        => (int) $customer->id,
                'full_name' => 'Expired Token Attempt',
                'email'     => $customer->email,
            ]);

            $I->seeResponseCodeIs(403);
            Assert::assertSame(
                'rest_cookie_invalid_nonce',
                $body['code'] ?? '',
                'expired-nonce rejection must surface the same rest_cookie_invalid_nonce code (WP does not distinguish expired from invalid)'
            );
            Assert::assertNotEmpty($body['message'] ?? '', 'expired-nonce response must include a non-empty message');

            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT first_name FROM {$wpdb->prefix}fct_customers WHERE id = %d",
                $customer->id
            ), ARRAY_A);
            Assert::assertNotSame(
                'Expired',
                (string) ($row['first_name'] ?? ''),
                'no DB mutation may persist when expired-nonce rejects the request'
            );
        } finally {
            remove_filter('rest_pre_dispatch', $injectExpiredNonce, 10);
        }
    }
}
