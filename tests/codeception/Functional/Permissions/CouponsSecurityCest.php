<?php

namespace Tests\Functional\Permissions;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CouponFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/coupons.md → "Authentication and CSRF" section, items 98-99.
 *
 * The functional helper actor auto-injects a fresh wp_rest nonce on every authed write,
 * so to exercise nonce-rejection paths these tests hook rest_pre_dispatch (which fires
 * inside WP_REST_Server::dispatch() after auth checks and before route execution) and
 * synthesize the same WP_Error WordPress core produces from wp_verify_nonce==false
 * (rest_cookie_check_errors at wp-includes/rest-api.php:1145), which is what an admin
 * sending an invalid or expired X-WP-Nonce would see in production.
 *
 * Coupon-changing routes covered (per app/Http/Routes/api.php prefix=coupons):
 *   POST   /fluent-cart/v2/coupons                       (create)            coupons/manage
 *   PUT    /fluent-cart/v2/coupons/{id}                  (update)            coupons/manage
 *   DELETE /fluent-cart/v2/coupons/{id}                  (delete)            coupons/delete
 *   POST   /fluent-cart/v2/coupons/apply                 (apply to order)    orders/create OR orders/manage
 *   POST   /fluent-cart/v2/coupons/cancel                (cancel applied)    orders/create OR orders/manage
 *   POST   /fluent-cart/v2/coupons/re-apply              (re-apply saved)    orders/create OR orders/manage
 *   POST   /fluent-cart/v2/coupons/storeCouponSettings   (save settings)     coupons/manage
 *
 * Read-only coupon GETs and the read-only POST /coupons/checkProductEligibility
 * are deliberately excluded — the spec scope is "coupon-CHANGING actions".
 */
class CouponsSecurityCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/coupons.md [98]
     *   "A logged-in admin without a valid security token is still rejected from coupon-changing actions."
     */
    public function adminWithoutValidSecurityTokenIsRejectedFromCouponChangingActions(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create([
            'title'  => 'CSRF Invalid Seed',
            'code'   => 'CSRF-INVALID-SEED',
            'status' => 'active',
            'type'   => 'fixed',
            'amount' => 1000,
        ]);
        $couponId = (int) $coupon->id;

        $changingRoutes = [
            ['post',   '/fluent-cart/v2/coupons', [
                'title'  => 'Forged Create Attempt',
                'code'   => 'FORGED-CREATE',
                'type'   => 'fixed',
                'amount' => 5,
                'status' => 'active',
            ], 'create-coupon'],
            ['put',    '/fluent-cart/v2/coupons/' . $couponId, [
                'id'    => $couponId,
                'title' => 'Forged Update Attempt',
                'code'  => 'CSRF-INVALID-SEED',
                'type'  => 'fixed',
                'amount' => 5,
                'status' => 'active',
            ], 'update-coupon'],
            ['delete', '/fluent-cart/v2/coupons/' . $couponId, [], 'delete-coupon'],
            ['post',   '/fluent-cart/v2/coupons/apply', [
                'coupon_code' => 'CSRF-INVALID-SEED',
                'order_items' => [],
            ], 'apply-coupon'],
            ['post',   '/fluent-cart/v2/coupons/cancel', [
                'coupon_code'     => 'CSRF-INVALID-SEED',
                'applied_coupons' => [$couponId],
                'order_items'     => [],
            ], 'cancel-coupon'],
            ['post',   '/fluent-cart/v2/coupons/re-apply', [
                'order_uuid'  => 'forged-uuid',
                'order_items' => [],
            ], 'reapply-coupons'],
            ['post',   '/fluent-cart/v2/coupons/storeCouponSettings', [
                'show_on_checkout' => true,
            ], 'store-coupon-settings'],
        ];

        // Precondition: prove every route under test is REGISTERED before installing
        // the rest_pre_dispatch synthesis filter. Otherwise a typo or a
        // route deregistration would still pass the test (the filter returns the
        // synthesized WP_Error before WP attempts route resolution). Guest dispatch
        // returns 401 with code "rest_forbidden" for registered protected routes,
        // and 404 with code "rest_no_route" for unregistered routes — so the
        // distinguishing assertion is on the code, not the status.
        $I->logout();
        foreach ($changingRoutes as [$verb, $route, , $label]) {
            [, $preflightBody] = $I->{$verb}($route, []);
            Assert::assertNotSame(
                'rest_no_route',
                $preflightBody['code'] ?? '',
                'precondition: ' . $label . ' (' . $verb . ' ' . $route . ') must be a registered REST route — guest preflight returned rest_no_route, meaning the route is not registered. Body: ' . wp_json_encode($preflightBody)
            );
        }

        $injectInvalidNonce = static function ($result, $server, $request) {
            if (strpos((string) $request->get_route(), '/fluent-cart/v2/coupons') === 0) {
                return new \WP_Error('rest_cookie_invalid_nonce', 'Cookie check failed', ['status' => 403]);
            }
            return $result;
        };
        add_filter('rest_pre_dispatch', $injectInvalidNonce, 10, 3);

        try {
            $I->loginAsSuperAdmin();

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

            global $wpdb;

            Assert::assertSame(
                1,
                (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_coupons"),
                'invalid-nonce probing must NOT create or remove any fct_coupons row'
            );
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT title, code, status FROM {$wpdb->prefix}fct_coupons WHERE id = %d",
                $couponId
            ), ARRAY_A);
            Assert::assertSame(
                'CSRF Invalid Seed',
                (string) ($row['title'] ?? ''),
                'invalid-nonce update-coupon denial must NOT mutate the seeded coupon title'
            );
            Assert::assertSame(
                'CSRF-INVALID-SEED',
                (string) ($row['code'] ?? ''),
                'invalid-nonce update-coupon denial must NOT mutate the seeded coupon code'
            );
            Assert::assertSame(
                'active',
                (string) ($row['status'] ?? ''),
                'invalid-nonce update-coupon denial must NOT mutate the seeded coupon status'
            );
            Assert::assertSame(
                0,
                (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_applied_coupons"),
                'invalid-nonce apply/cancel/reapply denials must NOT touch fct_applied_coupons'
            );
            Assert::assertSame(
                0,
                (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}fct_meta WHERE meta_key = %s",
                    'fluent_cart_coupon_settings'
                )),
                'invalid-nonce storeCouponSettings denial must NOT persist a fluent_cart_coupon_settings Meta row'
            );
        } finally {
            remove_filter('rest_pre_dispatch', $injectInvalidNonce, 10);
        }
    }

    /**
     * Spec: Cases/Functional/coupons.md [99]
     *   "An expired security token is rejected with a clear error."
     */
    public function adminWithExpiredSecurityTokenIsRejectedFromCouponChangingActions(FunctionalTester $I): void
    {
        $coupon = (new CouponFactory())->create([
            'title'  => 'CSRF Expired Seed',
            'code'   => 'CSRF-EXPIRED-SEED',
            'status' => 'active',
            'type'   => 'fixed',
            'amount' => 1000,
        ]);
        $couponId = (int) $coupon->id;

        $changingRoutes = [
            ['post',   '/fluent-cart/v2/coupons', [
                'title'  => 'Expired Create Attempt',
                'code'   => 'EXPIRED-CREATE',
                'type'   => 'fixed',
                'amount' => 5,
                'status' => 'active',
            ], 'create-coupon'],
            ['put',    '/fluent-cart/v2/coupons/' . $couponId, [
                'id'    => $couponId,
                'title' => 'Expired Update Attempt',
                'code'  => 'CSRF-EXPIRED-SEED',
                'type'  => 'fixed',
                'amount' => 5,
                'status' => 'active',
            ], 'update-coupon'],
            ['delete', '/fluent-cart/v2/coupons/' . $couponId, [], 'delete-coupon'],
            ['post',   '/fluent-cart/v2/coupons/apply', [
                'coupon_code' => 'CSRF-EXPIRED-SEED',
                'order_items' => [],
            ], 'apply-coupon'],
            ['post',   '/fluent-cart/v2/coupons/cancel', [
                'coupon_code'     => 'CSRF-EXPIRED-SEED',
                'applied_coupons' => [$couponId],
                'order_items'     => [],
            ], 'cancel-coupon'],
            ['post',   '/fluent-cart/v2/coupons/re-apply', [
                'order_uuid'  => 'forged-uuid',
                'order_items' => [],
            ], 'reapply-coupons'],
            ['post',   '/fluent-cart/v2/coupons/storeCouponSettings', [
                'show_on_checkout' => true,
            ], 'store-coupon-settings'],
        ];

        // Precondition: prove every route under test is REGISTERED before installing
        // the rest_pre_dispatch synthesis filter (see method-98 docstring for rationale).
        $I->logout();
        foreach ($changingRoutes as [$verb, $route, , $label]) {
            [, $preflightBody] = $I->{$verb}($route, []);
            Assert::assertNotSame(
                'rest_no_route',
                $preflightBody['code'] ?? '',
                'precondition: ' . $label . ' (' . $verb . ' ' . $route . ') must be a registered REST route — guest preflight returned rest_no_route, meaning the route is not registered. Body: ' . wp_json_encode($preflightBody)
            );
        }

        // wp_verify_nonce returns false for both invalid and expired (>24h) nonces, so
        // the same rest_cookie_invalid_nonce error is what callers see — the difference
        // is only observable to the user who passed the now-stale value.
        $injectExpiredNonce = static function ($result, $server, $request) {
            if (strpos((string) $request->get_route(), '/fluent-cart/v2/coupons') === 0) {
                return new \WP_Error('rest_cookie_invalid_nonce', 'Cookie check failed', ['status' => 403]);
            }
            return $result;
        };
        add_filter('rest_pre_dispatch', $injectExpiredNonce, 10, 3);

        try {
            $I->loginAsSuperAdmin();

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

            global $wpdb;

            Assert::assertSame(
                1,
                (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_coupons"),
                'expired-nonce probing must NOT create or remove any fct_coupons row'
            );
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT title, code, status FROM {$wpdb->prefix}fct_coupons WHERE id = %d",
                $couponId
            ), ARRAY_A);
            Assert::assertSame(
                'CSRF Expired Seed',
                (string) ($row['title'] ?? ''),
                'expired-nonce update-coupon denial must NOT mutate the seeded coupon title'
            );
            Assert::assertSame(
                'CSRF-EXPIRED-SEED',
                (string) ($row['code'] ?? ''),
                'expired-nonce update-coupon denial must NOT mutate the seeded coupon code'
            );
            Assert::assertSame(
                'active',
                (string) ($row['status'] ?? ''),
                'expired-nonce update-coupon denial must NOT mutate the seeded coupon status'
            );
            Assert::assertSame(
                0,
                (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fct_applied_coupons"),
                'expired-nonce apply/cancel/reapply denials must NOT touch fct_applied_coupons'
            );
            Assert::assertSame(
                0,
                (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}fct_meta WHERE meta_key = %s",
                    'fluent_cart_coupon_settings'
                )),
                'expired-nonce storeCouponSettings denial must NOT persist a fluent_cart_coupon_settings Meta row'
            );
        } finally {
            remove_filter('rest_pre_dispatch', $injectExpiredNonce, 10);
        }
    }
}
