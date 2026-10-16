<?php

namespace FluentCart\App\Services;

use FluentCart\Api\Resource\CouponResource;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\MultiCurrency\Services\BaseCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySnapshot;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Modules\MultiCurrency\Services\PriceConverter;
use FluentCart\App\Modules\MultiCurrency\Services\RoundingRules;
use FluentCart\Framework\Support\Arr;

/**
 * Prices an admin-created order in the currency the admin picked. The
 * Create Order screen previews with it and the save prices again with it,
 * so the screen shows exactly what the order will store. A save without a
 * preview takes priceInPostedCurrency() in another currency, else asPosted().
 */
class AdminOrderPricing
{
    /**
     * The quote an admin-created order is priced at: the base currency, or
     * an enabled currency that has a rate.
     *
     * @param mixed $currency requested currency code
     * @return array|\WP_Error
     */
    public static function quoteFor($currency)
    {
        $currency = strtoupper(sanitize_text_field((string) $currency));

        if ($currency === '' || BaseCurrency::is($currency)) {
            return PriceConverter::baseQuote();
        }

        $quote = MultiCurrencySettings::isOffered($currency) ? CartCurrency::freshQuote($currency) : null;

        if (!$quote) {
            return new \WP_Error('invalid_currency', sprintf(
                /* translators: %1$s: currency code */
                __('%1$s is not an enabled currency with an exchange rate.', 'fluent-cart'),
                $currency
            ), ['status' => 422]);
        }

        return $quote;
    }

    /**
     * Shipping the admin entered, in the order's currency. Null (omitted)
     * is 0. Non-numeric and negative values are rejected on the raw value,
     * before rounding: roundCent(-0.4) is 0 and would read as free shipping.
     *
     * @param mixed $value
     * @throws \InvalidArgumentException
     */
    public static function shippingCents($value): int
    {
        if ($value === null) {
            return 0;
        }

        if (!is_numeric($value)) {
            throw new \InvalidArgumentException(
                'Shipping total must be a numeric cents amount or omitted, got: ' . gettype($value)
            );
        }

        if ((float) $value < 0) {
            throw new \InvalidArgumentException(
                'Shipping total cannot be a negative cents amount: ' . var_export($value, true)
            );
        }

        return Helper::roundCent($value);
    }

    /**
     * @param array $data currency, order_items, applied_coupon, discount, shipping_total
     * @param string $customerEmail for per-customer coupon limits
     * @param string|float|null $rate a CurrencySnapshot::storableRate() value, taken as final (no margin, rounding, ending or adjustment); null for the store's
     * @return array|\WP_Error quote, quote_revision, items, applied_coupons, shipping_total,
     *                         subtotal, coupon_discount_total, manual_discount_total, total
     * @throws \InvalidArgumentException on a malformed shipping_total
     */
    public static function price(array $data, $customerEmail = '', $rate = null)
    {
        $quote = static::quoteFor(Arr::get($data, 'currency'));
        if (is_wp_error($quote)) {
            return $quote;
        }

        if ($rate !== null) {
            $quote = array_merge($quote, [
                'rate'       => CurrencySnapshot::normalizeRate($rate),
                'rate_id'    => null,
                'source'     => CurrencySnapshot::SOURCE_EXTERNAL,
                'margin'     => '0',
                'rounding'   => RoundingRules::NONE,
                'charm'      => RoundingRules::NONE,
                'adjustment' => '0',
            ]);
        }

        $unit = RoundingRules::unit((string) $quote['currency']);

        if (!self::fitsInCents(Arr::get($data, 'shipping_total'))) {
            return self::amountError(__('Amount is too large.', 'fluent-cart'));
        }
        $shippingTotal = static::shippingCents(Arr::get($data, 'shipping_total'));
        if ($shippingTotal % $unit !== 0) {
            return self::amountError(__('Shipping must be in whole units of the order currency.', 'fluent-cart'));
        }

        $orderItems = (array) Arr::get($data, 'order_items', []);
        $variations = self::loadVariations($orderItems);

        $items = [];
        $lineShipping = 0;
        foreach ($orderItems as $index => $item) {
            $item = self::priceLine((array) $item, $quote, $unit, $variations);
            if (is_wp_error($item)) {
                return $item;
            }
            $items[$index] = $item;
            $lineShipping += $item['shipping_charge'];
        }
        if ($lineShipping > 0 && $lineShipping !== $shippingTotal) {
            return self::amountError(__('Item shipping charges must add up to the shipping total.', 'fluent-cart'));
        }

        $gross = (float) $shippingTotal;
        foreach ($items as $item) {
            $gross += ((float) $item['unit_price'] + (float) Arr::get($item, 'other_info.signup_fee', 0)) * (float) $item['quantity'];
        }
        if (!self::fitsInCents($gross)) {
            return self::amountError(__('Amount is too large.', 'fluent-cart'));
        }

        $couponQuote = PriceConverter::isBaseQuote($quote) ? null : $quote;
        $couponCheck = CurrencyContext::withCurrency($quote['currency'], function () use ($items, $data, $customerEmail, $couponQuote) {
            return CouponResource::validateOrderCoupons($items, (array) Arr::get($data, 'applied_coupon', []), (string) $customerEmail, $couponQuote);
        });
        if (is_wp_error($couponCheck)) {
            return $couponCheck;
        }

        $items = self::roundLineDiscounts($couponCheck['items'], $unit);
        $appliedCoupons = self::reconcileCouponRecords(
            (array) $couponCheck['applied_coupons'],
            array_sum(array_column($items, 'discount_total')),
            $unit
        );

        $discountValue = Arr::get($data, 'discount.value', 0);
        if (is_numeric($discountValue) && (float) $discountValue > 0) {
            $room = 0;
            $chargedTotal = 0;
            $hasTrial = false;
            foreach ($items as $item) {
                $chargedTotal += self::chargedNow($item);
                $hasTrial = $hasTrial || (int) Arr::get($item, 'other_info.trial_days', 0) > 0;
                $room += max(0, self::chargedNow($item) - (int) $item['discount_total']);
            }

            // toCent() turns 1e308 into 0
            if (!self::fitsInCents((float) $discountValue * 100)) {
                return self::amountError(__('Amount is too large.', 'fluent-cart'));
            }
            $manualDiscount = Helper::toCent($discountValue);
            if ($manualDiscount % $unit !== 0) {
                return self::amountError(__('The discount must be in whole units of the order currency.', 'fluent-cart'));
            }
            if ($manualDiscount > $room) {
                if ($hasTrial && $chargedTotal === 0) {
                    return self::amountError(__('Trial items can\'t take a manual discount.', 'fluent-cart'));
                }
                return self::amountError(__('The discount cannot be more than the order subtotal.', 'fluent-cart'));
            }

            static::distributeManualDiscount($items, $manualDiscount, $unit);
        }

        $subtotal = 0;
        $couponDiscountTotal = 0;
        $manualDiscountTotal = 0;
        foreach ($items as $item) {
            $quantity = (int) $item['quantity'];
            if ((int) Arr::get($item, 'other_info.trial_days', 0) <= 0) {
                $subtotal += (int) $item['unit_price'] * $quantity;
            }
            if (Arr::get($item, 'other_info.payment_type') === 'subscription') {
                $subtotal += (int) Arr::get($item, 'other_info.signup_fee', 0) * $quantity;
            }
            $couponDiscountTotal += (int) $item['discount_total'];
            $manualDiscountTotal += (int) $item['manual_discount'];
        }

        $total = max(0, $subtotal - $couponDiscountTotal - $manualDiscountTotal + $shippingTotal);

        return [
            'quote'                 => $quote,
            'quote_revision'        => self::revision($quote, $items, [$shippingTotal, $subtotal, $couponDiscountTotal, $manualDiscountTotal, $total]),
            'items'                 => $items,
            'applied_coupons'       => $appliedCoupons,
            'shipping_total'        => $shippingTotal,
            'subtotal'              => $subtotal,
            'coupon_discount_total' => $couponDiscountTotal,
            'manual_discount_total' => $manualDiscountTotal,
            'total'                 => $total,
        ];
    }

    /**
     * A save in another currency sent without a preview (REST callers):
     * priced on the server like the screen, at the posted rate when one is
     * sent, else the store's. A posted catalog line price or total must
     * match the server's, or the caller is told the prices changed.
     *
     * @param array $data currency, rate, total_amount, order_items, applied_coupon, discount, shipping_total
     * @param string $customerEmail for per-customer coupon limits
     * @return array|\WP_Error as price()
     * @throws \InvalidArgumentException on a malformed shipping_total
     */
    public static function priceInPostedCurrency(array $data, $customerEmail = '')
    {
        $rate = Arr::get($data, 'rate');
        if ($rate === '') {
            $rate = null;
        }
        if ($rate !== null) {
            $rate = CurrencySnapshot::storableRate($rate);
            if ($rate === null) {
                $quote = static::quoteFor(Arr::get($data, 'currency'));

                return is_wp_error($quote) ? $quote : self::amountError(__('Rate must be a number above 0.', 'fluent-cart'));
            }
        }

        $pricing = static::price($data, $customerEmail, $rate);
        if (is_wp_error($pricing)) {
            return $pricing;
        }

        $pricesUpdated = CartCurrency::pricesUpdatedError(['status' => 422, 'rate' => $pricing['quote']['rate']]);

        $pricedItems = array_values($pricing['items']);
        foreach (array_values((array) Arr::get($data, 'order_items', [])) as $index => $item) {
            $postedPrice = Arr::get($item, 'unit_price');
            if (Arr::get($item, 'has_custom_price') || $postedPrice === null || $postedPrice === '') {
                continue;
            }
            if (!self::fitsInCents($postedPrice) || Helper::roundCent($postedPrice) !== (int) $pricedItems[$index]['unit_price']) {
                return $pricesUpdated;
            }
        }

        $total = Arr::get($data, 'total_amount');
        // REST sanitizes an empty total_amount to 0
        if ((float) $total !== 0.0 && (!self::fitsInCents($total) || Helper::roundCent($total) !== $pricing['total'])) {
            return $pricesUpdated;
        }

        return $pricing;
    }

    /**
     * A save in the store currency sent without a preview (the documented
     * REST payload, PHP callers) keeps the pre multi-currency contract: the
     * posted line prices, coupons and manual discount applied on top.
     *
     * @param array $data order_items, applied_coupon, discount, shipping_total
     * @param string $customerEmail for per-customer coupon limits
     * @return array|\WP_Error quote, items, applied_coupons, shipping_total
     * @throws \InvalidArgumentException on a malformed shipping_total
     */
    public static function asPosted(array $data, $customerEmail = '')
    {
        $couponCheck = CouponResource::validateOrderCoupons(
            (array) Arr::get($data, 'order_items', []),
            (array) Arr::get($data, 'applied_coupon', []),
            (string) $customerEmail
        );
        if (is_wp_error($couponCheck)) {
            return $couponCheck;
        }

        $items = $couponCheck['items'];
        if (Helper::toCent(Arr::get($data, 'discount.value', 0)) > 0) {
            $chargedTotal = array_sum(array_map([static::class, 'chargedNow'], $items));
            $hasTrial = array_filter($items, function ($item) {
                return (int) Arr::get($item, 'other_info.trial_days', 0) > 0;
            });
            if ($hasTrial && $chargedTotal === 0) {
                return self::amountError(__('Trial items can\'t take a manual discount.', 'fluent-cart'));
            }
            static::distributeManualDiscount($items, Helper::toCent(Arr::get($data, 'discount.value', 0)));
        }

        return [
            'quote'           => PriceConverter::baseQuote(),
            'items'           => $items,
            'applied_coupons' => $couponCheck['applied_coupons'],
            'shipping_total'  => static::shippingCents(Arr::get($data, 'shipping_total')),
        ];
    }

    /**
     * Fingerprint of everything the preview shows, so a save is rejected when
     * any line changed, not only the rate. A trial's recurring price is not in
     * the total.
     */
    private static function revision(array $quote, array $items, array $totals): string
    {
        $lines = [];
        foreach ($items as $item) {
            $lines[] = [
                (int) Arr::get($item, 'object_id', 0),
                (int) $item['quantity'],
                (int) $item['unit_price'],
                (int) $item['discount_total'],
                (int) $item['manual_discount'],
                (int) $item['shipping_charge'],
                $item['other_info'],
            ];
        }

        return md5(CartCurrency::revisionOfQuote($quote) . wp_json_encode([$lines, $totals]));
    }

    /**
     * The coupon service spreads discounts in fractions of a cent. Round the
     * total to whole units of the currency, then give each line its floor plus
     * one unit by largest remainder, never past the line subtotal.
     */
    private static function roundLineDiscounts(array $items, int $unit): array
    {
        $raw = [];
        $caps = [];
        $floored = 0;
        foreach ($items as $key => $item) {
            $raw[$key] = max(0, (float) Arr::get($item, 'discount_total', 0));
            $caps[$key] = (int) $item['unit_price'] * (int) $item['quantity'];
            $items[$key]['discount_total'] = min($caps[$key], (int) (floor($raw[$key] / $unit) * $unit));
            $floored += $items[$key]['discount_total'];
        }

        $remainder = (int) (round(array_sum($raw) / $unit) * $unit) - $floored;
        $keys = array_keys($items);
        $positions = array_flip($keys);
        usort($keys, function ($left, $right) use ($raw, $items, $positions) {
            $byRemainder = ($raw[$right] - $items[$right]['discount_total']) <=> ($raw[$left] - $items[$left]['discount_total']);

            return $byRemainder !== 0 ? $byRemainder : $positions[$left] <=> $positions[$right];
        });
        foreach ($keys as $key) {
            if ($remainder < $unit) {
                break;
            }
            if ($items[$key]['discount_total'] + $unit <= $caps[$key]) {
                $items[$key]['discount_total'] += $unit;
                $remainder -= $unit;
            }
        }

        return $items;
    }

    /**
     * Coupon records are rounded on their own, so they can drift from the
     * line discounts. Move the difference so they sum to the order's coupon
     * total: a shortfall goes on the largest coupon, a surplus is taken from
     * the largest first, none below 0.
     */
    private static function reconcileCouponRecords(array $appliedCoupons, int $lineTotal, int $unit): array
    {
        $codes = [];
        $sum = 0;
        foreach ($appliedCoupons as $code => $appliedCoupon) {
            if (!is_array($appliedCoupon) || !isset($appliedCoupon['discount']) || !is_numeric($appliedCoupon['discount'])) {
                continue;
            }
            $appliedCoupons[$code]['discount'] = (int) (round((float) $appliedCoupon['discount'] / $unit) * $unit);
            $sum += $appliedCoupons[$code]['discount'];
            $codes[] = $code;
        }

        if (!$codes) {
            return $appliedCoupons;
        }

        // usort is not stable before PHP 8
        $positions = array_flip($codes);
        usort($codes, function ($left, $right) use ($appliedCoupons, $positions) {
            $byAmount = $appliedCoupons[$right]['discount'] <=> $appliedCoupons[$left]['discount'];

            return $byAmount !== 0 ? $byAmount : $positions[$left] <=> $positions[$right];
        });

        $difference = $lineTotal - $sum;
        if ($difference > 0) {
            $appliedCoupons[$codes[0]]['discount'] += $difference;
        }
        foreach ($codes as $code) {
            if ($difference >= 0) {
                break;
            }
            $taken = min(-$difference, $appliedCoupons[$code]['discount']);
            $appliedCoupons[$code]['discount'] -= $taken;
            $difference += $taken;
        }

        return $appliedCoupons;
    }

    /**
     * A catalog line takes its price from the product at the order's quote.
     * A custom price the admin typed is already in the order's currency and
     * is only bounds-checked.
     *
     * @param array $variations ProductVariation keyed by id, from loadVariations()
     * @return array|\WP_Error
     */
    private static function priceLine(array $item, array $quote, int $unit, array $variations)
    {
        $item['quantity'] = max(1, (int) Arr::get($item, 'quantity', 1));
        $item['discount_total'] = 0;
        $item['coupon_discount'] = 0;
        $item['manual_discount'] = 0;

        $variation = Arr::get($variations, (int) Arr::get($item, 'object_id', 0));

        if (!$variation || !$variation->product) {
            return new \WP_Error('invalid_product', __('A product on this order no longer exists.', 'fluent-cart'), ['status' => 422]);
        }

        $basePrice = apply_filters('fluent_cart/cart/item_price', $variation->item_price, [
            'variation' => $variation,
            'quantity'  => $item['quantity'],
        ]);
        $priced = CartCurrency::priceItem([
            'quantity'   => $item['quantity'],
            'unit_price' => max(0, (int) $basePrice),
            'other_info' => $variation->other_info,
        ], $quote);
        $item['other_info'] = $priced['other_info'];

        $shippingCharge = Arr::get($item, 'shipping_charge', 0);
        $item['shipping_charge'] = self::typedAmount($shippingCharge === null || $shippingCharge === '' ? 0 : $shippingCharge, $unit);
        if (is_wp_error($item['shipping_charge'])) {
            return $item['shipping_charge'];
        }

        $unitPrice = $priced['unit_price'];
        if (Arr::get($item, 'has_custom_price')) {
            $unitPrice = self::typedAmount(Arr::get($item, 'unit_price'), $unit);
            if (is_wp_error($unitPrice)) {
                return $unitPrice;
            }
        }

        return self::withLineTotals($item, $unitPrice);
    }

    /**
     * @return array ProductVariation keyed by id
     */
    private static function loadVariations(array $orderItems): array
    {
        $ids = array_values(array_unique(array_filter(array_map(function ($item) {
            return (int) Arr::get((array) $item, 'object_id', 0);
        }, $orderItems))));
        if (!$ids) {
            return [];
        }

        $variations = [];
        $rows = ProductVariation::query()->with('product')->whereIn('id', $ids)->get();
        foreach ($rows as $variation) {
            $variations[(int) $variation->id] = $variation;
        }

        return $variations;
    }

    private static function withLineTotals(array $item, int $unitPrice): array
    {
        $item['unit_price'] = $unitPrice;
        $item['price'] = $unitPrice;
        $item['subtotal'] = $unitPrice * $item['quantity'];
        $item['line_total'] = $item['subtotal'];

        return $item;
    }

    /**
     * @param mixed $value cents in the order's currency
     * @return int|\WP_Error
     */
    private static function typedAmount($value, int $unit)
    {
        if (!is_numeric($value) || (float) $value < 0) {
            return self::amountError(__('Prices must be zero or more.', 'fluent-cart'));
        }
        if (!self::fitsInCents($value)) {
            return self::amountError(__('Amount is too large.', 'fluent-cart'));
        }

        $cents = Helper::roundCent($value);
        if ($cents % $unit !== 0) {
            return self::amountError(__('Prices must be in whole units of the order currency.', 'fluent-cart'));
        }

        return $cents;
    }

    /**
     * Helper::roundCent() throws past this range instead of returning a 422.
     *
     * @param mixed $value
     */
    private static function fitsInCents($value): bool
    {
        return !is_numeric($value) || abs((float) $value) <= 9.0e15;
    }

    /**
     * A trial line's price is billed after the trial, not on this order.
     */
    private static function chargedNow(array $item): int
    {
        if ((int) Arr::get($item, 'other_info.trial_days', 0) > 0) {
            return 0;
        }

        return (int) Arr::get($item, 'unit_price', 0) * (int) Arr::get($item, 'quantity', 1);
    }

    private static function amountError($message): \WP_Error
    {
        return new \WP_Error('invalid_amount', $message, ['status' => 422]);
    }

    /**
     * Spread the manual discount over the lines by their share of the
     * subtotal, in whole units of the order's currency. Each line takes at
     * most what it has left after its coupon discount (AdminOrderProcessor
     * subtracts both from the line), so no line total goes negative.
     *
     * @param int $unit the order currency's smallest unit in cents (100 for
     *                  a zero-decimal currency): each line's share is whole units
     */
    public static function distributeManualDiscount(&$items, $manualDiscountTotal, int $unit = 1)
    {
        $totalSubtotal = array_reduce($items, function ($carry, $item) {
            return $carry + self::chargedNow($item);
        }, 0);

        if ($totalSubtotal <= 0) {
            return;
        }

        $distributed = 0;
        $capacities = [];
        foreach ($items as $key => &$checkoutItem) {
            $itemSubtotal = self::chargedNow($checkoutItem);
            $capacity = max(0, $itemSubtotal - (int)Arr::get($checkoutItem, 'discount_total', 0));

            $itemManualDiscount = (int) (floor(($itemSubtotal / $totalSubtotal) * $manualDiscountTotal / $unit) * $unit);
            $itemManualDiscount = max(0, min($itemManualDiscount, $capacity));

            $distributed += $itemManualDiscount;
            $capacities[$key] = $capacity - $itemManualDiscount;

            Arr::set($checkoutItem, 'manual_discount', $itemManualDiscount);
        }
        unset($checkoutItem);

        $remainder = (int) round($manualDiscountTotal - $distributed);
        $positions = array_flip(array_keys($items));
        // Bounded by the line count, not the amount: every bulk round leaves
        // less than a unit per open line or a line with less than a unit of
        // room, and every pass after it ends the remainder or fills a line.
        // The round limit only backs that up.
        $rounds = 2 * count($items) + 2;
        while ($remainder > 0 && $rounds-- > 0) {
            $open = array_filter($capacities, function ($capacity) {
                return $capacity > 0;
            });
            if (!$open) {
                break;
            }

            // A line capped at its capacity leaves a shortfall that can be
            // most of the discount, not rounding dust. Give every open line
            // the same whole units at once - as many full passes of a unit
            // each as the remainder and the smallest open line allow. That is
            // what passing a unit at a time would give them, without
            // iterating once per cent or yen
            $fullPasses = min(
                intdiv($remainder, count($open) * $unit),
                intdiv(min($open), $unit)
            );
            if ($fullPasses > 0) {
                $step = $fullPasses * $unit;
                foreach (array_keys($open) as $key) {
                    $items[$key]['manual_discount'] += $step;
                    $capacities[$key] -= $step;
                    $remainder -= $step;
                }
                continue;
            }

            // Less than a unit per open line is left (or a line has less than
            // a unit of room): one pass of a unit each, largest remaining
            // capacity first (ties by position: usort is not stable before
            // PHP 8), never past a line's capacity
            $keys = array_keys($open);
            usort($keys, function ($left, $right) use ($capacities, $positions) {
                $byCapacity = $capacities[$right] <=> $capacities[$left];

                return $byCapacity !== 0 ? $byCapacity : $positions[$left] <=> $positions[$right];
            });
            foreach ($keys as $key) {
                if ($remainder <= 0) {
                    break;
                }
                $step = min($unit, $remainder, $capacities[$key]);
                $items[$key]['manual_discount'] += $step;
                $capacities[$key] -= $step;
                $remainder -= $step;
            }
        }
    }
}
