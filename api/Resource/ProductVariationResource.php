<?php

namespace FluentCart\Api\Resource;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class ProductVariationResource extends BaseResourceApi
{

    public static function getQuery(): Builder
    {
        return ProductVariation::query();
    }

    public static function get(array $params = []): array
    {
        $variantIdsForFilter = Arr::get($params, 'variant_ids', []);

        $query = static::getQuery()
                ->select(Arr::get($params, 'select', '*'))
                ->whereIn('id', $variantIdsForFilter);

        if (Arr::get($params, 'with_detail')) {
            $query->with('product_detail');
        }

        $variants = $query->orderBy(
                sanitize_sql_orderby(Arr::get($params, 'order_by', 'ID')),
                sanitize_sql_orderby(Arr::get($params, 'order_type', 'ASC'))
            )
            ->get();

        return [
            'variants' => $variants
        ];
    }

    /**
     * Find variant by its id.
     *
     * @param int $variantId The id of the variant.
     * @param array $data Additional data for finding variant (optional).
     *
     */
    public static function find($variantId, $data = [])
    {
        return static::getQuery()->find($variantId);
    }

    /**
     * Create a new variant with the given data.
     *
     * @param array $data Array containing the necessary parameters.
     *
     *   $data = [
     *          'id'             => (int) Required. The variant ID.
     *          'post_id'        => (int) Required. The product ID.
     *          'variant_title'  => (string) Required. The variant title.
     *          'item_price'     => (float) Required. The item price.
     *          'compare_price'  => (float) Required. The compare price.
     *          'manage_cost'    => (string) Optional. Whether to manage costs.
     *          'item_cost'      => (float) Required if manage cost is yes. The item cost.
     *          'manage_stock'   => (string) Required. Whether to manage stock.
     *          'stock_status'   => (string) Required. The stock status.
     *          'stock'          => (int) Required. The stock quantity.
     *          'media'          => (array) Optional. Info of media files for each variant.
     *              'id'    => (string) Required if upload any media. The media ID.
     *              'url'   => (string) Required if upload any media. The media URL.
     *              'title' => (string) Required if upload any media. The media title.
     *          'other_info'     => (array) Optional. Other information for the variant.
     *              'payment_type'       => (string) Required. The payment type.
     *              'times'             => (string) Required. The number of times.
     *              'repeat_interval'       => (string) Required. The repeat interval unit.
     *              'signup_fee'         => (string) Required. The signup fee.
     *              'downloadable_files' => (array) Required if downloadable is true.
     *                  'download_limit'  => (string) Required. The download limit.
     *                  'download_expiry' => (string) Required. The download expiry.
     *              'downloadable'      => (bool) Optional. Whether the product is downloadable.
     *          'files'          => (array) Optional. Info of downloadable files for each variant.
     *              'title'      => (string) Required if files. The file title.
     *              'type'       => (string) Required if files. The file type.
     *              'file_name'  => (string) Required if files. The file name.
     *              'file_path'  => (string) Required if files. The file path.
     *              'file_url'   => (string) Required if files. The file URL.
     *              'serial'     => (string) Required if files. The file serial
     *  ];
     */
    public static function create($variant, $params = [])
    {
        $otherInfo = Arr::get($variant, 'other_info');
        if (Arr::get($otherInfo, 'payment_type') == 'onetime') {
            $otherInfo = Arr::only($otherInfo, [
                'payment_type',
                'description',
            ]);
        }
        if (Arr::get($otherInfo, 'payment_type') == 'subscription') {
            if (Arr::get($otherInfo, 'manage_setup_fee') == 'no') {
                unset($otherInfo['signup_fee_name']);
                unset($otherInfo['signup_fee']);
                unset($otherInfo['setup_fee_per_item']);
            }
            if (Arr::get($otherInfo, 'manage_setup_fee') == 'yes') {
                $signupFee = Helper::toCent(floatval(Arr::get($otherInfo, 'signup_fee', 0)));
                Arr::set($otherInfo, 'signup_fee', $signupFee);
            }
        }

        $hasSubscription = Arr::get($variant, 'other_info.payment_type') === 'subscription';
        $isDownloadable = Arr::get($variant, 'downloadable', true);
        $itemPrice = Arr::get($variant, 'item_price', 1);
        $comparePrice = Arr::get($variant, 'compare_price');
        $available = Arr::get($variant, 'available', 0);
        $stockStatus = Arr::get($variant, 'stock_status', Helper::IN_STOCK);
        if (Arr::get($variant, 'manage_stock') == 1) {
            $stockStatus = ($available > 0) ? Helper::IN_STOCK : Helper::OUT_OF_STOCK;
        } else if (Arr::get($variant, 'manage_stock') == 0) {
            $stockStatus = Helper::IN_STOCK;
        }
        $variantData = [
            'post_id'          => Arr::get($variant, 'post_id'),
            'serial_index'     => Arr::get($variant, 'serial_index'),
            'manage_stock'     => Arr::get($variant, 'manage_stock', 0),
            'total_stock'      => Arr::get($variant, 'total_stock'),
            'available'        => Arr::get($variant, 'available'),
            'committed'        => Arr::get($variant, 'committed'),
            'on_hold'          => Arr::get($variant, 'on_hold'),
            'stock_status'     => $stockStatus,
            'item_price'       => Helper::toCent($itemPrice),
            //'compare_price'   => ($comparePrice !== '' && $comparePrice >= $itemPrice) ? Helper::toCent($comparePrice) : Helper::toCent($itemPrice),
            'compare_price'    => ($comparePrice !== '' && $comparePrice >= $itemPrice) ? Helper::toCent($comparePrice) : 0,
            'item_cost'        => Helper::toCent(Arr::get($variant, 'item_cost', 0)),
            'manage_cost'      => Arr::get($variant, 'manage_cost', 'false'),
            'fulfillment_type' => Arr::get($variant, 'fulfillment_type', 'physical'),
            'variation_title'  => Arr::get($variant, 'variation_title', ''),
            'other_info'       => $otherInfo,
            'downloadable'     => $isDownloadable,
            'payment_type'     => $hasSubscription ? 'subscription' : 'onetime',
        ];

        $isCreated = static::getQuery()->create($variantData);

        if ($isCreated) {
            $media = Arr::get($variant, 'media', []);
            if (!empty($media)) {
                static::setImage($media, $isCreated->id);
            }
            return static::makeSuccessResponse(
                $isCreated,
                __('Pricing has been created', 'fluent-cart')
            );
        }
        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Pricing creation failed!', 'fluent-cart')]
        ]);
    }

    /**
     * Update a variant with the given data.
     *
     * @param array $data Array containing the necessary parameters.
     *
     *   $variant = [
     *          'id'               => (int) Required. The variant ID.
     *          'post_id'          => (int) Required. The product ID.
     *          'variant_title'  => (string) Required. The variant title.
     *          'item_price'     => (float) Required. The item price.
     *          'compare_price'  => (float) Required. The compare price.
     *          'manage_cost'    => (string) Optional. Whether to manage costs.
     *          'item_cost'      => (float) Required if manage cost is yes. The item cost.
     *          'manage_stock'   => (string) Required. Whether to manage stock.
     *          'stock_status'   => (string) Required. The stock status.
     *          'stock'          => (int) Required. The stock quantity.
     *          'media'          => (array) Optional. Info of media files for each variant.
     *              'id'    => (string) Required if upload any media. The media ID.
     *              'url'   => (string) Required if upload any media. The media URL.
     *              'title' => (string) Required if upload any media. The media title.
     *          'other_info'     => (array) Optional. Other information for the variant.
     *              'payment_type'       => (string) Required. The payment type.
     *              'times'             => (string) Required. The number of times.
     *              'repeat_interval'       => (string) Required. The repeat interval unit.
     *              'signup_fee'         => (string) Required. The signup fee.
     *              'downloadable_files' => (array) Required if downloadable is true.
     *                  'download_limit'  => (string) Required. The download limit.
     *                  'download_expiry' => (string) Required. The download expiry.
     *              'downloadable'      => (bool) Optional. Whether the product is downloadable.
     *          'files'          => (array) Optional. Info of downloadable files for each variant.
     *              'title'      => (string) Required if files. The file title.
     *              'type'       => (string) Required if files. The file type.
     *              'file_name'  => (string) Required if files. The file name.
     *              'file_path'  => (string) Required if files. The file path.
     *              'file_url'   => (string) Required if files. The file URL.
     *              'serial'     => (string) Required if files. The file serial
     *      'product_terms' => (array) Optional. Terms of the product.
     *          'product-categories' => (array) Required if categories. Product categories.
     *              [0]       => (int) Optional. The category ID.
     *          'product-tags'       => (array) Required if tags. Product tags.
     *              [0]       => (int) Optional. The tag ID.
     *          'product-types'      => (array) Required if types. Product types.
     *              [0]       => (int) Optional. The type ID.
     * ];
     */
    public static function update($variant, $variantId, $params = [])
    {
        $variant ??= [];
        $variantId = Arr::get($variant, 'id');
        $otherInfo = Arr::get($variant, 'other_info');

        if (Arr::get($otherInfo, 'payment_type') == 'onetime') {
            $otherInfo = Arr::only($otherInfo, [
                'payment_type',
                'description',
            ]);
        }
        if (Arr::get($otherInfo, 'payment_type') == 'subscription') {
            if (Arr::get($otherInfo, 'manage_setup_fee') == 'no') {
                unset($otherInfo['signup_fee_name']);
                unset($otherInfo['signup_fee']);
                unset($otherInfo['setup_fee_per_item']);
            }
            if (Arr::get($otherInfo, 'manage_setup_fee') == 'yes') {
                $signupFee = Helper::toCent(floatval(Arr::get($otherInfo, 'signup_fee', 0)));
                Arr::set($otherInfo, 'signup_fee', $signupFee);
            }
        }

        $isDownloadable = Arr::get($variant, 'downloadable', true);
        $itemPrice = Arr::get($variant, 'item_price', 1);
        $comparePrice = Arr::get($variant, 'compare_price');
        $available = Arr::get($variant, 'available', 0);
        $stockStatus = Arr::get($variant, 'stock_status', Helper::IN_STOCK);
        if (Arr::get($variant, 'manage_stock') == 1) {
            $stockStatus = ($available > 0) ? Helper::IN_STOCK : Helper::OUT_OF_STOCK;
        } else if (Arr::get($variant, 'manage_stock') == 0) {
            $stockStatus = Helper::IN_STOCK;
        }

        $hasSubscription = Arr::get($variant, 'other_info.payment_type') === 'subscription';
        $variantData = [
            'post_id'          => Arr::get($variant, 'post_id'),
            'serial_index'     => Arr::get($variant, 'serial_index'),
            'manage_stock'     => Arr::get($variant, 'manage_stock', 0),
            'total_stock'      => Arr::get($variant, 'total_stock'),
            'available'        => Arr::get($variant, 'available'),
            'committed'        => Arr::get($variant, 'committed'),
            'on_hold'          => Arr::get($variant, 'on_hold'),
            'shipping_class'   => Arr::get($variant, 'shipping_class'),
            'stock_status'     => $stockStatus,
            'item_price'       => Helper::toCent($itemPrice),
            //'compare_price'   => ($comparePrice !== '' && $comparePrice >= $itemPrice) ? Helper::toCent($comparePrice) : Helper::toCent($itemPrice),
            'compare_price'    => ($comparePrice !== '' && $comparePrice >= $itemPrice) ? Helper::toCent($comparePrice) : 0,
            'item_cost'        => Helper::toCent(Arr::get($variant, 'item_cost', 0)),
            'manage_cost'      => Arr::get($variant, 'manage_cost', 'false'),
            'fulfillment_type' => Arr::get($variant, 'fulfillment_type', 'physical'),
            'variation_title'  => Arr::get($variant, 'variation_title', ''),
            'other_info'       => $otherInfo,
            'downloadable'     => $isDownloadable,
            'payment_type'     => $hasSubscription ? 'subscription' : 'onetime',
        ];

        // $result = ProductVariation::query()->find($variantId)->fill($variantData)->save();
        $isUpdated = static::getQuery()->find($variantId);
        $isUpdated->update($variantData);
        if ($isUpdated) {
            $media = Arr::get($variant, 'media', []);
            if (!empty($media)) {
                static::setImage($media, $variantId);
            } else {
                ProductMetaResource::delete($variantId);
            }


            return static::makeSuccessResponse(
                $isUpdated,
                __('Pricing has been updated', 'fluent-cart')
            );
        }
        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Pricing creation failed!', 'fluent-cart')]
        ]);
    }


    /**
     * Delete a variant and its associated data.
     *
     * @param int $variantId The id of the variant to be deleted.
     * @param array $params Additional parameters for the deletion process.
     *
     */
    public static function delete($variantId, $params = [])
    {
        $variant = static::getQuery()
            ->with('order_items', function ($query) use ($variantId) {
                return $query->whereHas('order', function ($query) {
                    return $query->search(["status" => ["column" => "status", "operator" => "in", "value" => [Status::ORDER_PROCESSING, Status::ORDER_ON_HOLD]]]);
                });
            })
            ->find($variantId);
        $variantTitle = $variant->variation_title;

        if (!empty($variant)) {
            if (count($variant->order_items) > 0) {
                return static::makeErrorResponse([
                    ['code' => 400, 'message' => __('This pricing cannot be deleted at the moment. There are pending orders associated with it. Deleting the pricing will disrupt the order processing and might cause inconvenience to our customers.', 'fluent-cart')]
                ]);
            }
            $variant->media()->delete();
            // $variant->downloadable_files()->delete();
            $deletedVariant = $variant->delete();
            if ($deletedVariant) {
                fluent_cart_success_log(
                    __('Pricing deleted', 'fluent-cart'),
                    sprintf(
                        /* translators: %s is the pricing title */
                        __('Pricing %s is deleted', 'fluent-cart'), $variantTitle),
                    [
                        'module_name' => 'Product',
                        'module_id'   => 0,
                        'module_type' => ProductVariation::class,
                    ]
                );
                return static::makeSuccessResponse(
                    '',
                    __('Selected pricing and associated data has been deleted', 'fluent-cart')
                );
            }


            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('Pricing deletion failed!', 'fluent-cart')]
            ]);
        }

        return static::makeErrorResponse([
            ['code' => 404, 'message' => __('Pricing not found in database.', 'fluent-cart')]
        ]);

    }

    public static function setImage($media, $variantId, $params = [])
    {

        $media ??= [];
        $exist = ProductMetaResource::find($variantId);
        if ($exist) {
            return ProductMetaResource::update($media, $variantId);

        } else {
            return ProductMetaResource::create($media, ['product_id' => $variantId]);
        }
    }

    /**
     * Update a variant pricing table info with the given data.
     * @param int $variantId The id of the variant.
     * @param array $data Array containing the necessary parameters.
     *
     *   $variant = [
     *          'description'  => (string) Required. The variant description.
     *   ];
     */
    public static function updatePricingTable($variant, $variantId, $params = [])
    {

        $variant ??= [];
        $description = Arr::get($variant, 'description');
        $isUpdated = static::getQuery()->find($variantId);
        $otherInfo = $isUpdated->other_info;
        $otherInfo['description'] = $description;
        $isUpdated->update([
            'other_info' => $otherInfo,
        ]);

        if ($isUpdated) {
            return static::makeSuccessResponse(
                $isUpdated,
                __('Pricing table has been updated', 'fluent-cart')
            );
        }
        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Failed to update pricing table!', 'fluent-cart')]
        ]);
    }
}
