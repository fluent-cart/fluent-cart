<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\ProductReviewService;

/**
 * Markup pieces shared by every server-rendered review surface — the thread
 * modal and the review list. One implementation of the avatar, the palette
 * colour behind it, and the relative date, so the surfaces cannot drift.
 */
class ReviewThreadMarkup
{
    /**
     * The avatar for one row: the photo when there is one, the person icon
     * underneath as the placeholder. A photo that fails to load — Gravatar
     * default=404 does exactly that for an address with no avatar — removes
     * itself and the icon shows, so the markup needs no script of its own.
     *
     * @param string $photoUrl
     * @return string
     */
    public static function avatarHtml($photoUrl): string
    {
        $photoTag = '';

        if ($photoUrl) {
            $photoTag = '<img class="fct-review-avatar-photo" src="' . esc_url($photoUrl) . '" alt="" loading="lazy" onerror="this.remove()"/>';
        }

        $placeholder = '<svg class="fct-review-avatar-placeholder" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none" aria-hidden="true">'
            . '<path d="M16.6667 17.5V15.8333C16.6667 14.9493 16.3155 14.1014 15.6904 13.4763C15.0653 12.8512 14.2174 12.5 13.3334 12.5H6.66671C5.78265 12.5 4.93481 12.8512 4.30968 13.4763C3.68456 14.1014 3.33337 14.9493 3.33337 15.8333V17.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>'
            . '<path d="M10 9.16667C11.8410 9.16667 13.3334 7.67428 13.3334 5.83333C13.3334 3.99238 11.8410 2.5 10 2.5C8.15909 2.5 6.66671 3.99238 6.66671 5.83333C6.66671 7.67428 8.15909 9.16667 10 9.16667Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>'
            . '</svg>';

        return '<div class="fct-review-avatar-circle" data-review-avatar>'
            . $photoTag
            . $placeholder
            . '</div>';
    }

    /**
     * Relative date for a row — "5 mins ago" while recent, the site's date
     * format once it is a month old.
     *
     * @param string $createdAt
     * @return string
     */
    public static function relativeDate($createdAt): string
    {
        if (!$createdAt) {
            return '';
        }

        $createdTimestamp = strtotime($createdAt);
        if (!$createdTimestamp) {
            return '';
        }

        $secondsAgo = time() - $createdTimestamp;

        if ($secondsAgo < MINUTE_IN_SECONDS) {
            return __('just now', 'fluent-cart');
        }

        if ($secondsAgo < MONTH_IN_SECONDS) {
            /* translators: 1: human readable time difference, e.g. "5 mins" */
            $relative = sprintf(__('%1$s ago', 'fluent-cart'), human_time_diff($createdTimestamp));

            return $relative;
        }

        return DateTime::gmtToTimezone($createdAt, wp_timezone_string())->format(get_option('date_format'));
    }

    /**
     * Star row with its screen-reader label, matching the storefront list.
     *
     * @param int $rating
     * @return string
     */
    public static function starsHtml($rating): string
    {
        $rating = (int) $rating;

        /* translators: 1: the star rating, e.g. "Rated 4 out of 5" */
        $srLabel = sprintf(__('Rated %1$d out of 5', 'fluent-cart'), $rating);
        $srLabel = esc_html($srLabel);

        $html = '<span class="fct-sr-only">' . $srLabel . '</span>';

        for ($i = 1; $i <= 5; $i++) {
            $html .= '<span class="fct-star ' . ($i <= $rating ? 'fct-star-filled' : 'fct-star-empty') . '" aria-hidden="true">' . static::starSvg() . '</span>';
        }

        return $html;
    }

    /**
     * The one star glyph every review surface draws — an SVG filled with
     * currentColor, so the existing colour classes keep working.
     *
     * @return string
     */
    /**
     * One review media item's markup, shared by the list and the modal.
     *
     * The default preserves the contract every consumer of these payloads
     * has always had: type "video" renders a <video> thumbnail with
     * data-media-type="video", anything else renders the image thumbnail —
     * so existing extension payloads keep working with no paired deploy.
     * The filter lets the extension that owns a type replace its markup,
     * the same way allowed file types are extended.
     *
     * @param array $item media item from the filtered review payload
     * @param string $context 'list' or 'modal'
     * @return string
     */
    public static function mediaItemHtml(array $item, string $context, bool $hidden = false): string
    {
        $url = esc_url((string) Arr::get($item, 'url', ''));
        $type = (string) Arr::get($item, 'type', 'image');

        // Past the visible limit the tile stays in the markup but out of the
        // layout, and stays that way: the lightbox builds its album from the
        // gallery, and an album without the hidden photos is one the + tile
        // has nothing to open into.
        $hiddenAttr = $hidden ? ' hidden' : '';

        $html = '';
        if ($url && $type === 'video') {
            // A plain box around the video, not a button: once it plays the
            // video shows its own controls, and a control nested in a button
            // is markup a keyboard and a screen reader cannot make sense of —
            // and a click the button would swallow. The box carries the thumb
            // class, where the gallery's sizing has always applied, and the
            // play overlay is the one thing in here that is pressable.
            $html = '<span class="fct-review-media-thumb fct-review-media-video"'
                . ' data-media-url="' . $url . '" data-media-type="video"' . $hiddenAttr . '>'
                . '<video src="' . $url . '" preload="metadata" playsinline></video>'
                . '<button type="button" class="fct-review-media-play"'
                . ' aria-label="' . esc_attr__('Play video', 'fluent-cart') . '"></button>'
                . '</span>';
        } elseif ($url) {
            // A button to the keyboard: Reviews.js opens it in the lightbox
            // on click, Enter or Space.
            $html = '<img class="fct-review-media-thumb" src="' . $url . '" alt=""'
                . ' role="button" tabindex="0" aria-label="' . esc_attr__('View photo', 'fluent-cart') . '"'
                . ' data-media-url="' . $url . '" data-media-type="image" loading="lazy"' . $hiddenAttr . '/>';
        }

        /**
         * The rendered markup for one review media item. Consumers owning a
         * media type return their own markup for it and are responsible for
         * escaping their output. A consumer that honours 'hidden' keeps its
         * tile out of an overflowing gallery; one that ignores it renders the
         * tile visible, as it always did.
         *
         * @param string $html the default markup
         * @param array $context ['item' => array, 'context' => 'list'|'modal', 'hidden' => bool]
         */
        return (string) apply_filters('fluent_cart/review/media_item_html', $html, [
            'item'    => $item,
            'context' => $context,
            'hidden'  => $hidden,
        ]);
    }

    /**
     * The whole gallery for one review, the markup the list rows and the
     * composed Review Photos block both draw.
     *
     * Shared so the two paths cannot disagree about how many tiles a gallery
     * shows or how big they are — the same reason mediaItemHtml is shared.
     *
     * @param array $media media items from the filtered review payload
     * @param string $context 'list' or 'modal'
     * @param array $options ['visible' => int, 'width' => int, 'height' => int]
     * @return string
     */
    public static function mediaGalleryHtml(array $media, string $context, array $options = []): string
    {
        $items = array_values(array_filter($media, 'is_array'));

        if (!$items) {
            return '';
        }

        $tiles = static::mediaTilesHtml($items, $context, $options);

        if ($tiles === '') {
            return '';
        }

        $style = static::mediaSizeStyle($options);

        // Same modifier the Review Photos block writes, so a rendered row and
        // a composed one flush a photograph alike.
        $photoStyling = ProductReviewService::isPhotoStylingAllowed();
        $flush = ($photoStyling && Arr::get($options, 'flush')) ? ' fct-review-media-gallery--flush' : '';
        $backdrop = ($photoStyling && Arr::get($options, 'backdrop')) ? ' fct-review-media-gallery--backdrop' : '';

        return '<div class="fct-review-media-gallery' . $flush . $backdrop . '"'
            . ($style ? ' style="' . esc_attr($style) . '"' : '')
            . '>' . $tiles . '</div>';
    }

    /**
     * The tiles alone, for a caller that owns the gallery element.
     *
     * The Review Photos block is that caller: its wrapper carries the block's
     * spacing support, and a second element around the gallery would stack
     * that margin on top of the gallery's own.
     *
     * @param array $media
     * @param string $context
     * @param array $options
     * @return string
     */
    public static function mediaTilesHtml(array $media, string $context, array $options = []): string
    {
        $items = array_values(array_filter($media, 'is_array'));

        if (!$items) {
            return '';
        }

        $visible = max(0, (int) Arr::get($options, 'visible', 0));
        $more = static::moreTilePlacement(Arr::get($options, 'more', 'overlay'));

        // An item can render as nothing — a payload with no URL, or a consumer
        // of the filter declining the type — so the limit counts tiles that
        // were drawn, not items that were offered. Counting the offer would
        // let a payload that renders nothing eat a place in the row: ask for
        // three and get two, with the third behind a + that stands for it.
        $shown = 0;
        $rendered = [];
        foreach ($items as $item) {
            $hidden = $visible > 0 && $shown >= $visible;
            $html = static::mediaItemHtml($item, $context, $hidden);

            if ($html === '') {
                continue;
            }

            if (!$hidden) {
                $shown++;
            }

            $rendered[] = ['html' => $html, 'hidden' => $hidden];
        }

        if (!$rendered) {
            return '';
        }

        $hiddenCount = 0;
        $lastVisible = -1;
        foreach ($rendered as $index => $tile) {
            if ($tile['hidden']) {
                $hiddenCount++;
            } else {
                $lastVisible = $index;
            }
        }

        // 'none' leaves the overflow simply hidden, with nothing announcing
        // it. The photos are still in the album, so the lightbox pages into
        // them from any visible photo — the + is the shortcut, not the only
        // way there.
        $showMore = $hiddenCount > 0 && $more !== 'none';

        // Overlaid on the last visible attachment, which needs a box around
        // the two of them: a thumbnail is an <img>, and a replaced element
        // holds nothing. With nothing visible to sit on it falls back to a
        // tile of its own.
        $overlay = $showMore && $more === 'overlay' && $lastVisible > -1;

        $tiles = '';
        foreach ($rendered as $index => $tile) {
            if ($overlay && $index === $lastVisible) {
                $tiles .= '<span class="fct-review-media-tile">'
                    . $tile['html']
                    . static::mediaMoreTileHtml($hiddenCount, true)
                    . '</span>';
                continue;
            }

            $tiles .= $tile['html'];
        }

        if ($showMore && !$overlay) {
            $tiles .= static::mediaMoreTileHtml($hiddenCount, false);
        }

        return $tiles;
    }

    /**
     * Where the + goes: over the last visible attachment, on a tile of its
     * own, or nowhere.
     *
     * Whitelisted rather than passed through, for the reason the pager type
     * is: it selects a render path, and an unknown value arriving from a
     * shortcode would otherwise draw nothing at all.
     *
     * @param mixed $value
     * @return string
     */
    public static function moreTilePlacement($value): string
    {
        $placement = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($placement, ['overlay', 'tile', 'none'], true) ? $placement : 'overlay';
    }

    /**
     * The "+2" tile that stands in for the attachments past the limit.
     *
     * A real button, because it is one: Reviews.js opens the lightbox on the
     * first photo it stands for, leaving the row as it is — the tiles behind
     * it stay hidden and the + stays put, so closing the lightbox returns the
     * visitor to the gallery they left. The count is on the attribute as well
     * as in the label, so a script reading the gallery does not have to parse
     * the "+2" out of the text — named -count because the container carries a
     * data-media-more of its own, which is the placement.
     *
     * @param int $remaining
     * @return string
     */
    protected static function mediaMoreTileHtml(int $remaining, bool $overlay = false): string
    {
        // Overlaid, it is a badge in the corner of a photo and takes none of
        // the gallery's tile sizing; on its own it is a tile like the others.
        $class = $overlay
            ? 'fct-review-media-more is-overlay'
            : 'fct-review-media-thumb fct-review-media-more';

        return '<button type="button" class="' . $class . '"'
            . ' data-media-more-count="' . esc_attr((string) $remaining) . '"'
            . ' aria-label="' . esc_attr(
                sprintf(
                    /* translators: %s: number of attachments not shown */
                    _n('View %s more attachment', 'View %s more attachments', $remaining, 'fluent-cart'),
                    number_format_i18n($remaining)
                )
            ) . '">'
            . static::mediaMoreIconSvg()
            . '<span aria-hidden="true">+' . esc_html(number_format_i18n($remaining)) . '</span>'
            . '</button>';
    }

    /**
     * The stacked-photos glyph on the + badge.
     *
     * Here rather than inline at its call site for the reason the star and the
     * reply arrow are: the editor's canvas draws the same badge, and a copy
     * that drifts is a badge that looks like a different one.
     *
     * @return string
     */
    public static function mediaMoreIconSvg(): string
    {
        return '<svg class="fct-review-media-more-icon" width="14" height="14" viewBox="0 0 24 24"'
            . ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
            . ' stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . '<rect x="3" y="3" width="18" height="18" rx="3"/>'
            . '<circle cx="8.5" cy="8.5" r="1.5"/>'
            . '<path d="M21 15l-5-5L5 21"/>'
            . '</svg>';
    }

    /**
     * The tile size as custom properties, as a style declaration.
     *
     * Inline rather than in the stylesheet because PRO's review stylesheet
     * enqueues after this plugin's and carries a plain `width: 72px` for the
     * same class — a rule of equal specificity that source order would hand
     * the win to. An inline custom property is out of that argument entirely.
     *
     * Public because the Review Photos block hands it to
     * get_block_wrapper_attributes(), which merges it with whatever the
     * block's own style supports have written.
     *
     * @param array $options
     * @return string
     */
    public static function mediaSizeStyle(array $options): string
    {
        $width = max(0, (int) Arr::get($options, 'width', 0));
        $height = max(0, (int) Arr::get($options, 'height', 0));

        $style = '';
        if (Arr::get($options, 'fullWidth')) {
            // A tile as wide as the gallery, which puts one on each line: the
            // tiles are flex items that do not shrink, so a 100% basis wraps
            // every one of them. The pixel width is the setting it replaces,
            // so it is not also printed.
            $style .= '--fct-review-thumb-w:100%;';

            // With no height of its own a full-width tile takes the photo's,
            // which is the only height that does not crop it: the thumbnail
            // covers its box, and a photo a thousand pixels wide squeezed into
            // a 72px strip is a sliver of one. A height that was asked for is
            // still honoured — that is how a banner-shaped row is made.
            if ($height < 1) {
                $style .= '--fct-review-thumb-h:auto;';
            }
        } elseif ($width > 0) {
            $style .= '--fct-review-thumb-w:' . $width . 'px;';
        }

        if ($height > 0) {
            $style .= '--fct-review-thumb-h:' . $height . 'px;';
        }

        return $style;
    }

    /**
     * The curved arrow on the View Reply button.
     *
     * Here rather than inline at its two call sites — the storefront row and
     * the Review Reply block — because those two draw the same button and a
     * copy that drifts is a button that looks like a different one.
     *
     * @return string
     */
    public static function replyIconSvg(): string
    {
        return '<svg class="fct-review-reply-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 17 4 12 9 7"/><path d="M20 18v-2a4 4 0 0 0-4-4H4"/></svg>';
    }

    public static function starSvg(): string
    {
        return '<svg class="fct-star-svg" width="1em" height="1em" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12 2.25l2.955 6.377 6.98.638-5.268 4.62 1.558 6.865L12 17.155 5.775 20.75l1.558-6.865-5.268-4.62 6.98-.638L12 2.25z"/></svg>';
    }
}
