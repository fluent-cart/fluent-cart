<?php

namespace Tests\Functional\Permissions;

use FluentCart\App\Helpers\Status;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsSubscriptions;

/**
 * Spec: Cases/Functional/subscriptions.md → "Authentication and CSRF" section, items 95-98.
 *
 * The functional helper actor injects a fresh wp_rest nonce on every authed write, so
 * to exercise invalid/expired-nonce paths these tests hook rest_pre_dispatch (which
 * fires inside WP_REST_Server::dispatch after auth and before route execution) and
 * synthesize the same WP_Error WordPress core would emit from wp_verify_nonce==false
 * (rest_cookie_check_errors at wp-includes/rest-api.php). Same pattern as
 * OrdersSecurityCest::adminWithoutValidSecurityTokenIsRejectedFromOrderChangingAction.
 *
 * The guest tests (items 97 + 98) rely on the OrderPolicy::hasRoutePermissions check
 * (app/Http/Policies/OrderPolicy.php) which returns 401/rest_forbidden when no logged-in
 * user holds the subscriptions/manage permission.
 */
class SubscriptionsSecurityCest
{
    use BuildsSubscriptions;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [95]
     *   "A logged-in admin without a valid security token is rejected from cancelling a subscription."
     */
    public function loggedInAdminWithoutValidSecurityTokenIsRejectedFromCancellingSubscription(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'CSRF Cancel 95',
            'status'    => Status::SUBSCRIPTION_ACTIVE,
        ], ['email' => 'csrf-sub-95@example.test']);

        $injectInvalidNonce = static function ($result, $server, $request) use ($order, $subscription) {
            $expectedRoute = '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/cancel';
            if ((string) $request->get_route() === $expectedRoute) {
                return new \WP_Error('rest_cookie_invalid_nonce', 'Cookie check failed', ['status' => 403]);
            }
            return $result;
        };
        add_filter('rest_pre_dispatch', $injectInvalidNonce, 10, 3);

        try {
            $I->loginAsSuperAdmin();
            [$status, $body] = $I->put(
                '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/cancel',
                ['cancel_reason' => 'CSRF probe']
            );

            Assert::assertSame(403, $status, 'invalid-nonce cancel must surface 403. Body: ' . wp_json_encode($body));
            Assert::assertSame(
                'rest_cookie_invalid_nonce',
                (string) ($body['code'] ?? ''),
                'invalid-nonce cancel response must carry the rest_cookie_invalid_nonce code that WordPress core emits on wp_verify_nonce failure'
            );
            Assert::assertNotEmpty(
                $body['message'] ?? '',
                'invalid-nonce cancel response must include a non-empty user-facing message'
            );

            global $wpdb;
            Assert::assertSame(
                Status::SUBSCRIPTION_ACTIVE,
                (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                    (int) $subscription->id
                )),
                'invalid-nonce cancel denial must NOT mutate fct_subscriptions.status — the cancel controller must never run when the nonce check rejects the request before dispatch'
            );
            Assert::assertEmpty(
                $wpdb->get_var($wpdb->prepare(
                    "SELECT canceled_at FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                    (int) $subscription->id
                )),
                'invalid-nonce cancel denial must NOT stamp fct_subscriptions.canceled_at'
            );
        } finally {
            remove_filter('rest_pre_dispatch', $injectInvalidNonce, 10);
        }
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [96]
     *   "An expired security token is rejected with a clear error when generating an early-payment link."
     */
    public function expiredSecurityTokenIsRejectedWhenGeneratingEarlyPaymentLink(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name'  => 'CSRF EarlyPay 96',
            'status'     => Status::SUBSCRIPTION_ACTIVE,
            'bill_times' => 12,
            'bill_count' => 3,
        ], ['email' => 'csrf-sub-96@example.test']);

        // wp_verify_nonce returns false for both invalid and expired (>24h) nonces, so
        // WordPress surfaces the same rest_cookie_invalid_nonce code regardless of which
        // failure happened — the only difference is observable to the user who held the
        // now-stale token. Injecting the WP_Error reproduces that authentic shape.
        $injectExpiredNonce = static function ($result, $server, $request) use ($order, $subscription) {
            $expectedRoute = '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/early-payment-link';
            if ((string) $request->get_route() === $expectedRoute) {
                return new \WP_Error('rest_cookie_invalid_nonce', 'Cookie check failed', ['status' => 403]);
            }
            return $result;
        };
        add_filter('rest_pre_dispatch', $injectExpiredNonce, 10, 3);

        try {
            $I->loginAsSuperAdmin();
            [$status, $body] = $I->post(
                '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/early-payment-link',
                []
            );

            Assert::assertSame(403, $status, 'expired-nonce early-payment-link must surface 403. Body: ' . wp_json_encode($body));
            Assert::assertSame(
                'rest_cookie_invalid_nonce',
                (string) ($body['code'] ?? ''),
                'expired-nonce early-payment-link response must carry the same rest_cookie_invalid_nonce code WP uses for both invalid and expired (they share the wp_verify_nonce==false path)'
            );
            Assert::assertNotEmpty(
                $body['message'] ?? '',
                'expired-nonce early-payment-link response must include a non-empty user-facing message'
            );
            Assert::assertArrayNotHasKey(
                'payment_url',
                (array) $body,
                'expired-nonce rejection must NOT leak a payment_url'
            );
        } finally {
            remove_filter('rest_pre_dispatch', $injectExpiredNonce, 10);
        }
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [97]
     *   "A logged-out visitor cannot cancel a subscription through the admin route."
     */
    public function loggedOutVisitorCannotCancelSubscriptionThroughAdminRoute(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Guest Cancel 97',
            'status'    => Status::SUBSCRIPTION_ACTIVE,
        ], ['email' => 'csrf-sub-97@example.test']);

        $I->logout();
        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/cancel',
            ['cancel_reason' => 'guest attempt']
        );

        Assert::assertSame(401, $status, 'guest cancel must return 401 unauthorized. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            'rest_forbidden',
            (string) ($body['code'] ?? ''),
            'guest cancel denial must surface the rest_forbidden code per OrderPolicy::hasRoutePermissions'
        );
        Assert::assertNotEmpty(
            $body['message'] ?? '',
            'guest cancel denial must include a permission-denied message'
        );

        global $wpdb;
        Assert::assertSame(
            Status::SUBSCRIPTION_ACTIVE,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                (int) $subscription->id
            )),
            'guest cancel denial must NOT mutate fct_subscriptions.status'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [98]
     *   "A logged-out visitor cannot generate an early-payment link through the admin route."
     */
    public function loggedOutVisitorCannotGenerateEarlyPaymentLinkThroughAdminRoute(FunctionalTester $I): void
    {
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name'  => 'Guest EarlyPay 98',
            'status'     => Status::SUBSCRIPTION_ACTIVE,
            'bill_times' => 12,
            'bill_count' => 3,
        ], ['email' => 'csrf-sub-98@example.test']);

        $I->logout();
        [$status, $body] = $I->post(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/early-payment-link',
            []
        );

        Assert::assertSame(401, $status, 'guest early-payment-link must return 401 unauthorized. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            'rest_forbidden',
            (string) ($body['code'] ?? ''),
            'guest early-payment-link denial must surface the rest_forbidden code per OrderPolicy::hasRoutePermissions'
        );
        Assert::assertNotEmpty(
            $body['message'] ?? '',
            'guest early-payment-link denial must include a permission-denied message'
        );
        Assert::assertArrayNotHasKey(
            'payment_url',
            (array) $body,
            'guest early-payment-link denial must NOT leak a payment_url'
        );
    }
}
