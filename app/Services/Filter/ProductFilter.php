<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\Api\Taxonomy;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class ProductFilter extends BaseFilter
{
    public string $defaultSortBy = "ID";

    public function applySimpleFilter(?string $search = null): void
    {
        $isApplied = $this->applySimpleOperatorFilter($search);
        if ($isApplied) {
            return;
        }

        $this->query = $this->query->when($search ?? $this->search, function ($query, $search) {
            return $query
                ->where(function ($query) use ($search) {
                    $query->search([
                        'post_title' => [
                            'column'   => 'post_title',
                            'operator' => 'like_all',
                            'value'    => $search
                        ],
                        'ID'         => [
                            'column'   => 'ID',
                            'operator' => 'or_like_all',
                            'value'    => $search
                        ]
                    ])->orWhereHas('variants', function ($query) use ($search) {
                        $query->where('variation_title', 'like', '%' . $search . '%');
                    });
                });
        });
    }

    public function tabsMap(): array
    {
        return [
            'publish'          => 'post_status',
            'draft'            => 'post_status',
            //'simple'            => 'variation_type',
            //'simple_variations' => 'variation_type',
            'physical'         => 'fulfillment_type',
            'digital'          => 'fulfillment_type',
            'subscribable'     => 'has_subscription',
            'not_subscribable' => 'has_subscription',
            'bundle'           => 'bundle',
            'non_bundle'       => 'nonBundle',
        ];
    }

    public function getModel(): string
    {
        return Product::class;
    }

    public static function getFilterName(): string
    {
        return 'products';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function sortableColumns(): array
    {
        return [
            'ID'         => ['label' => __('Product ID', 'fluent-cart'), 'column' => 'ID'],
            'post_title' => ['label' => __('Title', 'fluent-cart'), 'column' => 'post_title'],
            'post_date'  => ['label' => __('Created at', 'fluent-cart'), 'column' => 'post_date'],
        ];
    }

    /**
     * SCREEN keys — one per calling screen. Four genuinely different screens
     * read products and each renders a different DEPTH of the same two
     * relations, so each gets a key it can re-scope without widening the
     * payload for the other three. Every screen wants both `detail` and
     * `variants`, which is why there is one key per screen rather than one per
     * relation per screen — a second key would only ever be sent alongside the
     * first.
     *
     * PUBLIC keys — `detail` and `variants` are the two relations an external
     * consumer can reasonably ask a product endpoint for, and `GET /products`
     * is a public REST route where they were accepted values already. They give
     * the plain, unnested version; the screens' deeper subtrees are properties
     * of those screens, not of the endpoint's published shape.
     *
     * `GET /products` requires `products/view`, and every callback re-states
     * that same bar rather than inheriting it — the map is also reachable from
     * `BulkProductUpdateService` and from any add-on that builds the filter
     * directly, where no route ran.
     *
     * Deliberately NOT allowlisted: `downloadable_files` (protected file
     * paths), `licensesMeta`, `integrations`, `postmeta` and `wpTerms` (raw
     * meta / taxonomy joins that leak far more than the catalogue),
     * `orderItems`, and every dotted path.
     *
     * `categories` used to be on this map and is gone: three clients requested
     * it and none of them read it — a three-join hasMany per row for a value
     * nothing renders.
     *
     * No entry declares a select, and that is deliberate. `Product::$appends`
     * carries `thumbnail`, which reads `detail->featured_media` — itself a
     * `ProductDetail` append backed by the `galleryImage` relation.
     * `ProductVariation::$appends` carries `thumbnail`, which lazy-loads `media`
     * keyed on the variant `id`, and `ProductVariation::booted()` appends
     * `formatted_total` from `item_price` to every retrieved row. Dropping `id`
     * blanks every picker thumbnail; dropping `post_id` returns an empty
     * relation outright.
     *
     * @return array<string, callable>
     */
    protected function allowedWiths(): array
    {
        return [
            'admin_product_list'      => [$this, 'adminProductList'],
            'block_picker'            => [$this, 'blockPicker'],
            'elementor_picker'        => [$this, 'elementorPicker'],
            'admin_order_add_product' => [$this, 'adminOrderAddProduct'],

            'detail'   => [$this, 'publicDetail'],
            'variants' => [$this, 'publicVariants'],
        ];
    }

    /**
     * `utils/table-new/ProductTable.js` — the admin product list. Renders the
     * detail thumbnail and the min/max price, and the stock column sums
     * `variants[].available` and reads `manage_stock` per row.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder|bool
     */
    protected function adminProductList($query)
    {
        if (!$this->userCanAny('products/view')) {
            return false;
        }

        return $query->with([
            'detail',
            'variants',
        ]);
    }

    /**
     * The Gutenberg product pickers — `BlockEditor/Components/SearchableSelect.jsx`
     * and `BlockEditor/Components/ProductPicker/{ProductDropdownPicker,
     * ProductSelector,VariationSelector}.jsx`. They render the product
     * thumbnail, which resolves through `detail->featured_media`, and they list
     * the variation rows with a per-variant thumbnail.
     *
     * `media` comes down in the SAME with() call as `variants` — a later entry
     * naming `variants.something` would otherwise inject an empty closure over
     * `variants` and drop whatever this one constrained.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder|bool
     */
    protected function blockPicker($query)
    {
        if (!$this->userCanAny('products/view')) {
            return false;
        }

        return $query->with([
            'detail',
            'variants',
            'variants.media',
        ]);
    }

    /**
     * `elementor/VariationSelector/SelectorModel.js` and
     * `elementor/PricingTable/App.vue`.
     *
     * `detail` is loaded in its own right, not as an ORM-injected parent
     * segment of a dotted path: without it
     * `elementor/VariationSelector/ListItem.vue` reads
     * `product.detail.stock_availability`, `min_price` and `max_price` as
     * undefined and the picker goes silently blank. ListItem.vue also iterates
     * `product.variants` and calls `model.getVariantThumbnail(variant)`, which
     * reads `variant.thumbnail` — the append backed by `media`, loaded in one
     * call for the clobber reason above.
     *
     * A separate key from `block_picker` even though the subtree matches today:
     * either picker must be re-scopable without moving the other.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder|bool
     */
    protected function elementorPicker($query)
    {
        if (!$this->userCanAny('products/view')) {
            return false;
        }

        return $query->with([
            'detail',
            'variants',
            'variants.media',
        ]);
    }

    /**
     * `Modules/Orders/Modals/AddProductItemModal.vue` — picking a product to add
     * to an existing order. The whole subtree is loaded in ONE with() call so no
     * segment can be clobbered by an empty closure from another entry.
     *
     * `bundleChildren` is load-bearing, not decorative: `Bits/productService.js`
     * assigns `bundle_items = variant.bundle_children`, and the modal calls
     * `.length` on it with no optional chaining. Dropping it is a TypeError on
     * every bundle product, not a blank cell. That regression has already
     * shipped once.
     *
     * This is also the only key that loads `detail.variants`, which is what
     * `AttributeHelper::attachVariationDisplayTitles()` walks in
     * `ProductController::index()` to resolve `variation_display_title`.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder|bool
     */
    protected function adminOrderAddProduct($query)
    {
        if (!$this->userCanAny('products/view')) {
            return false;
        }

        return $query->with([
            'detail',
            'detail.variants',
            'detail.variants.media',
            'detail.variants.bundleChildren',
        ]);
    }

    /**
     * `with[]=detail` — a public entry point. The catalogue detail row, gated
     * on the same `products/view` bar as every screen key: a public name is not
     * a way around the check.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder|bool
     */
    protected function publicDetail($query)
    {
        if (!$this->userCanAny('products/view')) {
            return false;
        }

        return $query->with(['detail']);
    }

    /**
     * `with[]=variants` — a public entry point. The variation rows, carrying
     * the same `products/view` bar.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder|bool
     */
    protected function publicVariants($query)
    {
        if (!$this->userCanAny('products/view')) {
            return false;
        }

        return $query->with(['variants']);
    }

    /**
     * `cartable` is sent by the Gutenberg and Elementor product pickers to hide
     * subscription/licensed products from a one-off add-to-cart widget.
     *
     * @return array<string, callable>
     */
    protected function allowedScopes(): array
    {
        return [
            'cartable' => [$this, 'applyCartableScope'],
        ];
    }

    /**
     * Declared `($query)` on purpose: the array request form `scopes[]=['cartable',
     * 'anything']` would deliver that tail as `$args`, and a one-parameter
     * callback structurally cannot see it.
     *
     * No permission bar here, unlike the `with` entries. `cartable` NARROWS the
     * result set, so refusing it would return MORE rows, not fewer — a
     * permission check on a narrowing scope is a widening bug. Access to the
     * list itself is already gated by the route and by the `with` callbacks.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    protected function applyCartableScope($query)
    {
        return $query->scopes(['cartable']);
    }


//    public static function parseableKeys(): array
//    {
//        return array_merge(
//            parent::parseableKeys(),
//            ['payment_statuses', 'order_statuses', 'shipping_statuses']
//        );
//    }
    public function applyActiveViewFilter(?string $activeView = null): void
    {
        $activeView = $activeView ?? $this->activeView;
        $tabsMap = $this->tabsMap();

        $this->query->when($activeView, function ($query, $activeView) use ($tabsMap) {
            return $query->where(function (Builder $q) use ($activeView, $tabsMap) {
                $column = Arr::get($tabsMap, $activeView);

                if ($activeView === 'draft') {
                    $q->whereIn('post_status', ['draft', 'future']);
                } else if ($column === 'post_status') {
                    $q->where($column, $activeView);
                } else if ($activeView === 'subscribable') {
                    $q->whereHas('variants', function ($detailQuery) {
                        $detailQuery->where('payment_type', 'subscription');
                    });
                } else if ($activeView === 'not_subscribable') {
                    $q->whereDoesntHave('variants', function ($detailQuery) {
                        $detailQuery->where('payment_type', 'subscription');
                    });
                } else if (in_array($activeView, ['bundle', 'non_bundle'])) {
                    $q->{$column}();
                } else if (!empty($column)) {
                    $q->whereHas('detail', function ($detailQuery) use ($column, $activeView) {
                        $detailQuery->where($column, $activeView);
                    });
                }
            });
        });
    }

    public static function getSearchableFields(): array
    {
        return [
            'id' => [
                'column'      => 'ID',
                'description' => 'Product ID',
                'type'        => 'numeric',
                'examples'    => [
                    'id = 1',
                    'id > 5',
                    'id :: 1-10'
                ]
            ],

            'sku' => [
                'column'      => 'SKU',
                'description' => 'Search By SKU',
                'note'        => "Supports '=' and '!=' operators with optional * wildcard matching",
                'type'        => 'custom',
                'examples'    => [
                    'sku = 1',
                    'sku != 1',
                    'sku = starter*',
                    'sku = *pro*',
                ],
                'callback'    => static function (Builder $query, $value, $operator, BaseFilter $filter) {
                    if ($filter->shouldApplyMatchFilter($operator)) {
                        $query->whereHas('variants', function (Builder $query) use ($value, $filter, $operator) {
                            $filter->applyMatchFilter($query, 'sku', $value, $operator);
                        });
                    }
                }
            ],

            'description' => [
                'column'      => 'post_content',
                'description' => 'Search By Description',
                'note'        => "Supports '=' and '!=' operators with optional * wildcard matching",
                'type'        => 'custom',
                'examples'    => [
                    'description = *course*',
                    'description = starter*',
                    'description != *bundle*',
                ],
                'callback'    => static function (Builder $query, $value, $operator, BaseFilter $filter) {
                    if ($filter->shouldApplyMatchFilter($operator)) {
                        $filter->applyMatchFilter($query, 'post_content', $value, $operator);
                    }
                }
            ]
        ];
    }

    public static function advanceFilterOptions(): array
    {

        $taxonomyFilters = [];
        foreach (Taxonomy::taxonomyWithTerms() as $key => $taxonomy) {

            $taxonomyFilters[] =
                [
                    'label'          => $taxonomy['label'],
                    'value'          => $key,
                    'filter_type'    => 'relation',
                    'relation'       => 'wpTerms',
                    'column'         => 'term_id',
                    'type'           => 'remote_tree_select',
                    'check_strictly' => true,
                    'options'        => static::makeNestedTreeOption($taxonomy['terms']),
                    'is_multiple'    => true,
                ];
        }

        return [
            'pricing' => [
                'label'    => __('Pricing', 'fluent-cart'),
                'value'    => 'pricing',
                'children' => [
                    [
                        'filter_type' => 'relation',
                        'relation'    => 'detail',
                        'column'      => 'min_price',
                        'label'       => __('Min Price', 'fluent-cart'),
                        'value'       => 'min_price',
                        'type'        => 'numeric',
                        'is_multiple' => false,
                    ],
                    [
                        'filter_type' => 'relation',
                        'relation'    => 'detail',
                        'column'      => 'max_price',
                        'label'       => __('Max Price', 'fluent-cart'),
                        'value'       => 'max_price',
                        'type'        => 'numeric',
                        'is_multiple' => false,
                    ],
                ],
            ],
            'stock' => [
                'label'    => __('Stock', 'fluent-cart'),
                'value'    => 'stock',
                'children' => [
                    [
                        'filter_type' => 'custom',
                        'label'       => __('Stock Status', 'fluent-cart'),
                        'value'       => 'stock_status',
                        'type'        => 'selections',
                        'options'     => [
                            'in_stock'     => __('In Stock', 'fluent-cart'),
                            'out_of_stock' => __('Out of Stock', 'fluent-cart'),
                        ],
                        'is_multiple' => false,
                        'is_only_in'  => true,
                        'callback'    => static function ($query, $item) {
                            $operator = $item['value'] === 'in_stock' ? '>' : '<=';
                            self::filterByTotalAvailable($query, $operator, 0);
                        },
                    ],
                    [
                        'filter_type' => 'custom',
                        'label'       => __('Available Quantity', 'fluent-cart'),
                        'value'       => 'available_quantity',
                        'type'        => 'numeric',
                        'is_multiple' => false,
                        'callback'    => static function ($query, $item) {
                            $operator = $item['operator'];
                            if (!in_array($operator, ['>', '<', '=', '!=', '>=', '<='])) {
                                return;
                            }
                            self::filterByTotalAvailable($query, $operator, absint($item['value']));
                        },
                    ],
                ],
            ],
            'order' => [
                'label'    => __('Order Property', 'fluent-cart'),
                'value'    => 'order',
                'children' => [
                    [
                        'filter_type' => 'relation',
                        'relation'    => 'orderItems',
                        'label'       => __('Order Count', 'fluent-cart'),
                        'value'       => 'has',
                        'type'        => 'numeric',
                        'is_multiple' => false,
                    ]
                ],
            ],

            'variations' => [
                'label'    => __('Variations', 'fluent-cart'),
                'value'    => 'variations',
                'children' => [
                    [
                        'filter_type' => 'relation',
                        'relation'    => 'variants',
                        'label'       => __('Variation Count', 'fluent-cart'),
                        'value'       => 'has',
                        'type'        => 'numeric',
                    ],
                    [
                        'label'           => __('Variation', 'fluent-cart'),
                        'value'           => 'variation_items',
                        'column'          => 'id',
                        'filter_type'     => 'relation',
                        'relation'        => 'variants',
                        'remote_data_key' => 'product_variations',
                        'type'            => 'remote_tree_select',
                        'limit'           => 10,
                    ],
                    [
                        'label'       => __('Variation Type', 'fluent-cart'),
                        'value'       => 'variation_type',
                        'filter_type' => 'relation',
                        'relation'    => 'detail',
                        'column'      => 'variation_type',
                        'type'        => 'selections',
                        'options'     => [
                            Helper::PRODUCT_TYPE_SIMPLE            => __('Simple', 'fluent-cart'),
                            Helper::PRODUCT_TYPE_SIMPLE_VARIATION  => __('Simple Variations', 'fluent-cart'),
                            Helper::PRODUCT_TYPE_ADVANCE_VARIATION => __('Advanced Variations', 'fluent-cart'),
                        ],
                        'is_multiple' => false,
                        //'is_only_in'  => true
                    ],
                ],
            ],
            'taxonomy'   => [
                'label'    => __('Taxonomies', 'fluent-cart'),
                'value'    => 'taxonomy',
                'children' => $taxonomyFilters
            ]
        ];
    }

    /**
     * Filter products by total available stock.
     * Variants with manage_stock=0 are unlimited (always in-stock).
     * For managed variants, SUM(available) is used.
     */
    private static function filterByTotalAvailable($query, $operator, $value)
    {
        // Subquery: SUM of available across managed variants only
        $managedSum = ProductVariation::query()
            ->selectRaw('COALESCE(SUM(available), 0)')
            ->whereColumn('fct_product_variations.post_id', 'posts.ID')
            ->where('manage_stock', 1);

        // Only consider products that have variants
        $query->has('variants');

        if (in_array($operator, ['>', '>='])) {
            // Positive check: unlimited products match, OR managed sum meets condition
            $query->where(function ($q) use ($managedSum, $operator, $value) {
                $q->whereHas('variants', function ($vq) {
                    $vq->where('manage_stock', 0);
                })->orWhere($managedSum, $operator, $value);
            });
        } else {
            // Negative check: exclude unlimited, check managed sum only
            $query->whereDoesntHave('variants', function ($vq) {
                $vq->where('manage_stock', 0);
            })->where($managedSum, $operator, $value);
        }
    }

    public function centColumns(): array
    {
        return ['min_price', 'max_price'];
    }

    public static function makeNestedTreeOption($data): array
    {
        $options = [];
        foreach ($data as $item) {
            $option = [];
            $option['value'] = $item['value'];
            $option['label'] = $item['label'];

            if (is_array($item['children']) && count($item['children'])) {
                $option['children'] = static::makeNestedTreeOption($item['children']);
            } else {
                $option['children'] = [];
            }
            $options[] = $option;
        }

        return $options;
    }
}
