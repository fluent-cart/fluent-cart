<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Modules\PaymentMethods\StripeGateway\SubscriptionsManager;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Traits\ConfiguresStripeTestMode;
use Tests\Support\WpDieCapture;

/**
 * Spec: Cases/Functional/subscriptions.md [59]
 *   "… Authoritative contract: 5 attempts per customer per day by default
 *    (`fluent_cart/stripe/setup_intent_rate_limit_customer_daily` filter), one
 *    shared source for the displayed count AND enforcement."
 *
 * Drives the PRODUCTION verification path (SubscriptionsManager::
 * verifyPaymentMethod) with mocked Stripe HTTP: exactly 5 attempts are
 * allowed and the 6th is rejected with 429 BEFORE any gateway call — the
 * card-testing control the displayed remaining count promises.
 */
class StripeSetupIntentRateLimitTest extends WPTestCase
{
    use ConfiguresStripeTestMode;

    public function setUp(): void
    {
        parent::setUp();
        $this->enableStripeTestMode();
    }

    public function test_verify_payment_method_allows_five_attempts_then_rejects_with_429(): void
    {
        $customerId = 'cus_rate_limit_contract';
        delete_transient('fct_stripe_setup_intent_rate_daily_' . md5($customerId));

        $stripeCalls = 0;
        $httpMock = function ($preempt, $args, $url) use (&$stripeCalls) {
            if (strpos($url, 'api.stripe.com') === false) {
                return $preempt;
            }
            $stripeCalls++;
            return [
                'headers'  => [],
                'body'     => wp_json_encode(['id' => 'seti_test_' . $stripeCalls, 'status' => 'succeeded']),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
                'filename' => null,
            ];
        };

        add_filter('pre_http_request', $httpMock, 10, 3);

        try {
            $manager = new SubscriptionsManager();

            for ($attempt = 1; $attempt <= 5; $attempt++) {
                $this->assertSame(
                    5 - ($attempt - 1),
                    $manager->getRemainingRateLimit($customerId),
                    "before attempt {$attempt} the displayed remaining count must mirror the enforcement transient"
                );
                $this->assertTrue(
                    SubscriptionsManager::verifyPaymentMethod('pm_test_card', $customerId),
                    "attempt {$attempt} of 5 must pass the rate gate and verify via the (mocked) SetupIntent"
                );
            }

            $this->assertSame(
                0,
                $manager->getRemainingRateLimit($customerId),
                'after five attempts the displayed remaining count must be zero'
            );

            $stripeCallsBeforeRejection = $stripeCalls;
            $result = WpDieCapture::capture(function () {
                SubscriptionsManager::verifyPaymentMethod('pm_test_card', 'cus_rate_limit_contract');
            });

            $this->assertTrue($result['died'], 'the 6th attempt must terminate through wp_send_json');
            $this->assertSame(429, $result['status'], 'the 6th attempt must be rejected with HTTP 429');
            $this->assertSame('failed', $result['json']['status'] ?? null, 'rejection envelope must carry status failed');
            $this->assertStringContainsString(
                'Too many verification attempts',
                (string) ($result['json']['message'] ?? ''),
                'rejection must carry the rate-limit message'
            );
            $this->assertSame(
                $stripeCallsBeforeRejection,
                $stripeCalls,
                'the rate-limited attempt must be rejected BEFORE any Stripe API call — that is the card-testing control'
            );
        } finally {
            remove_filter('pre_http_request', $httpMock, 10);
            delete_transient('fct_stripe_setup_intent_rate_daily_' . md5($customerId));
        }
    }

    public function test_rate_limit_filter_governs_both_enforcement_and_display(): void
    {
        $customerId = 'cus_rate_limit_filtered';
        delete_transient('fct_stripe_setup_intent_rate_daily_' . md5($customerId));

        $capOverride = function () {
            return 1;
        };
        add_filter('fluent_cart/stripe/setup_intent_rate_limit_customer_daily', $capOverride);

        $httpMock = function ($preempt, $args, $url) {
            if (strpos($url, 'api.stripe.com') === false) {
                return $preempt;
            }
            return [
                'headers'  => [],
                'body'     => wp_json_encode(['id' => 'seti_test_f', 'status' => 'succeeded']),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
                'filename' => null,
            ];
        };
        add_filter('pre_http_request', $httpMock, 10, 3);

        try {
            $manager = new SubscriptionsManager();

            $this->assertSame(1, $manager->getRemainingRateLimit($customerId), 'display must honor the filtered cap');
            $this->assertTrue(
                SubscriptionsManager::verifyPaymentMethod('pm_test_card', $customerId),
                'the single filtered attempt must pass'
            );

            $result = WpDieCapture::capture(function () {
                SubscriptionsManager::verifyPaymentMethod('pm_test_card', 'cus_rate_limit_filtered');
            });
            $this->assertSame(
                429,
                $result['status'],
                'enforcement must honor the SAME filtered cap as the display — the two share one contract'
            );
        } finally {
            remove_filter('fluent_cart/stripe/setup_intent_rate_limit_customer_daily', $capOverride);
            remove_filter('pre_http_request', $httpMock, 10);
            delete_transient('fct_stripe_setup_intent_rate_daily_' . md5($customerId));
        }
    }

    public function test_attempt_is_rejected_without_stripe_call_while_another_request_holds_the_counter(): void
    {
        $customerId = 'cus_rate_limit_locked';
        delete_transient('fct_stripe_setup_intent_rate_daily_' . md5($customerId));

        $stripeCalls = 0;
        $httpMock = $this->countingStripeMock($stripeCalls);
        add_filter('pre_http_request', $httpMock, 10, 3);

        $other = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $lockName = 'fct_seti_' . md5($customerId);
        $this->assertSame('1', (string) $other->get_var($other->prepare('SELECT GET_LOCK(%s, 0)', $lockName)));

        try {
            $result = WpDieCapture::capture(function () use ($customerId) {
                SubscriptionsManager::verifyPaymentMethod('pm_test_card', $customerId);
            });

            $this->assertSame(429, $result['status'], 'an attempt that cannot take the counter lock must be refused');
            $this->assertSame(0, $stripeCalls, 'no SetupIntent may be created while the counter is locked');
        } finally {
            $other->get_var($other->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
            $other->close();
            remove_filter('pre_http_request', $httpMock, 10);
            delete_transient('fct_stripe_setup_intent_rate_daily_' . md5($customerId));
        }
    }

    public function test_attempt_is_rejected_without_stripe_call_when_the_count_cannot_be_saved(): void
    {
        $customerId = 'cus_rate_limit_unsaved';
        $key = 'fct_stripe_setup_intent_rate_daily_' . md5($customerId);
        set_transient($key, 1, DAY_IN_SECONDS);

        $stripeCalls = 0;
        $httpMock = $this->countingStripeMock($stripeCalls);
        add_filter('pre_http_request', $httpMock, 10, 3);

        $keepOld = function ($value, $oldValue) {
            return $oldValue;
        };
        add_filter('pre_update_option__transient_' . $key, $keepOld, 10, 2);

        try {
            $result = WpDieCapture::capture(function () use ($customerId) {
                SubscriptionsManager::verifyPaymentMethod('pm_test_card', $customerId);
            });

            $this->assertSame(429, $result['status'], 'an attempt that was not counted must be refused');
            $this->assertSame(0, $stripeCalls, 'no SetupIntent may be created when the count did not persist');
        } finally {
            remove_filter('pre_update_option__transient_' . $key, $keepOld, 10);
            remove_filter('pre_http_request', $httpMock, 10);
            delete_transient($key);
        }
    }

    private function countingStripeMock(&$stripeCalls): callable
    {
        return function ($preempt, $args, $url) use (&$stripeCalls) {
            if (strpos($url, 'api.stripe.com') === false) {
                return $preempt;
            }
            $stripeCalls++;
            return [
                'headers'  => [],
                'body'     => wp_json_encode(['id' => 'seti_test_' . $stripeCalls, 'status' => 'succeeded']),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
                'filename' => null,
            ];
        };
    }
}
