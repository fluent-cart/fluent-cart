<?php

namespace FluentCart\App\Services\Coupon\Concerns;

use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\Customer;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\OrderService;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;
use WP_Error;

trait CanValidateCoupon
{
    /**
     * @return bool|WP_Error
     */
    public function validate($couponCode)
    {
        $couponCode = apply_filters('fluent_cart/coupon/validating_coupon', $couponCode,
            [
                'coupon_code'   => $couponCode,
                'line_items'    => $this->lineItems,
                'couponService' => $this
            ]
        );

        if (is_wp_error($couponCode)) {
            return $couponCode;
        }

        if (empty($couponCode)) {
            return $this->makeError(__('Invalid Coupon Code', 'fluent-cart'), 404);
        }

        $coupon = $this->applicableCoupons->firstWhere('code', $couponCode);

        if (empty($coupon)) {
            return $this->makeError(__('Coupon Not Found', 'fluent-cart'), 404);
        }

        if ($this->isAlreadyApplied($coupon)) {
            return $this->makeError(__('Coupon already applied', 'fluent-cart'), 401);
        }

        //Return true at this point is not using cart, because at this point coupon is already used in an order
        if (!$this->usingCart && $this->previouslyAppliedCoupons->has($couponCode)) {
            return true;
        }

        if (!$this->canBeStacked($coupon)) {
            return $this->makeError(__('Can not apply the coupons together', 'fluent-cart'), 401);
        }

        if (!$this->isCouponActive($coupon)) {

            return $this->makeError(__('This coupon has expired or is not valid', 'fluent-cart'), 401);
        }

        if ($this->requiredUserToBeLoggedIn($coupon)) {

            if (!is_user_logged_in()) {
                return $this->makeError(__('You Need To be logged in', 'fluent-cart'), 403);
            }

            if (!$this->hasMaxPerUserLimit($coupon)) {
                return $this->makeError(__('Coupon Uses Limit Exceeded', 'fluent-cart'), 401);
            }
        }

        if (!$this->hasUseLimit($coupon)) {
            return $this->makeError(__('Coupon Uses Limit Exceeded', 'fluent-cart'), 403);
        }

        if (!$this->ensureMinimumPurchaseAmount($coupon)) {
            return $this->makeError(__('Purchase amount is smaller than required amount', 'fluent-cart'), 403);
        }

        if (!$this->ensureMaximumPurchaseAmount($coupon)) {
            return $this->makeError(__('Your cart total exceeds the maximum amount allowed for this coupon.', 'fluent-cart'), 403);
        }

        if (!$this->matchesEmailRestrictions($coupon)) {
            return $this->makeError(__('This coupon is restricted to specific email addresses', 'fluent-cart'), 403);
        }

        if (!$this->isProductValidated($coupon)) {
            return $this->makeError(__('No applicable products in the cart for this coupon', 'fluent-cart'), 403);
        }

        return true;
    }

    public function isProductValidated($coupon): bool
    {
        foreach ($this->lineItems as $item) {
            if ($this->isApplicableToProduct($coupon, $item['post_id'], $item['id'])) {
                return true;
            }
        }
        return false;
    }

    public function canBeStacked(Coupon $coupon): bool
    {
        //If there is no applied coupon return true;
        if (empty($this->previouslyAppliedCouponCodes)) {
            return true;
        }
        //If there is any applied coupon, that is not stackable return false;
        foreach ($this->previouslyAppliedCoupons as $lcoupon) {
            if (Arr::get($lcoupon, 'stackable') === 'no') {
                return false;
            }
        }

        //return true if the coupon is stackable
        return (Arr::get($coupon, 'stackable') !== 'no');
    }

    public function isAlreadyApplied(Coupon $coupon): bool
    {
        return in_array($coupon->code, $this->previouslyAppliedCouponCodes);
    }

    public function ensureMinimumPurchaseAmount(Coupon $coupon): bool
    {
        $min_purchase_amount = Arr::get($coupon->conditions, 'min_purchase_amount', null);
        if (empty($min_purchase_amount)) {
            return true;
        } else {
            // The coupon's min_amount_basis ('subtotal' | 'total') governs whether shipping counts
            // toward this gate. This admin order-editing path operates on line items only and has no
            // live shipping context, so the check is always against the items subtotal here — the
            // 'total' (shipping-inclusive) basis takes effect on the storefront checkout path
            // (see DiscountService::isCouponValid).
            $orderTotal = OrderService::getItemsAmountTotal($this->lineItems, false, false);
            return $min_purchase_amount <= $orderTotal;
        }
    }

    public function ensureMaximumPurchaseAmount(Coupon $coupon): bool
    {
        $maxPurchaseAmount = Arr::get($coupon->conditions, 'max_purchase_amount', null);
        if (empty($maxPurchaseAmount)) {
            return true;
        }

        // Unlike min_purchase_amount (converted to cents by CouponResource::formatCouponData),
        // max_purchase_amount is stored as the decimal amount the admin typed — so the
        // cents-denominated items total must come down to decimal before comparing, exactly
        // as DiscountService::isCouponValid does on the storefront path.
        $orderTotal = OrderService::getItemsAmountTotal($this->lineItems, false, false);

        return ($orderTotal / 100) <= $maxPurchaseAmount;
    }

    public function matchesEmailRestrictions(Coupon $coupon): bool
    {
        $emailRestrictions = trim((string) Arr::get($coupon->conditions, 'email_restrictions', ''));
        if (!$emailRestrictions) {
            return true;
        }

        $allowedEmails = array_filter(array_map('trim', explode(',', $emailRestrictions)));
        if (!$allowedEmails) {
            return true;
        }

        // A restricted coupon needs a known customer email to match against —
        // same contract as the storefront path (DiscountService), which also
        // refuses when the cart has no email yet.
        if (!$this->customerEmail) {
            return false;
        }

        foreach ($allowedEmails as $email) {
            $pattern = '/^' . str_replace('\*', '.*', preg_quote($email, '/')) . '$/i';
            if (preg_match($pattern, $this->customerEmail)) {
                return true;
            }
        }

        return false;
    }

    public function isCouponActive(Coupon $coupon): bool
    {
        $status = $coupon->status;
        $now = DateTime::gmtNow();

        $hasExpirationDate = true;
        $hasStartDate = true;

        if (empty($coupon->start_date) || $coupon->start_date === '0000-00-00 00:00:00') {
            $hasStartDate = false;
        }

        if (empty($coupon->end_date) || $coupon->end_date === '0000-00-00 00:00:00') {
            $hasExpirationDate = false;
        }


        if ($status === 'active' && !$hasExpirationDate && !$hasStartDate) {
            return true;
        }


        if ($status === 'scheduled' || $status === 'active') {

            $startDate = null;
            $endDate = null;
            $couponStarted = false;
            $couponEnded = false;

            if (!empty($coupon->start_date)) {
                $startDate = DateTime::parse($coupon->start_date);
                $couponStarted = $startDate <= $now;
            }

            if (!empty($coupon->end_date)) {
                $endDate = DateTime::parse($coupon->end_date);
                $couponEnded = $endDate < $now;
            }




            if (empty($startDate))
                return !$couponEnded;
            if (empty($endDate))
                return $couponStarted;

            return $couponStarted && !$couponEnded;
        }

        return false;
    }

    public function hasUseLimit(Coupon $coupon): bool
    {
        $maxUse = Arr::get($coupon->conditions, 'max_uses', null);
        if (empty($maxUse)) {
            return true;
        }

        if (empty($coupon->use_count)) {
            return true;
        }

        return $maxUse > $coupon->use_count;
    }

    public function requiredUserToBeLoggedIn(Coupon $coupon): bool
    {
        return Arr::get($coupon->conditions, 'max_per_customer', 0);
    }

    public function hasMaxPerUserLimit(Coupon $coupon): bool
    {
        $maxPerCustomer = Arr::get($coupon->conditions, 'max_per_customer', 0);
        if (empty($maxPerCustomer)) {
            return true;
        }

        $userId = get_current_user_id();

        $customer = Customer::query()->where('user_id', $userId)->first();

        if (empty($customer)) {
            return true;
        }

        // fct_applied_coupons has no customer_id column — the customer is
        // reachable only through the order the coupon row belongs to.
        // coupon_id is nullable: legacy rows carry only the code, and skipping
        // them would let a capped customer reuse the coupon.
        $usedCount = AppliedCoupon::query()
            ->where(function ($query) use ($coupon) {
                $query->where('coupon_id', $coupon->id)
                    ->orWhere(function ($legacyQuery) use ($coupon) {
                        $legacyQuery->whereNull('coupon_id')->where('code', $coupon->code);
                    });
            })
            ->whereHas('order', function ($orderQuery) use ($customer) {
                $orderQuery->where('customer_id', $customer->id);
            })
            ->count();

        return $maxPerCustomer > $usedCount;
    }

    public function isApplicableToProduct(Coupon $coupon, $productId, $variationsId): bool
    {
        if (empty($variationsId)) {
            return false;
        }

        $categories = Arr::get($this->products, ($productId . '') . '.categories');

        $categoryIds = Collection::make($categories)->pluck('term_id')->toArray();

        $canBeApplied = true;

        $conditions = $coupon->conditions;

        $excludedCategories = $this->getArrayValue($conditions, 'excluded_categories');
        $includedCategories = $this->getArrayValue($conditions, 'included_categories');
        $excludedProducts = $this->getArrayValue($conditions, 'excluded_products');
        $includedProducts = $this->getArrayValue($conditions, 'included_products');

        if (in_array($variationsId, $includedProducts)) {
            return true;
        }

        if (in_array($variationsId, $excludedProducts)) {
            return false;
        }

        foreach ($categoryIds as $categoryId) {
            if (empty($categoryId)) {
                continue;
            }
            if (in_array($categoryId, $excludedCategories)) {
                $canBeApplied = false;
            }
            if (!empty($includedCategories) && !in_array($categoryId, $includedCategories)) {
                $canBeApplied = false;
            }

            if (!$canBeApplied) {
                break;
            }
        }

        if (!empty($includedProducts) && !in_array($variationsId, $includedProducts)) {
            return false;
        }


        return $canBeApplied;
    }

    protected function makeError(string $message, $code = null): WP_Error
    {
        return new WP_Error($code, $message);
    }

    private function getArrayValue($array, $key, $defaultValue = [])
    {
        return isset($array[$key]) && is_array($array[$key]) ? $array[$key] : $defaultValue;
    }
}
