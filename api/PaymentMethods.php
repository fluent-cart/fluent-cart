<?php

namespace FluentCart\Api;

use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Stripe;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;

class  PaymentMethods
{
    /**
     * @return array
     *
     * All payment methods
     * @throws \Exception
     */
    public function getAll(): array
    {
        $manager = GatewayManager::getInstance();
        return $manager->getAllMeta();
        //return apply_filters('fluent_cart/payments/get_global_payment_methods', []);
    }

    public static function getLogos(): array
    {
        $manager = GatewayManager::getInstance();
        $paymentInstanceMeta = $manager->getAllMeta();
        return Collection::make($paymentInstanceMeta)->pluck('logo', 'route')->toArray();
    }

    public static function getIcons(): array
    {
        $manager = GatewayManager::getInstance();
        $paymentInstanceMeta = $manager->getAllMeta();
        return Collection::make($paymentInstanceMeta)->pluck('icon', 'route')->toArray();
    }

    /**
     * @return array
     *
     * All active payment methods
     */
    public static function getActiveMethodInstance($cart = null): array
    {
        $paymentMethods = GatewayManager::getInstance()->all();
        $activePaymentMethods = [];

        $hasSubscription = $cart ? $cart->hasSubscription() : false;

        foreach ($paymentMethods as $paymentMethod) {
            $settings = $paymentMethod->settings->get();
            if (Arr::get($settings, 'is_active') === 'yes') {
                $isCurrencySupported = $paymentMethod->isCurrencySupported();
                if (!$isCurrencySupported || ($hasSubscription && !$paymentMethod->has('subscriptions'))) {
                    continue;
                }
                $activePaymentMethods[] = $paymentMethod;
            }
        }

        // Sort by saved order if available
        $savedOrder = get_option('fluent_cart_payment_methods_order', []);
        if (!empty($savedOrder) && is_array($savedOrder)) {
            $orderMap = array_flip($savedOrder);
            usort($activePaymentMethods, function($a, $b) use ($orderMap) {
                $aOrder = $orderMap[$a->getMeta('route')] ?? PHP_INT_MAX;
                $bOrder = $orderMap[$b->getMeta('route')] ?? PHP_INT_MAX;
                return $aOrder <=> $bOrder;
            });
        }

        return $activePaymentMethods;
    }

    public static function getActiveMeta(): array
    {
        $methodInstance = static::getActiveMethodInstance();
        $activePaymentMethods = [];
        foreach ($methodInstance as $method) {
            $activePaymentMethods[] = $method->getMeta();
        }
        return $activePaymentMethods;
    }


    /**
     * @param string $method name like stripe, PayPal etc
     * Get payment method to connect info if any method uses connecting
     * @return array
     *
     */
    public function getConnectInfo($method)
    {
        return apply_filters('fluent_cart/get_payment_connect_info_' . sanitize_text_field($method), [], []);
    }

}
