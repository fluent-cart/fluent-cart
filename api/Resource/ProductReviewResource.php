<?php

namespace FluentCart\Api\Resource;

use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductReview;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class ProductReviewResource extends BaseResourceApi
{
    /**
     * Depth counter tracking whether a resource operation is currently
     * mutating reviews, plus the products whose ratings that operation
     * still owes a recalculation.
     *
     * Resource methods mutate reviews through WordPress's native comment
     * functions, which fire wp_set_comment_status / edit_comment /
     * delete_comment — the same hooks ReviewCommentHandler listens to as a
     * safety net for changes made OUTSIDE this resource (native wp-admin
     * Comments actions, WP-CLI, other plugins). Without this mechanism,
     * every resource operation recalculated ratings twice, and bulkAction()
     * once per row PLUS its own final batched pass (50 approvals = up to
     * 51 passes instead of 1).
     *
     * Recalculation is DEFERRED, not suppressed: while a mutation is in
     * progress, both the resource methods and the hook handler record the
     * affected product IDs via deferProductRecalculation(); when the
     * outermost endReviewMutation() runs, the deduplicated union is
     * recalculated in one batched pass. Because WordPress hooks are
     * re-entrant, a third-party callback fired mid-operation can mutate a
     * review on a DIFFERENT product — deferral keeps that product's
     * aggregates correct where blanket suppression would silently lose it.
     *
     * An int depth (not a bool) so accidental nesting can never flush
     * early. A failed operation must call discardDeferredRecalculations()
     * before endReviewMutation() so a rolled-back mutation is not
     * recalculated as if it had committed.
     */
    protected static $reviewMutationDepth = 0;

    protected static $deferredRecalculationProductIds = [];

    public static function beginReviewMutation(): void
    {
        static::$reviewMutationDepth++;
    }

    public static function endReviewMutation(): void
    {
        static::$reviewMutationDepth = max(0, static::$reviewMutationDepth - 1);

        if (static::$reviewMutationDepth === 0 && static::$deferredRecalculationProductIds) {
            $productIds = array_values(static::$deferredRecalculationProductIds);
            static::$deferredRecalculationProductIds = [];
            static::recalculateProductRatings($productIds);
        }
    }

    public static function isReviewMutationInProgress(): bool
    {
        return static::$reviewMutationDepth > 0;
    }

    /**
     * Queue a product for the single batched recalculation that runs when
     * the outermost mutation ends. Keyed by ID, so repeats deduplicate.
     */
    public static function deferProductRecalculation($postId): void
    {
        $postId = (int) $postId;

        if ($postId > 0) {
            static::$deferredRecalculationProductIds[$postId] = $postId;
        }
    }

    /**
     * Drop the queued recalculations — for rollback paths, where the
     * mutations that queued them never committed.
     */
    public static function discardDeferredRecalculations(): void
    {
        static::$deferredRecalculationProductIds = [];
    }

    public static function getQuery(): Builder
    {
        return ProductReview::query();
    }

    public static function get(array $params = [])
    {
        $query = static::getQuery();

        $status = Arr::get($params, 'status', 'all');
        $postId = Arr::get($params, 'post_id');
        $rating = Arr::get($params, 'rating');
        $search = Arr::get($params, 'search');
        $sortBy = Arr::get($params, 'sort_by', 'id');
        $sortOrder = Arr::get($params, 'sort_order', 'DESC');
        $perPage = min(100, max(1, (int) Arr::get($params, 'per_page', 15)));
        $with = Arr::get($params, 'with', []);

        // Only show top-level reviews (not replies) in listings
        $query->topLevel();

        $query->ofStatus($status)
            ->ofProduct($postId)
            ->ofRating($rating);

        if (Arr::get($params, 'has_media')) {
            $query->whereExists(function ($sub) {
                $sub->from('commentmeta')
                    ->whereColumn('commentmeta.comment_id', 'comments.comment_ID')
                    ->where('commentmeta.meta_key', ProductReview::META_MEDIA);
            });
        }

        if (Arr::get($params, 'verified_only')) {
            $query->whereExists(function ($sub) {
                $sub->from('commentmeta')
                    ->whereColumn('commentmeta.comment_id', 'comments.comment_ID')
                    ->where('commentmeta.meta_key', ProductReview::META_IS_VERIFIED)
                    ->where('commentmeta.meta_value', '1');
            });
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
            $likeSearch = '%' . $wpdb->esc_like($search) . '%';

            $query->where(function ($q) use ($likeSearch) {
                $q->where('comment_content', 'LIKE', $likeSearch)
                    ->orWhere('comment_author', 'LIKE', $likeSearch)
                    ->orWhere('comment_author_email', 'LIKE', $likeSearch)
                    ->orWhereExists(function ($sub) use ($likeSearch) {
                        $sub->from('commentmeta')
                            ->whereColumn('commentmeta.comment_id', 'comments.comment_ID')
                            ->where('commentmeta.meta_key', ProductReview::META_TITLE)
                            ->where('commentmeta.meta_value', 'LIKE', $likeSearch);
                    })
                    ->orWhereHas('product', function ($productQuery) use ($likeSearch) {
                        $productQuery->where('post_title', 'LIKE', $likeSearch);
                    });
            });
        }

        // Split eager-load: ORM relations (product, replies) vs meta-based (customer, order)
        $ormRelations = [];
        $metaRelations = [];
        foreach ($with as $key => $value) {
            $name = is_int($key) ? $value : $key;
            if (in_array($name, ['customer', 'order'])) {
                $metaRelations[] = $name;
            } else {
                $ormRelations[$key] = $value;
            }
        }

        if (!empty($ormRelations)) {
            $query->with($ormRelations);
        }

        $sortOrder = in_array(strtoupper($sortOrder), ['ASC', 'DESC']) ? strtoupper($sortOrder) : 'DESC';

        // Column name mapping for sort
        $sortColumnMap = [
            'id'            => 'comment_ID',
            'created_at'    => 'comment_date_gmt',
            'reviewer_name' => 'comment_author',
        ];

        $defaultSortColumns = ['id', 'rating', 'created_at', 'reviewer_name'];
        $query = apply_filters('fluent_cart/review/sort_query', $query, $sortBy, $sortOrder);

        if (in_array($sortBy, $defaultSortColumns)) {
            if ($sortBy === 'rating') {
                $query->leftJoin("commentmeta as cm_sort_rating", function ($join) {
                    $join->on('cm_sort_rating.comment_id', '=', 'comments.comment_ID')
                        ->where('cm_sort_rating.meta_key', '=', ProductReview::META_RATING);
                });
                $query->orderByRaw('CAST(cm_sort_rating.meta_value AS UNSIGNED) ' . $sortOrder);
            } else {
                $resolvedCol = $sortColumnMap[$sortBy] ?? $sortBy;
                $query->orderBy($resolvedCol, $sortOrder);
            }

            // Tie-breaker on the primary key. Ratings and dates collide constantly, and
            // without a deterministic second key MySQL is free to order ties differently
            // per page — rows then repeat or vanish while paginating.
            //
            // Must be table-qualified: the rating sort joins commentmeta, whose
            // `comment_id` collides with `comment_ID` under MySQL's case-insensitive
            // column matching ("Column 'comment_ID' in order clause is ambiguous").
            if ($sortBy !== 'id') {
                $query->orderBy('comments.comment_ID', $sortOrder);
            }
        } elseif (!has_filter('fluent_cart/review/sort_query')) {
            $query->orderBy('comment_ID', $sortOrder);
        }

        $reviews = $query->paginate($perPage);

        // Batch-load meta-based relations (customer, order)
        if (!empty($metaRelations)) {
            ProductReview::loadMetaRelations($reviews, $metaRelations);
        }

        return $reviews;
    }

    public static function find($id, $params = [])
    {
        $with = Arr::get($params, 'with', ['product', 'customer', 'order', 'replies']);

        // ORM relationships only
        $ormWith = [];
        foreach ($with as $key => $value) {
            $name = is_int($key) ? $value : $key;
            if (in_array($name, ['product', 'replies'])) {
                $ormWith[$key] = $value;
            }
        }

        $review = static::getQuery()->with($ormWith)->find($id);

        if (!$review) {
            return static::makeErrorResponse([
                ['code' => 404, 'message' => __('Review not found', 'fluent-cart')]
            ]);
        }

        // Load meta-based relations via batch helper (no ORM relationship methods needed)
        $metaRelations = array_intersect(['customer', 'order'], $with);
        if (!empty($metaRelations)) {
            ProductReview::loadMetaRelations(
                new \FluentCart\Framework\Database\Orm\Collection([$review]),
                $metaRelations
            );
        }

        return $review;
    }

    public static function create($data, $params = [])
    {
        $postId = (int) ($data['post_id'] ?? 0);
        if ($postId < 1) {
            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('A valid product is required', 'fluent-cart')]
            ]);
        }

        // Validate parent_id belongs to the same product if provided
        $parentId = (int) ($data['parent_id'] ?? 0);
        if ($parentId) {
            $parent = static::getQuery()->find($parentId);
            if (!$parent || $parent->post_id !== $postId) {
                $parentId = 0;
            }
        }

        $commentData = [
            'comment_post_ID'      => $postId,
            'comment_parent'       => $parentId,
            'user_id'              => $data['user_id'] ?? 0,
            'comment_author'       => $data['reviewer_name'] ?? '',
            'comment_author_email' => $data['reviewer_email'] ?? '',
            'comment_content'      => $data['content'] ?? '',
            'comment_approved'     => ProductReview::translateStatusToWp($data['status'] ?? 'pending'),
            'comment_type'         => ProductReview::COMMENT_TYPE,
            'comment_date_gmt'     => gmdate('Y-m-d H:i:s'),
            'comment_date'         => current_time('mysql'),
            'comment_author_IP'    => !empty($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '',
            'comment_agent'        => 'FluentCart',
        ];

        // Suppress WordPress default comment notifications
        add_filter('notify_post_author', '__return_false');
        add_filter('notify_moderator', '__return_false');

        $commentId = wp_insert_comment($commentData);

        remove_filter('notify_post_author', '__return_false');
        remove_filter('notify_moderator', '__return_false');

        if (!$commentId) {
            return static::makeErrorResponse([
                ['code' => 400, 'message' => __('Failed to create review', 'fluent-cart')]
            ]);
        }

        static::saveCommentMeta($commentId, $data, true);

        $review = static::getQuery()->find($commentId);

        // Admin replies (rating=0, is_admin_reply=1) don't affect rating aggregates — skip recalculation.
        // This also prevents N redundant recalculations during bulkReply.
        if ($review && $review->status === 'approved' && !($data['is_admin_reply'] ?? false)) {
            static::recalculateProductRatings($review->post_id);
        }

        // The creation hook is fluent_cart/review_created, fired by the
        // ReviewCreated event with the standard array payload — matching the
        // fluent_cart/{entity}_{verb} event convention (order_created etc.).
        // A near-identical fluent_cart/review/created action used to fire
        // here with a bare model payload; it had no consumers and existed
        // only as a trap for extension authors grabbing the wrong one.

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

        // Update comment columns via wp_update_comment
        $columnMap = [
            'post_id'        => 'comment_post_ID',
            'parent_id'      => 'comment_parent',
            'user_id'        => 'user_id',
            'reviewer_name'  => 'comment_author',
            'reviewer_email' => 'comment_author_email',
            'content'        => 'comment_content',
        ];

        $commentUpdate = ['comment_ID' => $id];
        $hasColumnChanges = false;

        foreach ($columnMap as $virtual => $real) {
            if (array_key_exists($virtual, $data)) {
                $commentUpdate[$real] = $data[$virtual];
                $hasColumnChanges = true;
            }
        }

        if (array_key_exists('status', $data)) {
            $commentUpdate['comment_approved'] = ProductReview::translateStatusToWp($data['status']);
            $hasColumnChanges = true;
        }

        // wp_update_comment() fires edit_comment, whose handler would
        // recalculate unconditionally — this method recalculates itself
        // below, and only when status/rating actually changed.
        static::beginReviewMutation();

        try {
            if ($hasColumnChanges) {
                wp_update_comment($commentUpdate);
            }

            // Update meta fields via update_comment_meta
            static::saveCommentMeta($id, $data);

            // Reload to get fresh data
            $review = static::getQuery()->find($id);

            if (!$review) {
                return static::makeErrorResponse([
                    ['code' => 404, 'message' => __('Review not found after update', 'fluent-cart')]
                ]);
            }

            $statusChanged = $oldStatus !== $review->status;
            $ratingChanged = $oldRating !== $review->rating;

            // Rating-only edits go through update_comment_meta, which fires
            // no guarded hook — defer explicitly. The flush at
            // endReviewMutation() dedupes this against the edit_comment
            // deferral wp_update_comment() may have already queued.
            if ($statusChanged || $ratingChanged) {
                static::deferProductRecalculation($review->post_id);
            }
        } finally {
            static::endReviewMutation();
        }

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
            ->whereIn('comment_ID', $ids)
            ->topLevel()
            ->get();

        if ($reviews->isEmpty()) {
            return static::makeErrorResponse([
                ['code' => 404, 'message' => __('No valid reviews found', 'fluent-cart')]
            ]);
        }

        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            $replied = 0;
            foreach ($reviews as $review) {
                $replyData = array_merge($replyTemplate, [
                    'parent_id' => $review->id,
                    'post_id'   => $review->post_id,
                ]);

                $reply = static::create($replyData);

                if (is_wp_error($reply)) {
                    throw new \Exception($reply->get_error_message());
                }

                $replied++;
            }

            $wpdb->query('COMMIT');
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');

            // See bulkAction(): wp_insert_comment() primed the cache for rows the
            // rollback has now discarded.
            static::flushCommentCache($ids);

            return static::makeErrorResponse([
                ['code' => 500, 'message' => __('Failed to create replies', 'fluent-cart')]
            ]);
        }

        return static::makeSuccessResponse(
            ['replied' => $replied],
            sprintf(
                /* translators: %d - number of reviews replied to */
                __('Successfully replied to %d review(s).', 'fluent-cart'),
                $replied
            )
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
        $wasApproved = $review->status === 'approved';

        do_action('fluent_cart/review/before_delete', $review);

        static::beginReviewMutation();

        global $wpdb;
        $wpdb->query('START TRANSACTION');

        try {
            // Replies are deleted together with the review in one batch —
            // no orphaned comments, no per-row wp_delete_comment() cost.
            $replyIds = static::getQuery()
                ->where('comment_parent', $id)
                ->pluck('comment_ID')
                ->toArray();

            static::batchDeleteReviewRows(array_merge($replyIds, [$id]));

            // Batch deletion bypasses wp_delete_comment()'s per-row count
            // maintenance — recount the product once instead.
            wp_update_comment_count($postId);

            if ($wasApproved) {
                static::deferProductRecalculation($postId);
            }

            $wpdb->query('COMMIT');
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');

            // A rolled-back mutation must not be recalculated as committed,
            // and the object cache may hold state the rollback discarded.
            static::discardDeferredRecalculations();
            static::flushCommentCache(array_merge($replyIds ?? [], [$id]));
            static::endReviewMutation();

            return static::makeErrorResponse([
                ['code' => 500, 'message' => __('Failed to delete review', 'fluent-cart')]
            ]);
        }

        static::endReviewMutation();

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
        $ids = array_slice($ids, 0, 50);

        // Resolve the supplied IDs through the review scope and replace the
        // list with what actually matched — a mixed payload must never reach
        // wp_set_comment_status()/deletion for IDs that are ordinary WP
        // comments rather than reviews.
        $reviewRows = static::getQuery()
            ->whereIn('comment_ID', $ids)
            ->get();

        if ($reviewRows->isEmpty()) {
            return static::makeErrorResponse([
                ['code' => 404, 'message' => __('Reviews not found', 'fluent-cart')]
            ]);
        }

        $ids = array_map('intval', $reviewRows->pluck('comment_ID')->toArray());
        $affectedProductIds = array_values(array_unique(
            array_map('intval', $reviewRows->pluck('comment_post_ID')->toArray())
        ));

        $affectedCount = 0;

        global $wpdb;
        $wpdb->query('START TRANSACTION');

        // The per-row wp_set_comment_status() calls below each fire hooks
        // whose handler would recalculate per row — up to 50 passes. Inside
        // the mutation window those hook events defer instead, and
        // endReviewMutation() flushes one deduplicated batched pass after
        // COMMIT.
        static::beginReviewMutation();

        try {
            if ($action === 'delete') {
                $reviews = $reviewRows;
                $affectedCount = $reviews->count();

                // Batch-fetch all reply IDs in one query to avoid N+1
                $allReplyIds = static::getQuery()
                    ->whereIn('comment_parent', $ids)
                    ->pluck('comment_ID')
                    ->toArray();

                foreach ($reviews as $review) {
                    do_action('fluent_cart/review/before_delete', $review);
                }

                // Replies and parents deleted together in one batch — no
                // per-row wp_delete_comment() cost.
                static::batchDeleteReviewRows(array_merge(
                    $allReplyIds,
                    $reviews->pluck('comment_ID')->toArray()
                ));

                // Batch deletion bypasses wp_delete_comment()'s per-row
                // count maintenance — recount each product once.
                foreach ($affectedProductIds as $affectedProductId) {
                    wp_update_comment_count($affectedProductId);
                }
            } else {
                $statusMap = [
                    'approve' => '1',
                    'pending' => '0',
                    'spam'    => 'spam',
                    'trash'   => 'trash',
                ];
                foreach ($ids as $id) {
                    $result = wp_set_comment_status($id, $statusMap[$action]);
                    if ($result) {
                        $affectedCount++;
                    } else {
                        throw new \Exception(
                            sprintf(__('Failed to update review #%d', 'fluent-cart'), $id)
                        );
                    }
                }
            }

            // One batched recalculation for everything this operation touched
            // (plus anything re-entrant callbacks deferred) — flushed by
            // endReviewMutation() after COMMIT.
            foreach ($affectedProductIds as $affectedProductId) {
                static::deferProductRecalculation($affectedProductId);
            }

            $wpdb->query('COMMIT');
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');

            // A rolled-back mutation must not be recalculated as committed.
            static::discardDeferredRecalculations();

            // wp_set_comment_status()/wp_delete_comment() update the object cache as they
            // run, and ROLLBACK only reverts the database. Without this the cache keeps
            // the statuses the transaction just threw away and the admin list renders
            // state that no longer exists in the DB.
            static::flushCommentCache($ids);

            return static::makeErrorResponse([
                ['code' => 500, 'message' => __('Bulk action failed', 'fluent-cart')]
            ]);
        } finally {
            static::endReviewMutation();
        }

        return static::makeSuccessResponse(
            ['affected' => $affectedCount],
            __('Bulk action completed successfully', 'fluent-cart')
        );
    }

    /**
     * Drop cached comment objects and their meta whenever the DB and the object
     * cache have been made to disagree: after a rolled-back transaction (WP
     * comment functions write to the cache as a side effect, which ROLLBACK
     * cannot undo) and after batchDeleteReviewRows() (batch SQL skips the
     * per-row clean_comment_cache() that wp_delete_comment() would have done).
     * Without it, get_comment()/meta reads keep serving rows the database no
     * longer agrees with.
     *
     * @param array $ids Parent review IDs touched by the batch
     */
    protected static function flushCommentCache(array $ids)
    {
        $ids = array_map('intval', array_filter($ids));

        if (empty($ids)) {
            return;
        }

        // Replies are cached under their own IDs, so clear them alongside the parents.
        $replyIds = static::getQuery()
            ->whereIn('comment_parent', $ids)
            ->pluck('comment_ID')
            ->toArray();

        $allIds = array_unique(array_merge($ids, array_map('intval', $replyIds)));

        clean_comment_cache($allIds);
        wp_cache_delete_multiple($allIds, 'comment_meta');
    }

    /**
     * Delete review rows and their meta in two batched statements instead of
     * one wp_delete_comment() round-trip per row — wp_delete_comment() runs
     * per-comment meta deletes, cache clears, count updates, and hook rounds,
     * so a 50-row bulk delete pays for everything 50 times.
     *
     * Reviews are an isolated comment_type that never renders in native
     * comment surfaces, and the plugin's own extension point
     * (fluent_cart/review/before_delete) is fired by the callers before this
     * runs — WP's per-comment delete hooks are not load-bearing here.
     *
     * Callers are responsible for firing before_delete first, and for
     * updating post comment counts / recalculating ratings afterwards.
     */
    protected static function batchDeleteReviewRows(array $commentIds): void
    {
        $commentIds = array_map('intval', array_filter($commentIds));

        if (empty($commentIds)) {
            return;
        }

        global $wpdb;

        // Raw SQL: wp_commentmeta has no model, and WP's meta API only deletes per-object.
        $placeholders = implode(',', array_fill(0, count($commentIds), '%d'));
        $metaDeleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ({$placeholders})",
            $commentIds
        ));

        // $wpdb->query() reports failure as false without throwing; surface it
        // so the caller's transaction rolls back instead of half-deleting.
        // (The ORM delete below throws through the connection on its own.)
        if ($metaDeleted === false) {
            throw new \Exception('Failed to delete review meta rows: ' . $wpdb->last_error);
        }

        static::getQuery()->whereIn('comment_ID', $commentIds)->delete();

        // Batch SQL skips wp_delete_comment()'s per-row cache cleanup — without
        // this, get_comment()/meta reads keep serving the deleted rows as ghosts.
        static::flushCommentCache($commentIds);
    }

    /**
     * Save meta fields to wp_commentmeta for the given data.
     *
     * @param int   $commentId
     * @param array $data
     * @param bool  $includeTrustFields When true, allows saving is_verified, is_admin_reply,
     *                                  customer_id, and order_id. Only pass true from create()
     *                                  where these values are server-computed.
     */
    protected static function saveCommentMeta($commentId, array $data, $includeTrustFields = false)
    {
        $metaMap = [
            'rating' => ProductReview::META_RATING,
            'title'  => ProductReview::META_TITLE,
            'meta'   => ProductReview::META_DATA,
        ];

        if ($includeTrustFields) {
            $metaMap['is_verified']    = ProductReview::META_IS_VERIFIED;
            $metaMap['is_admin_reply'] = ProductReview::META_IS_ADMIN_REPLY;
            $metaMap['customer_id']    = ProductReview::META_CUSTOMER_ID;
            $metaMap['order_id']       = ProductReview::META_ORDER_ID;
        }

        foreach ($metaMap as $key => $metaKey) {
            if (array_key_exists($key, $data)) {
                $value = $data[$key];
                if ($key === 'rating') {
                    $value = max(0, min(5, (int) $value));
                }
                if ($key === 'meta' && (is_array($value) || is_object($value))) {
                    $value = wp_json_encode($value);
                }
                update_comment_meta($commentId, $metaKey, $value);
            }
        }
    }

    public static function getStatusCounts($postId = null)
    {
        $query = static::getQuery()->topLevel();

        if ($postId) {
            $query->where('comment_post_ID', $postId);
        }

        $counts = $query->selectRaw('comment_approved, COUNT(*) as count')
            ->groupBy('comment_approved')->get();

        $result = [
            'all'          => 0,
            'approved'     => 0,
            'pending'      => 0,
            'spam'         => 0,
            'trash'        => 0,
            'post-trashed' => 0,
        ];

        foreach ($counts as $row) {
            $status = ProductReview::translateStatusFromWp($row->comment_approved);
            $result[$status] = (int) $row->count;
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

        // Aggregate rating stats from wp_comments + wp_commentmeta
        // No transaction wrapper: $wpdb queries run on a separate connection from the ORM,
        // and the updates are idempotent (re-triggerable via any status change).
        $postIds = array_map('intval', $postIds);
        $idPlaceholders = implode(',', array_fill(0, count($postIds), '%d'));

        // 1. Per-star breakdown for average + breakdown (only reviews with valid rating 1-5)
        $ratingRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT c.comment_post_ID as post_id,
                        cm_r.meta_value as rating,
                        COUNT(*) as cnt
                 FROM `{$wpdb->comments}` c
                 INNER JOIN `{$wpdb->commentmeta}` cm_r
                     ON c.comment_ID = cm_r.comment_id AND cm_r.meta_key = %s
                 LEFT JOIN `{$wpdb->commentmeta}` cm_a
                     ON c.comment_ID = cm_a.comment_id AND cm_a.meta_key = %s
                 WHERE c.comment_type = %s
                   AND c.comment_approved = '1'
                   AND c.comment_parent = 0
                   AND cm_r.meta_value IN ('1','2','3','4','5')
                   AND (cm_a.meta_value IS NULL OR cm_a.meta_value = '0')
                   AND c.comment_post_ID IN ({$idPlaceholders})
                 GROUP BY c.comment_post_ID, cm_r.meta_value",
                array_merge(
                    [ProductReview::META_RATING, ProductReview::META_IS_ADMIN_REPLY, ProductReview::COMMENT_TYPE],
                    $postIds
                )
            )
        );

        // 2. Total review count — ALL approved top-level reviews regardless of rating
        //    Matches getStatusCounts().approved so product card and reviews page show same number.
        $countRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT comment_post_ID as post_id, COUNT(*) as cnt
                 FROM `{$wpdb->comments}`
                 WHERE comment_type = %s
                   AND comment_approved = '1'
                   AND comment_parent = 0
                   AND comment_post_ID IN ({$idPlaceholders})
                 GROUP BY comment_post_ID",
                array_merge(
                    [ProductReview::COMMENT_TYPE],
                    $postIds
                )
            )
        );

        $totalCountMap = [];
        foreach ($countRows as $row) {
            $totalCountMap[(int) $row->post_id] = (int) $row->cnt;
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
            $reviewCount = (int) $row->cnt;
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
