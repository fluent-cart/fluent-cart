<?php

namespace FluentCart\App\Http\Controllers\FrontendControllers;

use FluentCart\Api\Resource\ProductReviewResource;
use FluentCart\App\Events\ReviewCreated;
use FluentCart\App\Http\Requests\FrontendRequests\ReviewRequest;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\Framework\Http\Request\Request;

class ProductReviewFrontendController extends BaseFrontendController
{
    public function getReviews(Request $request, $postId)
    {
        $postId = intval($postId);

        // Only allow reading reviews for published products
        if (!Product::query()->where('post_status', 'publish')->find($postId)) {
            return $this->sendError([
                'message' => __('Product not found', 'fluent-cart'),
            ], 404);
        }

        $data = [
            'per_page'      => intval($request->get('per_page', 10)),
            'sort_by'       => sanitize_text_field($request->get('sort_by', 'created_at')),
            'sort_order'    => sanitize_text_field($request->get('sort_order', 'DESC')),
            'rating'        => intval($request->get('rating', 0)),
            'has_media'     => intval($request->get('has_media', 0)),
            'verified_only' => intval($request->get('verified_only', 0)),
        ];

        $settings = ProductReviewService::getReviewSettings();
        $perPage = max(1, min(
            !empty($data['per_page']) ? (int) $data['per_page'] : 10,
            (int) $settings['reviews_per_page']
        ));

        $params = [
            'post_id'    => $postId,
            'status'     => 'approved',
            'sort_by'    => $data['sort_by'] ?? 'created_at',
            'sort_order' => $data['sort_order'] ?? 'DESC',
            'per_page'   => $perPage,
        ];

        $rating = !empty($data['rating']) ? (int) $data['rating'] : null;
        if ($rating && $rating >= 1 && $rating <= 5) {
            $params['rating'] = $rating;
        }

        if (!empty($data['has_media'])) {
            $params['has_media'] = true;
        }

        if (!empty($data['verified_only'])) {
            $params['verified_only'] = true;
        }

        $reviews = ProductReviewResource::get($params);

        // Append avatar URLs + reply counts, hide sensitive fields
        // Replies are NOT eager-loaded — fetched on-demand via getReplies endpoint
        if ($reviews && method_exists($reviews, 'items')) {
            $avatarCache = [];
            $currentUserId = get_current_user_id();
            $items = $reviews->items();

            // Batch reply counts in a single query, keyed by parent_id
            $reviewIds = array_map(fn($r) => (int) $r->id, $items);
            $replyCounts = [];
            if (!empty($reviewIds)) {
                global $wpdb;
                $placeholders = implode(',', array_fill(0, count($reviewIds), '%d'));
                $rows = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT comment_parent, COUNT(*) AS cnt
                         FROM {$wpdb->comments}
                         WHERE comment_type = %s
                           AND comment_approved = '1'
                           AND comment_parent IN ({$placeholders})
                         GROUP BY comment_parent",
                        array_merge([ProductReview::COMMENT_TYPE], $reviewIds)
                    )
                );
                foreach ($rows as $row) {
                    $replyCounts[(int) $row->comment_parent] = (int) $row->cnt;
                }
            }

            foreach ($items as $review) {
                $email = $review->reviewer_email;
                if (!isset($avatarCache[$email])) {
                    $avatarCache[$email] = get_avatar_url($email, ['size' => 48, 'default' => 'mm']);
                }
                $review->avatar_url = $avatarCache[$email];
                $review->is_owner = $currentUserId && (int) $review->user_id === $currentUserId;
                $review->reply_count = $replyCounts[(int) $review->id] ?? 0;
                $review->makeHidden(['reviewer_email', 'user_id', 'customer_id', 'order_id', 'meta']);
            }
        }

        $responseData = [
            'reviews' => $reviews->toArray(),
        ];

        $responseData = apply_filters('fluent_cart/review/public_response', $responseData, $postId);

        return $this->sendSuccess($responseData);
    }

    public function getRatingSummary(Request $request, $postId)
    {
        $postId = intval($postId);

        if (!Product::query()->where('post_status', 'publish')->find($postId)) {
            return $this->sendError([
                'message' => __('Product not found', 'fluent-cart'),
            ], 404);
        }

        $summary = ProductReviewService::getProductRatingSummary($postId);

        // Check if current user can submit a review
        $canSubmit = ProductReviewService::canSubmitReview($postId, $request);

        return $this->sendSuccess([
            'summary'    => $summary,
            'can_submit' => $canSubmit,
        ]);
    }

    public function submitReview(ReviewRequest $request, $postId)
    {
        // No explicit nonce check here, deliberately. This endpoint accepts guest
        // submissions in 'anyone' permission mode, and the nonce is rendered into the
        // page HTML — under full-page caching it outlives its 12-24h validity and a
        // hard check would reject legitimate guest reviews.
        //
        // CSRF is already neutralised upstream: these are register_rest_route() routes,
        // so WP's rest_cookie_check_errors() calls wp_set_current_user(0) on a nonce-less
        // request. A forged cross-site POST therefore arrives with no identity to abuse
        // and lands on the guest path, which requires a name and email and is rate
        // limited and moderated. updateReview() and submitReply() do check the nonce —
        // both require an authenticated user, where a stale nonce is not a concern
        // because caches bypass logged-in requests.
        $postId = intval($postId);

        // Rate limit: max 5 review submissions per identity per hour.
        $ip = !empty($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '';
        $userId = get_current_user_id();

        if ($userId) {
            $identity = 'u' . $userId;
        } elseif ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
            $identity = 'ip_' . md5($ip);
        } else {
            // No usable identity means the limit cannot be enforced. Deny rather than
            // fall through unlimited — an unattributable submission is the one case
            // where skipping the limit would be most costly.
            return $this->sendError([
                'message' => __('Unable to verify identity.', 'fluent-cart'),
            ], 400);
        }

        // wp_cache_incr is atomic on a persistent object cache (Redis/Memcached) —
        // the increment and the read happen as one operation, closing the
        // count-then-insert race a DB count() query followed by an insert would
        // have. On the default non-persistent cache the cache only lives for the
        // current request, so this degrades to an in-process counter and the
        // race reopens across separate PHP processes; this is a documented,
        // accepted limitation on sites without a persistent object cache.
        $rateLimitKey = 'fct_review_rate_' . $identity;
        $count = wp_cache_incr($rateLimitKey, 1, 'fluent_cart_reviews');
        if ($count === false) {
            wp_cache_set($rateLimitKey, 1, 'fluent_cart_reviews', HOUR_IN_SECONDS);
            $count = 1;
        }

        if ($count > 5) {
            return $this->sendError([
                'message' => __('Too many submissions. Please try again later.', 'fluent-cart'),
            ], 429);
        }

        // Verify product exists and is published
        $product = Product::query()->where('post_status', 'publish')->find($postId);
        if (!$product) {
            return $this->sendError([
                'message' => __('Product not found', 'fluent-cart'),
            ], 404);
        }

        // Check if user can submit review
        $canSubmit = ProductReviewService::canSubmitReview($postId, $request);
        $canSubmit = apply_filters('fluent_cart/review/can_submit', $canSubmit, $postId, $request);
        if (!is_array($canSubmit) || !$canSubmit['can_submit']) {
            return $this->sendError([
                'message' => $canSubmit['message'],
            ], 403);
        }

        $data = $request->getSafe($request->sanitize());
        $settings = ProductReviewService::getReviewSettings();

        if (empty(trim($data['content'] ?? ''))) {
            return $this->sendError([
                'message' => __('Review content is required.', 'fluent-cart'),
            ], 422);
        }

        // Clamp rating to valid range, then enforce requirement based on settings
        $rating = isset($data['rating']) ? max(0, min(5, (int) $data['rating'])) : 0;
        $starEnabled = !isset($settings['enable_star_rating']) || $settings['enable_star_rating'] === 'yes';
        if ($starEnabled && (!isset($settings['star_rating_required']) || $settings['star_rating_required'] === 'yes') && $rating < 1) {
            return $this->sendError([
                'message' => __('Star rating is required', 'fluent-cart'),
            ], 422);
        }

        $reviewData = [
            'post_id'        => $postId,
            'rating'         => $rating,
            'title'          => isset($data['title']) ? $data['title'] : '',
            'content'        => $data['content'],
            'status'         => $settings['auto_approve_reviews'] === 'yes' ? 'approved' : 'pending',
            'is_verified'    => 0,
            'user_id'        => $userId ?: 0,
            'customer_id'    => null,
            'order_id'       => null,
            'reviewer_name'  => '',
            'reviewer_email' => '',
        ];

        // Set reviewer info based on logged in user or guest
        if ($userId) {
            $user = get_userdata($userId);
            $reviewData['reviewer_name'] = $user ? $user->display_name : '';
            $reviewData['reviewer_email'] = $user ? $user->user_email : '';

            $customer = Customer::query()->where('user_id', $userId)->first();
            if ($customer) {
                $reviewData['customer_id'] = $customer->id;

                // Check if verified purchase
                if (ProductReviewService::isVerifiedPurchase($postId, $customer->id)) {
                    $reviewData['is_verified'] = 1;
                }
            }
        } else {
            $reviewerName = isset($data['reviewer_name']) ? trim($data['reviewer_name']) : '';
            $reviewerEmail = isset($data['reviewer_email']) ? trim($data['reviewer_email']) : '';

            if (empty($reviewerName)) {
                return $this->sendError([
                    'message' => __('Name is required', 'fluent-cart'),
                ], 422);
            }
            if (empty($reviewerEmail) || !is_email($reviewerEmail)) {
                return $this->sendError([
                    'message' => __('A valid email address is required', 'fluent-cart'),
                ], 422);
            }

            $reviewData['reviewer_name'] = $reviewerName;
            $reviewData['reviewer_email'] = $reviewerEmail;
        }

        // Preserve server-computed trust fields before exposing to filter
        $trustFields = [
            'post_id'        => $postId,
            'status'         => $reviewData['status'],
            'is_verified'    => $reviewData['is_verified'],
            'is_admin_reply' => 0,
            'user_id'        => $reviewData['user_id'],
            'customer_id'    => $reviewData['customer_id'],
            'order_id'       => $reviewData['order_id'],
        ];

        $reviewData = apply_filters('fluent_cart/review/submit_data', $reviewData, $request, $postId);

        // Enforce trust fields — these must never come from user input or filters
        $reviewData = array_merge($reviewData, $trustFields);

        // Claim the (product, identity) slot atomically so a second concurrent
        // request for the same product + user/email cannot slip past the
        // duplicate check above before this one finishes inserting.
        $lock = ProductReviewService::claimReviewSlot($postId, $userId, $reviewData['reviewer_email']);
        if (!$lock) {
            return $this->sendError([
                'message' => $userId
                    ? __('You have already submitted a review for this product', 'fluent-cart')
                    : __('A review with this email already exists for this product', 'fluent-cart'),
            ], 409);
        }

        try {
            $review = ProductReviewResource::create($reviewData);
        } finally {
            ProductReviewService::releaseReviewSlot($lock);
        }

        if (is_wp_error($review)) {
            return $review;
        }

        // Run after_submit hooks first (media attachment, etc.) so they complete
        // before the event dispatch which may trigger email notifications
        do_action('fluent_cart/review/after_submit', $review, $request);

        // Dispatch event (triggers email notifications)
        (new ReviewCreated($review))->dispatch();

        $message = $reviewData['status'] === 'approved'
            ? __('Thank you for your review!', 'fluent-cart')
            : __('Thank you! Your review has been submitted and is pending approval.', 'fluent-cart');

        $message = apply_filters('fluent_cart/review/submit_success_message', $message, $review);

        // Match the hiding applied by getReviews — the create response must not be the
        // one path that serialises reviewer_email and the internal id columns.
        if ($review && method_exists($review, 'makeHidden')) {
            $review->makeHidden(['reviewer_email', 'user_id', 'customer_id', 'order_id', 'meta']);
        }

        return $this->sendSuccess([
            'message' => $message,
            'review'  => $review,
        ]);
    }

    public function updateReview(ReviewRequest $request, $postId, $reviewId)
    {
        // CSRF protection — verify WordPress REST nonce (see submitReview).
        if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) {
            return $this->sendError([
                'message' => __('Session expired. Please refresh and try again.', 'fluent-cart'),
            ], 403);
        }

        $postId = intval($postId);
        $reviewId = intval($reviewId);
        $userId = get_current_user_id();

        if (!$userId) {
            return $this->sendError([
                'message' => __('You must be logged in to update a review', 'fluent-cart'),
            ], 403);
        }

        // Verify the review exists and belongs to the current user
        $review = ProductReview::query()
            ->where('comment_ID', $reviewId)
            ->where('comment_post_ID', $postId)
            ->where('user_id', $userId)
            ->first();

        if (!$review) {
            return $this->sendError([
                'message' => __('Review not found or you do not have permission to edit it', 'fluent-cart'),
            ], 404);
        }

        $data = $request->getSafe($request->sanitize());
        $settings = ProductReviewService::getReviewSettings();

        // Fall back to the stored rating when the client omits it, so a content-only
        // edit is not rejected by the "star rating is required" gate below and is not
        // silently downgraded to zero stars.
        $rating = array_key_exists('rating', $data)
            ? max(0, min(5, (int) $data['rating']))
            : (int) $review->rating;

        $starEnabled = !isset($settings['enable_star_rating']) || $settings['enable_star_rating'] === 'yes';
        if ($starEnabled && (!isset($settings['star_rating_required']) || $settings['star_rating_required'] === 'yes') && $rating < 1) {
            return $this->sendError([
                'message' => __('Star rating is required', 'fluent-cart'),
            ], 422);
        }

        // Build from what the client actually sent. Assigning content unconditionally
        // would null out the stored body whenever a partial edit omits it.
        $updateData = [];

        if (array_key_exists('content', $data)) {
            $content = trim((string) $data['content']);
            if ($content === '') {
                return $this->sendError([
                    'message' => __('Review content is required.', 'fluent-cart'),
                ], 422);
            }
            $updateData['content'] = $content;
        }
        if (array_key_exists('rating', $data)) {
            $updateData['rating'] = $rating;
        }
        if (array_key_exists('title', $data)) {
            $updateData['title'] = $data['title'];
        }

        if (empty($updateData)) {
            return $this->sendError([
                'message' => __('Nothing to update.', 'fluent-cart'),
            ], 422);
        }

        $updateData = apply_filters('fluent_cart/review/update_data', $updateData, $request, $postId, $reviewId);

        // Whitelist: only allow these fields through — everything else is dropped.
        // Filters must not change ownership, status, or product association.
        $allowedUpdateFields = ['content', 'rating', 'title'];
        $updateData = array_intersect_key($updateData, array_flip($allowedUpdateFields));

        // Re-moderate after the whitelist so neither the client nor a filter can choose
        // the status. Without this, an approved review can be edited to arbitrary content
        // and stay published — one approval would grant ongoing unmoderated publishing.
        if ($settings['auto_approve_reviews'] !== 'yes' && $review->status === 'approved') {
            $updateData['status'] = 'pending';
        }

        $result = ProductReviewResource::update($updateData, $reviewId);

        if (is_wp_error($result)) {
            return $result;
        }

        do_action('fluent_cart/review/after_update', $result, $request);

        // Tell the reviewer their edit is queued again — otherwise the review silently
        // disappears from the public list after a successful save.
        $message = isset($updateData['status']) && $updateData['status'] === 'pending'
            ? __('Your review has been updated and is pending approval.', 'fluent-cart')
            : __('Your review has been updated!', 'fluent-cart');

        return $this->sendSuccess([
            'message' => $message,
            'review'  => $result,
        ]);
    }

    /**
     * Get paginated replies for a single review.
     * Called when opening the thread modal — keeps the list endpoint lightweight.
     */
    public function getReplies(Request $request, $postId, $reviewId)
    {
        $postId = intval($postId);
        $reviewId = intval($reviewId);
        $perPage = min(100, max(1, intval($request->get('per_page', 50))));
        $page = max(1, intval($request->get('page', 1)));

        // Only allow reading replies for published products (matches getReviews gate)
        if (!Product::query()->where('post_status', 'publish')->find($postId)) {
            return $this->sendError([
                'message' => __('Product not found', 'fluent-cart'),
            ], 404);
        }

        // Verify parent review exists, belongs to product, is approved + top-level
        $review = ProductReview::query()
            ->where('comment_ID', $reviewId)
            ->where('comment_post_ID', $postId)
            ->where('comment_parent', 0)
            ->where('comment_approved', '1')
            ->first();

        if (!$review) {
            return $this->sendError([
                'message' => __('Review not found', 'fluent-cart'),
            ], 404);
        }

        $replies = ProductReview::query()
            ->where('comment_parent', $reviewId)
            ->where('comment_approved', '1')
            ->orderBy('comment_date_gmt', 'ASC')
            ->orderBy('comment_ID', 'ASC')
            ->paginate($perPage, ['*'], 'page', $page);

        $avatarCache = [];
        foreach ($replies->items() as $reply) {
            $email = $reply->reviewer_email;
            if ($email && !isset($avatarCache[$email])) {
                $avatarCache[$email] = get_avatar_url($email, ['size' => 48, 'default' => 'mm']);
            }
            $reply->avatar_url = $email ? $avatarCache[$email] : '';
            $reply->makeHidden(['reviewer_email', 'user_id', 'customer_id', 'order_id', 'meta']);
        }

        return $this->sendSuccess([
            'replies' => $replies->toArray(),
        ]);
    }

    /**
     * Reviewer adds a follow-up reply to their own approved review.
     * Only the original reviewer (matched by user_id) can reply.
     */
    public function submitReply(ReviewRequest $request, $postId, $reviewId)
    {
        // CSRF protection — verify WordPress REST nonce
        if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) {
            return $this->sendError([
                'message' => __('Session expired. Please refresh and try again.', 'fluent-cart'),
            ], 403);
        }

        $postId = intval($postId);
        $reviewId = intval($reviewId);
        $userId = get_current_user_id();

        if (!$userId) {
            return $this->sendError([
                'message' => __('You must be logged in to reply', 'fluent-cart'),
            ], 403);
        }

        // Only allow replying on published products (matches public read endpoints)
        if (!Product::query()->where('post_status', 'publish')->find($postId)) {
            return $this->sendError([
                'message' => __('Product not found', 'fluent-cart'),
            ], 404);
        }

        // Find the parent review and verify ownership + approval
        $review = ProductReview::query()
            ->where('comment_ID', $reviewId)
            ->where('comment_post_ID', $postId)
            ->where('comment_parent', 0)
            ->first();

        if (!$review) {
            return $this->sendError([
                'message' => __('Review not found', 'fluent-cart'),
            ], 404);
        }

        if ((int) $review->user_id !== $userId) {
            return $this->sendError([
                'message' => __('You can only reply to your own review', 'fluent-cart'),
            ], 403);
        }

        if ($review->status !== 'approved') {
            return $this->sendError([
                'message' => __('Replies are only allowed on approved reviews', 'fluent-cart'),
            ], 403);
        }

        $data = $request->getSafe($request->sanitize());
        $content = trim($data['content'] ?? '');

        if (empty($content)) {
            return $this->sendError([
                'message' => __('Reply content is required', 'fluent-cart'),
            ], 422);
        }

        // Rate limit: max 10 replies per user per hour across ALL reviews.
        // Scoping the count to a single review made the ceiling 10 x (reviews the user
        // owns), which for a real catalogue is effectively no limit at all.
        $rateLimitWindow = gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS);
        $recentRepliesQuery = ProductReview::query()
            ->where('comment_type', ProductReview::COMMENT_TYPE)
            ->where('comment_parent', '>', 0)
            ->where('user_id', $userId)
            ->where('comment_date_gmt', '>=', $rateLimitWindow);

        if ($recentRepliesQuery->count() >= 10) {
            return $this->sendError([
                'message' => __('Too many replies. Please try again later.', 'fluent-cart'),
            ], 429);
        }

        $user = get_userdata($userId);
        $settings = ProductReviewService::getReviewSettings();

        $replyData = [
            'parent_id'      => $reviewId,
            'post_id'        => $postId,
            'rating'         => 0,
            'title'          => '',
            'content'        => $content,
            // Replies follow the same moderation setting as reviews. Hardcoding
            // 'approved' let one approved review become a standing licence to publish
            // unmoderated follow-up content on a public product page.
            'status'         => $settings['auto_approve_reviews'] === 'yes' ? 'approved' : 'pending',
            // Mirror the parent's verified-purchase state so a verified buyer's reply
            // does not render as unverified next to their own verified review.
            'is_verified'    => (int) $review->is_verified,
            'is_admin_reply' => 0,
            'user_id'        => $userId,
            'customer_id'    => $review->customer_id,
            'order_id'       => $review->order_id,
            'reviewer_name'  => $user ? $user->display_name : '',
            'reviewer_email' => $user ? $user->user_email : '',
        ];

        $reply = ProductReviewResource::create($replyData);

        if (is_wp_error($reply)) {
            return $reply;
        }

        do_action('fluent_cart/review/reviewer_reply_submitted', $reply, $review, $request);

        if ($reply && method_exists($reply, 'makeHidden')) {
            $reply->makeHidden(['reviewer_email', 'user_id', 'customer_id', 'order_id', 'meta']);
        }

        $message = $replyData['status'] === 'approved'
            ? __('Your reply has been posted!', 'fluent-cart')
            : __('Your reply has been submitted and is pending approval.', 'fluent-cart');

        return $this->sendSuccess([
            'message' => $message,
            'reply'   => $reply,
        ]);
    }
}
