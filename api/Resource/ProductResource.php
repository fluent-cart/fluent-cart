<?php

namespace FluentCart\Api\Resource;

use FluentCart\Api\Taxonomy;
use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Events\StockChanged;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\ProductAdminHelper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;

class ProductResource extends BaseResourceApi
{

    public static function getQuery(): Builder
    {
        return Product::query();
    }

    public static function get(array $params = []): array
    {
        return [];
    }

    public static function getProducttitle($productId)
    {
        $product = static::getQuery()->find($productId);
        if ($product) {
            return $product->post_title;
        }
        return '';
    }


    /**
     * Find product by its ID.
     *
     * @param int|string $id The ID of the post.
     * @param array $data Additional data for finding product (optional).
     *
     */
    public static function find($id, $data = []): ?array
    {
        return ShopResource::find($id, $data = []);
    }

    /**
     * Create a new product with the given data.
     *
     * @param array $data Array containing the necessary parameters.
     *
     *   $data = [
     *      'post_title'   => (string) Required. The title of the product.
     *      'post_status'  => (string) Optional. The status of the product default:draft.
     *      'post_content' => (string) Optional. The content of the product.
     *      'post_date'    => (date) Optional. The date of the product.
     *      'detail'       => (array) Required. Details of the product.
     *          'fulfillment_type' => (string) Required. The fulfillment type default:physical.
     *          'variation_type'  => (string) Required. The variation type default:simple.
     *          'manage_stock'  => (int) Required. The manage stock default:1.
     *  ];
     */
    public static function create($data, $params = [])
    {
        $postData = array_filter(Arr::only($data, [
            'post_title',
            'post_excerpt',
            'post_content',
            'post_status',
        ]));
        $postData['post_type'] = FluentProducts::CPT_NAME;

        $createdPostId = wp_insert_post($postData);
        if (!$createdPostId) {
            return static::makeErrorResponse([
                [
                    'code'    => 403,
                    'message' => esc_html($createdPostId->get_error_message()),
                ]
            ]);

        }

        $detail = Arr::get($data, 'detail');
        $detail['post_id'] = $createdPostId;
        $createdProductDetail = ProductDetailResource::create($detail);

        if ($createdProductDetail) {
            return static::makeSuccessResponse(
                [
                    'ID'              => $createdPostId,
                    'product_details' => Arr::get($createdProductDetail, 'data'),
                ],
                __('Product has been created successfully', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Product creation failed!', 'fluent-cart')]
        ]);
    }

    /**
     * Update a product with the given data.
     *
     * @param array $product Array containing the necessary parameters.
     *
     *   $product = [
     *      'ID'            => (int) Required. The product ID.
     *      'post_title'   => (string) Required. The title of the product.
     *      'post_status'  => (string) Optional. The status of the product.
     *      'post_content' => (string) Optional. The content of the product.
     *      'post_date'    => (string) Optional. The date of the product.
     *      'detail'        => (array) Required. Details of the product.
     *          'id'                => (int) Required. The detail ID.
     *          'post_id'           => (int) Required. The product ID.
     *          'fulfillment_type'   => (string) Required. The fulfillment type.
     *          'variation_type'  => (string) Required. The variation type.
     *          'default_variation_id' => (int) Required. The default variation ID.
     *      'variants'      => (array) Required. Variants of the product.
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
     *          'product-brands'       => (array) Required if brands. Product brands.
     *              [0]       => (int) Optional. The tag ID.
     * ];
     */
    public static function update($product, $postId = '', $params = [])
    {

        $product ??= [];
        $variants = Arr::get($product, 'variants', []);
        $detail = Arr::get($product, 'detail');
        $gallery = Arr::get($product, 'gallery', []);
        $variants = Arr::except($variants, ['*']);


        if (count($variants) > 0) {

            $variationType = Arr::get($detail, 'variation_type', 'simple');
            if ($variationType === 'simple') {
                $variant = $variants[0];
                $otherInfo = Arr::get($variant, 'other_info', []);

                $priceColumns = [
                    'item_price',
                    'compare_price',
                    'item_cost',
                ];

                foreach ($priceColumns as $column) {
                    if (Arr::has($variant, $column)) {
                        $variant[$column] = Arr::get($variant, $column) * 100;
                    }
                }

                unset($variant['rowId']);
                unset($variant['media']);
                $variantData = $variant;

                // Handle other_info
                if (!empty($otherInfo)) {
                    if (Arr::get($otherInfo, 'payment_type') == 'subscription') {
                        if (Arr::get($otherInfo, 'manage_setup_fee') == 'yes') {
                            $signupFee = Helper::toCent(floatval(Arr::get($otherInfo, 'signup_fee', 0)));
                            Arr::set($otherInfo, 'signup_fee', $signupFee);
                        }
                        $variantData['payment_type'] = 'subscription';
                    } else {
                        $variantData['payment_type'] = 'onetime';
                    }
                    $variantData['other_info'] = $otherInfo;
                }


                // Only update if there's data to update
                if (!empty($variantData)) {
                    ProductVariation::query()->where('id', Arr::get($variant, 'id'))->update($variantData);
                }

            } else {
                $variantData = [];

                foreach ($variants as $index => $variant) {
                    $otherInfo = Arr::get($variant, 'other_info', []);

                    $priceColumns = [
                        'item_price',
                        'compare_price',
                        'item_cost',
                    ];

                    foreach ($priceColumns as $column) {
                        if (Arr::has($variant, $column)) {
                            $variant[$column] = Arr::get($variant, $column) * 100;
                        }
                    }
                    unset($variant['rowId']);
                    $variant['serial_index'] = $index + 1;
                    if (!empty($otherInfo)) {
                        if (Arr::get($otherInfo, 'payment_type') == 'subscription') {
                            if (Arr::get($otherInfo, 'manage_setup_fee') == 'yes') {
                                $signupFee = Helper::toCent(floatval(Arr::get($otherInfo, 'signup_fee', 0)));
                                Arr::set($otherInfo, 'signup_fee', $signupFee);
                            }
                        }
                        $variant['other_info'] = $otherInfo;
                    }
                    $variantData[] = $variant;

                }

                // Only batch update if there's data
                if (!empty($variantData)) {
                    ProductVariation::query()->batchUpdate($variantData);
                }
            }


//            $variationDetails = $detail;
//            $variants = ProductAdminHelper::syncProduct($variationDetails, $variants);
        }

        $defaultVariationId = Arr::get($detail, 'default_variation_id');
        $detail['default_variation_id'] = $defaultVariationId;
        ProductDetailResource::update($detail, Arr::get($detail, 'id'), ['triggerable_action' => 'all_column']);

        (new StockChanged([$postId]))->dispatch();

        static::updateWpPost($postId, $product);
        if (Arr::has($product, 'gallery')) {
            update_post_meta($postId, FluentProducts::CPT_NAME . '-gallery-image', $gallery);
        }


        if (isset($gallery[0])) {
            set_post_thumbnail($postId, Arr::get($gallery, '0.id'));
            $thumbnailImageId = get_post_meta($postId, '_thumbnail_id', true);
            $thumbnail = wp_prepare_attachment_for_js($thumbnailImageId);
            $thumbUrl = Arr::get($thumbnail, 'url');
            if (!empty($thumbUrl) && Arr::get($gallery, '0.id') !== $thumbnailImageId) {
                update_post_meta($postId, '_thumbnail_id', $thumbnailImageId);
            }
        } else {
            delete_post_thumbnail($postId);
        }

        $product = static::getQuery()->with('variants')->addAppends([
            'viewUrl'
        ])->find($postId);


        return static::makeSuccessResponse(
            $product,
            __('Product has been updated', 'fluent-cart')
        );
    }

    public static function updateWpPost($postId, $params = [])
    {

        $postStatus = Arr::get($params, 'post_status');
        $postTitle = Arr::get($params, 'post_title');
        $postContent = Arr::get($params, 'post_content');
        $postExcerpt = Arr::get($params, 'post_excerpt');
        $commentStatus = Arr::get($params, 'comment_status');
        $postName = Arr::get($params, 'post_name');
        $postDate = Arr::get($params, 'post_date');
        if (empty($postDate) || $postStatus !== 'future') {
            $postDate = DateTime::gmtNow()->format('Y-m-d H:i:s');
        }

        if ($postStatus === 'future') {
            $postDate = DateTime::anyTimeToGmt($postDate)->format('Y-m-d H:i:s');
        }

        $data = [
            'ID'             => $postId,
            'post_title'     => $postTitle,
            'post_status'    => $postStatus,
            'comment_status' => $commentStatus,
            'post_name'      => $postName,
        ];

        if (isset($postExcerpt)) {
            $data['post_excerpt'] = $postExcerpt;
        }

        $activeEditor = Arr::get($params, 'detail.other_info.active_editor', 'wp-editor');
        if (empty($activeEditor)) {
            $activeEditor = 'wp-editor';
        }
        if (isset($postContent)) {
            $data['post_content'] = $postContent;
        }
        if (!empty($postDate)) {
            $data['post_date'] = $postDate;
            $data['post_date_gmt'] = $postDate;
            $data['post_modified'] = $postDate;
            $data['post_modified_gmt'] = $postDate;
        }

        $updated = wp_update_post($data);

        if ($updated) {
            Product::query()->where('ID', $postId)->update([
                'post_status'       => $postStatus,
                'post_date'         => $postDate,
                'post_date_gmt'     => $postDate,
                'post_modified'     => $postDate,
                'post_modified_gmt' => $postDate,
            ]);
        }

        return $updated;
    }

    /**
     * Delete a product and its associated data.
     *
     * @param int $postId The ID of the product to be deleted.
     * @param array $params Additional parameters for the deletion process.
     *
     */
    public static function delete($postId, $params = [])
    {


        $product = static::getQuery()
            ->with('variants')
            ->with('orderItems', function ($query) use ($postId) {
                return $query->whereHas('order', function ($query) {
                    return $query->search(["status" => ["column" => "status", "operator" => "in", "value" => [Status::ORDER_ON_HOLD, Status::ORDER_PROCESSING]]]);
                });
            })
            ->find($postId);


        if (!empty($product)) {
            if (count($product->orderItems) > 0) {
                return static::makeErrorResponse([
                    ['code' => 400, 'message' => __('This product cannot be deleted at the moment. There are pending orders associated with it. Deleting the product will disrupt the order processing and might cause inconvenience to our customers.', 'fluent-cart')]
                ]);
            }
            foreach ($product->variants as $variant) {
                $variant->media()->delete();
            }
            $product->detail()->delete();
            $product->variants()->delete();
            $product->downloadable_files()->delete();

            $taxonomies = Taxonomy::getTaxonomies();
            Collection::make($taxonomies)
                ->each(function ($taxonomy) use (&$product) {
                    $ids = Taxonomy::getTermIdsFromTerms($product->getTermByType($taxonomy)->get()->toArray());
                    foreach ($ids as $id) {
                        Taxonomy::deleteTaxonomyTermFromProduct($product->ID, $taxonomy, $id);
                    }
                });
            $product->wp_terms()->delete();

            $productTitle = $product->post_title;


            $deletedProduct = $product->delete();

            if ($deletedProduct) {

                fluent_cart_success_log(
                    __('Product deleted', 'fluent-cart'),
                    sprintf(
                        /* translators: %s is the product title */
                        __('Product %s is deleted', 'fluent-cart'), $productTitle),
                    [
                        'module_name' => 'Product',
                        'module_id'   => 0,
                        'module_type' => Product::class,
                    ]
                );
                return static::makeSuccessResponse(
                    '',
                    __('Selected product and associated data has been deleted', 'fluent-cart')
                );
            }
            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('Product deletion failed!', 'fluent-cart')]
            ]);
        }

        return static::makeErrorResponse([
            ['code' => 404, 'message' => __('Product not found in database.', 'fluent-cart')]
        ]);

    }

    public static function setThumbnail($productId, $data = [])
    {

        $productMeta = ProductMetaResource::find($productId);

        if (empty($productMeta)) {
            return ProductMetaResource::Create($data['thumbnail'], ['product_id' => $productId]);
        }
        return ProductMetaResource::update($data['thumbnail'], $productId);
    }

    /**
     *
     * @param $productId
     * @param $data
     * @return mixed
     */
    public static function syncVariantOption($productId, $data = [])
    {
        $srcPricing = ProductDetail::where('post_id', $productId)->first();
        $settings = Arr::get($data, 'options');
        $variationType = Arr::get($data, 'variation_type');

        if (!empty($variationType) && $variationType === Helper::PRODUCT_TYPE_ADVANCE_VARIATION) {

            $variants = ProductAdminHelper::syncProduct($srcPricing, $settings);

            $srcPricing->fill([
                'other_info'     => $settings,
                'variation_type' => Helper::PRODUCT_TYPE_ADVANCE_VARIATION,
            ])->save();

            return static::makeSuccessResponse(
                $variants,
                __('Variation combination updated!', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Illegal data provided.', 'fluent-cart')]
        ]);
    }

    /**
     * Manage products based on the provided action and product IDs.
     *
     * @param array $params Optional. Array containing the necessary parameters
     *        [
     *           'action' => (string) Required. The action to be performed on the selected products.
     *                       (e.g., Possible values: 'delete_products')
     *           'product_ids'  => (array) Required. Product IDs whose action will be performed.
     *        ]
     *
     */
    public static function manageBulkActions($params = [])
    {
        $action = Arr::get($params, 'action', '');
        $productIds = Arr::get($params, 'product_ids', []);

        $productIds = array_map(function ($id) {
            return (int)$id;
        }, $productIds);

        $productIds = array_filter($productIds);

        if (!$productIds) {
            return static::makeErrorResponse([
                ['code' => 403, 'message' => __('Products selection is required', 'fluent-cart')]
            ]);
        }

        $products = Product::whereIn('ID', $productIds)->get();

        if ($action == 'delete_products') {

            $failedProductIds = [];
            $deletedProductIds = [];

            foreach ($products as $product) {
                $isDeleted = static::delete($product->ID);

                if (is_wp_error($isDeleted)) {
                    $failedProductIds[] = $product->ID;
                } else {
                    $deletedProductIds[] = $product->ID;
                }
            }

            if (count($failedProductIds) > 0) {
                $failedProductIds = implode(' , ', $failedProductIds);
                /* translators: %s: The product ID(s) that could not be deleted. */
                return count($deletedProductIds) > 0
                    ? static::makeSuccessResponse(
                        '',
                        sprintf(
                            /* translators: %s: The product ID(s) that could not be deleted. */
                            esc_html__(
                                'The Product ID - %s cannot be deleted at the moment as there are pending orders associated with it. And remaining product and its associated data have been deleted.',
                                'fluent-cart'
                            ),
                            esc_html($failedProductIds)
                        )
                    )
                    : static::makeErrorResponse([
                        [
                            'code'    => 400,
                            'message' => sprintf(
                                /* translators: %s: The product ID(s) that could not be deleted. */
                                esc_html__(
                                    'The Product ID - %s cannot be deleted at the moment as there are pending orders associated with it.',
                                    'fluent-cart'
                                ),
                                esc_html($failedProductIds)
                            ),
                        ],
                    ]);
            }

            if (count($deletedProductIds) > 0 && count($failedProductIds) < 1) {
                return static::makeSuccessResponse('', __('Selected product and associated data have been deleted', 'fluent-cart'));
            }
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Selected action is invalid', 'fluent-cart')]
        ]);
    }

    public static function validateDownloadableFiles($data)
    {
        $downloadableFiles = Arr::except(Arr::get($data, 'downloadable_files', []), ['*']);
        $variants = Arr::except(Arr::get($data, 'variants', []), ['*']);
        $fulfilmentType = Arr::get($data, 'detail.fulfillment_type');
        $errors = [];

        if (!empty($downloadableFiles)) {
            foreach ($variants as $index => $variant) {
                $count = 0;
                $id = Arr::get($variant, 'rowId', null);
                foreach ($downloadableFiles as $idx => $downloadFile) {
                    $variantIds = Arr::get($downloadFile, 'product_variation_id', null);
                    if ($variantIds == null) {
                        $errors['downloadable_files.' . $idx . '.product_variation_id'] = 'Please choose variant';
                    }
                    if ($fulfilmentType === 'digital' && is_array($variantIds) && in_array($id, $variantIds)) {
                        $count += 1;
                    }
                    if ($fulfilmentType === 'physical') {
                        if (Arr::get($variant, 'downloadable') == 'true' && is_array($variantIds) && in_array($id, $variantIds)) {
                            $count += 1;
                        }
                        if (Arr::get($variant, 'downloadable') == 'false') {
                            $count += 1;
                        }
                    }
                }
                if ($count == 0) {
                    $errors['variants.' . $index . '.downloadable'] = sprintf(
                        /* translators: %s is the variant title */
                        __('%s variant is downloadable without any downloadable file', 'fluent-cart'),
                        $variant['variation_title']

                    );

                }
            }
        }
        return $errors;
    }

    public static function getNextProduct($productId, $skipOutOfStock)
    {

    }

    /**
     * Count total no of products.
     *
     */
    public static function countTotalProducts()
    {
        return static::getQuery()
            ->whereHas('detail')
            ->whereHas('variants')
            ->count();
    }

    public static function syncTaxonomyTerms($data, $postId = '', $params = [])
    {
        $data ??= [];

        if (count(Arr::get($data, 'terms', [])) == 0) {
            $isSynced = Taxonomy::deleteAllTermRelationshipsFromProduct($postId, Arr::get($data, 'taxonomy'));
        } else {
            $isSynced = Taxonomy::syncTaxonomyTermsToProduct($postId, Arr::get($data, 'taxonomy'), Arr::get($data, 'terms', []));
        }
        if (!is_wp_error($isSynced)) {
            return static::makeSuccessResponse(
                $isSynced,
                __("Product has been updated", 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __("Product update failed!", 'fluent-cart')]
        ]);
    }

    public static function deleteTaxonomyTerms($data, $postId = '', $params = [])
    {
        $data ??= [];

        $isDeleted = Taxonomy::deleteTaxonomyTermFromProduct(
            $postId,
            Arr::get($data, 'taxonomy'),
            Arr::get($data, 'term', null)
        );

        if (!is_wp_error($isDeleted)) {
            return static::makeSuccessResponse(
                $isDeleted,
                __("Product has been updated", 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __("Product update failed!", 'fluent-cart')]
        ]);
    }

}
