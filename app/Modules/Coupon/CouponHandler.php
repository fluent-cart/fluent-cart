<?php

namespace FluentCart\App\Modules\Coupon;

use FluentCart\App\Helpers\CouponHelper;

class CouponHandler
{
    public function register()
    {
        add_filter('fluent_cart/payments/apply_coupon', [$this, 'applyCoupon'], 10, 2);
        add_filter('fluent_cart/payments/store_applied_coupon_data', [$this, 'storeAppliedCouponData'], 10, 3);
        add_shortcode('fluent_cart_show_coupon', [$this, 'show_coupon']);
    }

    public function applyCoupon($appliedCouponList, $orderTotal)
    {
        if (!empty($appliedCouponList)) {
            $couponHelper = new CouponHelper();
            return $couponHelper->calculateCoupon($appliedCouponList, $orderTotal);
        }
        return;
    }

    public function storeAppliedCouponData($appliedCouponList = [], $appliedDiscountsList = [], $orderId = null)
    {
        $couponHelper = new CouponHelper();
        if (!empty($appliedCouponList)) {
            return $couponHelper->storeAppliedCouponData($appliedCouponList, $appliedDiscountsList, $orderId);
        }
        return;
    }

    public function show_coupon()
    {
        
      
    }
}