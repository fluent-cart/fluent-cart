<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\Framework\Support\Arr;

/**
 * Server-side renderer for the storefront review list rows.
 *
 * The rows used to be assembled in Reviews.js from the JSON payload; they
 * are built here now, the way the shop app and the thread modal already
 * render on the server, and the JS only swaps the returned markup in. The
 * classes and data attributes are a contract: Pro decorates the rendered
 * rows (vote buttons, media lightbox) by querying them.
 */
class ReviewListRenderer
{
    /**
     * @var array
     */
    protected $options;

    public function __construct(array $options = [])
    {
        $this->options = wp_parse_args($options, [
            'show_reviewer'   => true,
            'show_date'       => true,
            'show_verified'   => true,
            'show_view_reply' => true,
            'show_avatar'     => true,
            'show_title'      => true,
            'show_content'    => true,
            'show_photos'     => true,
            'show_footer'     => true,
            'show_variation'  => true,
            'can_thread'      => false,
            // A caller that builds the rows itself — the Review List block when
            // an editor has filled it with blocks. Given the page's reviews it
            // returns all of them, because what repeats is the Review Item
            // block's business, not this renderer's. Unset, the renderer draws
            // its own rows, which is what the shortcode and the product page
            // get.
            'rows_renderer'   => null,
            // How the pager draws itself. A block attribute, so it travels
            // with the request the same way the display flags do — the script
            // re-renders the pager on every page change and would otherwise
            // hand back the default one on the first click.
            'pagination_type' => 'numbers',
            // Words to show before a Read more, 0 for the whole review. The
            // Review Content block carries its own; this is the same limit
            // for a row the renderer draws itself, which had no way to say
            // it and so always printed reviews in full.
            'max_words'       => 0,
            // The review attachments. media_visible is how many tiles a
            // row shows before the + tile takes over, 0 for all of them;
            // media_width and media_height are the tile size in pixels, 0 for
            // the stylesheet's own. The Review Photos block carries the same
            // three; these are for a row this renderer draws itself.
            'media_visible'   => 0,
            'media_width'     => 0,
            'media_height'    => 0,
            // A tile as wide as the gallery instead of media_width, which puts
            // one attachment on each line.
            'media_full_width' => false,
            // Pro, and refused without it by ReviewThreadMarkup: the
            // attachment becomes the top of the card (flush) or fills it and
            // takes the rest of the review over itself (backdrop).
            'media_flush'      => false,
            'media_backdrop'   => false,
            // Where the + counting the attachments past media_visible goes:
            // 'overlay' on the last visible one, 'tile' on one of its own, or
            // 'none' at all.
            'media_more'       => 'overlay',
            // How the row is arranged, mirroring LayoutPresets::row(). A
            // preset that wants the photograph to sell the card puts it
            // first; a testimonial leads with the quote and signs off with
            // the badge underneath rather than beside the name.
            'photos_first'     => false,
            'rating_first'     => false,
            'badge_last'       => false,
            // Who wrote it and how they rated it. Off, the card is whatever
            // is left -- which for a photo strip is the photograph alone.
            'show_meta'        => true,
            // A class the preset puts on the card. Whitelisted rather than
            // taken as given: this value makes the round trip through the
            // public reviews endpoint, so it is request input by the time a
            // second page renders.
            'item_class'       => '',
        ]);
    }

    /**
     * The pagers this renders.
     *
     * One list, because three places have to agree on it: the block that
     * saves the choice, the container attribute the script sends back, and
     * the endpoint that re-renders the pager from it. A type any one of them
     * did not know would come back as the numbered pager on the first page
     * change and never return.
     *
     * @return array<int, string>
     */
    public static function paginationTypes(): array
    {
        return ['numbers', 'fraction', 'bullets'];
    }

    /**
     * The rendered rows for one page of reviews.
     *
     * @param array $reviews Row arrays from the serialized, filtered response.
     * @return string
     */
    public function renderReviewItems(array $reviews): string
    {
        if (!$reviews) {
            return static::emptyStateHtml();
        }

        $rowsRenderer = $this->options['rows_renderer'];

        if (is_callable($rowsRenderer)) {
            return (string) call_user_func($rowsRenderer, $reviews);
        }

        $html = '';

        foreach ($reviews as $review) {
            $html .= $this->renderReviewItem((array) $review);
        }

        return $html;
    }

    /**
     * What a list with nothing to show says.
     *
     * Shared, because the composed row draws it too: two spellings of the same
     * empty state would be two things for the storefront script to find, and
     * it looks for [data-reviews-empty].
     *
     * @return string
     */
    /**
     * The card classes a preset may ask for.
     *
     * A closed list, because renderReviewItem() prints this into a class
     * attribute and the value arrives from the public reviews endpoint on
     * every sort, filter and page. Anything unrecognised becomes nothing.
     *
     * @return array<int, string>
     */
    public static function itemClasses(): array
    {
        return ['fct-review-photo-only', 'fct-review-photo-grid', 'fct-review-testimonial'];
    }

    /**
     * @param mixed $value
     * @return string
     */
    public static function itemClass($value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return in_array($value, static::itemClasses(), true) ? $value : '';
    }

    public static function emptyStateHtml(): string
    {
        return '<p class="fct-reviews-empty" data-reviews-empty>'
            . esc_html__('No reviews yet. Be the first to write a review!', 'fluent-cart')
            . '</p>';
    }

    /**
     * The windowed pagination the list used to build client-side:
     * 1 … current-1 current current+1 … last, with prev/next at the ends.
     * Empty when there is a single page.
     *
     * @param array $paginator The serialized paginator (current_page, last_page).
     * @return string
     */
    public function paginationHtml(array $paginator): string
    {
        $lastPage = (int) Arr::get($paginator, 'last_page', 1);
        $currentPage = (int) Arr::get($paginator, 'current_page', 1);

        if ($lastPage <= 1) {
            return '';
        }

        $paginationType = (string) $this->options['pagination_type'];

        if ($paginationType === 'fraction') {
            return $this->fractionPagination($currentPage, $lastPage);
        }

        if ($paginationType === 'bullets') {
            return $this->bulletPagination($currentPage, $lastPage);
        }

        return $this->numberedPagination($currentPage, $lastPage);
    }

    /**
     * The default pager: first page, last page, and the ones either side of
     * where the reader is, with an ellipsis across each gap.
     */
    protected function numberedPagination(int $current, int $last): string
    {
        $pages = [1 => true, $last => true];
        for ($i = max(2, $current - 1); $i <= min($last - 1, $current + 1); $i++) {
            $pages[$i] = true;
        }
        $pages = array_keys($pages);
        sort($pages);

        $html = '<div class="fct-reviews-pagination-inner">';

        $html .= static::navButton('left', $current - 1, $current <= 1);

        $previousPage = 0;
        foreach ($pages as $page) {
            if ($page - $previousPage > 1) {
                $html .= '<span class="fct-reviews-page-ellipsis" aria-hidden="true">…</span>';
            }

            $html .= '<button class="fct-reviews-page-btn' . ($page === $current ? ' active' : '') . '"'
                . ' data-page="' . esc_attr($page) . '"'
                . ($page === $current ? ' aria-current="page"' : '')
                . '>' . esc_html($page) . '</button>';

            $previousPage = $page;
        }

        $html .= static::navButton('right', $current + 1, $current >= $last);

        return $html . '</div>';
    }

    /**
     * One review row — markup parity with what Reviews.js used to build.
     *
     * @param array $review
     * @return string
     */
    protected function renderReviewItem(array $review): string
    {
        $reviewerName = (string) Arr::get($review, 'reviewer_name', '');
        $rating = (int) Arr::get($review, 'rating', 0);

        $nameLineParts = [];

        if ($this->options['show_reviewer'] && $reviewerName !== '') {
            $nameLineParts[] = '<span class="fct-review-item-author">' . esc_html($reviewerName) . '</span>';
        }

        // Stars above the words rather than beside the name: on a card the
        // rating is the headline, and a wall of cards is read by its stars
        // long before any of its sentences. Block-level, matching what the
        // Review Rating block's own wrapper draws.
        $ratingFirst = !empty($this->options['rating_first']);
        $lead = '';
        if ($rating > 0) {
            $stars = ReviewThreadMarkup::starsHtml($rating);

            if ($ratingFirst) {
                $lead = '<div class="fct-review-item-stars">' . $stars . '</div>';
            } else {
                $nameLineParts[] = '<span class="fct-review-item-stars">' . $stars . '</span>';
            }
        }

        // Signed off at the end rather than beside the name: on a card the eye
        // reads downwards, so the badge is the last word and not part of the
        // greeting. Wrapped for the same reason the template wraps it -- the
        // signoff rule is what centres it under the byline.
        $badgeLast = !empty($this->options['badge_last']);
        $sign = '';
        if ($this->options['show_verified'] && !empty($review['is_verified'])) {
            $badge = '<span class="fct-review-verified">' . esc_html__('Verified Purchase', 'fluent-cart') . '</span>';

            if ($badgeLast) {
                $sign = '<div class="fct-review-item-signoff">' . $badge . '</div>';
            } else {
                $nameLineParts[] = $badge;
            }
        }

        // Which item the review is about, when it names one — the label the
        // buyer saw on their order, attached by attachItemLabels().
        $itemLabel = trim((string) Arr::get($review, 'item_label', ''));
        if ($this->options['show_variation'] && $itemLabel !== '') {
            $nameLineParts[] = '<span class="fct-review-item-variant">' . esc_html($itemLabel) . '</span>';
        }

        $metaTextParts = [];

        if ($nameLineParts) {
            $metaTextParts[] = '<div class="fct-review-item-name-line">' . implode('', $nameLineParts) . '</div>';
        }

        if ($this->options['show_date'] && !empty($review['created_at'])) {
            $metaTextParts[] = '<span class="fct-review-item-date">' . esc_html(ReviewThreadMarkup::relativeDate($review['created_at'])) . '</span>';
        }

        // Who wrote it. Dropped whole rather than field by field: a photo
        // strip wants the photograph and nothing else, and an empty header
        // still draws its own box.
        $meta = '';
        if (!empty($this->options['show_meta'])) {
            $headerLeft = '<div class="fct-review-item-header-left">'
                . ($this->options['show_avatar'] ? ReviewThreadMarkup::avatarHtml((string) Arr::get($review, 'photo', '')) : '')
                . '<div class="fct-review-item-meta-text">' . implode('', $metaTextParts) . '</div>'
                . '</div>';

            $meta = '<div class="fct-review-item-header">' . $headerLeft . '</div>';
        }

        $title = '';
        if ($this->options['show_title'] && !empty($review['title'])) {
            $title = '<div class="fct-review-item-title">' . esc_html($review['title']) . '</div>';
        }

        $content = $this->options['show_content']
            ? '<div class="fct-review-item-content"' . $this->maxWordsAttribute() . '>'
                . esc_html((string) Arr::get($review, 'content', '')) . '</div>'
            : '';

        $media = $this->options['show_photos'] ? $this->renderMediaGallery($review) : '';

        // The row's actions, drawn whether or not anything has landed in it
        // yet. PRO appends the Helpful buttons to this element from the
        // browser, and it looks the element up rather than creating one -- so a
        // row that only drew the footer when it happened to have a reply sent
        // the buttons to the card instead, where the footer's own styling does
        // not reach them. That made a review with a reply and a review without
        // one wear different buttons. Empty it collapses: reviews.scss hides
        // .fct-review-footer:empty, margin and all.
        $reply = $this->options['show_footer']
            ? '<div class="fct-review-footer">' . $this->renderViewReplyButton($review) . '</div>'
            : '';

        $body = $title . $content;

        // Two arrangements, the same two LayoutPresets::row() builds.
        // Photographs first is a card the picture sells and the words explain;
        // otherwise the words lead and the picture supports.
        $inside = !empty($this->options['photos_first'])
            ? $media . $lead . $body . $meta . $sign . $reply
            : $meta . $lead . $body . $media . $sign . $reply;

        $itemClass = static::itemClass(Arr::get($this->options, 'item_class', ''));

        return '<div class="fct-review-item' . ($itemClass ? ' ' . $itemClass : '') . '"'
            . ' data-review-id="' . esc_attr((string) Arr::get($review, 'id', '')) . '"'
            . ' data-user-vote="' . (int) Arr::get($review, 'user_vote', 0) . '"'
            . ' data-helpful-count="' . (int) Arr::get($review, 'helpful_count', 0) . '"'
            . ' data-not-helpful-count="' . (int) Arr::get($review, 'not_helpful_count', 0) . '"'
            . '>'
            . $inside
            . '</div>';
    }

    /**
     * The word limit as an attribute, or nothing when there is none.
     *
     * review-clamp.js reads data-max-words off the content element and is
     * indifferent to which layout drew it, so a storefront row only ever
     * needed the attribute to behave like a composed one.
     */
    protected function maxWordsAttribute(): string
    {
        $maxWords = max(0, (int) $this->options['max_words']);

        return $maxWords > 0 ? ' data-max-words="' . esc_attr((string) $maxWords) . '"' : '';
    }

    /**
     * The media gallery (PRO attaches the items to the response).
     *
     * @param array $review
     * @return string
     */
    protected function renderMediaGallery(array $review): string
    {
        $media = Arr::get($review, 'media', []);

        if (empty($media) || !is_array($media)) {
            return '';
        }

        return ReviewThreadMarkup::mediaGalleryHtml($media, 'list', [
            'visible'   => (int) $this->options['media_visible'],
            'width'     => (int) $this->options['media_width'],
            'height'    => (int) $this->options['media_height'],
            'fullWidth' => (bool) $this->options['media_full_width'],
            'flush'     => (bool) $this->options['media_flush'],
            'backdrop'  => (bool) $this->options['media_backdrop'],
            'more'      => (string) $this->options['media_more'],
        ]);
    }

    /**
     * "Page 2 of 7" between the two arrows.
     *
     * For a list with many pages, where a row of numbers is mostly ellipses.
     * The arrows carry the page to go to, so the script's [data-page] binding
     * works unchanged — only the middle is different.
     */
    protected function fractionPagination(int $current, int $last): string
    {
        return '<div class="fct-reviews-pagination-inner is-fraction">'
            . static::navButton('left', $current - 1, $current <= 1)
            . '<span class="fct-reviews-page-fraction">'
            . sprintf(
                /* translators: 1: current page number, 2: total number of pages */
                esc_html__('Page %1$s of %2$s', 'fluent-cart'),
                '<strong>' . esc_html((string) $current) . '</strong>',
                esc_html((string) $last)
            )
            . '</span>'
            . static::navButton('right', $current + 1, $current >= $last)
            . '</div>';
    }

    /**
     * A dot per page.
     *
     * Only for a list short enough to count at a glance — past that the dots
     * stop being a control anyone can aim at, and the numbered pager is what
     * a reader gets instead. The label is what makes a dot usable at all: it
     * has no text of its own.
     */
    protected function bulletPagination(int $current, int $last): string
    {
        if ($last > 10) {
            return $this->numberedPagination($current, $last);
        }

        $html = '<div class="fct-reviews-pagination-inner is-bullets">'
            . static::navButton('left', $current - 1, $current <= 1);

        for ($page = 1; $page <= $last; $page++) {
            $html .= '<button type="button" class="fct-reviews-page-bullet' . ($page === $current ? ' active' : '') . '"'
                . ' data-page="' . esc_attr((string) $page) . '"'
                . ($page === $current ? ' aria-current="page"' : '')
                . ' aria-label="' . esc_attr(sprintf(
                    /* translators: %s: page number */
                    __('Page %s', 'fluent-cart'),
                    $page
                )) . '"></button>';
        }

        return $html . static::navButton('right', $current + 1, $current >= $last) . '</div>';
    }

    /**
     * The previous/next arrow both alternative pagers share.
     */
    protected static function navButton(string $direction, int $page, bool $disabled): string
    {
        $label = $direction === 'left'
            ? __('Previous page', 'fluent-cart')
            : __('Next page', 'fluent-cart');

        return '<button type="button" class="fct-reviews-page-btn fct-reviews-page-nav" data-page="' . esc_attr((string) $page) . '"'
            . ($disabled ? ' disabled' : '')
            . ' aria-label="' . esc_attr($label) . '">' . static::chevronSvg($direction) . '</button>';
    }

    /**
     * Chevron for the pagination prev/next buttons.
     *
     * @param string $direction 'left' or 'right'.
     * @return string
     */
    protected static function chevronSvg($direction): string
    {
        $path = $direction === 'left' ? 'm15 18-6-6 6-6' : 'm9 18 6-6-6-6';

        return '<svg class="fct-nav-arrow-svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' . $path . '"/></svg>';
    }

    /**
     * The View Reply / Reply trigger. With replies it opens the read-only
     * thread; with none, it only renders as Reply for the review owner when
     * threaded replies are enabled.
     *
     * @param array $review
     * @return string
     */
    protected function renderViewReplyButton(array $review): string
    {
        if (!$this->options['show_view_reply']) {
            return '';
        }

        $replyCount = (int) Arr::get($review, 'reply_count', 0);
        $label = '';

        if ($replyCount > 0) {
            $label = __('View Reply', 'fluent-cart');
        } elseif ($this->options['can_thread'] && !empty($review['is_owner'])) {
            $label = __('Reply', 'fluent-cart');
        }

        if ($label === '') {
            return '';
        }

        $icon = ReviewThreadMarkup::replyIconSvg();

        return '<button type="button" class="fct-review-view-replies" data-view-replies aria-haspopup="dialog" data-review-id="' . esc_attr((string) Arr::get($review, 'id', '')) . '">'
            . $icon
            . '<span>' . esc_html($label) . '</span>'
            . '</button>';
    }
}
