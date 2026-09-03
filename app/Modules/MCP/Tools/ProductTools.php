<?php

namespace FluentCart\App\Modules\MCP\Tools;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Modules\MCP\Support\AdvancedSearch;
use FluentCart\App\Modules\MCP\Support\MCPHelper;
use FluentCart\App\Modules\MCP\Support\PermissionGate;

/**
 * Product & inventory tools.
 *
 * Note on money: product/variation prices are stored as cents (numeric), so the
 * shared money() helper formats them correctly — same as orders. We never mix a
 * raw price into prose.
 *
 * Parameter design:
 *  - list-products filters on what a merchant browses by (status, fulfillment,
 *    stock, price band, category). Price filters are in store currency.
 *  - get-product is lean by default (detail + variations); sales rollup and
 *    downloadable files are opt-in via include[].
 *  - get-inventory is the dedicated "what do I need to restock?" view — distinct
 *    from sales reporting. It only lists stock-managed variations at risk.
 */
class ProductTools
{
    public static function definitions()
    {
        return [
            'fluent-cart/list-products' => [
                'label'       => __('List Products', 'fluent-cart'),
                'description' => __('Find and filter products. Compact rows: title, status, price range, variation count, fulfillment, stock status. Use get-product for full detail and per-variation stock. Price filters are in store currency, not cents. For conditions these flat filters cannot express (OR groups, order/variation counts, available stock quantity, taxonomy terms) pass advanced_filters — call get-search-schema entity=products first (Pro).', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'search'           => ['type' => 'string', 'description' => 'Matches product title.'],
                        'status'           => ['type' => 'string', 'enum' => ['publish', 'draft', 'private', 'pending'], 'description' => 'WordPress post status.'],
                        'fulfillment_type' => ['type' => 'string', 'enum' => ['physical', 'digital']],
                        'variation_type'   => ['type' => 'string', 'enum' => ['simple', 'simple_variations', 'advanced_variations']],
                        'stock_status'     => ['type' => 'string', 'enum' => ['in-stock', 'out-of-stock']],
                        'category'         => ['type' => 'string', 'description' => 'Category term slug.'],
                        'min_price'        => ['type' => 'number', 'description' => 'Minimum price in store currency.'],
                        'max_price'        => ['type' => 'number', 'description' => 'Maximum price in store currency.'],
                        'advanced_filters' => ['type' => 'array', 'items' => ['type' => ['object', 'array']], 'description' => 'Pro: condition groups {property, operator, value} — outer array = OR groups, inner = AND. Call get-search-schema entity=products FIRST for properties/operators/format. AND-combines with the other filters here. An empty array means no advanced filter.'],
                        'sort_by'          => ['type' => 'string', 'enum' => ['id', 'title', 'date'], 'default' => 'date'],
                        'sort_type'        => ['type' => 'string', 'enum' => ['ASC', 'DESC'], 'default' => 'DESC'],
                        'page'             => ['type' => 'integer', 'default' => 1],
                        'per_page'         => ['type' => 'integer', 'default' => 15, 'description' => 'Max 100.'],
                    ],
                ],
                'execute_callback'    => [self::class, 'listProducts'],
                'permission_callback' => function () {
                    return PermissionGate::can('products/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/get-product' => [
                'label'       => __('Get Product', 'fluent-cart'),
                'description' => __('Full detail for one product: description, variations with SKU/price/stock/subscription terms, categories and tags. Add include[] for sales (lifetime units + revenue) and downloads. Identify by product_id.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'integer'],
                        'include'    => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string', 'enum' => ['sales', 'downloads']],
                            'description' => 'Optional sections. detail + variations + taxonomy are always returned.',
                        ],
                    ],
                    'required' => ['product_id'],
                ],
                'execute_callback'    => [self::class, 'getProduct'],
                'permission_callback' => function () {
                    return PermissionGate::can('products/view');
                },
                'annotations' => ['readonly' => true],
            ],

            'fluent-cart/get-inventory' => [
                'label'       => __('Get Inventory Status', 'fluent-cart'),
                'description' => __('Stock-managed variations that need attention: out-of-stock, or at/below a threshold. Answers "what do I need to restock?" — this is inventory health, not sales. Shows available vs committed vs on-hold.', 'fluent-cart'),
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'threshold'        => ['type' => 'integer', 'default' => 5, 'description' => 'Flag variations with available stock at or below this.'],
                        'only_out_of_stock' => ['type' => 'boolean', 'default' => false],
                        'page'             => ['type' => 'integer', 'default' => 1],
                        'per_page'         => ['type' => 'integer', 'default' => 25, 'description' => 'Max 100.'],
                    ],
                ],
                'execute_callback'    => [self::class, 'getInventory'],
                'permission_callback' => function () {
                    return PermissionGate::can('products/view');
                },
                'annotations' => ['readonly' => true],
            ],
        ];
    }

    public static function listProducts($params = [])
    {
        $paging = MCPHelper::pagination($params);

        // advanced_filters routes through the admin filter engine (validated
        // first — a bad condition errors, never silently drops); the named
        // filters below then AND onto the same query either way.
        $advWarnings = [];
        if (!empty($params['advanced_filters'])) {
            $built = AdvancedSearch::buildQuery('products', $params['advanced_filters']);
            if (is_wp_error($built)) {
                return $built;
            }
            $query       = $built['query'];
            $advWarnings = $built['warnings'];
        } else {
            // Product model adds a global scope pinning post_type to the canonical
            // CPT (fluent-products) — don't re-add it here, a wrong literal would
            // AND against the scope and match nothing.
            $query = Product::query();
        }
        $query->with('detail');

        if (!empty($params['search'])) {
            $query->where('post_title', 'LIKE', '%' . sanitize_text_field($params['search']) . '%');
        }
        if (!empty($params['status'])) {
            $query->where('post_status', sanitize_text_field($params['status']));
        } else {
            $query->whereIn('post_status', ['publish', 'draft', 'private', 'pending']);
        }

        // Detail-scoped filters (fulfillment, variation type, stock, price band).
        $detailFilters = [
            'fulfillment_type' => isset($params['fulfillment_type']) ? sanitize_text_field($params['fulfillment_type']) : null,
            'variation_type'   => isset($params['variation_type']) ? sanitize_text_field($params['variation_type']) : null,
            'stock_status'     => isset($params['stock_status']) ? sanitize_text_field($params['stock_status']) : null,
            'min_price'        => isset($params['min_price']) ? Helper::toCent($params['min_price']) : null,
            'max_price'        => isset($params['max_price']) ? Helper::toCent($params['max_price']) : null,
        ];
        if (array_filter($detailFilters, function ($v) { return $v !== null; })) {
            $query->whereHas('detail', function ($q) use ($detailFilters) {
                if ($detailFilters['fulfillment_type'] !== null) {
                    $q->where('fulfillment_type', $detailFilters['fulfillment_type']);
                }
                if ($detailFilters['variation_type'] !== null) {
                    $q->where('variation_type', $detailFilters['variation_type']);
                }
                if ($detailFilters['stock_status'] !== null) {
                    $q->where('stock_availability', $detailFilters['stock_status']);
                }
                if ($detailFilters['min_price'] !== null) {
                    $q->where('min_price', '>=', $detailFilters['min_price']);
                }
                if ($detailFilters['max_price'] !== null) {
                    $q->where('max_price', '<=', $detailFilters['max_price']);
                }
            });
        }

        if (!empty($params['category'])) {
            $cat = sanitize_text_field($params['category']);
            $query->whereHas('categories', function ($q) use ($cat) {
                $q->where('slug', $cat);
            });
        }

        $sortMap  = ['id' => 'ID', 'title' => 'post_title', 'date' => 'post_date'];
        $sortBy   = isset($params['sort_by']) && isset($sortMap[$params['sort_by']]) ? $sortMap[$params['sort_by']] : 'post_date';
        $sortType = strtoupper(isset($params['sort_type']) ? $params['sort_type'] : 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $query->orderBy($sortBy, $sortType);
        if ($sortBy !== 'ID') {
            $query->orderBy('ID', 'DESC');
        }

        $paginator = $query->paginate($paging['per_page'], ['*'], 'page', $paging['page']);
        $total     = self::total($paginator);

        $rows = [];
        foreach (MCPHelper::paginatorItems($paginator) as $product) {
            $rows[] = self::formatRow($product);
        }

        $meta = MCPHelper::pagingMeta($paginator);
        if ($advWarnings) {
            $meta['warnings'] = $advWarnings;
        }

        return MCPHelper::envelope(
            sprintf(
                /* translators: %d: number of matching products */
                _n('%d product found.', '%d products found.', $total, 'fluent-cart'),
                $total
            ),
            ['products' => $rows],
            $meta
        );
    }

    private static function formatRow($product)
    {
        $detail = ($product->relationLoaded('detail') && $product->detail) ? $product->detail : null;

        return [
            'product_id'       => (int) $product->ID,
            'title'            => $product->post_title,
            'status'           => $product->post_status,
            'price_range'      => self::priceRange($detail),
            'fulfillment_type' => $detail ? $detail->fulfillment_type : null,
            'variation_type'   => $detail ? $detail->variation_type : null,
            'stock_status'     => $detail ? $detail->stock_availability : null,
        ];
    }

    private static function priceRange($detail)
    {
        if (!$detail) {
            return null;
        }
        $min = (int) $detail->min_price;
        $max = (int) $detail->max_price;
        if ($min === $max) {
            return ['from' => MCPHelper::moneyCompact($min), 'to' => MCPHelper::moneyCompact($max), 'single' => true];
        }
        return ['from' => MCPHelper::moneyCompact($min), 'to' => MCPHelper::moneyCompact($max), 'single' => false];
    }

    public static function getProduct($params = [])
    {
        if (empty($params['product_id'])) {
            return MCPHelper::error('missing_identifier', __('product_id is required.', 'fluent-cart'));
        }

        // post_type is pinned by the model's global scope; filtering by ID only.
        $product = Product::query()
            ->where('ID', (int) $params['product_id'])
            ->with(['detail', 'variants'])
            ->first();

        if (!$product) {
            return MCPHelper::error('product_not_found', __('No product found for the given product_id.', 'fluent-cart'));
        }

        $include = isset($params['include']) ? (array) $params['include'] : [];
        $detail  = $product->detail;

        $data = [
            'product_id'       => (int) $product->ID,
            'title'            => $product->post_title,
            'status'           => $product->post_status,
            // Cap the detail description so a very long product body can't blow
            // the agent's context window (generous vs the 150-char list preview).
            'description'      => MCPHelper::preview($product->post_content, 2000),
            'fulfillment_type' => $detail ? $detail->fulfillment_type : null,
            'variation_type'   => $detail ? $detail->variation_type : null,
            'stock_status'     => $detail ? $detail->stock_availability : null,
            'price_range'      => self::priceRange($detail),
            'variations'       => self::variations($product),
            'categories'       => self::terms($product),
            'created_at'       => MCPHelper::toIso8601($product->post_date_gmt ? $product->post_date_gmt : $product->post_date),
        ];

        if (in_array('sales', $include, true)) {
            $data['sales'] = self::salesRollup((int) $product->ID);
        }
        if (in_array('downloads', $include, true)) {
            $data['downloads'] = self::downloads($product);
        }

        return MCPHelper::envelope(
            sprintf(
                /* translators: 1: product title, 2: product status */
                __('Product "%1$s" — %2$s', 'fluent-cart'),
                $product->post_title,
                $product->post_status
            ),
            $data
        );
    }

    private static function variations($product)
    {
        if (!$product->relationLoaded('variants')) {
            $product->load('variants');
        }
        $out = [];
        foreach ($product->variants as $v) {
            $out[] = [
                'variation_id'   => (int) $v->id,
                'title'          => $v->variation_title,
                'sku'            => $v->sku,
                'price'          => MCPHelper::money($v->item_price),
                'payment_type'   => $v->payment_type,
                'stock_status'   => $v->stock_status,
                'manage_stock'   => (bool) $v->manage_stock,
                'stock'          => [
                    'total'     => (int) $v->total_stock,
                    'available' => (int) $v->available,
                    'committed' => (int) $v->committed,
                    'on_hold'   => (int) $v->on_hold,
                ],
            ];
        }
        return $out;
    }

    private static function terms($product)
    {
        try {
            $terms = $product->getCategories();
        } catch (\Throwable $e) {
            return [];
        }
        $out = [];
        foreach ((array) $terms as $term) {
            $obj  = is_object($term) ? get_object_vars($term) : (is_array($term) ? $term : []);
            $id   = isset($obj['term_id']) ? (int) $obj['term_id'] : (isset($obj['id']) ? (int) $obj['id'] : null);
            $name = isset($obj['name']) ? $obj['name'] : null;
            $slug = isset($obj['slug']) ? $obj['slug'] : null;
            // Skip phantom/empty entries rather than emitting all-null objects.
            if (!$id && ($name === null || $name === '')) {
                continue;
            }
            $out[] = ['id' => $id, 'name' => $name, 'slug' => $slug];
        }
        return $out;
    }

    /**
     * Lifetime sales for this product, from order items on realized-revenue
     * orders only. Revenue is net of item-level refunds (line_total - refund_total).
     * Scoped to paid / partially_refunded orders — unpaid/canceled items don't
     * count, and partially_paid is excluded (partial payments are not implemented).
     */
    private static function salesRollup($productId)
    {
        $paidScope = function ($q) {
            $q->whereIn('payment_status', ['paid', 'partially_refunded']);
        };

        $row = OrderItem::query()
            ->where('post_id', $productId)
            ->whereHas('order', $paidScope)
            ->selectRaw(
                'COALESCE(SUM(quantity), 0) as units, '
                . 'COALESCE(SUM(line_total - refund_total), 0) as revenue, '
                . 'COUNT(DISTINCT order_id) as orders'
            )
            ->first();

        return [
            'units_sold'  => $row ? (int) $row->units : 0,
            'revenue'     => MCPHelper::money($row ? (int) $row->revenue : 0),
            'order_count' => $row ? (int) $row->orders : 0,
        ];
    }

    private static function downloads($product)
    {
        $product->load('downloadable_files');
        $out = [];
        if (!$product->relationLoaded('downloadable_files')) {
            return $out;
        }
        foreach ($product->downloadable_files as $file) {
            $out[] = [
                'id'    => (int) $file->id,
                'title' => $file->title,
                'size'  => $file->file_size ? (int) $file->file_size : null,
            ];
        }
        return $out;
    }

    public static function getInventory($params = [])
    {
        $paging    = MCPHelper::pagination($params, 25);
        $threshold = isset($params['threshold']) ? max((int) $params['threshold'], 0) : 5;
        $onlyOut   = !empty($params['only_out_of_stock']);

        $query = ProductVariation::query()->where('manage_stock', 1);

        if ($onlyOut) {
            $query->where('stock_status', 'out-of-stock');
        } else {
            $query->where(function ($q) use ($threshold) {
                $q->where('available', '<=', $threshold)->orWhere('stock_status', 'out-of-stock');
            });
        }

        $query->orderBy('available', 'ASC')->orderBy('id', 'DESC');

        $paginator = $query->paginate($paging['per_page'], ['*'], 'page', $paging['page']);
        $total     = self::total($paginator);

        $rows = [];
        foreach (MCPHelper::paginatorItems($paginator) as $v) {
            $rows[] = [
                'variation_id' => (int) $v->id,
                'product_id'   => (int) $v->post_id,
                'title'        => $v->variation_title,
                'sku'          => $v->sku,
                'stock_status' => $v->stock_status,
                'available'    => (int) $v->available,
                'committed'    => (int) $v->committed,
                'on_hold'      => (int) $v->on_hold,
                'total'        => (int) $v->total_stock,
            ];
        }

        return MCPHelper::envelope(
            sprintf(
                /* translators: %d: number of variations needing attention */
                _n('%d variation needs attention.', '%d variations need attention.', $total, 'fluent-cart'),
                $total
            ),
            ['variations' => $rows],
            MCPHelper::pagingMeta($paginator)
        );
    }

    private static function total($paginator)
    {
        return MCPHelper::paginatorTotal($paginator);
    }
}
