<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\Api\Taxonomy;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Product;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class ProductFilter extends BaseFilter
{
    public string $defaultSortBy = "ID";

    public function applySimpleFilter()
    {

        $isApplied = $this->applySimpleOperatorFilter();
        if ($isApplied) {
            return;
        }

        $this->query = $this->query->when($this->search, function ($query, $search) {
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
    public function applyActiveViewFilter()
    {
        $tabsMap = $this->tabsMap();

        $this->query->when($this->activeView, function ($query, $activeView) use ($tabsMap) {
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
