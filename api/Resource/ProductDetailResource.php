<?php

namespace FluentCart\Api\Resource;

use FluentCart\App\Events\StockChanged;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\ProductAdminHelper;
use FluentCart\App\Models\ProductDetail;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class ProductDetailResource extends BaseResourceApi
{

    public static function getQuery(): Builder
    {
        return ProductDetail::query();
    }

    public static function get(array $params = [])
    {
        //
    }

    /**
     * Find product detail by its id.
     *
     * @param int $id The id of the product detail.
     * @param array $data Additional data for finding product (optional).
     *
     */
    public static function find($id, $data = [])
    {
        return static::getQuery()->find($id);
    }

    /**
     * Create a new product detail with the given data.
     *
     * @param array $data Array containing the necessary parameters.
     *
     *   $data = [
     *      'post_id'           => (int) Required. The product ID.
     *      'fulfillment_type' => (string) Required. The fulfillment type default:physical.
     *      'variation_type'  => (string) Required. The variation type default:simple.
     *      'manage_stock'  => (int) Required. The manage stock default:1.
     *   ];
     */
    public static function create($data, $params = [])
    {
        $isCreated = static::getQuery()->create($data);

        if ($isCreated) {
            return static::makeSuccessResponse(
                $isCreated,
                __('Product has been created successfully', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Product creation failed!', 'fluent-cart')]
        ]);
    }

    /**
     * Update a product detail with the given data.
     * @param int $id The id of the product detail to be updated.
     * @param array $data Array containing the necessary parameters.
     *
     *   $data = [
     *          'id'                => (int) Required. The detail id.
     *          'post_id'           => (int) Required. The product ID.
     *          'fulfillment_type'   => (string) Required. The fulfillment type.
     *          'variation_type'  => (string) Required. The variation type.
     *          'default_variation_id' => (int) Required. The default variation ID.
     *          'manage_stock'  => (int) Required. The manage stock default:1.
     *  ];
     * @param array $params Additional parameters for the update process.
     *  $params = [
     *          'triggerable_action'  => (string) Required. This param will help to update detail based on the specific action i.e: all_column(Triggers when  multiple columns'll update), specific_column(Triggers when specific column'll update).
     *  ];
     */
    public static function update($data, $id, $params = [])
    {
        $data ??= [];

        if (!$id) {
            return static::makeErrorResponse([
                ['code' => 403, 'message' => __('Please edit a valid product!', 'fluent-cart')]
            ]);
        }

        $detail = static::getQuery()->find($id);

        if (!$detail) {
            return static::makeErrorResponse([
                ['code' => 404, 'message' => __('Product not found, please reload the page and try again!', 'fluent-cart')]
            ]);
        }

        if (Arr::get($params, 'triggerable_action') === 'all_column') {
            if (Arr::get($data, 'manage_stock') == 0) {
                $data['stock_availability'] = Helper::IN_STOCK;
            }
            $data['min_price'] = $detail->variants()->min('item_price');
            $data['max_price'] = $detail->variants()->max('item_price');
        }

        if (Arr::get($params, 'triggerable_action') === 'specific_column') {
            if (Arr::get($data, 'variation_type') == 'simple') {
                $variationIds = Arr::get($data, 'variation_ids', []);
                if (!empty($detail->post_id) && count($variationIds) > 0) {
                    ProductAdminHelper::deleteOrphanVariant($detail->post_id, $variationIds);
                }
            }
        }

        if (empty(Arr::get($data, 'default_variation_id'))) {
            $data['default_variation_id'] = NULL;
        }

        // Handle other_info merge
        if (Arr::has($data, 'other_info')) {
            $existingOtherInfo = $detail->other_info ?? [];
            $newOtherInfo = Arr::get($data, 'other_info', []);

            // Merge existing with new data (new data overwrites existing)
            $mergedOtherInfo = array_merge($existingOtherInfo, $newOtherInfo);

            // Handle subscription-specific logic
            if (Arr::get($mergedOtherInfo, 'payment_type') == 'subscription') {
                if (Arr::get($mergedOtherInfo, 'manage_setup_fee') == 'yes') {
                    $signupFee = Helper::toCent(floatval(Arr::get($mergedOtherInfo, 'signup_fee', 0)));
                    $mergedOtherInfo['signup_fee'] = $signupFee;
                }
            }

            $data['other_info'] = $mergedOtherInfo;
        }

        $isUpdated = $detail->update($data);


        if ($isUpdated) {
            return static::makeSuccessResponse($isUpdated, __('Product pricing has been changed!', 'fluent-cart'));
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Product update failed.', 'fluent-cart')]
        ]);
    }

    /**
     * Delete  product detail and its associated data.
     *
     * @param int $id The id of the product detail to be deleted.
     * @param array $params Additional parameters for the deletion process.
     *
     */
    public static function delete($id, $params = [])
    {
        //
    }

}
