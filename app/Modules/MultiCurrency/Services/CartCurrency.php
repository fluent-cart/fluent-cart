<?php

namespace FluentCart\App\Modules\MultiCurrency\Services;

use FluentCart\App\Helpers\Helper;
use FluentCart\Api\Cookie\Cookie;
use FluentCart\Api\CurrencySettings;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A cart is priced in exactly one currency at one locked rate
 * (dev-docs/multi-currency/plan.md §2.3 invariant #6, §11.6).
 *
 * - Every cart item carries its base-currency price in other_info.fx_base;
 *   the price shown and charged is derived from it at the cart's quote.
 * - The quote lives in checkout_data.fx with the time it was locked. It is
 *   honoured for the rate-lock window; after that the cart is re-priced at
 *   the active rate, and checkout refuses once so the customer sees it.
 * - A cart rebuilt from an existing order is pinned to that order's
 *   currency and rate and is never re-priced.
 */
class CartCurrency
{
    /** @var array|null quote for the current request's cart */
    private static $currentQuote = null;

    /** @var string|null presentment currency $currentQuote was computed for */
    private static $currentQuoteCurrency = null;

    /** @var bool */
    private static $reconciling = false;

    /** @var bool */
    private static $computingQuote = false;

    /** @var array<string, bool> cart hashes re-priced by the save in progress */
    private static $repriced = [];

    /** @var bool the after-save follow-up is running; its own saves skip it */
    private static $followingUp = false;

    /** @var array<string, array> cart hash => the lock a checkout accepted */
    private static $checkoutHolds = [];

    /** @var array<string, Cart|null> */
    private static $requestCarts = [];

    /**
     * The shopper's cart for this request, found the way core finds it —
     * the instant-checkout parameter, else the cart cookie, else a logged-in
     * shopper's latest cart (CartResource::getOrSetCartForThisDevice()).
     *
     * Deliberately not CartHelper::getCart(): that resolves the framework
     * request and can create a cart, and currency resolution runs inside
     * unrelated requests (any code that formats a price). A plain lookup has
     * no side effects.
     */
    public static function requestCart(bool $userFallback = true): ?Cart
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only lookup by an opaque hash
        $hash = isset($_REQUEST[Helper::INSTANT_CHECKOUT_URL_PARAM]) ? sanitize_text_field(wp_unslash($_REQUEST[Helper::INSTANT_CHECKOUT_URL_PARAM])) : '';
        if ($hash === '') {
            $hash = Cookie::getCartHash();
        }

        // The open cart the shopper is using — found the way the cart itself
        // is (CartResource): by hash, else a logged-in user's latest open cart
        if ($hash !== '' && preg_match('/^[a-f0-9]{32}$/', $hash)) {
            if (!array_key_exists($hash, self::$requestCarts)) {
                self::$requestCarts[$hash] = Cart::query()
                    ->where('cart_hash', $hash)
                    ->where('stage', '!=', 'completed')
                    ->first();
            }
            if (self::$requestCarts[$hash]) {
                return self::$requestCarts[$hash];
            }
        }

        // The user is only known once pluggable functions have loaded
        $userId = ($userFallback && function_exists('wp_get_current_user')) ? get_current_user_id() : 0;
        if (!$userId) {
            return null;
        }

        $key = 'user:' . $userId;
        if (!array_key_exists($key, self::$requestCarts)) {
            self::$requestCarts[$key] = Cart::query()
                ->where('user_id', $userId)
                ->where('stage', '!=', 'completed')
                ->where('cart_group', 'global')
                ->orderBy('updated_at', 'DESC')
                ->first();
        }

        return self::$requestCarts[$key];
    }

    /**
     * The quote stored on the cart, if any.
     */
    public static function lock(?Cart $cart): ?array
    {
        if (!$cart) {
            return null;
        }

        $lock = Arr::get((array) $cart->checkout_data, 'fx');

        return is_array($lock) && !empty($lock['currency']) && CurrencySnapshot::isValidRate(Arr::get($lock, 'rate')) ? $lock : null;
    }

    public static function pinnedCurrency(?Cart $cart): ?string
    {
        $lock = self::lock($cart);

        return $lock && !empty($lock['pinned']) ? strtoupper($lock['currency']) : null;
    }

    /**
     * The currency a cart is held to regardless of what the visitor browses
     * in: pinned (an existing order's items) or forced (a plan upgrade priced
     * in the subscription's currency).
     */
    public static function heldCurrency(?Cart $cart): ?string
    {
        $lock = self::lock($cart);

        return $lock && (!empty($lock['pinned']) || !empty($lock['forced'])) ? strtoupper($lock['currency']) : null;
    }

    /**
     * The quote a plan upgrade of $order is priced at. An upgrade is a new
     * order and a new subscription, so it is in the currency the shopper has
     * chosen (switcher / cookie), else the subscription's currency, at
     * today's rate (plan §15.6). An order whose rate was never known stays
     * in its own currency — its credit cannot be converted.
     *
     * Null when the order's own currency cannot be quoted (no rate today and
     * no trusted snapshot): the prorate credit is in that currency, so the
     * upgrade is refused rather than priced in the base currency beside it.
     *
     * @param \FluentCart\App\Models\Order $order the latest order of the subscription
     */
    public static function upgradeQuote($order): ?array
    {
        $snapshot = CurrencySnapshot::fromOrder($order);
        $orderCurrency = strtoupper((string) $order->currency);
        $chosen = CurrencyContext::chosen();

        if ($chosen && $chosen !== $orderCurrency && (CurrencySnapshot::hasTrustedRate($snapshot) || BaseCurrency::is($orderCurrency))) {
            return self::quoteFor($chosen);
        }

        $quote = self::quoteFor($orderCurrency, $snapshot);

        return strcasecmp((string) Arr::get($quote, 'currency'), $orderCurrency) === 0 ? $quote : null;
    }

    /**
     * A quote for $currency at today's rate; else the rate on $snapshot when
     * it is a trusted rate for the same currency; else the base quote.
     */
    public static function quoteFor(string $currency, ?array $snapshot = null): array
    {
        $fresh = self::freshQuote($currency);
        if ($fresh) {
            return $fresh;
        }

        if ($snapshot && CurrencySnapshot::hasTrustedRate($snapshot) && strcasecmp((string) Arr::get($snapshot, 'currency'), $currency) === 0) {
            return [
                'currency'  => strtoupper($currency),
                'rate'      => CurrencySnapshot::normalizeRate($snapshot['rate']),
                'rate_id'   => Arr::get($snapshot, 'rate_id'),
                'source'    => (string) Arr::get($snapshot, 'source', CurrencySnapshot::SOURCE_MANUAL),
                'margin'    => (string) Arr::get($snapshot, 'margin', '0'),
                'rounding'   => MultiCurrencySettings::pricingRules($currency)['rounding'],
                'charm'      => MultiCurrencySettings::pricingRules($currency)['charm'],
                'adjustment' => MultiCurrencySettings::pricingRules($currency)['adjustment'],
                'locked_at' => DateTime::gmtNow()->format('Y-m-d H:i:s'),
            ];
        }

        return PriceConverter::baseQuote();
    }

    /**
     * Hold a new cart to $currency — e.g. a plan upgrade, which must be
     * priced in the subscription's currency whatever the visitor browses
     * in. Unlike pin(), items are still converted from their base price at
     * the current rate (plan decision #10). Call after checkout_data is set.
     *
     * @return array the quote the cart is priced at
     */
    public static function force(Cart $cart, string $currency, ?array $snapshot = null): array
    {
        $quote = self::quoteFor($currency, $snapshot);
        $quote['forced'] = true;

        $checkoutData = (array) $cart->checkout_data;
        $checkoutData['fx'] = $quote;
        $cart->checkout_data = $checkoutData;
        self::$currentQuote = null;
        CurrencyContext::reset();

        return $quote;
    }

    /**
     * Hold a cart that pays toward an existing subscription (early
     * installment, reactivation) to the subscription's currency, else its
     * parent order's. Amounts taken from the subscription are already in that
     * currency and are pinned at its rate; a base catalogue price
     * ($basePriced) is converted at today's rate. Call after checkout_data is set.
     *
     * Returns the quote the cart is held at, so the caller can convert any
     * other base amount (e.g. the new recurring amount) exactly as the line
     * is. Null when that currency cannot be quoted (no rate today and no
     * trusted snapshot): the cart is left untouched and the caller refuses,
     * as for upgrades — never a base-currency cart for the subscription.
     */
    public static function holdToSubscription(Cart $cart, Subscription $subscription, bool $basePriced = false): ?array
    {
        $currency = strtoupper((string) $subscription->currency);
        $snapshot = CurrencySnapshot::fromSubscription($subscription);
        if (strcasecmp((string) $snapshot['currency'], $currency) !== 0) {
            return null;
        }

        if ($basePriced) {
            $quote = self::quoteFor($currency, $snapshot);
            if (strcasecmp((string) $quote['currency'], $currency) !== 0) {
                return null;
            }

            return self::force($cart, $currency, $snapshot);
        }

        self::pin($cart, $snapshot);

        return self::lock($cart);
    }

    /**
     * Pin a cart to an existing order's currency and rate (re-paying an
     * order, paying a renewal invoice). Its items are already in that
     * currency and are never re-priced.
     */
    public static function pin(Cart $cart, array $snapshot): void
    {
        $checkoutData = (array) $cart->checkout_data;
        $checkoutData['fx'] = [
            'currency'  => strtoupper((string) $snapshot['currency']),
            'rate'      => CurrencySnapshot::normalizeRate($snapshot['rate']),
            'rate_id'   => Arr::get($snapshot, 'rate_id'),
            'source'    => (string) Arr::get($snapshot, 'source', CurrencySnapshot::SOURCE_BASE),
            'margin'    => (string) Arr::get($snapshot, 'margin', '0'),
            'rounding'   => (string) Arr::get($snapshot, 'rounding', RoundingRules::NONE),
            'charm'      => (string) Arr::get($snapshot, 'charm', RoundingRules::NONE),
            'adjustment' => MultiCurrencySettings::formatAdjustment(Arr::get($snapshot, 'adjustment', 0)),
            'locked_at'  => (string) Arr::get($snapshot, 'captured_at', ''),
            'pinned'    => true,
        ];
        $cart->checkout_data = $checkoutData;
        self::$currentQuote = null;
        CurrencyContext::reset();
    }

    public static function isExpired(array $lock): bool
    {
        if (!empty($lock['pinned']) || PriceConverter::isBaseQuote($lock)) {
            return false;
        }

        $holdSeconds = MultiCurrencySettings::rateLockSeconds();
        if ($holdSeconds === 0) {
            return !self::isActiveQuote($lock);
        }

        $lockedAt = strtotime((string) Arr::get($lock, 'locked_at') . ' UTC');

        return !$lockedAt || $lockedAt + $holdSeconds < time();
    }

    /**
     * With no hold, a lock stands only while it is still the rate and pricing
     * rules in force; its age alone must not make checkout refuse.
     */
    private static function isActiveQuote(array $lock): bool
    {
        $fresh = self::freshQuote((string) Arr::get($lock, 'currency', ''));
        if (!$fresh) {
            // No rate to move to, e.g. an upgrade held to its subscription's rate
            return true;
        }

        return CurrencySnapshot::normalizeRate($fresh['rate']) === CurrencySnapshot::normalizeRate(Arr::get($lock, 'rate'))
            && PriceConverter::rulesKey($fresh) === PriceConverter::rulesKey($lock);
    }

    /**
     * A fresh quote for $currency at the rate active now, or null when the
     * currency has no rate (and so cannot be offered).
     */
    public static function freshQuote(string $currency): ?array
    {
        if (BaseCurrency::is($currency)) {
            return PriceConverter::baseQuote();
        }

        $rate = ExchangeRateService::current($currency, ['context' => 'cart']);
        if (!$rate) {
            return null;
        }

        return [
            'currency'  => strtoupper($currency),
            'rate'      => $rate['rate'],
            'rate_id'   => $rate['rate_id'],
            'source'    => $rate['source'],
            'margin'    => $rate['margin'],
            'rounding'   => MultiCurrencySettings::pricingRules($currency)['rounding'],
            'charm'      => MultiCurrencySettings::pricingRules($currency)['charm'],
            'adjustment' => MultiCurrencySettings::pricingRules($currency)['adjustment'],
            'locked_at' => DateTime::gmtNow()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * The quote this cart should be priced at now.
     */
    public static function targetQuote(Cart $cart): array
    {
        $lock = self::lock($cart);

        if ($lock && !empty($lock['pinned'])) {
            return $lock;
        }

        // A checkout in progress prices every stage at the lock it accepted
        $held = Arr::get(self::$checkoutHolds, (string) $cart->cart_hash);
        if ($held) {
            return $held;
        }

        if ($lock && !empty($lock['forced'])) {
            if (!self::isExpired($lock)) {
                return $lock;
            }

            // Same currency, today's rate
            return array_merge(self::quoteFor($lock['currency']), ['forced' => true]);
        }

        $currency = CurrencyContext::current();

        if ($lock && strcasecmp($lock['currency'], $currency) === 0 && !self::isExpired($lock)) {
            return $lock;
        }

        return self::freshQuote($currency) ?: PriceConverter::baseQuote();
    }

    /**
     * The quote for the current request's cart — what catalogue prices are
     * displayed at, so a price shown equals the price added to the cart.
     */
    public static function currentQuote(): array
    {
        $currency = CurrencyContext::current();

        if (self::$currentQuote !== null && self::$currentQuoteCurrency === $currency) {
            return self::$currentQuote;
        }

        self::$currentQuoteCurrency = $currency;

        if (!CurrencyContext::isPresentment()) {
            return self::$currentQuote = PriceConverter::baseQuote();
        }

        // Loading the cart can load variations, whose formatted price asks
        // for this quote again — answer with the fresh rate while in flight
        if (self::$computingQuote) {
            return self::freshQuote(CurrencyContext::current()) ?: PriceConverter::baseQuote();
        }

        self::$computingQuote = true;
        try {
            $cart = self::requestCart();
            self::$currentQuote = $cart
                ? self::targetQuote($cart)
                : (self::freshQuote(CurrencyContext::current()) ?: PriceConverter::baseQuote());
        } finally {
            self::$computingQuote = false;
        }

        return self::$currentQuote;
    }

    /**
     * Record the base-currency price on a freshly generated cart item.
     */
    public static function stampBase(array $item): array
    {
        $otherInfo = is_array(Arr::get($item, 'other_info')) ? $item['other_info'] : [];

        if (!isset($otherInfo['fx_base'])) {
            $otherInfo['fx_base'] = [
                'unit_price' => (int) Arr::get($item, 'unit_price', 0),
                'signup_fee' => (int) Arr::get($otherInfo, 'signup_fee', 0),
            ];
            $otherInfo['fx'] = [
                'currency' => BaseCurrency::get(),
                'rate'     => CurrencySnapshot::normalizeRate(1),
            ];
        }

        $item['other_info'] = $otherInfo;

        return $item;
    }

    /**
     * other_info for a line rebuilt from its base price: the base snapshot is
     * kept, and the marker says base-priced, so the cart converts the line
     * again on save instead of taking the base numbers as already converted.
     */
    public static function asBasePriced(array $otherInfo): array
    {
        $otherInfo['fx'] = [
            'currency' => BaseCurrency::get(),
            'rate'     => CurrencySnapshot::normalizeRate(1),
        ];

        return $otherInfo;
    }

    /**
     * The base-currency unit price of an item already in a cart (for code
     * that re-feeds a cart item's price into a new line).
     */
    public static function baseUnitPrice(array $item): int
    {
        return (int) Arr::get($item, 'other_info.fx_base.unit_price', Arr::get($item, 'unit_price', 0));
    }

    /**
     * Price one item at $quote from its base-currency values.
     */
    public static function priceItem(array $item, array $quote): array
    {
        $item = self::stampBase($item);
        $base = $item['other_info']['fx_base'];
        $quantity = max(0, (int) Arr::get($item, 'quantity', 1));

        $unitPrice = PriceConverter::toPrice($base['unit_price'], $quote);
        $subtotal = $unitPrice * $quantity;

        $item['price'] = $unitPrice;
        $item['unit_price'] = $unitPrice;
        $item['subtotal'] = $subtotal;
        // Discounts were computed in the previous currency; coupons are
        // re-applied after the re-price (see afterSave()).
        $item['discount_total'] = 0;
        $item['coupon_discount'] = 0;
        $item['line_total'] = $subtotal;
        $item['line_total_formatted'] = CurrencySettings::getFormattedPrice($subtotal, $quote['currency']);

        if ($base['signup_fee']) {
            $item['other_info']['signup_fee'] = PriceConverter::toPrice($base['signup_fee'], $quote);
        }

        // Reverse charge keeps the gross price it netted, in the old currency;
        // the tax pass would restore it over the new price. Drop it, so
        // reverse charge is worked out again from the re-priced line.
        unset($item['line_meta']['original_unit_price'], $item['line_meta']['reverse_charge_adjustment'], $item['other_info']['original_signup_fee']);

        $item['other_info']['fx'] = [
            'currency' => strtoupper($quote['currency']),
            'rate'     => CurrencySnapshot::normalizeRate($quote['rate']),
            'rules'    => PriceConverter::rulesKey($quote),
        ];

        return $item;
    }

    /**
     * Bring every item to the cart's target quote. Runs from Cart::saving.
     */
    public static function reconcile(Cart $cart): void
    {
        if (self::$reconciling || (!MultiCurrencySettings::isEnabled() && !self::lock($cart))) {
            return;
        }

        // Back-office saves (cron, admin) never re-price a shopper's cart, and
        // a completed cart is history: its order froze the rate (a payment
        // webhook completing it has no visitor currency)
        if (CurrencyContext::isBackOffice() || self::pinnedCurrency($cart) || $cart->stage === 'completed') {
            return;
        }

        self::$reconciling = true;
        try {
            $items = (array) $cart->cart_data;
            $target = self::targetQuote($cart);
            $repriced = false;

            foreach ($items as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $item = self::stampBase($item);
                if (!self::isItemPricedAt($item, $target)) {
                    $item = self::priceItem($item, $target);
                    $repriced = true;
                }
                $items[$index] = $item;
            }

            $cart->cart_data = $items;

            $checkoutData = (array) $cart->checkout_data;
            $lock = self::lock($cart);
            if ($lock && $repriced && !empty($checkoutData['fees'])) {
                $checkoutData['fees'] = self::convertFees((array) $checkoutData['fees'], $lock, $target);
                $cart->clearFeeCache();
            }
            if (!$lock || $lock['currency'] !== $target['currency'] || $lock['rate'] !== $target['rate'] || Arr::get($lock, 'locked_at') !== Arr::get($target, 'locked_at')) {
                $checkoutData['fx'] = $target;
            }
            $cart->checkout_data = $checkoutData;

            if ($repriced) {
                self::$repriced[(string) $cart->cart_hash] = true;
            }
        } finally {
            self::$reconciling = false;
        }

        self::$currentQuote = null;
    }

    /**
     * Stored fees are amounts in the cart's currency with no base price, so a
     * re-priced cart carries them from the old quote to the new one. Without
     * this, the tax pass and the total take them as the new currency.
     */
    private static function convertFees(array $fees, array $from, array $to): array
    {
        foreach ($fees as $index => $fee) {
            if (!is_array($fee) || !isset($fee['amount'])) {
                continue;
            }

            $amount = PriceConverter::between($fee['amount'], $from, $to);
            if ($amount !== null) {
                $fees[$index]['amount'] = $amount;
            }
        }

        return $fees;
    }

    /**
     * Re-price the shopper's cart before a storefront page renders it, when
     * its prices no longer match the visitor's currency or rate: a currency
     * chosen by cookie, a rate set for the chosen currency, an expired lock.
     * Otherwise the page shows one currency and the cart drawer, until the
     * cart's next save, another.
     *
     * @return bool whether the cart was re-priced
     */
    public static function syncRequestCart(): bool
    {
        if (CurrencyContext::isBackOffice()) {
            return false;
        }

        $cart = self::requestCart();
        if (!$cart || !$cart->cart_data || self::pinnedCurrency($cart)) {
            return false;
        }

        // With multi-currency off, only a cart priced while it was on can be stale
        if (!MultiCurrencySettings::isEnabled() && !self::lock($cart)) {
            return false;
        }

        if (self::isPricedAt($cart, self::targetQuote($cart))) {
            return false;
        }

        $cart->save();

        return true;
    }

    /**
     * Whether the cart's lock and every item are already at $quote.
     */
    private static function isPricedAt(Cart $cart, array $quote): bool
    {
        $lock = self::lock($cart);
        $rate = CurrencySnapshot::normalizeRate($quote['rate']);

        if (!$lock || self::isExpired($lock) || strcasecmp($lock['currency'], $quote['currency']) !== 0 || CurrencySnapshot::normalizeRate($lock['rate']) !== $rate) {
            return false;
        }

        foreach ((array) $cart->cart_data as $item) {
            if (!is_array($item) || !self::isItemPricedAt($item, $quote)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether an item is already priced at $quote: same currency, rate and
     * pricing rules. An item priced before rules existed counts as priced
     * under the defaults, so existing carts are not churned.
     */
    private static function isItemPricedAt(array $item, array $quote): bool
    {
        $fx = Arr::get($item, 'other_info.fx');
        if (!$fx) {
            return false;
        }

        return strcasecmp((string) Arr::get($fx, 'currency'), (string) $quote['currency']) === 0
            && Arr::get($fx, 'rate') === CurrencySnapshot::normalizeRate($quote['rate'])
            && (string) Arr::get($fx, 'rules', PriceConverter::rulesKey([])) === PriceConverter::rulesKey($quote);
    }

    /**
     * After a re-price, run what any item change runs: coupons are re-applied
     * in the new currency, and tax and shipping — computed from the item
     * prices — are recalculated through fluent_cart/cart/cart_data_items_updated,
     * with or without coupons. Runs from Cart::saved; the listeners' own saves
     * reconcile to the same quote and skip this follow-up.
     */
    public static function afterSave(Cart $cart): void
    {
        $hash = (string) $cart->cart_hash;
        if (self::$followingUp || empty(self::$repriced[$hash])) {
            return;
        }

        unset(self::$repriced[$hash]);
        self::$followingUp = true;
        try {
            if ($cart->coupons) {
                $cart->reValidateCoupons();
            }

            do_action('fluent_cart/cart/cart_data_items_updated', [
                'cart'       => $cart,
                'scope'      => 'currency_repriced',
                'scope_data' => self::lock($cart),
            ]);
        } finally {
            self::$followingUp = false;
        }
    }

    /**
     * Pin the lock checkout accepted for the rest of this request. Coupon
     * revalidation, tax, shipping and fee hooks save the cart; without the
     * hold, a lock that runs out mid-checkout would be re-priced by one of
     * those saves and the stages would see different prices.
     */
    public static function holdForCheckout(Cart $cart): void
    {
        $lock = self::lock($cart);
        if ($lock) {
            self::$checkoutHolds[(string) $cart->cart_hash] = $lock;
        }
    }

    public static function releaseCheckout(Cart $cart): void
    {
        unset(self::$checkoutHolds[(string) $cart->cart_hash]);
    }

    /**
     * Refusal when the cart's lock is no longer the one checkout accepted —
     * the order is never built at a rate the shopper was not shown.
     */
    public static function heldLockChanged(Cart $cart): ?\WP_Error
    {
        $held = Arr::get(self::$checkoutHolds, (string) $cart->cart_hash);
        if (!$held) {
            return null;
        }

        $lock = self::lock($cart);
        $same = $lock
            && strcasecmp((string) $lock['currency'], (string) $held['currency']) === 0
            && CurrencySnapshot::normalizeRate($lock['rate']) === CurrencySnapshot::normalizeRate($held['rate'])
            && PriceConverter::rulesKey($lock) === PriceConverter::rulesKey($held);

        return $same ? null : self::pricesUpdatedError();
    }

    /**
     * The snapshot an order placed from this cart is stamped with, or an
     * error when the locked rate expired or the currency changed since the
     * checkout page was rendered (another tab) — the cart is re-priced and
     * the customer is asked to review the new total before paying.
     *
     * @param string|null $shownCurrency the currency the checkout page showed
     * @return array|\WP_Error
     */
    public static function checkoutSnapshot(Cart $cart, $shownCurrency = null, $shownQuote = null)
    {
        $snapshot = self::lockedSnapshot($cart);
        if (!is_wp_error($snapshot) && !self::mayCheckoutIn($cart, (string) $snapshot['currency'])) {
            return new \WP_Error('fx_currency_unavailable', sprintf(
                /* translators: %s: currency code, e.g. GBP */
                __('Payment in %s is not available. Please start your purchase again.', 'fluent-cart'),
                strtoupper((string) $snapshot['currency'])
            ));
        }

        $shownCurrency = strtoupper(trim((string) $shownCurrency));
        $shownQuote = trim((string) $shownQuote);

        if (is_wp_error($snapshot) || $shownCurrency === '' || self::pinnedCurrency($cart)) {
            return $snapshot;
        }

        if (strcasecmp($snapshot['currency'], $shownCurrency) !== 0) {
            $cart->save();
            return new \WP_Error('fx_currency_changed', sprintf(
                /* translators: %s: the currency code the cart is now priced in, e.g. USD */
                __('Your currency changed to %s. Please review your order total before paying.', 'fluent-cart'),
                $snapshot['currency']
            ));
        }

        // Same currency, another rate: a second tab refreshed the lock after
        // this page was priced, so its total is not the one charged
        if ($shownQuote !== '' && !hash_equals(self::quoteRevision($cart), $shownQuote)) {
            return self::pricesUpdatedError();
        }

        return $snapshot;
    }

    /**
     * Without multi-currency (no Pro) checkout places new orders in the base
     * currency only. A cart pinned to an existing order (re-pay, renewal
     * invoice) still pays it in its own currency: relabelling it would corrupt it.
     */
    private static function mayCheckoutIn(Cart $cart, string $currency): bool
    {
        if (BaseCurrency::is($currency) || MultiCurrencySettings::isAvailable()) {
            return true;
        }

        if (!self::pinnedCurrency($cart) || !$cart->order_id) {
            return false;
        }

        $order = Order::query()->find($cart->order_id);

        return $order && strcasecmp((string) $order->currency, $currency) === 0;
    }

    /**
     * A short fingerprint of the quote a cart is priced at — its currency,
     * rate and pricing rules. The checkout page sends back the one it was
     * rendered with. A lock refreshed at the same rate and rules keeps it,
     * so it forces no reload. Default rules add nothing, so a page rendered
     * before rules existed keeps its fingerprint.
     */
    public static function quoteRevision(?Cart $cart): string
    {
        $lock = self::lock($cart);
        if (!$lock) {
            return '';
        }

        $fingerprint = strtoupper((string) $lock['currency']) . '|' . CurrencySnapshot::normalizeRate($lock['rate']);
        $rules = PriceConverter::rulesKey($lock);
        if ($rules !== PriceConverter::rulesKey([])) {
            $fingerprint .= '|' . $rules;
        }

        return substr(md5($fingerprint), 0, 12);
    }

    /**
     * @return array|\WP_Error
     */
    private static function lockedSnapshot(Cart $cart)
    {
        $lock = self::lock($cart);

        if (!$lock || PriceConverter::isBaseQuote($lock)) {
            if (CurrencyContext::isPresentment()) {
                // Items were priced in the base currency, or never priced, and
                // the visitor is now in another currency
                $cart->save();
                return self::pricesUpdatedError();
            }

            return CurrencySnapshot::make(BaseCurrency::get());
        }

        if (empty($lock['pinned']) && (self::isExpired($lock) || strcasecmp($lock['currency'], CurrencyContext::current()) !== 0)) {
            $cart->save();
            return self::pricesUpdatedError();
        }

        return CurrencySnapshot::make($lock['currency'], $lock['rate'], [
            'source'      => Arr::get($lock, 'source') ?: CurrencySnapshot::SOURCE_MANUAL,
            'rate_id'     => Arr::get($lock, 'rate_id'),
            'margin'      => Arr::get($lock, 'margin', '0'),
            'rounding'    => Arr::get($lock, 'rounding', RoundingRules::NONE),
            'charm'       => Arr::get($lock, 'charm', RoundingRules::NONE),
            'adjustment'  => MultiCurrencySettings::formatAdjustment(Arr::get($lock, 'adjustment', 0)),
            'captured_at' => Arr::get($lock, 'locked_at'),
        ]);
    }

    public static function reset(): void
    {
        self::$currentQuote = null;
        self::$repriced = [];
        self::$followingUp = false;
        self::$checkoutHolds = [];
        self::$requestCarts = [];
    }

    private static function pricesUpdatedError(): \WP_Error
    {
        return new \WP_Error('fx_prices_updated', __('Prices were updated to today\'s exchange rate. Please review your order total before paying.', 'fluent-cart'));
    }
}
