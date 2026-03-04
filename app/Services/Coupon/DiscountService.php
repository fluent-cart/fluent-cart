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

        $coupons = Coupon::query()->whereIn('code', $codes)
            ->where('status', 'active')
            ->get();

        if ($coupons->isEmpty()) {
            return new \WP_Error('no_valid_coupons', __('Coupon can not be applied.', 'fluent-cart'), []);
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
            return new \WP_Error('no_valid_coupons', __('Coupon can not be applied.', 'fluent-cart'), $invalidCoupons);
        }

        // Let's check if we have multiple coupons and if they are stackable. If not, we will only keep the first one and invalidate the rest.
        if (count($validCoupons) >= 2) {
            $intermediateValidCoupons = [];
            foreach ($validCoupons as $coupon) {
                if ($coupon->stackable === 'yes') {
                    $intermediateValidCoupons[] = $coupon;
                } else {
                    $invalidCoupons[$coupon->code] = [
                        'success'    => false,
                        'error'      => __('This coupon cannot be stacked with other coupons.', 'fluent-cart'),
                        'error_code' => 'coupon_not_stackable'
                    ];
                }
            }

            if (!$intermediateValidCoupons) {
                $validCoupons = [$validCoupons[0]];
            } else {
                $validCoupons = $intermediateValidCoupons;
            }
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
        $cartItems = $this->cartItems;

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
            return new \WP_Error('no_applicable_items', __('No applicable items found for this coupon.', 'fluent-cart'));
        }

        $percent = $this->calculateDiscountPercent($coupon, $currentItemsTotalAfterDiscount);

        list($preValidatedItems, $couponDiscountTotal) = $this->applyDiscountToItems($preValidatedItems, $percent, $coupon);

        if ($coupon->type === 'fixed') {
            list($preValidatedItems, $couponDiscountTotal) = $this->correctFixedCouponRounding(
                $preValidatedItems, $coupon, $couponDiscountTotal
            );
        }

        $cartItems = $this->mergeValidatedItems($cartItems, $preValidatedItems);

        if (!$couponDiscountTotal) {
            return new \WP_Error('no_discount_applied', __('This coupon could not apply any discount.', 'fluent-cart'));
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
            $message = __('This coupon cannot be used.', 'fluent-cart');
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
        $subtotal = (int) $item['subtotal'];
        if (Arr::get($item, 'other_info.payment_type') === 'subscription'
            && Arr::get($item, 'other_info.trial_days', 0) > 0
        ) {
            $quantity = (int) Arr::get($item, 'quantity', 1);
            $subtotal = (int) Arr::get($item, 'other_info.signup_fee', 0) * ($quantity > 0 ? $quantity : 1);
        }
        return $subtotal;
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
        // let's validate the start date and end date first
        $startDate = $coupon->start_date;
        if ($startDate && $startDate != '0000-00-00 00:00:00' && strtotime($startDate) > time()) {
            return new \WP_Error('coupon_not_started', __('This coupon is no longer valid.', 'fluent-cart'));
        }
        $endDate = $coupon->end_date;
        if ($endDate && $endDate != '0000-00-00 00:00:00' && strtotime($endDate) < time()) {
            return new \WP_Error('coupon_expired', __('This coupon is no longer valid.', 'fluent-cart'));
        }

        $conditions = $coupon->conditions;

        // add check max_purchase_amount
        $maxPurchaseAmount = Arr::get($conditions, 'max_purchase_amount', 0);
        $getCartTotal = 0;
        if ($this->cart) {
            $getCartTotal = ($this->cart->getEstimatedTotal() / 100);
        }

        if ($maxPurchaseAmount) {
            if ($getCartTotal > $maxPurchaseAmount) {
                return new \WP_Error('max_purchase_amount_exceeded', __('This coupon is no longer valid.', 'fluent-cart'));
            }
        }

        $minPurchaseAmount = Arr::get($conditions, 'min_purchase_amount', 0);
        if ($minPurchaseAmount) {
            if ($getCartTotal < ($minPurchaseAmount / 100)) {
                return new \WP_Error('min_purchase_amount_not_met', __('This coupon is no longer valid.', 'fluent-cart'));
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
                    return new \WP_Error('coupon_max_uses_exceeded', __('You have reached the maximum number of uses for this coupon.', 'fluent-cart'));
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
