<?php

namespace FluentCart\Api\Resource;

use FluentCart\App\Events\ReviewApproved;
use FluentCart\App\Events\ReviewReplied;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class ProductReviewResource extends BaseResourceApi
{
    public static function getQuery(): Builder
    {
        return ProductReview::query();
    }

    public static function get(array $params = [])
    {
        $query = static::getQuery();

        $status = Arr::get($params, 'status', 'all');
        $postId = Arr::get($params, 'post_id');
        // A list of stars from the storefront's chips wins over a single rating.
        $rating = Arr::get($params, 'ratings') ?: Arr::get($params, 'rating');
        $search = Arr::get($params, 'search');
        $sortBy = Arr::get($params, 'sort_by', 'id');
        $sortOrder = Arr::get($params, 'sort_order', 'DESC');
        $perPage = min(100, max(1, (int) Arr::get($params, 'per_page', 15)));
        $with = Arr::get($params, 'with', []);

        // Only show top-level reviews (not replies) in listings
        $query->topLevel();

        $query->ofStatus($status)
            ->ofProduct($postId)
            ->ofRating($rating)
            // The list's own floor, set on the Review Item block. Sits beside
            // ofRating() rather than instead of it: the chips still pick a
            // single star, and a chip below the floor simply finds nothing —
            // the two narrow the same query from different directions.
            ->minRating(Arr::get($params, 'min_rating'));

        if (Arr::get($params, 'has_media')) {
            $query->withMedia();
        }

        if (Arr::get($params, 'verified_only')) {
            $query->where('is_verified', 1);
        }

        // Allow extensions to apply advanced filters (e.g. saved views from Pro)
        $filterType = Arr::get($params, 'filter_type', 'simple');
        if ($filterType === 'advanced') {
            $advancedFilters = Arr::get($params, 'advanced_filters', []);
            if (is_string($advancedFilters)) {
                $advancedFilters = json_decode($advancedFilters, true) ?: [];
            }
            $query = apply_filters('fluent_cart/review/advanced_filters', $query, $advancedFilters, $params);
        }

        if ($search) {
            global $wpdb;
            // esc_like escapes the LIKE wildcards; search() adds the
            // surrounding % via its like_all operators.
            $searchValue = $wpdb->esc_like(trim($search));

            $query->where(function ($reviewQuery) use ($searchValue) {
                $reviewQuery->search([
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
        }

        if (!empty($with)) {
            $query->with($with);
        }

        $sortOrder = in_array(strtoupper($sortOrder), ['ASC', 'DESC']) ? strtoupper($sortOrder) : 'DESC';

        $defaultSortColumns = ['id', 'rating', 'created_at', 'reviewer_name'];
        $query = apply_filters('fluent_cart/review/sort_query', $query, $sortBy, $sortOrder);

        if (in_array($sortBy, $defaultSortColumns)) {
            $query->orderBy($sortBy, $sortOrder);

            // Tie-breaker on the primary key. Ratings and dates collide constantly, and
            // without a deterministic second key MySQL is free to order ties differently
            // per page — rows then repeat or vanish while paginating.
            if ($sortBy !== 'id') {
                $query->orderBy('id', $sortOrder);
            }
        } elseif (!has_filter('fluent_cart/review/sort_query')) {
            $query->orderBy('id', $sortOrder);
        }

        // Null page falls through to the paginator's own request resolution,
        // the same shape every sibling resource uses.
        return $query->paginate($perPage, ['*'], 'page', Arr::get($params, 'page'));
    }

    public static function find($id, $params = [])
    {
        $with = Arr::get($params, 'with', ['product', 'customer', 'order', 'replies']);

        $review = static::getQuery()->with($with)->find($id);

        if (!$review) {
            return static::makeErrorResponse([
                ['code' => 404, 'message' => __('Review not found', 'fluent-cart')]
            ]);
        }

        return $review;
    }

    public static function create($data, $params = [])
    {
        // Verify the product actually exists — exists() matches the codebase
        // convention for boolean checks (ProductReviewService, TaxClass,
        // stock checks): no model hydration, nothing to throw, so a review
        // can never be attached to a nonexistent product ID.
        $postId = (int) ($data['post_id'] ?? 0);
        if (!$postId || !Product::query()->where('ID', $postId)->exists()) {
            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('A valid product is required', 'fluent-cart')]
            ], 400);
        }

        // Validate parent_id belongs to the same product if provided
        $parentId = (int) ($data['parent_id'] ?? 0);
        if ($parentId) {
            $parent = static::getQuery()->find($parentId);
            if (!$parent || $parent->post_id !== $postId) {
                $parentId = 0;
            }
        }

        // Any child row is a reply — admin or customer follow-up; the
        // is_admin_reply flag records which.
        $isReply = (bool) $parentId;

        // A reply belongs to the same item as its review, so the thread can
        // be read per item without a join. A top-level row takes what the
        // caller resolved — the controller has already checked the item
        // belongs to the product and that a grant covers it.
        $itemId = $isReply ? (int) $parent->item_id : (int) ($data['item_id'] ?? 0);

        $review = new ProductReview([
            'post_id'        => $postId,
            'item_id'        => $itemId ?: null,
            'parent_id'      => $parentId ?: null,
            'reviewer_name'  => $data['reviewer_name'] ?? '',
            'reviewer_email' => $data['reviewer_email'] ?? '',
            'title'          => $data['title'] ?? null,
            'review'         => $data['content'] ?? '',
            'rating'         => $isReply ? null : ProductReviewService::clampRating($data['rating'] ?? 0),
            'ip_address'     => !empty($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '',
            'other_info'     => static::buildOtherInfo([], $data),
        ]);

        // Trust fields — server-derived by the caller, never mass-assigned
        $review->user_id = (int) ($data['user_id'] ?? 0) ?: null;
        $review->customer_id = (int) ($data['customer_id'] ?? 0) ?: null;
        $review->order_id = (int) ($data['order_id'] ?? 0) ?: null;
        $review->status = $data['status'] ?? Status::REVIEW_PENDING;
        $review->is_verified = (int) !empty($data['is_verified']);
        $review->is_admin_reply = (int) !empty($data['is_admin_reply']);

        if (!$review->save()) {
            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('Failed to create review', 'fluent-cart')]
            ]);
        }

        // Admin replies (rating NULL) don't affect rating aggregates — skip recalculation.
        // This also prevents N redundant recalculations during bulkReply.
        if ($review->status === Status::REVIEW_APPROVED && !$isReply) {
            static::recalculateProductRatings($review->post_id);
        }

        // A review born approved — the store auto-approves, or a moderator
        // wrote it — is an approval too, but it is NOT dispatched from here.
        // Both callers that create top-level reviews run their after_submit
        // and media hooks after this returns, and those hooks write other_info
        // from a model and save() it. The approval notice claims a key in that
        // same blob the moment the event fires; fired here, the claim would be
        // erased by the media save that follows. The controllers dispatch once
        // their hooks are done, on a re-read row — see
        // ReviewApproved::dispatchIfApproved() and its callers.

        // The creation hook is fluent_cart/review_created, fired by the
        // ReviewCreated event with the standard array payload — matching the
        // fluent_cart/{entity}_{verb} event convention (order_created etc.).

        return $review;
    }

    public static function update($data, $id, $params = [])
    {
        $review = static::getQuery()->find($id);

        if (!$review) {
            return static::makeErrorResponse([
                ['code' => 404, 'message' => __('Review not found', 'fluent-cart')]
            ]);
        }

        $oldStatus = $review->status;
        $oldRating = $review->rating;

        foreach (['reviewer_name', 'reviewer_email', 'title'] as $column) {
            if (array_key_exists($column, $data)) {
                $review->{$column} = $data[$column];
            }
        }

        if (array_key_exists('post_id', $data)) {
            $review->post_id = (int) $data['post_id'];
        }

        if (array_key_exists('content', $data)) {
            $review->review = $data['content'];
        }

        if (array_key_exists('rating', $data) && !$review->parent_id) {
            $review->rating = ProductReviewService::clampRating($data['rating']);
        }

        if (array_key_exists('status', $data)) {
            $review->status = $data['status'];
        }

        // Guarded column — assigned directly, never mass-assigned. Only a
        // top-level review carries the badge; a reply has no purchase to
        // verify.
        if (array_key_exists('is_verified', $data) && !$review->parent_id) {
            $review->is_verified = (int) !empty($data['is_verified']);
        }

        if (array_key_exists('meta', $data)) {
            $review->other_info = static::buildOtherInfo($review->other_info ?: [], $data);
        }

        // A failed write must not be reported as success — callers would act on
        // the in-memory model while the stored row still holds the old values,
        // and the status-change hooks would fire for a change that never landed.
        if (!$review->save()) {
            return static::makeErrorResponse([
                ['code' => 500, 'message' => __('Failed to update review', 'fluent-cart')]
            ]);
        }

        if ($oldStatus !== $review->status || $oldRating !== $review->rating) {
            static::recalculateProductRatings($review->post_id);
        }

        ReviewApproved::dispatchIfApproved($review, $oldStatus);

        return static::makeSuccessResponse(
            $review,
            __('Review updated successfully', 'fluent-cart')
        );
    }

    public static function bulkReply(array $ids, array $replyTemplate)
    {
        // Cap batch size to prevent long-running requests
        $ids = array_slice($ids, 0, 50);

        $reviews = static::getQuery()
            ->whereIn('id', $ids)
            ->topLevel()
            ->get();

        if ($reviews->isEmpty()) {
            return static::makeErrorResponse([
                ['code' => 404, 'message' => __('No valid reviews found', 'fluent-cart')]
            ]);
        }

        $connection = static::getQuery()->getConnection();
        $connection->beginTransaction();

        try {
            // One store reply per review unless an extension allows multiple
            // replies: skip reviews that already have a reply. The lookup
            // runs inside the transaction with the parent rows locked, so a
            // concurrent single or bulk reply to the same reviews serializes
            // on the locks instead of racing the check.
            $skipped = 0;
            if (!\FluentCart\App\Services\ProductReviewService::isMultipleRepliesAllowed()) {
                $reviewIds = [];
                foreach ($reviews as $review) {
                    $reviewIds[] = (int) $review->id;
                }

                // Row locks on every selected parent; released on commit/rollback.
                static::getQuery()
                    ->whereIn('id', $reviewIds)
                    ->lockForUpdate()
                    ->get();

                $repliedParentIds = [];
                $existingReplies = static::getQuery()->whereIn('parent_id', $reviewIds)->get();
                foreach ($existingReplies as $existingReply) {
                    $repliedParentIds[(int) $existingReply->parent_id] = true;
                }

                $reviews = $reviews->filter(function ($review) use ($repliedParentIds) {
                    return empty($repliedParentIds[(int) $review->id]);
                });

                $skipped = count($repliedParentIds);

                if ($reviews->isEmpty()) {
                    $connection->rollBack();

                    return static::makeErrorResponse([
                        ['code' => 422, 'message' => __('All selected reviews already have a reply.', 'fluent-cart')]
                    ], 422);
                }
            }

            $replied = 0;
            $createdReplies = [];
            foreach ($reviews as $review) {
                $replyData = array_merge($replyTemplate, [
                    'parent_id' => $review->id,
                    'post_id'   => $review->post_id,
                ]);

                $reply = static::create($replyData);

                if (is_wp_error($reply)) {
                    throw new \Exception($reply->get_error_message());
                }

                // Kept with the review it answers, which this loop already
                // holds — the event is handed both rather than looking the
                // review up again for every reply in the batch.
                $createdReplies[] = ['reply' => $reply, 'review' => $review];
                $replied++;
            }

            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollBack();

            return static::makeErrorResponse([
                ['code' => 500, 'message' => __('Failed to create replies', 'fluent-cart')]
            ]);
        }

        // Announced only now, after the commit: a listener that queued an
        // email for a reply the rollback then erased would tell a reviewer
        // about a reply that does not exist. The products for the whole
        // batch come in one query, so a batch of fifty announces itself
        // without fifty lookups of what was just written.
        $productIds = [];
        foreach ($createdReplies as $pair) {
            $productIds[(int) $pair['review']->post_id] = true;
        }
        $products = Product::query()->whereIn('ID', array_keys($productIds))->get()->keyBy('ID');

        foreach ($createdReplies as $pair) {
            ReviewReplied::dispatchIfStoreReply(
                $pair['reply'],
                $pair['review'],
                $products->get((int) $pair['review']->post_id)
            );
        }

        $message = sprintf(
            /* translators: %d - number of reviews replied to */
            __('Successfully replied to %d review(s).', 'fluent-cart'),
            $replied
        );

        if ($skipped > 0) {
            $message .= ' ' . sprintf(
                /* translators: %d - number of reviews skipped because they already have a reply */
                __('%d review(s) skipped — they already have a reply.', 'fluent-cart'),
                $skipped
            );
        }

        return static::makeSuccessResponse(
            ['replied' => $replied, 'skipped' => $skipped],
            $message
        );
    }

    public static function delete($id, $params = [])
    {
        $review = static::getQuery()->find($id);

        if (!$review) {
            return static::makeErrorResponse([
                ['code' => 404, 'message' => __('Review not found', 'fluent-cart')]
            ]);
        }

        $postId = $review->post_id;
        $wasApproved = $review->status === Status::REVIEW_APPROVED;

        $connection = static::getQuery()->getConnection();
        $connection->beginTransaction();

        try {
            do_action('fluent_cart/review/before_delete', $review);

            // Replies are deleted together with the review in one batch
            static::getQuery()
                ->where(function ($q) use ($id) {
                    $q->where('id', $id)->orWhere('parent_id', $id);
                })
                ->delete();

            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollBack();

            return static::makeErrorResponse([
                ['code' => 500, 'message' => __('Failed to delete review', 'fluent-cart')]
            ]);
        }

        do_action('fluent_cart/review/after_delete', ['reviews' => [$review]]);

        if ($wasApproved) {
            static::recalculateProductRatings($postId);
        }

        return static::makeSuccessResponse(
            [],
            __('Review deleted successfully', 'fluent-cart')
        );
    }

    public static function bulkAction($action, $ids)
    {
        if (empty($ids)) {
            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('No reviews selected', 'fluent-cart')]
            ]);
        }

        $validActions = ['approve', 'pending', 'spam', 'trash', 'delete'];
        if (!in_array($action, $validActions)) {
            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('Invalid action', 'fluent-cart')]
            ]);
        }

        // Cap batch size to prevent long-running requests
        $ids = array_slice(array_map('intval', $ids), 0, 50);

        // Resolve the supplied IDs through the model and replace the list
        // with what actually matched.
        $reviewRows = static::getQuery()->whereIn('id', $ids)->get();

        if ($reviewRows->isEmpty()) {
            return static::makeErrorResponse([
                ['code' => 404, 'message' => __('Reviews not found', 'fluent-cart')]
            ]);
        }

        $ids = array_map('intval', $reviewRows->pluck('id')->toArray());
        $affectedProductIds = array_values(array_unique(
            array_map('intval', $reviewRows->pluck('post_id')->toArray())
        ));

        $connection = static::getQuery()->getConnection();
        $connection->beginTransaction();

        try {
            if ($action === 'delete') {
                foreach ($reviewRows as $review) {
                    do_action('fluent_cart/review/before_delete', $review);
                }

                // Replies and parents deleted together in one batch
                $affectedCount = $reviewRows->count();
                static::getQuery()
                    ->where(function ($q) use ($ids) {
                        $q->whereIn('id', $ids)->orWhereIn('parent_id', $ids);
                    })
                    ->delete();
            } else {
                $statusMap = [
                    'approve' => Status::REVIEW_APPROVED,
                    'pending' => Status::REVIEW_PENDING,
                    'spam'    => Status::REVIEW_SPAM,
                    'trash'   => Status::REVIEW_TRASH,
                ];

                $affectedCount = static::getQuery()
                    ->whereIn('id', $ids)
                    ->update(['status' => $statusMap[$action]]);
            }

            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollBack();

            return static::makeErrorResponse([
                ['code' => 500, 'message' => __('Bulk action failed', 'fluent-cart')]
            ]);
        }

        if ($action === 'delete') {
            do_action('fluent_cart/review/after_delete', ['reviews' => $reviewRows->all()]);
        }

        if ($action === 'approve') {
            static::dispatchApprovedEventsForBulk($reviewRows);
        }

        // One batched recalculation for everything this operation touched
        static::recalculateProductRatings($affectedProductIds);

        return static::makeSuccessResponse(
            ['affected' => $affectedCount],
            __('Bulk action completed successfully', 'fluent-cart')
        );
    }

    /**
     * The approved event, for the rows a bulk approve actually changed.
     *
     * The bulk write is one UPDATE, so no model saw its own transition. The
     * rows fetched before it still carry the old status, which is exactly the
     * question: a row already approved was not approved by this action and
     * its author is not told twice. The rows that did move are re-read so the
     * event carries what is stored, not a stale copy.
     *
     * @param \FluentCart\Framework\Support\Collection $reviewsBeforeUpdate the selected rows, as read before the update
     */
    protected static function dispatchApprovedEventsForBulk($reviewsBeforeUpdate): void
    {
        $newlyApprovedIds = [];
        foreach ($reviewsBeforeUpdate as $row) {
            if (!$row->parent_id && $row->status !== Status::REVIEW_APPROVED) {
                $newlyApprovedIds[] = (int) $row->id;
            }
        }

        if (!$newlyApprovedIds) {
            return;
        }

        // The product rides along in the same query; the event reads it off
        // each review rather than fetching it once per dispatch.
        $newlyApprovedReviews = static::getQuery()->with('product')->whereIn('id', $newlyApprovedIds)->get();
        foreach ($newlyApprovedReviews as $review) {
            ReviewApproved::dispatchIfApproved($review, Status::REVIEW_PENDING);
        }
    }

    /**
     * The other_info keys the system owns, which request data can neither
     * set nor erase: the media list (the Pro pipeline's, kept with
     * media_count) and the notice leases (ProductReviewService's — the
     * approval and reply notices' state, timestamp, token and delivered
     * names). A submission that could write approval_notice as "sent" would
     * silence its own approval email; an edit that could erase a real "sent"
     * would have the next re-approval send again, and one that erased a live
     * lease would let a second worker send alongside the first.
     */
    const SYSTEM_OTHER_INFO_PREFIXES = ['media', 'approval_notice', 'reply_notice'];

    /**
     * Whether an other_info key is the system's, by name or prefix — the
     * notice keys come as a family (approval_notice, approval_notice_at, …).
     */
    protected static function isSystemOtherInfoKey($key): bool
    {
        $key = (string) $key;

        foreach (static::SYSTEM_OTHER_INFO_PREFIXES as $prefix) {
            if ($key === $prefix || strpos($key, $prefix . '_') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merge request-shaped extra data into other_info, preserving every
     * system-owned key — see SYSTEM_OTHER_INFO_PREFIXES — exactly as stored.
     *
     * @param array $current Existing other_info
     * @param array $data Request data (extra data under the 'meta' key)
     * @return array|null
     */
    protected static function buildOtherInfo(array $current, array $data)
    {
        if (!array_key_exists('meta', $data)) {
            return $current ?: null;
        }

        $extra = $data['meta'];
        if (is_object($extra)) {
            $extra = (array) $extra;
        }
        if (!is_array($extra)) {
            $extra = [];
        }

        // System-owned keys are never accepted from request data …
        foreach (array_keys($extra) as $key) {
            if (static::isSystemOtherInfoKey($key)) {
                unset($extra[$key]);
            }
        }

        // … and what is stored under them survives the replacement of the
        // rest, untouched.
        $systemOwned = [];
        foreach ($current as $key => $value) {
            if (static::isSystemOtherInfoKey($key)) {
                $systemOwned[$key] = $value;
            }
        }

        return array_merge($extra, $systemOwned) ?: null;
    }

    public static function getStatusCounts($postId = null)
    {
        $query = static::getQuery()->topLevel()->ofProduct($postId);

        $counts = $query->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')->get();

        $result = [
            'all'      => 0,
            'approved' => 0,
            'pending'  => 0,
            'spam'     => 0,
            'trash'    => 0,
        ];

        foreach ($counts as $row) {
            $status = $row->status;
            if (isset($result[$status])) {
                $result[$status] = (int) $row->count;
            }
            $result['all'] += (int) $row->count;
        }

        return $result;
    }

    /**
     * @param int|array $postIds Single post ID or array of post IDs
     */
    public static function recalculateProductRatings($postIds)
    {
        $postIds = array_unique(array_filter((array) $postIds));
        if (empty($postIds)) {
            return;
        }

        global $wpdb;

        // Aggregate rating stats from fct_product_reviews.
        // No transaction wrapper: the updates are idempotent (re-triggerable
        // via any status change).
        $postIds = array_map('intval', $postIds);

        // 1. Per-star breakdown for average + breakdown (only reviews with valid rating 1-5)
        $ratingRows = ProductReview::query()
            ->topLevel()
            ->approved()
            ->whereIn('post_id', $postIds)
            ->whereBetween('rating', [1, 5])
            ->groupBy('post_id', 'rating')
            ->selectRaw('post_id, rating, COUNT(*) as review_count')
            ->get();

        // 2. Total review count — ALL approved top-level reviews regardless of rating.
        //    Matches getStatusCounts().approved so product card and reviews page show same number.
        $countRows = ProductReview::query()
            ->topLevel()
            ->approved()
            ->whereIn('post_id', $postIds)
            ->groupBy('post_id')
            ->selectRaw('post_id, COUNT(*) as review_count')
            ->get();

        $totalCountMap = [];
        foreach ($countRows as $row) {
            $totalCountMap[(int) $row->post_id] = (int) $row->review_count;
        }

        // Build per-product rating stats from the breakdown rows
        $defaultBreakdown = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
        $ratingStatsMap = [];
        foreach ($ratingRows as $row) {
            $productId = (int) $row->post_id;
            if (!isset($ratingStatsMap[$productId])) {
                $ratingStatsMap[$productId] = [
                    'breakdown'    => $defaultBreakdown,
                    'rated_count'  => 0,
                    'weighted_sum' => 0,
                ];
            }
            $starValue = (int) $row->rating;
            $reviewCount = (int) $row->review_count;
            $ratingStatsMap[$productId]['breakdown'][$starValue] = $reviewCount;
            $ratingStatsMap[$productId]['rated_count'] += $reviewCount;
            $ratingStatsMap[$productId]['weighted_sum'] += $starValue * $reviewCount;
        }

        $details = ProductDetail::query()
            ->whereIn('post_id', $postIds)
            ->get()
            ->keyBy('post_id');

        foreach ($postIds as $postId) {
            $detail = $details->get($postId);
            if (!$detail) {
                continue;
            }

            $ratingStat = $ratingStatsMap[$postId] ?? null;
            $ratedCount = $ratingStat ? $ratingStat['rated_count'] : 0;
            $avgRating = $ratedCount > 0 ? round($ratingStat['weighted_sum'] / $ratedCount, 2) : 0.00;
            $breakdown = $ratingStat ? $ratingStat['breakdown'] : $defaultBreakdown;
            $totalCount = $totalCountMap[$postId] ?? 0;

            // Partial JSON_MERGE_PATCH update in a single atomic statement —
            // only the three keys we own are rewritten (each replaced
            // wholesale, matching PATCH semantics), so a concurrent writer of
            // a different other_info key (e.g. reviews_enabled from
            // ProductUpdateRequest) can never be clobbered by a PHP-side
            // read-then-merge-then-save race on the same JSON blob.
            // JSON_MERGE_PATCH (not JSON_SET + CAST(... AS JSON)) because
            // MariaDB has no native JSON type and rejects CAST(x AS JSON).
            //
            // The patch document is built with JSON_OBJECT() rather than a
            // bound json_encode() string: WPFluent's WPDBConnection converts
            // every double quote in a compiled query to a backtick (its
            // ANSI-identifier normalization is a blind str_replace), which
            // corrupts JSON text embedded in a raw fragment and makes the
            // statement fail with "Invalid JSON text". JSON_OBJECT() needs
            // no double quotes at all — keys are single-quoted SQL strings,
            // values are bound numbers — and exists on MySQL 5.7+ and
            // MariaDB 10.2.3+.
            $query = ProductDetail::query();
            $query->where('id', $detail->id)->update([
                'other_info' => $query->raw($wpdb->prepare(
                    "JSON_MERGE_PATCH(
                        IF(other_info IS NULL OR other_info = '', '{}', other_info),
                        JSON_OBJECT(
                            'average_rating', %f,
                            'review_count', %d,
                            'rating_breakdown', JSON_OBJECT('5', %d, '4', %d, '3', %d, '2', %d, '1', %d)
                        )
                    )",
                    $avgRating,
                    $totalCount,
                    $breakdown[5],
                    $breakdown[4],
                    $breakdown[3],
                    $breakdown[2],
                    $breakdown[1]
                )),
            ]);
        }
    }
}
