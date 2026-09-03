<?php

namespace FluentCart\App\Modules\Subscriptions\Services;

use FluentCart\App\App;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\Framework\Support\Arr;

/**
 * Store-level choice of WHO manages subscription renewals: gateway_managed
 * (default, pre-feature behavior) vs store_managed (manual invoicing, no
 * vendor subscriptions). Stamped into a subscription at checkout, so changing
 * the setting later never mutates existing subscriptions.
 */
class SubscriptionManagementMode
{
    const SETTING_KEY = 'subscription_management_mode';
    const GATEWAY_MANAGED = 'gateway_managed';
    const STORE_MANAGED = 'store_managed';

    /** Store setting: auto-charge renewal invoices under store-managed mode (collection_method `system`)? */
    const SYSTEM_CHARGE_KEY = 'subscription_system_charge';

    /** Store setting: under gateway-managed mode, also admit gateways without native subscription support? */
    const MANUAL_FALLBACK_KEY = 'subscription_manual_fallback';

    /** Config key stamped at checkout — the durable per-subscription record of the mode it was born under. */
    const CONFIG_KEY = 'management_mode';

    public static function getMode(): string
    {
        $mode = App::storeSettings()->get(self::SETTING_KEY, self::GATEWAY_MANAGED);

        $mode = apply_filters('fluent_cart/subscription/management_mode', $mode);

        // Whitelist — any unexpected stored/filtered value reads as the safe default.
        return $mode === self::STORE_MANAGED ? self::STORE_MANAGED : self::GATEWAY_MANAGED;
    }

    public static function isStoreManaged(): bool
    {
        return self::getMode() === self::STORE_MANAGED;
    }

    public static function isSystemChargeEnabled(): bool
    {
        if (!self::isStoreManaged()) {
            return false;
        }

        $enabled = App::storeSettings()->get(self::SYSTEM_CHARGE_KEY, 'no') === 'yes';

        return (bool) apply_filters('fluent_cart/subscriptions/system_collection_enabled', $enabled);
    }

    public static function isManualFallbackEnabled(): bool
    {
        if (self::isStoreManaged()) {
            return false;
        }

        return App::storeSettings()->get(self::MANUAL_FALLBACK_KEY, 'no') === 'yes';
    }

    public static function resolveCollectionMethodFor($gateway): string
    {
        if (self::isSystemChargeEnabled() && $gateway && $gateway->has('system_subscription')) {
            return 'system';
        }

        return 'manual';
    }

    /**
     * `system` may only be stored on a gateway that can actually charge it — guards
     * the `fluent_cart/subscription_collection_method_{gateway}` filter's return value.
     */
    public static function sanitizeCollectionMethod($method, $gateway): string
    {
        if (!in_array($method, ['automatic', 'manual', 'system'], true)) {
            return 'manual';
        }

        if ($method === 'system' && !($gateway && $gateway->has('system_subscription'))) {
            return 'manual';
        }

        return $method;
    }

    public static function isSubscriptionStoreManaged($subscription): bool
    {
        if (!$subscription) {
            return false;
        }

        $config = $subscription->config;

        return is_array($config) && Arr::get($config, self::CONFIG_KEY) === self::STORE_MANAGED;
    }

    /**
     * A renewal/reactivation cart resolves via the existing subscription's
     * collection_method; only a new subscription cart reads the store setting.
     */
    public static function currentCheckoutIsSystem($gateway): bool
    {
        $cart = CartHelper::getCart();
        if (!$cart || !$cart->hasSubscription()) {
            return false;
        }

        $subscription = self::resolveRenewalSubscription($cart)
            ?: self::resolveReactivationSubscription($cart);

        if ($subscription) {
            return $subscription->collection_method === 'system';
        }

        return self::isSystemChargeEnabled() && $gateway && $gateway->has('system_subscription');
    }

    /**
     * Mirrors AbstractPaymentGateway::shouldChargeSubscriptionAsOneTime() so the
     * client payment UI matches what makePaymentFromPaymentInstance will do.
     */
    public static function currentCheckoutChargesOneTime(): bool
    {
        $cart = CartHelper::getCart();

        $subscription = self::resolveRenewalSubscription($cart)
            ?: self::resolveReactivationSubscription($cart);

        if ($subscription) {
            if (!in_array($subscription->collection_method, ['manual', 'system'], true)) {
                return false;
            }

            return self::isSubscriptionStoreManaged($subscription) || self::isStoreManaged();
        }

        return self::isStoreManaged();
    }

    public static function resolveRenewalSubscription($cart)
    {
        if (!$cart || !$cart->order_id || !Arr::get((array) $cart->checkout_data, 'renewal_order')) {
            return null;
        }

        $order = Order::query()->find($cart->order_id);
        if (!$order || !$order->parent_id) {
            return null;
        }

        return Subscription::query()->where('parent_order_id', $order->parent_id)->first();
    }

    /** Reads `renew_data.subscription_hash`, set by fluent-cart-pro's instant-cart builder. */
    public static function resolveReactivationSubscription($cart)
    {
        if (!$cart) {
            return null;
        }

        $hash = Arr::get((array) $cart->checkout_data, 'renew_data.subscription_hash');
        if (!$hash) {
            return null;
        }

        return Subscription::query()->where('uuid', $hash)->first();
    }
}
