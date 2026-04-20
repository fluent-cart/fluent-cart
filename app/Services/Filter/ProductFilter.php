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
                    $q->whereHas('variants', function ($detailQuery) {
                        $detailQuery->where('payment_type', '!=', 'subscription');
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
                            Helper::PRODUCT_TYPE_SIMPLE           => __('Simple', 'fluent-cart'),
                            Helper::PRODUCT_TYPE_SIMPLE_VARIATION => __('Simple Variations', 'fluent-cart'),
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
