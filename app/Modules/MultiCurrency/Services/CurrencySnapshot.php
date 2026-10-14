<?php

namespace FluentCart\App\Modules\MultiCurrency\Services;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Money;
use FluentCart\Framework\Support\Arr;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The currency snapshot every order and subscription carries in config.fx.
 *
 * Money is converted from the base currency once, when an amount is first
 * authored onto an order; everything downstream (transactions, renewals,
 * refunds, reports) copies the stored rate instead of looking one up.
 * dev-docs/multi-currency/plan.md §9.
 *
 * `rate` = units of the order's currency per 1 unit of the base currency,
 * kept as a 12-decimal string so it round-trips DECIMAL(24,12) exactly.
 */
class CurrencySnapshot
{
    /** currency == base currency, rate 1 */
    const SOURCE_BASE = 'base';

    /** a rate the merchant entered */
    const SOURCE_MANUAL = 'manual';

    /** a rate supplied by third-party code (e.g. the fluent_cart/checkout/order_data filter) */
    const SOURCE_EXTERNAL = 'external';

    /** a non-base currency with no trustworthy rate */
    const SOURCE_UNKNOWN = 'unknown';

    /** backfilled onto an order that predates snapshots and is in the base currency */
    const SOURCE_LEGACY_BASE = 'legacy_base';

    /** backfilled onto an order that predates snapshots and is NOT in the base currency */
    const SOURCE_LEGACY_UNKNOWN = 'legacy_unknown';

    const RATE_SCALE = 12;

    /**
     * Build a snapshot for an amount authored in $currency.
     *
     * @param string $currency ISO code of the order's (presentment) currency
     * @param string|float|int|null $rate base -> presentment rate
     * @param array $args source, rate_id, margin, rounding, captured_at
     */
    public static function make($currency, $rate = null, array $args = []): array
    {
        $base = BaseCurrency::get();
        $currency = strtoupper(trim((string) $currency)) ?: $base;
        $source = (string) Arr::get($args, 'source', '');
        $rateId = Arr::get($args, 'rate_id');

        if ($currency === $base) {
            // Invariant: rate is exactly 1 whenever the order is in the base currency
            $rate = '1';
            $source = self::SOURCE_BASE;
            $rateId = null;
        } elseif (self::storableRate($rate) !== null && ($source !== '' || self::normalizeRate($rate) !== self::normalizeRate(1))) {
            $source = $source ?: self::SOURCE_EXTERNAL;
        } else {
            // A different currency at rate 1 (or no rate) is the store currency
            // label on a foreign amount — never treat it as a real rate.
            $rate = '1';
            $source = self::SOURCE_UNKNOWN;
            $rateId = null;
        }

        return [
            'currency'      => $currency,
            'base_currency' => $base,
            'rate'          => self::normalizeRate($rate),
            'rate_id'       => $rateId ? (int) $rateId : null,
            'source'        => $source,
            'captured_at'   => (string) (Arr::get($args, 'captured_at') ?: DateTime::gmtNow()->format('Y-m-d H:i:s')),
            'margin'        => (string) Arr::get($args, 'margin', '0'),
            'rounding'      => (string) Arr::get($args, 'rounding', 'none'),
            'charm'         => (string) Arr::get($args, 'charm', 'none'),
            'adjustment'    => MultiCurrencySettings::formatAdjustment(Arr::get($args, 'adjustment', 0)),
            'decimals'      => (int) Money::getDecimals($currency),
        ];
    }

    /**
     * The snapshot recorded on an order, or — for an order that predates
     * snapshots — the legacy classification derived from its currency.
     */
    public static function fromOrder(Order $order): array
    {
        $stored = Arr::get((array) $order->config, 'fx');

        if (self::isSnapshot($stored)) {
            return $stored;
        }

        return self::legacy($order->currency, $order->rate, $order->created_at);
    }

    /**
     * The snapshot a subscription renews with: its own, else its parent order's.
     */
    public static function fromSubscription(Subscription $subscription): array
    {
        $stored = Arr::get((array) $subscription->config, 'fx');

        if (self::isSnapshot($stored)) {
            return $stored;
        }

        $parentOrder = $subscription->parent_order_id
            ? Order::query()->find($subscription->parent_order_id)
            : null;

        $currency = (string) Arr::get($subscription->getAttributes(), 'currency', '');
        if ($currency === '') {
            $currency = (string) (Arr::get((array) $subscription->config, 'currency') ?: ($parentOrder ? $parentOrder->currency : $subscription->currency));
        }

        return self::parentSnapshot($parentOrder, $currency) ?: self::legacy($currency, 1, $subscription->created_at);
    }

    /**
     * The parent order's snapshot when it describes the subscription's
     * currency. A rate only holds for amounts in its own currency, so a
     * subscription in another currency gets none rather than a wrong one.
     *
     * @param Order|null $parentOrder
     * @param string $currency the subscription's currency
     * @return array|null
     */
    public static function parentSnapshot($parentOrder, $currency)
    {
        if (!$parentOrder) {
            return null;
        }

        $snapshot = self::fromOrder($parentOrder);

        return strcasecmp((string) $snapshot['currency'], (string) $currency) === 0 ? $snapshot : null;
    }

    /**
     * Classify a row that predates snapshots. Never guesses a rate: a
     * non-base-currency row without one is `legacy_unknown` (plan §10).
     */
    public static function legacy($currency, $rate = 1, $capturedAt = null): array
    {
        $base = BaseCurrency::get();
        $currency = strtoupper(trim((string) $currency)) ?: $base;
        $isBase = $currency === $base;

        return [
            'currency'      => $currency,
            'base_currency' => $base,
            'rate'          => $isBase ? self::normalizeRate(1) : self::normalizeRate(self::isValidRate($rate) ? $rate : 1),
            'rate_id'       => null,
            'source'        => $isBase ? self::SOURCE_LEGACY_BASE : self::SOURCE_LEGACY_UNKNOWN,
            'captured_at'   => (string) ($capturedAt ?: ''),
            'margin'        => '0',
            'rounding'      => 'none',
            'decimals'      => (int) Money::getDecimals($currency),
        ];
    }

    /**
     * Stamp config.fx and the rate column on an order that is being created.
     *
     * An explicit snapshot already on the order wins — that is how a renewal
     * carries its subscription's rate and how checkout carries the cart's
     * locked rate. Otherwise one is built from the order's currency and rate.
     * Runs from Order::creating, so every creation path is covered: checkout,
     * admin, renewals, WP-CLI.
     */
    public static function applyToNewOrder(Order $order): void
    {
        $config = (array) $order->config;
        $stored = Arr::get($config, 'fx');

        $storedRate = self::isSnapshot($stored) ? self::storableRate($stored['rate']) : null;

        if ($storedRate !== null && self::fitsOrder($stored, $storedRate, $order)) {
            $snapshot = $stored;
            $snapshot['rate'] = $storedRate;
        } else {
            $snapshot = self::make($order->currency, $order->rate);
        }

        $config['fx'] = $snapshot;
        $order->config = $config;
        $order->rate = $snapshot['rate'];
    }

    /**
     * Whether a supplied snapshot describes this order in this store: same
     * currency, same base currency, and rate 1 when that is the base.
     */
    private static function fitsOrder(array $snapshot, string $rate, Order $order): bool
    {
        $base = BaseCurrency::get();
        $currency = strtoupper((string) $snapshot['currency']);

        if (strcasecmp($currency, (string) $order->currency) !== 0) {
            return false;
        }

        if (strtoupper((string) Arr::get($snapshot, 'base_currency', $base)) !== $base) {
            return false;
        }

        return $currency !== $base || $rate === self::normalizeRate(1);
    }

    /**
     * A transaction always carries its order's rate (plan §2.3 invariant #2).
     *
     * A currency that differs from the order's is detected and logged, never
     * rejected: refusing the insert after a gateway has taken the money would
     * lose the only record of a real payment (plan §12.8). The comparison is
     * case-insensitive — some gateways report lowercase codes.
     */
    public static function applyToNewTransaction(OrderTransaction $transaction): void
    {
        if (!$transaction->order_id) {
            return;
        }

        $order = Order::query()->select(['id', 'currency', 'rate'])->find($transaction->order_id);

        if (!$order) {
            return;
        }

        if (empty($transaction->currency)) {
            $transaction->currency = $order->currency;
        }

        $transaction->rate = self::normalizeRate(self::isValidRate($order->rate) ? $order->rate : 1);

        if (strcasecmp((string) $transaction->currency, (string) $order->currency) !== 0) {
            fluent_cart_add_log(
                __('Transaction currency differs from the order currency', 'fluent-cart'),
                sprintf(
                    /* translators: 1: transaction currency, 2: order currency */
                    __('A %1$s transaction was recorded on a %2$s order. Every amount on an order is in its own currency, so this payment needs review.', 'fluent-cart'),
                    strtoupper((string) $transaction->currency),
                    strtoupper((string) $order->currency)
                ),
                'warning',
                [
                    'module_name' => 'order',
                    'module_id'   => $order->id,
                ]
            );

            do_action('fluent_cart/currency/transaction_mismatch', [
                'transaction' => $transaction,
                'order'       => $order,
            ]);
        }
    }

    /**
     * Give a new subscription its currency column and its parent order's
     * snapshot — renewals carry that rate for the subscription's life
     * (plan §9.2, decision #8).
     */
    public static function applyToNewSubscription(Subscription $subscription): void
    {
        $config = is_array($subscription->config) ? $subscription->config : [];
        $parentOrder = $subscription->parent_order_id
            ? Order::query()->find($subscription->parent_order_id)
            : null;

        $currency = (string) Arr::get($subscription->getAttributes(), 'currency', '');
        if ($currency === '') {
            $currency = (string) (Arr::get($config, 'currency') ?: ($parentOrder ? $parentOrder->currency : BaseCurrency::get()));
        }
        $subscription->setAttribute('currency', strtoupper($currency));

        if (!self::isSnapshot(Arr::get($config, 'fx'))) {
            $config['fx'] = self::parentSnapshot($parentOrder, $currency) ?: self::make($currency);
            $subscription->config = $config;
        }
    }

    /**
     * Whether base-currency figures can be derived from this snapshot's rate.
     */
    public static function hasTrustedRate(array $snapshot): bool
    {
        return !in_array(Arr::get($snapshot, 'source'), [self::SOURCE_UNKNOWN, self::SOURCE_LEGACY_UNKNOWN], true);
    }

    public static function isSnapshot($value): bool
    {
        return is_array($value) && !empty($value['currency']) && isset($value['rate'], $value['source']);
    }

    public static function isValidRate($rate): bool
    {
        return is_numeric($rate) && (float) $rate > 0;
    }

    /**
     * A rate as a fixed 12-decimal string. Numeric strings are rescaled
     * without a float round-trip so a rate read back from DECIMAL(24,12)
     * compares equal to the one written.
     *
     * @param string|float|int $rate
     */
    /**
     * The rate exactly as DECIMAL(24,12) will store it, or null when it
     * cannot be stored as a real rate: zero once rounded to 12 decimals,
     * negative, or more than 12 integer digits. Checked on the normalized
     * decimal string, so no float rounding decides it.
     *
     * @param mixed $rate
     */
    public static function storableRate($rate): ?string
    {
        if (!is_numeric($rate)) {
            return null;
        }

        $normalized = self::normalizeRate(is_string($rate) ? trim($rate) : $rate);

        if (!preg_match('/^\d{1,12}\.\d{12}$/', $normalized) || !preg_match('/[1-9]/', $normalized)) {
            return null;
        }

        return $normalized;
    }

    public static function normalizeRate($rate): string
    {
        if (is_string($rate) && preg_match('/^\s*(\d+)(?:\.(\d*))?\s*$/', $rate, $parts)) {
            $integer = ltrim($parts[1], '0') ?: '0';
            $fraction = isset($parts[2]) ? $parts[2] : '';
            $fraction = substr(str_pad($fraction, self::RATE_SCALE, '0'), 0, self::RATE_SCALE);

            return $integer . '.' . $fraction;
        }

        return number_format((float) $rate, self::RATE_SCALE, '.', '');
    }
}
