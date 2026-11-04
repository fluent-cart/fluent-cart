<?php

namespace Tests\Functional\Rest\Subscriptions;

use FluentCart\App\Helpers\Status;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsSubscriptions;

/**
 * Spec: Cases/Functional/subscriptions.md → items 31-33 (contract superseded by
 * the renewal engine: pause/resume/reactivate are implemented now, gated by
 * collection method).
 *
 * Routes (all PUT under /fluent-cart/v2/orders/{order}/subscriptions/{subscription}):
 *   - /reactivate — store-billed (manual/system) only; gateway-managed rejected
 *     (SubscriptionController::reactivateSubscription)
 *   - /pause / /resume — store-billed always eligible; gateway-managed (automatic)
 *     requires gateway capability, which offline_payment does not declare
 *     (Subscription::canPause / canResume → SubscriptionService).
 */
class AdminPauseResumeReactivateCest
{
    use BuildsSubscriptions;

    /**
     * Spec: Cases/Functional/subscriptions.md [31]
     *   "Reactivating a gateway-managed subscription is rejected as store-billed only."
     */
    public function reactivatingGatewayManagedSubscriptionIsRejectedAsStoreBilledOnly(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Reactivate Gateway-Managed 31',
            'status'    => Status::SUBSCRIPTION_CANCELED,
        ]);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/reactivate',
            []
        );

        Assert::assertSame(
            422,
            $status,
            'reactivate on an automatic (gateway-managed) subscription must surface sendError 422 (SubscriptionController::reactivateSubscription usesRenewalEngine guard). Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'Only store-billed',
            (string) ($body['message'] ?? ''),
            'rejection must carry the store-billed-only message from SubscriptionController::reactivateSubscription'
        );

        global $wpdb;
        Assert::assertSame(
            Status::SUBSCRIPTION_CANCELED,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                (int) $subscription->id
            )),
            'rejected reactivate must NOT mutate fct_subscriptions.status — the guard returns before touching state'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [32]
     *   "Pausing a gateway-managed subscription whose gateway cannot pause is rejected."
     */
    public function pausingGatewayManagedSubscriptionWithoutGatewaySupportIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Pause Gateway-Managed 32',
            'status'    => Status::SUBSCRIPTION_ACTIVE,
        ]);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/pause',
            []
        );

        Assert::assertSame(
            422,
            $status,
            'pause must surface the canPause() rejection as sendError 422 (offline_payment declares no pause capability). Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'cannot be paused',
            (string) ($body['message'] ?? ''),
            'rejection must carry the cannot_pause message from SubscriptionService::pauseSubscription'
        );

        global $wpdb;
        Assert::assertSame(
            Status::SUBSCRIPTION_ACTIVE,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                (int) $subscription->id
            )),
            'rejected pause must NOT mutate fct_subscriptions.status'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [33]
     *   "Resuming a gateway-managed subscription whose gateway cannot resume is rejected."
     */
    public function resumingGatewayManagedSubscriptionWithoutGatewaySupportIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name' => 'Resume Gateway-Managed 33',
            'status'    => Status::SUBSCRIPTION_PAUSED,
        ]);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/resume',
            []
        );

        Assert::assertSame(
            422,
            $status,
            'resume must surface the canResume() rejection as sendError 422 (offline_payment declares no resume_subscription capability). Body: ' . wp_json_encode($body)
        );
        Assert::assertStringContainsString(
            'cannot be resumed',
            (string) ($body['message'] ?? ''),
            'rejection must carry the cannot_resume message from SubscriptionService::resumeSubscription'
        );

        global $wpdb;
        Assert::assertSame(
            Status::SUBSCRIPTION_PAUSED,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                (int) $subscription->id
            )),
            'rejected resume must NOT mutate fct_subscriptions.status'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [31a]
     *   "Admin can pause a store-billed subscription and it is recorded as paused."
     */
    public function adminCanPauseStoreBilledSubscription(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name'         => 'Pause Store-Billed 31a',
            'status'            => Status::SUBSCRIPTION_ACTIVE,
            'collection_method' => 'manual',
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS),
        ]);

        // The normal workflow pauses AFTER a renewal order was generated —
        // pause must retire it (SubscriptionService::voidPendingRenewals), or
        // the renewal stays payable on a paused subscription.
        $renewal = (new \Tests\Support\Factory\OrderFactory())->create([
            'customer_id'    => $subscription->customer_id,
            'parent_id'      => $order->id,
            'type'           => 'renewal',
            'payment_status' => 'pending',
            'status'         => 'processing',
        ]);
        $renewalTxn = (new \Tests\Support\Factory\OrderTransactionFactory())->create($renewal->id, [
            'subscription_id'  => $subscription->id,
            'transaction_type' => 'charge',
            'status'           => 'pending',
        ]);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/pause',
            []
        );

        Assert::assertSame(200, $status, 'store-billed pause must succeed. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString(
            'paused successfully',
            (string) ($body['message'] ?? ''),
            'success response must carry the paused confirmation message'
        );

        global $wpdb;
        Assert::assertSame(
            Status::SUBSCRIPTION_PAUSED,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                (int) $subscription->id
            )),
            'fct_subscriptions.status must be persisted as paused'
        );

        $renewalRow = $wpdb->get_row($wpdb->prepare(
            "SELECT status, payment_status FROM {$wpdb->prefix}fct_orders WHERE id = %d",
            (int) $renewal->id
        ));
        Assert::assertSame(
            'failed',
            (string) $renewalRow->payment_status,
            'pause must void the open renewal order (payment_status failed) — deleting the voidPendingRenewals call must fail here'
        );
        Assert::assertSame(
            'canceled',
            (string) $renewalRow->status,
            'the voided renewal order must be canceled'
        );
        Assert::assertSame(
            'failed',
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fct_order_transactions WHERE id = %d",
                (int) $renewalTxn->id
            )),
            'the voided renewal order\'s pending transaction must be failed'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [31c]
     *   "Pausing a system (auto-charge) subscription unschedules its queued charge and clears the charge state."
     */
    public function pausingSystemSubscriptionUnschedulesChargeAndClearsChargeState(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name'         => 'Pause System 31c',
            'status'            => Status::SUBSCRIPTION_ACTIVE,
            'collection_method' => 'system',
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS),
        ]);

        $renewal = (new \Tests\Support\Factory\OrderFactory())->create([
            'customer_id'    => $subscription->customer_id,
            'parent_id'      => $order->id,
            'type'           => 'renewal',
            'payment_status' => 'payment_scheduled',
            'status'         => 'processing',
        ]);

        $subscription->updateMeta('system_charge_state', [
            'order_id' => (int) $renewal->id,
            'attempts' => 0,
        ]);
        as_schedule_single_action(
            time() + HOUR_IN_SECONDS,
            \FluentCart\App\Modules\Subscriptions\Services\SystemChargeService::HOOK,
            [(int) $renewal->id, 1],
            \FluentCart\App\Modules\Subscriptions\Services\SystemChargeService::SCHEDULER_GROUP
        );
        Assert::assertNotFalse(
            as_next_scheduled_action(
                \FluentCart\App\Modules\Subscriptions\Services\SystemChargeService::HOOK,
                [(int) $renewal->id, 1],
                \FluentCart\App\Modules\Subscriptions\Services\SystemChargeService::SCHEDULER_GROUP
            ),
            'fixture: the automatic charge attempt must be queued before pausing'
        );

        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/pause',
            []
        );

        Assert::assertSame(200, $status, 'system-billed pause must succeed. Body: ' . wp_json_encode($body));

        Assert::assertFalse(
            as_next_scheduled_action(
                \FluentCart\App\Modules\Subscriptions\Services\SystemChargeService::HOOK,
                [(int) $renewal->id, 1],
                \FluentCart\App\Modules\Subscriptions\Services\SystemChargeService::SCHEDULER_GROUP
            ),
            'pause must unschedule the queued automatic charge attempt (SystemChargeService::unscheduleCharges)'
        );
        Assert::assertSame(
            [],
            \FluentCart\App\Models\Subscription::query()->find($subscription->id)->getMeta('system_charge_state', []) ?: [],
            'pause must clear the system_charge_state meta bound to the voided renewal'
        );

        global $wpdb;
        Assert::assertSame(
            'failed',
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT payment_status FROM {$wpdb->prefix}fct_orders WHERE id = %d",
                (int) $renewal->id
            )),
            'the scheduled renewal order must be voided by the pause'
        );
    }

    /**
     * Spec: Cases/Functional/subscriptions.md [33a]
     *   "Admin can resume a paused store-billed subscription and it is recorded as active."
     */
    public function adminCanResumeStoreBilledSubscription(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        [, , $order, $subscription] = $this->buildSubscriptionChain([
            'item_name'         => 'Resume Store-Billed 33a',
            'status'            => Status::SUBSCRIPTION_PAUSED,
            'collection_method' => 'manual',
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS),
        ]);

        [$status, $body] = $I->put(
            '/fluent-cart/v2/orders/' . (int) $order->id . '/subscriptions/' . (int) $subscription->id . '/resume',
            []
        );

        Assert::assertSame(200, $status, 'store-billed resume must succeed. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsString(
            'resumed successfully',
            (string) ($body['message'] ?? ''),
            'success response must carry the resumed confirmation message'
        );

        global $wpdb;
        Assert::assertSame(
            Status::SUBSCRIPTION_ACTIVE,
            (string) $wpdb->get_var($wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}fct_subscriptions WHERE id = %d",
                (int) $subscription->id
            )),
            'fct_subscriptions.status must be persisted as active'
        );
    }
}
