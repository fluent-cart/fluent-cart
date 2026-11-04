<?php

namespace Tests\Functional\Rest\Subscriptions;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsSubscriptions;

/**
 * Spec: Cases/Functional/subscriptions.md → "Cancelling a subscription" section, items 18-22.
 *
 * Route: PUT /fluent-cart/v2/orders/{order}/subscriptions/{subscription}/cancel
 * Controller: SubscriptionController::cancelSubscription
 *   (app/Modules/Subscriptions/Http/Controllers/SubscriptionController.php:83-115)
 * Policy:     OrderPolicy::hasRoutePermissions (subscriptions/manage)
 *
 * The default offline_payment gateway carries a CodSubscriptions::cancel that always
 * succeeds (returns ['status'=>'canceled', 'canceled_at'=>now]). Item 22 specifically
 * needs the gateway to REJECT the cancellation; we hot-swap the gateway's subscriptions
 * module with an anonymous AbstractSubscriptionModule subclass that returns WP_Error
 * from cancel(), then restore in finally so other Cests are not affected.
 */
class CancelSubscriptionCest
{
    use BuildsSubscriptions;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [18]
     *   "Admin can cancel an active subscription by supplying a cancel reason,
     *    and the subscription is marked canceled."
     */
    public function adminCanCancelActiveSubscriptionBySupplyingCancelReason(FunctionalTester $I): void
    {

        $I->loginAsSuperAdmin();
        // Seeded with real checkout metadata, not an empty config: the controller
        // must MERGE the reason into the stored config. A wholesale replacement
        // would satisfy a "reason is present" assertion while destroying every
        // other key, so the surviving keys are asserted individually below.
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Cancel Probe 18',
            'status'    => Status::SUBSCRIPTION_ACTIVE,
            'config'    => [
                'currency'        => 'EUR',
                'management_mode' => 'automatic',
                'variation_type'  => 'subscription',
                'item_attributes' => ['payment_type' => 'subscription', 'interval' => 'monthly'],
            ],
        ]);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/cancel',
            ['cancel_reason' => 'Customer requested cancellation']
        );

        Assert::assertSame(200, $status, 'admin cancel must return 200 success when supplying a cancel_reason on an active subscription. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString(
            'cancelled successfully',
            (string) ($body['message'] ?? ''),
            'admin cancel success body must carry the controller success message. Body: ' . wp_json_encode($body)
        );
        Assert::assertArrayHasKey('subscription', (array) $body, 'admin cancel success body must include the canceled subscription payload returned by SubscriptionController::cancelSubscription');
        Assert::assertSame(
            (int) $subscription->id,
            (int) (((array) ($body['subscription'] ?? []))['id'] ?? 0),
            'admin cancel success body must echo the requested subscription id. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        Assert::assertSame(
            Status::SUBSCRIPTION_CANCELED,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                (int) $subscription->id
            )),
            'admin cancel must persist fct_subscriptions.status=canceled (Subscription::cancelRemoteSubscription updates the local row regardless of vendor branch). Body: ' . wp_json_encode($body)
        );
        Assert::assertNotEmpty(
            $wpdb->get_var($wpdb->prepare(
                "SELECT canceled_at FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                (int) $subscription->id
            )),
            'admin cancel must stamp fct_subscriptions.canceled_at as a UTC timestamp (Subscription::cancelRemoteSubscription line 552-554)'
        );
        $storedConfig = json_decode((string) $wpdb->get_var($wpdb->prepare(
            "SELECT config FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
            (int) $subscription->id
        )), true);

        Assert::assertIsArray($storedConfig, 'fct_subscriptions.config must remain decodable JSON after the cancel write');
        Assert::assertSame(
            'Customer requested cancellation',
            $storedConfig['cancellation_reason'] ?? null,
            'admin cancel must persist the supplied cancel_reason into fct_subscriptions.config.cancellation_reason'
        );

        // The reason must be ADDED, not swapped in for the whole config. Each
        // seeded key is asserted by value, so replacing the config with a
        // single-key array fails here instead of passing on the reason alone.
        Assert::assertSame('EUR', $storedConfig['currency'] ?? null, 'admin cancel must preserve config.currency — the reason is merged into the stored config, never substituted for it');
        Assert::assertSame('automatic', $storedConfig['management_mode'] ?? null, 'admin cancel must preserve config.management_mode');
        Assert::assertSame('subscription', $storedConfig['variation_type'] ?? null, 'admin cancel must preserve config.variation_type');
        // assertEquals, not assertSame: the JSON round-trip does not promise key
        // ORDER, only that every key and value survives.
        Assert::assertEquals(
            ['payment_type' => 'subscription', 'interval' => 'monthly'],
            $storedConfig['item_attributes'] ?? null,
            'admin cancel must preserve the nested config.item_attributes snapshot intact'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [19]
     *   "Cancelling a subscription without supplying a cancel reason is rejected with a clear error."
     */
    public function cancellingSubscriptionWithoutCancelReasonIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Cancel Probe 19',
            'status'    => Status::SUBSCRIPTION_ACTIVE,
        ]);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/cancel',
            [] // no cancel_reason
        );

        Assert::assertSame(
            422,
            $status,
            'missing cancel_reason rejection must surface the controller sendError 422 (base sendError default code is 422). Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'select cancel reason',
            (string) ($body['message'] ?? ''),
            'missing cancel_reason rejection message must name the missing field per SubscriptionController::cancelSubscription line 87-91. Body: ' . wp_json_encode($body)
        );

        global $wpdb;
        Assert::assertSame(
            Status::SUBSCRIPTION_ACTIVE,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                (int) $subscription->id
            )),
            'missing cancel_reason rejection must NOT mutate fct_subscriptions.status — the active subscription must remain active because the controller returns sendError before reaching cancelRemoteSubscription. Body: ' . wp_json_encode($body)
        );
        Assert::assertEmpty(
            $wpdb->get_var($wpdb->prepare(
                "SELECT canceled_at FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                (int) $subscription->id
            )),
            'missing cancel_reason rejection must NOT stamp fct_subscriptions.canceled_at'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [20]
     *   "The cancel reason supplied by the admin is sanitized as plain text before being
     *    stored, so script-like input is stripped."
     */
    public function cancelReasonSuppliedByAdminIsSanitizedAsPlainTextBeforeStored(FunctionalTester $I): void
    {

        $I->loginAsSuperAdmin();
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Cancel Probe 20',
            'status'    => Status::SUBSCRIPTION_ACTIVE,
            'config'    => ['currency' => 'GBP', 'management_mode' => 'manual'],
        ]);

        // sanitize_text_field strips HTML tags and removes line breaks but preserves the
        // surrounding plain-text content. A <script> element body is preserved (alert(...))
        // because sanitize_text_field operates on the markup, not on JavaScript semantics —
        // what must be stripped is the literal <script>...</script> tag chars.
        $rawReason = '<script>alert("xss")</script>Refund please';
        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/cancel',
            ['cancel_reason' => $rawReason]
        );

        Assert::assertSame(200, $status, 'sanitized cancel_reason must still result in a successful cancel. Body: ' . wp_json_encode($body));

        global $wpdb;
        $storedConfigJson = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT config FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
            (int) $subscription->id
        ));
        Assert::assertStringContainsString(
            'Refund please',
            $storedConfigJson,
            'stored fct_subscriptions.config.cancellation_reason must preserve the plain-text portion of the supplied reason'
        );
        Assert::assertStringNotContainsString(
            '<script>',
            $storedConfigJson,
            'stored cancellation_reason must have the <script> tag stripped by sanitize_text_field at SubscriptionController::cancelSubscription:94'
        );
        Assert::assertStringNotContainsString(
            '</script>',
            $storedConfigJson,
            'stored cancellation_reason must have the closing </script> tag stripped'
        );

        // Same merge contract as spec [18]: sanitizing the reason must not cost
        // the surrounding config.
        $storedConfig = json_decode($storedConfigJson, true);
        Assert::assertIsArray($storedConfig, 'fct_subscriptions.config must remain decodable JSON after the sanitized cancel write');
        Assert::assertSame('GBP', $storedConfig['currency'] ?? null, 'the sanitized cancel path must preserve config.currency');
        Assert::assertSame('manual', $storedConfig['management_mode'] ?? null, 'the sanitized cancel path must preserve config.management_mode');
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [21]
     *   "Cancelling a subscription triggers a 'subscription canceled' notification that
     *    internal listeners can react to."
     */
    public function cancellingSubscriptionTriggersSubscriptionCanceledNotification(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Cancel Probe 21',
            'status'    => Status::SUBSCRIPTION_ACTIVE,
        ]);

        // Hook the SubscriptionCanceled event's do_action so we can verify the notification
        // actually fired and carries the canceled subscription as its payload (per
        // EventDispatcher line 27-29 in app/Events/EventDispatcher.php and the
        // SubscriptionCanceled::$hook string). The event's `reason` slot carries the $note
        // arg (defaulting to "on customer request" in Subscription::cancelRemoteSubscription
        // line 574-577) rather than the supplied cancel_reason, so the spec sentence
        // "triggers a notification that internal listeners can react to" is honored by
        // verifying the listener saw the dispatched payload, not by re-asserting the
        // cancel_reason inside the event.
        $captured = ['fired_count' => 0, 'subscription_id' => null, 'has_reason' => false];
        $listener = static function ($payload) use (&$captured) {
            $captured['fired_count']++;
            $sub = $payload['subscription'] ?? null;
            if ($sub && isset($sub->id)) {
                $captured['subscription_id'] = (int) $sub->id;
            }
            $captured['has_reason'] = (string) ($payload['reason'] ?? '') !== '';
        };
        add_action('fluent_cart/subscription_canceled', $listener, 10, 1);

        try {
            [$status, $body] = $I->put(
                '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/cancel',
                ['cancel_reason' => 'Subscription canceled by admin for reason 21']
            );

            Assert::assertSame(200, $status, 'admin cancel must succeed so the SubscriptionCanceled event can be dispatched. Body: ' . wp_json_encode($body));
            Assert::assertSame(
                1,
                $captured['fired_count'],
                'the fluent_cart/subscription_canceled action must fire exactly once when cancelRemoteSubscription completes — Subscription::cancelRemoteSubscription line 580-582 dispatches SubscriptionCanceled which auto-fires the hook via EventDispatcher line 27-29'
            );
            Assert::assertSame(
                (int) $subscription->id,
                $captured['subscription_id'],
                'the dispatched event payload must carry the just-canceled subscription so internal listeners can react to it (SubscriptionCanceled::toArray exposes subscription)'
            );
            Assert::assertTrue(
                $captured['has_reason'],
                'the dispatched event payload reason must be non-empty (SubscriptionCanceled::toArray defaults reason to "canceled on customer request" when the controller supplies no $note) so internal listeners always receive an actionable reason'
            );
        } finally {
            remove_action('fluent_cart/subscription_canceled', $listener, 10);
        }
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [22]
     *   "When the payment gateway rejects the cancellation, the admin sees a clear error
     *    message that names the gateway's response."
     */
    public function whenPaymentGatewayRejectsCancellationAdminSeesClearErrorMessage(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        // A vendor_subscription_id is required: cancelRemoteSubscription only calls the
        // gateway when one exists — without it the renewal engine cancels locally and
        // succeeds (the "nothing to cancel at the gateway" branch).
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name'              => 'Cancel Probe 22',
            'status'                 => Status::SUBSCRIPTION_ACTIVE,
            'vendor_subscription_id' => 'sub_vendor_cancel_probe_22',
        ]);

        // Replace the offline_payment gateway's subscriptions module with a stub that returns
        // WP_Error from cancel() so Subscription::cancelRemoteSubscription surfaces the
        // gateway error. The controller then hits its is_wp_error($result) branch and
        // surfaces the gateway message via sendError. Since the renewal engine dropped
        // 'subscriptions' from offline_payment's supportedFeatures, the capability must be
        // declared too — cancelRemoteSubscription gates the gateway call on has('subscriptions').
        $gateway = App::gateway('offline_payment');
        $originalSubs = $gateway->subscriptions;
        $originalFeatures = $gateway->supportedFeatures;
        $gateway->supportedFeatures[] = 'subscriptions';
        $rejectionMessage = 'Card processor declined cancellation: subscription must be paid in full first';
        $gateway->subscriptions = new class($rejectionMessage) extends AbstractSubscriptionModule {
            private string $rejectionMessage;
            public function __construct(string $rejectionMessage)
            {
                $this->rejectionMessage = $rejectionMessage;
            }
            public function cancel($vendorSubscriptionId, $args = [])
            {
                return new \WP_Error('vendor_declined', $this->rejectionMessage);
            }
        };

        try {
            [$status, $body] = $I->put(
                '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/cancel',
                ['cancel_reason' => 'Testing gateway rejection']
            );

            Assert::assertSame(
                422,
                $status,
                'gateway-rejected cancel must surface the controller sendError 422 (default code, since cancelRemoteSubscription returned WP_Error at app/Models/Subscription.php:538-540). Body: ' . wp_json_encode($body)
            );
            Assert::assertStringContainsString(
                $rejectionMessage,
                (string) ($body['message'] ?? ''),
                'gateway-rejected cancel response must name the gateway response (WP_Error::get_error_message()) so admins can act on it. Body: ' . wp_json_encode($body)
            );

            global $wpdb;
            Assert::assertSame(
                Status::SUBSCRIPTION_ACTIVE,
                (string) $wpdb->get_var($wpdb->prepare(
                    "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                    (int) $subscription->id
                )),
                'gateway-rejected cancel must NOT mutate fct_subscriptions.status — cancelRemoteSubscription returns the WP_Error before the local-update branch at app/Models/Subscription.php:543-572 runs. Body: ' . wp_json_encode($body)
            );
            Assert::assertEmpty(
                $wpdb->get_var($wpdb->prepare(
                    "SELECT canceled_at FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                    (int) $subscription->id
                )),
                'gateway-rejected cancel must NOT stamp fct_subscriptions.canceled_at'
            );
        } finally {
            $gateway->subscriptions = $originalSubs;
            $gateway->supportedFeatures = $originalFeatures;
        }
    }
}
