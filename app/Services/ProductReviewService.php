<?php

namespace FluentCart\App\Services;

use FluentCart\Api\ModuleSettings;
use FluentCart\Api\Resource\ProductReviewResource;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductReview;
use FluentCart\Framework\Support\Arr;

class ProductReviewService
{
    public static function isVerifiedPurchase($postId, $customerIdOrEmail): bool
    {
        if (!is_numeric($customerIdOrEmail)) {
            $customer = Customer::query()->where('email', $customerIdOrEmail)->first();
            if (!$customer) {
                return false;
            }
            $customerId = $customer->id;
        } else {
            $customerId = $customerIdOrEmail;
        }

        return OrderItem::query()
            ->where('post_id', $postId)
            ->whereHas('order', function ($q) use ($customerId) {
                $q->whereIn('status', Status::getOrderSuccessStatuses())
                  ->where('customer_id', $customerId);
            })
            ->exists();
    }

    /**
     * Whether the customer has ANY order containing this product, in any
     * status. Review eligibility in verified_buyers mode uses this — placing
     * an order at all unlocks the review form. The stricter
     * isVerifiedPurchase() (successful orders only) keeps governing the
     * Verified Purchase badge.
     */
    public static function hasOrderedProduct($postId, $customerId): bool
    {
        return OrderItem::query()
            ->where('post_id', $postId)
            ->whereHas('order', function ($q) use ($customerId) {
                $q->where('customer_id', $customerId);
            })
            ->exists();
    }

    public static function isReviewEnabledForProduct($postId): bool
    {
        $globalSettings = static::getReviewSettings();
        if ($globalSettings['reviews_enabled'] !== 'yes') {
            return false;
        }

        $detail = ProductDetail::query()->where('post_id', $postId)->first();
        if ($detail && $detail->other_info) {
            $otherInfo = $detail->other_info;
            if (isset($otherInfo['reviews_enabled']) && $otherInfo['reviews_enabled'] === 'no') {
                return false;
            }
        }

        return true;
    }

    public static function canSubmitReview($postId, $request): array
    {
        if (!static::isReviewEnabledForProduct($postId)) {
            return [
                'can_submit' => false,
                'message'    => __('Reviews are currently disabled for this product', 'fluent-cart'),
            ];
        }

        $settings = static::getReviewSettings();

        $permissionMode = $settings['review_permission_mode'];
        $userId = get_current_user_id();

        if ($permissionMode === Status::REVIEW_PERMISSION_VERIFIED_BUYERS) {
            if (!$userId) {
                return [
                    'can_submit' => false,
                    'message'    => __('Please log in to leave a review', 'fluent-cart'),
                ];
            }

            $customer = Customer::query()->where('user_id', $userId)->first();
            if (!$customer || !static::hasOrderedProduct($postId, $customer->id)) {
                return [
                    'can_submit' => false,
                    'message'    => __('Only verified buyers can leave a review', 'fluent-cart'),
                ];
            }
        } elseif ($permissionMode === Status::REVIEW_PERMISSION_LOGGED_IN) {
            if (!$userId) {
                return [
                    'can_submit' => false,
                    'message'    => __('Please log in to leave a review', 'fluent-cart'),
                ];
            }
        }
        // 'anyone' mode allows all

        // Check for duplicate review. Spam/trash are excluded (see
        // Status::getReviewDuplicateStatuses()): a rejected review does not
        // block a fresh submission.
        $duplicateStatuses = Status::getReviewDuplicateStatuses();
        if ($userId) {
            $existingReview = ProductReview::query()
                ->where('comment_post_ID', $postId)
                ->where('user_id', $userId)
                ->whereIn('comment_approved', $duplicateStatuses)
                ->first();

            if ($existingReview) {
                return [
                    'can_submit' => false,
                    'message'    => __('You have already submitted a review for this product', 'fluent-cart'),
                ];
            }
        } else {
            $email = $request->get('reviewer_email');
            if ($email) {
                // Case-insensitive match — Test@x.com and test@x.com are the same
                // reviewer identity for the purpose of this guard.
                $normalizedEmail = strtolower(sanitize_email($email));

                $existingReview = ProductReview::query()
                    ->where('comment_post_ID', $postId)
                    ->whereRaw('LOWER(comment_author_email) = ?', [$normalizedEmail])
                    ->whereIn('comment_approved', $duplicateStatuses)
                    ->first();

                if ($existingReview) {
                    return [
                        'can_submit' => false,
                        'message'    => __('A review with this email already exists for this product', 'fluent-cart'),
                    ];
                }
            }
        }

        return [
            'can_submit' => true,
            'message'    => '',
        ];
    }

    // Generous upper bound on a review submission request (insert + meta save
    // + event dispatch + email). A lock older than this almost certainly
    // means the request that claimed it died before reaching its finally
    // block (fatal error, timeout, OOM kill) rather than genuine contention.
    const REVIEW_LOCK_TTL = 120;

    /**
     * Atomically claim the (product, identity) slot for the duration of a review
     * submission, closing the check-then-insert race that canSubmitReview()'s
     * duplicate lookup cannot close on its own — two concurrent requests for the
     * same product + identity can both pass that lookup before either insert
     * lands. Uses INSERT IGNORE against wp_options' UNIQUE(option_name), the
     * same primitive WP core's own locking helpers rely on, so only one caller
     * ever wins the row regardless of object-cache availability.
     *
     * The lock is short-lived and released via releaseReviewSlot() right after
     * the create attempt in the same request — it is a submission-time mutex,
     * not a permanent uniqueness record, so no cleanup on delete/trash/untrash
     * is needed. The real duplicate-prevention for subsequent requests stays in
     * canSubmitReview()'s query against the now-inserted row.
     *
     * If the slot is already held, its embedded timestamp is checked against
     * REVIEW_LOCK_TTL: a lock older than that is reclaimed via a
     * compare-and-swap UPDATE (only succeeds if option_value still equals the
     * stale value just read), so a lock a concurrent request is legitimately
     * still holding, or has already renewed/reclaimed itself, can never be
     * stolen out from under it — only a truly abandoned lock is ever reused.
     *
     * The stored value is "$timestamp:$ownerToken", not just a timestamp —
     * the random token is what makes an acquisition individually
     * identifiable, so releaseReviewSlot() can delete-if-still-mine instead
     * of delete-by-name. Without it, a request that outlives the TTL (slow,
     * not dead) would delete a successor's freshly-reclaimed lock the moment
     * it finally reaches its finally block, reopening the duplicate-review
     * race this exists to close.
     *
     * @return array{name: string, value: string}|false lock claim on success,
     *         false if genuinely held
     */
    public static function claimReviewSlot($postId, $userId, $email)
    {
        global $wpdb;

        $identity = $userId
            ? 'u' . (int) $userId
            : 'e' . md5(strtolower(sanitize_email((string) $email)));

        $lockName = 'fct_review_lock_' . (int) $postId . '_' . $identity;
        $now = time();
        $ownerValue = $now . ':' . wp_generate_uuid4();

        $claimed = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            $lockName,
            $ownerValue
        ));

        if ($claimed) {
            return ['name' => $lockName, 'value' => $ownerValue];
        }

        // Slot already held (or the INSERT hit a real DB error — either way
        // $claimed is falsy here). Read the current holder's value and
        // reclaim only if its embedded timestamp proves stale. A null read
        // here (the row was deleted between our INSERT and this SELECT —
        // e.g. the original holder's releaseReviewSlot() landed in that
        // exact window, or a genuine query error) is treated the same as
        // live contention: we deny this attempt rather than retry the
        // INSERT. The narrow race this misses — the slot was actually free
        // by the time we checked — self-heals on the caller's next submit
        // attempt; we never reclaim without positive proof the existing
        // lock is old.
        $existingValue = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $lockName
        ));

        if ($existingValue === null || (int) strtok($existingValue, ':') > $now - self::REVIEW_LOCK_TTL) {
            return false;
        }

        $reclaimed = $wpdb->update(
            $wpdb->options,
            ['option_value' => $ownerValue],
            ['option_name' => $lockName, 'option_value' => $existingValue]
        );

        return $reclaimed ? ['name' => $lockName, 'value' => $ownerValue] : false;
    }

    public static function releaseReviewSlot($lock): void
    {
        if (!$lock) {
            return;
        }

        global $wpdb;

        // Compare-and-delete on the exact owner value this call claimed —
        // never delete by option_name alone. If a later request's TTL
        // reclaim has since overwritten the value, this release no longer
        // matches and is a safe no-op, so a request that outlives the TTL
        // (slow, not dead) can never tear down a successor's live lock.
        $wpdb->delete($wpdb->options, [
            'option_name'  => $lock['name'],
            'option_value' => $lock['value'],
        ]);
    }

    public static function getReviewSettings(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $moduleSettings = ModuleSettings::getSettings('reviews');

        if (!$moduleSettings || !\is_array($moduleSettings)) {
            $moduleSettings = [];
        }

        $settings = [
            'reviews_enabled'        => Arr::get($moduleSettings, 'active', 'yes'),
            'review_permission_mode' => Arr::get($moduleSettings, 'review_permission_mode', Status::REVIEW_PERMISSION_VERIFIED_BUYERS),
            'auto_approve_reviews'   => Arr::get($moduleSettings, 'auto_approve_reviews', 'no'),
            'reviews_per_page'       => (int) Arr::get($moduleSettings, 'reviews_per_page', 10),
            'enable_star_rating'     => Arr::get($moduleSettings, 'enable_star_rating', 'yes'),
            'star_rating_required'   => Arr::get($moduleSettings, 'star_rating_required', 'yes'),
            'show_verified_badge'    => Arr::get($moduleSettings, 'show_verified_badge', 'yes'),
        ];

        // Merge any additional keys (e.g. PRO settings) from module settings
        unset($moduleSettings['active']);
        $cached = array_merge($moduleSettings, $settings);
        return $cached;
    }

    public static function getProductRatingSummary($postId): array
    {
        // Single source of truth: detail.other_info, maintained by recalculateProductRatings().
        // All consumers (product cards, admin list, reviews page) read from the same cache.
        $productDetail = ProductDetail::query()->where('post_id', $postId)->first();
        $productOtherInfo = ($productDetail && $productDetail->other_info) ? $productDetail->other_info : [];

        // If rating_breakdown is missing (product cached before this field was added),
        // backfill it once, then re-read.
        //
        // Guarded on $productDetail: a product with no fct_product_details row reaches
        // here with $productOtherInfo = [], which passes the array_key_exists check
        // below — calling refresh() on the null would fatal on a public request.
        //
        // The backfill is a write inside a read path, so it is throttled with a short
        // lock: without it every concurrent visitor to an unbackfilled product runs the
        // same two aggregate queries and the same save.
        if ($productDetail && !array_key_exists('rating_breakdown', $productOtherInfo)) {
            $lockKey = 'fct_rating_backfill_' . (int) $postId;

            if (!get_transient($lockKey)) {
                set_transient($lockKey, 1, MINUTE_IN_SECONDS);
                ProductReviewResource::recalculateProductRatings($postId);
                $productDetail->refresh();
                $productOtherInfo = $productDetail->other_info ?: [];
            }
        }

        $defaultBreakdown = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];

        $starBreakdown = $defaultBreakdown;
        if (array_key_exists('rating_breakdown', $productOtherInfo) && is_array($productOtherInfo['rating_breakdown'])) {
            foreach ($productOtherInfo['rating_breakdown'] as $starValue => $reviewCount) {
                $starValue = (int) $starValue;
                if ($starValue >= 1 && $starValue <= 5) {
                    $starBreakdown[$starValue] = (int) $reviewCount;
                }
            }
        }

        $totalReviews = array_key_exists('review_count', $productOtherInfo) ? (int) $productOtherInfo['review_count'] : 0;
        $averageRating = array_key_exists('average_rating', $productOtherInfo) ? round((float) $productOtherInfo['average_rating'], 2) : 0;

        return [
            'breakdown' => $starBreakdown,
            'total'     => $totalReviews,
            'average'   => $averageRating,
        ];
    }
}
