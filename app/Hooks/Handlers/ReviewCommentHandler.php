<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\Api\Resource\ProductReviewResource;
use FluentCart\App\Models\ProductReview;

/**
 * Isolates FluentCart product reviews from WordPress's native comment system.
 *
 * Since reviews are stored in wp_comments with comment_type = 'fct_product_review',
 * we need to ensure they don't appear in:
 * - WP Admin > Comments page
 * - Dashboard comment counts
 * - Post comment_count values
 * - Theme comment templates
 */
class ReviewCommentHandler
{
    public function register()
    {
        add_filter('pre_get_comments', [$this, 'excludeFromDefaultQueries']);
        add_filter('wp_count_comments', [$this, 'excludeFromCounts'], 10, 2);
        add_filter('comment_feed_where', [$this, 'excludeFromFeeds'], 10, 2);
        add_action('wp_set_comment_status', [$this, 'onCommentStatusChange'], 10, 2);
        add_action('edit_comment', [$this, 'onCommentEdit'], 10, 2);
        add_action('delete_comment', [$this, 'onCommentDelete'], 10, 2);
    }

    /**
     * Exclude fluentcart_product_review from all default WP comment queries
     * unless explicitly requested.
     */
    public function excludeFromDefaultQueries($query)
    {
        $commentType = ProductReview::COMMENT_TYPE;

        // If someone is explicitly querying for our comment type, allow it
        if (isset($query->query_vars['type']) && $query->query_vars['type'] === $commentType) {
            return $query;
        }

        // If type__in explicitly includes our type, allow it
        if (!empty($query->query_vars['type__in']) && in_array($commentType, (array) $query->query_vars['type__in'])) {
            return $query;
        }

        // Otherwise, exclude our comment type
        if (empty($query->query_vars['type__not_in']) || !is_array($query->query_vars['type__not_in'])) {
            $query->query_vars['type__not_in'] = [];
        }

        if (!in_array($commentType, $query->query_vars['type__not_in'])) {
            $query->query_vars['type__not_in'][] = $commentType;
        }

        return $query;
    }

    /**
     * Exclude reviews from WordPress dashboard comment counts.
     */
    public function excludeFromCounts($counts, $postId)
    {
        // Only override global counts (not per-post)
        if ($postId !== 0) {
            return $counts;
        }

        $cacheKey = 'fct_wp_comment_counts_no_reviews';
        $cached = wp_cache_get($cacheKey, 'fct_reviews');

        if ($cached !== false) {
            return $cached;
        }

        $stats = ProductReview::withoutGlobalScope('comment_type')
            ->where('comment_type', '!=', ProductReview::COMMENT_TYPE)
            ->selectRaw('comment_approved, COUNT(*) as total')
            ->groupBy('comment_approved')
            ->get();

        // wp_count_comments() expects stdClass with these exact properties
        $counts = new \stdClass();
        $counts->approved = 0;
        $counts->moderated = 0;
        $counts->spam = 0;
        $counts->trash = 0;
        $counts->{'post-trashed'} = 0;
        $counts->total_comments = 0;
        $counts->all = 0;

        foreach ($stats as $row) {
            switch ($row->comment_approved) {
                case '1':
                    $counts->approved = (int) $row->total;
                    break;
                case '0':
                    $counts->moderated = (int) $row->total;
                    break;
                case 'spam':
                    $counts->spam = (int) $row->total;
                    break;
                case 'trash':
                    $counts->trash = (int) $row->total;
                    break;
                case 'post-trashed':
                    $counts->{'post-trashed'} = (int) $row->total;
                    break;
            }
        }

        $counts->all = $counts->approved + $counts->moderated;
        $counts->total_comments = $counts->all + $counts->spam;

        wp_cache_set($cacheKey, $counts, 'fct_reviews', 300);

        return $counts;
    }

    /**
     * Exclude reviews from comment feeds.
     */
    public function excludeFromFeeds($where, $query)
    {
        global $wpdb;

        $commentType = ProductReview::COMMENT_TYPE;
        $where .= $wpdb->prepare(" AND comment_type != %s", $commentType);

        return $where;
    }

    /**
     * Recalculate product ratings when a review status changes via WordPress native functions.
     *
     * Fires on wp_set_comment_status (approve, hold, spam, trash, delete).
     */
    public function onCommentStatusChange($commentId, $status)
    {
        $comment = get_comment($commentId);

        if (!$comment || $comment->comment_type !== ProductReview::COMMENT_TYPE) {
            return;
        }

        wp_cache_delete('fct_wp_comment_counts_no_reviews', 'fct_reviews');

        // While a resource operation is mutating reviews, this hook fires
        // once per row — recalculating here would multiply the work by the
        // batch size. Defer instead of recalculating: the operation flushes
        // the deduplicated union in one batched pass when it ends, which
        // also keeps products touched by re-entrant third-party callbacks
        // correct (blanket suppression would silently lose them).
        if (ProductReviewResource::isReviewMutationInProgress()) {
            ProductReviewResource::deferProductRecalculation((int) $comment->comment_post_ID);
            return;
        }

        ProductReviewResource::recalculateProductRatings((int) $comment->comment_post_ID);
    }

    /**
     * Recalculate product ratings when a review is edited via WordPress native functions.
     *
     * Fires on edit_comment (e.g. rating changed via wp-admin).
     */
    public function onCommentEdit($commentId, $data)
    {
        $comment = get_comment($commentId);

        if (!$comment || $comment->comment_type !== ProductReview::COMMENT_TYPE) {
            return;
        }

        wp_cache_delete('fct_wp_comment_counts_no_reviews', 'fct_reviews');

        // See onCommentStatusChange() — defer during resource operations.
        if (ProductReviewResource::isReviewMutationInProgress()) {
            ProductReviewResource::deferProductRecalculation((int) $comment->comment_post_ID);
            return;
        }

        ProductReviewResource::recalculateProductRatings((int) $comment->comment_post_ID);
    }

    /**
     * Recalculate product ratings when a review is deleted via WordPress native functions.
     *
     * Fires on delete_comment — covers WP admin bulk delete, WP-CLI, and other plugins.
     * The comment object is fetched before WordPress removes it from the database.
     */
    public function onCommentDelete($commentId, $comment)
    {
        if (!$comment || $comment->comment_type !== ProductReview::COMMENT_TYPE) {
            return;
        }

        wp_cache_delete('fct_wp_comment_counts_no_reviews', 'fct_reviews');

        // See onCommentStatusChange() — defer during resource operations.
        if (ProductReviewResource::isReviewMutationInProgress()) {
            ProductReviewResource::deferProductRecalculation((int) $comment->comment_post_ID);
            return;
        }

        ProductReviewResource::recalculateProductRatings((int) $comment->comment_post_ID);
    }
}
