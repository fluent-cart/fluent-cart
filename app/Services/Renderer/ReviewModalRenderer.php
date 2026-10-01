<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;

/**
 * Server-rendered markup for the review thread modal.
 *
 * The storefront JS only paints the overlay shell and a loader, then swaps
 * in this output — the same split ProductModalRenderer uses for the product
 * modal. Rendering here means the review, its replies and any extension
 * footer arrive in one request instead of the modal opening empty and
 * fetching replies afterwards.
 */
class ReviewModalRenderer
{
    protected $review;

    protected $productName;

    public function __construct(ProductReview $review, $productName = '')
    {
        $this->review = $review;
        $this->productName = $productName;
    }

    public function render()
    {
        $heading = $this->productName
            /* translators: %s - product name */
            ? sprintf(__("%s's Review", 'fluent-cart'), $this->productName)
            : __('Review Thread', 'fluent-cart');

        $approvedReplies = $this->review->replies()
            ->where('status', Status::REVIEW_APPROVED)
            ->orderBy('created_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->limit(50)
            ->get();
        ?>
        <div class="fct-review-modal" data-review-modal tabindex="-1">
            <div class="fct-review-modal-header" data-review-modal-header>
                <h3 class="fct-review-modal-heading" data-review-modal-heading><?php echo esc_html($heading); ?></h3>
                <button type="button" class="fct-review-modal-close" data-modal-close
                        aria-label="<?php esc_attr_e('Close', 'fluent-cart'); ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true" focusable="false"><path d="M12.8337 1.16663L1.16699 12.8333M1.16699 1.16663L12.8337 12.8333" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </button>
            </div>
            <div class="fct-review-modal-body" data-review-modal-body>
                <div class="fct-review-modal-original" data-review-original>
                    <?php $this->renderAuthorRow($this->review); ?>
                    <?php
                    // The item the review names, if any. Resolved here rather
                    // than by the caller so every route into this modal shows
                    // it; a single review is a one-row batch.
                    ProductReviewService::attachItemLabels([$this->review]);
                    $itemLabel = trim((string) $this->review->getAttribute('item_label'));
                    ?>
                    <?php if ($itemLabel !== '') : ?>
                        <div class="fct-review-modal-item" data-review-item><?php echo esc_html($itemLabel); ?></div>
                    <?php endif; ?>
                    <?php if ($this->review->title) : ?>
                        <div class="fct-review-modal-title" data-review-title><?php echo esc_html($this->review->title); ?></div>
                    <?php endif; ?>
                    <div class="fct-review-modal-content" data-review-content><?php echo esc_html($this->review->content); ?></div>
                    <?php $this->renderMedia($this->review); ?>
                </div>
                <div class="fct-review-modal-replies" data-review-replies>
                    <?php if ($approvedReplies->isEmpty()) : ?>
                        <div class="fct-review-modal-empty" data-review-replies-empty><?php esc_html_e('No replies yet.', 'fluent-cart'); ?></div>
                    <?php else : ?>
                        <?php foreach ($approvedReplies as $reply) : ?>
                            <?php $this->renderReply($reply); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php
                /**
                 * Extension point for the modal footer — Pro renders the
                 * reviewer reply form here when threaded replies are on.
                 *
                 * A single named-array payload, the shape the great majority
                 * of fluent_cart hooks use, so keys can be added later
                 * without changing the listener signature.
                 *
                 * @param array $payload ['review' => ProductReview]
                 */
                do_action('fluent_cart/review/modal_footer', ['review' => $this->review]);
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * One reply row.
     *
     * Public so the reply endpoint can return the same markup for a reply
     * just posted — the storefront appends it verbatim instead of rebuilding
     * the structure in JS, where it would drift from this renderer.
     */
    public function renderReply(ProductReview $reply)
    {
        ?>
        <div class="fct-review-modal-reply" data-review-reply data-reply-id="<?php echo esc_attr($reply->id); ?>">
            <?php $this->renderAuthorRow($reply); ?>
            <div class="fct-review-modal-content" data-review-content><?php echo esc_html($reply->content); ?></div>
        </div>
        <?php
    }

    /**
     * Rendered markup for a single reply, for callers that need it as a
     * string (the reply endpoint's JSON response).
     */
    public static function replyHtml(ProductReview $reply): string
    {
        ob_start();
        (new static($reply))->renderReply($reply);

        return (string) ob_get_clean();
    }

    /**
     * Author line for a thread entry — the review itself or one of its
     * replies, both of which are ProductReview rows.
     */
    /**
     * The avatar for one thread row: the row's photo when there is one, a
     * person icon as the placeholder underneath. A photo that fails to load
     * — Gravatar default=404 does exactly that for an address with no
     * avatar — removes itself and the icon shows, so the markup needs no
     * script of its own.
     *
     * @param ProductReview $entry
     * @param string $authorName
     * @return void
     */
    protected function renderAvatar(ProductReview $entry, $authorName)
    {
        echo ReviewThreadMarkup::avatarHtml((string) $entry->photo); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    protected function renderAuthorRow(ProductReview $entry)
    {
        $authorName = $entry->reviewer_name ?: __('Anonymous', 'fluent-cart');
        ?>
        <div class="fct-review-modal-author-row" data-review-author-row>
            <?php $this->renderAvatar($entry, $authorName); ?>
            <div class="fct-review-modal-meta-text">
                <div class="fct-review-modal-name-line">
                    <span class="fct-review-modal-author" data-review-author><?php echo esc_html($authorName); ?></span>
                    <?php if ($entry->rating > 0) : ?>
                        <span class="fct-review-modal-stars" data-review-stars><?php $this->renderStars((int) $entry->rating); ?></span>
                    <?php endif; ?>
                </div>
                <div class="fct-review-modal-date" data-review-date><?php echo esc_html($this->relativeDate($entry->created_at)); ?></div>
            </div>
        </div>
        <?php
    }

    protected function renderMedia(ProductReview $entry)
    {
        $mediaItems = $entry->media;

        if (empty($mediaItems)) {
            return;
        }
        ?>
        <div class="fct-review-modal-media" data-review-media>
            <?php foreach ($mediaItems as $mediaItem) : ?>
                <?php if (is_array($mediaItem)) : ?>
                    <?php echo ReviewThreadMarkup::mediaItemHtml($mediaItem, 'modal'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts; filter consumers own their markup ?>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Star row, matching FluentCartProductReviews#getStarsHtml in Reviews.js:
     * the same fct-star-filled / fct-star-empty classes the stylesheet colours,
     * preceded by the screen-reader label, since the stars themselves are
     * aria-hidden.
     */
    protected function renderStars($rating)
    {
        $rating = (int) $rating;

        printf(
            '<span class="fct-sr-only">%s</span>',
            esc_html(sprintf(
                /* translators: %d - star rating out of five */
                __('Rated %d out of 5', 'fluent-cart'),
                $rating
            ))
        );

        for ($position = 1; $position <= 5; $position++) {
            $starClass = $position <= $rating ? 'fct-star fct-star-filled' : 'fct-star fct-star-empty';
            echo '<span class="' . esc_attr($starClass) . '" data-review-star aria-hidden="true">' . ReviewThreadMarkup::starSvg() . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /**
     * Matches the storefront's relative-time labels so a server-rendered
     * modal reads the same as the JS-rendered list behind it.
     */
    protected function relativeDate($createdAt)
    {
        return ReviewThreadMarkup::relativeDate($createdAt);
    }
}
