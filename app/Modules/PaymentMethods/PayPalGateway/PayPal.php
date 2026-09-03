<?php

namespace FluentCart\App\Modules\PaymentMethods\PayPalGateway;

use FluentCart\Api\CurrencySettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\CartCheckoutHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Hooks\Cart\WebCheckoutHandler;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\API;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\Webhook;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class PayPal extends AbstractPaymentGateway
{

    private $methodSlug = 'paypal';

    public array $supportedFeatures = ['payment', 'refund', 'webhook', 'custom_payment', 'card_update', 'switch_payment_method' => [
        'supported_gateways' => ['stripe', 'paypal'],
    ], 'dispute_handler', 'subscriptions', 'resume_subscription', 'system_subscription', 'manual_subscription', 'verify_vendor_ids'];

    private $vaultUserIdToken = '';

    private $vaultSetupUnavailable = false;


    public function __construct()
    {
        parent::__construct(
            new PayPalSettingsBase(),
            new PayPalSubscriptions()
        );

        add_filter('fluent_cart/payment_methods_with_custom_checkout_buttons', function ($methods) {
            $methods[] = 'paypal';
            return $methods;
        });
    }

    public function meta(): array
    {
        return [
            'title'       => 'PayPal',
            'route'       => 'paypal',
            'slug'        => 'paypal',
            'label'       => 'PayPal',
            'description' => __('PayPal is the faster, safer way to send and receive money or make an online payment. Get started or create a merchant account to accept payments.', 'fluent-cart'),
            'logo'        => Vite::getAssetUrl("images/payment-methods/paypal-icon.svg"),
            'icon'        => Vite::getAssetUrl("images/payment-methods/paypal-icon.svg"),
            'brand_color' => '#60cdff',
            'status'      => $this->settings->get('is_active') === 'yes',
            'upcoming'    => false,
            'supported_features' => $this->supportedFeatures
        ];
    }

    public function boot()
    {
        (new IPN())->init();

        add_action('wp_ajax_nopriv_fluent_cart_confirm_paypal_payment', [$this, 'confirmPayPalSinglePayment']);
        add_action('wp_ajax_fluent_cart_confirm_paypal_payment', [$this, 'confirmPayPalSinglePayment']);

        add_action('wp_ajax_nopriv_fluent_cart_confirm_paypal_subscription', [$this, 'confirmPayPalSubscription']);
        add_action('wp_ajax_fluent_cart_confirm_paypal_subscription', [$this, 'confirmPayPalSubscription']);

        add_action('wp_ajax_nopriv_fluent_cart_confirm_paypal_vault_setup', [$this, 'confirmPayPalVaultSetup']);
        add_action('wp_ajax_fluent_cart_confirm_paypal_vault_setup', [$this, 'confirmPayPalVaultSetup']);

        add_filter('fluent_cart/payment_methods/paypal_client_id', [$this, 'getClientId'], 10, 2);

        // add PayPal partner tags
        add_filter('script_loader_tag', function ($tag, $handle) {
            if ($handle === 'fluent-cart-checkout-sdk-paypal') {
                $tag = str_replace(
                    '<script ',
                    '<script data-partner-attribution-id="FLUENTCART_SP_PPCP" ', $tag
                );

                // The vault setup-token (save-without-purchase) buttons flow
                // requires a browser-safe id token on the SDK script tag.
                if ($this->vaultUserIdToken) {
                    $tag = str_replace(
                        '<script ',
                        '<script data-user-id-token="' . esc_attr($this->vaultUserIdToken) . '" ', $tag
                    );
                }
            }
            return $tag;
        }, 1, 2);

    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        if ($paymentInstance->subscription) {
            $subscription = $paymentInstance->subscription;

            // Store-managed mode: charge the first order / renewal invoice one-time.
            // No PayPal billing agreement, no manual→automatic conversion — the
            // invoice engine owns all future renewals.
            if ($this->shouldChargeSubscriptionAsOneTime($paymentInstance)) {
                $paymentArgs = [];

                // System subscriptions vault the buyer's PayPal account during this
                // purchase (save-on-success) so future renewal invoices can be
                // charged merchant-initiated. The disclosure is shown at checkout
                // and PayPal's own approval UI carries the save agreement.
                if ($subscription->collection_method === 'system') {
                    // Nothing payable now (free trial): a $0 PayPal order is invalid —
                    // vault via a Vault v3 setup token instead (no purchase).
                    if ((int) $paymentInstance->transaction->total <= 0) {
                        return (new Processor())->handleSetupOnlyPayment($paymentInstance);
                    }

                    $paymentArgs['vault_on_success'] = true;
                }

                return (new Processor())->handleSinglePayment($paymentInstance, $paymentArgs);
            }

            if ($subscription->collection_method === 'manual') {
                $previousPaymentMethod = $subscription->current_payment_method;
                $conversionResult = $this->convertManualSubscription($subscription);
                if (is_wp_error($conversionResult)) {
                    return $conversionResult;
                }

                $result = (new Processor())->handleSubscriptionPaymentFromPaymentInstance($paymentInstance, []);
                
                if (is_wp_error($result)) {
                    $subscription->update([
                        'collection_method'      => 'manual',
                        'current_payment_method' => $previousPaymentMethod,
                    ]);
                } else {
                    $subscription->addLog(
                        'Converted to automatic billing',
                        sprintf('Subscription converted from manual to automatic billing via %s', 'PayPal'),
                        'info'
                    );
                    do_action('fluent_cart/subscription_converted_to_automatic', [
                        'subscription'   => $subscription,
                        'payment_method' => 'paypal',
                    ]);
                }

                return $result;
            }

            return (new Processor())->handleSubscriptionPaymentFromPaymentInstance($paymentInstance, []);
        }

        return (new Processor())->handleSinglePayment($paymentInstance, []);
    }

    public function convertManualSubscription($subscription)
    {
        if (!$subscription || $subscription->collection_method !== 'manual') {
            return new \WP_Error('invalid_subscription', __('Subscription is not manual or does not exist', 'fluent-cart'));
        }

        if (in_array($subscription->status, ['completed'])) {
            return new \WP_Error('subscription_invalid_status', __('Cannot convert completed subscriptions', 'fluent-cart'));
        }

        $subscription->collection_method = 'automatic';
        $subscription->current_payment_method = 'paypal';
        $subscription->save();

        return true;
    }

    private function shouldRenderAsSubscriptionMode($hasSubscription): bool
    {
        // One-time-charged subscription payments (store-managed mode, or a renewal of
        // a store-managed-born subscription) go through handleSinglePayment, so the
        // PayPal SDK must load with intent=capture (no vault) and getOrderInfo must
        // report payment mode, not subscription mode.
        if (\FluentCart\App\Modules\Subscriptions\Services\SubscriptionManagementMode::currentCheckoutChargesOneTime()) {
            return false;
        }

        return $hasSubscription;
    }

    /**
     * PayPal can vault a wallet without charging (Vault v3 setup tokens) — but
     * only the smart-buttons flow implements it; other checkout modes keep the
     * pre-feature behavior (gateway hidden for zero-payable system carts).
     */
    public function supportsSetupWithoutCharge(): bool
    {
        return $this->settings->get('checkout_mode') === 'paypal_pro';
    }

    /**
     * Zero-payable system checkout on this page load: the SDK must carry a
     * user id token and getOrderInfo must report setup mode.
     */
    private function isZeroPayableSetupCheckout($hasSubscription): bool
    {
        if (!$hasSubscription || $this->shouldRenderAsSubscriptionMode($hasSubscription)) {
            return false;
        }

        if (!\FluentCart\App\Modules\Subscriptions\Services\SubscriptionManagementMode::currentCheckoutIsSystem($this)) {
            return false;
        }

        return CartHelper::getCart() && $this->getPayableNowTotal() <= 0;
    }

    /**
     * Amount payable on THIS checkout (items + shipping + additive taxes) — the
     * same total the charge transaction is created with. Every frontend
     * zero-payable decision must predict transaction->total with this computation.
     */
    private function getPayableNowTotal(): int
    {
        $checkOutHelper = CartCheckoutHelper::make();
        $shippingChargeData = (new WebCheckoutHandler())->getShippingChargeData(CartHelper::getCart());
        $shippingCharge = Arr::get($shippingChargeData, 'charge');
        $totalPrice = $checkOutHelper->getItemsAmountTotal(false) + $shippingCharge;

        $tax = $checkOutHelper->getCart()->checkout_data['tax_data'] ?? [];
        $taxBehavior = (int) Arr::get($tax, 'tax_behavior', 0);
        $storeTaxBehavior = (int) Arr::get($tax, 'store_tax_behavior', $taxBehavior);

        if ($taxBehavior === 1) {
            // Pure exclusive — add all tax including fee tax (tax_total contains both).
            $totalPrice = $totalPrice + (int) Arr::get($tax, 'tax_total', 0)
                                         + (int) Arr::get($tax, 'shipping_tax', 0);
        } elseif ($taxBehavior === 3) {
            // Mixed — add only exclusive product tax + fee/shipping if store is exclusive.
            $totalPrice = $totalPrice + (int) Arr::get($tax, 'exclusive_tax_total', 0);
            if ($storeTaxBehavior === 1) {
                $totalPrice = $totalPrice + (int) Arr::get($tax, 'fee_tax', 0)
                                     + (int) Arr::get($tax, 'shipping_tax', 0);
            }
        }

        return (int) $totalPrice;
    }

    /**
     * Off-session charge of a system subscription's renewal invoice against the
     * vaulted PayPal token. Contract per
     * dev-docs/system-subscriptions/gateway-implementation-guide.md.
     *
     * @param PaymentInstance $paymentInstance
     * @param array $args ['attempt' => int]
     * @return true|string|\WP_Error true = confirmed; 'processing' = accepted,
     *                               settling (webhook/reconciler will confirm)
     */
    public function chargeRenewal(PaymentInstance $paymentInstance, $args = [])
    {
        return (new Processor())->chargeVaultedRenewal($paymentInstance, $args);
    }

    /**
     * Re-check a processing vault charge (lost webhook / slow eCheck).
     *
     * @param PaymentInstance $paymentInstance
     * @return true|string|\WP_Error
     */
    public function reconcileRenewalCharge(PaymentInstance $paymentInstance)
    {
        return (new Processor())->reconcileVaultedRenewal($paymentInstance);
    }

    public function syncRemoteTransaction(\FluentCart\App\Models\OrderTransaction $transaction)
    {
        return (new Processor())->syncRemoteTransaction($transaction);
    }

    public function confirmPayPalSinglePayment()
    {
        if (empty(App::request()->get('payId')) || empty(App::request()->get('ref_id'))) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('No payId ID!', 'fluent-cart')
            ], 422);
        }

        $payPalReferenceId = sanitize_text_field(App::request()->get('payId'));
        $transactionHash = sanitize_text_field(App::request()->get('ref_id'));

        $payment_intent = $this->verifyPayPalPayment($payPalReferenceId);

        if (is_wp_error($payment_intent)) {
            wp_send_json([
                'status'  => 'failed',
                'message' => $payment_intent->get_error_message(),
            ], 422);
        }

        $transaction = null;

        $intendedTransactionHash = Arr::get($payment_intent, 'purchase_units.0.reference_id', '');
        if ($intendedTransactionHash) {
            $transaction = OrderTransaction::query()
                ->where('uuid', $intendedTransactionHash)
                ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                ->first();
        }

        if (!$transaction) {
            $transaction = OrderTransaction::query()
                ->where('uuid', $transactionHash)
                ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                ->first();
        }

        if (!$transaction) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Transaction not found!', 'fluent-cart')
            ], 423);
        }

        // Bind the PayPal payment to THIS transaction. FluentCart sets the
        // transaction uuid as the PayPal order reference_id/custom_id at creation,
        // so a legitimate confirmation always references it. Requiring the match
        // prevents a real payment for one order from being applied to an unrelated
        // order via a forged ref_id in the fallback above.
        $referencedHashes = [];
        foreach (Arr::get($payment_intent, 'purchase_units', []) as $unit) {
            $referencedHashes[] = Arr::get($unit, 'reference_id', '');
            $referencedHashes[] = Arr::get($unit, 'custom_id', '');
        }
        if (!in_array($transaction->uuid, array_filter($referencedHashes), true)) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Payment does not match this transaction!', 'fluent-cart')
            ], 422);
        }

        // Move the money ourselves — never trust the browser to have captured.
        // FluentCart creates the order with intent=CAPTURE, but the buyer only
        // AUTHORIZES it in the popup (status APPROVED). The funds are not captured
        // until we call capture server-side. An APPROVED-but-uncaptured order means
        // PayPal is holding $0; accepting it as paid delivers the product for free.
        if (Arr::get($payment_intent, 'status') === 'APPROVED') {
            $captured = $this->capturePayPalPayment($payPalReferenceId);

            if (is_wp_error($captured)) {
                // The normal (non-malicious) flow captures in the browser first, so by
                // the time we reach here the order may already be captured. That is
                // success, not failure: re-read the order and continue. Any other
                // capture error is fatal.
                if (!$this->isAlreadyCapturedError($captured)) {
                    wp_send_json([
                        'status'  => 'failed',
                        'message' => $captured->get_error_message(),
                    ], 422);
                }

                $payment_intent = $this->verifyPayPalPayment($payPalReferenceId);
                if (is_wp_error($payment_intent)) {
                    wp_send_json([
                        'status'  => 'failed',
                        'message' => $payment_intent->get_error_message(),
                    ], 422);
                }
            } else {
                $payment_intent = $captured;
            }
        }

        // Only a COMPLETED order (its capture actually moved money) counts as paid.
        // APPROVED is deliberately NOT accepted here.
        if (Arr::get($payment_intent, 'status') !== 'COMPLETED') {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Payment not completed!', 'fluent-cart')
            ], 422);
        }

        $paidAmount = 0;
        $paidCurrency = '';
        foreach (Arr::get($payment_intent, 'purchase_units', []) as $unit) {
            $paidAmount += Helper::toCent(Arr::get($unit, 'amount.value', 0));
            if (!$paidCurrency) {
                $paidCurrency = strtoupper(Arr::get($unit, 'amount.currency_code', ''));
            }
        }

        if ($paidAmount != $transaction->total) {
            fluent_cart_warning_log(
                __('PayPal Amount Mismatch Attempt', 'fluent-cart'),
                sprintf(
                    /* translators: %1$s: expected amount, %2$s: received amount */
                    __('Payment amount mismatch detected. Expected: %1$s, Received: %2$s. This may indicate payment tampering.', 'fluent-cart'),
                    Helper::toDecimal($transaction->total),
                    Helper::toDecimal($paidAmount)
                ),
                [
                    'module_name' => 'order',
                    'module_id'   => $transaction->order_id,
                    'log_type'    => 'api'
                ]
            );
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Paid amount does not match with transaction amount!', 'fluent-cart')
            ], 422);
        }

        if ($paidCurrency && $transaction->currency && strtoupper($transaction->currency) !== $paidCurrency) {
            fluent_cart_warning_log(
                __('PayPal Currency Mismatch Attempt', 'fluent-cart'),
                sprintf(
                    /* translators: %1$s: expected currency, %2$s: received currency */
                    __('Payment currency mismatch detected. Expected: %1$s, Received: %2$s. This may indicate payment tampering.', 'fluent-cart'),
                    $transaction->currency,
                    $paidCurrency
                ),
                [
                    'module_name' => 'order',
                    'module_id'   => $transaction->order_id,
                    'log_type'    => 'api'
                ]
            );
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Payment currency does not match with transaction currency!', 'fluent-cart')
            ], 422);
        }

        $capture      = Arr::get($payment_intent, 'purchase_units.0.payments.captures.0', []);
        $chargeId     = Arr::get($capture, 'id', '');
        $captureStatus = Arr::get($capture, 'status', '');

        if ($captureStatus === 'PENDING') {
            if (!$this->recordPendingCapture($transaction, $capture)) {
                // The capture ID already belongs to another transaction. The eventual
                // PAYMENT.CAPTURE.COMPLETED webhook resolves by vendor_charge_id and will
                // update that other transaction, so this buyer must never be redirected
                // to a receipt that will now stay pending forever.
                wp_send_json([
                    'status'  => 'failed',
                    'message' => __('This PayPal payment has already been processed!', 'fluent-cart')
                ], 422);
            }

            wp_send_json([
                'status'       => 'pending',
                'redirect_url' => $this->getConfirmRedirectUrl($transaction),
                'order'        => [
                    'uuid' => $transaction->order->uuid
                ],
                'message'      => __('Your payment is being reviewed by PayPal. Your order will be confirmed once the payment is completed.', 'fluent-cart')
            ], 202);
        }

        if (!$chargeId || $captureStatus !== 'COMPLETED') {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Payment not completed!', 'fluent-cart')
            ], 422);
        }

        $duplicateCapture = false;

        $payPalCaptureLockAcquired = $this->acquirePayPalCaptureLock($chargeId);
        if (!$payPalCaptureLockAcquired) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Payment confirmation is already processing. Please try again.', 'fluent-cart')
            ], 409);
        }

        // Prevent a single PayPal capture from being applied to more than one
        // transaction (replay/duplicate-capture protection).
        try {
            $duplicateCapture = $this->hasExistingPayPalCapture($transaction, $chargeId);

            if (!$duplicateCapture) {
                // All Verified! Let's update the transaction and order
                (new Processor())->confirmPaymentSuccessByCharge($transaction, [
                    'vendor_charge_id'    => $chargeId,
                    'status'              => Status::TRANSACTION_SUCCEEDED,
                    'total'               => $paidAmount,
                    'payment_method_type' => 'PayPal',
                    'meta'                => [
                        'payer' => Arr::get($payment_intent, 'payer', [])
                    ],
                    'payment_source'      => Arr::get($payment_intent, 'payment_source', []),
                ]);

                // System subscription: persist the vault token from the captured
                // order (or demote to manual when vaulting did not happen).
                (new Processor())->maybePersistVaultToken($transaction, $payment_intent);
            }
        } finally {
            if ($payPalCaptureLockAcquired) {
                $this->releasePayPalCaptureLock($chargeId);
            }
        }

        if ($duplicateCapture) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('This PayPal payment has already been processed!', 'fluent-cart')
            ], 422);
        }

        wp_send_json([
            'status'       => 'success',
            'redirect_url' => $this->getConfirmRedirectUrl($transaction),
            'order'        => [
                'uuid' => $transaction->order->uuid
            ],
            'message'      => __('Payment has been paid successfully! Redirecting...', 'fluent-cart')
        ]);
    }

    /**
     * AJAX confirmation of a zero-payable system checkout: the buyer approved
     * the vault setup token in PayPal's popup; exchange it for a durable payment
     * token, persist it on the subscription, and complete the $0 order.
     */
    public function confirmPayPalVaultSetup()
    {
        $setupTokenId = sanitize_text_field(App::request()->get('setup_token', ''));
        $transactionHash = sanitize_text_field(App::request()->get('ref_id', ''));

        if (!$setupTokenId || !$transactionHash) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('No setup token!', 'fluent-cart')
            ], 422);
        }

        $transaction = OrderTransaction::query()
            ->where('uuid', $transactionHash)
            ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
            ->first();

        if (!$transaction) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Transaction not found!', 'fluent-cart')
            ], 423);
        }

        // Bind the approval to THIS transaction — the setup token id was stored
        // on it at creation, so a forged ref_id/token pair can never match.
        if (Arr::get($transaction->meta ?? [], 'paypal_setup_token_id') !== $setupTokenId) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Setup token does not match this transaction!', 'fluent-cart')
            ], 422);
        }

        // Locked on the transaction uuid, not the token — a resubmission mints a
        // new token, and a token-keyed lock would not serialize the two. The
        // binding write in handleSetupOnlyPayment takes the same lock.
        $payPalVaultLockAcquired = Processor::acquireVaultTransactionLock($transactionHash);
        if (!$payPalVaultLockAcquired) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Payment confirmation is already processing. Please try again.', 'fluent-cart')
            ], 409);
        }

        $result = true;

        try {
            /** @var OrderTransaction $transaction */
            $transaction = OrderTransaction::query()->find($transaction->id);

            // Re-check the binding under the lock — the setup token may have
            // been replaced since the pre-lock check, making this approval stale.
            if (Arr::get($transaction->meta ?? [], 'paypal_setup_token_id') !== $setupTokenId) {
                $result = new \WP_Error('stale_setup_token', __('This PayPal approval is no longer valid. Please try again.', 'fluent-cart'));
            } else {
                $result = (new Processor())->confirmVaultSetup($transaction, $setupTokenId);
            }
        } finally {
            if ($payPalVaultLockAcquired) {
                Processor::releaseVaultTransactionLock($transactionHash);
            }
        }

        if (is_wp_error($result)) {
            wp_send_json([
                'status'  => 'failed',
                'message' => $result->get_error_message()
            ], 422);
        }

        wp_send_json([
            'status'       => 'success',
            'redirect_url' => $this->getConfirmRedirectUrl($transaction),
            'order'        => [
                'uuid' => $transaction->order->uuid
            ],
            'message'      => __('Your PayPal account has been saved successfully! Redirecting...', 'fluent-cart')
        ]);
    }

    public function confirmPayPalSubscription()
    {
        if (empty(App::request()->get('subscription_id')) || empty(App::request()->get('ref_id'))) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('No Subscription ID!', 'fluent-cart')
            ], 423);
        }

        $subscriptionId = sanitize_text_field(App::request()->get('subscription_id'));

        $paypalSubscription = $this->getPayPalSubscription($subscriptionId);

        if (is_wp_error($paypalSubscription)) {
            wp_send_json([
                'message' => $paypalSubscription->get_error_message(),
                'status'  => 'failed',
            ], 422);
        }


        $status = Arr::get($paypalSubscription, 'status', '');

        if ($status != 'ACTIVE') {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Subscription is not active', 'fluent-cart')
            ], 423);
        }

        $transaction = OrderTransaction::query()->where('uuid', sanitize_text_field(App::request()->get('ref_id')))->first();

        if (!$transaction) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Transaction not found!', 'fluent-cart')
            ], 404);
        }

        $localSubscription = Subscription::query()->where('id', $transaction->subscription_id)->first();

        if (!$localSubscription) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Subscription not found!', 'fluent-cart')
            ], 404);
        }

        // Bind the PayPal subscription to THIS local subscription. FluentCart sets
        // the local subscription uuid as the PayPal subscription custom_id at
        // creation (the same field the IPN webhook resolves by), so a forged ref_id
        // cannot point an unrelated active PayPal subscription at another customer's
        // transaction.
        $paypalCustomId = Arr::get($paypalSubscription, 'custom_id', '');
        if ($paypalCustomId !== $localSubscription->uuid) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('PayPal subscription does not match this transaction!', 'fluent-cart')
            ], 422);
        }

        // Prevent the same PayPal subscription from being bound to more than one
        // local subscription (reuse protection).
        $alreadyUsed = Subscription::query()
            ->where('vendor_subscription_id', $subscriptionId)
            ->where('id', '!=', $localSubscription->id)
            ->first();
        if ($alreadyUsed) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('This PayPal subscription has already been used!', 'fluent-cart')
            ], 422);
        }

        // Verify the PayPal subscription's plan matches the expected plan
        if ($localSubscription && $localSubscription->vendor_plan_id) {
            $paypalPlanId = Arr::get($paypalSubscription, 'plan_id', '');
            if ($paypalPlanId && $paypalPlanId !== $localSubscription->vendor_plan_id) {
                fluent_cart_add_log(
                    'PayPal Subscription Plan Mismatch',
                    'The PayPal subscription plan ID does not match the expected plan ID for this subscription. This may indicate a configuration issue or potential tampering.',
                    [
                        'module_name' => 'subscription',
                        'module_id'   => $localSubscription->id,
                        'log_type'    => 'api'
                    ]
                );
                
                wp_send_json([
                    'status'  => 'failed',
                    'message' => __('PayPal subscription plan does not match the expected plan.', 'fluent-cart')
                ], 422);
            }
        }

        $subscriptionModel = (new Processor())->activateSubscription($paypalSubscription, $transaction);

        if (!$subscriptionModel || !in_array($subscriptionModel->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING], true)) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Subscription activation failed.', 'fluent-cart')
            ], 422);
        }

        wp_send_json([
            'status'       => 'success',
            'message'      => __('Subscription has been activated successfully!', 'fluent-cart'),
            'redirect_url' => $this->getConfirmRedirectUrl($transaction),
            'order'        => [
                'uuid' => $transaction->order->uuid
            ],
        ], 200);
    }

    protected function getPayPalSubscription($subscriptionId)
    {
        return API::getResource('billing/subscriptions/' . $subscriptionId);
    }

    /**
     * Post-payment redirect for PayPal confirm responses. The canonical
     * fluent_cart/payment/success_url filter fires inside getSuccessUrl();
     * the receipt_page_url filter is bridged for existing consumers of the
     * previous PayPal redirect and will be dropped from this path later.
     */
    private function getConfirmRedirectUrl($transaction)
    {
        $url = $transaction->getSuccessUrl();

        return apply_filters_deprecated(
            'fluent_cart/transaction/receipt_page_url',
            [$url, ['transaction' => $transaction, 'order' => $transaction->order]],
            '1.6.2',
            'fluent_cart/payment/success_url',
            'PayPal post-payment redirects now go through fluent_cart/payment/success_url. Hook that filter instead; this bridge will be removed in a future release.'
        );
    }

    protected function verifyPayPalPayment($payPalReferenceId)
    {
        return API::verifyPayment($payPalReferenceId);
    }

    protected function capturePayPalPayment($payPalReferenceId)
    {
        return API::captureOrder($payPalReferenceId);
    }

    /**
     * Detects PayPal's "this order was already captured" response. In the normal flow the
     * browser captures first, so our server-side capture of the same order legitimately
     * fails with 422 UNPROCESSABLE_ENTITY / issue ORDER_ALREADY_CAPTURED — that is expected
     * and must be treated as success (re-GET the order), not as a payment failure.
     *
     * @param \WP_Error $error
     * @return bool
     */
    protected function isAlreadyCapturedError($error)
    {
        if ($error->get_error_code() === 'ORDER_ALREADY_CAPTURED') {
            return true;
        }

        $body = $error->get_error_data();
        if (is_array($body)) {
            $issue = Arr::get($body, 'details.0.issue', '');
            if ($issue === 'ORDER_ALREADY_CAPTURED') {
                return true;
            }
        }

        return false;
    }

    /**
     * A PENDING capture has moved no money. Bind its id to the transaction so the
     * PAYMENT.CAPTURE.COMPLETED webhook resolves it without the order-lookup
     * fallback, and record PayPal's hold reason (ECHECK, PENDING_REVIEW,
     * RECEIVING_PREFERENCE_MANDATES_MANUAL_ACTION, ...) on the order for support.
     *
     * Shares the completed-path capture lock so a concurrent confirmation for the
     * same charge id cannot bind it to two transactions. Returns false when the
     * charge id already belongs to another transaction — the caller must not treat
     * that as pending-for-this-order.
     *
     * @param OrderTransaction $transaction
     * @param array $capture
     * @return bool
     */
    protected function recordPendingCapture(OrderTransaction $transaction, $capture)
    {
        $chargeId = Arr::get($capture, 'id', '');

        if (!$chargeId) {
            return true;
        }

        if ($transaction->vendor_charge_id === $chargeId) {
            return true;
        }

        $payPalCaptureLockAcquired = $this->acquirePayPalCaptureLock($chargeId);
        if (!$payPalCaptureLockAcquired) {
            return false;
        }

        try {
            if ($this->hasExistingPayPalCapture($transaction, $chargeId)) {
                return false;
            }

            if (!$transaction->vendor_charge_id) {
                $transaction->update([
                    'vendor_charge_id' => $chargeId,
                    'payment_method'   => 'paypal',
                ]);
            }
        } finally {
            $this->releasePayPalCaptureLock($chargeId);
        }

        $reason = Arr::get($capture, 'status_details.reason', '');

        fluent_cart_add_log(
            __('PayPal Payment Pending', 'fluent-cart'),
            sprintf(
            /* translators: %1$s: PayPal capture id, %2$s: PayPal hold reason */
                __('PayPal placed this payment on hold and no money has moved yet. Capture: %1$s, Reason: %2$s. The order stays unpaid until the PAYMENT.CAPTURE.COMPLETED webhook arrives.', 'fluent-cart'),
                $chargeId ? $chargeId : 'unknown',
                $reason ? $reason : 'unknown'
            ),
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $transaction->order_id,
                'log_type'    => 'api'
            ]
        );

        return true;
    }

    protected function hasExistingPayPalCapture(OrderTransaction $transaction, $chargeId)
    {
        return (bool) OrderTransaction::query()
            ->where('vendor_charge_id', $chargeId)
            ->where('id', '!=', $transaction->id)
            ->first();
    }

    protected function acquirePayPalCaptureLock($chargeId)
    {
        global $wpdb;

        $result = $wpdb->get_var($wpdb->prepare(
            'SELECT GET_LOCK(%s, %d)',
            $this->getPayPalCaptureLockName($chargeId),
            10
        ));

        return (string) $result === '1';
    }

    protected function releasePayPalCaptureLock($chargeId)
    {
        global $wpdb;

        $wpdb->get_var($wpdb->prepare(
            'SELECT RELEASE_LOCK(%s)',
            $this->getPayPalCaptureLockName($chargeId)
        ));
    }

    protected function getPayPalCaptureLockName($chargeId)
    {
        return 'fluent_cart_paypal_capture_' . md5($chargeId);
    }

    public function getClientId($value, $args)
    {
        return $this->settings->getPublicKey();
    }

    public function handleIPN()
    {
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] != 'POST') {
            return;
        }

        (new IPN())->processWebhook();
        exit(200);
    }

    public function getTransactionUrl($url, $data)
    {
        if (Arr::get($data, 'payment_mode') === 'test') {
            return 'https://www.sandbox.paypal.com/activity/payment/' . Arr::get($data, 'vendor_charge_id');
        }

        return 'https://www.paypal.com/activity/payment/' . Arr::get($data, 'vendor_charge_id');
    }

    public function appAuthenticator($request)
    {
        ConnectConfig::parseConnectInfos($request);
    }

    public function getSubscriptionUrl($url, $data)
    {
        if (Arr::get($data, 'payment_mode') === 'test') {
            return 'https://www.sandbox.paypal.com/billing/subscriptions/' . Arr::get($data, 'vendor_subscription_id');
        }

        return 'https://www.paypal.com/billing/subscriptions' . Arr::get($data, 'vendor_subscription_id');
    }

    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        if (Arr::get($data, 'payment_mode') === 'live') {
            $data['live_client_secret'] = Helper::encryptKey($data['live_client_secret']);
        } else {
            $data['test_client_secret'] = Helper::encryptKey($data['test_client_secret']);
        }

        if (isset($data['define_test_keys'])) {
            unset($data['define_test_keys']);
        }
        if (isset($data['define_live_keys'])) {
            unset($data['define_live_keys']);
        }
        //clean existing access token if exist, fix for: api key change authentication error
        fluent_cart_update_option('_paypal_access_token_' . Arr::get($data, 'payment_mode'), []);

        return $data;
    }

    public function isEnabled(): bool
    {
        return $this->settings->isActive();
    }

    /**
     * Connect configuration should return
     */
    public function getConnectInfo()
    {
        return ConnectConfig::getConnectConfig();
    }

    public function disconnect($data)
    {
        return ConnectConfig::disconnect($data);
    }

    public function getWebhookInfo($mode = 'test')
    {
        $webhookId = $this->settings->get($mode . '_webhook_id');
        $webhookEvents = $this->settings->get($mode . '_webhook_events');

        if (!$webhookId || !$webhookEvents) {
            return false;
        }

        /**
         * return string
         * webhook url also in code formatted and add copy button
         * webhook id
         * webhook events (list of events, and every list item should be code formatted), if not empty
         * $webhookUrl = home_url('/wp-json/fluent-cart/v2/webhook?fct_payment_listener=1&method=paypal')
         */

        $webhookInfo = '';
        if ($webhookId) {
            $webhookInfo .= '<p><b>' . __('Webhook (No further setup needed) :', 'fluent-cart') . '</b><span style="color:green;">Your webhook <code class="copyable-content">' . $webhookId . '</code> is connected!</span> </p>';
        }
        if ($webhookEvents) {
            $webhookInfo .= '<p>' . __('and now watching for Webhook Events listed bellow:', 'fluent-cart') . '</p><p style="word-wrap: break-word;
                font-size: 12px;" class="copyable-content">';
            foreach ($webhookEvents as $event) {
                $webhookInfo .= $event['name'] . ' | ';
            }
            $webhookInfo .= '</p>';
        }

        return $webhookInfo;
    }

    public function fields()
    {
        $testSchema = [
            'webhook_instruction' => [
                'value' => Webhook::webhookInstruction(),
                'label' => __('Webhook Setup', 'fluent-cart'),
                'type'  => 'html_attr'
            ],
            'test_webhook_id'     => [
                'value'       => '',
                'placeholder' => 'Webhook ID',
                'required'    => true,
                'label'       => __('Test Webhook ID (Copy the webhook id and paste bellow)', 'fluent-cart'),
                'type'        => 'text'
            ],
        ];

        $liveSchema = [
            'webhook_instruction' => [
                'value' => Webhook::webhookInstruction(),
                'label' => __('Webhook Setup', 'fluent-cart'),
                'type'  => 'html_attr'
            ],
            'live_webhook_id'     => [
                'value'       => '',
                'placeholder' => 'Webhook ID',
                'required'    => true,
                'label'       => __('Live Webhook ID (Copy the webhook id and paste bellow)', 'fluent-cart'),
                'type'        => 'text'
            ],
        ];

        // if not defined property then no need to show webhook instruction
        if ($this->settings->getProviderType() !== 'api_keys') {
            $testSchema = [];
            $liveSchema = [];
        }

        $payPalFields = array(
            'notice'            => [
                'value' => $this->renderStoreModeNotice(),
                'label' => __('PayPal', 'fluent-cart'),
                'type'  => 'notice'
            ],
            'payment_mode'      => [
                'type'   => 'tabs',
                'schema' => [
                    [
                        'type'   => 'tab',
                        'label'  => __('Live credentials', 'fluent-cart'),
                        'value'  => 'live',
                        'schema' => $liveSchema
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => __('Test credentials', 'fluent-cart'),
                        'value'  => 'test',
                        'schema' => $testSchema
                    ]
                ]
            ],
            'provider'          => array(
                'value' => $this->settings->getProviderType(),
                'label' => __('Provider', 'fluent-cart'),
                'type'  => 'provider'
            ),
            'webhook_info_test' => array(
                'info' => $this->getWebhookInfo('test'),
                'label' => __('Webhook Info', 'fluent-cart'),
                'type' => 'webhook_info',
                'mode' => 'test'
            ),
            'webhook_info_live' => array(
                'info'  => $this->getWebhookInfo('live'),
                'label' => __('Webhook Info', 'fluent-cart'),
                'type'  => 'webhook_info',
                'mode'  => 'live'
            ),
            'is_pro_item'       => array(
                'value' => 'no',
                'label' => __('PayPal', 'fluent-cart'),
                'type'  => 'validate'
            ),
        );

        return $payPalFields;
    }

    public function webHookPaymentMethodName()
    {
        return $this->methodSlug;
    }

    public static function validateSettings($data): array
    {
        $mode = Arr::get($data, 'payment_mode', 'test');
        $provider = Arr::get($data, 'provider', 'connect');

        if ($provider === 'api_keys') {
            if ($mode === 'live') {
                $clientId = defined('FCT_PAYPAL_LIVE_PUBLIC_KEY') ? FCT_PAYPAL_LIVE_PUBLIC_KEY : Arr::get($data, 'live_client_id');
                $clientSecret = defined('FCT_PAYPAL_LIVE_SECRET_KEY') ? FCT_PAYPAL_LIVE_SECRET_KEY : Arr::get($data, 'live_client_secret');
            } else {
                $clientId = defined('FCT_PAYPAL_TEST_PUBLIC_KEY') ? FCT_PAYPAL_TEST_PUBLIC_KEY : Arr::get($data, 'test_client_id');
                $clientSecret = defined('FCT_PAYPAL_TEST_SECRET_KEY') ? FCT_PAYPAL_TEST_SECRET_KEY : Arr::get($data, 'test_client_secret');
            }

            return static::validateApiCredentials($clientId, $clientSecret, $mode);

        }

        $clientId = Arr::get($data, "{$mode}_client_id");
        $clientSecret = Arr::get($data, "{$mode}_client_secret");

        if (!$clientId || !$clientSecret) {
            return [
                'status'  => 'failed',
                'message' => $mode === 'live' ? __('PayPal live credentials are required!', 'fluent-cart') : __('PayPal test credentials are required!', 'fluent-cart'),
            ];
        }

        return [
            'status'  => 'success',
            'message' => __('Credentials are valid!', 'fluent-cart')
        ];

    }

    private static function validateApiCredentials($clientId, $clientSecret, $mode): array
    {
        $result = API::validateCredentials($clientId, $clientSecret, $mode);

        if (is_wp_error($result)) {
            return [
                'status'  => 'failed',
                'message' => $result->get_error_message()
            ];
        }

        return [
            'status'  => 'success',
            'message' => __('Credentials are valid!', 'fluent-cart')
        ];

    }

    /*
     * Default sdk enqueue version is the plugin version
     * if any sdk require a specific version, then override this method
     * or to remove a version, return null
     */
    public function getEnqueueVersion()
    {
        return null;
    }

    public function getEnqueueScriptSrc($hasSubscription = false): array
    {
        if ($this->settings->get('checkout_mode') !== 'paypal_pro') {
            return [];
        }

        $clientId = $this->settings->getPublicKey();
        $clientId = sanitize_text_field($clientId);

        $sdkSrc = 'https://www.paypal.com/sdk/js?client-id=' . $clientId;

        $renderAsSubscription = $this->shouldRenderAsSubscriptionMode($hasSubscription);

        if ($renderAsSubscription) {
            $sdkSrc = add_query_arg(array('vault' => 'true', 'intent' => 'subscription'), $sdkSrc);
        } else {
            $sdkSrc = add_query_arg(array('currency' => strtoupper(CurrencySettings::get('currency')), 'intent' => 'capture'), $sdkSrc);

            if ($this->isZeroPayableSetupCheckout($hasSubscription)) {
                $idToken = API::getUserIdToken();
                if (!is_wp_error($idToken) && $idToken) {
                    $this->vaultUserIdToken = $idToken;
                } else {
                    // The vault buttons cannot start without the SDK id token —
                    // tell the checkout JS to show an error, not a dead button.
                    $this->vaultSetupUnavailable = true;
                    if (is_wp_error($idToken)) {
                        fluent_cart_add_log('PayPal Vault Setup', $idToken->get_error_message(), 'error', ['log_type' => 'payment']);
                    }
                }
            }
        }
        $sdkSrc = apply_filters('fluent_cart/payments/paypal_sdk_src', $sdkSrc, []);

        return [
            [
                'handle' => 'fluent-cart-checkout-sdk-paypal',
                'src'    => $sdkSrc,
            ],
            [
                'handle' => 'fluent-cart-checkout-handler-paypal',
                'src'    => Vite::getEnqueuePath('public/payment-methods/paypal-checkout.js'),
                'deps'   => ['fluent-cart-checkout-sdk-paypal']
            ]
        ];
    }

    public function getLocalizeData(): array
    {
        return [
            'fct_paypal_data' => [
                'vault_setup_unavailable' => $this->vaultSetupUnavailable ? 'yes' : 'no',
                'translations' => [
                    'PayPal is temporarily unavailable for this checkout. Please choose another payment method or try again later.' => __('PayPal is temporarily unavailable for this checkout. Please choose another payment method or try again later.', 'fluent-cart'),
                    'uuid not found' => __('uuid not found', 'fluent-cart'),
                    'Choose any option to continue' => __('Choose any option to continue', 'fluent-cart'),
                    'An unknown error occurred' => __('An unknown error occurred', 'fluent-cart'),
                    'An error occurred while loading PayPal.' => __('An error occurred while loading PayPal.', 'fluent-cart'),
                    'Loading Payment Processor...' => __('Loading Payment Processor...', 'fluent-cart'),
                    'Order creation failed' => __('Order creation failed', 'fluent-cart'),
                    'Not proper order handler' => __('Not proper order handler', 'fluent-cart'),
                    'No Subscription ID' => __('No Subscription ID', 'fluent-cart'),
                    'no processing' => __('no processing', 'fluent-cart'),
                    'not proper order handler' => __('not proper order handler', 'fluent-cart'),
                    'Payment confirmation failed' => __('Payment confirmation failed', 'fluent-cart'),
                    'Your payment is being reviewed. We will confirm your order once it completes.' => __('Your payment is being reviewed. We will confirm your order once it completes.', 'fluent-cart'),
                ]
            ]
        ];
    }

    public function processRefund($transaction, $amount, $args)
    {
        if (!$amount) {
            return new \WP_Error(
                'fluent_cart_stripe_refund_error',
                __('Refund amount is required.', 'fluent-cart')
            );
        }

        return PayPalHelper::processRemoteRefund($transaction, $amount, $args);
    }

    public function getOrderInfo($data)
    {
        $checkOutHelper = CartCheckoutHelper::make();
        $totalPrice = $this->getPayableNowTotal();

        $items = $checkOutHelper->getItems();
        $hasSubscription = $this->validateSubscriptions($items);

        $clientId = $this->settings->getPublicKey();

        if (empty($clientId)) {
            $message = __('Please provide a valid Client Id!', 'fluent-cart');
            fluent_cart_add_log('PayPal Credential Validation', $message, 'error', ['log_type' => 'payment']);
            wp_send_json([
                'status'  => 'failed',
                'message' => __('No valid Client ID found!', 'fluent-cart')
            ], 422);
        }

        $paymentArgs['public_key'] = $clientId;

        $paymentDetails = [
            'mode'     => 'payment',
            'amount'   => number_format(Helper::toDecimalWithoutComma($totalPrice), 2, '.', ''),
            'currency' => strtoupper(CurrencySettings::get('currency')),
        ];

        $renderAsSubscription = $this->shouldRenderAsSubscriptionMode($hasSubscription);

        if ($renderAsSubscription) {
            $paymentDetails['mode'] = 'subscription';
        }

        // System (auto-charged, store-billed) checkout: the buyer's PayPal account
        // is vaulted during the purchase — disclose the save-and-auto-charge next
        // to the PayPal button (PayPal's approval popup carries the agreement too).
        $systemConsent = '';
        if (!$renderAsSubscription && \FluentCart\App\Modules\Subscriptions\Services\SubscriptionManagementMode::currentCheckoutIsSystem($this)) {
            $systemConsent = __('Your PayPal account will be saved securely and charged automatically on each renewal date. You can cancel any time from your account.', 'fluent-cart');

            // Nothing payable now (free trial): buttons render the vault
            // setup-token flow. The disclosure stays informational — PayPal's
            // approval popup itself carries the explicit save agreement.
            if ($totalPrice <= 0) {
                $paymentDetails['mode'] = 'setup';
            }
        }

        $this->checkCurrencySupport();

        wp_send_json(
            [
                'data'           => [],
                'payment_args'   => $paymentArgs,
                'message'        => __('Order info retrieved!', 'fluent-cart'),
                'intent'         => $paymentDetails,
                'system_consent' => $systemConsent,
            ],
            200
        );

    }

    public function checkCurrencySupport()
    {
        $currency = CurrencySettings::get('currency');

        if (!in_array(strtoupper($currency), self::getPaypalSupportedCurrency())) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('PayPal does not support the currency you are using!', 'fluent-cart')
            ], 422);
        }
    }

    public function isCurrencySupported(): bool
    {
        $currency = CurrencySettings::get('currency');
        return in_array(strtoupper($currency), self::getPaypalSupportedCurrency());
    }

    public static function getPaypalSupportedCurrency(): array
    {
        return [
            'USD', 'EUR', 'GBP', 'AUD', 'CAD', 'JPY', 'NZD', 'CHF', 'HKD', 'SGD', 'SEK', 'DKK', 'PLN', 'NOK', 'HUF', 'CZK', 'ILS', 'MXN', 'MYR', 'BRL', 'PHP', 'TWD', 'THB'
        ];
    }

    public function acceptRemoteDispute($transaction, $args = [])
    {
        $disputeId = Arr::get($transaction->meta, 'dispute_id');
        $dispute = (new API())->getResource('customer/disputes/'. $disputeId);

        if (!$disputeId) {
            $dispute = (new API())->getResource('customer/disputes/'. $disputeId);

            if (is_wp_error($dispute) || empty($dispute['dispute_id'])) {
                new \WP_Error('No dispute ID found!', __('Please check PayPal if the dispute is already accepted or not!', 'fluent-cart'));
            }

            $disputeId = Arr::get($dispute, 'dispute_id');
        }

        $note = Arr::get($args, 'dispute_note', 'Accepted full dispute claim!'); 

        $closeDispute = (new API())->createResource('customer/disputes/' . $disputeId . '/accept-claim', ['note' => $note]);

        if (is_wp_error($closeDispute)) {
            return $closeDispute;
        }

        return $closeDispute;
    }

}
