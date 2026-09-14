<?php

namespace FluentCart\App\Modules\PaymentMethods\StripeGateway;

use FluentCart\App\App;
use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;

class Processor
{
    /**
     * Stripe Checkout caps a session at 100 line items. Kept below it with room
     * for the tax and reconciliation lines this builder may append.
     */
    const MAX_HOSTED_LINE_ITEMS = 90;

    /**
     * The return URL handed to Stripe for an onsite confirm.
     *
     * Onsite normally never navigates (`redirect: 'if_required'`), but an
     * issuer that forces a full 3DS redirect sends the buyer here. Deliberately
     * unfiltered: this is a machine contract dispatched by core WebRoutes to
     * the fluent_cart_action_fct_stripe_onsite_return action, which confirms
     * before the buyer is sent anywhere.
     *
     * @param \FluentCart\App\Models\OrderTransaction $transaction
     * @return string
     */
    public static function getOnsiteGatewayReturnUrl($transaction)
    {
        return site_url('?fluent-cart=fct_stripe_onsite_return&trx_hash=' . $transaction->uuid);
    }

    /**
     * The return URL handed to Stripe for hosted checkout sessions.
     *
     * Deliberately unfiltered: this URL is a machine contract dispatched by
     * core WebRoutes to the fluent_cart_action_fct_stripe_hosted action,
     * which confirms the session. The buyer's real destination —
     * fluent_cart/payment/success_url — is applied AFTER confirmation, in
     * the hosted-return redirect.
     *
     * @param \FluentCart\App\Models\OrderTransaction $transaction
     * @return string
     */
    public static function getHostedGatewayReturnUrl($transaction)
    {
        return site_url('?fluent-cart=fct_stripe_hosted&trx_hash=' . $transaction->uuid);
    }

    public function handleSubscription(PaymentInstance $paymentInstance, $paymentArgs)
    {
        $stripeSettings = new StripeSettingsBase();
        $checkoutMode = $stripeSettings->get('checkout_mode') ?? 'onsite';

        // If hosted mode, create Checkout Session for subscription
        if ($checkoutMode === 'hosted') {
            return $this->handleHostedSubscriptionCheckout($paymentInstance, $paymentArgs);
        }

        // Original onsite subscription flow
        $orderType = $paymentInstance->order->type;
        $fcCustomer = $paymentInstance->order->customer;
        $billingAddress = $paymentInstance->order->billing_address;

        $subscriptionModel = $paymentInstance->subscription;

        if (!$subscriptionModel) {
            return new \WP_Error('no_subscription', __('No subscription found.', 'fluent-cart'));
        }

        $stripeCustomer = StripeHelper::createOrGetStripeCustomer($paymentInstance->order->customer);

        if (is_wp_error($stripeCustomer)) {
            return $stripeCustomer;
        }

        $feeTotal = $orderType !== 'renewal' ? (int)$paymentInstance->order->fee_total : 0;
        $initialAmount = (int)$subscriptionModel->signup_fee + $paymentInstance->getExtraAddonAmount() + $feeTotal;

        if ($orderType == 'renewal') {
            $stripePlan = Plan::getStripePricing([
                'order_id'         => $subscriptionModel->parent_order_id,
                'product_id'       => $subscriptionModel->product_id,
                'variation_id'     => $subscriptionModel->variation_id,
                'billing_interval' => $subscriptionModel->billing_interval,
                'recurring_total'  => $subscriptionModel->getCurrentRenewalAmount(),
                'currency'         => $paymentInstance->order->currency,
                'trial_days'       => $subscriptionModel->getReactivationTrialDays(), // No trial for renewals
                'interval_count'   => 1 // per month / year / week
            ]);

            $initialAmount = 0;
        } else {
            $stripePlan = Plan::getStripePricing([
                'order_id'         => $subscriptionModel->parent_order_id,
                'product_id'       => $subscriptionModel->product_id,
                'variation_id'     => $subscriptionModel->variation_id,
                'billing_interval' => $subscriptionModel->billing_interval,
                'recurring_total'  => $subscriptionModel->recurring_total,
                'currency'         => $paymentInstance->order->currency,
                'trial_days'       => (int)$subscriptionModel->trial_days,
                'interval_count'   => 1 // per month / year / week
            ]);
        }

        if (is_wp_error($stripePlan)) {
            return $stripePlan;
        }

        $stripeSubscriptionData = [
            'customer'         => Arr::get($stripeCustomer, 'id', ''),
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => [
                'save_default_payment_method' => 'on_subscription'
            ],
            'items'            => [
                [
                    'plan'     => $stripePlan['id'],
                    'quantity' => $subscriptionModel->quantity ?: 1,
                ]
            ],
            'expand'           => [
                'latest_invoice.confirmation_secret',
                'pending_setup_intent'
            ],
            'metadata'         => apply_filters('fluent_cart/payments/stripe_metadata_subscription', [
                'fct_ref_id' => $paymentInstance->order->uuid,
                'email'      => $paymentInstance->order->customer->email,
                'name'       => $paymentInstance->order->full_name,
                'subscription_item'       => $subscriptionModel->item_name,
                'order_reference' => 'fct_order_id_' . $paymentInstance->order->id,
            ], [
                'order'       => $paymentInstance->order,
                'transaction' => $paymentInstance->transaction,
                'subscription' => $subscriptionModel
            ]),
        ];

        if (Arr::get($stripePlan, 'trial_period_days')) {
            // Anchor trial_end to a STABLE point (the charge transaction's creation time,
            // which is preserved across re-submissions) instead of "now". A volatile
            // trial_end would make a retried create send a different body, and Stripe
            // rejects a reused Idempotency-Key whose parameters changed (400), bricking
            // the order for the key's 24h lifetime. Anchoring keeps retries byte-identical.
            $trialDays = (int) Arr::get($stripePlan, 'trial_period_days');
            $anchorTs = $paymentInstance->transaction && $paymentInstance->transaction->created_at
                ? strtotime($paymentInstance->transaction->created_at . ' UTC')
                : time();
            $trialEnd = strtotime('+' . $trialDays . ' days', $anchorTs);
            // Stripe requires trial_end in the future; only a stale late retry could fall
            // behind, and that order would already carry a fresh transaction/key anyway.
            if ($trialEnd <= time() + MINUTE_IN_SECONDS) {
                $trialEnd = strtotime('+' . $trialDays . ' days');
            }
            $stripeSubscriptionData['trial_end'] = $trialEnd;
        }

        // Maybe we have initial amount
        if ($initialAmount) {
            $addonPrice = Plan::getOneTimeAddonPrice([
                'product_id' => $subscriptionModel->product_id,
                'currency'   => $paymentInstance->order->currency,
                'amount'     => (int)$initialAmount,
                'variation_id'     => $subscriptionModel->variation_id,
                'order_id'         => $subscriptionModel->parent_order_id,
            ]);

            if (is_wp_error($addonPrice)) {
                return $addonPrice;
            }

            $stripeSubscriptionData['add_invoice_items'] = [
                [
                    'price'    => $addonPrice['id'],
                    'quantity' => 1
                ]
            ];
        }

        if ($expireAt = $paymentInstance->getSubscriptionCancelAtTimeStamp()) {
          //  $stripeSubscriptionData['cancel_at'] = $expireAt;
        }

        // Duplicate-charge defense — key construction contract in
        // .claude/skills/coding-rules/payment-idempotency.md. Seed dedupes duplicates
        // and frees retries; fingerprint = charge-material params so an edited order
        // gets a fresh key instead of a same-key/changed-parameters 400 (the abandoned
        // incomplete subscription auto-expires). Params, not transaction->total: a
        // recurring coupon can change the plan while the first charge stays $0.
        // Metadata excluded — volatile filters must not change the key on a duplicate.
        // Guard runs here, after the create body is built, so it can compare it.
        $existingRemoteSubscription = $this->guardExistingRemoteSubscription($subscriptionModel, $paymentInstance->order, $stripeSubscriptionData);
        if (is_wp_error($existingRemoteSubscription)) {
            return $existingRemoteSubscription;
        }

        $idempotencyFingerprint = [
            'customer'          => Arr::get($stripeSubscriptionData, 'customer'),
            'items'             => Arr::get($stripeSubscriptionData, 'items'),
            'add_invoice_items' => Arr::get($stripeSubscriptionData, 'add_invoice_items'),
            'trial_end'         => Arr::get($stripeSubscriptionData, 'trial_end'),
            'replaces'          => (string)Arr::get(
                (array)$subscriptionModel->config,
                'stripe_replaced_vendor_sub_id',
                ''
            ),
        ];
        $idempotencySeed = $paymentInstance->getIdempotencySeed();
        $idempotencyKey = $idempotencySeed
            ? 'fct_stripe_sub_' . md5($idempotencySeed . '|' . wp_json_encode($idempotencyFingerprint))
            : null;

        if ($existingRemoteSubscription) {
            $stripeSubscription = $existingRemoteSubscription;
        } else {
            $stripeSubscription = (new API())->createStripeObject('subscriptions', $stripeSubscriptionData, 'current', [
                'Idempotency-Key' => $idempotencyKey
            ]);
        }

        if (is_wp_error($stripeSubscription)) {
            return $stripeSubscription;
        }

        // A guard-reused sub carries an expanded payment_intent object here.
        $vendorChargeId = Arr::get($stripeSubscription, 'latest_invoice.payment_intent');
        if (is_array($vendorChargeId)) {
            $vendorChargeId = Arr::get($vendorChargeId, 'id');
        }
        if (!$vendorChargeId) {
            $vendorChargeId = Arr::get($stripeSubscription, 'pending_setup_intent.id');
        }

        if ($vendorChargeId) {
            $paymentInstance->transaction->update(['vendor_charge_id' => $vendorChargeId]);
        }

        $vendorSubscriptionId = Arr::get($stripeSubscription, 'id');

        $subscriptionUpdateFields = [
            'vendor_subscription_id' => $vendorSubscriptionId,
            'vendor_customer_id'     => $stripeSubscription['customer']
        ];

        $subscriptionModel->update($subscriptionUpdateFields);

        if ($orderType == 'renewal' && Arr::get($stripePlan, 'trial_period_days', 0) > 0) {
            $subscriptionModel->mergeConfig(['is_trial_days_simulated' => 'yes']);
        }

        if ($stripeSubscription['pending_setup_intent'] != null) {
            $paymentArgs['vendor_subscription_info'] = [
                'type'         => 'setup',
                'clientSecret' => Arr::get($stripeSubscription, 'pending_setup_intent.client_secret'),
                'trx_hash'     => $paymentInstance->transaction->uuid,
            ];
        } else {
            $paymentArgs['vendor_subscription_info'] = [
                'type'         => 'payment',
                'clientSecret' => Arr::get($stripeSubscription, 'latest_invoice.confirmation_secret.client_secret')
            ];
        }

        $customerData = [
            'name'      => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
            'email'     => $fcCustomer->email,
            'address_1' => $billingAddress->address_1,
            'address_2' => $billingAddress->address_2,
            'city'      => $billingAddress->city,
            'state'     => $billingAddress->state,
            'postcode'  => $billingAddress->postcode,
            'country'   => $billingAddress->country
        ];

        return [
            'nextAction'   => 'stripe',
            'actionName'   => 'custom',
            'status'       => 'success',
            'message'      => __('Order has been placed successfully', 'fluent-cart'),
            'payment_args' => $paymentArgs,
            'response'     => $stripeSubscription,
            'fc_customer'  => $customerData
        ];
    }

    /**
     * A retryable checkout can arrive with a live Stripe subscription already
     * attached — the previous create succeeded but its confirm/webhook never
     * landed, and a changed cart mints a fresh idempotency key, so the key alone
     * cannot stop a second create. A second create bills the customer on a
     * subscription the store cannot see or cancel.
     *
     * Ownership discriminator: metadata.fct_ref_id (stamped at create) must match
     * $order->uuid before the guard reuses or cancels anything. A non-matching sub
     * belongs to another flow — on renewal, the previous cycle's subscription that
     * SubscriptionRenewalHandler cancels only after payment succeeds — so the
     * guard must leave it alone. active/trialing blocks regardless of owner: the
     * customer must never be charged beside a live subscription. One exception:
     * an owned trialing sub with no payment method and a still-confirmable
     * pending_setup_intent is handed back for reuse — a $0 first invoice skips
     * `incomplete`, so a failed card setup leaves the sub trialing, not dead.
     *
     * Owned incomplete the buyer can still confirm is handed back for reuse —
     * `incomplete` is the normal status for the whole confirm (3DS) window, and
     * cancelling it voids the PaymentIntent mid-confirmation
     * (payment_intent_unexpected_state). Owned but dead is cancelled, the
     * cancelled id persisted to subscription config
     * (stripe_replaced_vendor_sub_id) and the local vendor id cleared; the
     * caller folds the persisted id into the idempotency fingerprint so the
     * recreate cannot replay Stripe's 24h-cached response for the deleted sub
     * (the pending transaction's seed has not rolled). Persisted, not
     * request-local, so a retry after an ambiguously failed recreate computes
     * the same key and Stripe's idempotent replay recovers the unrecorded sub.
     * A failed cancel fails CLOSED, like guardExistingPaymentIntent.
     *
     * @param array $requestData create body for reuse comparison; empty (hosted
     *                           Checkout Session) means nothing is reusable.
     * @return array|\WP_Error|null reusable remote sub, stop, or create fresh
     */
    private function guardExistingRemoteSubscription($subscriptionModel, $order, $requestData = [])
    {
        $existingVendorSubId = $subscriptionModel->vendor_subscription_id;
        if (!$existingVendorSubId || strpos($existingVendorSubId, 'sub_') !== 0) {
            return null;
        }

        $remoteSub = (new API())->getStripeObject('subscriptions/' . $existingVendorSubId, [
            'expand' => [
                'latest_invoice.confirmation_secret',
                'latest_invoice.payment_intent',
                'pending_setup_intent'
            ]
        ], 'current');

        if (is_wp_error($remoteSub)) {
            return null;
        }

        $remoteStatus = Arr::get($remoteSub, 'status');

        if (in_array($remoteStatus, ['active', 'trialing'], true)) {
            // A $0 first invoice skips `incomplete`: the sub is `trialing` while the
            // card is still being set up via pending_setup_intent, and a failed 3DS
            // leaves it trialing with no payment method. Hand the setup intent back
            // so the buyer's retry can attach a card instead of being blocked.
            if (
                $remoteStatus === 'trialing'
                && !Arr::get($remoteSub, 'default_payment_method')
                && Arr::get($remoteSub, 'metadata.fct_ref_id') === $order->uuid
                && $this->remoteSubscriptionIsConfirmable($remoteSub, $requestData)
            ) {
                return $remoteSub;
            }

            (new StripeSubscriptions())->reSyncSubscriptionFromRemote($subscriptionModel);
            return new \WP_Error(
                'stripe_subscription_already_active',
                __('Subscription is already active. Please refresh this page to see the status instead of trying again.', 'fluent-cart')
            );
        }

        if (Arr::get($remoteSub, 'metadata.fct_ref_id') !== $order->uuid) {
            return null;
        }

        // Already canceled remotely (e.g. an earlier guard cancel whose replacement
        // create failed before it was recorded): mark it replaced and clear the
        // local id — the retry then recomputes the post-cancel key, and Stripe's
        // idempotent replay recovers any unrecorded replacement.
        if (in_array($remoteStatus, ['canceled', 'incomplete_expired'], true)) {
            $subscriptionModel->mergeConfig(['stripe_replaced_vendor_sub_id' => $existingVendorSubId]);
            $subscriptionModel->update(['vendor_subscription_id' => '']);
            return null;
        }

        if (!in_array($remoteStatus, ['incomplete', 'unpaid'], true)) {
            return null;
        }

        if ($remoteStatus === 'incomplete' && $this->remoteSubscriptionIsConfirmable($remoteSub, $requestData)) {
            return $remoteSub;
        }

        $cancelResponse = (new API())->deleteStripeObject('subscriptions/' . $existingVendorSubId, [], 'current');
        if (is_wp_error($cancelResponse)) {
            fluent_cart_warning_log(
                'Stripe stale ' . $remoteStatus . ' subscription cancel failed',
                $cancelResponse->get_error_message() . ' (' . $existingVendorSubId . ')',
                [
                    'module_type' => 'FluentCart\App\Models\Subscription',
                    'module_id'   => $subscriptionModel->id,
                    'module_name' => 'subscription',
                    'log_type'    => 'api'
                ]
            );

            return new \WP_Error(
                'stripe_subscription_cancel_failed',
                __('We could not update your previous subscription attempt. Please wait a moment and try again.', 'fluent-cart')
            );
        }

        // Marker before id-clear: a crash between the two leaves the id pointing at
        // the now-canceled sub, which the canceled branch above converges on retry.
        $subscriptionModel->mergeConfig(['stripe_replaced_vendor_sub_id' => $existingVendorSubId]);
        $subscriptionModel->update(['vendor_subscription_id' => '']);

        return null;
    }

    /**
     * The intent (payment or setup) must still be browser-confirmable AND the
     * subscription must bill exactly what this attempt would create — a cart
     * edited between attempts mints new Stripe price ids, and reusing the old
     * subscription would charge the wrong amount.
     */
    private function remoteSubscriptionIsConfirmable($remoteSub, $requestData)
    {
        if (!$requestData) {
            return false;
        }

        $confirmable = ['requires_payment_method', 'requires_confirmation', 'requires_action'];

        $intentStatus = Arr::get($remoteSub, 'latest_invoice.payment_intent.status');
        $clientSecret = Arr::get($remoteSub, 'latest_invoice.confirmation_secret.client_secret');
        if (!$intentStatus) {
            $intentStatus = Arr::get($remoteSub, 'pending_setup_intent.status');
            $clientSecret = Arr::get($remoteSub, 'pending_setup_intent.client_secret');
        }

        if (!in_array($intentStatus, $confirmable, true) || !$clientSecret) {
            return false;
        }

        return $this->subscriptionChargeMaterialMatches($remoteSub, $requestData);
    }

    /**
     * Recurring items are compared against `items.data`; one-off signup/addon
     * lines live only on the first invoice, so they are compared against
     * `latest_invoice.lines` when the invoice carries any — a $0 trial invoice
     * often carries none, and falling back to the item comparison there keeps
     * trial checkouts reusable instead of cancelling a live intent.
     */
    private function subscriptionChargeMaterialMatches($remoteSub, $requestData)
    {
        $wantedItems = [];
        foreach ((array)Arr::get($requestData, 'items', []) as $item) {
            $priceId = Arr::get($item, 'plan', Arr::get($item, 'price'));
            $wantedItems[] = (string)$priceId . ':' . (int)(Arr::get($item, 'quantity') ?: 1);
        }

        $remoteItems = [];
        foreach ((array)Arr::get($remoteSub, 'items.data', []) as $item) {
            $priceId = Arr::get($item, 'price.id', Arr::get($item, 'plan.id'));
            $remoteItems[] = (string)$priceId . ':' . (int)(Arr::get($item, 'quantity') ?: 1);
        }

        sort($wantedItems);
        sort($remoteItems);

        if (!$wantedItems || $wantedItems !== $remoteItems) {
            return false;
        }

        $remoteLines = [];
        foreach ((array)Arr::get($remoteSub, 'latest_invoice.lines.data', []) as $line) {
            $remoteLines[] = (string)Arr::get($line, 'price.id', Arr::get($line, 'plan.id'));
        }

        if (!$remoteLines) {
            return true;
        }

        $wantedLines = [];
        foreach ((array)Arr::get($requestData, 'items', []) as $item) {
            $wantedLines[] = (string)Arr::get($item, 'plan', Arr::get($item, 'price'));
        }
        foreach ((array)Arr::get($requestData, 'add_invoice_items', []) as $item) {
            $wantedLines[] = (string)Arr::get($item, 'price');
        }

        $remoteLines = array_values(array_unique($remoteLines));
        $wantedLines = array_values(array_unique($wantedLines));
    
        sort($remoteLines);
        sort($wantedLines);

        return $wantedLines === $remoteLines;
    }

    /**
     * One-time analogue of guardExistingRemoteSubscription(). A resubmit whose
     * charge-material params changed (or whose key aged past Stripe's 24h window)
     * would mint a second PaymentIntent while the first stays confirmable in any
     * stale tab — and a charge on that orphan is dropped by the webhook with no
     * local record. Succeeded remote: record the payment and stop the re-charge.
     * In-flight (processing / requires_capture): stop and let it settle.
     * Confirmable with matching charge-material params: reuse it. Mismatched:
     * cancel it so exactly one confirmable intent exists. Lookup/cancel failures
     * fail CLOSED (WP_Error, retryable) rather than falling through to create —
     * otherwise a transient Stripe error would let a second intent get created
     * while the first stays confirmable, reopening the orphan path this guards.
     *
     * Returns null (create fresh), the reusable intent array, a redirect response
     * array (already-succeeded — checkout's GET render has no order-status check,
     * so the caller must push the browser to the receipt page itself rather than
     * ask the customer to refresh), or WP_Error (stop, retryable).
     */
    private function guardExistingPaymentIntent(PaymentInstance $paymentInstance, $intentData)
    {
        $transaction = $paymentInstance->transaction;
        $existingIntentId = $transaction->vendor_charge_id;

        if (!$existingIntentId || strpos($existingIntentId, 'pi_') !== 0) {
            return null;
        }

        $existingIntent = (new API())->getStripeObject('payment_intents/' . $existingIntentId, [
            'expand' => ['latest_charge']
        ], 'current');

        if (is_wp_error($existingIntent)) {
            fluent_cart_warning_log(
                'Stripe existing payment intent lookup failed',
                $existingIntent->get_error_message() . ' (' . $existingIntentId . ')',
                [
                    'module_name' => 'order',
                    'module_id'   => $transaction->order_id,
                    'log_type'    => 'api'
                ]
            );
            return new \WP_Error(
                'stripe_payment_intent_lookup_failed',
                __('We could not verify your previous payment attempt. Please wait a moment and try again.', 'fluent-cart')
            );
        }

        $intentStatus = Arr::get($existingIntent, 'status');

        if ('succeeded' === $intentStatus) {
            $charge = Arr::get($existingIntent, 'latest_charge', []);
            (new Confirmations())->confirmPaymentSuccessByCharge($transaction, [
                'charge'    => is_array($charge) ? $charge : [],
                'intent_id' => $existingIntentId
            ]);

            // Local state is already synced to success — send the browser straight
            // to the receipt instead of erroring and telling the customer to refresh
            // a page that has no idea their order is paid.
            return [
                'fct_redirect' => true,
                'status'       => 'success',
                'redirect_to'  => $transaction->getSuccessUrl(),
                'message'      => __('Your payment has already been processed. Redirecting to your order...', 'fluent-cart')
            ];
        }

        if (in_array($intentStatus, ['processing', 'requires_capture'], true)) {
            return new \WP_Error(
                'stripe_payment_in_flight',
                __('Your previous payment attempt is still being processed. Please wait a moment before trying again — do not resubmit.', 'fluent-cart')
            );
        }

        if (in_array($intentStatus, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
            $chargeMaterialMatches = (int)Arr::get($existingIntent, 'amount') === (int)Arr::get($intentData, 'amount')
                && strtolower((string)Arr::get($existingIntent, 'currency')) === strtolower((string)Arr::get($intentData, 'currency'))
                && Arr::get($existingIntent, 'customer') === Arr::get($intentData, 'customer');

            if ($chargeMaterialMatches) {
                return $existingIntent;
            }

            $cancelResponse = (new API())->createStripeObject('payment_intents/' . $existingIntentId . '/cancel', [], 'current');
            if (is_wp_error($cancelResponse)) {
                fluent_cart_warning_log(
                    'Stripe stale payment intent cancel failed',
                    $cancelResponse->get_error_message() . ' (' . $existingIntentId . ')',
                    [
                        'module_name' => 'order',
                        'module_id'   => $transaction->order_id,
                        'log_type'    => 'api'
                    ]
                );
                return new \WP_Error(
                    'stripe_payment_intent_cancel_failed',
                    __('We could not update your previous payment attempt. Please wait a moment and try again.', 'fluent-cart')
                );
            }
        }

        return null;
    }


    /**
     * Handle single payment for stripe (onsite or hosted)
     *
     * @return \WP_Error|array
     */
    /**
     * Zero-payable system (auto-charged) subscription checkout — a free trial with
     * nothing to pay today. A $0 PaymentIntent is invalid, so the card is vaulted
     * via a SetupIntent instead; confirmation (Confirmations::confirmSetupIntent)
     * persists the token, completes the $0 order, and activates the trial. The
     * trial-end invoice is then charged off-session like any other system renewal.
     *
     * Consent is REQUIRED here (not just disclosed): without a saved card the
     * trial can never bill, so a checkout without the consent flag is rejected.
     */
    public function handleSetupOnlyPayment(PaymentInstance $paymentInstance, $paymentArgs = [])
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $order->customer;
        $billingAddress = $order->billing_address;

        $consent = sanitize_text_field(App::request()->get('_fct_system_consent', ''));
        if ($consent !== 'yes') {
            return new \WP_Error(
                'consent_required',
                __('Please agree to save your payment method for automatic renewal charges to start this subscription.', 'fluent-cart')
            );
        }

        $stripeCustomer = StripeHelper::createOrGetStripeCustomer($fcCustomer);
        if (is_wp_error($stripeCustomer)) {
            return $stripeCustomer;
        }

        $intentData = [
            'customer' => $stripeCustomer['id'],
            'usage'    => 'off_session',
            'automatic_payment_methods' => ['enabled' => 'true'],
            'metadata' => apply_filters('fluent_cart/payments/stripe_metadata_onetime', [
                'fct_ref_id'      => $order->uuid,
                'Name'            => $fcCustomer->full_name,
                'Email'           => $fcCustomer->email,
                'order_reference' => 'fct_order_id_' . $order->id,
            ], [
                'order'       => $order,
                'transaction' => $transaction
            ]),
        ];

        $intent = (new API())->createStripeObject('setup_intents', $intentData);

        if (is_wp_error($intent)) {
            return $intent;
        }

        // confirmSetupIntent() resolves the transaction by this id (and clears it
        // after confirmation — a setup intent id is not a charge id).
        $transaction->update([
            'vendor_charge_id' => $intent['id']
        ]);

        $paymentArgs['public_key'] = (new StripeSettingsBase())->getPublicKey();
        // The AJAX confirm endpoint requires the transaction hash for seti_ ids.
        $paymentArgs['trx_hash'] = $transaction->uuid;

        $customerData = [
            'name'      => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
            'email'     => $fcCustomer->email,
            'address_1' => $billingAddress ? $billingAddress->address_1 : '',
            'address_2' => $billingAddress ? $billingAddress->address_2 : '',
            'city'      => $billingAddress ? $billingAddress->city : '',
            'state'     => $billingAddress ? $billingAddress->state : '',
            'postcode'  => $billingAddress ? $billingAddress->postcode : '',
            'country'   => $billingAddress ? $billingAddress->country : ''
        ];

        return [
            'status'       => 'success',
            'nextAction'   => 'stripe',
            'actionName'   => 'custom',
            'message'      => __('Order has been placed successfully', 'fluent-cart'),
            'response'     => $intent,
            'payment_args' => $paymentArgs,
            'fc_customer'  => $customerData
        ];
    }

    /**
     * Hosted-checkout counterpart to handleSetupOnlyPayment() — hosted mode never
     * loads Stripe.js/Elements, so a zero-payable system-subscription checkout
     * redirects to a Checkout Session in `mode: setup` instead of a client-side
     * SetupIntent. The session's auto-created setup_intent id is stored as
     * vendor_charge_id so setup_intent.succeeded / confirmByCheckoutSession
     * resolve the transaction exactly like the onsite path.
     */
    public function handleHostedSetupOnlyCheckout(PaymentInstance $paymentInstance, $paymentArgs = [])
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $order->customer;

        $consent = sanitize_text_field(App::request()->get('_fct_system_consent', ''));
        if ($consent !== 'yes') {
            return new \WP_Error(
                'consent_required',
                __('Please agree to save your payment method for automatic renewal charges to start this subscription.', 'fluent-cart')
            );
        }

        $stripeCustomer = StripeHelper::createOrGetStripeCustomer($fcCustomer);
        if (is_wp_error($stripeCustomer)) {
            return $stripeCustomer;
        }

        $transactionCurrency = $transaction->currency;

        $sessionData = [
            'customer'            => $stripeCustomer['id'],
            'client_reference_id' => $order->uuid,
            'mode'                => 'setup',
            'currency'            => strtolower($transactionCurrency),
            'success_url'         => Processor::getHostedGatewayReturnUrl($transaction),
            'cancel_url'          => StripeHelper::getCancelUrl(),
            'metadata'            => [
                'fct_ref_id'       => $order->uuid,
                'transaction_hash' => $transaction->uuid,
                'order_reference'  => 'fct_order_id_' . $order->id,
            ],
        ];

        $sessionData = apply_filters('fluent_cart/payments/stripe_checkout_session_args', $sessionData, [
            'order'       => $order,
            'transaction' => $transaction
        ]);

        // Same duplicate-charge defense as every other Stripe create path.
        $idempotencyFingerprint = [
            'customer' => Arr::get($sessionData, 'customer'),
            'mode'     => Arr::get($sessionData, 'mode'),
            'currency' => Arr::get($sessionData, 'currency'),
        ];
        $idempotencySeed = $paymentInstance->getIdempotencySeed();
        $idempotencyKey = $idempotencySeed
            ? 'fct_stripe_cs_' . md5($idempotencySeed . '|' . wp_json_encode($idempotencyFingerprint))
            : null;

        $session = (new API())->createStripeObject('checkout/sessions', $sessionData, 'current', [
            'Idempotency-Key' => $idempotencyKey
        ]);

        if (is_wp_error($session)) {
            return $session;
        }

        // confirmSetupIntent() resolves the transaction by this id (and clears it
        // after confirmation — a setup intent id is not a charge id).
        $transaction->update([
            'vendor_charge_id' => Arr::get($session, 'setup_intent'),
            'meta'             => array_merge($transaction->meta ?? [], [
                'session_id' => $session['id']
            ])
        ]);

        return [
            'status'       => 'success',
            'nextAction'   => 'stripe',
            'actionName'   => 'redirect',
            'message'      => __('Redirecting to Stripe checkout...', 'fluent-cart'),
            'response'     => $session,
            'payment_args' => array_merge($paymentArgs, [
                'checkout_url' => $session['url'],
                'session_id'   => $session['id']
            ])
        ];
    }

    public function handleSinglePayment(PaymentInstance $paymentInstance, $paymentArgs = [])
    {
        $stripeSettings = new StripeSettingsBase();
        $checkoutMode = $stripeSettings->get('checkout_mode') ?? 'onsite';

        if ($checkoutMode === 'hosted') {
            return $this->handleHostedCheckout($paymentInstance, $paymentArgs);
        }

        // Original onsite payment flow
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $paymentInstance->order->customer;
        $billingAddress = $order->billing_address;

        $transactionCurrency = $transaction->currency;
        $intentAmount = (int)$transaction->total;

        if ($transactionCurrency && CurrenciesHelper::isZeroDecimal($transactionCurrency)) {
            $intentAmount = (int)($intentAmount / 100);
        }

        $intentData = [
            'amount'                    => $intentAmount,
            'currency'                  => $transactionCurrency,
            'automatic_payment_methods' => ['enabled' => 'true'],
            'metadata'                  => apply_filters('fluent_cart/payments/stripe_metadata_onetime', [
                'fct_ref_id' => $order->uuid,
                'Name'       => $order->customer->full_name,
                'Email'      => $order->customer->email,
                'order_reference' => 'fct_order_id_' . $paymentInstance->order->id,
            ], [
                'order'       => $order,
                'transaction' => $transaction
            ]),
        ];

        $itemCount = 1;
        foreach($paymentInstance->order->order_items as $item) {
            $intentData['metadata']['item ' . $itemCount] = 'Name: ' . $item->title . ', ' . 'Qty: ' . $item->quantity . ', Price: ' . Helper::toDecimal($item->line_total, false, null, true, true, false);
            if (count($intentData['metadata']) > 49) {
                break;
            }
            $itemCount++;
        }

        if (!empty($paymentArgs['customer'])) {
            $intentData['customer'] = $paymentArgs['customer'];
        } else {
            $stripeCustomer = StripeHelper::createOrGetStripeCustomer($order->customer);
            if (is_wp_error($stripeCustomer)) {
                return $stripeCustomer;
            }
            $intentData['customer'] = $stripeCustomer['id'];
        }

        if (!empty($paymentArgs['setup_future_usage'])) {
            $intentData['setup_future_usage'] = $paymentArgs['setup_future_usage'];
        }

        $paymentArgs['public_key'] = (new StripeSettingsBase())->getPublicKey();

        $intentData = apply_filters('fluent_cart/payments/stripe_onetime_intent_args', $intentData, [
            'order'       => $order,
            'transaction' => $transaction
        ]);

        // Reuse or retire any intent this transaction already holds — the idempotency
        // key alone cannot cover a resubmit whose charge-material params changed or
        // whose key aged out of Stripe's 24h window.
        $intent = $this->guardExistingPaymentIntent($paymentInstance, $intentData);
        if (is_wp_error($intent)) {
            return $intent;
        }

        if (!empty($intent['fct_redirect'])) {
            return $intent;
        }

        if (!$intent) {
            // Same duplicate-charge defense for one-time onsite payments. Customer is in
            // the fingerprint because a guest editing their email between attempts maps to
            // a different Stripe customer — same key there would 400 for the key's 24h
            // lifetime. Built AFTER the intent-args filter so filtered amounts are what
            // get fingerprinted.
            $idempotencyFingerprint = [
                'amount'   => Arr::get($intentData, 'amount'),
                'currency' => Arr::get($intentData, 'currency'),
                'customer' => Arr::get($intentData, 'customer'),
            ];
            $idempotencySeed = $paymentInstance->getIdempotencySeed();
            $idempotencyKey = $idempotencySeed
                ? 'fct_stripe_pi_' . md5($idempotencySeed . '|' . wp_json_encode($idempotencyFingerprint))
                : null;

            $intent = (new API())->createStripeObject('payment_intents', $intentData, 'current', [
                'Idempotency-Key' => $idempotencyKey
            ]);

            if (is_wp_error($intent)) {
                return $intent;
            }

            $transaction->update([
                'vendor_charge_id' => $intent['id']
            ]);
        }

        $customerData = [
            'name'      => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
            'email'     => $fcCustomer->email,
            'address_1' => $billingAddress->address_1,
            'address_2' => $billingAddress->address_2,
            'city'      => $billingAddress->city,
            'state'     => $billingAddress->state,
            'postcode'  => $billingAddress->postcode,
            'country'   => $billingAddress->country
        ];

        return [
            'status'       => 'success',
            'nextAction'   => 'stripe',
            'actionName'   => 'custom',
            'message'      => __('Order has been placed successfully', 'fluent-cart'),
            'response'     => $intent,
            'payment_args' => $paymentArgs,
            'fc_customer'  => $customerData
        ];
    }


    private function handleHostedCheckout(PaymentInstance $paymentInstance, $paymentArgs = [])
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $order->customer;
        $billingAddress = $order->billing_address;

        $transactionCurrency = $transaction->currency;
        $chargeAmount = (int)$transaction->total;

        if ($transactionCurrency && CurrenciesHelper::isZeroDecimal($transactionCurrency)) {
            $chargeAmount = (int)($chargeAmount / 100);
        }

        // Create or get Stripe customer
        $stripeCustomer = StripeHelper::createOrGetStripeCustomer($fcCustomer);
        if (is_wp_error($stripeCustomer)) {
            return $stripeCustomer;
        }

        // Per-item breakdown when it reconciles exactly to the charge amount,
        // otherwise the historical single aggregate line. buildHostedLineItems()
        // returns null for anything it cannot prove sums to $chargeAmount — the
        // fallback is always a correct charge, just a less itemised one.
        $lineItems = $this->buildHostedLineItems($order, $transactionCurrency, $chargeAmount);
        $usedBreakdown = $lineItems !== null;

        if (!$usedBreakdown) {
            $lineItems = $this->aggregateHostedLineItems($order, $transactionCurrency, $chargeAmount);
        }

        $sessionData = [
            'customer'           => $stripeCustomer['id'],
            'client_reference_id' => $order->uuid,
            'line_items'         => $lineItems,
            'mode'               => 'payment',
            'success_url'        => Processor::getHostedGatewayReturnUrl($transaction),
            'cancel_url'         => StripeHelper::getCancelUrl(),
            'metadata'           => [
                'fct_ref_id'      => $order->uuid,
                'transaction_hash' => $transaction->uuid,
                'order_reference' => 'fct_order_id_' . $order->id,
            ],
        ];

        // Same vaulting contract as the onsite intent path (see setup_future_usage
        // above) — a mode: payment Checkout Session only saves the card when this
        // is set on payment_intent_data.
        if (!empty($paymentArgs['setup_future_usage'])) {
            $sessionData['payment_intent_data'] = [
                'setup_future_usage' => $paymentArgs['setup_future_usage'],
            ];
        }

        $submitType = (new StripeSettingsBase())->getSubmitType();
        if ($submitType && in_array($submitType, ['auto', 'book', 'donate', 'pay'], true)) {
            $sessionData['submit_type'] = $submitType;
        }

        $itemCount = 1;
        foreach($order->order_items as $item) {
            $sessionData['metadata']['item ' . $itemCount] = 'Name: ' . $item->title . ', ' . 'Qty: ' . $item->quantity . ', Price: ' . Helper::toDecimal($item->line_total, false, null, true, true, false);
            if (count($sessionData['metadata']) > 49) {
                break;
            }
            
            $itemCount++;
        }

        // Kept unfiltered so the aggregate retry below can re-derive the body from
        // the same base rather than editing a filtered one underneath its author.
        $baseSessionData = $sessionData;

        $sessionData = apply_filters('fluent_cart/payments/stripe_checkout_session_args', $sessionData, [
            'order'       => $order,
            'transaction' => $transaction
        ]);

        $idempotencySeed = $paymentInstance->getIdempotencySeed();
        $idempotencyKey = $this->hostedSessionIdempotencyKey($idempotencySeed, $sessionData);

        $session = (new API())->createStripeObject('checkout/sessions', $sessionData, 'current', [
            'Idempotency-Key' => $idempotencyKey
        ]);

        // A breakdown adds validation surface the single aggregate line does not
        // have (line-item cap, product naming, per-item amounts). If Stripe rejects
        // the itemised body, fall back to the aggregate rather than failing the
        // buyer's checkout. Only a structural rejection qualifies: a transport
        // failure may mean the session was in fact created, and retrying that under
        // a different key would abandon the idempotency guarantee for no reason.
        if ($usedBreakdown && $this->isStripeValidationError($session)) {
            fluent_cart_warning_log(
                'Stripe checkout line-item breakdown rejected',
                'Stripe rejected the itemised checkout session; retrying with a single aggregate line item. Reason: ' . $session->get_error_message(),
                [
                    'module_name' => 'order',
                    'module_id'   => $order->id,
                    'log_type'    => 'api'
                ]
            );

            // Re-filter the aggregate body instead of swapping line_items inside the
            // filtered one: a subscriber that derives anything from line_items (per
            // line tax_rates, automatic_tax, its own totals) decided that against the
            // itemised body, and editing underneath it leaves the two disagreeing.
            // Rebuilt from the unfiltered base so a subscriber that appends rather
            // than replaces does not apply twice.
            $baseSessionData['line_items'] = $this->aggregateHostedLineItems($order, $transactionCurrency, $chargeAmount);

            $sessionData = apply_filters('fluent_cart/payments/stripe_checkout_session_args', $baseSessionData, [
                'order'       => $order,
                'transaction' => $transaction
            ]);

            $idempotencyKey = $this->hostedSessionIdempotencyKey($idempotencySeed, $sessionData);

            $session = (new API())->createStripeObject('checkout/sessions', $sessionData, 'current', [
                'Idempotency-Key' => $idempotencyKey
            ]);
        }

        if (is_wp_error($session)) {
            return $session;
        }

        $transaction->update([
            'meta'             => array_merge($transaction->meta ?? [], [
                'session_id' => $session['id']
            ])
        ]);

        return [
            'status'       => 'success',
            'nextAction'   => 'stripe',
            'actionName'   => 'redirect',
            'message'      => __('Redirecting to Stripe checkout...', 'fluent-cart'),
            'response'     => $session,
            'payment_args' => array_merge($paymentArgs, [
                'checkout_url' => $session['url'],
                'session_id'   => $session['id']
            ])
        ];
    }


    /**
     * Itemised line_items for a hosted (mode: payment) Checkout Session.
     *
     * Unlike PayPal's purchase_unit — where we declare amount.value and the
     * breakdown merely has to agree with it — Stripe DERIVES the session total
     * from line_items. There is no total to assert and no subtractive field
     * (order-level discounts need a Coupon object; negative unit_amount is
     * rejected). So the sum of what we send IS what the buyer is charged, and a
     * breakdown that is a cent off does not error, it mischarges.
     *
     * Everything is therefore reconciled against $chargeAmount before returning,
     * in wire units, and null is returned for any order this cannot prove exact.
     * The caller then sends a single aggregate line — always the correct amount.
     *
     * Discounts need no line of their own: order_items.line_total is already
     * subtotal minus discount_total (CheckoutProcessor::116), so item-level
     * discounts are baked into the per-unit price.
     *
     * @param \FluentCart\App\Models\Order $order
     * @param string $currency
     * @param int    $chargeAmount Charge total in wire units (already divided for zero-decimal).
     * @return array|null Null when no exact breakdown is possible.
     */
    /**
     * Duplicate-charge defense for a hosted payment session: a pure duplicate
     * replays the key and gets the original session back, while an edited-cart
     * resubmit gets a fresh key instead of a same-key/changed-parameters 400.
     *
     * Derived from the FILTERED body, so a subscriber that changes what is
     * actually charged changes the key with it. Metadata is excluded — a
     * volatile metadata filter must not roll the key on a genuine duplicate.
     *
     * @param string|null $seed
     * @param array $sessionData
     * @return string|null
     */
    private function hostedSessionIdempotencyKey($seed, $sessionData)
    {
        if (!$seed) {
            return null;
        }

        $fingerprint = [
            'customer'   => Arr::get($sessionData, 'customer'),
            'line_items' => Arr::get($sessionData, 'line_items'),
            'mode'       => Arr::get($sessionData, 'mode'),
        ];

        return 'fct_stripe_cs_' . md5($seed . '|' . wp_json_encode($fingerprint));
    }

    private function buildHostedLineItems($order, $currency, $chargeAmount)
    {
        $isZeroDecimal = $currency && CurrenciesHelper::isZeroDecimal($currency);
        $stripeCurrency = strtolower($currency);

        $lineItems = [];
        $sum = 0;

        // The relation is materialised on this request either way (the metadata
        // loop below walks it), so reuse it instead of issuing a second query.
        // Deterministic order: the idempotency fingerprint hashes line_items, so a
        // varying sequence would roll the key on a genuine duplicate submission.
        $orderItems = $order->order_items->sortBy('id')->values();

        // Decline before building anything the cap would discard.
        if ($orderItems->count() > self::MAX_HOSTED_LINE_ITEMS) {
            return null;
        }

        foreach ($orderItems as $item) {
            $quantity = (int) $item->quantity;
            if ($quantity < 1) {
                $quantity = 1;
            }

            $lineTotal = $this->toStripeWireAmount($item->line_total, $isZeroDecimal);

            // A zero line contributes nothing to the total and Stripe has no use
            // for a zero-priced row here; skipping keeps us under the item cap.
            if ($lineTotal <= 0) {
                continue;
            }

            $unitAmount = intdiv($lineTotal, $quantity);
            if ($unitAmount <= 0) {
                continue;
            }

            $name = $this->hostedLineItemName($item);
            if ($name === '') {
                return null; // Stripe requires a non-empty product name.
            }

            if (count($lineItems) >= self::MAX_HOSTED_LINE_ITEMS) {
                return null;
            }

            $lineItems[] = [
                'price_data' => [
                    'currency'     => $stripeCurrency,
                    'product_data' => [
                        'name' => $name,
                    ],
                    'unit_amount'  => $unitAmount,
                ],
                'quantity'   => $quantity,
            ];

            // intdiv floors, so any per-unit remainder is left for the
            // reconciliation line below rather than silently inflating the charge.
            $sum += $unitAmount * $quantity;
        }

        if (!$lineItems) {
            return null;
        }

        $shipping = $this->toStripeWireAmount($order->shipping_total, $isZeroDecimal);
        if ($shipping > 0) {
            $lineItems[] = $this->hostedFlatLineItem(__('Shipping', 'fluent-cart'), $stripeCurrency, $shipping);
            $sum += $shipping;
        }

        // Only tax the buyer pays ON TOP of item prices belongs here — inclusive
        // tax is already inside line_total and adding it would double-charge.
        $tax = $this->toStripeWireAmount($this->additiveTaxTotal($order), $isZeroDecimal);
        if ($tax > 0) {
            $lineItems[] = $this->hostedFlatLineItem(__('Tax', 'fluent-cart'), $stripeCurrency, $tax);
            $sum += $tax;
        }

        if ($sum > $chargeAmount) {
            return null; // Cannot subtract without a Coupon object; aggregate instead.
        }

        if ($sum < $chargeAmount) {
            $lineItems[] = $this->hostedFlatLineItem(
                __('Adjustment', 'fluent-cart'),
                $stripeCurrency,
                $chargeAmount - $sum
            );
            $sum = $chargeAmount;
        }

        if ($sum !== $chargeAmount || count($lineItems) > self::MAX_HOSTED_LINE_ITEMS) {
            return null;
        }

        return $lineItems;
    }

    /**
     * The historical single-line body: one item worth the whole charge. Always a
     * correct amount, and the fallback for every path the breakdown declines.
     *
     * @param \FluentCart\App\Models\Order $order
     * @param string $currency
     * @param int    $chargeAmount
     * @return array
     */
    private function aggregateHostedLineItems($order, $currency, $chargeAmount)
    {
        $storeName = (new \FluentCart\Api\StoreSettings())->get('store_name');

        return [
            [
                'price_data' => [
                    'currency'     => strtolower($currency),
                    'product_data' => [
                        'name'        => $storeName . ' - Order #' . $order->uuid,
                        'description' => __('Order total including all items, shipping (If any), and taxes (If any)', 'fluent-cart'),
                    ],
                    'unit_amount'  => $chargeAmount,
                ],
                'quantity'   => 1,
            ]
        ];
    }

    /**
     * @param string $name
     * @param string $stripeCurrency
     * @param int    $amount
     * @return array
     */
    private function hostedFlatLineItem($name, $stripeCurrency, $amount)
    {
        return [
            'price_data' => [
                'currency'     => $stripeCurrency,
                'product_data' => [
                    'name' => $name,
                ],
                'unit_amount'  => $amount,
            ],
            'quantity'   => 1,
        ];
    }

    /**
     * @param \FluentCart\App\Models\OrderItem $item
     * @return string
     */
    private function hostedLineItemName($item)
    {
        $name = trim($item->post_title . ' ' . $item->title);

        if ($name === '') {
            return '';
        }

        if (function_exists('mb_substr') && mb_strlen($name) > 250) {
            return mb_substr($name, 0, 247) . '...';
        }

        if (strlen($name) > 250) {
            return substr($name, 0, 247) . '...';
        }

        return $name;
    }

    /**
     * Storage cents to the units Stripe is charged in. Every part of the
     * breakdown is converted individually and the caller reconciles the SUM
     * against the converted charge total — converting after summing would
     * disagree with Stripe, which only ever sees the per-item figures.
     *
     * @param mixed $cents
     * @param bool  $isZeroDecimal
     * @return int
     */
    private function toStripeWireAmount($cents, $isZeroDecimal)
    {
        $cents = Helper::roundCent($cents);

        return $isZeroDecimal ? intdiv($cents, 100) : $cents;
    }

    /**
     * Tax the buyer pays on top of item prices, in storage cents.
     *
     * Mirrors the PayPal purchase-unit breakdown (PayPalGateway/Processor.php):
     * behaviour 1 is fully exclusive, 3 is mixed (only the exclusive portion is
     * additive, with shipping and fee tax additive only when the store itself is
     * exclusive), anything else is inclusive and contributes nothing.
     *
     * @param \FluentCart\App\Models\Order $order
     * @return int
     */
    private function additiveTaxTotal($order)
    {
        $taxBehavior       = (int) $order->tax_behavior;
        $exclusiveTaxTotal = (int) $order->getMeta('exclusive_tax_total');
        $storeTaxBehavior  = (int) $order->getMeta('store_tax_behavior');
        $feeTax            = (int) $order->getMeta('fee_tax');

        // Fallback: if meta missing (old order), use tax_behavior as store_tax_behavior
        if (empty($storeTaxBehavior) && $taxBehavior > 0) {
            $storeTaxBehavior = $taxBehavior;
        }

        if ($taxBehavior === 1) {
            return Helper::roundCent($order->tax_total) + Helper::roundCent($order->shipping_tax);
        }

        if ($taxBehavior === 3) {
            $taxTotal = $exclusiveTaxTotal;

            if ($storeTaxBehavior === 1) {
                $taxTotal += Helper::roundCent($order->shipping_tax);
                $taxTotal += $feeTax;
            }

            return $taxTotal;
        }

        return 0;
    }

    /**
     * True only for a structural rejection of the request body (Stripe
     * `invalid_request_error`). A transport failure is deliberately excluded: the
     * session may have been created, and retrying under a different idempotency
     * key would give up the duplicate protection that key exists for.
     *
     * @param mixed $response
     * @return bool
     */
    private function isStripeValidationError($response)
    {
        if (!is_wp_error($response) || $response->get_error_code() !== 'api_error') {
            return false;
        }

        $body = $response->get_error_data();

        return is_array($body) && Arr::get($body, 'error.type') === 'invalid_request_error';
    }


    /**
     * The one-time part of a hosted subscription session, itemised.
     *
     * `$initialAmount` is a bundle of up to four unrelated things — a merchant
     * setup fee, one-time cart items, order fees, and a synthetic first-cycle
     * delta the plan price cannot carry — so a single line can only ever be
     * labelled correctly for one of them. Everything the order itemises gets its
     * own line; whatever is left over (tax, the delta) becomes one remainder line
     * named for the case that produced it.
     *
     * Returns null when the breakdown cannot be reconciled to `$initialAmount`,
     * which sends the caller to the aggregate single line.
     *
     * @param PaymentInstance $paymentInstance
     * @param string $stripeCurrency
     * @param bool   $isZeroDecimal
     * @param int    $initialAmount Wire amount, already converted.
     * @param int    $existingCount Lines already in the session body.
     * @return array|null
     */
    private function buildHostedSubscriptionInitialLineItems(PaymentInstance $paymentInstance, $stripeCurrency, $isZeroDecimal, $initialAmount, $existingCount)
    {
        $order = $paymentInstance->order;

        $lineItems = [];
        $sum = 0;

        // Already materialised by getExtraAddonAmount() before this runs, so reuse
        // the relation rather than querying again. Deterministic order: the
        // idempotency fingerprint hashes line_items.
        $orderItems = $order->order_items->sortBy('id')->values();

        // Decline before building anything the cap would discard.
        if ($existingCount + $orderItems->count() > self::MAX_HOSTED_LINE_ITEMS) {
            return null;
        }

        foreach ($orderItems as $item) {
            if ($item->payment_type === 'subscription') {
                continue; // Carried by the recurring price.
            }

            $lineTotal = $this->toStripeWireAmount($item->line_total, $isZeroDecimal);
            if ($lineTotal <= 0) {
                continue;
            }

            $quantity = (int)$item->quantity;
            if ($quantity < 1) {
                $quantity = 1;
            }

            $unitAmount = intdiv($lineTotal, $quantity);
            if ($unitAmount <= 0) {
                continue;
            }

            // A signup-fee row already carries the merchant's own label in
            // `title` (other_info.signup_fee_name), a fee row the fee label.
            $name = $this->hostedLineItemName($item);

            if ($name === '') {
                return null;
            }

            if ($existingCount + count($lineItems) >= self::MAX_HOSTED_LINE_ITEMS) {
                return null;
            }

            $lineItems[] = [
                'price_data' => [
                    'currency'     => $stripeCurrency,
                    'product_data' => [
                        'name' => $name,
                    ],
                    'unit_amount'  => $unitAmount,
                ],
                'quantity'   => $quantity,
            ];

            $sum += $unitAmount * $quantity;
        }

        if ($sum > $initialAmount) {
            return null; // Cannot subtract without a Coupon object.
        }

        if ($sum < $initialAmount) {
            $remainder = $initialAmount - $sum;

            // No itemised part at all means the whole amount IS the first-cycle
            // delta, and it gets the case name rather than a tax label.
            $name = $lineItems
                ? __('Taxes & adjustments', 'fluent-cart')
                : $this->hostedSubscriptionInitialName($order, $paymentInstance->subscription);

            if ($existingCount + count($lineItems) >= self::MAX_HOSTED_LINE_ITEMS) {
                return null;
            }

            $lineItems[] = $this->hostedFlatLineItem($name, $stripeCurrency, $remainder);
            $sum += $remainder;
        }

        if (!$lineItems || $sum !== $initialAmount) {
            return null;
        }

        return $lineItems;
    }

    /**
     * Label for a one-time amount that the order does not itemise.
     *
     * A configured setup fee owns the label when one exists. Otherwise the amount
     * is a first-cycle delta computed in CheckoutProcessor::convertToSubscriptionFormat():
     * with a simulated trial the recurring line charges nothing now, so this line
     * is the whole first payment; without one it is only the excess over recurring.
     *
     * @param \FluentCart\App\Models\Order $order
     * @param \FluentCart\App\Models\Subscription|null $subscriptionModel
     * @return string
     */
    private function hostedSubscriptionInitialName($order, $subscriptionModel)
    {
        $signupFeeItem = $order->order_items->first(function ($item) {
            return $item->payment_type === 'signup_fee';
        });

        if ($signupFeeItem) {
            $name = $this->hostedLineItemName($signupFeeItem);

            if ($name !== '') {
                return $name;
            }
        }

        $simulatedTrial = $subscriptionModel
            && Arr::get($subscriptionModel->config, 'is_trial_days_simulated', 'no') === 'yes';

        return $simulatedTrial
            ? __('First payment', 'fluent-cart')
            : __('First payment adjustment', 'fluent-cart');
    }

    private function handleHostedSubscriptionCheckout(PaymentInstance $paymentInstance, $paymentArgs = [])
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $subscriptionModel = $paymentInstance->subscription;
        $fcCustomer = $order->customer;

        if (!$subscriptionModel) {
            return new \WP_Error('no_subscription', __('No subscription found.', 'fluent-cart'));
        }

        // No request body: a hosted Checkout Session mints its own subscription,
        // so nothing is reusable here.
        $guardError = $this->guardExistingRemoteSubscription($subscriptionModel, $order);
        if (is_wp_error($guardError)) {
            return $guardError;
        }

        $transactionCurrency = $transaction->currency;
        $orderType = $order->type;

        // Create or get Stripe customer
        $stripeCustomer = StripeHelper::createOrGetStripeCustomer($fcCustomer);
        if (is_wp_error($stripeCustomer)) {
            return $stripeCustomer;
        }

        // Get or create Stripe price/plan
        if ($orderType == 'renewal') {
            $stripePlan = Plan::getStripePricing([
                'product_id'       => $subscriptionModel->product_id,
                'variation_id'     => $subscriptionModel->variation_id,
                'billing_interval' => $subscriptionModel->billing_interval,
                'recurring_total'  => $subscriptionModel->getCurrentRenewalAmount(),
                'currency'         => $order->currency,
                'trial_days'       => $subscriptionModel->getReactivationTrialDays(),
                'interval_count'   => 1,
                'order_id'         => $subscriptionModel->parent_order_id,
            ]);
        } else {
            $stripePlan = Plan::getStripePricing([
                'product_id'       => $subscriptionModel->product_id,
                'variation_id'     => $subscriptionModel->variation_id,
                'billing_interval' => $subscriptionModel->billing_interval,
                'recurring_total'  => $subscriptionModel->recurring_total,
                'currency'         => $order->currency,
                'trial_days'       => (int)$subscriptionModel->trial_days,
                'interval_count'   => 1,
                'order_id'         => $subscriptionModel->parent_order_id,
            ]);
        }

        if (is_wp_error($stripePlan)) {
            return $stripePlan;
        }


        $feeTotal = $orderType !== 'renewal' ? (int)$paymentInstance->order->fee_total : 0;
        $initialAmount = (int)$subscriptionModel->signup_fee + $paymentInstance->getExtraAddonAmount() + $feeTotal;

        if ($orderType == 'renewal') {
            $initialAmount = 0;
        }

        $recurringTotal = (int)$subscriptionModel->recurring_total;
        $isZeroDecimal = $transactionCurrency && CurrenciesHelper::isZeroDecimal($transactionCurrency);
        if ($isZeroDecimal) {
            $initialAmount = (int)($initialAmount / 100);
            $recurringTotal = (int)($recurringTotal / 100);
        }

        $lineItems = [
            [
                'price'    => $stripePlan['id'],
                'quantity' => $subscriptionModel->quantity ?: 1,
            ]
        ];

        $subscriptionData = [
            'metadata' => [
                'fct_ref_id'      => $order->uuid,
                'email'           => $fcCustomer->email,
                'name'            => $order->full_name,
                'order_reference' => 'fct_order_id_' . $order->id,
                'subscription_item'            => $subscriptionModel->item_name,
            ],
        ];

        // Handle trial period if set in plan (same as onsite lines 94-96)
        if (!empty($stripePlan['trial_period_days'])) {
            $subscriptionData['trial_period_days'] = $stripePlan['trial_period_days'];
        }

        // can add billing cycle anchor config here, if we allow billing anchor in fluent-cart subscription

        if ($initialAmount > 0) {
            $stripeCurrency = strtolower($order->currency);

            $initialItems = $this->buildHostedSubscriptionInitialLineItems(
                $paymentInstance,
                $stripeCurrency,
                $isZeroDecimal,
                (int)$initialAmount,
                count($lineItems)
            );

            if ($initialItems === null) {
                $initialItems = [
                    $this->hostedFlatLineItem(
                        $this->hostedSubscriptionInitialName($order, $subscriptionModel),
                        $stripeCurrency,
                        (int)$initialAmount
                    )
                ];
            }

            $lineItems = array_merge($lineItems, $initialItems);
        }

        $sessionData = [
            'customer'            => $stripeCustomer['id'],
            'client_reference_id' => $order->uuid,
            'line_items'          => $lineItems,
            'mode'                => 'subscription',
            'consent_collection' => ['payment_method_reuse_agreement' => ['position' => 'hidden']],
            'success_url'         => Processor::getHostedGatewayReturnUrl($transaction),
            'cancel_url'          => StripeHelper::getCancelUrl(),
            'subscription_data'   => $subscriptionData,
            'saved_payment_method_options' => [
                'payment_method_save' => 'enabled'
            ],
            'metadata'            => [
                'fct_ref_id'         => $order->uuid,
                'subscription_item'  => $subscriptionModel->item_name,
                'transaction_hash'   => $transaction->uuid,
                'order_reference'    => 'fct_order_id_' . $order->id,
            ],
        ];

        $submitType = (new StripeSettingsBase())->getSubmitType();
        if ($submitType && in_array($submitType, ['donate', 'subscribe', 'auto'], true)) {
            $sessionData['submit_type'] = $submitType;
        }

        $sessionData = apply_filters('fluent_cart/payments/stripe_subscription_checkout_session_args', $sessionData, [
            'order'        => $order,
            'transaction'  => $transaction,
            'subscription' => $subscriptionModel
        ]);

        // Same duplicate-subscription defense as the onsite path, applied to the hosted
        // Checkout Session. Metadata is excluded so a volatile metadata filter cannot
        // change the key on a genuine duplicate and reopen the double-charge window.
        $idempotencyFingerprint = [
            'customer'          => Arr::get($sessionData, 'customer'),
            'line_items'        => Arr::get($sessionData, 'line_items'),
            'mode'              => Arr::get($sessionData, 'mode'),
            'subscription_data' => Arr::get($sessionData, 'subscription_data'),
            // See the onsite path: rolls the key after a guard cancel, read from
            // the persisted marker so retries recompute the same key.
            'replaces'          => (string)Arr::get(
                (array)$subscriptionModel->config,
                'stripe_replaced_vendor_sub_id',
                ''
            ),
        ];
        $idempotencySeed = $paymentInstance->getIdempotencySeed();
        $idempotencyKey = $idempotencySeed
            ? 'fct_stripe_sub_cs_' . md5($idempotencySeed . '|' . wp_json_encode($idempotencyFingerprint))
            : null;

        $session = (new API())->createStripeObject('checkout/sessions', $sessionData, 'current', [
            'Idempotency-Key' => $idempotencyKey
        ]);

        if (is_wp_error($session)) {
            return $session;
        }

        $subscriptionModel->update([
            'vendor_customer_id'     => $stripeCustomer['id']
        ]);

        $transaction->update([
            'vendor_charge_id' => Arr::get($session, 'payment_intent', Arr::get($session, 'id')),
            'meta'             => array_merge($transaction->meta ?? [], [
                'session_id' => $session['id']
            ])
        ]);

        return [
            'status'       => 'success',
            'nextAction'   => 'stripe',
            'actionName'   => 'redirect',
            'message'      => __('Redirecting to Stripe checkout...', 'fluent-cart'),
            'response'     => $session,
            'payment_args' => array_merge($paymentArgs, [
                'checkout_url' => $session['url'],
                'session_id'   => $session['id']
            ])
        ];
    }

}
