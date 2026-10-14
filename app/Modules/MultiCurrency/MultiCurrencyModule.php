<?php

namespace FluentCart\App\Modules\MultiCurrency;

use FluentCart\App\App;
use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Modules\MultiCurrency\Services\BaseCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CouponCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySwitcher;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Services\Money;
use FluentCart\Framework\Support\Arr;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Storefront side of multi-currency (dev-docs/multi-currency/plan.md §5
 * Phase 3). Everything here is inert until multi-currency is enabled with at
 * least one currency.
 */
class MultiCurrencyModule
{
    public static function register()
    {
        $self = new static();
        App::getInstance()->addAction('fluentcart_loaded', [$self, 'init']);
    }

    public function init($app)
    {
        add_shortcode('fluent_cart_currency_switcher', [CurrencySwitcher::class, 'render']);
        add_filter('wp_nav_menu_objects', [CurrencySwitcher::class, 'removeIdleMenuTriggers'], 10, 1);

        // Every formatter, gateway and script that reads the store currency
        // sees the visitor's presentment currency on storefront requests
        add_filter('fluent_cart/global_currency_setting', [$this, 'presentCurrency'], 10, 1);

        // Fixed coupon amounts and spend thresholds are authored in the base currency
        add_filter('fluent_cart/coupon/resolve_coupons', [CouponCurrency::class, 'convert'], 99, 3);

        // Runs while multi-currency is off too: a pinned or forced cart keeps its currency
        add_filter('fluent_cart/checkout_active_payment_methods', [$this, 'filterPaymentMethods'], 10, 2);

        add_action('init', [$this, 'initEnabledFeatures'], 11);
    }

    public function initEnabledFeatures()
    {
        if (!MultiCurrencySettings::isSwitchedOn()) {
            return;
        }

        // Also without Pro: brings a cart left in another currency back to the base
        add_action('template_redirect', [$this, 'syncRequestCart'], 0);

        if (!MultiCurrencySettings::isEnabled()) {
            return;
        }

        $this->handleSwitch();
        add_action('template_redirect', [$this, 'preventCaching'], 1);
    }

    /**
     * Re-price the shopper's cart before the page renders. A named method,
     * not a closure, so the hook can be removed.
     */
    public function syncRequestCart(): void
    {
        CartCurrency::syncRequestCart();
    }

    /**
     * @param array $settings
     * @return array
     */
    public function presentCurrency($settings)
    {
        if (!is_array($settings) || !CurrencyContext::isPresentment()) {
            return $settings;
        }

        $currency = CurrencyContext::current();
        $settings['currency'] = $currency;
        $settings['currency_sign'] = html_entity_decode(CurrenciesHelper::getCurrencySign($currency));
        $settings['is_zero_decimal'] = Money::getDecimals($currency) === 0;

        return $settings;
    }

    /**
     * A cart priced in a currency other than the store's is offered, and may
     * be paid with, only gateways that declare the multi_currency feature.
     *
     * @param array $methods gateway instances or slugs
     * @param array $data ['cart' => Cart|null]
     * @return array
     */
    public function filterPaymentMethods($methods, $data = [])
    {
        if (!is_array($methods)) {
            return $methods;
        }

        $cart = Arr::get((array) $data, 'cart');
        $lock = CartCurrency::lock($cart instanceof Cart ? $cart : null);
        $currency = $lock ? $lock['currency'] : CurrencyContext::current();
        if (BaseCurrency::is($currency)) {
            return $methods;
        }

        return array_values(array_filter($methods, function ($method) {
            $gateway = is_string($method) ? GatewayManager::gateway($method) : $method;

            return is_object($gateway) && method_exists($gateway, 'has') && $gateway->has('multi_currency');
        }));
    }

    /**
     * ?currency=GBP on any storefront URL switches the visitor's currency and
     * re-prices their cart immediately.
     */
    public function handleSwitch()
    {
        if (CurrencyContext::isBackOffice()) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display preference, no state beyond a cookie
        $requested = isset($_GET[CurrencyContext::QUERY_PARAM]) ? sanitize_text_field(wp_unslash($_GET[CurrencyContext::QUERY_PARAM])) : '';
        if ($requested === '' || !CurrencyContext::choose($requested)) {
            return;
        }

        CartCurrency::reset();
        $cart = CartCurrency::requestCart();
        if ($cart && $cart->cart_data && !CartCurrency::pinnedCurrency($cart)) {
            $cart->save();
        }
    }

    /**
     * Pages rendered in a non-base currency must not be served from a page
     * cache to a visitor in another currency (plan §12.7). Base-currency
     * visitors keep full caching.
     */
    public function preventCaching()
    {
        if (!CurrencyContext::isPresentment()) {
            return;
        }

        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
        header('Vary: Cookie', false);
    }
}
