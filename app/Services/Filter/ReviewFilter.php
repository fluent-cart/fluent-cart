<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\App\Models\ProductReview;
use FluentCart\Framework\Support\Arr;

class ReviewFilter extends BaseFilter
{
    public string $defaultSortBy = 'id';

    public function applySimpleFilter(?string $search = null): void
    {
        $isApplied = $this->applySimpleOperatorFilter();
        if ($isApplied) {
            return;
        }

        $searchTerm = $search ?? $this->search;

        $this->query = $this->query->when($searchTerm, function ($query, $search) {
            // Escape LIKE wildcards first — the search() scope adds the
            // surrounding %, so a literal % or _ in the term must not widen
            // the match.
            $searchValue = addcslashes(trim($search), '\\%_');

            return $query->where(function ($query) use ($searchValue) {
                $query->search([
                    'reviewer_name'  => ['column' => 'reviewer_name', 'operator' => 'like_all', 'value' => $searchValue],
                    'reviewer_email' => ['column' => 'reviewer_email', 'operator' => 'or_like_all', 'value' => $searchValue],
                    'title'          => ['column' => 'title', 'operator' => 'or_like_all', 'value' => $searchValue],
                    'review'         => ['column' => 'review', 'operator' => 'or_like_all', 'value' => $searchValue],
                ])->orWhereHas('product', function ($productQuery) use ($searchValue) {
                    $productQuery->search([
                        'post_title' => ['column' => 'post_title', 'operator' => 'like_all', 'value' => $searchValue],
                    ]);
                });
            });
        });
    }

    /**
     * Maps tab keys to the column used for active view filtering.
     */
    public function tabsMap(): array
    {
        return [
            'approved' => 'status',
            'pending'  => 'status',
            'spam'     => 'status',
            'trash'    => 'status',
        ];
    }

    public function getModel(): string
    {
        return ProductReview::class;
    }

    /**
     * Whitelists `with[]=product` (admin Product column, filter banner) and
     * `with[]=customer` (reviewer link). No extra gate: already behind
     * `reviews/manage`.
     *
     * @return array<string, callable>
     */
    protected function allowedWiths(): array
    {
        return [
            'product'  => static function ($query) {
                return $query->with(['product']);
            },
            'customer' => static function ($query) {
                return $query->with(['customer']);
            },
        ];
    }

    public static function getFilterName(): string
    {
        return 'reviews';
    }

    public function applyActiveViewFilter(?string $activeView = null): void
    {
        $view = $activeView ?? $this->activeView;

        if (!$view || $view === 'all') {
            return;
        }

        $column = Arr::get($this->tabsMap(), $view);

        if (!$column) {
            return;
        }

        $this->query->where($column, $view);
    }

    public static function getSearchableFields(): array
    {
        return [
            'id' => [
                'column'      => 'id',
                'description' => __('Review ID', 'fluent-cart'),
                'type'        => 'numeric',
                'examples'    => [
                    'id = 1',
                    'id > 5',
                    'id :: 1-10',
                ],
            ],
        ];
    }

    public static function advanceFilterOptions(): array
    {
        return [
            'review_property' => [
                'label'    => __('Review Property', 'fluent-cart'),
                'value'    => 'review_property',
                'children' => [
                    [
                        'label'       => __('Star Rating', 'fluent-cart'),
                        'value'       => 'star_rating',
                        'filter_type' => 'custom',
                        'type'        => 'selections',
                        'options'     => [
                            '5' => __('5 Stars', 'fluent-cart'),
                            '4' => __('4 Stars', 'fluent-cart'),
                            '3' => __('3 Stars', 'fluent-cart'),
                            '2' => __('2 Stars', 'fluent-cart'),
                            '1' => __('1 Star', 'fluent-cart'),
                        ],
                        'is_multiple' => false,
                        'callback'    => static function ($query, $item) {
                            $operator = Arr::get($item, 'operator', '=');
                            if (!in_array($operator, ['=', '!=', '>', '<', '>=', '<='])) {
                                $operator = '=';
                            }
                            $value = absint(Arr::get($item, 'value', 0));
                            if ($value < 1 || $value > 5) {
                                return;
                            }
                            $query->where('rating', $operator, $value);
                        },
                    ],
                    [
                        'label'       => __('Verified Purchase', 'fluent-cart'),
                        'value'       => 'verified_purchase',
                        'filter_type' => 'custom',
                        'type'        => 'selections',
                        'options'     => [
                            'yes' => __('Yes', 'fluent-cart'),
                            'no'  => __('No', 'fluent-cart'),
                        ],
                        'is_multiple' => false,
                        'is_only_in'  => true,
                        'callback'    => static function ($query, $item) {
                            $value = Arr::get($item, 'value');
                            if ($value === 'yes') {
                                $query->where('is_verified', 1);
                            } elseif ($value === 'no') {
                                $query->where('is_verified', 0);
                            }
                        },
                    ],
                    [
                        'label'       => __('Has Admin Reply', 'fluent-cart'),
                        'value'       => 'has_admin_reply',
                        'filter_type' => 'custom',
                        'type'        => 'selections',
                        'options'     => [
                            'yes' => __('Yes', 'fluent-cart'),
                            'no'  => __('No', 'fluent-cart'),
                        ],
                        'is_multiple' => false,
                        'is_only_in'  => true,
                        'callback'    => static function ($query, $item) {
                            $value = Arr::get($item, 'value');
                            if ($value === 'yes') {
                                $query->hasReplies();
                            } elseif ($value === 'no') {
                                $query->withoutReplies();
                            }
                        },
                    ],
                    [
                        'label'       => __('Review Date', 'fluent-cart'),
                        'value'       => 'created_at',
                        'filter_type' => 'date',
                        'type'        => 'dates',
                        'is_multiple' => false,
                    ],
                    [
                        'label'       => __('Reviewer Name', 'fluent-cart'),
                        'value'       => 'reviewer_name',
                        'filter_type' => 'column',
                        'type'        => 'text',
                        'is_multiple' => false,
                    ],
                    [
                        'label'       => __('Reviewer Email', 'fluent-cart'),
                        'value'       => 'reviewer_email',
                        'filter_type' => 'column',
                        'type'        => 'text',
                        'is_multiple' => false,
                    ],
                ],
            ],
        ];
    }

    protected function parseSortBy(): string
    {
        $sortBy = Arr::get($this->args, 'sort_by');

        if (empty($sortBy)) {
            return $this->defaultSortBy;
        }

        $allowedSorts = [
            'id'            => 'id',
            'created_at'    => 'created_at',
            'reviewer_name' => 'reviewer_name',
            'rating'        => 'rating',
        ];

        return Arr::get($allowedSorts, $sortBy, $this->defaultSortBy);
    }

    protected function applySort()
    {
        // Pass the raw requested sort_by (e.g. 'rating', 'id') to the hook
        // so extensions can detect custom sort keys before they are mapped to columns.
        $rawSortBy = Arr::get($this->args, 'sort_by', $this->sortBy);
        $this->query = apply_filters('fluent_cart/review/sort_query', $this->query, $rawSortBy, $this->sortType);

        if ($this->sortBy === 'rating') {
            $this->query->orderBy('rating', $this->sortType);
            // Deterministic tie-breaker: ratings are 1-5, so ties are the norm and an
            // unstable order makes rows repeat or vanish across pages.
            $this->query->orderBy('id', $this->sortType);
            return;
        }

        parent::applySort();
    }

    public function customQuery()
    {
        /** @var \FluentCart\Framework\Database\Orm\Builder $query */
        $query = $this->query;

        // Only show top-level reviews, not admin replies. Spelled out rather than
        // calling ProductReview::scopeTopLevel(): model scopes resolve through
        // Builder::__call(), which static analysis cannot see. Keep in step with
        // that scope if its predicate ever changes.
        $query->whereNull('parent_id');

        // Backward compat: honor legacy post_id and rating params
        $postId = Arr::get($this->args, 'post_id');
        if ($postId) {
            $query->where('post_id', (int) $postId);
        }

        $rating = Arr::get($this->args, 'rating');
        if ($rating && $rating >= 1 && $rating <= 5) {
            $query->where('rating', (int) $rating);
        }

        return $query;
    }

    /**
     * Disable user-controlled scopes entirely.
     *
     * BaseFilter::applyScopes() calls arbitrary methods on the query builder
     * from the `scopes` request param (e.g. scopes=["delete"] would execute
     * $query->delete()). The controller whitelist already excludes `scopes`,
     * but this is defense-in-depth in case make() is called directly.
     */
    protected function applyScopes()
    {
        // No-op — scopes not used. The top-level predicate is applied via customQuery().
    }

    public function dateColumns(): array
    {
        return ['created_at'];
    }

}
