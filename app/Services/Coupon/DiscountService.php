<?php

namespace FluentCart\App\Services\Coupon;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
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
        $conditions = $coupon->conditions;

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

        $preValidatedItems = array_filter($cartItems, function ($item) use ($coupon, $conditions) {
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
                if($allowedEmails) {
                    foreach ($allowedEmails as $email) {
                        // match with regex pattern
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

        $preValidatedItems = array_values(array_filter($preValidatedItems));

        if (!$preValidatedItems) {
            return new \WP_Error('no_applicable_items', __('No applicable items found for this coupon.', 'fluent-cart'));
        }

        $currentItemsSubtotal = array_sum(array_map(function ($item) {
            return (int)$item['subtotal'];
        }, $preValidatedItems));

        $currentItemsDiscountTotal = array_sum(array_map(function ($item) {
            return (int)Arr::get($item, 'coupon_discount', 0);
        }, $preValidatedItems));

        $currentItemsTotalAfterDiscount = $currentItemsSubtotal - $currentItemsDiscountTotal;

        if ($currentItemsTotalAfterDiscount <= 0) {
            return new \WP_Error('no_applicable_items', __('No applicable items found for this coupon.', 'fluent-cart'));
        }

        if ($coupon->type == 'fixed') {
            $amount = $coupon->amount;
            // convert this to percentage of the current items total after discount
            if ($amount >= $currentItemsTotalAfterDiscount) {
                $percent = 100;
            } else {
                $percent = round(($amount / $currentItemsTotalAfterDiscount) * 100, 2);
            }
        } else {
            $percent = round((min(100, max(0, (float)$coupon->amount))), 2);
        }

        $couponDiscountTotal = 0;

        foreach ($preValidatedItems as $index => $item) {
            $existingAmount = (int)Arr::get($item, 'coupon_discount', 0);
            $itemTotal = (int)($item['subtotal'] - $existingAmount);
            $currentDiscount = (int)round($itemTotal * ($percent / 100));
            $discountTotal = (int)($existingAmount + $currentDiscount);

            if ($discountTotal > $itemTotal) {
                $discountTotal = $itemTotal;
            }

            $netDiscount = $discountTotal - $existingAmount;

            $couponDiscountTotal += ($netDiscount < 0) ? 0 : $netDiscount;

            $preValidatedItems[$index]['coupon_discount'] = $discountTotal;
        }

        if ($coupon->type === 'fixed') {

            if ($couponDiscountTotal < $coupon->amount) {
                // we have a rounding issue! Let's fix it by reducing the discount from any item that has a discount
                $remainingAmount = $coupon->amount - $couponDiscountTotal;
                foreach ($preValidatedItems as $index => $item) {
                    if ($remainingAmount <= 0) {
                        break;
                    }

                    $maximumReduction = (int)($item['subtotal'] - Arr::get($item, 'coupon_discount', 0));
                    if ($maximumReduction <= 0) {
                        continue;
                    }

                    $newDiscountAmount = min($maximumReduction, $remainingAmount);
                    $existingAmount = Arr::get($item, 'coupon_discount', 0);
                    $item['coupon_discount'] = $existingAmount + $newDiscountAmount;
                    $preValidatedItems[$index] = $item;
                    $couponDiscountTotal += $newDiscountAmount;
                    $remainingAmount -= $newDiscountAmount;
                }
            } else if ($couponDiscountTotal > $coupon->amount) {
                // we have a rounding issue! Let's fix it by reducing the discount from any item that has a discount
                $excessAmount = $couponDiscountTotal - $coupon->amount;
                foreach ($preValidatedItems as $index => $item) {
                    if ($excessAmount <= 0) {
                        break;
                    }

                    $existingDiscount = Arr::get($item, 'coupon_discount', 0);
                    if ($existingDiscount <= 0) {
                        continue;
                    }

                    $newReductionAmount = min($existingDiscount, $excessAmount);
                    $item['coupon_discount'] = $existingDiscount - $newReductionAmount;
                    $preValidatedItems[$index] = $item;
                    $couponDiscountTotal -= $newReductionAmount;
                    $excessAmount -= $newReductionAmount;
                }
            }
        }

        // now we will merge the preValidatedItems back to cartItems
        foreach ($cartItems as $index => $item) {
            foreach ($preValidatedItems as $preItem) {
                if ($item['id'] == $preItem['id']) {
                    $cartItems[$index] = $preItem;
                    break;
                }
            }
        }

        if (!$couponDiscountTotal) {
            return new \WP_Error('no_discount_applied', __('This coupon could not apply any discount.', 'fluent-cart'));
        }

        foreach ($cartItems as &$item) {
            $item['discount_total'] = (int)(Arr::get($item, 'manual_discount', 0) + Arr::get($item, 'coupon_discount', 0));
            $item['line_total'] = (int)($item['subtotal'] - $item['discount_total']);
            if ($item['line_total'] < 0) {
                $item['line_total'] = 0;
            }
        }

        $this->cartItems = array_values($cartItems);
        $this->appliedCoupons[] = $coupon->code;

        $this->perCouponDiscounts[$coupon->code] = $couponDiscountTotal;

        return true;
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
        if ($maxPerCustomer && $useCount) {
            $customer = $this->getCustomer();
            // we will find out how many times this customer has used this coupon
            if ($customer) {
                $usedCount = AppliedCoupon::query()->whereHas('order', function ($query) use ($customer) {
                    $query->whereIn('payment_status', Status::getOrderPaymentSuccessStatuses());
                })
                    ->where('customer_id', $customer->id)
                    ->where('coupon_id', $coupon->id)->count();

                if ($usedCount && $usedCount >= $maxPerCustomer) {
                    return new \WP_Error('coupon_max_uses_exceeded', __('You have reached the maximum number of uses for this coupon.', 'fluent-cart'));
                }
            }
        }

        return $coupon;
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
