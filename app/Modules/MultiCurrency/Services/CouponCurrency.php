<?php

namespace FluentCart\App\Modules\MultiCurrency\Services;

use FluentCart\App\Models\Coupon;
use FluentCart\Framework\Support\Arr;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Coupons are authored in the base currency. For a cart priced in another
 * currency, fixed amounts and spend thresholds are converted at the cart's
 * rate — on in-memory copies that can never be saved (plan §2.5.2).
 */
class CouponCurrency
{
    /**
     * The converted copies themselves. Holding the objects, not their ids,
     * means a released copy's id can never be reused by a fresh coupon and
     * mistaken for a copy. Request-scoped.
     *
     * @var \SplObjectStorage|null
     */
    private static $converted = null;

    private static function converted(): \SplObjectStorage
    {
        if (!self::$converted) {
            self::$converted = new \SplObjectStorage();
        }

        return self::$converted;
    }

    /**
     * fluent_cart/coupon/resolve_coupons listener.
     *
     * @param \FluentCart\Framework\Support\Collection $coupons
     * @param array $codes
     * @param array $context ['cart' => Cart|null] or ['quote' => array] (an order's frozen rate)
     * @return \FluentCart\Framework\Support\Collection
     */
    public static function convert($coupons, $codes = [], $context = [])
    {
        $quote = Arr::get((array) $context, 'quote');
        if (!is_array($quote)) {
            $cart = Arr::get((array) $context, 'cart');
            $quote = $cart ? CartCurrency::lock($cart) : null;
        }

        if (!$quote || PriceConverter::isBaseQuote($quote) || !is_object($coupons) || !method_exists($coupons, 'map')) {
            return $coupons;
        }

        return $coupons->map(function ($coupon) use ($quote) {
            if (!$coupon instanceof Coupon || self::converted()->contains($coupon)) {
                return $coupon;
            }

            $copy = clone $coupon;

            if ($copy->type === 'fixed') {
                $copy->amount = PriceConverter::toQuote($copy->amount, $quote);
            }

            $conditions = is_array($copy->conditions) ? $copy->conditions : [];
            foreach (['min_purchase_amount', 'max_discount_amount'] as $centsKey) {
                if (!empty($conditions[$centsKey])) {
                    $conditions[$centsKey] = PriceConverter::toQuote($conditions[$centsKey], $quote);
                }
            }
            // Stored in major units (CouponRequest), unlike the two above
            if (!empty($conditions['max_purchase_amount'])) {
                $conditions['max_purchase_amount'] = PriceConverter::toQuote((float) $conditions['max_purchase_amount'] * 100, $quote) / 100;
            }
            $copy->conditions = $conditions;

            self::converted()->attach($copy);

            return $copy;
        });
    }

    /**
     * The quote to convert coupons at when an admin edits $order: its frozen
     * rate. Null for a base-currency order, or one whose rate was never known
     * (nothing to convert at).
     *
     * @param \FluentCart\App\Models\Order|null $order
     */
    public static function orderQuote($order): ?array
    {
        if (!$order) {
            return null;
        }

        $snapshot = CurrencySnapshot::fromOrder($order);
        if (!CurrencySnapshot::hasTrustedRate($snapshot)) {
            return null;
        }

        $quote = ['currency' => $snapshot['currency'], 'rate' => $snapshot['rate']];

        return PriceConverter::isBaseQuote($quote) ? null : $quote;
    }

    /**
     * Coupon::saving guard — a converted copy must never be written back.
     */
    public static function isConvertedCopy(Coupon $coupon): bool
    {
        return self::converted()->contains($coupon);
    }
}
