<?php

namespace FluentCart\App\Services\Coupon;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\Customer;
use FluentCart\Framework\Support\Arr;

class DiscountService
{
    protected $cart = null;

    protected $cartItems = [];

    protected $customer = null;

    protected $appliedCoupons = [];

    protected $validCoupons = [];

    protected $invalidCoupons = [];

    protected $perCouponDiscounts = [];

    public function __construct(?Cart $cart = null, $cartItems = [], $customer = null)
    {
        $this->cart = $cart;

        if ($cartItems) {
            $this->cartItems = $cartItems;
        } else if ($cart) {
            $this->cartItems = $cart->cart_data;
        }

        if ($customer) {
            $this->customer = $customer;
        }
    }

    public function resetIndividualItemsDiscounts()
    {
        foreach ($this->cartItems as &$item) {
            $item['discount_total'] = Arr::get($item, 'manual_discount', 0);
            $item['coupon_discount'] = 0;
            $item['line_total'] = (int)($item['subtotal'] - $item['discount_total']);
            if (isset($item['recurring_discounts'])) {
                unset($item['recurring_discounts']);
            }
        }

        $this->cartItems = array_values($this->cartItems);
        $this->cart->cart_data = $this->cartItems;
        $this->cart->save();
        return $this;
    }

    public function revalidateCoupons()
    {
        if ($this->cart && $this->cart->coupons) {
            return $this->applyCouponCodes($this->cart->coupons);
        }

        return new \WP_Error('no_coupons', __('No coupons found to revalidate.', 'fluent-cart'));
    }

    public function applyCouponCodes($codes = [])
    {
        if (!is_array($codes)) {
            $codes = [$codes];
        }

        $existingCoupons = $this->cart ? $this->cart->coupons : [];

        if (!$existingCoupons || !is_array($existingCoupons)) {
            $existingCoupons = [];
        }

        $codes = array_merge($existingCoupons, $codes);

        $codes = array_map('trim', $codes);
        $codes = array_filter($codes);
        $codes = array_unique($codes);
        $codes = array_values($codes);

        $coupons = Coupon::query()->whereIn('code', $codes)->get();

        /*
         * Allow addons to resolve codes that do not exist in fct_coupons into in-memory
         * (virtual) Coupon models — e.g. a wallet / store-credit integration that applies a
         * discount without persisting a coupon. The filter receives the DB-found coupons,
         * the requested codes, and the cart; it may append unsaved Coupon instances.
         */
        $coupons = apply_filters('fluent_cart/coupon/resolve_coupons', $coupons, $codes, [
            'cart' => $this->cart,
        ]);

        if ($coupons->isEmpty()) {
            return new \WP_Error('no_valid_coupons', __('No matching coupon found for this code.', 'fluent-cart'), []);
        }

        $invalidCoupons = [];

        $formattedCoupons = $this->formatCoupons($coupons, $codes);
        $validCoupons = [];

        foreach ($formattedCoupons as $coupon) {
            $validCoupon = $this->isCouponValid($coupon);
            if (is_wp_error($validCoupon)) {
                $invalidCoupons[$coupon->code] = [
                    'error'      => $validCoupon->get_error_message(),
                    'error_code' => $validCoupon->get_error_code()
                ];
            } else {
                $validCoupons[] = $coupon;
            }
        }

        if (empty($validCoupons)) {
            $message = __('Coupon can not be applied.', 'fluent-cart');
            if (!empty($invalidCoupons)) {
                $firstInvalid = reset($invalidCoupons);
                if (!empty($firstInvalid['error'])) {
                    $message = $firstInvalid['error'];
                }
            }
            return new \WP_Error('no_valid_coupons', $message, $invalidCoupons);
        }

        // Stacking contract — the first coupon applied always stays (parity with
        // the admin path, CanValidateCoupon::canBeStacked). applyCouponCodes()
        // merges existing cart coupons before newly submitted codes and
        // formatCoupons() preserves that order, so index 0 is genuinely the
        // first-applied valid coupon. A non-stackable first coupon locks the
        // cart to itself; a stackable first admits only later stackable codes.
        if (count($validCoupons) >= 2) {
            $firstCoupon = $validCoupons[0];
            $intermediateValidCoupons = [$firstCoupon];
            foreach (array_slice($validCoupons, 1) as $coupon) {
                if ($firstCoupon->stackable === 'yes' && $coupon->stackable === 'yes') {
                    $intermediateValidCoupons[] = $coupon;
                } else {
                    $invalidCoupons[$coupon->code] = [
                        'success'    => false,
                        'error'      => __('This coupon cannot be stacked with other coupons.', 'fluent-cart'),
                        'error_code' => 'coupon_not_stackable'
                    ];
                }
            }

            $validCoupons = $intermediateValidCoupons;
        }

        // Ensure stackable coupons are applied in priority order (lower value = higher priority)
        if (count($validCoupons) >= 2) {
            usort($validCoupons, function ($a, $b) {
                $priorityA = isset($a->priority) ? (int)$a->priority : 0;
                $priorityB = isset($b->priority) ? (int)$b->priority : 0;

                if ($priorityA === $priorityB) {
                    return 0;
                }

                return ($priorityA < $priorityB) ? -1 : 1;
            });
        }

        // Now we have all the valid and stackable coupons. Let's apply them to the cart.
        $this->resetIndividualItemsDiscounts();

        foreach ($validCoupons as $index => $coupon) {
            $result = $this->apply($coupon);
            if (is_wp_error($result)) {
                $invalidCoupons[$coupon->code] = [
                    'success'    => false,
                    'error'      => $result->get_error_message(),
                    'error_code' => $result->get_error_code()
                ];
                unset($validCoupons[$index]);
            }
        }

        $this->validCoupons = $validCoupons;
        $this->invalidCoupons = $invalidCoupons;

        return $this->getResult();
    }

    public function getResult()
    {
        $couponResults = $this->invalidCoupons;

        foreach ($this->validCoupons as $validCoupon) {
            $couponResults[$validCoupon->code] = [
                'success' => true,
                'coupon'  => $validCoupon
            ];
        }

        return [
            'applied_coupon_codes' => $this->appliedCoupons,
            'coupon_results'       => $couponResults,
            'cart_items'           => $this->cartItems,
            'per_coupon_discounts' => $this->perCouponDiscounts
        ];
    }

    public function getCartItems()
    {
        return $this->cartItems;
    }

    public function getPerCouponDiscounts()
    {
        return $this->perCouponDiscounts;
    }

    public function getAppliedCoupons()
    {
        return $this->appliedCoupons;
    }

    public function apply(Coupon $coupon)
    {
        $cartItems = apply_filters('fluent_cart/discount/pre_apply', $this->cartItems, [
            'coupon' => $coupon,
            'cart'   => $this->cart,
        ]);

        $canUseCheck = $this->checkCanUseCoupon($coupon, $cartItems);
        if (is_wp_error($canUseCheck)) {
            return $canUseCheck;
        }

        $preValidatedItems = $this->filterApplicableItems($cartItems, $coupon);
        if (empty($preValidatedItems)) {
            return new \WP_Error('no_applicable_items', __('No applicable items found for this coupon.', 'fluent-cart'));
        }

        $currentItemsSubtotal = $this->calculateItemsSubtotal($preValidatedItems);
        $currentItemsDiscountTotal = $this->calculateExistingCouponDiscount($preValidatedItems);
        $currentItemsTotalAfterDiscount = $currentItemsSubtotal - $currentItemsDiscountTotal;

        if ($currentItemsTotalAfterDiscount <= 0) {
            return new \WP_Error('items_already_discounted', __('The eligible items are already fully discounted by another coupon.', 'fluent-cart'));
        }

        $percent = $this->calculateDiscountPercent($coupon, $currentItemsTotalAfterDiscount);

        // Snapshot per-item discounts before this coupon runs so the max-discount
        // cap can trim only THIS coupon's contribution — stacked coupons applied
        // earlier must keep their share untouched.
        $preCouponDiscounts = [];
        $preRecurringDiscounts = [];
        foreach ($preValidatedItems as $preItem) {
            $preCouponDiscounts[$preItem['id']] = (int) Arr::get($preItem, 'coupon_discount', 0);
            $preRecurringDiscounts[$preItem['id']] = (int) Arr::get($preItem, 'recurring_discounts.amount', 0);
        }

        list($preValidatedItems, $couponDiscountTotal) = $this->applyDiscountToItems($preValidatedItems, $percent, $coupon);

        if ($coupon->type === 'fixed') {
            list($preValidatedItems, $couponDiscountTotal) = $this->correctFixedCouponRounding(
                $preValidatedItems, $coupon, $couponDiscountTotal
            );
        }

        $maxDiscountAmount = (int) Arr::get($coupon->conditions, 'max_discount_amount', 0);
        if ($maxDiscountAmount > 0) {
            list($preValidatedItems, $couponDiscountTotal) = $this->capDiscountAtMax(
                $preValidatedItems, 'coupon_discount', $maxDiscountAmount, $preCouponDiscounts
            );
            // The per-renewal discount must honor the same cap, otherwise every
            // renewal charge overshoots it.
            list($preValidatedItems) = $this->capDiscountAtMax(
                $preValidatedItems, 'recurring_discounts.amount', $maxDiscountAmount, $preRecurringDiscounts
            );
        }

        $cartItems = $this->mergeValidatedItems($cartItems, $preValidatedItems);

        if (!$couponDiscountTotal) {
            return new \WP_Error('no_discount_applied', __('This coupon does not provide any additional discount on your order.', 'fluent-cart'));
        }

        $cartItems = $this->updateItemTotals($cartItems);

        $this->cartItems = array_values($cartItems);
        $this->appliedCoupons[] = $coupon->code;
        $this->perCouponDiscounts[$coupon->code] = $couponDiscountTotal;

        return true;
    }

    private function checkCanUseCoupon(Coupon $coupon, array $cartItems)
    {
        $canUse = apply_filters('fluent_cart/coupon/can_use_coupon', true, [
            'coupon'     => $coupon,
            'cart'       => $this->cart,
            'cart_items' => $cartItems,
        ]);

        if (!$canUse || is_wp_error($canUse)) {
            $message = __('This coupon is not available for your order.', 'fluent-cart');
            if (is_wp_error($canUse)) {
                $message = $canUse->get_error_message();
            }
            return new \WP_Error('coupon_cannot_be_used', $message);
        }

        return true;
    }

    private function filterApplicableItems(array $cartItems, Coupon $coupon)
    {
        $conditions = $coupon->conditions;

        $filtered = array_filter($cartItems, function ($item) use ($coupon, $conditions) {
            $willPreSkip = apply_filters('fluent_cart/coupon/will_skip_item', false, [
                'item'   => $item,
                'coupon' => $coupon,
                'cart'   => $this->cart
            ]);

            if ($willPreSkip || Arr::get($item, 'other_info.is_locked') === 'yes') {
                return false;
            }

            $excludedProducts = Arr::get($conditions, 'excluded_products', []);
            if ($excludedProducts && in_array($item['object_id'], $excludedProducts)) {
                return false;
            }

            $includedProducts = Arr::get($conditions, 'included_products', []);
            if (!is_array($includedProducts)) {
                $includedProducts = [];
            }
            if ($includedProducts && !in_array($item['object_id'], $includedProducts)) {
                return false;
            }

            $includedCategories = Arr::get($conditions, 'included_categories', []);
            if (!is_array($includedCategories)) {
                $includedCategories = [];
            }

            $excludedCategories = Arr::get($conditions, 'excluded_categories', []);
            if (!is_array($excludedCategories)) {
                $excludedCategories = [];
            }

            if ($includedCategories || $excludedCategories) {
                $productCategoryIds = $this->getProductCategories(Arr::get($item, 'post_id'));
                if ($includedCategories) {
                    $intersect = array_intersect($includedCategories, $productCategoryIds);
                    if (empty($intersect)) {
                        return false;
                    }
                }

                if ($excludedCategories) {
                    $intersect = array_intersect($excludedCategories, $productCategoryIds);
                    if (!empty($intersect)) {
                        return false;
                    }
                }
            }

            $emailRestrictions = trim(Arr::get($conditions, 'email_restrictions', ''));
            if ($emailRestrictions) {
                $customerEmail = $this->cart ? $this->cart->email : '';
                if (!$customerEmail) {
                    return false;
                }

                $allowedEmails = array_filter(array_map('trim', explode(',', $emailRestrictions)));
                if ($allowedEmails) {
                    foreach ($allowedEmails as $email) {
                        $pattern = '/^' . str_replace('\*', '.*', preg_quote($email, '/')) . '$/i';
                        if (preg_match($pattern, $customerEmail)) {
                            return true;
                        }
                    }

                    return false;
                }
            }

            return true;
        });

        return array_values(array_filter($filtered));
    }

    private function calculateItemsSubtotal(array $items)
    {
        return array_sum(array_map(function ($item) {
            return $this->getItemEffectiveSubtotal($item);
        }, $items));
    }

    private function calculateExistingCouponDiscount(array $items)
    {
        return array_sum(array_map(function ($item) {
            return (int) Arr::get($item, 'coupon_discount', 0);
        }, $items));
    }

    private function calculateDiscountPercent(Coupon $coupon, $totalAfterDiscount)
    {
        if ($coupon->type == 'fixed') {
            if ($coupon->amount >= $totalAfterDiscount) {
                return 100.0;
            }
            return round(($coupon->amount / $totalAfterDiscount) * 100, 2);
        }

        return round(min(100, max(0, (float) $coupon->amount)), 2);
    }

    private function applyDiscountToItems(array $items, $percent, Coupon $coupon)
    {
        $couponDiscountTotal = 0;

        foreach ($items as $index => $item) {
            $existingAmount = (int) Arr::get($item, 'coupon_discount', 0);
            $itemSubtotal = $this->getItemEffectiveSubtotal($item);
            $hasTrialDays = Arr::get($item, 'other_info.payment_type') === 'subscription'
                && Arr::get($item, 'other_info.trial_days', 0) > 0;

            $remainingTotal = max(0, $itemSubtotal - $existingAmount);
            $currentDiscount = (int) round($remainingTotal * ($percent / 100));
            $discountTotal = min($existingAmount + $currentDiscount, $itemSubtotal);
            $netDiscount = max(0, $discountTotal - $existingAmount);

            $couponDiscountTotal += $netDiscount;
            $items[$index]['coupon_discount'] = $discountTotal;

            // Apply recurring discount for non-trial subscriptions
            if (Arr::get($item, 'other_info.payment_type') === 'subscription' && !$hasTrialDays) {
                if (!isset($items[$index]['recurring_discounts'])) {
                    $items[$index]['recurring_discounts'] = [
                        'signup' => 0,
                        'amount' => 0
                    ];
                }

                if ($coupon->isRecurringDiscount()) {
                    $unitPrice = (int) Arr::get($item, 'unit_price', 0);
                    if ($unitPrice > 0) {
                        $previousAmount = (int) Arr::get($item, 'recurring_discounts.amount', 0);
                        $remainingRecurring = max(0, $unitPrice - $previousAmount);
                        $recurringDiscount = (int) round($remainingRecurring * ($percent / 100));
                        $totalRecurringDiscount = min($previousAmount + $recurringDiscount, $unitPrice);

                        Arr::set($items, $index . '.recurring_discounts.amount', $totalRecurringDiscount);
                    }
                }
            }
        }

        return [$items, $couponDiscountTotal];
    }

    /**
     * Clamp this coupon's total contribution under $valueKey to $maxAmount,
     * scaling each item's share proportionally (cents in, cents out).
     *
     * $preValues holds each item's value before this coupon ran, keyed by item
     * id — only the delta above it (this coupon's share) is ever reduced.
     *
     * @return array [items, appliedTotalForThisCoupon]
     */
    private function capDiscountAtMax(array $items, $valueKey, $maxAmount, array $preValues)
    {
        $shares = [];
        $totalShare = 0;
        foreach ($items as $index => $item) {
            $current = (int) Arr::get($item, $valueKey, 0);
            $pre = (int) Arr::get($preValues, $item['id'], 0);
            $share = max(0, $current - $pre);
            if ($share > 0) {
                $shares[$index] = $share;
                $totalShare += $share;
            }
        }

        if ($totalShare <= $maxAmount) {
            return [$items, $totalShare];
        }

        $capped = [];
        $cappedTotal = 0;
        foreach ($shares as $index => $share) {
            $cappedShare = (int) floor(($share * $maxAmount) / $totalShare);
            $capped[$index] = $cappedShare;
            $cappedTotal += $cappedShare;
        }

        // floor() can leave a few cents of the cap unassigned — hand them out
        // to items that still have room so the total lands exactly on the cap.
        $leftover = $maxAmount - $cappedTotal;
        foreach ($shares as $index => $share) {
            if ($leftover <= 0) {
                break;
            }
            $room = $share - $capped[$index];
            if ($room <= 0) {
                continue;
            }
            $add = min($room, $leftover);
            $capped[$index] += $add;
            $leftover -= $add;
        }

        foreach ($capped as $index => $cappedShare) {
            $pre = (int) Arr::get($preValues, $items[$index]['id'], 0);
            Arr::set($items, $index . '.' . $valueKey, $pre + $cappedShare);
        }

        return [$items, $maxAmount];
    }

    private function correctFixedCouponRounding(array $items, Coupon $coupon, $couponDiscountTotal)
    {
        if ($couponDiscountTotal < $coupon->amount) {
            $remainingAmount = $coupon->amount - $couponDiscountTotal;
            foreach ($items as $index => $item) {
                if ($remainingAmount <= 0) {
                    break;
                }

                $subtotal = $this->getItemEffectiveSubtotal($item);
                $maximumReduction = (int) ($subtotal - Arr::get($item, 'coupon_discount', 0));
                if ($maximumReduction <= 0) {
                    continue;
                }

                $newDiscountAmount = min($maximumReduction, $remainingAmount);
                $items[$index]['coupon_discount'] = Arr::get($item, 'coupon_discount', 0) + $newDiscountAmount;
                $couponDiscountTotal += $newDiscountAmount;
                $remainingAmount -= $newDiscountAmount;
            }
        } else if ($couponDiscountTotal > $coupon->amount) {
            $excessAmount = $couponDiscountTotal - $coupon->amount;
            foreach ($items as $index => $item) {
                if ($excessAmount <= 0) {
                    break;
                }

                $existingDiscount = Arr::get($item, 'coupon_discount', 0);
                if ($existingDiscount <= 0) {
                    continue;
                }

                $newReductionAmount = min($existingDiscount, $excessAmount);
                $items[$index]['coupon_discount'] = $existingDiscount - $newReductionAmount;
                $couponDiscountTotal -= $newReductionAmount;
                $excessAmount -= $newReductionAmount;
            }
        }

        return [$items, $couponDiscountTotal];
    }

    private function mergeValidatedItems(array $cartItems, array $validatedItems)
    {
        foreach ($cartItems as $index => $item) {
            foreach ($validatedItems as $preItem) {
                if ($item['id'] == $preItem['id']) {
                    $cartItems[$index] = $preItem;
                    break;
                }
            }
        }

        return $cartItems;
    }

    private function updateItemTotals(array $cartItems)
    {
        foreach ($cartItems as &$item) {
            $item['discount_total'] = (int) (Arr::get($item, 'manual_discount', 0) + Arr::get($item, 'coupon_discount', 0));
            $subtotal = $this->getItemEffectiveSubtotal($item);
            $item['line_total'] = max(0, (int) ($subtotal - $item['discount_total']));
        }

        return $cartItems;
    }

    private function getItemEffectiveSubtotal(array $item)
    {
        if (Arr::get($item, 'other_info.payment_type') === 'subscription'
            && Arr::get($item, 'other_info.trial_days', 0) > 0
        ) {
            $quantity = max(1, (int) Arr::get($item, 'quantity', 1));
            // When dynamic RC has already adjusted signup_fee to the net amount, use the
            // pre-adjustment gross value so the coupon always applies to the original price.
            $signupFee = Arr::get($item, 'other_info.original_signup_fee') !== null
                ? (int) Arr::get($item, 'other_info.original_signup_fee')
                : (int) Arr::get($item, 'other_info.signup_fee', 0);
            return $signupFee * $quantity;
        }

        // When dynamic RC has already reduced unit_price to the net (tax-stripped) amount,
        // use the saved gross price so the coupon is always calculated against the original
        // inclusive price — regardless of whether VAT number was entered before or after
        // the coupon was applied.
        $originalUnitPrice = Arr::get($item, 'line_meta.original_unit_price');
        if ($originalUnitPrice !== null) {
            $quantity = max(1, (int) Arr::get($item, 'quantity', 1));
            return (int) $originalUnitPrice * $quantity;
        }

        return (int) $item['subtotal'];
    }

    public function saveCart()
    {
        if (!$this->cart) {
            return new \WP_Error('no_cart', __('No cart found to save.', 'fluent-cart'));
        }

        $existingCheckoutData = $this->cart->checkout_data;

        if (!is_array($existingCheckoutData)) {
            $existingCheckoutData = [];
        }

        $existingCheckoutData['__per_coupon_discounts'] = $this->perCouponDiscounts;

        $this->cart->cart_data = $this->cartItems;
        $this->cart->coupons = $this->appliedCoupons;
        $this->cart->save();
        return $this->cart;
    }

    protected function formatCoupons($coupons, $codes)
    {
        $coupons = $coupons->keyBy('code');
        $formatted = [];

        foreach ($codes as $code) {
            if (isset($coupons[$code])) {
                $formatted[] = $coupons[$code];
            }
        }

        return $formatted;
    }

    protected function isCouponValid($coupon)
    {
        $status = $coupon->status;
        if ($status === 'expired') {
            return new \WP_Error('coupon_expired', __('This coupon has expired.', 'fluent-cart'));
        }
        if ($status === 'scheduled') {
            return new \WP_Error('coupon_not_started', __('This coupon is not yet active.', 'fluent-cart'));
        }
        if ($status !== 'active') {
            return new \WP_Error('coupon_not_available', __('This coupon is not currently available.', 'fluent-cart'));
        }

        // let's validate the start date and end date first
        $startDate = $coupon->start_date;
        if ($startDate && $startDate != '0000-00-00 00:00:00' && strtotime($startDate) > time()) {
            return new \WP_Error('coupon_not_started', __('This coupon is not yet active.', 'fluent-cart'));
        }
        $endDate = $coupon->end_date;
        if ($endDate && $endDate != '0000-00-00 00:00:00' && strtotime($endDate) < time()) {
            return new \WP_Error('coupon_expired', __('This coupon has expired.', 'fluent-cart'));
        }

        $conditions = $coupon->conditions;

        // The spend limits below (min/max) are measured against either the cart subtotal
        // (items only) or the full order total (shipping + fees included), per the coupon's
        // min_amount_basis setting. Coupons created before this setting existed have no stored
        // value and historically compared against the order total, so the fallback stays 'total'
        // to preserve their behavior. New coupons default to 'subtotal' in the admin UI.
        $amountBasis = Arr::get($conditions, 'min_amount_basis', 'total');

        // add check max_purchase_amount
        $maxPurchaseAmount = Arr::get($conditions, 'max_purchase_amount', 0);
        $getCartTotal = 0;
        if ($this->cart) {
            $cartAmount = $amountBasis === 'total'
                ? $this->cart->getEstimatedTotal()
                : $this->cart->getItemsSubtotal();
            $getCartTotal = ($cartAmount / 100);
        }

        if ($maxPurchaseAmount) {
            if ($getCartTotal > $maxPurchaseAmount) {
                return new \WP_Error('max_purchase_amount_exceeded', __('Your cart total exceeds the maximum amount allowed for this coupon.', 'fluent-cart'));
            }
        }

        $minPurchaseAmount = Arr::get($conditions, 'min_purchase_amount', 0);
        if ($minPurchaseAmount) {
            if ($getCartTotal < ($minPurchaseAmount / 100)) {
                return new \WP_Error('min_purchase_amount_not_met', __('Your cart total is below the minimum required to use this coupon.', 'fluent-cart'));
            }
        }

        // Let's check the use count and max uses
        $useCount = $coupon->use_count;
        $maxUses = Arr::get($conditions, 'max_uses', 0);
        if ($useCount && $maxUses && $useCount >= $maxUses) {
            return new \WP_Error('coupon_max_uses_exceeded', __('This coupon has reached its maximum number of uses.', 'fluent-cart'));
        }
        $maxPerCustomer = Arr::get($conditions, 'max_per_customer', 0);
        if ($maxPerCustomer) {
            if (!is_user_logged_in()) {
                return new \WP_Error('coupon_login_required', __('Please log in to use this coupon.', 'fluent-cart'));
            }

            $customer = $this->resolveCustomerForUsageLimit();
            if ($customer) {
                $usageQuery = AppliedCoupon::query()
                    ->where('coupon_id', $coupon->id)
                    ->whereHas('order', function ($orderQuery) use ($customer) {
                        $orderQuery->where('customer_id', $customer->id);
                    });

                $usageQuery = apply_filters('fluent_cart/coupon/per_customer_usage_query', $usageQuery, [
                    'coupon'   => $coupon,
                    'customer' => $customer,
                    'cart'     => $this->cart,
                ]);

                $usedCount = $usageQuery->count();

                if ($usedCount >= $maxPerCustomer) {
                    return new \WP_Error('coupon_max_uses_exceeded', __('You have already used this coupon the maximum number of times.', 'fluent-cart'));
                }
            }
        }

        return $coupon;
    }

    protected function resolveCustomerForUsageLimit()
    {
        if (!is_user_logged_in()) {
            return null;
        }

        $customer = $this->getCustomer();
        if ($customer) {
            return $customer;
        }

        $customer = Customer::query()->where('user_id', get_current_user_id())->first();
        if ($customer) {
            $this->customer = $customer;
            return $customer;
        }

        return null;
    }

    public function setCustomer(Customer $customer)
    {
        $this->customer = $customer;
    }

    public function getCustomer()
    {
        if ($this->customer) {
            return $this->customer;
        }

        if ($this->cart) {
            $this->customer = $this->cart->guessCustomer();
            return $this->customer;
        }

        return null;
    }

    protected function getProductCategories($postId)
    {
        static $cached = [];

        if (isset($cached[$postId])) {
            return $cached[$postId];
        }


        $taxonomyName = 'product-categories';
        $terms = get_the_terms($postId, $taxonomyName);
        if (is_wp_error($terms) || !$terms) {
            $cached[$postId] = [];
        } else {
            $cached[$postId] = wp_list_pluck($terms, 'term_id');
        }

        return $cached[$postId];
    }

}
