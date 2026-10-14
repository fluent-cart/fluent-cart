<?php

namespace FluentCart\App\Modules\MultiCurrency\Services;


if (!defined('ABSPATH')) {
    exit;
}

/**
 * The presentment currency for the current storefront request.
 *
 * Distinct from the base currency on purpose (plan §12.1): admin screens,
 * cron, renewals and webhooks always work in the base currency; only a
 * storefront visitor ever sees another one. Resolution order: a cart pinned
 * to an existing order's currency, the ?currency= switch / cookie, a
 * geo-hint via fluent_cart/currency/resolve_for_request, then the base.
 */
class CurrencyContext
{
    const COOKIE = 'fct_currency';

    const QUERY_PARAM = 'currency';

    /** @var string|null */
    private static $current = null;

    /** @var string|null the base currency $current was resolved against */
    private static $resolvedForBase = null;

    /** @var bool */
    private static $resolving = false;

    /** @var string[] stack of currencies an order is being rendered in */
    private static $rendering = [];

    /**
     * Render $callback in $currency — an order's emails, receipt and
     * invoice show its own currency, whatever the visitor's (or, in a
     * background email, the store's) currency is. Every formatter that falls
     * back to the store currency sees it through
     * fluent_cart/global_currency_setting.
     *
     * @param string|null $currency no-op when empty
     * @return mixed the callback's return value
     */
    public static function withCurrency($currency, callable $callback)
    {
        $currency = strtoupper(trim((string) $currency));
        if ($currency === '') {
            return $callback();
        }

        self::$rendering[] = $currency;
        CartCurrency::reset();
        try {
            return $callback();
        } finally {
            array_pop(self::$rendering);
            CartCurrency::reset();
        }
    }

    /**
     * withCurrency() for a render's data: its order's currency, else its
     * subscription's (a subscription event may carry no order).
     *
     * @param array $data ['order' => Order|array|null, 'subscription' => Subscription|array|null]
     * @return mixed the callback's return value
     */
    public static function withCurrencyOf(array $data, callable $callback)
    {
        $currency = null;
        foreach (['order', 'subscription'] as $key) {
            $record = $data[$key] ?? null;
            $currency = is_object($record) ? ($record->currency ?? null) : (is_array($record) ? ($record['currency'] ?? null) : null);
            if ($currency) {
                break;
            }
        }

        return self::withCurrency($currency, $callback);
    }

    public static function current(): string
    {
        if (self::$rendering) {
            return end(self::$rendering);
        }

        $base = BaseCurrency::get();

        // Re-resolve if the base currency changed since (e.g. the store
        // currency was saved earlier in this process)
        if (self::$current !== null && self::$resolvedForBase === $base) {
            return self::$current;
        }

        // Resolving reads the cart, which may format money, which asks for the
        // current currency again — answer with the base while in flight.
        if (self::$resolving) {
            return BaseCurrency::get();
        }

        self::$resolving = true;
        try {
            self::$current = self::resolve();
            self::$resolvedForBase = $base;
        } finally {
            self::$resolving = false;
        }

        return self::$current;
    }

    public static function isPresentment(): bool
    {
        return !BaseCurrency::is(self::current());
    }

    /**
     * Whether $currency can be offered right now: the base, or an enabled
     * currency that has a rate. A currency without a rate is never offered.
     */
    public static function isSelectable($currency): bool
    {
        $currency = strtoupper((string) $currency);

        if (BaseCurrency::is($currency)) {
            return true;
        }

        return MultiCurrencySettings::isOffered($currency) && ExchangeRateService::current($currency) !== null;
    }

    /**
     * Remember the visitor's choice for later requests.
     */
    public static function choose($currency): bool
    {
        $currency = strtoupper(sanitize_text_field((string) $currency));

        if (!self::isSelectable($currency)) {
            return false;
        }

        if (!headers_sent()) {
            setcookie(self::COOKIE, $currency, [
                'expires'  => time() + 30 * DAY_IN_SECONDS,
                'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
                'domain'   => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
                'secure'   => is_ssl(),
                'httponly' => false,
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE[self::COOKIE] = $currency;
        self::$current = null;

        return true;
    }

    /**
     * Currencies a switcher may list: base first, then enabled ones with a rate.
     *
     * @return string[]
     */
    public static function selectableCurrencies(): array
    {
        $codes = [BaseCurrency::get()];
        $offered = MultiCurrencySettings::currencyCodes();
        // One rate lookup for all; isSelectable() then reads the store's cache
        ExchangeRateService::primeRates($offered);
        foreach ($offered as $code) {
            if (self::isSelectable($code)) {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    public static function reset(): void
    {
        self::$current = null;
        self::$resolvedForBase = null;
    }

    /**
     * The currency the shopper explicitly picked — ?currency= or the switcher
     * cookie — when it can be offered; null when they have not chosen one
     * (a geo hint or the base currency is not a choice).
     */
    public static function chosen(): ?string
    {
        if (!MultiCurrencySettings::isEnabled() || self::isBackOffice()) {
            return null;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display preference
        $requested = isset($_GET[self::QUERY_PARAM]) ? sanitize_text_field(wp_unslash($_GET[self::QUERY_PARAM])) : '';
        if ($requested === '' && isset($_COOKIE[self::COOKIE])) {
            $requested = sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE]));
        }

        return ($requested !== '' && self::isSelectable($requested)) ? strtoupper($requested) : null;
    }

    private static function resolve(): string
    {
        $base = BaseCurrency::get();

        if (self::isBackOffice()) {
            return $base;
        }

        // A cart built from an existing order (pinned) or a plan upgrade
        // (forced) is held to that currency — still so after multi-currency
        // is switched off: an open GBP invoice, or an upgrade whose prorate
        // credit is in GBP, is paid in GBP. Without Pro, checkout still
        // refuses one that is not paying its own order (checkoutSnapshot())
        $held = CartCurrency::heldCurrency(CartCurrency::requestCart(MultiCurrencySettings::isEnabled()));
        if ($held) {
            return $held;
        }

        if (!MultiCurrencySettings::isEnabled()) {
            return $base;
        }

        $chosen = self::chosen();
        if ($chosen) {
            return $chosen;
        }

        $hinted = apply_filters('fluent_cart/currency/resolve_for_request', $base, [
            'country' => self::visitorCountry(),
        ]);

        return self::isSelectable($hinted) ? strtoupper((string) $hinted) : $base;
    }

    /**
     * Admin screens, cron and CLI never price in a visitor's currency.
     * Storefront AJAX (checkout, cart) does.
     */
    public static function isBackOffice(): bool
    {
        if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return true;
        }

        if (is_admin()) {
            return !wp_doing_ajax();
        }

        return self::isAdminRestRequest();
    }

    /**
     * The admin app works over REST, where is_admin() is false. Its routes
     * must not follow a currency cookie the admin picked up on the
     * storefront; FluentCart's storefront routes do. Routes of other
     * plugins are left alone.
     */
    private static function isAdminRestRequest(): bool
    {
        $route = self::restRoute();
        if (!preg_match('#^/?fluent-cart/v\d+/([^/?]*)#', $route, $matches)) {
            return false;
        }

        return !in_array($matches[1], ['public', 'cart', 'checkout', 'customer-profile'], true);
    }

    /**
     * The REST route of this request, from the URL — resolution can run
     * before WordPress has parsed the request.
     */
    private static function restRoute(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only
        if (isset($_GET['rest_route'])) {
            return sanitize_text_field(wp_unslash($_GET['rest_route']));
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
        $path = (string) wp_parse_url($uri, PHP_URL_PATH);
        $prefix = '/' . trim(rest_get_url_prefix(), '/') . '/';

        $start = strpos($path, $prefix);

        return $start === false ? '' : substr($path, $start + strlen($prefix));
    }

    private static function visitorCountry(): string
    {
        $country = isset($_SERVER['HTTP_CF_IPCOUNTRY']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_IPCOUNTRY'])) : '';

        // Cloudflare reports XX for unknown and T1 for Tor
        return in_array($country, ['XX', 'T1'], true) ? '' : strtoupper($country);
    }
}
