<?php

namespace Tests\Integration\Modules\PaymentMethods;

use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\SubscriptionMeta;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\StripeSubscriptions;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;
use Tests\Support\Traits\ConfiguresStripeTestMode;

/**
 * A card change made outside our card-update flow (Stripe-hosted portal,
 * dunning card replacement) fires customer.subscription.updated, which lands in
 * reSyncSubscriptionFromRemote via reSyncFromRemote. The resync must carry the
 * remote subscription's default payment method into the active_payment_method
 * meta — otherwise the portal and admin keep rendering the old card forever.
 */
class StripeResyncActivePaymentMethodTest extends WPTestCase
{
    use ConfiguresStripeTestMode;

    private $httpMock;

    public function setUp(): void
    {
        parent::setUp();
        $this->enableStripeTestMode();
    }

    public function tearDown(): void
    {
        if ($this->httpMock) {
            remove_filter('pre_http_request', $this->httpMock, 10);
            $this->httpMock = null;
        }
        parent::tearDown();
    }

    private function makeStripeSubscription(): Subscription
    {
        $customer = (new CustomerFactory())->create();
        $product  = (new ProductFactory())->withVariantAttributes(['payment_type' => 'subscription'])->create();
        $variant  = $product->variants->first();
        $order    = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'type'        => 'subscription',
            'mode'        => 'test',
        ]);

        return (new SubscriptionFactory())->create([
            'customer_id'            => $customer->id,
            'parent_order_id'        => $order->id,
            'product_id'             => $product->ID,
            'variation_id'           => $variant->id,
            'current_payment_method' => 'stripe',
            'vendor_subscription_id' => 'sub_resync_pm_test',
            'status'                 => 'active',
        ]);
    }

    /**
     * @param array|null $defaultPaymentMethod expanded PM object, or null for
     *                                         a subscription with no sub-level default
     */
    private function mockStripe($defaultPaymentMethod): void
    {
        $subscriptionBody = [
            'id'                 => 'sub_resync_pm_test',
            'status'             => 'active',
            'current_period_end' => time() + 30 * DAY_IN_SECONDS,
            'plan'               => ['amount' => 2999, 'currency' => 'usd'],
            'latest_invoice'     => ['id' => 'in_resync_1', 'status' => 'paid'],
        ];
        if ($defaultPaymentMethod !== null) {
            $subscriptionBody['default_payment_method'] = $defaultPaymentMethod;
        }

        $this->httpMock = function ($preempt, $args, $url) use ($subscriptionBody) {
            if (strpos($url, 'api.stripe.com') === false) {
                return $preempt;
            }

            if (strpos($url, 'subscriptions/sub_resync_pm_test') !== false) {
                $body = $subscriptionBody;
            } elseif (strpos($url, 'invoices') !== false) {
                $body = ['object' => 'list', 'data' => []];
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

        add_filter('pre_http_request', $this->httpMock, 10, 3);
    }

    private function expandedCardPm(string $id, string $last4): array
    {
        return [
            'id'              => $id,
            'object'          => 'payment_method',
            'type'            => 'card',
            'card'            => [
                'brand'       => 'visa',
                'last4'       => $last4,
                'exp_month'   => 4,
                'exp_year'    => 2030,
                'fingerprint' => 'fp_' . $id,
            ],
            'billing_details' => ['name' => 'Resync Tester'],
        ];
    }

    public function test_resync_writes_remote_default_payment_method_to_meta(): void
    {
        $sub = $this->makeStripeSubscription();
        $this->mockStripe($this->expandedCardPm('pm_new_from_remote', '4242'));

        $result = (new StripeSubscriptions())->reSyncSubscriptionFromRemote($sub);
        $this->assertNotInstanceOf(\WP_Error::class, $result);

        $meta = Subscription::find($sub->id)->getMeta('active_payment_method', []);
        $this->assertSame('stripe', $meta['method'] ?? null);
        $this->assertSame('pm_new_from_remote', $meta['details']['payment_method_id'] ?? null);
        $this->assertSame('4242', $meta['details']['last_4'] ?? null);
        $this->assertSame('visa', $meta['details']['brand'] ?? null);
    }

    public function test_resync_skips_write_when_stored_method_id_matches_remote(): void
    {
        $sub = $this->makeStripeSubscription();
        $stored = [
            'method'  => 'stripe',
            'type'    => 'card',
            'details' => [
                'payment_method_id' => 'pm_already_stored',
                'brand'             => 'mastercard',
                'last_4'            => '9999',
            ],
        ];
        $sub->updateMeta('active_payment_method', $stored);

        // Remote reports the SAME payment method id — the richer stored record
        // (e.g. from the card-update flow) must not be overwritten.
        $this->mockStripe($this->expandedCardPm('pm_already_stored', '1111'));

        $result = (new StripeSubscriptions())->reSyncSubscriptionFromRemote($sub);
        $this->assertNotInstanceOf(\WP_Error::class, $result);

        $meta = Subscription::find($sub->id)->getMeta('active_payment_method', []);
        $this->assertSame('9999', $meta['details']['last_4'] ?? null);
    }

    public function test_resync_preserves_meta_when_remote_has_no_default_payment_method(): void
    {
        $sub = $this->makeStripeSubscription();
        $stored = [
            'method'  => 'stripe',
            'type'    => 'card',
            'details' => [
                'payment_method_id' => 'pm_existing',
                'brand'             => 'amex',
                'last_4'            => '0005',
            ],
        ];
        $sub->updateMeta('active_payment_method', $stored);

        $this->mockStripe(null);

        $result = (new StripeSubscriptions())->reSyncSubscriptionFromRemote($sub);
        $this->assertNotInstanceOf(\WP_Error::class, $result);

        $meta = Subscription::find($sub->id)->getMeta('active_payment_method', []);
        $this->assertSame('pm_existing', $meta['details']['payment_method_id'] ?? null);
    }

    public function test_resync_converges_duplicate_meta_rows_to_one(): void
    {
        $sub = $this->makeStripeSubscription();

        // Simulate the concurrent-insert race: updateMeta() is check-then-create
        // and the table has no unique key, so two racing resyncs can each insert
        // an active_payment_method row.
        foreach (['pm_dup_a', 'pm_dup_b'] as $pmId) {
            SubscriptionMeta::query()->create([
                'subscription_id' => $sub->id,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key'        => 'active_payment_method',
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value'      => [
                    'method'  => 'stripe',
                    'type'    => 'card',
                    'details' => ['payment_method_id' => $pmId],
                ],
            ]);
        }

        $this->mockStripe($this->expandedCardPm('pm_new_from_remote', '4242'));

        $result = (new StripeSubscriptions())->reSyncSubscriptionFromRemote($sub);
        $this->assertNotInstanceOf(\WP_Error::class, $result);

        $rows = SubscriptionMeta::query()
            ->where('subscription_id', $sub->id)
            ->where('meta_key', 'active_payment_method')
            ->orderBy('id', 'ASC')
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame(
            'pm_new_from_remote',
            $rows->first()->meta_value['details']['payment_method_id'] ?? null
        );
    }
}
