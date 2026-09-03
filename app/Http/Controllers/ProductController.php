<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\ModuleSettings;
use FluentCart\Api\Resource\ProductDetailResource;
use FluentCart\Api\Resource\ProductResource;
use FluentCart\Api\Resource\ProductVariationResource;
use FluentCart\App\Events\StockChanged;
use FluentCart\Api\Resource\ShopResource;
use FluentCart\Api\Taxonomy;
use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Helpers\AdminHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Http\Requests\ProductCreateRequest;
use FluentCart\App\Http\Requests\ProductRequest;
use FluentCart\App\Http\Requests\ProductUpdateRequest;
use FluentCart\App\Http\Requests\UpgradePathSettingRequest;
use FluentCart\App\Models\Meta;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductDownload;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\ShippingClass;
use FluentCart\App\Models\TaxClass;
use FluentCart\App\Modules\ReportingModule\ProductReport;
use FluentCart\App\Services\Async\DummyProductService;
use FluentCart\App\Helpers\AttributeHelper;
use FluentCart\App\Services\BulkProductInsertService;
use FluentCart\App\Services\BulkProductUpdateService;
use FluentCart\App\Services\Filter\ProductFilter;
use FluentCart\App\Services\Permission\PermissionManager;
use FluentCart\App\Services\PlanUpgradeService;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;
use FluentCart\Framework\Support\Str;
use WP_REST_Response;
use FluentCart\Api\Helper as ApiHelper;

class ProductController extends Controller
{
    public function index(Request $request): WP_REST_Response
    {
        //$request->set('with', ['detail', 'variants:post_id,available,manage_stock,stock_status,variation_title,other_info']);
        $products = ProductFilter::fromRequest($request)->paginate();

        // Attach the resolved variation_display_title to each variation (batched,
        // no N+1) so the admin order product picker matches the order item display.
        AttributeHelper::attachVariationDisplayTitles($products->getCollection());

        $collection = $products->getCollection();

        $products->setCollection(
            $collection->transform(function ($product) {
                $product->setAppends(['view_url', 'edit_url']);

                // Source rating from canonical detail.other_info (maintained by recalculateProductRatings).
                // Use array_key_exists to avoid overwriting valid data with fallback-to-zero.
                if ($product->detail) {
                    $otherInfo = $product->detail->other_info ?? [];

                    if (array_key_exists('average_rating', $otherInfo)) {
                        $product->avg_rating = (float) $otherInfo['average_rating'];
                    }

                    if (array_key_exists('review_count', $otherInfo)) {
                        $product->reviews_count = (int) $otherInfo['review_count'];
                    }
                }

                return $product;
            })
        );

        $products = apply_filters('fluent_cart/products_list', $products);

        return $this->sendSuccess([
            'products' => $products
        ]);
    }

    public function find(Request $request, Product $product): array
    {
        $with = $this->resolveEagerLoads($request->get('with', []));

        if ($with) {
            $product->load($with);
        }
        $data = [
            'product' => $product,
        ];

        if (in_array('product_menu', $request->get('with', []))) {
            $data['product_menu'] = AdminHelper::getProductMenu($product);
        }

        return $data;
    }

    /**
     * What the `with` parameter on `GET products/{id}` may eager-load.
     *
     * ## The entry form
     *
     * Every entry is a LITERAL request key mapped to a CALLABLE. The key is never
     * decomposed, prefix-matched or suffix-stripped, so what the client sends is
     * either a key in this map or it is dropped. That is what keeps the dotted
     * `orderItems.order.customer`, the column-select
     * `orderItems.order.customer:id,email` and the nested array
     * `with[orderItems][]=order.customer` out — none of them is a key.
     *
     * The callback owns the whole path AND its own permission bar, and returns the
     * relation paths to eager-load, or an empty array when it refuses.
     *
     * ## Two tiers of key
     *
     * A SCREEN key names a calling screen and loads exactly what that screen
     * renders. A PUBLIC key is a plain relation name an external consumer of a
     * product endpoint can reasonably ask for.
     *
     * ## What stays off the map
     *
     * `Product::orderItems()` is a real relation keyed on `post_id`, so before the
     * request value was constrained an actor holding nothing but products/view
     * could walk `?with[]=orderItems.order.customer` from a catalogue product to
     * order and customer data — a probe pulled 104 KB of it off one product. It
     * must stay unreachable, along with `downloadable_files` (protected file
     * paths), `licensesMeta`, `postmeta` and `wpTerms`.
     *
     * `product_menu` is NOT on this map and does not belong on it: it is a
     * controller sentinel, not a relation. find() reads it straight off the raw
     * request and answers it with AdminHelper::getProductMenu(); it never reaches
     * load(), so it is unaffected by anything here.
     *
     * No entry declares a select. `Product::$appends` carries `thumbnail`, which
     * resolves through `detail->featured_media` — itself a ProductDetail append
     * backed by the `galleryImage` relation — and `ProductVariation::$appends`
     * lazy-loads `media` keyed on the variant `id`. A select would have to go
     * INSIDE the relation closure in any case; on the main query it would narrow
     * the product row itself.
     *
     * @return array<string, callable>
     */
    private function allowedWiths(): array
    {
        return [
            'block_product_detail' => [$this, 'blockProductDetail'],

            // The public entry points. These are the two relations an external
            // consumer can reasonably ask a product endpoint for: the catalogue
            // detail row and the variation rows. Both are catalogue data already
            // covered by the route's own products/view, and neither chains toward
            // orders, customers or protected downloads, so they carry no risk the
            // route does not already carry.
            //
            // The screen key above exists because the block editors want both in
            // one request; these two give either one on its own to a consumer
            // that is not that screen.
            'detail'               => [$this, 'publicDetail'],
            'variants'             => [$this, 'publicVariants'],
        ];
    }

    /**
     * The Gutenberg block editors' single-product fetch. Fourteen block editors
     * under `resources/admin/BlockEditor/` hit this endpoint —  BuySection,
     * Excerpt, MediaCarousel, PriceRange, ProductCard, ProductDescription,
     * ProductGallery, ProductImage, ProductInfo, ProductSku, ProductTitle,
     * RelatedProduct, SaleBadge and Stock — and between them they render the
     * detail row (price range, stock availability, gallery) and the variation
     * rows (SKU, per-variant price, buy section), so the key loads both.
     *
     * `products/view` is the route's own bar, restated here so the entry still
     * refuses if this map is ever reached from somewhere the route did not guard.
     *
     * @return array relation paths
     */
    private function blockProductDetail(): array
    {
        if (!PermissionManager::hasPermission('products/view')) {
            return [];
        }

        return ['detail', 'variants'];
    }

    /**
     * The catalogue detail row on its own — price range, stock availability,
     * variation type, featured media.
     *
     * @return array relation paths
     */
    private function publicDetail(): array
    {
        if (!PermissionManager::hasPermission('products/view')) {
            return [];
        }

        return ['detail'];
    }

    /**
     * The variation rows on their own — SKU, per-variant price, stock.
     *
     * @return array relation paths
     */
    private function publicVariants(): array
    {
        if (!PermissionManager::hasPermission('products/view')) {
            return [];
        }

        return ['variants'];
    }

    /**
     * Reduce a client-supplied `with` payload to the relation paths this endpoint
     * is allowed to eager-load.
     *
     * Anything that is not a literal key of allowedWiths() is dropped SILENTLY —
     * an unknown relation otherwise reaches Builder::getRelation() and becomes a
     * RelationNotFoundException, i.e. a 500, where a stale block build should
     * simply render without its data.
     *
     * Only STRING request entries are considered, which is what drops the nested
     * array shape `with[orderItems][]=order.customer`: its value is an array and
     * its key is never read.
     *
     * Kept local to this controller rather than folded into
     * `Services/Filter/BaseFilter::allowedWiths()`: that map adopts a Builder
     * returned by each callback, while this endpoint eager-loads onto a
     * route-model-bound instance, and the two maps share no entry.
     *
     * @param mixed $with raw request value
     * @return array relation names safe to pass to Product::load()
     */
    private function resolveEagerLoads($with): array
    {
        $map = $this->allowedWiths();

        $resolved = [];

        foreach (Arr::wrap($with) as $requestKey) {
            if (!is_string($requestKey) || !array_key_exists($requestKey, $map)) {
                continue;
            }

            $entry = $map[$requestKey];

            if (!is_callable($entry)) {
                continue;
            }

            foreach ((array) $entry() as $relation) {
                if (is_string($relation) && $relation !== '') {
                    $resolved[$relation] = true;
                }
            }
        }

        return array_keys($resolved);
    }

    public function getRelatedProducts(Request $request, $productId): WP_REST_Response
    {
        $productId = absint($productId);

        if (!$productId) {
            return $this->sendError(__('Invalid product ID', 'fluent-cart'));
        }

        $relatedBy = [];

        if (filter_var($request->get('related_by_categories'), FILTER_VALIDATE_BOOLEAN)) {
            $relatedBy[] = 'product-categories';
        }

        if (filter_var($request->get('related_by_brands'), FILTER_VALIDATE_BOOLEAN)) {
            $relatedBy[] = 'product-brands';
        }

        $orderBy = sanitize_text_field($request->get('order_by', 'title_asc'));
        $postsPerPage = absint($request->get('posts_per_page', 6));

        $products = ShopResource::getSimilarProducts($productId, true, [
            'related_by'     => $relatedBy,
            'order_by'       => $orderBy,
            'posts_per_page' => $postsPerPage,
        ]);

        return $this->sendSuccess([
            'products' => $products
        ]);
    }


    /**
     *
     * @param ProductRequest $request
     * @return WP_REST_Response
     */
    public function create(ProductCreateRequest $request): WP_REST_Response
    {

        $data = $request->getSafe($request->sanitize());


        $postData = array_filter(Arr::only($data, [
            'post_title',
            'post_status',
            //'detail',
        ]));

        $postData['post_name'] = sanitize_title($postData['post_title']);

        $postData['post_type'] = FluentProducts::CPT_NAME;
        $createdPostId = wp_insert_post($postData);

        if (is_wp_error($createdPostId)) {
            return $this->sendError([
                'code'    => 403,
                'message' => $createdPostId->get_error_message()
            ]);
        }

        $detail = Arr::get($data, 'detail');
        $detail['post_id'] = $createdPostId;

        $isDigital = Arr::get($detail, 'fulfillment_type') === 'digital';

        $createdProductDetail = ProductDetail::query()->create($detail);

        // Only Simple products get a default starter variant. Simple Variations
        // and Advanced Variations are created with no variant and build their own
        // on the edit page — Simple Variations via the pricing table's "Add
        // Pricing" empty state, Advanced Variations via attribute combinations
        // (a starter variant there would be an orphan the attribute UI never expects).
        $variation = null;
        if (Arr::get($detail, 'variation_type') === Helper::PRODUCT_TYPE_SIMPLE) {
            $variation = ProductVariation::query()->create([
                'post_id'          => $createdPostId,
                'serial_index'     => 1,
                'variation_title'  => $postData['post_title'],
                //'stock_status'     => $isDigital ? 'in-stock' : 'out-of-stock',
                'stock_status'     => 'in-stock',
                'payment_type'     => 'onetime',
                'total_stock'      => 1,
                'available'        => 1,
                'fulfillment_type' => $detail['fulfillment_type'],
                'other_info'       => [
                    'description'        => '',
                    'payment_type'       => 'onetime',
                    'tax_class'          => 'standard',
                    'tax_exempt'         => 'no',
                    'times'              => '',
                    'repeat_interval'    => '',
                    'trial_days'         => '',
                    'billing_summary'    => '',
                    'manage_setup_fee'   => 'no',
                    'signup_fee_name'    => '',
                    'signup_fee'         => '',
                    'setup_fee_per_item' => 'no',
                    'is_bundle_product'  => Arr::get($detail, 'other_info.is_bundle_product', 'no'),
                ]
            ]);
        }

        if ($createdProductDetail) {
            return $this->sendSuccess([
                'data'    => [
                    'ID'              => $createdPostId,
                    'variant'         => $variation,
                    'product_details' => Arr::get($createdProductDetail, 'data'),
                ],
                'message' => __('Product has been created successfully', 'fluent-cart')
            ]);
        }

        return $this->sendError(['code' => 400, 'message' => __('Product creation failed!', 'fluent-cart')]);

    }

    /**
     * Bulk insert products from import/manual entry.
     *
     * @param Request $request
     * @return WP_REST_Response
     */
    public function bulkInsert(Request $request): WP_REST_Response
    {
        $products = $request->get('products', []);

        if (empty($products) || !is_array($products)) {
            return $this->sendError([
                'message' => __('No products provided', 'fluent-cart'),
            ]);
        }

        if (count($products) > 10) {
            return $this->sendError([
                'message' => __('Maximum 10 products per chunk allowed', 'fluent-cart'),
            ]);
        }

        try {
            $service = new BulkProductInsertService();
            $result = $service->insertChunk($products);

            if (empty($result['created']) && !empty($result['errors'])) {
                return $this->sendError([
                    'message' => __('All products failed to insert', 'fluent-cart'),
                    'errors'  => $result['errors'],
                ]);
            }

            return $this->sendSuccess([
                'message' => sprintf(
                    __('%d product(s) created successfully', 'fluent-cart'),
                    count($result['created'])
                ),
                'created' => $result['created'],
                'errors'  => $result['errors'],
            ]);
        } catch (\Throwable $e) {
            return $this->sendError([
                'message' => __('Bulk insert failed: ', 'fluent-cart') . $e->getMessage(),
            ]);
        }
    }

    /**
     * Duplicate a product with selected options
     *
     * @param Request $request
     * @param int $productId
     * @return WP_REST_Response
     */
    public function duplicate(Request $request, $productId): WP_REST_Response
    {
        try {
            $data = $request->getSafe([
                'import_stock_management'   => 'sanitize_text_field',
                'import_license_settings'   => 'sanitize_text_field',
                'import_downloadable_files' => 'sanitize_text_field',
            ]);

            $importStockManagement = filter_var(
                Arr::get($data, 'import_stock_management', false),
                FILTER_VALIDATE_BOOLEAN
            );
            $importLicenseSettings = filter_var(
                Arr::get($data, 'import_license_settings', false),
                FILTER_VALIDATE_BOOLEAN
            );
            $importDownloadableFiles = filter_var(
                Arr::get($data, 'import_downloadable_files', false),
                FILTER_VALIDATE_BOOLEAN
            );

            try {
                $newProductId = Product::duplicateProduct($productId, [
                    'import_stock_management'   => $importStockManagement,
                    'import_license_settings'   => $importLicenseSettings,
                    'import_downloadable_files' => $importDownloadableFiles,
                ]);

                return $this->sendSuccess([
                    'product_id' => $newProductId,
                    'message'    => __('Product duplicated successfully. The new product has been saved as a draft.', 'fluent-cart')
                ]);

            } catch (\RuntimeException $e) {
                if ((int)$e->getCode() === 404) {
                    return $this->sendError([
                        'message' => __('Product not found', 'fluent-cart')
                    ], 404);
                }
                return $this->sendError([
                    'message' => __('Failed to duplicate product: ', 'fluent-cart') . $e->getMessage()
                ]);
            } catch (\Exception $e) {
                return $this->sendError([
                    'message' => __('Failed to duplicate product: ', 'fluent-cart') . $e->getMessage()
                ]);
            }

        } catch (\Exception $e) {
            return $this->sendError([
                'message' => __('An error occurred while duplicating the product.', 'fluent-cart'),
                'error'   => $e->getMessage()
            ]);
        }
    }

    public function delete(Request $request, Product $product)
    {

        $isDeleted = ProductResource::delete($product->ID);

        if (is_wp_error($isDeleted)) {
            return $isDeleted;
        }
        return $this->response->sendSuccess($isDeleted);
    }

    public function update(ProductUpdateRequest $request, $postId)
    {
        $data = $request->getSafe($request->sanitize());

        $isPartialUpdate = !(isset($data['detail']) && is_array($data['detail'])) &&
                           !(isset($data['variants']) && is_array($data['variants']));

        if ($isPartialUpdate) {
            return $this->applyPartialPostUpdate($data, $postId);
        }

        if (
            Arr::get($data, 'detail.variation_type') === 'simple' &&
            (empty(Arr::get($data, 'variants')) || empty(Arr::get($data, 'variants.0')))
        ) {
            return $this->sendError(
                [
                    'message' => __('Variation info is not present', 'fluent-cart')
                ]
            );
        }

        // $hasError = ProductResource::validateDownloadableFiles($data);
        // if (!empty($hasError)) {
        //     return $this->sendError($hasError);
        // }

        $isUpdated = ProductResource::update($data, $postId);

        if (is_wp_error($isUpdated)) {
            return $isUpdated;
        }

        do_action('fluent_cart/product_updated', [
            'data'    => $data,
            'product' => $isUpdated['data']
        ]);

        return $this->response->sendSuccess($isUpdated);
    }

    private function applyPartialPostUpdate(array $data, $postId)
    {
        $result = ProductResource::partialUpdate($data, $postId);

        if (is_wp_error($result)) {
            $statusCode = $result->get_error_code() === 'not_found' ? 404 : 422;
            return $this->sendError(['message' => $result->get_error_message()], $statusCode);
        }

        do_action('fluent_cart/product_updated', [
            'data'    => $data,
            'product' => $result['data'],
        ]);

        return $this->response->sendSuccess($result);
    }

    public function updateLongDescEditorMode(Request $request, $postId)
    {
        // Validate input
        $activeEditor = sanitize_text_field($request->get('active_editor'));

        // Fetch product detail directly by post_id
        $productDetail = ProductDetail::query()->where('post_id', $postId)->first();

        if (!$productDetail) {
            return $this->sendError([
                'message' => __('Product not found', 'fluent-cart')
            ]);
        }

        $otherInfo = $productDetail->other_info;
        $otherInfo['active_editor'] = $activeEditor;

        // Update product detail
        $isUpdated = $productDetail->update([
            'other_info' => $otherInfo
        ]);

        if (!$isUpdated) {
            return $this->sendError([
                'message' => __('Failed to update editor mode', 'fluent-cart')
            ]);
        }

        return $this->sendSuccess([
            'message' => __('Editor mode updated successfully', 'fluent-cart')
        ]);
    }


    public function updateTaxClass(Request $request, $postId)
    {
        $taxClassId = sanitize_text_field(Arr::get($this->request->all(), 'tax_class', 0));

        $taxClass = TaxClass::query()->findOrFail($taxClassId);

        if (empty($taxClass)) {
            return $this->sendError([
                'message' => __('Tax Class not found', 'fluent-cart')
            ]);
        }

        $productDetail = ProductDetail::query()->where('post_id', $postId)->first();

        if (empty($productDetail)) {
            return $this->sendError([
                'message' => __('Product not found', 'fluent-cart')
            ]);
        }

        // Get existing other_info and merge with new tax_class
        $otherInfo = $productDetail->other_info;
        $otherInfo['tax_class'] = $taxClass->id;

        // update only tax_class inside the $productDetails->other_info
        $productDetail->update([
            'other_info' => $otherInfo
        ]);

        return $this->sendSuccess([
            'message' => __('Tax profile updated successfully', 'fluent-cart')
        ]);
    }

    public function removeTaxClass(Request $request, $postId)
    {
        $productDetail = ProductDetail::query()->where('post_id', $postId)->first();
        if (empty($productDetail)) {
            return $this->sendError([
                'message' => __('Product not found', 'fluent-cart')
            ]);
        }
        $otherInfo = $productDetail->other_info;
        $otherInfo['tax_class'] = '';
        $productDetail->update([
            'other_info' => $otherInfo
        ]);
        return $this->sendSuccess([
            'message' => __('Tax profile removed successfully', 'fluent-cart')
        ]);
    }

    public function toggleTaxExempt(Request $request, $postId)
    {
        $productDetail = ProductDetail::query()->where('post_id', $postId)->first();
        if (empty($productDetail)) {
            return $this->sendError([
                'message' => __('Product not found', 'fluent-cart')
            ]);
        }

        $otherInfo = $productDetail->other_info ?: [];
        $taxExempt = sanitize_text_field($request->get('tax_exempt', 'no'));
        $existingTaxClass = Arr::get($otherInfo, 'tax_class');
        // Product-level tax settings live on product detail, so convert the UI
        // slug back to the stored tax-class ID before persisting the change.
        $taxClassSlug = sanitize_text_field($request->get('tax_class', ''));
        $otherInfo['tax_exempt'] = $taxExempt === 'yes' ? 'yes' : 'no';

        if (!$taxClassSlug) {
            $defaultClass = TaxClass::query()->where('slug', 'standard')->first();
            $resolvedTaxClassId = $existingTaxClass ?: ($defaultClass ? $defaultClass->id : null);
            if ($resolvedTaxClassId) {
                $resolvedTaxClass = TaxClass::query()->find($resolvedTaxClassId);
                $taxClassSlug = $resolvedTaxClass ? $resolvedTaxClass->slug : 'standard';
            } else {
                $taxClassSlug = 'standard';
            }
        } else {
            $taxClass = TaxClass::query()->where('slug', $taxClassSlug)->first();
            if (!$taxClass) {
                return $this->sendError([
                    'message' => __('Invalid tax class', 'fluent-cart')
                ], 422);
            }
            $resolvedTaxClassId = $taxClass->id;
        }

        $otherInfo['tax_class'] = $resolvedTaxClassId;

        $productDetail->update([
            'other_info' => $otherInfo
        ]);

        return $this->sendSuccess([
            'message'        => $taxExempt === 'yes'
                ? __('Product is now tax exempt', 'fluent-cart')
                : __('Tax will be charged on this product', 'fluent-cart'),
            'tax_exempt'     => $otherInfo['tax_exempt'],
            'tax_class'      => $otherInfo['tax_class'],
            'tax_class_slug' => $taxClassSlug ?: 'standard'
        ]);
    }

    public function updateShippingClass(Request $request, $postId)
    {
        $shippingClassId = sanitize_text_field(Arr::get($this->request->all(), 'shipping_class', 0));
        $shippingClass = ShippingClass::query()->findOrFail($shippingClassId);

        if (empty($shippingClass)) {
            return $this->sendError([
                'message' => __('Shipping Class not found', 'fluent-cart')
            ]);
        }

        $productDetail = ProductDetail::query()->where('post_id', $postId)->first();
        if (empty($productDetail)) {
            return $this->sendError([
                'message' => __('Product not found', 'fluent-cart')
            ]);
        }
        $otherInfo = $productDetail->other_info;
        $otherInfo['shipping_class'] = $shippingClass->id;
        $productDetail->update([
            'other_info' => $otherInfo
        ]);
        return $this->sendSuccess([
            'message' => __('Shipping Class updated successfully', 'fluent-cart')
        ]);
    }

    public function removeShippingClass(Request $request, $postId)
    {
        $productDetail = ProductDetail::query()->where('post_id', $postId)->first();
        if (empty($productDetail)) {
            return $this->sendError([
                'message' => __('Product not found', 'fluent-cart')
            ]);
        }

        $otherInfo = $productDetail->other_info;
        $otherInfo['shipping_class'] = '';
        $productDetail->update([
            'other_info' => $otherInfo
        ]);

        return $this->sendSuccess([
            'message' => __('Shipping Class removed successfully', 'fluent-cart')
        ]);
    }

    /**
     *
     * @param Request $request
     * @param $productId
     * @return WP_REST_Response
     */
    public function get(Request $request, $productId)
    {
        // attrMap joins fct_atts_relations, populated by the Advanced Variation feature.
        $variantRelations = ['media', 'attrMap'];

        $product = Product::with([
            'detail',
            'variants' => function ($query) use ($variantRelations) {
                $query->with($variantRelations)
                    ->orderBy('serial_index', 'ASC');
            }
        ])->with('downloadable_files')->find($productId);

        if (empty($product)) {
            return $this->entityNotFoundError(
                __('Product not found', 'fluent-cart'),
                __('Back to Product List', 'fluent-cart'),
                '/products'
            );
        }

        //        $product = ProductResource::search(['id' => $productId], function (Builder $query) {
//            return $query
//                ->with([
//                    'detail',
//                    'variants' => function ($query) {
//                        $query->with(['media'])
//                            ->orderBy('serial_index', 'ASC');
//                    }
//                ])
//                ->with('downloadable_files');
//        }, true)->first();


        if (!empty($product)) {
            $product->setAppends(['view_url', 'edit_url']);
            $taxonomies = Taxonomy::getTaxonomies();
            $taxonomies = Collection::make($taxonomies)
                ->map(function ($taxonomy) use (&$product) {
                    $taxonomy_object = get_taxonomy($taxonomy);
                    $all_labels = (array)get_taxonomy_labels($taxonomy_object);
                    $filtered_labels = Arr::only($all_labels, ['singular_name', 'name']);
                    $product[$taxonomy] = Taxonomy::getTermIdsFromTerms($product->getTermByType($taxonomy)->get()->toArray());

                    return [
                        'name'   => $taxonomy,
                        'label'  => Str::headline($taxonomy),
                        'terms'  => Taxonomy::getFormattedTerms($taxonomy),
                        'labels' => $filtered_labels
                    ];
                });

            if (in_array('product_menu', $request->get('with', []))) {
                $productMenu = AdminHelper::getProductMenu($product);
            }

            $featuredImageId = get_post_thumbnail_id($product->ID);
            $productData = $product->toArray();
            $productData['featured_image_id'] = $featuredImageId;

            $payload = apply_filters('fluent_cart/product/get_response_data', [
                'product'    => $productData,
                'product_id' => (int) $productId,
                'request'    => $request,
            ]);

            return $this->sendSuccess([
                'product'      => Arr::get($payload, 'product', $productData),
                'product_menu' => $productMenu ?? "",
                'taxonomies'   => $taxonomies,
            ]);
        } else {
            return $this->sendError([
                'message' => __('Something went wrong', 'fluent-cart'),
            ]);
        }
        //return ProductResource::find($postId);
    }


    public function getUpgradeSettings($id, Request $request): WP_REST_Response
    {
        return $this->sendSuccess(
            [
                'data' => PlanUpgradeService::getUpgradeSettings($id)
            ]
        );

    }

    public function saveUpgradeSetting(UpgradePathSettingRequest $request, $id): WP_REST_Response
    {
        $data = ApiHelper::sanitizeTextAll(
            $request->except('query_timestamp')
        );

        $isSaved = PlanUpgradeService::saveUpgradeSetting($data);

        if ($isSaved) {
            return $this->sendSuccess(
                [
                    'message' => __('Settings saved successfully', 'fluent-cart')
                ]
            );
        } else {
            return $this->sendError(
                [
                    'message' => __('Failed to save settings', 'fluent-cart')
                ]
            );
        }

    }

    public function deleteUpgradePath($id): WP_REST_Response
    {
        $isDeleted = Meta::query()->where('id', $id)->delete();
        if ($isDeleted) {
            return $this->sendSuccess(
                [
                    'message' => __('Path deleted successfully', 'fluent-cart')
                ]
            );
        } else {
            return $this->sendError(
                [
                    'message' => __('Failed to delete path', 'fluent-cart')
                ]
            );
        }
    }

    public function updateUpgradePath(UpgradePathSettingRequest $request, $id): WP_REST_Response
    {
        $data = ApiHelper::sanitizeTextAll(
            $request->except('query_timestamp')
        );

        $isUpdated = PlanUpgradeService::updateUpgradeSetting($id, $data);

        if ($isUpdated) {
            return $this->sendSuccess(
                [
                    'message' => __('Settings updated successfully', 'fluent-cart')
                ]
            );
        } else {
            return $this->sendError(
                [
                    'message' => __('Failed to update settings', 'fluent-cart')
                ]
            );
        }

    }

    public function getUpgradePaths($variationId, Request $request)
    {
        $params = $request->get('params');
        $orderHash = Arr::get($params, 'order_hash');

        if (!$variationId || !$orderHash) {
            return [];
        }

        $upgradePaths = PlanUpgradeService::getUpgardePathsFromVariation($variationId, $orderHash);

        return $this->sendSuccess([
            'upgradePaths' => $upgradePaths
        ]);
    }

    public function getPricingWidgets(Request $request, $productId)
    {
        $thisMonthKey = '-' . gmdate('d') . ' days';
        $ranges = [
            'all_time'    => __('All time', 'fluent-cart'),
            '-30 days'    => __('Last 30 days', 'fluent-cart'),
            $thisMonthKey => __('This month', 'fluent-cart'),
        ];

        $stats = ProductReport::getStatByProductIds([$productId], array_keys($ranges));

        $html = '<ul class="fct-lists">';

        foreach ($ranges as $rangeSlug => $range) {
            $stat = $stats[$rangeSlug];
            $prefix = '';
            if ($stat->total_quantity) {
                $prefix = ' <b title="Quantity">(' . $stat->total_quantity . ')</b> ';
            }
            $html .= '<li> <span>' . $range . $prefix . '</span>' . '<span>' . Helper::toDecimal($stat->total_amount, true) . '</span>';
        }

        $html .= '</ul>';

        $widgets = [
            [
                'title' => __('Quick Sales Overview', 'fluent-cart'),
                'body'  => $html,
            ],
        ];

        return [
            'widgets' => $widgets,
        ];
    }

    public function updateProductDetail(Request $request, $id)
    {
        $data = $request->getSafe([
            'variation_type'  => 'sanitize_key',
            'variation_ids.*' => 'intval',
            'action'          => 'sanitize_key'
        ]);

        $isUpdated = ProductDetailResource::update(
            $data,
            $id,
            ['action' => Arr::get($data, 'action', 'change_variation_type')]
        );

        if (is_wp_error($isUpdated)) {
            return $isUpdated;
        }
        return $this->response->sendSuccess($isUpdated);
    }

    public function syncTaxonomyTerms(Request $request, $id)
    {

        if (empty(($request->get('terms')))) {
            $data['taxonomy'] = sanitize_key($request->get('taxonomy'));
        } else {
            $data = $request->getSafe([
                'taxonomy' => 'sanitize_key',
                'terms.*'  => 'intval',
            ]);
        }
        $isUpdated = ProductResource::syncTaxonomyTerms($data, $id);

        if (is_wp_error($isUpdated)) {
            return $isUpdated;
        }
        return $this->response->sendSuccess($isUpdated);
    }

    public function deleteTaxonomyTerms(Request $request, $id)
    {

        $data = $request->getSafe([
            'taxonomy' => 'sanitize_key',
            'term'     => 'intval',
        ]);
        $isUpdated = ProductResource::deleteTaxonomyTerms($data, $id);

        if (is_wp_error($isUpdated)) {
            return $isUpdated;
        }
        return $this->response->sendSuccess($isUpdated);
    }

    public function updateVariantOption(Request $request, $postId)
    {
        $data = $request->all();

        // Cap user-supplied option groups before they reach the sync pipeline. The
        // client UI enforces a 200-combination ceiling, but a forged POST can carry
        // arbitrarily many entries. Trim at the controller so the filter chain and
        // downstream Pro listeners never see unbounded input.
        if (isset($data['options']) && is_array($data['options'])) {
            $data['options'] = array_slice($data['options'], 0, 200);
        }

        $isSynced = ProductResource::syncVariantOption($postId, $data);

        if (is_wp_error($isSynced)) {
            return $isSynced;
        }
        return $this->response->sendSuccess($isSynced);
    }


    public function addProductTerms(Request $request)
    {
        $data = $request->get('term');
        $name = Arr::get($data, 'name', '');
        $taxonomy = Arr::get($data, 'taxonomy', '');
        $parent = Arr::get($data, 'parent', '');
        $name = sanitize_text_field($name);
        $taxonomy = sanitize_text_field($taxonomy);
        $parent = sanitize_text_field($parent);

        $args = [];

        if (!empty($parent)) {
            $args['parent'] = $parent;
        }


        $termNames = explode(',', $name);
        $ids = Taxonomy::addTaxonomyTerms($taxonomy, $termNames, $args);

        // response->json() delegates to wp_send_json(), which prints and exits —
        // bypassing the REST server (and killing in-process dispatch). send()
        // returns the identical JSON body and status through WP_REST_Response.
        // addTaxonomyTerms returns false (not an array) for a taxonomy outside
        // the registered catalog, e.g. the unshipped product-tags — that must
        // fall into the 423 branch, not raise a count-on-bool warning.
        if (is_array($ids) && count($ids)) {
            return $this->response->send([
                'term_ids' => $ids,
                'names'    => $termNames
            ]);
        }

        return $this->response->sendError([
            'message' => __('Unable To Create Term/s', 'fluent-cart'),
        ], 423);
    }

    public function getProductTermsList(): array
    {
        $taxonomies = Taxonomy::getTaxonomies();

        $taxonomies = Collection::make($taxonomies)
            ->map(function ($taxonomy) {
                return [
                    'name'  => $taxonomy,
                    'label' => Str::headline($taxonomy),
                    'terms' => Taxonomy::getFormattedTerms($taxonomy),
                ];
            });
        return [
            "taxonomies" => $taxonomies,
        ];
    }

    public function getProductTermListByParent(Request $request): WP_REST_Response
    {
        $parentTerms = $request->get('parents');
        $termsData = [];
        foreach ($request->get('listeners') as $listener) {
            $termsData[$listener] = Taxonomy::getFormattedTerms($listener, false, $parentTerms);
        }
        return $this->sendSuccess([
            'data' => $termsData
        ]);

    }

    public function handleBulkActions(Request $request)
    {

        $isUpdated = ProductResource::manageBulkActions($request->all());

        if (is_wp_error($isUpdated)) {
            return $isUpdated;
        }
        return $this->response->sendSuccess($isUpdated);
    }

    public static function getMimeGroups()
    {
        return apply_filters('fluent_support/mime_groups', [
            'images'    => [
                'title' => __('Photos', 'fluent-cart'),
                'mimes' => [
                    'image/gif',
                    'image/ief',
                    'image/jpeg',
                    'image/webp',
                    'image/pjpeg',
                    'image/ktx',
                    'image/png',
                ],
            ],
            'csv'       => [
                'title' => __('CSV', 'fluent-cart'),
                'mimes' => [
                    'application/csv',
                    'application/txt',
                    'text/csv',
                    'text/plain',
                    'text/comma-separated-values',
                    'text/anytext',
                ],
            ],
            'documents' => [
                'title' => __('PDF/Docs', 'fluent-cart'),
                'mimes' => [
                    'application/excel',
                    'application/vnd.ms-excel',
                    'application/vnd.msexcel',
                    'application/octet-stream',
                    'application/pdf',
                    'application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                ],
            ],
            'zip'       => [
                'title' => __('Zip', 'fluent-cart'),
                'mimes' => [
                    'application/zip',
                ],
            ],
            'json'      => [
                'title' => __('JSON', 'fluent-cart'),
                'mimes' => [
                    'application/json',
                    'application/jsonml+json',
                ],
            ],
        ]);
    }

    public function searchVariantByName(Request $request): array
    {
        $data = $request->getSafe([
            'name'   => 'sanitize_text_field',
            'ids.*'  => 'intval',
            'search' => 'sanitize_text_field',
        ]);

        $name = Arr::get($data, 'name', '');
        if (empty($name)) {
            $name = Arr::get($data, 'search', '');
        }
        $ids = Arr::get($data, 'ids', []);
        $productVariations = [];

        $products = Product::query()
            ->with(['variants' => function ($variantQuery) use ($name) {
                if (!empty($name)) {
                    // Emit only variants the term actually hit: the variant's own
                    // title, or every variant of a product whose title matched.
                    // Without this, a product matched through one variant leaked
                    // all its non-matching siblings into the picker.
                    $variantQuery->where(function ($vq) use ($name) {
                        $vq->where('variation_title', 'like', '%' . $name . '%')
                            ->orWhereHas('product', function ($pq) use ($name) {
                                $pq->where('post_title', 'like', '%' . $name . '%');
                            });
                    });
                }
                // The relation query is shared across all matched parents, so this
                // caps total child rows serialized per request for this
                // remote-search picker.
                $variantQuery->orderBy('id')->limit(100);
            }])
            ->when(!empty($name) || count($ids) > 0, function (Builder $q) use ($name, $ids) {
                $q->where('post_status', 'publish');

                if (count($ids) > 0) {
                    $q->whereIn('ID', $ids);
                }

                if (!empty($name)) {
                    // The endpoint's name is searchVariantByName: a term must match
                    // the product title OR any of its variants' variation_title.
                    // The previous search-helper query matched post_title only, so
                    // typing a variant's own title returned nothing.
                    $q->where(function (Builder $titleQuery) use ($name) {
                        $titleQuery->where('post_title', 'like', '%' . $name . '%')
                            ->orWhereHas('variants', function ($variantQuery) use ($name) {
                                $variantQuery->where('variation_title', 'like', '%' . $name . '%');
                            });
                    });
                }
            })
            ->when(empty($name), function (Builder $q) {
                return $q->limit(10);
            })
            ->limit(20)->get()
            ->map(function ($product) {
                return [
                    'value'    => $product->ID,
                    'label'    => $product->post_title,
                    'children' => $product->variants->map(function ($variation) use ($product) {
                        return [
                            'value' => $variation->id,
                            // 'label' => $product->post_title . ' - ' . $variation->variation_title,
                            'label' => $variation->variation_title,
                        ];
                    })
                        ->toArray()
                ];
            })->toArray();
        return $products;
    }

    public function searchProductVariantOptions(Request $request): array
    {
        $data = $request->getSafe([
            'include_ids.*'       => 'intval',
            'search'              => 'sanitize_text_field',
            'scopes.*'            => 'sanitize_text_field',
            'subscription_status' => 'sanitize_text_field',
        ]);

        $subscription_status = Arr::get($data, 'subscription_status');
        $search = Arr::get($data, 'search', '');
        $includeIds = Arr::get($data, 'include_ids', []);

        $productsQuery = Product::query()
            ->whereIn('post_status', ['publish', 'private']);

        $productsQuery->with(['detail', 'variants' => function ($query) use ($subscription_status) {
            if ($subscription_status === 'not_subscribable') {
                $query->where('payment_type', '!=', 'subscription');
            }
        }]);

        $scopes = Arr::get($data, 'scopes', []);
        if ($scopes) {
            $productsQuery = $productsQuery->scopes($scopes);
        }

        if ($search) {
            $productsQuery->where(function ($query) use ($search, $subscription_status) {
                $query->where('post_title', 'like', '%' . $search . '%')
                    ->orWhereHas('variants', function ($query) use ($search, $subscription_status) {
                        $query->where('variation_title', 'like', "%$search%");
                        if ($subscription_status === 'not_subscribable') {
                            $query->where('payment_type', '!=', 'subscription');
                        }
                    });
            });
        }

        $productsQuery->limit(20);

        $products = $productsQuery->get();

        $pushedVariationIds = [];
        $formattedProducts = [];

        foreach ($products as $product) {
            $detail = $product->detail;
            if ($detail && $detail->manage_stock && $detail->stock_availability !== Helper::IN_STOCK) {
                continue;
            }

            $formatted = [
                'value' => 'product_' . $product->ID,
                'label' => $product->post_title,
            ];

            $variants = $product->variants;

            $children = [];
            foreach ($variants as $variant) {
                if ($variant->manage_stock && $variant->stock_status !== Helper::IN_STOCK) {
                    continue;
                }
                $pushedVariationIds[] = $variant->id;
                $children[] = [
                    'value' => $variant->id,
                    'label' => $variant->variation_title,
                ];
            }

            if (!$children) {
                continue;
            }

            $formatted['children'] = $children;
            $formattedProducts[$product->ID] = $formatted;
        }

        $leftVariationIds = array_diff($includeIds, $pushedVariationIds);

        if ($leftVariationIds) {
            $leftVariants = ProductVariation::query()
                ->whereIn('id', $leftVariationIds)
                ->with(['product' => function ($query) {
                    $query->whereIn('post_status', ['publish', 'private']);
                }, 'product.detail'])
                ->get();

            foreach ($leftVariants as $variant) {
                if ($subscription_status == 'not_subscribable' && $variant->payment_type === 'subscription') {
                    continue;
                }
                if ($variant->manage_stock && $variant->stock_status !== Helper::IN_STOCK) {
                    continue;
                }
                $product = $variant->product;
                if (!$product) {
                    continue;
                }
                $detail = $product->detail;
                if ($detail && $detail->manage_stock && $detail->stock_availability !== Helper::IN_STOCK) {
                    continue;
                }
                if (isset($formattedProducts[$product->ID])) {
                    $formattedProducts[$product->ID]['children'][] = [
                        'value' => $variant->id,
                        'label' => $variant->variation_title,
                    ];
                } else {
                    $formattedProducts[$product->ID] = [
                        'value'    => 'product_' . $product->ID,
                        'label'    => $product->post_title,
                        'children' => [
                            [
                                'value' => $variant->id,
                                'label' => $variant->variation_title,
                            ]
                        ]
                    ];
                }
            }
        }

        $products = array_values($formattedProducts);

        // sort the products by label
        usort($products, function ($a, $b) {
            return strcmp($a['label'], $b['label']);
        });

        return [
            'products' => $products
        ];
    }

    public function findSubscriptionVariants(Request $request)
    {
        $data = $request->getSafe([
            'name' => 'sanitize_text_field',
        ]);

        $search = Arr::get($data, 'name', '');


        if (!empty($search)) {
            $query['variation_title'] = [
                'column'   => 'variation_title',
                'operator' => 'like',
                'value'    => '%' . $search . '%',
            ];
        }

        $variants = ProductVariation::query()
            ->when(!empty($search), function (Builder $query) use ($search) {
                $query->where('variation_title', 'like', '%' . $search . '%');
            })
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(other_info, '$.payment_type')) = ?", ['subscription'])
            ->get()
            ->map(function ($variation) {
                return [
                    'id'    => $variation->id,
                    'title' => $variation->variation_title,
                ];
            })
            ->toArray();

        return $variants;


    }


    public function fetchVariationsByIds(Request $request): array
    {
        $ids = $request->getSafe(['productIds.*' => 'intval']);
        $ids = Arr::get($ids, 'productIds', []);
        $ids = is_array($ids) ? $ids : [];
        if (empty($ids)) {
            return ['products' => []];
        }
        $products = ProductVariationResource::search(["id" => ["column" => "id", "operator" => "in", "value" => is_array($ids) ? $ids : [],]], function (Builder $query) {
            return $query;
        }, true)->pluck('variation_title', 'id')->map(function ($name, $id) {
            return [
                'value' => $id,
                'label' => $name,
            ];
        })->values()->toArray();
        return ['products' => $products];
    }

    public function searchProductByName(Request $request): array
    {
        $searchValue = $request->getSafe('name', 'sanitize_text_field');
        $urlMode = $request->getSafe('url_mode', 'sanitize_text_field');
        $termId = $request->getSafe('termId', 'intval');

        $defaultFilters =
            [
                "wildcard" => $searchValue,
            ];

        $status = ["post_status" => ["column" => "post_status", "operator" => "in", "value" => ["publish"]]];

        $params = [
            "select"          => ['id AS ID', 'post_title'],
            "with"            => ['wpTerms'],
            "selected_status" => true,
            "status"          => $status,
            "default_filters" => $defaultFilters,
        ];

        if (!empty($termId)) {
            $params["taxonomy_filters"] = [
                'product-categories' => Arr::wrap($termId)
            ];
        }

        $products = ShopResource::get($params);
        $items = $products['products'];

        return [
            'products' => $items
        ];
    }

    public function getBundleInfo(Request $request, $productId): array
    {
        $variants = ProductVariation::query()
            ->where('post_id', $productId)
            ->select([
                'id',
                'variation_title',
                'other_info'
            ])
            ->get()
            ->toArray();

        $variants = Helper::loadBundleChild($variants);

        return $variants;
    }

    public function saveBundleInfo(Request $request, $variationId): array
    {
        $variation = ProductVariation::query()->findOrFail($variationId);

        $childIds = $request->get('bundle_child_ids');

        if (!empty($childIds) && is_array($childIds)) {
            // Reject any child variations that belong to a bundle product
            $bundleChildVariations = ProductVariation::whereIn('id', $childIds)
                ->get(['id', 'post_id']);

            foreach ($bundleChildVariations as $childVariation) {
                if ($childVariation->product && $childVariation->product->isBundleProduct()) {
                    return wp_send_json([
                        'message' => __('A bundle product cannot be added as a bundle child.', 'fluent-cart'),
                    ], 422);
                }
            }
        }

        $otherInfo = $variation->other_info ?? [];
        $otherInfo['bundle_child_ids'] = $childIds;
        $variation->other_info = $otherInfo;

        return [$variation->update()];
    }

    public function fetchProductsByIds(Request $request): array
    {
        $ids = $request->getSafe(['productIds.*' => 'intval']);

        $ids = Arr::get($ids, 'productIds', []);
        $ids = is_array($ids) ? $ids : [];

        if (empty($ids)) {
            return [
                'products' => []
            ];
        }
        $products = ProductResource::search([
            "id" => [
                "column"   => "id",
                "operator" => "in",
                "value"    => is_array($ids) ? $ids : [],
            ]
        ], function (Builder $query) {
            return $query
                ->with('detail');
        }, true);

        return [
            'products' => $products
        ];
    }

    public function getMaxExcerptWordCount(): WP_REST_Response
    {
        return $this->sendSuccess([
            'count' => (int)apply_filters('excerpt_length', 55)
        ]);
    }

    public function createDummyProducts(Request $request)
    {
        return DummyProductService::create($request->get('category'), $request->get('index'));
    }

    public function updateInventory(Request $request, $postId, $variantId)
    {
        if (!ModuleSettings::isActive('stock_management')) {
            return $this->response->sendError([
                'message' => __('Stock Management module is disabled. Enable it from Settings to manage inventory.', 'fluent-cart')
            ], 422);
        }

        $variant = ProductVariation::query()->find($variantId);

        if (!$variant) {
            return $this->response->sendError([
                'message' => __('Variant not found', 'fluent-cart')
            ], 404);
        }

        // Capture old stock state before update
        $oldAvailable = intval($variant->available);
        $oldStockStatus = $variant->stock_status;

        $detail = ProductDetail::query()->where('post_id', $postId)->first();

        // get variations by post_id
        $variations = ProductVariation::query()->where('post_id', $postId)->where('id', '!=', $variantId)->get();
        $updateData = [];
        foreach ($variations as $variation) {
            $updateData[] = [
                'id'           => $variation->id,
                'manage_stock' => 1,
                'total_stock'  => $variation->total_stock,
                'available'    => $variation->available,
                'stock_status' => $variation->stock_status
            ];
        }
        $newAvailable = intval($request->get('available'));
        $newStockStatus = $newAvailable > 0 ? 'in-stock' : 'out-of-stock';

        $updateData[] = [
            'id'          => $variantId,
            'total_stock' => sanitize_text_field($request->get('total_stock')),
            'available'   => $newAvailable,
            'manage_stock' => 1,
            'stock_status' => $newStockStatus
        ];
        // update variations
        $isUpdated = ProductVariation::query()->batchUpdate($updateData);


        if ($detail) {
            $hasAvailableStock = ProductVariation::query()->where('post_id', $postId)->where('available', '>', 0)->exists();
            $detail->stock_availability = $hasAvailableStock ? 'in-stock' : 'out-of-stock';
            $detail->manage_stock = 1;
            $detail->save();
        }
        if (is_wp_error($isUpdated)) {
            return $this->response->sendError([
                'message' => __('Inventory update failed', 'fluent-cart')
            ]);
        }

        // Stock persisted — fire StockChanged only if it actually changed.
        if ($oldAvailable !== $newAvailable || $oldStockStatus !== $newStockStatus) {
            (new StockChanged([$postId]))->dispatch();
        }

        return $this->response->sendSuccess([
            'message' => __('Inventory updated successfully', 'fluent-cart')
        ]);
    }

    public function updateManageStock(Request $request, $postId)
    {
        $manageStock = sanitize_text_field($request->get('manage_stock'));

        // Turning inventory ON requires the Stock Management module to be active.
        // Turning it OFF stays allowed so a store that disables the module can
        // still clean up products that were left with manage_stock = 1.
        if ($manageStock == 1 && !ModuleSettings::isActive('stock_management')) {
            return $this->response->sendError([
                'message' => __('Stock Management module is disabled. Enable it from Settings to manage inventory.', 'fluent-cart')
            ], 422);
        }

        $detail = ProductDetail::query()->where('post_id', $postId)->first();

        $updateData = [
            'manage_stock' => $manageStock,
        ];
        if ($manageStock == 0) {
            $updateData['stock_status'] = 'in-stock';
        }

        $updatedVariations = ProductVariation::query()->where('post_id', $postId)->update($updateData);

        $hasAvailableStock = ProductVariation::query()->where('post_id', $postId)->where('available', '>', 0)->exists();
        $detail->manage_stock = $manageStock;
        $detail->stock_availability = $hasAvailableStock || $manageStock == 0 ? 'in-stock' : 'out-of-stock';
        $updatedProductDetails = $detail->save();

        if (is_wp_error($updatedProductDetails)) {
            return $this->response->sendError([
                'message' => __('Manage stock update failed', 'fluent-cart')
            ]);
        }

        if (is_wp_error($updatedVariations)) {
            return $this->response->sendError([
                'message' => __('Manage stock update failed', 'fluent-cart')
            ]);
        }

        return $this->response->sendSuccess([
            'message' => __('Manage stock updated successfully', 'fluent-cart')
        ]);
    }

    /**
     * Suggest a unique SKU based on product title and optional variant title.
     *
     * @param Request $request
     * @return WP_REST_Response
     */
    public function suggestSku(Request $request)
    {
        $title = sanitize_text_field($request->get('title', ''));
        $variantTitle = sanitize_text_field($request->get('variant_title', ''));
        $excludeId = absint($request->get('exclude_id', 0));

        if (empty($title)) {
            return $this->sendError([
                'message' => __('Product title is required to generate SKU.', 'fluent-cart')
            ]);
        }

        $sku = $this->generateSkuFromTitle($title, $variantTitle);

        if (empty($sku)) {
            return $this->sendError([
                'message' => __('Could not generate SKU from the given title.', 'fluent-cart')
            ]);
        }

        $sku = $this->ensureUniqueSku($sku, $excludeId);

        return $this->sendSuccess([
            'sku' => $sku,
        ]);
    }

    /**
     * Generate a SKU string from a product title and optional variant title.
     *
     * @param string $title
     * @param string $variantTitle
     * @return string
     */
    private function generateSkuFromTitle($title, $variantTitle = '')
    {
        $stopWords = ['the', 'and', 'for', 'with', 'a', 'an', 'of', 'in', 'on', 'to', 'is', 'it', 'by', 'or', 'at', 'from'];

        $fullTitle = trim($title);
        if (!empty($variantTitle) && strtolower(trim($variantTitle)) !== strtolower(trim($title))) {
            $fullTitle .= ' ' . trim($variantTitle);
        }

        $cleaned = strtoupper($fullTitle);
        $cleaned = preg_replace('/[^A-Z0-9\s]/', '', $cleaned);
        $words = array_values(array_filter(explode(' ', $cleaned), function ($word) use ($stopWords) {
            return strlen($word) > 0 && !in_array(strtolower($word), $stopWords);
        }));

        if (empty($words)) {
            return '';
        }

        $parts = array_map(function ($word) {
            return substr($word, 0, 3);
        }, $words);

        $base = implode('-', $parts);

        // Keep base within 25 chars to leave room for uniqueness suffix
        if (strlen($base) > 25) {
            $base = substr($base, 0, 25);
            $base = rtrim($base, '-');
        }

        return $base;
    }

    /**
     * Ensure the SKU is unique in the database, appending a numeric suffix if needed.
     *
     * @param string $sku
     * @param int|null $excludeId
     * @return string
     */
    private function ensureUniqueSku($sku, $excludeId = null)
    {
        $original = $sku;
        $suffix = 0;
        $batchSize = 10;

        while ($suffix < 100) {
            // Build a batch of candidate SKUs to check at once
            $candidates = [];
            for ($i = $suffix; $i < $suffix + $batchSize && $i < 100; $i++) {
                if ($i === 0) {
                    $candidates[] = $original;
                } else {
                    $maxBaseLen = 30 - strlen('-' . $i);
                    $candidates[] = substr($original, 0, $maxBaseLen) . '-' . $i;
                }
            }

            $query = ProductVariation::query()->whereIn('sku', $candidates);
            if ($excludeId) {
                $query->where('id', '!=', $excludeId);
            }
            $taken = $query->get()->pluck('sku')->toArray();

            // Return the first candidate that isn't taken
            foreach ($candidates as $candidate) {
                if (!in_array($candidate, $taken)) {
                    return $candidate;
                }
            }

            $suffix += $batchSize;
        }

        // All candidates exhausted — fallback to timestamp-based suffix
        $maxBaseLen = 30 - 1 - 10; // hyphen + 10-digit timestamp
        return substr($original, 0, $maxBaseLen) . '-' . substr(time(), -10);
    }

    /**
     * Fetch products formatted for bulk editing.
     */
    public function bulkEditFetch(Request $request): WP_REST_Response
    {
        try {
            $service = new BulkProductUpdateService();
            $result = $service->fetchForBulkEdit($request);

            return $this->sendSuccess($result);
        } catch (\Throwable $e) {
            return $this->sendError([
                'message' => __('Failed to fetch products: ', 'fluent-cart') . $e->getMessage(),
            ]);
        }
    }

    /**
     * Bulk update products from the bulk edit spreadsheet.
     */
    public function bulkUpdate(Request $request): WP_REST_Response
    {
        $products = $request->get('products', []);

        if (empty($products) || !is_array($products)) {
            return $this->sendError([
                'message' => __('No products provided', 'fluent-cart'),
            ]);
        }

        if (count($products) > 10) {
            return $this->sendError([
                'message' => __('Maximum 10 products per chunk allowed', 'fluent-cart'),
            ]);
        }

        try {
            $service = new BulkProductUpdateService();
            $result = $service->updateChunk($products);

            if (empty($result['updated']) && !empty($result['errors'])) {
                return $this->sendError([
                    'message' => __('All products failed to update', 'fluent-cart'),
                    'errors'  => $result['errors'],
                ]);
            }

            return $this->sendSuccess([
                'message' => sprintf(
                    __('%d product(s) updated successfully', 'fluent-cart'),
                    count($result['updated'])
                ),
                'updated' => $result['updated'],
                'errors'  => $result['errors'],
            ]);
        } catch (\Throwable $e) {
            return $this->sendError([
                'message' => __('Bulk update failed: ', 'fluent-cart') . $e->getMessage(),
            ]);
        }
    }
}
