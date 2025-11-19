<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\Resource\ProductDetailResource;
use FluentCart\Api\Resource\ProductResource;
use FluentCart\Api\Resource\ProductVariationResource;
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
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\TaxClass;
use FluentCart\App\Modules\ReportingModule\ProductReport;
use FluentCart\App\Services\Async\DummyProductService;
use FluentCart\App\Services\Filter\ProductFilter;
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

        $products->setCollection(
            $products->getCollection()->transform(function ($product) {
                return $product->setAppends(['view_url', 'edit_url']);
            })
        );

        $products = apply_filters('fluent_cart/products_list', $products);

        return $this->sendSuccess([
            'products' => $products
        ]);
    }

    public function find(Request $request, Product $product): array
    {
        if ($request->get('with')) {
            $product->load($request->get('with'));
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
                'times'              => '',
                'repeat_interval'    => '',
                'trial_days'         => '',
                'billing_summary'    => '',
                'manage_setup_fee'   => 'no',
                'signup_fee_name'    => '',
                'signup_fee'         => '',
                'setup_fee_per_item' => 'no',
            ]
        ]);

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
            'message' => __('Tax Class updated successfully', 'fluent-cart')
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
            'message' => __('Tax Class removed successfully', 'fluent-cart')
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
        $product = Product::with([
            'detail',
            'variants' => function ($query) {
                $query->with(['media'])
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
            //get featured image id
            return $this->sendSuccess([
                'product'      => $productData,
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

    /**
     * Set thumbnail from a product gallery
     *
     * @param Request $request
     * @param $variantId
     * @return mixed
     */
    public function setProductImage(Request $request, $variantId)
    {


        $isSetThumbnail = ProductResource::setThumbnail($variantId, $request->all());

        if (is_wp_error($isSetThumbnail)) {
            return $isSetThumbnail;
        }
        return $this->response->sendSuccess($isSetThumbnail);
    }

    public function updateProductDetail(Request $request, $id)
    {

        $isUpdated = ProductDetailResource::update($request->all(), $id, ['triggerable_action' => 'specific_column']);

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
        //        ProductValidator::validate($data, [
//            'variation_type' => 'required',
//            'product_id' => 'required',
//            'options.*.id' => 'required',
//            'options.*.variants' => 'required',
//        ]);
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

        if (count($ids)) {
            $this->response->json([
                'term_ids' => $ids,
                'names'    => $termNames
            ]);
        } else {
            $this->response->json([
                'message' => __('Unable To Create Term/s', 'fluent-cart'),
            ], 423);
        }

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
        $query = [];
        if (!empty($name) || count($ids) > 0) {
            $query = [
                "ID"          =>
                    [
                        "column"   => "ID",
                        "operator" => "in",
                        "value"    => Arr::get($data, 'ids', [])
                    ]
                ,
                "post_title"  =>
                    [
                        "column"   => "post_title",
                        "operator" => "like",
                        "value"    => '%' . Arr::get($data, 'name') . '%'
                    ],
                "post_status" =>
                    [
                        "column"   => "post_status",
                        "operator" => "=",
                        "value"    => 'publish'
                    ]
            ];
        }

        $products = Product::query()
            ->with('variants')
            ->when(count($query), function (Builder $q) use ($query) {
                return $q->search($query, function (Builder $query) {
                    return $query;
                }, true);
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
            'include_ids.*' => 'intval',
            'search'        => 'sanitize_text_field',
        ]);

        $search = Arr::get($data, 'search', '');
        $includeIds = Arr::get($data, 'include_ids', []);

        $productsQuery = Product::query()
            ->with(['variants']);

        if ($search) {
            $productsQuery->where('post_title', 'like', '%' . $search . '%');
        }

        $productsQuery->limit(20);

        $products = $productsQuery->get();

        $pushedVariationIds = [];
        $formattedProducts = [];

        foreach ($products as $product) {
            $formatted = [
                'value' => 'product_' . $product->ID,
                'label' => $product->post_title,
            ];

            $variants = $product->variants;

            $children = [];
            foreach ($variants as $variant) {
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
                ->with('product')
                ->get();

            foreach ($leftVariants as $variant) {
                $product = $variant->product;
                if (!$product) {
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

    public function fetchProductsByIds(Request $request): array
    {
        $ids = $request->getSafe(['productIds.*' => 'intval']);

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
}
