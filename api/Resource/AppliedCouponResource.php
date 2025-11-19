<?php

namespace FluentCart\Api\Resource;

use FluentCart\App\App;
use FluentCart\App\Models\AppliedCoupon;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

/**
 * Class CouponResource
 *
 * @package FluentCart\Api\Resource
 */

class AppliedCouponResource extends BaseResourceApi
{

    /**
     * Get the query builder instance for the Coupons model.
     *
     * @return Builder
     */

    public static function getQuery(): Builder
    {
        return AppliedCoupon::query();
    }

    /**
     * Retrieve order meta based on the provided parameters.
     *
     * @param array $params Optional. Additional parameters for data retrieval.
     *        [
     *          'order_id' => ( int ) Required. The ID of the order to filter the data.
     * ]
     *
     */
    public static function get(array $params = [])
    {
        return static::getQuery()->where('coupon_id', Arr::get($params, 'coupon_id'))->get();
    }

    /**
     * Create order meta data with the provided information.
     *
     * @param array $data   Required. Array containing the necessary parameters for data creation.
     *        [
     *              // Include required parameters for creating the data.
     * ]
     * @param array $params Optional. Additional parameters for data creation.
     *        [
     *             // Include optional parameters, if any.
     * ]
     *
     */

    public static function find($id, $params = [])
    {

    }

    public static function create($data, $params = [])
    {
        $isCreated = static::getQuery()->insert($data);
        if ($isCreated) {
            return static::makeSuccessResponse(
                $isCreated,
                __('Created successfully!', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Falied to create.', 'fluent-cart')]
        ]);
    }

    public static function createOrUpdate($data, $id)
    {
        if ($id) {
            return static::getQuery()->where('id', $id)->update($data);
        } else {
            return static::getQuery()->insert($data);
        }
    }

    public static function update($data, $id = null, $params = [])
    {

        $isUpdate = static::getQuery()->batchUpdate($data);

        if ($isUpdate) {
            return static::makeSuccessResponse(
                $isUpdate,
                __('Coupon updated.', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Coupon failed to update!', 'fluent-cart')]
        ]);
    }

    public static function deleteRemovedCoupons($appliedCoupons, $orderId)
    {
        $existingCoupons = static::getQuery()->where('order_id', $orderId)->get();
        foreach ($existingCoupons as $existingCoupon) {
            $code = $existingCoupon['code'];

            $found = false;
            foreach ($appliedCoupons as $couponData) {
                if ($couponData['code'] === $code) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $existingCoupon->delete();
            }
        }
    }

    public static function delete($id, $params = [])
    {
        return static::getQuery()->where('order_id', $id)->delete();
    }

    public static function bulkDeleteByOrderIds($ids, $params = [])
    {
        return static::getQuery()->whereIn('order_id', $ids)->delete();
    }

}