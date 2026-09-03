<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\Resource\ProductReviewResource;
use FluentCart\App\Http\Requests\ReviewRequest;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\Filter\ReviewFilter;

class ProductReviewController extends Controller
{
    public function index(ReviewRequest $request)
    {
        $data = $request->getSafe($request->sanitize());

        // Backward compat: map legacy 'status' param to 'active_view' when active_view is absent
        $activeView = $data['active_view'] ?? '';
        if (empty($activeView) && !empty($data['status']) && $data['status'] !== 'all') {
            $activeView = $data['status'];
        }

        $args = [
            'search'           => $data['search'] ?? '',
            'per_page'         => !empty($data['per_page']) ? (int) $data['per_page'] : 15,
            'sort_by'          => $data['sort_by'] ?? 'id',
            'sort_type'        => $data['sort_type'] ?? ($data['sort_order'] ?? 'DESC'),
            'filter_type'      => $data['filter_type'] ?? 'simple',
            'advanced_filters' => $data['advanced_filters'] ?? '',
            'active_view'      => $activeView,
            'user_tz'          => $data['user_tz'] ?? '',
            'page'             => !empty($data['page']) ? (int) $data['page'] : 1,
            'post_id'          => !empty($data['post_id']) ? (int) $data['post_id'] : null,
            'rating'           => !empty($data['rating']) ? (int) $data['rating'] : null,
            'with'             => ['product'],
        ];

        $reviews = ReviewFilter::make($args)->paginate();

        ProductReview::loadMetaRelations($reviews, ['customer']);

        return $this->sendSuccess([
            'reviews' => $reviews,
        ]);
    }

    public function find(ReviewRequest $request, $id)
    {
        $review = ProductReviewResource::find($id);

        if (is_wp_error($review)) {
            return $this->entityNotFoundError(
                __('Review not found', 'fluent-cart'),
                __('Back to Reviews', 'fluent-cart'),
                '/reviews'
            );
        }

        $responseData = [
            'review' => $review,
        ];

        $responseData = apply_filters('fluent_cart/review/admin_single_response', $responseData, $review);

        return $this->sendSuccess($responseData);
    }

    public function update(ReviewRequest $request, $id)
    {
        $data = $request->getSafe($request->sanitize());

        // Only allow specific fields to be updated — prevent mass assignment of protected fields
        // Only include fields that were actually sent in the request to avoid overwriting with defaults
        $allowedFields = ['status', 'title', 'content', 'rating'];
        $data = array_intersect_key($data, array_flip($allowedFields));
        $data = array_filter($data, function ($value, $key) use ($request) {
            return $request->exists($key);
        }, ARRAY_FILTER_USE_BOTH);

        $validStatuses = ['approved', 'pending', 'spam', 'trash'];
        if (isset($data['status']) && !in_array($data['status'], $validStatuses)) {
            return $this->sendError([
                'message' => __('Invalid status', 'fluent-cart'),
            ], 400);
        }

        $oldStatus = null;
        if (isset($data['status'])) {
            $existingReview = ProductReviewResource::find($id);
            if (!is_wp_error($existingReview)) {
                $oldStatus = $existingReview->status;
            }
        }

        $result = ProductReviewResource::update($data, $id);

        if (is_wp_error($result)) {
            return $result;
        }

        if ($oldStatus && $oldStatus !== $data['status']) {
            // update() reloads internally — use the review from its response
            $updatedReview = $result['data'] ?? null;
            if ($updatedReview) {
                do_action('fluent_cart/review/admin_status_changed', $updatedReview, $oldStatus, $data['status']);
            }
        }

        return $this->sendSuccess($result);
    }

    public function delete(ReviewRequest $request, $id)
    {
        $result = ProductReviewResource::delete($id);

        if (is_wp_error($result)) {
            return $result;
        }

        return $this->sendSuccess($result);
    }

    public function bulkAction(ReviewRequest $request)
    {
        $data = $request->getSafe($request->sanitize());

        $action = $data['action_type'] ?? '';
        $ids = $data['review_ids'] ?? [];

        if (!is_array($ids)) {
            $ids = [];
        }

        $ids = array_map('intval', array_filter($ids));

        $result = ProductReviewResource::bulkAction($action, $ids);

        if (is_wp_error($result)) {
            return $result;
        }

        return $this->sendSuccess($result);
    }

    public function stats(ReviewRequest $request)
    {
        $data = $request->getSafe($request->sanitize());
        $postId = !empty($data['post_id']) ? (int) $data['post_id'] : null;
        $counts = ProductReviewResource::getStatusCounts($postId);

        // Include avg_rating from canonical cache so the UI stays fresh after actions
        $avgRating = 0;
        if ($postId) {
            $detail = \FluentCart\App\Models\ProductDetail::query()->where('post_id', $postId)->first();
            if ($detail && $detail->other_info) {
                $avgRating = array_key_exists('average_rating', $detail->other_info)
                    ? round((float) $detail->other_info['average_rating'], 2)
                    : 0;
            }
        }

        $counts['avg_rating'] = $avgRating;

        return $this->sendSuccess([
            'stats' => $counts,
        ]);
    }

    public function reply(ReviewRequest $request, $id)
    {
        $parentReview = ProductReviewResource::find($id);

        if (is_wp_error($parentReview)) {
            return $parentReview;
        }

        $data = $request->getSafe($request->sanitize());
        $content = $data['content'] ?? '';

        if (empty(trim($content))) {
            return $this->sendError([
                'message' => __('Please write a reply message before sending.', 'fluent-cart'),
            ], 422);
        }

        $user = wp_get_current_user();

        $replyData = [
            'parent_id'      => $parentReview->id,
            'post_id'        => $parentReview->post_id,
            'rating'         => 0,
            'title'          => '',
            'content'        => $content,
            'status'         => 'approved',
            'is_verified'    => 0,
            'is_admin_reply' => 1,
            'user_id'        => $user->ID,
            'reviewer_name'  => $user->display_name,
            'reviewer_email' => $user->user_email,
        ];

        $reply = ProductReviewResource::create($replyData);

        if (is_wp_error($reply)) {
            return $reply;
        }

        return $this->sendSuccess([
            'message' => __('Your reply has been submitted successfully.', 'fluent-cart'),
            'reply'   => $reply,
        ]);
    }

    public function deleteReply(ReviewRequest $request, $reviewId, $replyId)
    {
        $reply = ProductReviewResource::getQuery()
            ->where('comment_ID', (int) $replyId)
            ->where('comment_parent', (int) $reviewId)
            ->first();

        if (!$reply) {
            return $this->sendError([
                'message' => __('Reply not found', 'fluent-cart'),
            ], 404);
        }

        // Same extension point every other delete path fires
        // (ProductReviewResource::delete()/bulkAction()) — Pro's cleanup
        // listeners must see single-reply deletions too.
        do_action('fluent_cart/review/before_delete', $reply);

        wp_delete_comment((int) $replyId, true);

        return $this->sendSuccess([
            'message' => __('Reply deleted successfully', 'fluent-cart'),
        ]);
    }

    public function bulkReply(ReviewRequest $request)
    {
        $data = $request->getSafe($request->sanitize());

        $content = $data['content'] ?? '';
        $ids = $data['review_ids'] ?? [];

        if (empty(trim($content))) {
            return $this->sendError([
                'message' => __('Please write a reply message before sending.', 'fluent-cart'),
            ], 422);
        }

        if (!is_array($ids)) {
            $ids = [];
        }

        $ids = array_map('intval', array_filter($ids));

        if (empty($ids)) {
            return $this->sendError([
                'message' => __('No reviews selected', 'fluent-cart'),
            ], 400);
        }

        // Cap batch size to prevent long-running requests
        $ids = array_slice($ids, 0, 50);

        $user = wp_get_current_user();

        $result = ProductReviewResource::bulkReply($ids, [
            'rating'         => 0,
            'title'          => '',
            'content'        => $content,
            'status'         => 'approved',
            'is_verified'    => 0,
            'is_admin_reply' => 1,
            'user_id'        => $user->ID,
            'reviewer_name'  => $user->display_name,
            'reviewer_email' => $user->user_email,
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        return $this->sendSuccess($result);
    }

}
