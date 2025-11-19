<?php

namespace FluentCart\App\Modules\Data;

use FluentCart\App\Models\Product;
use FluentCart\App\Models\WpModels\Term;
use FluentCart\App\Services\TemplateService;
use FluentCart\Framework\Support\Arr;

class ProductQuery
{
    private array $queryArgs = [];

    public function __construct($args = [])
    {
        //example tax query
//        $taxQuery = [
//            'product-categories' => [1,2],
//
//        ];

        $perPage = get_option('posts_per_page');

        $defaults = [
            'product_status' => 'publish',
            'per_page'       => $perPage,
            'sort_by'        => 'id', // id|date|title|price
            'sort_type'      => 'desc', // asc|desc
            'paginate'       => 'simple', //simple|cursor|false
            'page'           => 1,
            'cursor'         => null,
            'product_type'   => '', // physical|digital|subscription|onetime|simple|variations
            'stock_status'   => '', // in_stock|out_of_stock // check only if global stock management is enabled
            'min_price'      => 0,
            'max_price'      => 0,
            'on_sale'        => false,
            'is_main_query'  => false,
            'search'         => '',
            'include_ids'    => [], // array of product ids to include
            'exclude_ids'    => [], // array of product ids to exclude
            'with'           => ['detail', 'variants'], // relationships to load,
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
            'tax_query'      => [],
        ];


        $this->queryArgs = wp_parse_args($args, $defaults);

        if (Arr::get($this->queryArgs, 'is_main_query')) {
            $pageType = TemplateService::getCurrentFcPageType();
            if ($pageType == 'product_taxonomy') {
                $queried_object = get_queried_object();
                if (!empty($queried_object->taxonomy) && is_tax(get_object_taxonomies('fluent-products'))) {
                    $currentTermId = $queried_object->term_id;
                    // get the childs
                    $childTerms = get_terms([
                        'taxonomy'   => $queried_object->taxonomy,
                        'parent'     => $currentTermId,
                        'hide_empty' => false,
                    ]);
                    $childTermIds = array_column($childTerms, 'term_id');

                    $termIds = array_merge([$currentTermId], $childTermIds);
                    //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                    $this->queryArgs['tax_query'] = [
                        $queried_object->taxonomy => $termIds,
                    ];
                }
            }

            $paged = get_query_var('paged') ? get_query_var('paged') : null;
            if ($paged) {
                $this->queryArgs['page'] = absint($paged) ? absint($paged) : 1;
                $this->queryArgs['paginate'] = 'simple';
            }
        }

    }

    public function getDefaultFilters()
    {
        return array_filter([
            'per_page'     => $this->queryArgs['per_page'],
            'sort_by'      => $this->queryArgs['sort_by'],
            'sort_type'    => $this->queryArgs['sort_type'],
            'product_type' => $this->queryArgs['product_type'],
            'stock_status' => $this->queryArgs['stock_status'],
            'min_price'    => $this->queryArgs['min_price'],
            'max_price'    => $this->queryArgs['max_price'],
            'on_sale'      => $this->queryArgs['on_sale'],
            'search'       => $this->queryArgs['search'],
            'include_ids'  => $this->queryArgs['include_ids'],
            'exclude_ids'  => $this->queryArgs['exclude_ids'],
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
            'tax_query'    => $this->queryArgs['tax_query']
        ]);
    }

    public function setDefultFilters($filters = [])
    {
        foreach ($filters as $key => $value) {
            if (array_key_exists($key, $this->queryArgs)) {
                $this->queryArgs[$key] = $value;
            }
        }

        return $this;
    }

    public function getParsedArgs()
    {
        return $this->queryArgs;
    }

    public function getQuery()
    {
        $query = Product::query()
            ->where('post_status', $this->queryArgs['product_status'])
            ->when($this->queryArgs['search'], function ($query, $search) {
                $query->where('post_title', 'like', '%' . $search . '%');
            })
            ->when($this->queryArgs['include_ids'], function ($query, $includeIds) {
                $query->whereIn('ID', $includeIds);
            })
            ->when($this->queryArgs['exclude_ids'], function ($query, $excludeIds) {
                $query->whereNotIn('ID', $excludeIds);
            })
            //TODO: check if stock management module is on
            ->when($this->queryArgs['stock_status'], function ($query, $stockStatus) {
                $query->whereHas('detail', function ($query) use ($stockStatus) {
                    $query->where('stock_availability', $stockStatus);
                });
            })
            ->when($this->queryArgs['on_sale'], function ($query) {
                $query->whereHas('variants', function ($query) {
                    $query->where('item_price', '<', 'compare_price');
                });
            })
            ->with($this->queryArgs['with']);

        $query = $query->applyCustomSortBy(
            $this->queryArgs['sort_by'],
            $this->queryArgs['sort_type']
        )
            ->byVariantTypes(
                $this->queryArgs['product_type'],
            )->filterByTaxonomy(
                $this->queryArgs['tax_query']
            );

        return $query;


    }

    public function get()
    {
        $paginate = $this->queryArgs['paginate'];
        $query = $this->getQuery();

        if (!$paginate) {
            return $query->take(
                $this->queryArgs['per_page']
            )->get();
        }

        if ($paginate === 'cursor') {
            return $query->cursorPaginate(
                $this->queryArgs['per_page'],
                ['*'],
                'cursor',
                $this->queryArgs['cursor']
            );
        }

        return $query->paginate(
            $this->queryArgs['per_page'],
            ['*'],
            'page',
            $this->queryArgs['page']
        );
    }

    public function getConnectedTerms($types = [], $formatted = true)
    {
        $productQuery = $this->getQuery();

        $terms = Term::query()->whereHas('taxonomy', function ($query) use ($types, $productQuery) {
            if (!empty($types)) {
                $query->whereIn('taxonomy', (array)$types);
            }
            $query->whereHas('termRelationships', function ($query) use ($productQuery) {
                $query->whereIn('object_id', $productQuery->select('ID'));
            });
        })
            ->with(['taxonomy'])
            ->get();

        if ($formatted) {
            $formattedTerms = [];

            foreach ($terms as $term) {
                $taxonomy = $term->taxonomy;
                if ($taxonomy) {
                    $taxonomyName = $taxonomy->taxonomy;
                    if (!isset($formattedTerms[$taxonomyName])) {
                        $formattedTerms[$taxonomyName] = [
                            'taxonomy' => $taxonomyName,
                            'terms'    => []
                        ];
                    }

                    $formattedTerms[$taxonomyName]['terms'][] = [
                        'term_id'     => $term->term_id,
                        'name'        => $term->name,
                        'slug'        => $term->slug,
                        'parent'      => $taxonomy->parent,
                        'description' => $term->description,
                    ];
                }
            }

            return $formattedTerms;
        }


        return $terms;
    }
}
