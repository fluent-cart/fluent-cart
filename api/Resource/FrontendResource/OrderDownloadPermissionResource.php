<?php

namespace FluentCart\Api\Resource\FrontendResource;

use FluentCart\Api\Resource\BaseResourceApi;
use FluentCart\App\Models\OrderDownloadPermission;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class OrderDownloadPermissionResource extends BaseResourceApi
{

    public static function getQuery(): Builder
    {
        return OrderDownloadPermission::query();
    }

    /**
     * Get Order Download Permission based on specified parameters.
     *
     * @param array $params Array containing the necessary parameters.
     *
     */
    public static function get(array $params = [])
    {
        //
    }

    /**
     * Find Order Download Permission by ID.
     *
     * @param int   $id     Required. The ID of Order Download Permission.
     * @param array $params Optional. Additional parameters for finding a download permission.
     *        [
     *           'with' => (array) Optional. Relationships name to be eager loaded,
     *        ]
     *
     */
    public static function find($id, $params = [])
    {
        $orderId = Arr::get($params, 'order_id', null);
        $variationId = Arr::get($params, 'variation_id', null);
        $with = Arr::get($params, 'with', []);
        return static::getQuery()->with($with)->where('order_id', $orderId)->where('download_id', $id)->where('variation_id', $variationId)->first();
    }

    /**
     * Create a new Order Download Permission with the given data
     *
     * @param array $data   Required. Array containing the necessary parameters
     *        [
     *              'order_id'  => (int) Required. The id of the order,
     *              'customer_id'   => (int) Required. The id of the customer,
     *              'download_id'       => (int) Required. The id of the download file,
     *              'download_count'        => (int) Required. The count of the download log,
     *              'download_limit'       => (int) Required. The limit of the download file,
     *              'access_expires'    => (date) Required. The expiry  code of the download file,
     *        ]
     * @param array $params Optional. Additional parameters for creating a Order Download Permission.
     *
     */
    public static function create($data, $params = [])
    {
        $isCreated = static::getQuery()->create($data);

        if ($isCreated) {
            return static::makeSuccessResponse(
                    $isCreated,
                    __('Download log created successfully!', 'fluent-cart')
                );
        }

        return static::makeErrorResponse([
            [ 'code' => 400, 'message' => __('Download log creation failed.', 'fluent-cart') ]
        ]);
    }

     /**
     * Update Order Download Permission with the given data
     *
     * @param array $data   Required. Array containing the necessary parameters
     *        [
     *              'order_id'  => (int) Required. The id of the order,
     *              'customer_id'   => (int) Required. The id of the customer,
     *              'download_id'       => (int) Required. The id of the download file,
     *              'download_count'        => (int) Required. The count of the download log,
     *              'download_limit'       => (int) Required. The limit of the download file,
     *              'access_expires'    => (date) Required. The expiry  code of the download file,
     *        ]
     * @param int   $id     Required. The ID of the Order Download Permission.
     * @param array $params Optional. Additional parameters for creating a Order Download Permission.
     *
     */
    public static function update($data, $id, $params = [])
    {
        $isUpdated = static::getQuery()->find($id)->update($data);

        if ($isUpdated) {
            return static::makeSuccessResponse(
                $isUpdated,
                __('Download log updated successfully!', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            [ 'code' => 400, 'message' => __('Download log update failed.', 'fluent-cart') ]
        ]);
    }

    /**
     * Delete a download permission based on the given ID and parameters.
     *
     * @param int   $id     Optional. The ID of the order download permission.
     * @param array $params Optional. Additional parameters.
     *
     */
    public static function delete($id, $params = [])
    {
        //
    }
}