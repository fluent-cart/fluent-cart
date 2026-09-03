<?php

namespace FluentCart\App\Modules\Subscriptions\Services;

use FluentCart\Api\PaymentMethods;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\OrderService;
use FluentCart\App\Services\URL;
use FluentCart\Framework\Support\Arr;

/**
 * Checkout gateway visibility for every subscription cart shape: a new
 * subscription, a renewal invoice, and a reactivation.
 *
 * Two rules, one per cart shape:
 *  - New subscription: the store setting decides. `store_managed` admits only
 *    gateways the store can bill itself; `gateway_managed` admits only gateways
 *    that own a schedule, unless the Manual Fallback setting is on.
 *  - Renewal/reactivation: the subscription decides — its `collection_method`
 *    plus how it was BORN (the `management_mode` stamp). A gateway-managed
 *    `automatic` sub keeps its vendor schedule; everything else is store-billed.
 *
 * Full decision tables: dev-docs/subscription-engine/payment-rendering-and-conversion-guide.md
 */
class SubscriptionGatewayGate
{
    public function register()
    {
        add_filter('fluent_cart/checkout_active_payment_methods', [$this, 'filterCheckoutPaymentMethods'], 10, 2);
        // add_action('fluent_cart/before_payment_methods', [$this, 'renderSubscriptionGatewayNotice']);
    }

    public function filterCheckoutPaymentMethods($methods, $data)
    {
        $cart = Arr::get($data, 'cart');

        if (!$cart || !$cart->hasSubscription()) {
            return $methods;
        }

        $checkoutData = (array) $cart->checkout_data;

        if (Arr::get($checkoutData, 'renewal_order')) {
            return $this->gatewaysForExistingSubscription(
                $methods,
                SubscriptionManagementMode::resolveRenewalSubscription($cart),
                $cart
            );
        }

        if (Arr::get($checkoutData, 'renew_data.subscription_hash')) {
            return $this->gatewaysForExistingSubscription(
                $methods,
                SubscriptionManagementMode::resolveReactivationSubscription($cart),
                $cart
            );
        }

        return $this->gatewaysForNewSubscription($methods, $cart);
    }

    private function gatewaysForNewSubscription($methods, $cart)
    {
        $zeroPayable = self::getPayableNow($cart) <= 0;

        if (SubscriptionManagementMode::isStoreManaged()) {
            return self::storeBilledOnly($methods, $zeroPayable, SubscriptionManagementMode::isSystemChargeEnabled());
        }

        if (self::manualFallbackOnGatewayManage()) {
            return $zeroPayable ? self::zeroFirstPaymentCapable($methods) : $methods;
        }

        return self::subscriptionCapableOnly($methods);
    }

    /**
     * $subscription is null when the cart names one that can't be resolved — the
     * charge itself will fail later with `no_subscription`, so nothing is gated.
     */
    private function gatewaysForExistingSubscription($methods, $subscription, $cart)
    {
        if (!$subscription) {
            return $methods;
        }

        $storeManaged     = SubscriptionManagementMode::isStoreManaged();
        $collectionMethod = $subscription->collection_method;

        if (!$storeManaged && $collectionMethod === 'automatic') {
            // Keeps its vendor schedule; a one-time gateway would strand it.
            $eligible = self::subscriptionCapableOnly($methods);
        } elseif (!$storeManaged
            && $collectionMethod === 'manual'
            && !SubscriptionManagementMode::isSubscriptionStoreManaged($subscription)) {
            // Born gateway-managed and still unstamped: paying via a
            // subscription-capable gateway converts it to automatic, anything else
            // leaves it billing manually forever — opt-in only. Except before the
            // due date, where converting would cost the customer days (see
            // isAdvanceRenewal()).
            if (self::isAdvanceRenewal($cart)) {
                $eligible = self::oneTimePaymentOnly($methods);
            } else {
                $eligible = self::manualFallbackOnGatewayManage()
                    ? $methods
                    : self::subscriptionCapableOnly($methods);
            }
        } else {
            // A `system` sub is auto-charged whatever the store setting says today.
            $autoChargeable = $collectionMethod === 'system'
                || SubscriptionManagementMode::isSystemChargeEnabled();

            $eligible = self::storeBilledOnly($methods, self::getPayableNow($cart) <= 0, $autoChargeable);
        }

        return self::currentMethodFirst($eligible, $subscription);
    }

    private static function subscriptionCapableOnly($methods)
    {
        return array_filter($methods, function ($gateway) {
            return $gateway->has('subscriptions');
        });
    }

    private static function oneTimePaymentOnly($methods)
    {
        return array_filter($methods, function ($gateway) {
            return !$gateway->has('subscriptions');
        });
    }

    /**
     * Renewal invoices are created days ahead of their due date. Paying one early
     * through a subscription-capable gateway converts the subscription to automatic
     * (Stripe.php / PayPal.php) and the vendor schedule starts from TODAY — the days
     * between now and the due date are forfeited. Store-billed payment keeps the
     * cadence instead: handleRenewalPaid() anchors the next date to due_date.
     *
     * Only gateway-managed carts can convert, so only they need this guard.
     */
    private static function isAdvanceRenewal($cart): bool
    {
        if (!$cart || !$cart->order_id) {
            return false;
        }

        $order = Order::query()->find($cart->order_id);

        if (!$order) {
            return false;
        }

        $dueDate = $order->getMeta('due_date');

        return $dueDate && strtotime($dueDate) > time();
    }

    /** Nothing due today: only a gateway that can start the schedule at zero. */
    private static function zeroFirstPaymentCapable($methods)
    {
        return array_filter($methods, function ($gateway) {
            return $gateway->has('subscriptions') || $gateway->has('offline');
        });
    }

    /**
     * One-time gateways, plus subscription-capable ones that opt into store
     * billing (`manual_subscription`) — shouldChargeSubscriptionAsOneTime() routes
     * both to a single payment, so no vendor schedule is created. `manual_subscription`
     * only claims a one-time-charge path exists; it says nothing about being able to
     * vault a card without charging it, so it plays no part in the zero-payable case.
     * With nothing due today only a gateway that can vault without charging AND is
     * wired for later auto-charge (`system_subscription`) qualifies.
     */
    private static function storeBilledOnly($methods, $zeroPayable, $autoChargeable)
    {
        return array_filter($methods, function ($gateway) use ($zeroPayable, $autoChargeable) {
            if ($zeroPayable) {
                return $gateway->has('offline')
                    || ($autoChargeable && $gateway->has('system_subscription') && $gateway->supportsSetupWithoutCharge());
            }

            return $gateway->has('offline')
                || !$gateway->has('subscriptions')
                || $gateway->has('manual_subscription');
        });
    }

    private static function currentMethodFirst($methods, $subscription)
    {
        $current = $subscription->current_payment_method;

        if (!$current || !isset($methods[$current])) {
            return $methods;
        }

        return array_merge([$current => $methods[$current]], $methods);
    }

    private static function manualFallbackOnGatewayManage(): bool
    {
        return SubscriptionManagementMode::isManualFallbackEnabled();
    }

    /**
     * Admin-only notice on new-subscription carts explaining which gateways were
     * hidden and why. Renewal/reactivation carts don't get it — they follow the
     * subscription, not the store setting.
     */
    public function renderSubscriptionGatewayNotice($data)
    {
        $cart = Arr::get((array) $data, 'cart');

        if (!$this->isNewSubscriptionCart($cart)) {
            return;
        }

        $storeManaged   = SubscriptionManagementMode::isStoreManaged();
        $zeroPayable    = self::getPayableNow($cart) <= 0;
        $manualFallback = !$storeManaged && self::manualFallbackOnGatewayManage();

        if ($manualFallback && !$zeroPayable) {
            return;
        }

        if ($zeroPayable) {
            echo '<div class="fct-alert fct_zero_payment_notice">'
                . esc_html__('No payment is due today. Future payments for your subscription will be collected using the payment method you select.', 'fluent-cart')
                . '</div>';
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $allMethods = PaymentMethods::getActiveMethodInstance($cart);
        $eligible   = $this->gatewaysForNewSubscription($allMethods, $cart);

        $hiddenTitles = [];
        foreach ($allMethods as $gateway) {
            if (!in_array($gateway, $eligible, true)) {
                $hiddenTitles[] = $gateway->getMeta('title') ?: $gateway->getMeta('route');
            }
        }

        if (!$hiddenTitles) {
            return;
        }

        if ($zeroPayable) {
            $reason = __('the first payment of this subscription is zero and they cannot process a zero-amount checkout', 'fluent-cart');
        } elseif ($storeManaged) {
            $reason = __('they don\'t yet support taking a subscription\'s first payment as a plain one-time charge under store-managed billing', 'fluent-cart');
        } else {
            $reason = __('they create their own subscription schedule, which would bill alongside the renewals FluentCart generates', 'fluent-cart');
        }

        if ($storeManaged) {
            $hint = $zeroPayable
                ? __('Enable Automatic Charge in the subscription settings and use a gateway that can save a payment method without charging it, or keep offline methods active.', 'fluent-cart')
                : __('These payment methods do not yet support store-managed billing.', 'fluent-cart');
        } else {
            $hint = __('Under gateway-managed billing, only gateways with native subscription support can appear here. Enable Manual Fallback in the subscription settings to also allow non-subscription gateways (including offline methods) to fall back to manual billing.', 'fluent-cart');
        }

        /* translators: %1$s: comma-separated payment method names, %2$s: why they were hidden, %3$s: guidance on how to offer more payment methods */
        $adminText = sprintf(
            __('Admin note (only visible to you): %1$s hidden because %2$s. %3$s', 'fluent-cart'),
            implode(', ', $hiddenTitles),
            $reason,
            $hint
        );

        echo '<div class="fct-alert fct_zero_payment_admin_notice">'
            . esc_html($adminText)
            . ' <a href="' . esc_url(URL::getDashboardUrl('settings/payments')) . '" target="_blank">'
            . esc_html__('Payment settings', 'fluent-cart')
            . '</a></div>';
    }

    private function isNewSubscriptionCart($cart): bool
    {
        if (!$cart || !$cart->hasSubscription()) {
            return false;
        }

        $checkoutData = (array) $cart->checkout_data;

        return !Arr::get($checkoutData, 'renewal_order')
            && !Arr::get($checkoutData, 'renew_data.subscription_hash');
    }

    private static function getPayableNow($cart)
    {
        return OrderService::getItemsAmountTotal($cart->cart_data ?? [], false, false);
    }
}
