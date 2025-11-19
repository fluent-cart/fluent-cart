<?php

namespace FluentCart\App\Helpers;

use FluentCart\Api\Resource\AppliedCouponResource;
use FluentCart\Api\Resource\CouponResource;
use FluentCart\App\Models\AppliedCoupon;
use FluentCart\App\Models\Coupon;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Product;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

/**
 * Class CouponHelper
 *
 * This class provides utility functions related to coupons and their validation, cancellation, and calculation of discounts.
 */
class CouponHelper
{

    /**
     * Validates a coupon against various criteria.
     *
     * @param object $appliedCoupon The coupon object to be validated.
     *
     * @return array An array containing validation results.
     */

    public static function getQuery(): Builder
    {
        return Coupon::query();
    }

    public function calculateCoupon($appliedCouponList, $orderTotal)
    {
        $calculatedDiscountResult = CouponResource::calculateTotalDiscountAndPurchaseAmount($appliedCouponList, $orderTotal);
        $discount = Arr::get($calculatedDiscountResult, 'total_discount');
        $orderTotal = Arr::get($calculatedDiscountResult, 'total_purchase_amount');
        $appliedDiscountsList = Arr::get($calculatedDiscountResult, 'applied_discounts');

        return [
            'total_discount' => $discount,
            'total_purchase_amount' => $orderTotal,
            'applied_discounts' => $appliedDiscountsList
        ];
    }

    public function storeAppliedCouponData($appliedCouponList, $appliedDiscountsList, $orderId)
    {
        for ($i = 0; $i < count($appliedCouponList); $i++) {
            $appliedCoupon = self::getSingleCouponDetails($appliedCouponList[$i], $appliedDiscountsList[$i]);

            $appliedCouponSnapShot[] = [
                'order_id' => $orderId,
                'coupon_id' => $appliedCoupon['id'],
                'title' => $appliedCoupon['title'],
                'code' => $appliedCoupon['code'],
                'status' => $appliedCoupon['status'],
                'type' => $appliedCoupon['type'],
                'amount' => $appliedCoupon['amount'],
                'discounted_amount' => $appliedCoupon['discounted_amount'],
                'stackable' => $appliedCoupon['stackable'],
                'priority' => $appliedCoupon['priority'],
                'max_uses' => $appliedCoupon['max_uses'],
                'use_count' => $appliedCoupon['use_count'],
                'max_per_customer' => $appliedCoupon['max_per_customer'],
                'min_purchase_amount' => $appliedCoupon['min_purchase_amount'],
                'max_discount_amount' => $appliedCoupon['max_discount_amount'],
                'notes' => $appliedCoupon['max_discount_amount']
            ];

        }

        // $orderMetaData = [
        //     'order_id' => $order['id'],
        //     'key' => 'applied_coupon',
        //     'value'=> json_encode($appliedCouponSnapShot)
        // ];
        // OrderMetaResource::create($orderMetaData);
        return AppliedCouponResource::create($appliedCouponSnapShot);
    }


    /**
     * Calculates the discount based on the coupon code and purchase amount.
     *
     * @param string $couponCode The code of the coupon.
     * @param float $purchaseAmount The total purchase amount.
     *
     * @return float The calculated discount amount.
     */

    public static function calculateDiscount($coupon, $applicableTotalWithItems)
    {
        $type = Arr::get($coupon, 'type', null);
        $max_discount = Arr::get($coupon, 'max_discount_amount', null);
        $min_purchase_amount = Arr::get($coupon, 'min_purchase_amount');
        $discountAmount = floatval(Arr::get($coupon, 'amount', null));
        $lineData = Arr::get($applicableTotalWithItems, 'lineTotalWithItems', []);
        $applicableTotal = Arr::get($applicableTotalWithItems, 'applicableTotal', null);
        $totalApplicableDiscount = 0;

        foreach ($lineData as $key => $value) {
            if ($type === 'percentage') {
                $totalApplicableDiscount = Helper::toDecimal($applicableTotal * $discountAmount);
                if ($totalApplicableDiscount > $max_discount && $max_discount != 0) {
                    $totalApplicableDiscount = $max_discount;
                }

                $discountPercentage = Helper::toCent($totalApplicableDiscount / $applicableTotal);
                $discount = Helper::toDecimal($discountPercentage) * $value;

                $lineData[$key] =
                    [
                        'discountAmount' => $discount,
                        'originalAmount' => $value,
                        'afterDiscountAmount' => $value - $discount,
                    ];

                continue;
            }

            if ($type === 'fixed') {
                if ($min_purchase_amount === null || $applicableTotal > $min_purchase_amount) {
                    if ($discountAmount >= $applicableTotal) {
                        $discountAmount = $applicableTotal;
                    }
                    $totalApplicableDiscount = $discountAmount;
                    $discountPercentage = Helper::toCent($discountAmount / $applicableTotal);
                    $discount = Helper::toDecimal($discountPercentage) * $value;

                    $lineData[$key] =
                        [
                            'discountAmount' => $discount,
                            'originalAmount' => $value,
                            'afterDiscountAmount' => $value - $discount,
                        ];

                    continue;
                }
            }
        }

        return [
            'lineData' => $lineData,
            'totalApplicableDiscount' => $totalApplicableDiscount
        ];
    }

    /**
     * Cancels a coupon and adjusts the purchase amount if needed.
     *
     * @param object $cancelledCoupon The coupon object to cancel.
     *
     * @return array|null An array with the adjusted purchase amount if the coupon is canceled.
     */

    public static function checkStackability($appliedCoupon, $appliedCouponList, $coupon)
    {

        if (in_array($appliedCoupon, $appliedCouponList)) {
            return
                ['code' => 400, 'message' => sprintf(
                    /* translators: %s is the coupon code */
                    __('%s coupon can only be applied once per order', 'fluent-cart'), $appliedCoupon)];
        }

        if (count($appliedCouponList) > 0) {
            $lastCouponOfArray = end($appliedCouponList);
            $lastCouponStackability = static::getQuery()->where('code', $lastCouponOfArray)->first()['stackable'];
            if ($lastCouponStackability == 'no') {
                return
                    ['code' => 400, 'message' => sprintf(
                        /* translators: %s is the coupon code */
                        __('%s coupon cannot be used with other coupon', 'fluent-cart'), end($appliedCouponList))];
            }
        }

        if (count($appliedCouponList) > 0 && $coupon->stackable == 'no') {

            /**
             * Error message when a coupon cannot be used with another coupon.
             *
             * %1$s - Applied Coupon (e.g., "DISCOUNT10")
             *
             * This string is shown when the applied coupon cannot be used together with another coupon.
             */
            return [
                'code' => 400,
                'message' => sprintf(
                    /* translators: %s is the applied coupon code */
                    __('This %s coupon cannot be used with other coupon', 'fluent-cart'),
                    $appliedCoupon // %s: Applied Coupon (e.g., "DISCOUNT10")
                )
            ];

        }
    }

    //Returning false from this method meaning the coupon is valid
    public static function checkUsageLimit(Coupon $coupon, $customerEmail = '', $trigger = null)
    {
        $code = Arr::get($coupon, 'code', null);

        $maxUsesLimitAllCustomer = Arr::get($coupon, 'max_uses', null);
        $maxUsesLimitPerCustomer = Arr::get($coupon, 'max_per_customer', null);

        $maxUsesLimitAllCustomer = $maxUsesLimitAllCustomer !== null ? intval($maxUsesLimitAllCustomer) : null;
        $maxUsesLimitPerCustomer = $maxUsesLimitPerCustomer !== null ? intval($maxUsesLimitPerCustomer) : null;


        $totalUsedByAllCustomer = AppliedCoupon::where('code', $code)->get()->count();

        if (($maxUsesLimitAllCustomer === null && $maxUsesLimitPerCustomer === null)) {
            return false;
        }

        $customer = ($trigger == 'on_checkout')
            ? Customer::query()->where('email', $customerEmail)->first()
            : ( CartCheckoutHelper::make())->getCustomer($customerEmail);

        if (!$customer) {
            if (($totalUsedByAllCustomer >= $maxUsesLimitAllCustomer && $maxUsesLimitAllCustomer !== null) || $maxUsesLimitAllCustomer === 0 || $maxUsesLimitPerCustomer === 0) {
                return true;
            }
            if (($maxUsesLimitAllCustomer === null && $maxUsesLimitPerCustomer > 0) || ($maxUsesLimitAllCustomer < $totalUsedByAllCustomer && $maxUsesLimitPerCustomer > 0)) {
                return false;
            }
            return false;
        } else {
            $orderIds = Order::where('customer_id', $customer->id)->pluck('id');
            $totalUsedCouponPerCustomer = AppliedCoupon::whereIn('order_id', $orderIds)->where('code', $code)->get()->count();

            if (
                ($totalUsedByAllCustomer < $maxUsesLimitAllCustomer && $maxUsesLimitPerCustomer === null) ||
                ($maxUsesLimitAllCustomer === null && $totalUsedCouponPerCustomer < $maxUsesLimitPerCustomer)
            ) {
                return false;
            }

            if (
                ($maxUsesLimitAllCustomer === 0 && $maxUsesLimitPerCustomer === 0)
                || ($maxUsesLimitAllCustomer === null && $maxUsesLimitPerCustomer === 0)
                || $maxUsesLimitAllCustomer === 0 || $maxUsesLimitPerCustomer === 0
                || $totalUsedByAllCustomer >= $maxUsesLimitAllCustomer
                || $totalUsedCouponPerCustomer >= $maxUsesLimitPerCustomer
            ) {
                return true;
            }
        }

        return false;

    }

    public static function sortByPriority($appliedCouponList = [])
    {
        usort(
            $appliedCouponList,
            function ($a, $b) {
                $priorityA = self::getCouponPriority($a);
                $priorityB = self::getCouponPriority($b);

                return $priorityA - $priorityB;
            }
        );
        return $appliedCouponList;
    }

    public static function getSingleCouponDetails($coupon, $discount)
    {
        $couponDetails = static::getQuery()->where('code', $coupon)->first();
        $couponDetails['discounted_amount'] = $discount;
        return $couponDetails;
    }

    public static function getCouponPriority($couponCode)
    {
        $coupon = static::getQuery()->where('code', $couponCode)->first();
        return Arr::get($coupon, 'priority', 0);
    }

    public function prepareCouponCalculation($order)
    {
        $appliedCouponList = array_map(function ($coupon) {
            return $coupon['code'];
        }, $order['applied_coupon']);

        $calculatedDiscountResult = $this->calculateCoupon($appliedCouponList, $order['subtotal']);

        $discount = Arr::get($calculatedDiscountResult, 'total_discount');
        return [
            'discount' => $discount,
            'subTotal' => $order['subtotal'],
            'appliedCouponList' => $appliedCouponList
        ];

    }

    public static function checkProductEligibility($productId, $couponCode, $origin)
    {
        $coupon = static::getQuery()->where('code', $couponCode)->first();
        $excludedCategories = json_decode(Arr::get($coupon, 'excluded_categories', '[]'), true) ?? [];
        $includedCategories = json_decode(Arr::get($coupon, 'included_categories', '[]'), true) ?? [];
        $excludedProducts = json_decode(Arr::get($coupon, 'excluded_products', '[]'), true) ?? [];
        $includedProducts = json_decode(Arr::get($coupon, 'included_products', '[]'), true) ?? [];
        $product = Product::with('wp_terms')->find($productId)->toArray();

        $productCategories = array_map(function ($productCategories) {
            return $productCategories['term_taxonomy_id'];
        }, $product['wp_terms']);

        if (!empty($includedProducts) && !in_array($productId, $includedProducts)) {

            if ($includedCategories !== null && !empty(array_intersect($includedCategories, $productCategories))) {
                return [
                    'isApplicable' => true,
                ];
            }
            return [
                'isApplicable' => false,
            ];
        }

        if (empty($excludedCategories) && empty($excludedProducts) && empty($includedCategories) && empty($includedProducts)) {
            return [
                'isApplicable' => true
            ];
        }

        if (empty($productCategories) && !in_array($productId, $excludedProducts)) {
            return [
                'isApplicable' => true
            ];
        }

        // Check if the product is in the excluded products list
        if ($excludedProducts !== null && in_array($productId, $excludedProducts)) {
            return [
                'isApplicable' => false,
            ];
        }

        // Check if the product belongs to any included product list

        if ($includedProducts !== null && in_array($productId, $includedProducts)) {
            return [
                'isApplicable' => true,
            ];
        }

        /**
         * Error message when a coupon conflicts with a product.
         *
         * %1$s - Product Title (e.g., "Product A")
         * %2$s - Coupon Code (e.g., "DISCOUNT10")
         *
         * This string is shown when a coupon conflicts with a product, and the user
         * needs to remove the coupon first.
         */
        $message = sprintf(
            /* translators: %1$s: Product Title, %2$s: Coupon Code */
            __('%1$s conflicts with %2$s coupon. Remove the coupon first.', 'fluent-cart'),
            $product['post_title'], // %1$s: Product Title (e.g., "Product A")
            $couponCode             // %2$s: Coupon Code (e.g., "DISCOUNT10")
        );

        // Check if the product belongs to any excluded categories
        if ($excludedCategories != null && array_intersect($excludedCategories, $productCategories)) {
            return [
                'isApplicable' => false,
                'message' => $message
            ];
        }

        //check if the product belongs to any included categories
        if ($includedCategories !== null && empty(array_intersect($includedCategories, $productCategories))) {
            return [
                'isApplicable' => false,
                'message' => __('This coupon can not be applied to some items in your cart', 'fluent-cart'),
            ];
        }
        return [
            'isApplicable' => true,
        ];
    }

    public static function getCouponApplicableItemsWithLineTotal($coupon, $modifiedOrderItems)
    {
        $items = $modifiedOrderItems;
        $origin = null;
        $applicableTotalWithItems = []; // Initialize the array for storing item totals
        $applicableTotal = 0;

        foreach ($items as $item) {
            $isEligible = self::checkProductEligibility($item['post_id'], $coupon, $origin);

            if ($isEligible['isApplicable'] === true && !isset($item['object_type'])) {
                if ($item['other_info']['payment_type'] === 'subscription' && $item['other_info']['manage_setup_fee'] === 'yes' && $item['other_info']['signup_fee'] > 0) {
                    if ($item['other_info']['setup_fee_per_item'] === 'yes') {
                        $setup_fee = $item['other_info']['signup_fee'] * $item['quantity'];
                    } else {
                        $setup_fee = $item['other_info']['signup_fee'];
                    }
                }
                if (isset($item['other_info']['manage_setup_fee']) === 'yes') {
                    $lineTotal = $item['discounted_price'] * $item['quantity'] + $setup_fee;

                } else {
                    $lineTotal = $item['discounted_price'] * $item['quantity'];
                }

                $applicableTotalWithItems[$item['post_id']] = $lineTotal; // Store the total for this item
                $applicableTotal += $lineTotal;
            }
        }

        return [
            'lineTotalWithItems' => $applicableTotalWithItems,
            'applicableTotal' => $applicableTotal,
            'orderItems' => $items,
        ];
    }
    public static function updateCouponStatus($coupon = [])
    {
        if (empty($coupon)) {
            return;
        }

        $startDate = $coupon->start_date;
        $endDate = $coupon->end_date;
        $status = $coupon->status;

        $now = DateTime::gmtNow();

        $startDateTime = (!empty($startDate) && $startDate !== '0000-00-00 00:00:00')
            ? DateTime::anyTimeToGmt($startDate)
            : null;

        $endDateTime = (!empty($endDate) && $endDate !== '0000-00-00 00:00:00')
            ? DateTime::anyTimeToGmt($endDate)
            : null;

        // Mark expired if end date passed
        if ($endDateTime && $endDateTime < $now) {
            if ($status !== 'expired') {
                $coupon->setStatus('expired');
                $coupon->save();
            }
            return;
        }

        // Set status to scheduled if start date is in the future
        if ($startDateTime && $startDateTime > $now && !in_array($status, ['disabled', 'scheduled'])) {
            $coupon->setStatus('scheduled');
            $coupon->save();
            return;
        }

        // Activate if start date passed and status is not already active/disabled
        if ($startDateTime && $startDateTime <= $now && !in_array($status, ['disabled', 'active'])) {
            $coupon->setStatus('active');
            $coupon->save();
        }
    }

}
