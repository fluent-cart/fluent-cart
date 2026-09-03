<?php

namespace FluentCart\App\Modules\Subscriptions\Http\Controllers;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\Subscriptions\Services\EarlyPaymentFeature;
use FluentCart\App\Services\Reminders\ReminderService;
use FluentCart\App\Http\Requests\UpdateSubscriptionRequest;
use FluentCart\App\Http\Requests\UpdateVendorIdsRequest;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Modules\StoreManagedRenewal\Services\RenewalService;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Modules\Subscriptions\Services\Filter\SubscriptionFilter;

class SubscriptionController extends Controller
{
    public function index(Request $request): array
    {


        return [
            'data' => SubscriptionFilter::fromRequest($request)->paginate(),
        ];
    }

    public function getSubscriptionOrderDetails($subscriptionOrderId)
    {

        $subscription = Subscription::with([
            'labels',
            'activities.user',
            // Served by the fct_order_transactions subscription_id index
            // (OrderTransactionsMigrator base schema + migrated(), delivered to
            // existing stores via DBMigrator::maybeMigrateDBChanges at 1.0.49).
            'transactions',
            'customer.shipping_address' => function ($query) {
                $query->where('is_primary', 1);
            },
            'customer.billing_address'  => function ($query) {
                $query->where('is_primary', 1);
            },
            'order.billing_address',
            'order.shipping_address',
        ])
            ->addAppends(['business_info', 'is_reverse_charge_tax_order', 'has_pending_skip', 'last_skipped_period'])
            ->find($subscriptionOrderId);

        if (is_wp_error($subscription) || empty($subscription)) {
            return $this->entityNotFoundError(
                __('Subscription not found', 'fluent-cart'),
                __('Back to Subscription list', 'fluent-cart'),
                '/subscriptions'
            );
        }

        // Attach parent order's addresses and business info directly to subscription for consistent access
        $subscription->billing_address = $subscription->order->billing_address ?? null;
        $subscription->shipping_address = $subscription->order->shipping_address ?? null;
        $subscription->business_info = $subscription->order ? $subscription->order->getBusinessInfo() : [];
        // Upgrade eligibility, same flag the customer portal exposes
        // (CustomerSubscriptionController) — gateway-free: an upgrade-path meta
        // existence check plus a status check.
        if ($subscription instanceof Subscription) {
            $subscription->can_upgrade = $subscription->canUpgrade();
        }

        $subscription->related_orders = Order::query()
            ->with(['order_items' => function ($query) {
                $query->select('id', 'order_id', 'post_title', 'title', 'quantity', 'payment_type', 'line_meta');
            }])
            ->where('id', $subscription->parent_order_id)
            ->orWhere('parent_id', $subscription->parent_order_id)
            ->orderBy('id', 'DESC')
            ->get();


        $subscription = apply_filters('fluent_cart/subscription/view', $subscription, []);

        $reminderPermissions = (new ReminderService())->getSubscriptionReminderPermissions($subscription);

        return $this->sendSuccess([
            'subscription'         => $subscription,
            'selected_labels'      => $subscription->labels->pluck('label_id'),
            'reminder_permissions' => $reminderPermissions,
        ]);

    }

    public function validateSubscription($subscription)
    {
        if (!$subscription) {
            $this->sendError(['message' => __('Subscription not found!', 'fluent-cart')], 404);
        }
    }

    private function validateOrderBinding(Order $order, Subscription $subscription)
    {
        $rootOrderId = $order->parent_id ? $order->parent_id : $order->id;
        if ((int) $subscription->parent_order_id !== (int) $rootOrderId) {
            return $this->sendError(['message' => __('Subscription does not belong to this order.', 'fluent-cart')], 403);
        }

        return null;
    }

    public function cancelSubscription(Request $request, Order $order, Subscription $subscription)
    {
        $this->validateSubscription($subscription);
        if ($error = $this->validateOrderBinding($order, $subscription)) return $error;

        if (empty($request->getSafe('cancel_reason', 'sanitize_text_field'))) {
            return $this->sendError([
                'message' => __('Please select cancel reason!', 'fluent-cart')
            ]);
        }

        $reason = $request->getSafe('cancel_reason', 'sanitize_text_field');

        // Repeat cancels must not re-dispatch SubscriptionCanceled (duplicate
        // cancellation emails/automations) — the UI hides the button, but the
        // endpoint must guard too.
        if (in_array($subscription->status, [
            Status::SUBSCRIPTION_CANCELED,
            Status::SUBSCRIPTION_COMPLETED,
            Status::SUBSCRIPTION_EXPIRED,
        ], true)) {
            return $this->sendError([
                'message' => __('This subscription is already canceled, completed, or expired.', 'fluent-cart')
            ]);
        }

        // Only cancel at gateway if it's an automatic subscription with a vendor subscription ID
        $isAutomaticWithVendor = $subscription->collection_method === 'automatic' && !empty($subscription->vendor_subscription_id);

        if ($isAutomaticWithVendor) {
            // Cancel at the payment gateway
            $result = $subscription->cancelRemoteSubscription([
                'reason' => $reason
            ]);

            if (is_wp_error($result)) {
                return $this->sendError([
                    'message' => $result->get_error_message()
                ]);
            }

            $vendorCancelled = $result['vendor_result'];

            if (is_wp_error($vendorCancelled)) {
                return $this->sendError([
                    /* translators: %1$s: error message returned by the payment gateway */
                    'message' => sprintf(__('Subscription cancelled locally. Vendor Response: %1$s', 'fluent-cart'), $vendorCancelled->get_error_message())
                ]);
            }
        } else {
            // Manual subscription or automatic without vendor ID - just update locally
            $subscription->fill([
                'status'      => Status::SUBSCRIPTION_CANCELED,
                'canceled_at' => gmdate('Y-m-d H:i:s'),
            ])->save();

            // Same record the vendor branch keeps via cancelRemoteSubscription — merged
            // after save() so the write is not undone by the fill above.
            $subscription->mergeConfig(['cancellation_reason' => $reason]);

            // Single cancel chokepoint — void renewals, clear reminders, email once.
            SubscriptionService::finalizeCancellation($subscription, $reason ?: 'Subscription canceled by admin');
        }

        return $this->sendSuccess([
            'message'      => __('Subscription has been cancelled successfully!', 'fluent-cart'),
            'subscription' => Subscription::query()->find($subscription->id)
        ]);
    }

    public function reactivateSubscription(Request $request, Order $order, Subscription $subscription)
    {
        if ($error = $this->validateOrderBinding($order, $subscription)) return $error;
        if (!$subscription->usesRenewalEngine()) {
            return $this->sendError([
                'message' => __('Only store-billed (manual or auto-charge) subscriptions can be reactivated.', 'fluent-cart')
            ]);
        }

        $result = SubscriptionService::reactivateSubscriptionLocally($subscription);

        if (is_wp_error($result)) {
            return $this->sendError(['message' => $result->get_error_message()]);
        }

        return $this->sendSuccess([
            'message'      => __('Subscription has been reactivated successfully!', 'fluent-cart'),
            'subscription' => $result
        ]);
    }

    public function fetchSubscription(Request $request, Order $order, Subscription $subscription)
    {
        if ($error = $this->validateOrderBinding($order, $subscription)) return $error;
        $result = $subscription->reSyncFromRemote();

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message()
            ]);
        }

        return $this->sendSuccess([
            'message'      => __('Subscription fetched successfully from remote payment gateway!', 'fluent-cart'),
            'subscription' => $result
        ]);
    }

    public function pauseSubscription(Request $request, Order $order, Subscription $subscription)
    {
        $this->validateSubscription($subscription);
        if ($error = $this->validateOrderBinding($order, $subscription)) return $error;

        $reason = $request->getSafe('reason', 'sanitize_text_field') ?: __('Paused by admin', 'fluent-cart');

        $result = $subscription->pauseSubscription($reason);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message()
            ]);
        }

        return $this->sendSuccess([
            'message'      => __('Subscription has been paused successfully!', 'fluent-cart'),
            'subscription' => Subscription::query()->find($subscription->id)
        ]);
    }

    public function resumeSubscription(Request $request, Order $order, Subscription $subscription)
    {
        $this->validateSubscription($subscription);
        if ($error = $this->validateOrderBinding($order, $subscription)) return $error;

        $reason = $request->getSafe('reason', 'sanitize_text_field') ?: __('Resumed by admin', 'fluent-cart');

        $result = $subscription->resumeSubscription($reason);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message()
            ]);
        }

        return $this->sendSuccess([
            'message'      => __('Subscription has been resumed successfully!', 'fluent-cart'),
            'subscription' => Subscription::query()->find($subscription->id)
        ]);
    }

    public function updateSubscription(UpdateSubscriptionRequest $request, Order $order, Subscription $subscription)
    {
        $this->validateSubscription($subscription);
        if ($error = $this->validateOrderBinding($order, $subscription)) return $error;

        if (!$subscription->canUpdateDetails()) {
            return $this->sendError([
                'message' => __('Only manual subscriptions can be updated.', 'fluent-cart')
            ]);
        }

        $data = array_filter($request->all(), function ($value) {
            return !is_null($value);
        });

        if (empty($data)) {
            return $this->sendError([
                'message' => __('No data provided for update.', 'fluent-cart')
            ]);
        }

        $result = $subscription->updateSubscription($data);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message()
            ]);
        }

        return $this->sendSuccess([
            'message'      => __('Subscription has been updated successfully!', 'fluent-cart'),
            'subscription' => Subscription::query()->find($subscription->id)
        ]);
    }

    public function updateVendorIds(UpdateVendorIdsRequest $request, Order $order, Subscription $subscription)
    {
        $this->validateSubscription($subscription);
        if ($error = $this->validateOrderBinding($order, $subscription)) return $error;

        $data = $request->all();
        $payload = [];

        // Only the keys actually sent are written — an absent key must not blank
        // the stored id.
        foreach (['vendor_subscription_id', 'vendor_customer_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = (string) $data[$field];
            }
        }

        if (empty($payload)) {
            return $this->sendError([
                'message' => __('No data provided for update.', 'fluent-cart')
            ]);
        }

        $result = SubscriptionService::updateVendorIds($subscription, $payload);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message()
            ]);
        }

        return $this->sendSuccess([
            'message'      => __('Vendor IDs have been updated successfully!', 'fluent-cart'),
            'subscription' => Subscription::query()->find($subscription->id)
        ]);
    }

    public function verifyVendorIds(UpdateVendorIdsRequest $request, Order $order, Subscription $subscription)
    {
        $this->validateSubscription($subscription);
        if ($error = $this->validateOrderBinding($order, $subscription)) return $error;

        if (!$subscription->canEditVendorIds()) {
            return $this->sendError([
                'message' => __('Vendor IDs can only be edited on an active gateway-billed subscription.', 'fluent-cart')
            ]);
        }

        if (!$subscription->canVerifyVendorIds()) {
            return $this->sendError([
                'message' => __('This payment method does not support subscription lookup.', 'fluent-cart')
            ]);
        }

        /** @var AbstractPaymentGateway $gateway */
        $gateway = App::gateway($subscription->current_payment_method);

        $data = $request->all();

        $result = $gateway->subscriptions->verifyVendorSubscription([
            'vendor_subscription_id' => (string) Arr::get($data, 'vendor_subscription_id', ''),
            'vendor_customer_id'     => (string) Arr::get($data, 'vendor_customer_id', ''),
        ], $order->mode);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message()
            ]);
        }

        return $this->sendSuccess([
            'message'      => __('Subscription found at the payment gateway.', 'fluent-cart'),
            'verification' => $result
        ]);
    }

    public function generateEarlyPaymentLink(Request $request, Order $order, Subscription $subscription)
    {
        $this->validateSubscription($subscription);

        if (!EarlyPaymentFeature::isEnabled()) {
            return $this->sendError([
                'message' => __('Early payment is not enabled for this site.', 'fluent-cart')
            ]);
        }

        $subscriptionOrderId = null;
        // property_exists() cannot see Eloquent attributes (they live in the
        // model's attributes array behind __get), so it returned false even for
        // populated rows and every request died on the mismatch guard below.
        // isset() routes through __isset and sees the real attribute.
        if (isset($subscription->parent_order_id) && $subscription->parent_order_id) {
            $subscriptionOrderId = (int) $subscription->parent_order_id;
        }
        
        if ($subscriptionOrderId === null || $subscriptionOrderId !== (int) $order->id) {
            return $this->sendError([
                'message' => __('Invalid subscription for the specified order.', 'fluent-cart')
            ]);
        }

        if ($subscription->bill_times <= 0) {
            return $this->sendError([
                'message' => __('Early payment is only available for installment subscriptions.', 'fluent-cart')
            ]);
        }

        if (!in_array($subscription->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING])) {
            return $this->sendError([
                'message' => __('Subscription must be active to make early payments.', 'fluent-cart')
            ]);
        }

        $remaining = $subscription->bill_times - $subscription->bill_count;

        if ($remaining <= 0) {
            return $this->sendError([
                'message' => __('All installments have already been paid.', 'fluent-cart')
            ]);
        }

        $url = add_query_arg([
            'fluent-cart'       => 'early-installment-payment',
            'subscription_hash' => $subscription->uuid,
        ], home_url('/'));

        return $this->sendSuccess([
            'message'     => __('Early payment link generated.', 'fluent-cart'),
            'payment_url' => $url,
        ]);
    }

    /**
     * Run one immediate off-session charge attempt on the subscription's open
     * renewal invoice. A card decline is HTTP 200 with status "failed" — the
     * request worked, the charge did not. State violations are sendError 4xx.
     */
    public function chargeNow(Request $request, Order $order, Subscription $subscription)
    {
        $this->validateSubscription($subscription);
        if ($error = $this->validateOrderBinding($order, $subscription)) return $error;

        $invoice = Order::query()
            ->where('parent_id', $subscription->parent_order_id)
            ->where('type', Status::ORDER_TYPE_RENEWAL)
            ->whereIn('payment_status', [Status::PAYMENT_PENDING, Status::PAYMENT_SCHEDULED])
            ->orderBy('id', 'DESC')
            ->first();

        if (!$invoice) {
            return $this->sendError([
                'message' => __('There is no open renewal order to charge for this subscription.', 'fluent-cart')
            ]);
        }

        $result = \FluentCart\App\Modules\Subscriptions\Services\SystemChargeService::chargeNow(
            $invoice,
            $subscription,
            get_current_user_id()
        );

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message()
            ]);
        }

        return $this->sendSuccess([
            'status'       => $result['status'],
            'message'      => $result['message'],
            'subscription' => Subscription::query()->find($subscription->id)
        ]);
    }

    public function createRenewalNow(Request $request, Order $order, Subscription $subscription)
    {
        $this->validateSubscription($subscription);
        if ($error = $this->validateOrderBinding($order, $subscription)) return $error;

        if (!$subscription->usesRenewalEngine()) {
            return $this->sendError([
                'message' => __('Creating a renewal is only available for store-billed (manual or auto-charge) subscriptions.', 'fluent-cart')
            ]);
        }

        if (!in_array($subscription->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING])) {
            return $this->sendError([
                'message' => __('A renewal can only be created for active or trialing subscriptions.', 'fluent-cart')
            ]);
        }

        if (!$subscription->next_billing_date) {
            return $this->sendError([
                'message' => __('Subscription has no upcoming billing date.', 'fluent-cart')
            ]);
        }

        $result = RenewalService::createRenewalOrders($subscription);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message()
            ]);
        }

        $renewal = !empty($result) ? $result[0] : null;

        // Renewal already exists: manual has nothing to charge; system charges
        // the existing open renewal (one subscription per parent order).
        if (!$renewal) {
            if (!$subscription->isSystem()) {
                return $this->sendError([
                    'message' => __('A pending renewal already exists for this subscription.', 'fluent-cart')
                ], 422);
            }

            $renewal = Order::query()
                ->where('parent_id', $subscription->parent_order_id)
                ->where('type', Status::ORDER_TYPE_RENEWAL)
                ->whereIn('payment_status', [Status::PAYMENT_PENDING, Status::PAYMENT_SCHEDULED])
                ->orderBy('id', 'DESC')
                ->first();

            if (!$renewal) {
                return $this->sendError([
                    'message' => __('A renewal already exists but is not open for charging.', 'fluent-cart')
                ], 422);
            }
        }

        // System charges immediately; a decline falls back to the retry/dunning
        // flow. Manual just gets the pay-now renewal + email.
        if ($subscription->isSystem()) {
            $chargeResult = \FluentCart\App\Modules\Subscriptions\Services\SystemChargeService::chargeNow(
                $renewal,
                $subscription,
                get_current_user_id()
            );

            if (is_wp_error($chargeResult)) {
                return $this->sendError([
                    'message' => $chargeResult->get_error_message()
                ]);
            }

            return $this->sendSuccess([
                'status'       => $chargeResult['status'],
                'message'      => $chargeResult['message'],
                'subscription' => Subscription::query()->find($subscription->id)
            ]);
        }

        return $this->sendSuccess([
            'message' => __('Renewal has been created successfully.', 'fluent-cart'),
            'renewal' => $renewal
        ]);
    }

    public function skipRenewal(Request $request, Order $order, Subscription $subscription)
    {
        $this->validateSubscription($subscription);
        if ($error = $this->validateOrderBinding($order, $subscription)) return $error;

        if (!$subscription->usesRenewalEngine()) {
            return $this->sendError([
                'message' => __('Skip next period is only available for store-billed (manual or auto-charge) subscriptions.', 'fluent-cart')
            ]);
        }

        if (!in_array($subscription->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING])) {
            return $this->sendError([
                'message' => __('Period can only be skipped for active or trialing subscriptions.', 'fluent-cart')
            ]);
        }

        if (!$subscription->next_billing_date) {
            return $this->sendError([
                'message' => __('Subscription has no upcoming billing date to skip.', 'fluent-cart')
            ]);
        }

        $note = (string) $request->getSafe('reason', 'sanitize_text_field', '');

        $result = RenewalService::skipNextPeriod($subscription, $note);

        if (empty($result['skipped'])) {
            // Already pending, lost a race, or nothing to advance — the no-stacking
            // invariant is enforced inside skipNextPeriod(), so report it plainly.
            return $this->sendError([
                'message' => __('The next period could not be skipped. It may have already been skipped.', 'fluent-cart')
            ]);
        }

        return $this->sendSuccess([
            'message'              => __('Billing period has been skipped successfully.', 'fluent-cart'),
            'old_next_billing_date' => $result['old_next_billing_date'],
            'new_next_billing_date' => $result['new_next_billing_date'],
        ]);
    }
}
