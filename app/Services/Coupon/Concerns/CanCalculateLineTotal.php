<?php

namespace FluentCart\App\Services\Coupon\Concerns;

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Coupon;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;

trait CanCalculateLineTotal
{
    protected array $discountData = [];

    public function calculateLineItems()
    {
        $lineItems = $this->prepareLineItems($this->lineItems);

        $this->discountData = [];
        // $firstTime determines whether to use the original price or the discounted price as the current price for calculations

        foreach ($this->applicableCoupons as $coupon) {
            $validated = $this->validate($coupon->code);
            if (is_wp_error($validated)) {
                $this->couponErrors->put($coupon->code, $validated);
                continue;
            }

            $this->applyCouponToAllItems($coupon, $lineItems);
            $this->previouslyAppliedCouponCodes[] = $coupon->code;
            $this->previouslyAppliedCoupons->put($coupon->code, $coupon);
        }

        $this->formatDiscountNumbers();
        $this->calculatedLineItems = $lineItems;

    }

    protected function prepareLineItems(array $lineItems = []): array
    {
        foreach ($lineItems as $index => $lineItem) {
            $lineItems[$index]['price'] = $lineItem['unit_price'];
            $lineItems[$index]['discount_total'] = 0;
        }

        return $lineItems;
    }

    protected function formatDiscountNumbers()
    {
        foreach ($this->discountData as $code => $discount) {
            $formattedTitle = $code;

            if ($discount['type'] === 'percentage') {
                $formattedTitle .= ' (' . $discount['actual_amount'] . '%)';
            }

            $this->discountData[$code]['formatted_discount'] = CurrencySettings::getPriceHtml($discount['discount']);
            $this->discountData[$code]['actual_formatted_discount'] = CurrencySettings::getPriceHtml(
                $discount['discount']
            );
            $this->discountData[$code]['formatted_title'] = $formattedTitle;

        }
    }

    public function getDiscountData(): array
    {
        return $this->discountData;
    }

    private function applyCouponToAllItems(Coupon $coupon, array &$lineItems)
    {
        $this->ensureCouponExistInDiscountData($coupon);

        $filteredItems = Collection::make($lineItems)->filter(function ($item) use ($coupon) {
            return $this->isApplicableToProduct($coupon, $item['post_id'], $item['id']);
        });

        $totalNetPrice = $filteredItems->reduce(function ($carry, $item) {
            return $carry + (($item['unit_price'] * $item['quantity']) - $item['discount_total']);
        }, 0);

        $applicableAmount = $this->calculateApplicableAmount($coupon, $totalNetPrice);

        $lineItems = Collection::make($lineItems)->keyBy('id')->toArray();


        $discountAmount = $this->distributeDiscount($filteredItems, $lineItems, $applicableAmount);

        $this->discountData[$coupon->code]['discount'] += round($discountAmount, 2);
        $this->discountData[$coupon->code]['unit_amount'] = round($discountAmount, 2);
        $this->discountData[$coupon->code]['actual_quantity'] = $filteredItems->sum('quantity');

    }

    private function distributeDiscount(Collection $filteredItems, array &$lineItems, float &$remainingDiscount, int $depth = 0): float
    {
        $totalApplied = 0;
        $totalNetPrice = $filteredItems->reduce(function ($carry, $item) use ($lineItems) {
            $id = $item['id'];
            $line = Arr::get($lineItems, $id);

            if (empty($line)) {
                return $carry;
            }

            return $carry + (($line['unit_price'] * $line['quantity']) - $line['discount_total']);
        }, 0);

        if ($totalNetPrice <= 0 || $remainingDiscount <= 0.01 || $depth > 10) {
            return 0;
        }

        foreach ($filteredItems as $item) {
            $id = $item['id'];
            if (!isset($lineItems[$id])) continue;

            $lineItem = &$lineItems[$id];
            $itemNetTotal = ($lineItem['unit_price'] * $lineItem['quantity']) - $lineItem['discount_total'];
            if ($itemNetTotal <= 0) {
                continue;
            }

            $itemShare = ($itemNetTotal / $totalNetPrice) * $remainingDiscount;
            $itemShare = round($itemShare, 2);

            $applied = min($itemShare, $itemNetTotal, $remainingDiscount);
            $lineItem['discount_total'] += $applied;
            $remainingDiscount -= $applied;
            $totalApplied += $applied;

            if ($remainingDiscount <= 0.01) {
                break;
            }
        }

        // Recursive call if leftover remains and there's still room
        if ($remainingDiscount > 0.01) {
            $totalApplied += $this->distributeDiscount($filteredItems, $lineItems, $remainingDiscount, $depth + 1);
        }

        return $totalApplied;
    }


    public function calculateApplicableAmount(Coupon $coupon, $totalPrice)
    {
        $amount = $coupon->amount;

        $cart = CartResource::get([
            'hash' => App::request()->get(Helper::INSTANT_CHECKOUT_URL_PARAM)
        ]);

        if ($cart) {
            $upgradeDiscount = Arr::get($cart, 'checkout_data.manual_discount.amount', 0);
            $totalPrice = $totalPrice - $upgradeDiscount;
            if ($totalPrice < 0) {
                $totalPrice = 0;
            }
        }

        if ($coupon->type === 'percentage') {
            $amount = ($amount / 100) * $totalPrice;
        }
        $maxAmount = Arr::get($coupon->conditions, 'max_discount_amount', 0);
        if (!empty($maxAmount) && is_numeric($maxAmount)) {
            return min($totalPrice, $amount, $maxAmount);
        }

        return min($totalPrice, $amount);
    }

}
