<?php

namespace Tests\Functional\Rest\Subscriptions;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsSubscriptions;

/**
 * Spec: Cases/Functional/subscriptions.md → "Re-syncing a subscription from the payment gateway"
 *       section, items 23-24.
 *
 * Route: PUT /fluent-cart/v2/orders/{order}/subscriptions/{subscription}/fetch
 * Controller: SubscriptionController::fetchSubscription
 *   (app/Modules/Subscriptions/Http/Controllers/SubscriptionController.php:124-138)
 * Model path: Subscription::reSyncFromRemote
 *   (app/Models/Subscription.php:500-509)
 *
 * The default offline_payment gateway's CodSubscriptions::reSyncSubscriptionFromRemote is a
 * no-op that returns the same subscription unchanged. To exercise the spec sentences
 * (returned subscription reflects latest remote values; gateway-error → clear message), each
 * test temporarily swaps the gateway's subscriptions module with an anonymous
 * AbstractSubscriptionModule subclass that fakes the remote behavior and restores in
 * finally so other Cests are not affected.
 */
class ResyncSubscriptionCest
{
    use BuildsSubscriptions;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [23]
     *   "Admin can re-sync a subscription's state from its payment gateway, and the
     *    returned subscription reflects the latest remote values."
     */
    public function adminCanResyncSubscriptionStateFromPaymentGateway(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name'         => 'Resync Probe 23',
            'status'            => Status::SUBSCRIPTION_ACTIVE,
            'bill_count'        => 1,
            'bill_times'        => 12,
            'next_billing_date' => gmdate('Y-m-d H:i:s', strtotime('+30 days')),
        ]);

        // Fake gateway responds with the remote state showing one additional billing cycle
        // completed and a pushed-out next_billing_date. Persists the changes so the
        // controller's $result reflects what the remote system would have returned.
        $remoteNextBillingDate = gmdate('Y-m-d H:i:s', strtotime('+60 days'));
        $remoteBillCount       = 2;

        $gateway = App::gateway('offline_payment');
        $originalSubs = $gateway->subscriptions;
        $originalFeatures = $gateway->supportedFeatures;
        // COD no longer declares `subscriptions` — grant it so reSyncFromRemote's
        // capability check delegates to the injected fake module.
        $gateway->supportedFeatures[] = 'subscriptions';
        $gateway->subscriptions = new class($remoteNextBillingDate, $remoteBillCount) extends AbstractSubscriptionModule {
            private string $remoteNextBillingDate;
            private int $remoteBillCount;
            public function __construct(string $remoteNextBillingDate, int $remoteBillCount)
            {
                $this->remoteNextBillingDate = $remoteNextBillingDate;
                $this->remoteBillCount       = $remoteBillCount;
            }
            public function reSyncSubscriptionFromRemote(\FluentCart\App\Models\Subscription $subscriptionModel)
            {
                $subscriptionModel->next_billing_date = $this->remoteNextBillingDate;
                $subscriptionModel->bill_count        = $this->remoteBillCount;
                $subscriptionModel->save();
                return $subscriptionModel->fresh();
            }
        };

        try {
            [$status, $body] = $I->put(
                '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/fetch',
                []
            );

            Assert::assertSame(200, $status, 'admin resync must return 200 success when the gateway reSync returns a subscription model. Body: ' . wp_json_encode($body));
            Assert::assertArrayHasKey('subscription', (array) $body, 'admin resync success body must include the synced subscription payload (SubscriptionController::fetchSubscription line 134-137)');
            $payload = (array) ($body['subscription'] ?? []);
            Assert::assertSame(
                (int) $subscription->id,
                (int) ($payload['id'] ?? 0),
                'admin resync response must echo the requested subscription id'
            );
            Assert::assertSame(
                $remoteNextBillingDate,
                (string) ($payload['next_billing_date'] ?? ''),
                'admin resync response must reflect the latest remote next_billing_date returned by the gateway reSyncSubscriptionFromRemote contract'
            );
            Assert::assertSame(
                $remoteBillCount,
                (int) ($payload['bill_count'] ?? -1),
                'admin resync response must reflect the latest remote bill_count returned by the gateway reSyncSubscriptionFromRemote contract'
            );

            // DB invariant: the remote-sourced values are persisted into fct_subscriptions
            // because Subscription::reSyncFromRemote returns the model that the gateway
            // updated (the controller's success path then re-reads via Subscription::find).
            global $wpdb;
            Assert::assertSame(
                $remoteNextBillingDate,
                (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT next_billing_date FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                    (int) $subscription->id
                )),
                'admin resync must persist the gateway-supplied next_billing_date into fct_subscriptions'
            );
            Assert::assertSame(
                $remoteBillCount,
                (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT bill_count FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                    (int) $subscription->id
                )),
                'admin resync must persist the gateway-supplied bill_count into fct_subscriptions'
            );
        } finally {
            $gateway->subscriptions = $originalSubs;
            $gateway->supportedFeatures = $originalFeatures;
        }
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [24]
     *   "Re-syncing a subscription whose gateway returns an error is rejected with a
     *    clear error message."
     */
    public function resyncingSubscriptionWhoseGatewayReturnsErrorIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name'         => 'Resync Probe 24',
            'status'            => Status::SUBSCRIPTION_ACTIVE,
            'next_billing_date' => gmdate('Y-m-d H:i:s', strtotime('+10 days')),
        ]);

        $remoteErrorMessage = 'Remote subscription not found at gateway: SUB_99999';
        $gateway = App::gateway('offline_payment');
        $originalSubs = $gateway->subscriptions;
        $originalFeatures = $gateway->supportedFeatures;
        // COD no longer declares `subscriptions` — grant it so reSyncFromRemote's
        // capability check delegates to the injected fake module.
        $gateway->supportedFeatures[] = 'subscriptions';
        $gateway->subscriptions = new class($remoteErrorMessage) extends AbstractSubscriptionModule {
            private string $remoteErrorMessage;
            public function __construct(string $remoteErrorMessage)
            {
                $this->remoteErrorMessage = $remoteErrorMessage;
            }
            public function reSyncSubscriptionFromRemote(\FluentCart\App\Models\Subscription $subscriptionModel)
            {
                return new \WP_Error('remote_not_found', $this->remoteErrorMessage);
            }
        };

        $beforeNextBillingDate = (string) $subscription->next_billing_date;

        try {
            [$status, $body] = $I->put(
                '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/fetch',
                []
            );

            Assert::assertSame(
                422,
                $status,
                'gateway-error resync must surface the controller sendError 422 (default code, since reSyncFromRemote returned WP_Error at app/Models/Subscription.php:508). Body: ' . wp_json_encode($body)
            );
            Assert::assertStringContainsString(
                $remoteErrorMessage,
                (string) ($body['message'] ?? ''),
                'gateway-error resync response must name the gateway-supplied error message (SubscriptionController::fetchSubscription line 128-131 surfaces WP_Error::get_error_message)'
            );
            Assert::assertArrayNotHasKey(
                'subscription',
                (array) $body,
                'gateway-error resync response must NOT leak a subscription payload because the controller bailed via sendError before sendSuccess'
            );

            global $wpdb;
            Assert::assertSame(
                $beforeNextBillingDate,
                (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT next_billing_date FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                    (int) $subscription->id
                )),
                'gateway-error resync must NOT mutate fct_subscriptions.next_billing_date because reSyncFromRemote returned WP_Error before any local persistence'
            );
            Assert::assertSame(
                Status::SUBSCRIPTION_ACTIVE,
                (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                    (int) $subscription->id
                )),
                'gateway-error resync must NOT mutate fct_subscriptions.status'
            );
        } finally {
            $gateway->subscriptions = $originalSubs;
            $gateway->supportedFeatures = $originalFeatures;
        }
    }
}
