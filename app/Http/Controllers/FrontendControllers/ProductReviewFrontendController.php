<?php

namespace FluentCart\App\Http\Controllers\FrontendControllers;

use FluentCart\App\Helpers\Status;
use FluentCart\Api\Resource\CustomerResource;
use FluentCart\Api\Resource\ProductReviewResource;
use FluentCart\App\Events\ReviewApproved;
use FluentCart\App\Events\ReviewCreated;
use FluentCart\App\Http\Requests\FrontendRequests\ReviewRequest;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\App\Services\ReviewSubmissionLimiter;
use FluentCart\App\Services\Renderer\ProductReviewRenderer;
use FluentCart\App\Hooks\Handlers\BlockEditors\ProductReviewList\ProductReviewListBlockEditor;
use FluentCart\App\Services\Renderer\ReviewListRenderer;
use FluentCart\App\Services\Renderer\ReviewModalRenderer;
use FluentCart\App\Services\Renderer\ReviewThreadMarkup;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;

class ProductReviewFrontendController extends BaseFrontendController
{
    /**
     * The most reviews one page may ask for.
     *
     * per_page comes off the query string, so this is the guard against a
     * request asking for every review at once. Well above anything the block's
     * own control offers, so it never gets in an editor's way.
     */
    const MAX_REVIEWS_PER_PAGE = 100;

    /**
     * The longest trim a caller may ask for. Past this the limit stops being
     * a trim: a review that long was going to print in full anyway.
     */
    const MAX_REVIEW_WORDS = 500;

    public function getReviews(ReviewRequest $request, $postId)
    {
        $postId = intval($postId);

        // Only allow reading reviews for published products
        if (!ProductReviewService::isReviewEnabledForProduct($postId)
            || !Product::query()->where('post_status', 'publish')->find($postId)) {
            return $this->sendError([
                'message' => __('Product not found', 'fluent-cart'),
            ], 404);
        }

        $data = [
            // 0, not 10: absent means "the store's setting", resolved below.
            'per_page'      => intval($request->get('per_page', 0)),
            'sort_by'       => sanitize_text_field($request->get('sort_by', 'created_at')),
            'sort_order'    => sanitize_text_field($request->get('sort_order', 'DESC')),
            'rating'        => intval($request->get('rating', 0)),
            'has_media'     => intval($request->get('has_media', 0)),
            'verified_only' => intval($request->get('verified_only', 0)),
        ];

        $settings = ProductReviewService::getReviewSettings();

        // The store's setting is the default, not a ceiling. Capping at it made
        // a block's own Reviews Per Page a lie: the first render honoured the
        // block and every page after it silently dropped to the store's number,
        // so the rows-per-page changed under the reader — and in grid view the
        // columns went ragged on the first click.
        $perPage = $data['per_page'] > 0
            ? $data['per_page']
            : (int) $settings['reviews_per_page'];
        $perPage = max(1, min($perPage, static::MAX_REVIEWS_PER_PAGE));

        $params = [
            'post_id'    => $postId,
            'status'     => 'approved',
            'page'       => max(1, intval($request->get('page', 1))),
            'sort_by'    => $data['sort_by'] ?? 'created_at',
            'sort_order' => $data['sort_order'] ?? 'DESC',
            'per_page'   => $perPage,
            // A signed per-instance floor takes precedence over the legacy
            // composition default. Neither changes public-review permissions.
            'min_rating' => ProductReviewListBlockEditor::minRatingOfComposition(
                $request->get('client_id', ''),
                (int) $postId,
                (string) $request->get('rating_token', '')
            ),
        ];

        // ratings=5,4 from the star chips; the single rating param still works.
        $ratings = ProductReviewService::ratingList($request->get('ratings', ''));
        $rating = !empty($data['rating']) ? (int) $data['rating'] : null;

        if ($ratings) {
            $params['ratings'] = $ratings;
        } elseif ($rating && $rating >= 1 && $rating <= 5) {
            $params['rating'] = $rating;
        }

        if (!empty($data['has_media'])) {
            $params['has_media'] = true;
        }

        if (!empty($data['verified_only'])) {
            $params['verified_only'] = true;
        }

        $responseData = ProductReviewService::getPublicReviewsPayload($params, $postId);

        // The rows render on the server, after the filter so PRO's media and
        // vote fields are part of them; the JS swaps this markup in. Display
        // flags travel with the request since they are block attributes the
        // server cannot otherwise know.
        $listRenderer = new ReviewListRenderer([
            'show_reviewer'   => $request->get('show_reviewer', '1') !== '0',
            'show_date'       => $request->get('show_date', '1') !== '0',
            'show_verified'   => $request->get('show_verified', '1') !== '0',
            'show_view_reply' => $request->get('show_view_reply', '1') !== '0',
            'show_avatar'     => (int) $request->get('show_avatar', 1) !== 0,
            'show_title'      => (int) $request->get('show_title', 1) !== 0,
            'show_content'    => (int) $request->get('show_content', 1) !== 0,
            'show_photos'     => (int) $request->get('show_photos', 1) !== 0,
            'show_footer'     => (int) $request->get('show_footer', 1) !== 0,
            'show_variation'  => (int) $request->get('show_variation', 1) !== 0,
            'can_thread'      => ProductReviewService::isMultipleRepliesAllowed() && is_user_logged_in(),
            // Whitelisted, not passed through: it selects a render path, and
            // an unknown value would silently fall back to the numbered pager
            // on every page change while the first render kept the chosen one.
            'max_words'       => max(0, min(static::MAX_REVIEW_WORDS, (int) $request->get('max_words', 0))),
            // Bounded the same way the first render bounds them — this is the
            // same editor input arriving by a different door.
            'media_visible'   => ProductReviewRenderer::mediaVisibleCount($request->get('media_visible', 0)),
            'media_width'     => ProductReviewRenderer::mediaTileSize($request->get('media_width', 0)),
            'media_height'    => ProductReviewRenderer::mediaTileSize($request->get('media_height', 0)),
            'media_full_width' => $request->get('media_full_width', '0') === '1',
            'media_flush'     => $request->get('media_flush', '0') === '1',
            'media_backdrop'  => $request->get('media_backdrop', '0') === '1',
            'photos_first'    => $request->get('photos_first', '0') === '1',
            'rating_first'    => $request->get('rating_first', '0') === '1',
            'badge_last'      => $request->get('badge_last', '0') === '1',
            'show_meta'       => $request->get('show_meta', '1') !== '0',
            // Whitelisted, not taken as given -- this lands in a class
            // attribute and arrives from a public request.
            'item_class'      => ReviewListRenderer::itemClass($request->get('item_class', '')),
            'media_more'      => ReviewThreadMarkup::moreTilePlacement($request->get('media_more', 'overlay')),
            'pagination_type' => in_array(
                $request->get('pagination_type', 'numbers'),
                ReviewListRenderer::paginationTypes(),
                true
            ) ? $request->get('pagination_type', 'numbers') : 'numbers',
            // The composed row again, rebuilt from the post the block is saved
            // in. Without this the first sort, filter or page swaps the
            // editor's row for the fixed one and never gives it back — the
            // saved composition lasting exactly one page load.
            'rows_renderer'   => ProductReviewListBlockEditor::savedRowRenderer(
                $request->get('client_id', '')
            ),
        ]);

        $responseData['reviews_html'] = $listRenderer->renderReviewItems(Arr::get($responseData, 'reviews.data', []));
        $responseData['pagination_html'] = $listRenderer->paginationHtml(Arr::get($responseData, 'reviews', []));

        return $this->sendSuccess($responseData);
    }

    public function getRatingSummary(Request $request, $postId)
    {
        $postId = intval($postId);

        if (!ProductReviewService::isReviewEnabledForProduct($postId)
            || !Product::query()->where('post_status', 'publish')->find($postId)) {
            return $this->sendError([
                'message' => __('Product not found', 'fluent-cart'),
            ], 404);
        }

        $summary = ProductReviewService::getProductRatingSummary($postId);

        // Check if current user can submit a review
        $canSubmit = ProductReviewService::canSubmitReview($postId, $request);

        /* translators: 1: the average star rating, e.g. "Rated 4.5 out of 5" */
        $starsLabel = sprintf(__('Rated %1$s out of 5', 'fluent-cart'), Arr::get($summary, 'average', 0));

        return $this->sendSuccess([
            'summary'     => $summary,
            'can_submit'  => $canSubmit,
            // The average-star row rendered server-side, so the script swaps
            // markup instead of assembling it.
            'stars_html'  => (new ProductReviewRenderer($postId))->starsHtml(Arr::get($summary, 'average', 0)),
            'stars_label' => $starsLabel,
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
        // limited and moderated. updateReview() does check the nonce —
        // both require an authenticated user, where a stale nonce is not a concern
        // because caches bypass logged-in requests.
        $postId = intval($postId);

        $ip = !empty($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '';
        $userId = get_current_user_id();

        // The variation the review is about, from the request body and
        // nowhere else. Only the order-review page sends one, and only a
        // grant covering that exact item can honour it — checked below, once
        // the product is known to exist. Read here because the grant lookup
        // that decides the rate-limit exemption is already keyed on it.
        $itemId = max(0, (int) $request->get('item_id', 0));

        // A submission carrying a valid order grant is measured against its
        // own bucket, not the per-IP one. That limit exists to stop anonymous
        // bulk review spam and is the wrong instrument here: the order-review
        // page invites a customer to review every product they bought in one
        // sitting, so a six-item order would trip a five-per-hour cap on a
        // legitimate last review. The grant's own cap is the order's size
        // plus a little slack for a retry — enough for every line, not
        // enough to flood moderation from one purchase, however many times
        // an earlier review from that link is trashed and the slot reopens.
        $orderHash = (string) $request->get('order_hash', '');
        $grant = ProductReviewService::resolveOrderGrant($postId, $orderHash, $itemId);

        // Rate limit: max 5 review submissions per identity per hour.
        $limit = 5;
        if ($grant) {
            $identity = 'g' . (int) $grant->id;
            $limit = max($limit, ProductReviewService::grantProductCount($orderHash) + 2);
        } elseif ($userId) {
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

        $count = ReviewSubmissionLimiter::increment($identity);

        if ($count > $limit) {
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

        // The item must be one of this product's, and a simple product files
        // at product level whatever was sent. A stray id is refused rather
        // than quietly dropped, so the response never claims a review it did
        // not write.
        $grant = ProductReviewService::resolveOrderGrant($postId, $request->get('order_hash'), $itemId);
        $resolvedItemId = ProductReviewService::resolveReviewItem($postId, $itemId);
        if ($resolvedItemId === null) {
            return $this->sendError([
                'message' => __('That item is not available for review.', 'fluent-cart'),
            ], 422);
        }

        // resolveReviewItem() can downgrade a simple product's item to 0, in
        // which case the grant has to be re-read for the product-level slot.
        if ($resolvedItemId !== $itemId) {
            $itemId = $resolvedItemId;
            $grant = ProductReviewService::resolveOrderGrant($postId, $request->get('order_hash'), $itemId);
        }

        // An item-level review is only ever written from the order-review
        // page, where the order line names the item. Without a grant for that
        // item — no hash, a hash for another order, or an order that holds a
        // different variant — the request is refused, never downgraded to a
        // product-level review the visitor did not ask for.
        if ($itemId && !$grant) {
            return $this->sendError([
                'message' => __('Reviews of a specific item are only accepted through the order link that includes it.', 'fluent-cart'),
            ], 403);
        }

        // Check if user can submit review. Listeners read item_id off the
        // request; the filter keeps its three-argument shape.
        $canSubmit = ProductReviewService::canSubmitReview($postId, $request, $itemId);
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
        $rating = isset($data['rating']) ? ProductReviewService::clampRating($data['rating']) : 0;
        if (ProductReviewService::isStarRatingRequired() && $rating < 1) {
            return $this->sendError([
                'message' => __('Star rating is required', 'fluent-cart'),
            ], 422);
        }

        $reviewData = [
            'post_id'        => $postId,
            'item_id'        => $itemId,
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

        // A submission the order hash authorised posts as the order's customer,
        // whether or not anyone is signed in. Deciding this before the
        // logged-in branch is the point: otherwise a forwarded review link
        // opened by someone signed in to their own account would publish a
        // review under THEIR name for a product they never bought — the exact
        // thing verified_buyers mode exists to prevent.
        $grantOwns = ProductReviewService::grantOwnsSubmission($grant);
        $grantIdentity = $grantOwns ? ProductReviewService::orderGrantIdentity($grant) : null;
        $grantEmail = $grantIdentity ? trim((string) Arr::get($grantIdentity, 'email', '')) : '';

        // Set reviewer info based on the grant, the logged in user, or guest.
        // A grant that owns the submission but has no customer left to name
        // (the row is gone) takes the typed fields even from a signed-in
        // visitor: the review is the buyer's, filed against their order, and
        // must not land under whoever happened to be logged in when the link
        // was opened — their account would otherwise own, and could edit, a
        // review of something they never bought.
        if ($grantEmail !== '') {
            $reviewData['reviewer_name'] = Arr::get($grantIdentity, 'name', '');
            $reviewData['reviewer_email'] = $grantEmail;
            $reviewData['customer_id'] = Arr::get($grantIdentity, 'customer_id');
            $reviewData['order_id'] = $grant->id;

            // The review belongs to the buyer, so it carries the buyer's user
            // id when they have one — not the id of whoever opened the link.
            // It also keeps the duplicate guard working if that buyer later
            // signs in and tries to review the same product again, since that
            // check is keyed on user_id.
            $buyer = $grant->customer;
            $reviewData['user_id'] = ($buyer && $buyer->user_id) ? (int) $buyer->user_id : 0;

            // Same rule as the logged-in path: the badge tracks a successful
            // purchase, which is stricter than the grant.
            if ($reviewData['customer_id'] && ProductReviewService::isVerifiedPurchase($postId, $reviewData['customer_id'])) {
                $reviewData['is_verified'] = 1;
            }
        } elseif ($userId && !$grantOwns) {
            $user = get_userdata($userId);
            $reviewData['reviewer_name'] = $user ? $user->display_name : '';
            $reviewData['reviewer_email'] = $user ? $user->user_email : '';

            $customer = ProductReviewService::visitorCustomer($userId);
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

            // Reached only when no grant supplied an identity — including the
            // case of a grant whose customer row is gone, where the form shows
            // the visitor real name and email fields to fill in.
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
            // Typed identity is nobody's account, whoever is signed in.
            $reviewData['user_id'] = 0;
            $reviewData['customer_id'] = null;
        }

        // Whatever identity the row ends up under, a grant-authorised review
        // records the order that authorised it — the buyer signed in as
        // themselves, and a grant whose customer row is gone, included.
        if ($grant) {
            $reviewData['order_id'] = (int) $grant->id;
        }

        // Whether this submission needs moderating, settled before the trust
        // fields are captured below — the store's own rule, plus anything that
        // owns a fact the rule depends on. PRO holds photo reviews back here
        // when the store auto-approves reviews but not photo reviews: free
        // cannot see that a submission carries photos.
        $reviewData['status'] = ProductReviewService::applySubmissionStatusFilter(
            $reviewData['status'],
            $reviewData,
            $request
        );

        // Trust fields must never come from user input or a filter — the
        // service captures them before the filter and re-imposes them after.
        $reviewData = ProductReviewService::applySubmitDataFilter($reviewData, $request, $postId);

        // Claim the (product, identity) slot atomically so a second concurrent
        // request for the same product + identity cannot slip past the
        // duplicate check above before this one finishes inserting. The slot
        // belongs to the identity the row is filed under — the buyer when a
        // grant owns the submission — never to whoever opened the link.
        $identity = ProductReviewService::effectiveReviewerIdentity($grant, $reviewData['reviewer_email']);
        $duplicateMessage = $identity['user_id'] || $identity['via_grant']
            ? __('You have already submitted a review for this product', 'fluent-cart')
            : __('A review with this email already exists for this product', 'fluent-cart');

        $lock = ProductReviewService::claimReviewSlot(
            $postId,
            $identity['user_id'],
            ProductReviewService::slotLockEmail($identity, $reviewData['reviewer_email']),
            $itemId,
            $identity['customer_id']
        );
        if (!$lock) {
            return $this->sendError(['message' => $duplicateMessage], 409);
        }

        try {
            // The duplicate guard ran before the lock was held. A request that
            // passed it while another holder of this same slot was still
            // inserting would otherwise insert a second row the moment that
            // holder released — so the guard runs once more, now serialised.
            //
            // Keyed to the same slot as the lock and the first check. Without
            // $itemId it asks about the product-level slot instead: a buyer
            // who already reviewed the product as a whole was refused when
            // reviewing one of its variations from the order link, and two
            // concurrent submissions for the same variation both passed,
            // which is the race this recheck exists to close.
            $recheck = ProductReviewService::canSubmitReview($postId, $request, $itemId);
            $recheck = apply_filters('fluent_cart/review/can_submit', $recheck, $postId, $request);
            if (!is_array($recheck) || !$recheck['can_submit']) {
                // The guard names its own reason — reviews switched off for
                // the product mid-flight, an add-on refusing — and only
                // falls back to the duplicate wording when it gives none.
                $message = is_array($recheck) && !empty($recheck['message']) ? $recheck['message'] : $duplicateMessage;

                return $this->sendError(['message' => $message], 409);
            }

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

        // Born approved, if the store auto-approves. Decided on the row as it
        // is now, not as it was written: a hook above can hold a photo review
        // back, and the hooks save other_info from a model, so the approval
        // notice's claim on that blob has to land after them, not under them.
        $savedReview = ProductReviewResource::find($review->id, ['with' => []]);
        if ($savedReview && !is_wp_error($savedReview)) {
            ReviewApproved::dispatchIfApproved($savedReview);
        }

        // Dispatch event (triggers email notifications)
        (new ReviewCreated($review))->dispatch();

        $message = $reviewData['status'] === 'approved'
            ? __('Thank you for your review!', 'fluent-cart')
            : __('Thank you! Your review has been submitted and is pending approval.', 'fluent-cart');

        $message = apply_filters('fluent_cart/review/submit_success_message', $message, $review);

        // The hooks wrote onto the row (photos, counts); re-read so the form
        // gets the review as saved and can turn itself into the edit form.
        $saved = ProductReviewResource::find($review->id, ['with' => []]);
        if ($saved && !is_wp_error($saved)) {
            $review = $saved;
        }

        // Match the hiding applied by getReviews — the create response must not be the
        // one path that serialises reviewer_email and the internal id columns.
        if ($review && method_exists($review, 'makeHidden')) {
            $review->makeHidden(['reviewer_email', 'user_id', 'customer_id', 'order_id', 'meta']);
        }

        /**
         * The answer to a submission, before it is sent. An extension that
         * did work in after_submit — storing the photos that came with the
         * request — reports on it here, so the form can tell the reviewer
         * what happened to each part of what they sent.
         *
         * @param array  $payload  message and review
         * @param object $review   the saved review
         * @param object $request
         * @param bool   $isUpdate false: a new review
         */
        $payload = apply_filters('fluent_cart/review/submit_response', [
            'message' => $message,
            'review'  => $review,
            'media'   => static::mediaForResponse($review),
            'can_edit' => $userId > 0 && (int) $review->user_id === $userId,
        ], $review, $request, false);

        return $this->sendSuccess($payload);
    }

    /**
     * The photos on a review, as the form's uploader shows them: id and url,
     * from the media refs the row carries. An empty list without photos.
     *
     * @param mixed $review
     * @return array
     */
    protected static function mediaForResponse($review): array
    {
        $items = is_object($review) && isset($review->media) && is_array($review->media) ? $review->media : [];

        $media = [];
        foreach ($items as $item) {
            $id = (int) Arr::get($item, 'attachment_id', 0);
            $url = (string) Arr::get($item, 'url', '');
            if ($id && $url) {
                $media[] = ['id' => $id, 'url' => esc_url($url), 'name' => sanitize_text_field((string) Arr::get($item, 'name', ''))];
            }
        }

        return $media;
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

        if (!ProductReviewService::isReviewEnabledForProduct($postId)
            || !Product::query()->where('post_status', 'publish')->find($postId)) {
            return $this->sendError(['message' => __('Reviews are currently disabled', 'fluent-cart')], 403);
        }

        // Verify the review exists and belongs to the current user.
        //
        // topLevel() matters as much as the ownership columns: a reply is a
        // row on the same product with the same user_id, so without it this
        // endpoint edits replies too — accepting a title and a rating for a
        // row that has neither, and re-moderating an approved reply back to
        // pending, which drops it out of the thread it belongs to.
        //
        // Spelled out rather than the topLevel() scope: model scopes resolve
        // through Builder::__call(), which static analysis cannot see. Keep in
        // step with that scope's predicate.
        $review = ProductReview::query()
            ->whereNull('parent_id')
            ->where('id', $reviewId)
            ->where('post_id', $postId)
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
            ? ProductReviewService::clampRating($data['rating'])
            : (int) $review->rating;

        if (ProductReviewService::isStarRatingRequired() && $rating < 1) {
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

        $updateData['status'] = ProductReviewService::applyUpdateStatusFilter(
            $updateData['status'] ?? $review->status,
            $updateData,
            $request,
            $review
        );

        $result = ProductReviewResource::update($updateData, $reviewId);

        if (is_wp_error($result)) {
            return $result;
        }

        do_action('fluent_cart/review/after_update', $result, $request);

        // The hooks may have changed the row's photos; answer with it as saved.
        $saved = ProductReviewResource::find($reviewId, ['with' => []]);

        // Tell the reviewer their edit is queued again — otherwise the review silently
        // disappears from the public list after a successful save.
        $message = isset($updateData['status']) && $updateData['status'] === 'pending'
            ? __('Your review has been updated and is pending approval.', 'fluent-cart')
            : __('Your review has been updated!', 'fluent-cart');

        // See submitReview() — the same report, for an edit.
        $payload = apply_filters('fluent_cart/review/submit_response', [
            'message' => $message,
            'review'  => $result,
            'media'   => static::mediaForResponse($saved && !is_wp_error($saved) ? $saved : null),
        ], $review, $request, true);

        return $this->sendSuccess($payload);
    }

    /**
     * Server-rendered markup for the review thread modal.
     *
     * The storefront paints an overlay shell with a loader and swaps in this
     * view, the same split the product modal uses — so the review, its
     * replies and any extension footer arrive together instead of the modal
     * opening empty and fetching replies afterwards.
     */
    public function getModalView(Request $request, $postId, $reviewId)
    {
        $postId = intval($postId);
        $reviewId = intval($reviewId);

        // Same gate as the other public read endpoints.
        if (!ProductReviewService::isReviewEnabledForProduct($postId)
            || !Product::query()->where('post_status', 'publish')->find($postId)) {
            return $this->sendError([
                'message' => __('Product not found', 'fluent-cart'),
            ], 404);
        }

        $review = ProductReview::query()
            ->where('id', $reviewId)
            ->where('post_id', $postId)
            // Spelled out rather than the topLevel() scope: model scopes
            // resolve through Builder::__call(), which static analysis
            // cannot see. Keep in step with that scope's predicate.
            ->whereNull('parent_id')
            ->where('status', Status::REVIEW_APPROVED)
            ->first();

        if (!$review) {
            return $this->sendError([
                'message' => __('Review not found', 'fluent-cart'),
            ], 404);
        }

        ob_start();
        (new ReviewModalRenderer($review, get_post_field('post_title', $postId, 'raw')))->render();
        $view = ob_get_clean();

        return $this->sendSuccess([
            'view' => $view,
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
        if (!ProductReviewService::isReviewEnabledForProduct($postId)
            || !Product::query()->where('post_status', 'publish')->find($postId)) {
            return $this->sendError([
                'message' => __('Product not found', 'fluent-cart'),
            ], 404);
        }

        // Verify parent review exists, belongs to product, is approved + top-level
        $review = ProductReview::query()
            ->where('id', $reviewId)
            ->where('post_id', $postId)
            ->whereNull('parent_id')
            ->where('status', Status::REVIEW_APPROVED)
            ->first();

        if (!$review) {
            return $this->sendError([
                'message' => __('Review not found', 'fluent-cart'),
            ], 404);
        }

        $replies = ProductReview::query()
            ->where('parent_id', $reviewId)
            ->where('status', Status::REVIEW_APPROVED)
            ->orderBy('created_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->paginate($perPage, ['*'], 'page', $page);

        // photo is appended by the model itself.
        foreach ($replies->items() as $reply) {
            $reply->makeHidden(['reviewer_email', 'user_id', 'customer_id', 'order_id', 'meta']);
        }

        return $this->sendSuccess([
            'replies' => $replies->toArray(),
        ]);
    }

    /**
     * The review form for one product, for the My Reviews page.
     *
     * The dashboard is a Vue app with no product page under it, so it asks
     * for the same form the order-review page renders in its rows: the
     * storefront renderer with a modal around it, every field at once. The
     * page mounts the markup and ReviewForm.js takes it from there, so a
     * customer writes the review where they are instead of leaving for the
     * product page. Product level only — the order-review page is the one
     * place a review is filed under a specific item.
     *
     * Rendered, not the block: the block prints its own trigger button too,
     * and the page has the row's button for that. The customer's own
     * identity carries the submission, as it would on the product page.
     *
     * item_id names the slot when there is one. The History tab edits an
     * existing review through this same endpoint, and an item-level review
     * must open ITS form: the renderer decides between "write" and "edit" by
     * looking up the visitor's review in the (product, item) slot, so a
     * variation's review asked for at product level would come back as a
     * blank create form and a save would collide with the duplicate guard.
     */
    public function getReviewSubmissionForm(Request $request): \WP_REST_Response
    {
        if (ProductReviewService::getReviewSettings()['reviews_enabled'] !== 'yes') {
            return $this->sendError([
                'message' => __('Reviews are currently disabled', 'fluent-cart'),
            ], 404);
        }

        $postId = max(0, (int) $request->get('post_id', 0));
        $product = $postId
            ? Product::query()->where('post_status', 'publish')->find($postId)
            : null;

        if (!$product) {
            return $this->sendError([
                'message' => __('Product not found', 'fluent-cart'),
            ], 404);
        }

        // Refused rather than quietly downgraded, the same rule submitReview()
        // applies: null is a variation that is not this product's, and 0 is a
        // product with no items worth telling apart.
        $itemId = ProductReviewService::resolveReviewItem($product->ID, $request->get('item_id', 0));
        if ($itemId === null) {
            return $this->sendError([
                'message' => __('That item is not available for review.', 'fluent-cart'),
            ], 422);
        }

        ob_start();
        (new ProductReviewRenderer($product->ID, [
            'container' => 'modal',
            'layout'    => 'inline',
            'itemId'    => $itemId,
        ]))->renderForm();
        $html = trim((string) ob_get_clean());

        // Nothing rendered means reviews are off for this product.
        if ($html === '') {
            return $this->sendError([
                'message' => __('Reviews are currently disabled for this product', 'fluent-cart'),
            ], 404);
        }

        return $this->sendSuccess([
            'html' => $html,
        ]);
    }

    /**
     * The dashboard's My Reviews page, one endpoint for both tabs: the
     * default returns the logged-in customer's review history, type=pending
     * returns the purchased-but-not-reviewed products. Sits behind
     * CustomerFrontendPolicy and only ever reads rows scoped to the
     * current customer.
     */
    public function getReviewsByCustomer(Request $request): \WP_REST_Response
    {
        // Module switch is enforced here, not just in the menu: with reviews
        // off, the page URL and the endpoint must both go dark.
        if (ProductReviewService::getReviewSettings()['reviews_enabled'] !== 'yes') {
            return $this->sendError([
                'message' => __('Reviews are currently disabled', 'fluent-cart'),
            ], 404);
        }

        $customer = CustomerResource::getCurrentCustomer();

        if ($request->get('type') === 'pending') {
            // history_total rides along so the History tab shows its count
            // before it is ever opened — the rows themselves load lazily.
            return $this->sendSuccess([
                'products'      => $customer ? ProductReviewService::getPendingReviewProducts($customer) : [],
                'history_total' => $customer ? ProductReviewService::countCustomerReviews($customer) : 0,
            ]);
        }

        if (!$customer) {
            return $this->sendSuccess([
                'reviews' => [
                    'data'         => [],
                    'total'        => 0,
                    'per_page'     => 10,
                    'current_page' => 1,
                    'last_page'    => 1,
                ],
            ]);
        }

        return $this->sendSuccess(ProductReviewService::getCustomerReviewsPayload($customer, [
            'per_page' => (int) $request->get('per_page', 10),
            'page'     => (int) $request->get('page', 1),
        ]));
    }
}
