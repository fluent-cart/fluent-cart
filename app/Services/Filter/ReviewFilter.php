<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\App\Models\ProductReview;
use FluentCart\Framework\Support\Arr;

class ReviewFilter extends BaseFilter
{
    public string $defaultSortBy = 'comment_ID';

    public function applySimpleFilter(?string $search = null): void
    {
        $isApplied = $this->applySimpleOperatorFilter();
        if ($isApplied) {
            return;
        }

        $searchTerm = $search ?? $this->search;

        $this->query = $this->query->when($searchTerm, function ($query, $search) {
            $searchLike = '%' . addcslashes($search, '\\%_') . '%';
            return $query->where(function ($query) use ($searchLike) {
                $query->where('comment_author', 'LIKE', $searchLike)
                    ->orWhere('comment_author_email', 'LIKE', $searchLike)
                    ->orWhere('comment_content', 'LIKE', $searchLike)
                    ->orWhereExists(function ($sub) use ($searchLike) {
                        $sub->from('commentmeta')
                            ->whereColumn('commentmeta.comment_id', 'comments.comment_ID')
                            ->where('commentmeta.meta_key', ProductReview::META_TITLE)
                            ->where('commentmeta.meta_value', 'LIKE', $searchLike);
                    })
                    ->orWhereHas('product', function ($q) use ($searchLike) {
                        $q->where('post_title', 'LIKE', $searchLike);
                    });
            });
        });
    }

    /**
     * Maps tab keys to [column, wp_value] for active view filtering.
     * WordPress comments use '1'/'0' for approved/pending, not the status name.
     */
    public function tabsMap(): array
    {
        return [
            'approved' => 'comment_approved',
            'pending'  => 'comment_approved',
            'spam'     => 'comment_approved',
            'trash'    => 'comment_approved',
        ];
    }

    public function getModel(): string
    {
        return ProductReview::class;
    }

    /**
     * Whitelists `with[]=product` — needed for the admin Product column and
     * filter banner. No extra gate: already behind `reviews/manage`.
     *
     * @return array<string, callable>
     */
    protected function allowedWiths(): array
    {
        return [
            'product' => static function ($query) {
                return $query->with(['product']);
            }
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

        $wpValue = ProductReview::translateStatusToWp($view);
        $this->query->where($column, $wpValue);
    }

    public static function getSearchableFields(): array
    {
        return [
            'id' => [
                'column'      => 'comment_ID',
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
                            self::filterByCommentMeta($query, ProductReview::META_RATING, $operator, (string) $value);
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
                                self::filterByCommentMeta($query, ProductReview::META_IS_VERIFIED, '=', '1');
                            } elseif ($value === 'no') {
                                self::filterByCommentMetaNotExists($query, ProductReview::META_IS_VERIFIED, '1');
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
                            $commentType = ProductReview::getCommentType();
                            if ($value === 'yes') {
                                self::filterHasReply($query, $commentType);
                            } elseif ($value === 'no') {
                                self::filterNoReply($query, $commentType);
                            }
                        },
                    ],
                    [
                        'label'       => __('Review Date', 'fluent-cart'),
                        'value'       => 'comment_date_gmt',
                        'filter_type' => 'date',
                        'type'        => 'dates',
                        'is_multiple' => false,
                    ],
                    [
                        'label'       => __('Reviewer Name', 'fluent-cart'),
                        'value'       => 'comment_author',
                        'filter_type' => 'column',
                        'type'        => 'text',
                        'is_multiple' => false,
                    ],
                    [
                        'label'       => __('Reviewer Email', 'fluent-cart'),
                        'value'       => 'comment_author_email',
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
            'id'            => 'comment_ID',
            'created_at'    => 'comment_date_gmt',
            'reviewer_name' => 'comment_author',
            'comment_ID'    => 'comment_ID',
        ];

        // Rating sort is handled specially in applySort() via meta JOIN
        if ($sortBy === 'rating') {
            return 'rating';
        }

        return Arr::get($allowedSorts, $sortBy, $this->defaultSortBy);
    }

    protected function applySort()
    {
        // Pass the raw requested sort_by (e.g. 'rating', 'id') to the hook
        // so extensions can detect custom sort keys before they are mapped to columns.
        $rawSortBy = Arr::get($this->args, 'sort_by', $this->sortBy);
        $this->query = apply_filters('fluent_cart/review/sort_query', $this->query, $rawSortBy, $this->sortType);

        if ($this->sortBy === 'rating') {
            // Use unprefixed table name — WPFluent's leftJoin() adds the prefix automatically
            $this->query->leftJoin("commentmeta as cm_sort_rating", function ($join) {
                $join->on('cm_sort_rating.comment_id', '=', 'comments.comment_ID')
                    ->where('cm_sort_rating.meta_key', '=', ProductReview::META_RATING);
            });
            $this->query->orderByRaw("CAST(cm_sort_rating.meta_value AS UNSIGNED) {$this->sortType}");
            // Deterministic tie-breaker: ratings are 1-5, so ties are the norm and an
            // unstable order makes rows repeat or vanish across pages.
            $this->query->orderBy('comments.comment_ID', $this->sortType);
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
        $query->where('comment_parent', 0);

        // Backward compat: honor legacy post_id and rating params
        $postId = Arr::get($this->args, 'post_id');
        if ($postId) {
            $query->where('comment_post_ID', (int) $postId);
        }

        $rating = Arr::get($this->args, 'rating');
        if ($rating && $rating >= 1 && $rating <= 5) {
            self::filterByCommentMeta($query, ProductReview::META_RATING, '=', (string) $rating);
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
        // No-op — scopes not used. topLevel() is applied via customQuery().
    }

    public function dateColumns(): array
    {
        return ['comment_date_gmt'];
    }

    /**
     * Filter by existence of a commentmeta row with a specific value.
     */
    private static function filterByCommentMeta($query, string $metaKey, string $operator, string $value)
    {
        $allowed = ['=', '!=', '>', '<', '>=', '<='];
        if (!in_array($operator, $allowed, true)) {
            $operator = '=';
        }

        $query->whereExists(function ($sub) use ($metaKey, $operator, $value) {
            $sub->from('commentmeta')
                ->whereColumn('commentmeta.comment_id', 'comments.comment_ID')
                ->where('commentmeta.meta_key', $metaKey)
                ->where('commentmeta.meta_value', $operator, $value);
        });
    }

    /**
     * Filter reviews where a commentmeta key either doesn't exist or doesn't match the given value.
     */
    private static function filterByCommentMetaNotExists($query, string $metaKey, string $matchValue)
    {
        $query->whereNotExists(function ($sub) use ($metaKey, $matchValue) {
            $sub->from('commentmeta')
                ->whereColumn('commentmeta.comment_id', 'comments.comment_ID')
                ->where('commentmeta.meta_key', $metaKey)
                ->where('commentmeta.meta_value', $matchValue);
        });
    }

    /**
     * Filter reviews that have at least one reply.
     */
    private static function filterHasReply($query, string $commentType)
    {
        $query->whereExists(function ($sub) use ($commentType) {
            $sub->from('comments as r')
                ->whereColumn('r.comment_parent', 'comments.comment_ID')
                ->where('r.comment_type', $commentType);
        });
    }

    /**
     * Filter reviews that have no replies.
     */
    private static function filterNoReply($query, string $commentType)
    {
        $query->whereNotExists(function ($sub) use ($commentType) {
            $sub->from('comments as r')
                ->whereColumn('r.comment_parent', 'comments.comment_ID')
                ->where('r.comment_type', $commentType);
        });
    }
}
