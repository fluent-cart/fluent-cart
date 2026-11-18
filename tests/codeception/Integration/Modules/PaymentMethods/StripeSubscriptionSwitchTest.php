<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\SwitchCustomerMethod;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Traits\BuildsSubscriptions;
use Tests\Support\Traits\ConfiguresStripeTestMode;
use Tests\Support\Traits\MocksPayPalHttp;
use Tests\Support\WpDieCapture;

/**
 * A portal switch to Stripe touches the old subscription only after the new
 * Stripe subscription is confirmed live.
 */
class StripeSubscriptionSwitchTest extends DatabaseTestCase
{
    use BuildsSubscriptions;
    use ConfiguresStripeTestMode;
    use MocksPayPalHttp;

    private string $oldVendorId;
    private string $newStripeStatus = 'trialing';

    /** @var array<int, array{0: string, 1: string}> method, url */
    private array $stripeRequests = [];

    private $stripeHttpMock;

    public function setUp(): void
    {
        parent::setUp();
        $this->enableStripeTestMode();
        $this->installPayPalHttpMock();
        $this->oldVendorId = 'I-OLD' . strtoupper(substr(md5(uniqid('', true)), 0, 8));

        $this->stripeHttpMock = function ($preempt, $args, $url) {
            if (strpos($url, 'api.stripe.com') === false) {
                return $preempt;
            }

            $method = strtoupper($args['method'] ?? 'GET');
            $this->stripeRequests[] = [$method, $url];

            if (strpos($url, 'customers/cus_SWITCH/payment_methods') !== false) {
                $body = ['object' => 'list', 'data' => [['id' => 'pm_SWITCH']]];
            } elseif (strpos($url, '/plans/') !== false) {
                $body = ['id' => 'plan_SWITCH'];
            } elseif ($method === 'POST' && preg_match('#/v1/subscriptions$#', strtok($url, '?'))) {
                $body = ['id' => 'sub_NEW', 'status' => $this->newStripeStatus];
            } elseif ($method === 'DELETE' && strpos($url, 'subscriptions/sub_NEW') !== false) {
                $body = ['id' => 'sub_NEW', 'status' => 'canceled'];
            } else {
                $body = [];
            }

            return [
                'headers'  => [],
                'body'     => wp_json_encode($body),
                'response' => ['code' => 200, 'message' => 'OK'],
                'cookies'  => [],
                'filename' => null,
            ];
        };
        add_filter('pre_http_request', $this->stripeHttpMock, 10, 3);
    }

    public function tearDown(): void
    {
        remove_filter('pre_http_request', $this->stripeHttpMock, 10);
        $this->removePayPalHttpMock();
        parent::tearDown();
    }

    private function buildPayPalBackedSubscription(): Subscription
    {
        [, , , $subscription] = $this->buildSubscriptionChain([
            'status'                 => Status::SUBSCRIPTION_ACTIVE,
            'current_payment_method' => 'paypal',
            'vendor_subscription_id' => $this->oldVendorId,
            'vendor_plan_id'         => 'P-ORIGINAL',
            'billing_interval'       => 'monthly',
            'recurring_amount'       => Helper::toCent(100),
            'recurring_total'        => Helper::toCent(100),
            'next_billing_date'      => gmdate('Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS),
        ], [], [
            'payment_method' => 'paypal',
            'status'         => Status::ORDER_COMPLETED,
            'payment_status' => Status::PAYMENT_PAID,
            'mode'           => 'test',
        ]);

        $this->scriptPayPal('billing/subscriptions/' . $this->oldVendorId, 200, [
            'id'     => $this->oldVendorId,
            'status' => 'ACTIVE',
        ]);

        return $subscription;
    }

    private function switchToStripe(Subscription $subscription, string $claimedCurrentMethod = 'paypal'): array
    {
        return WpDieCapture::capture(function () use ($subscription, $claimedCurrentMethod) {
            (new SwitchCustomerMethod())->switchPayMethod([
                'currentPaymentMethod' => $claimedCurrentMethod,
                'customer_id'          => 'cus_SWITCH',
                'vendorPaymentMethod'  => ['id' => 'pm_SWITCH', 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242']],
            ], $subscription->id);
        });
    }

    private function stripeRequestCount(string $method, string $pattern): int
    {
        return count(array_filter($this->stripeRequests, function ($request) use ($method, $pattern) {
            return $request[0] === $method && preg_match($pattern, strtok($request[1], '?'));
        }));
    }

    public function test_incomplete_new_subscription_is_cancelled_and_old_left_alone(): void
    {
        $subscription = $this->buildPayPalBackedSubscription();
        $this->newStripeStatus = 'incomplete';

        $result = $this->switchToStripe($subscription);

        $this->assertGreaterThanOrEqual(400, $result['status'], 'an incomplete new subscription must fail the switch. Response: ' . $result['body']);
        $fresh = Subscription::query()->find($subscription->id);
        $this->assertSame($this->oldVendorId, $fresh->vendor_subscription_id);
        $this->assertSame('paypal', $fresh->current_payment_method);
        $this->assertSame(Status::SUBSCRIPTION_ACTIVE, $fresh->status);
        $this->assertSame(0, $this->payPalRequestCount('/cancel'), 'the old agreement must stay live');
        $this->assertSame(1, $this->stripeRequestCount('DELETE', '#subscriptions/sub_NEW$#'), 'the unusable new Stripe subscription must be cancelled');
    }

    public function test_old_subscription_is_cancelled_on_the_gateway_the_row_is_on(): void
    {
        $subscription = $this->buildPayPalBackedSubscription();

        $result = $this->switchToStripe($subscription, 'stripe');

        $this->assertSame(200, $result['status'], $result['body']);
        $fresh = Subscription::query()->find($subscription->id);
        $this->assertSame('sub_NEW', $fresh->vendor_subscription_id);
        $this->assertSame('stripe', $fresh->current_payment_method);
        $this->assertSame(1, $this->payPalRequestCount('billing/subscriptions/' . $this->oldVendorId . '/cancel'), 'the old PayPal agreement must be cancelled even when the request names another gateway');

        $old = $fresh->getMeta('old_subscriptions');
        $old = is_string($old) ? json_decode($old, true) : (array)$old;
        $this->assertSame('paypal', $old[0]['payment_method'] ?? null);
        $this->assertSame($this->oldVendorId, $old[0]['vendor_subscription_id'] ?? null);
    }

    public function test_failed_old_cancel_keeps_the_switch_and_logs_the_old_subscription_link(): void
    {
        $subscription = $this->buildPayPalBackedSubscription();
        $this->scriptPayPal('billing/subscriptions/' . $this->oldVendorId, 500, ['name' => 'INTERNAL_SERVER_ERROR']);

        $result = $this->switchToStripe($subscription);

        $this->assertSame(200, $result['status'], $result['body']);
        $this->assertSame('sub_NEW', Subscription::query()->find($subscription->id)->vendor_subscription_id);

        $log = Activity::query()
            ->where('module_name', 'subscription')
            ->where('module_id', $subscription->id)
            ->where('status', 'error')
            ->first();
        $this->assertNotNull($log, 'a failed old cancel must leave an error log on the subscription');
        $this->assertStringContainsString('https://www.sandbox.paypal.com/billing/subscriptions/' . $this->oldVendorId, $log->content);
    }

    public function test_switch_in_progress_is_refused_before_any_stripe_write(): void
    {
        $subscription = $this->buildPayPalBackedSubscription();

        $rival = new \wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $rival->get_var($rival->prepare('SELECT GET_LOCK(%s, %d)', 'fc_sub_switch_' . $subscription->id, 0));

        try {
            $result = $this->switchToStripe($subscription);
        } finally {
            $rival->query($rival->prepare('SELECT RELEASE_LOCK(%s)', 'fc_sub_switch_' . $subscription->id));
        }

        $this->assertSame(409, $result['status'], $result['body']);
        $this->assertSame(0, $this->stripeRequestCount('POST', '#/v1/subscriptions$#'), 'no new Stripe subscription while another switch runs');
        $this->assertSame($this->oldVendorId, Subscription::query()->find($subscription->id)->vendor_subscription_id);
    }
}
