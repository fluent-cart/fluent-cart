<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\Resource\ShopResource;
use FluentCart\Api\Sanitizer\Sanitizer;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\App\Services\Renderer\ProductListRenderer;
use FluentCart\App\Services\Renderer\ProductModalRenderer;
use FluentCart\App\Services\Renderer\ProductRenderer;
use FluentCart\App\Vite;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Pagination\CursorPaginator;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;
use FluentCart\Api\Taxonomy;
use FluentCart\App\Services\Renderer\SearchBarRenderer;

class ShopController extends Controller
{
    public function getProducts(Request $request): array
    {
        $defaultFilters = $request->get('default_filters', []);
        $filters = $request->get('filters', []);

        // Merge sort_by from default_filters (shortcode) if not set by interactive filters
        $defaultSortBy = Arr::get($defaultFilters, 'sort_by', '');
        if ($defaultSortBy && empty(Arr::get($filters, 'sort_by'))) {
            if (!is_array($filters)) {
                $filters = [];
            }
            $filters['sort_by'] = sanitize_text_field($defaultSortBy);
        }

        $defaultTermIds = Helper::parseTermIdsForFilter($defaultFilters);
        $filterTermIds = Helper::parseTermIdsForFilter($filters);
        $mergedTermIds = Helper::mergeTermIdsForFilter($defaultTermIds, $filterTermIds);

        $status = ["post_status" => ["column" => "post_status", "operator" => "in", "value" => ["publish"]]];
        $allowOutOfStock = $request->get('allow_out_of_stock', false) == true;
        $cursor = $request->get('cursor', null);
        $orderType = $request->get('order_type', 'DESC');
        // The storefront never chooses its own eager loads. This route is public
        // (frontend_routes.php, PublicPolicy), and forwarding the request's `with`
        // let an anonymous caller walk arbitrary relation chains — e.g.
        // `?with[]=orderItems.order.customer.wpUser` reached the WordPress users
        // table. The two relations below are the ones the appends applied after
        // this call already touch (`thumbnail` reads `detail`, `has_subscription`
        // reads `variants`), so the response shape is unchanged and they are now
        // eager loaded instead of lazily fetched per row.
        $with = ['detail', 'variants'];

        // Shortcode filter params from AJAX
        $includeIds  = array_slice(array_values(array_filter(array_map('intval', (array) $request->get('include_ids', [])), function ($id) { return $id > 0; })), 0, 100);
        $excludeIds  = array_slice(array_values(array_filter(array_map('intval', (array) $request->get('exclude_ids', [])), function ($id) { return $id > 0; })), 0, 100);
        $productType = sanitize_text_field($request->get('product_type', ''));
        $onSale      = !empty($request->get('on_sale'));

        $params = [
            'cursor'             => $cursor,
            "order_type"         => $orderType,
            "select"             => '*',
            "with"               => $with,
            "selected_status"    => true,
            "status"             => $status,
            "default_filters"    => $defaultFilters,
            "filters"            => $filters,
            'taxonomy_filters'   => $mergedTermIds,
            'allow_out_of_stock' => $allowOutOfStock,
            "per_page"           => $request->getSafe('per_page', Sanitizer::SANITIZE_TEXT_FIELD) ?: 10,
            'paginate_using'     => $request->getSafe('paginate_using', Sanitizer::SANITIZE_TEXT_FIELD),
            'include_ids'        => $includeIds,
            'exclude_ids'        => $excludeIds,
            'product_type'       => $productType,
            'on_sale'            => $onSale,
        ];

        $products = ShopResource::get($params);

        $collection = $products['products']->getCollection();

        // The appended has_subscription accessor reads $product->variants during
        // serialization, so variants reach the response even without with[]=variants.
        // Eager-load them once for the whole page (single query) so the sensitive
        // fields can be hidden before serialization.
        $collection->loadMissing('variants');

        $products['products']->setCollection(
            $collection->transform(function ($product) {
                $product->setAppends(['view_url', 'has_subscription', 'thumbnail']);
                $product->makeHidden(['post_content']);
                if ($product->detail !== null) {
                    $product->detail->makeHidden(['item_cost', 'editing_stage', 'stock', 'manage_stock', 'manage_cost', 'settings']);
                }
                $product->variants->each(function ($variant) {
                    $variant->makeHidden(['item_cost', 'manage_cost', 'manage_stock', 'total_stock', 'available', 'committed', 'on_hold']);

                    // other_info is a JSON blob that makeHidden cannot reach into; strip its
                    // admin-only keys while keeping the storefront display fields (payment_type,
                    // repeat_interval, trial_days, billing_summary, weight/dimensions).
                    $info = $variant->other_info;
                    if (is_array($info)) {
                        foreach (['tax_class', 'tax_exempt', 'package_slug', 'bundle_child_ids', 'variation_type', 'is_bundle_product'] as $internalKey) {
                            unset($info[$internalKey]);
                        }
                        $variant->other_info = $info;
                    }
                });
                return $product;
            })
        );

        return [
            'products' => $products,
        ];

    }

    public function getProductViews(Request $request): array
    {
        // $products = $this->getProducts($request)['products'];
        // $products = $products->toArray();
        $page = Arr::get($request->all(), 'current_page', 1);
        $perPage = Arr::get($request->all(), 'per_page', 10);

        $products = $this->getProducts($request);
        $total = Arr::get($products, 'products.total', 0);
        $templateProvider = $request->get('template_provider', '');
        $clientId = $request->get('client_id', '');
        if ($templateProvider) {
            $preLoadedView = apply_filters('fluent_cart/products_views/preload_collection_' . $templateProvider, '', [
                'client_id'   => $clientId,
                'products'    => Arr::get($products, 'products.products', []),
                'total'       => $total,
                'requestData' => $request->all()
            ]);

            if ($preLoadedView) {

                $from = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
                $to = $total > 0 ? min($total, $page * $perPage) : 0;

                if ($from <= 0) {
                    $from = 1;
                }


                if ($to == 0) {
                    $to = 1;
                }

                if ($page == 0) {
                    $page = 1;
                }

                return [
                    'products' => [
                        'views'        => $preLoadedView,
                        'current_page' => $page,
                        'last_page'    => max((int)ceil($total / $request->get('per_page', 10)), 1),
                        'total'        => $total,
                        'per_page'     => $perPage,
                        'from'         => $from,
                        'to'           => $to,
                    ]
                ];

            }
        }

        $clientId = 'fct_product_loop_client_' . $clientId;

        $variable = null;

        if ($clientId) {
            $variable = get_transient($clientId);
            $variable = $variable['markup'] ?? null;
        }

        if ($variable) {
            $view = do_blocks($variable);

            $from = $total > 0 ? (($page - 1) * $perPage) + 1 : 0;
            $to = $total > 0 ? min($total, $page * $perPage) : 0;

            if ($from <= 0) {
                $from = 1;
            }


            if ($to == 0) {
                $to = 1;
            }

            if ($page == 0) {
                $page = 1;
            }

            return [
                'products' => [
                    'views'        => $view,
                    'current_page' => $page,
                    'last_page'    => max((int)ceil($total / $request->get('per_page', 10)), 1),
                    'total'        => $total,
                    'per_page'     => $perPage,
                    'from'         => $from,
                    'to'           => $to,
                ]
            ];

        }

        $products = $this->getProducts($request);

        $originalProducts = $products['products'];

        if ($originalProducts instanceof CursorPaginator) {
            $cursor = wp_parse_args(wp_parse_url($originalProducts->nextPageUrl(), PHP_URL_QUERY));
        }


        $total = $products['products']['total'];

        $perPage = $request->get('per_page', 10);
        $products['total'] = $total;
        $products['last_page'] = max((int)ceil($total / $perPage), 1);
        $hideExcerpt = filter_var($request->get('hide_excerpt', false), FILTER_VALIDATE_BOOLEAN);
        ob_start();
        if (($products['total'])) {
            (new ProductListRenderer(Arr::get($products, 'products.products'), null, null, ['hide_excerpt' => $hideExcerpt]))->renderProductList();
        } else {
            ProductRenderer::renderNoProductFound();
        }

        $view = ob_get_clean();

        $products['views'] = $view;
        $products['per_page'] = $perPage;
        $from = ($page - 1) * $perPage + 1;
        $to = min($total, $page * $perPage);

        if ($from <= 0) {
            $from = 1;
        }

        $products['from'] = $from;

        if ($to == 0) {
            $to = 1;
        }

        if ($page == 0) {
            $page = 1;
        }

        $products['to'] = $to;
        $products['page'] = $page;
        $products['current_page'] = $page;

        unset($products['data']);
        unset($products['products']);

        return [
            'products' => $products
        ];
    }

    public function getTermIdsFromDefaultFilter($defaultFilters): array
    {
        $ids = [];
        $taxonomies = Taxonomy::getTaxonomies();
        foreach ($taxonomies as $key => $taxonomy) {
            $defaultTerms = array_filter(explode(',', Arr::get($defaultFilters, $key, '')));
            $ids = array_merge(
                $ids,
                $defaultTerms
            );
        }

        return Collection::make($ids)->map(function ($termId) {
            return sanitize_text_field((string)$termId);
        })->toArray();
    }

    public function getTermIdsFromFilter($filters): array
    {
        $taxonomies = Taxonomy::getTaxonomies();
        if (is_string($filters)) {
            $filters = json_decode($filters, true);
        }
        $formattedFilters = [];

        foreach ($taxonomies as $key => $taxonomy) {
            $terms = Arr::get($filters, $key, []);
            if (!is_array($terms)) {
                $terms = [$terms];
            }

            if (!empty($terms)) {
                $terms = array_map(function ($term) {
                    return sanitize_text_field((string)$term);
                }, $terms);

                $formattedFilters[$key] = $terms;
            }


        }

        return $formattedFilters;
    }

    public function searchProduct(Request $request)
    {

        $searchValue = $request->getSafe('post_title', 'sanitize_text_field');
        $urlMode = $request->getSafe('url_mode', 'sanitize_text_field');
        $termId = $request->getSafe('termId', 'intval');
        $showThumbnail = filter_var($request->get('show_thumbnail', true), FILTER_VALIDATE_BOOLEAN);

        $defaultFilters =
            [
                "wildcard" => $searchValue,
            ];

        $status = ["post_status" => ["column" => "post_status", "operator" => "in", "value" => ["publish"]]];

        $params = [
            "select"          => ['ID', 'guid', 'post_title'],
            "with"            => ['wpTerms', 'detail.galleryImage'],
            "selected_status" => true,
            "status"          => $status,
            "default_filters" => $defaultFilters,
        ];

        if (!empty($termId)) {
            $params["taxonomy_filters"] = [
                'product-categories' => Arr::wrap($termId)
            ];
        }

        $results = ShopResource::get($params);
        $products = $results['products'];
        ob_start();

        (new SearchBarRenderer([
            'url_mode'       => $urlMode,
            'show_thumbnail' => $showThumbnail,
        ]))->renderResultItems($products);

        $view = ob_get_clean();
        return $this->response->sendSuccess([
            'htmlView' => $view
        ]);
    }
}
