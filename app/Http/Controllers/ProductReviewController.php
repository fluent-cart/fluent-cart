<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\Resource\ProductReviewResource;
use FluentCart\App\Events\ReviewApproved;
use FluentCart\App\Events\ReviewReplied;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Http\Requests\ReviewCreateRequest;
use FluentCart\App\Http\Requests\ReviewRequest;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Services\Filter\ReviewFilter;
use FluentCart\App\Services\ProductReviewService;

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
            'with'             => ['product', 'customer'],
        ];

        $reviews = ReviewFilter::make($args)->paginate();

        // The item each review names, one query for the page.
        ProductReviewService::attachItemLabels($reviews->items());

        return $this->sendSuccess([
            'reviews' => $reviews,
        ]);
    }

    /**
     * Add a review by hand, attributed to a named reviewer rather than to the
     * admin who typed it.
     *
     * The storefront gates (permission mode, one-review-per-identity, rate
     * limit, submission lock) are intentionally absent — see
     * ProductReviewService::buildAdminReviewData(). No ReviewCreated event is
     * dispatched either: that event's only listener mails the store owner
     * "New Review Submitted", which would notify them about a review they
     * just wrote.
     */
    public function create(ReviewCreateRequest $request)
    {
        // Required fields, the email format, the rating rule and the status
        // list are enforced by ReviewCreateRequest before this runs.
        $data = $request->getSafe($request->sanitize());

        $postId = (int) $data['post_id'];
        $content = trim((string) $data['content']);
        $reviewerName = trim((string) $data['reviewer_name']);
        $reviewerEmail = trim((string) ($data['reviewer_email'] ?? ''));
        $rating = isset($data['rating']) ? ProductReviewService::clampRating($data['rating']) : 0;
        $status = !empty($data['status']) ? $data['status'] : Status::REVIEW_APPROVED;

        // The same resolution the storefront submit uses, so a moderator and a
        // buyer can never disagree about which slot a review occupies: null is
        // a variation that is not this product's (refused, never quietly
        // dropped), and 0 is a product with no items worth telling apart, which
        // files at product level whatever was sent.
        //
        // No grant is required here, unlike the storefront path: the order link
        // exists to prove a guest bought that item, and a moderator holding
        // reviews/manage is already trusted to write the review by hand.
        $itemId = ProductReviewService::resolveReviewItem($postId, $data['item_id'] ?? 0);
        if ($itemId === null) {
            // The field-map shape the Add Review form prints under each field,
            // so the message lands under the variation picker that caused it.
            return $this->sendError([
                'item_id' => [
                    'invalid' => __('Please select a variation that belongs to this product.', 'fluent-cart'),
                ],
            ], 422);
        }

        $reviewData = ProductReviewService::buildAdminReviewData([
            'post_id'        => $postId,
            'item_id'        => $itemId,
            'rating'         => $rating,
            'title'          => $data['title'] ?? '',
            'content'        => $content,
            'status'         => $status,
            'is_verified'    => !empty($data['is_verified']),
            'reviewer_name'  => $reviewerName,
            'reviewer_email' => $reviewerEmail,
        ]);

        // The admin's explicit status and verified choices survive a filter
        // that would rewrite them — see applySubmitDataFilter().
        $reviewData = ProductReviewService::applySubmitDataFilter($reviewData, $request, $postId);

        // Resolves the product and rejects an unknown post_id, so no separate
        // existence check is needed here.
        $review = ProductReviewResource::create($reviewData);

        if (is_wp_error($review)) {
            return $review;
        }

        // The storefront's own post-create hook, not an admin-specific one:
        // this path creates the same kind of row from the same kind of request,
        // and the extensions that care (the Pro media pipeline) already listen
        // here. A separate admin hook would have to be wired a second time and
        // would silently drop attachments until it was.
        do_action('fluent_cart/review/after_submit', $review, $request);

        // Attachments ride along on the same request. The review row already
        // exists by this point, so the files can be stored against a real id
        // — no upload-first endpoint, and no claim token to bridge the gap
        // between an upload and the review that will eventually own it.
        //
        // Free ships no media pipeline; whoever implements this stores the
        // bytes and attaches them (the Pro reviews module in practice).
        $files = $request->files('files');
        if (!empty($files)) {
            do_action('fluent_cart/review/admin_store_media', [
                'review' => $review,
                'files'  => is_array($files) ? $files : [$files],
            ]);

            // The listener writes media refs and media_count straight onto the
            // row, so re-read before echoing it back — otherwise the response
            // reports the review as having no attachments.
            $review = ProductReviewResource::find($review->id, ['with' => []]);
        }

        // Saved approved by the moderator's own hand: an approval, told the
        // same way as one from the moderation queue. After the media hooks,
        // on the row as it now is — they save other_info from a model, and
        // the approval notice's claim lives in that blob.
        $savedReview = ProductReviewResource::find($review->id, ['with' => []]);
        if ($savedReview && !is_wp_error($savedReview)) {
            ReviewApproved::dispatchIfApproved($savedReview);
        }

        $payload = apply_filters('fluent_cart/review/admin_submit_response', [
            'message' => __('Review added successfully', 'fluent-cart'),
            'review'  => $review,
        ]);

        return $this->sendSuccess($payload);
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

        // The permalink is an accessor, so it only reaches the JSON when it
        // is appended — the sidebar's storefront link depends on it. append()
        // rather than setAppends(), which would drop the model's own thumbnail.
        if ($review->relationLoaded('product') && $review->product) {
            $review->product->append('view_url');
        }

        ProductReviewService::attachItemLabels([$review]);

        $responseData = [
            'review' => $review,
            // One store reply per review unless threaded replies (Pro) are
            // enabled — the reply form hides once a reply exists.
            'allow_multiple_replies' => ProductReviewService::isMultipleRepliesAllowed(),
        ];

        $responseData = apply_filters('fluent_cart/review/admin_single_response', $responseData, $review);

        return $this->sendSuccess($responseData);
    }

    public function update(ReviewRequest $request, $id)
    {
        $data = $request->getSafe($request->sanitize());

        // Only allow specific fields to be updated — prevent mass assignment of protected fields
        // Only include fields that were actually sent in the request to avoid overwriting with defaults
        $allowedFields = ['status', 'title', 'content', 'rating', 'is_verified'];
        $data = array_intersect_key($data, array_flip($allowedFields));
        $data = array_filter($data, function ($value, $key) use ($request) {
            return $request->exists($key);
        }, ARRAY_FILTER_USE_BOTH);

        if (isset($data['status']) && !in_array($data['status'], Status::getReviewStatuses(), true)) {
            return $this->sendError([
                'message' => __('Invalid status', 'fluent-cart'),
            ], 400);
        }

        $oldStatus = null;
        if (isset($data['status'])) {
            // Only the status is needed here — skip find()'s default eager loads.
            $existingReview = ProductReviewResource::find($id, ['with' => []]);
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
            $detail = ProductDetail::query()->where('post_id', $postId)->first();
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

        // Replies hang off a review, never off another reply: free's contract
        // is one review and one store reply, and the thread modal only renders
        // a top-level review's direct children, so a nested row would be
        // written but never shown.
        if ($parentReview->parent_id) {
            return $this->sendError([
                'message' => __('You can only reply to a review.', 'fluent-cart'),
            ], 422);
        }

        $data = $request->getSafe($request->sanitize());
        $content = $data['content'] ?? '';

        if (empty(trim($content))) {
            return $this->sendError([
                'message' => __('Please write a reply message before sending.', 'fluent-cart'),
            ], 422);
        }

        $replyData = ProductReviewService::buildAdminReplyData($content, $parentReview);

        // One store reply per review unless an extension allows multiple
        // replies. A unique index cannot express it (Pro lifts the limit to
        // many replies per review), so the check runs inside a transaction
        // holding a row lock on the parent review — concurrent replies to the
        // same review serialize on the lock and the loser sees the winner's row.
        $connection = ProductReviewResource::getQuery()->getConnection();
        $connection->beginTransaction();

        try {
            // Row lock on the parent review; released on commit/rollback.
            ProductReviewResource::getQuery()
                ->where('id', (int) $parentReview->id)
                ->lockForUpdate()
                ->get();

            if (!ProductReviewService::isMultipleRepliesAllowed()) {
                $existingReplies = ProductReviewResource::getQuery()
                    ->where('parent_id', $parentReview->id)
                    ->count();
                if ($existingReplies > 0) {
                    $connection->rollBack();

                    return $this->sendError([
                        'message' => __('This review already has a reply. Delete the existing reply to write a new one.', 'fluent-cart'),
                    ], 422);
                }
            }

            $reply = ProductReviewResource::create($replyData);

            if (is_wp_error($reply)) {
                $connection->rollBack();

                return $reply;
            }

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            return $this->sendError([
                'message' => __('Could not save the reply. Please try again.', 'fluent-cart'),
            ], 500);
        }

        // After the commit, never inside it — see ReviewReplied. The parent
        // and its product were loaded to answer it; handed over rather than
        // looked up again.
        ReviewReplied::dispatchIfStoreReply($reply, $parentReview, $parentReview->product);

        return $this->sendSuccess([
            'message' => __('Your reply has been submitted successfully.', 'fluent-cart'),
            'reply'   => $reply,
        ]);
    }

    public function deleteReply(ReviewRequest $request, $reviewId, $replyId)
    {
        $reply = ProductReviewResource::getQuery()
            ->where('id', (int) $replyId)
            ->where('parent_id', (int) $reviewId)
            ->first();

        if (!$reply) {
            return $this->sendError([
                'message' => __('Reply not found', 'fluent-cart'),
            ], 404);
        }

        // Share the transactional deletion and post-commit media cleanup.
        $deleted = ProductReviewResource::delete($reply->id);
        if (is_wp_error($deleted)) {
            return $deleted;
        }

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

        $result = ProductReviewResource::bulkReply(
            $ids,
            ProductReviewService::buildAdminReplyData($content)
        );

        if (is_wp_error($result)) {
            return $result;
        }

        return $this->sendSuccess($result);
    }

}
