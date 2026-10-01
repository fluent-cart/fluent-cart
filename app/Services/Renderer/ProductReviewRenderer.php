<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Modules\Templating\TemplateActions;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\App\Hooks\Handlers\BlockEditors\ProductReviewList\InnerBlocks\InnerBlocks;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class ProductReviewRenderer
{
    /**
     * The largest a media tile may be asked to render.
     *
     * It comes from a block attribute or a shortcode attribute, so it is
     * editor input: a tile of 40000px is a page nobody can scroll past. How
     * MANY tiles a row may show is not a constant — it is the store's own
     * max photos per review, read by mediaVisibleCount().
     */
    const MAX_MEDIA_TILE_PX = 400;

    /**
     * What the store allows per review when it has not said, matching PRO's
     * own default in ReviewMediaService::getMaxPhotosPerReview().
     */
    const DEFAULT_MAX_PHOTOS_PER_REVIEW = 5;

    /**
     * The star colour a section gets when its block asks for none.
     *
     * reviews.scss falls back to this same value (--fct-review-star-color), so
     * a section left on it prints no override and the stylesheet paints it.
     * Anything else the block asks for is printed as --fct-star-color. Keep
     * the two in step: tests/js/reviews-palette-colours.test.js checks them.
     */
    const DEFAULT_STAR_COLOR = '#f59e0b';

    /**
     * The empty-star colour; reviews.scss and product-card.scss fall back to it
     * (--fct-review-star-empty-color).
     */
    const DEFAULT_EMPTY_STAR_COLOR = '#d1d5db';

    /**
     * The store-wide star colours.
     *
     * Stars are not palette colours, so Appearance has no picker for them; a
     * developer sets them through `fluent_cart/reviews/star_colors`. Each value
     * must be a hex colour, otherwise its default stands.
     *
     * @return array ['filled' => hex, 'empty' => hex]
     */
    public static function starColors(): array
    {
        $defaults = [
            'filled' => self::DEFAULT_STAR_COLOR,
            'empty'  => self::DEFAULT_EMPTY_STAR_COLOR,
        ];

        /**
         * Filter the store-wide review star colours.
         *
         * @param array $colors ['filled' => hex, 'empty' => hex]
         */
        $filtered = apply_filters('fluent_cart/reviews/star_colors', $defaults);

        if (!is_array($filtered)) {
            return $defaults;
        }

        $colors = [];

        foreach ($defaults as $key => $default) {
            $hex = sanitize_hex_color((string) Arr::get($filtered, $key, ''));
            $colors[$key] = $hex ? strtolower($hex) : $default;
        }

        return $colors;
    }

    /**
     * The star colours the filter changed, as the variables every star reads.
     *
     * `:root:root` rather than `:root`: reviews.scss and product-card.scss
     * declare the defaults on `:root`, and either can load after the head, so
     * source order alone would not decide it.
     *
     * @return string CSS, or '' while the defaults stand.
     */
    public static function starColorCss(): string
    {
        $colors = self::starColors();
        $vars = [
            'filled' => ['--fct-review-star-color', self::DEFAULT_STAR_COLOR],
            'empty'  => ['--fct-review-star-empty-color', self::DEFAULT_EMPTY_STAR_COLOR],
        ];

        $declarations = '';

        foreach ($vars as $key => $var) {
            if ($colors[$key] !== $var[1]) {
                $declarations .= $var[0] . ':' . $colors[$key] . ';';
            }
        }

        return $declarations === '' ? '' : ':root:root{' . $declarations . '}';
    }

    protected $postId;
    protected $settings;
    protected $renderOptions;

    /**
     * Identifies this renderer's trigger and its form to each other.
     *
     * Two Write a Review blocks can point at the same product with different
     * settings — one a modal, one a drawer. Without a name tying each button
     * to its own form, every button for a product opens whichever form
     * happened to initialise first, so the modal button opens the drawer.
     *
     * Both call sites that emit a trigger render the matching form from the
     * same instance, so an instance-scoped id pairs them.
     *
     * @var string
     */
    protected $instanceId;

    /** @var bool renderForm() has printed this instance's form */
    protected $formRendered = false;

    protected static $instanceCount = 0;

    public function __construct($postId, $options = [])
    {
        $this->postId = $postId;
        $this->instanceId = 'fct-review-form-' . $postId . '-' . (++static::$instanceCount);
        $this->settings = ProductReviewService::getReviewSettings();
        $showVerifiedBadge = !isset($this->settings['show_verified_badge']) || $this->settings['show_verified_badge'] === 'yes';
        $this->renderOptions = apply_filters('fluent_cart/review/renderer_options', wp_parse_args($options, [
            'showSummary'       => true,
            'summaryMode'       => 'side',
            'showCount'         => true,
            'showSortControls'  => true,
            // The star chips, separately from the sort control beside them.
            // One flag covered both because the blocks only ever wanted both;
            // the shortcode can ask for a list that filters but does not sort,
            // or the reverse. Without an explicit chips option, preserve the
            // old flag's behavior for existing renderer and block callers.
            'showFilterChips'   => Arr::get($options, 'showSortControls', true),
            // Only reviews carrying photos. A query filter, not a display one:
            // the rest never reach the page, so the count and the pager agree
            // with what is on screen.
            'hasMedia'          => false,
            'showVerifiedBadge' => $showVerifiedBadge,
            'showReviewDate'    => true,
            'showReviewerName'  => true,
            'showAvatar'        => true,
            'showTitle'         => true,
            'showContent'       => true,
            'showPhotos'        => true,
            'showFooter'        => true,
            'showVariation'     => true,
            'starColor'         => '#f59e0b',
            'defaultSortBy'     => 'created_at',
            'defaultSortOrder'  => 'DESC',
            'perPage'           => 0,
            'showViewReply'     => true,
            // The form has two independent axes. 'layout' paces the fields:
            // 'steps' walks a wizard, 'inline' shows everything at once.
            // 'container' is the chrome: a side 'drawer', a centred 'modal',
            // or 'none' — printed straight onto the page with no trigger.
            'layout'            => 'inline',
            'container'         => 'drawer',
            // An order uuid that proves the visitor bought this product. Set
            // by the public order-review page, where a guest reaches the form
            // through an emailed link and has no account to be logged into.
            'orderHash'         => '',
            // The variation this placement reviews, 0 for the product. Set
            // only by the order-review page, one row per line item; the
            // form prints it as data-item-id and ReviewForm.js sends it back
            // in the request body.
            'itemId'            => 0,
            // A caller that builds the rows itself — the Review List block when
            // an editor has filled it with blocks. Unset, the renderer draws
            // its own rows, which is what the shortcode and the product page
            // get.
            'rows_renderer'     => null,
            // The lowest rating the list shows, lifted off the Review Item
            // block. 0 is every review.
            'minRating'         => 0,
            // 'numbers' | 'fraction' | 'bullets'. Printed on the container so
            // the script can send it back: it re-renders the pager on every
            // page change, and without this the first click would replace the
            // chosen pager with the default one.
            'paginationType'    => 'numbers',
            // Words a review shows before a Read more, 0 for all of them.
            // Printed on the container beside paginationType and for the same
            // reason: the script re-renders the rows on every page change.
            'maxWords'          => 0,
            // The review attachments on a row this renderer draws
            // itself. mediaVisible is how many tiles show before the + tile
            // takes over (0 for all of them), mediaWidth and mediaHeight the
            // tile size in pixels (0 for the stylesheet's own). Printed on the
            // container alongside maxWords and for the same reason: the script
            // re-renders the rows on every page change.
            'mediaVisible'      => 0,
            'mediaWidth'        => 0,
            'mediaHeight'       => 0,
            'mediaFullWidth'    => false,
            // Pro. A flush tile becomes the top of the card and a backdrop
            // fills it; ReviewThreadMarkup refuses both without Pro, so these
            // travel as intent and the gate stays in one place.
            'mediaFlush'        => false,
            'mediaBackdrop'     => false,
            'mediaMore'         => 'overlay',
            // How a row this renderer draws is arranged, mirroring
            // LayoutPresets::row(). Printed on the container beside the media
            // settings and for the same reason: the script re-renders the
            // rows on every page change.
            'photosFirst'       => false,
            'ratingFirst'       => false,
            'badgeLast'         => false,
            'showMeta'          => true,
            'itemClass'         => '',
            // 'list' stacks the rows full width; 'grid' lays them out in
            // gridColumns columns; 'slider' puts that same row of columns in a
            // Swiper. The class goes on the list element itself, which the
            // storefront script empties and refills rather than replaces — so
            // the layout survives a page change without having to travel with
            // the request the way paginationType does.
            'viewMode'          => 'list',
            'gridColumns'       => 2,
            'sliderSettings'    => [
                'pagination'    => 'no',
                'paginationType'=> 'bullets',
            ],
            // Where the reviews endpoint can find the same composed row
            // again. Set only by the Review List block, and only when it has
            // children to compose.
        ]), $this->postId);
    }

    /**
     * The item this placement is bound to, 0 for the product as a whole.
     */
    /**
     * @var array<int, ProductReview|null> existingReviewFor() results by user
     */
    protected $existingReviewMemo = [];

    protected function itemId(): int
    {
        return max(0, (int) Arr::get($this->renderOptions, 'itemId', 0));
    }

    /**
     * The signed-in visitor's own review in this placement's (product, item)
     * slot — what decides between "Write a review" and "Edit your review".
     * Scoped to the slot the same way the duplicate guard is, so the two
     * never disagree: a review of Red does not put Blue's form in edit mode,
     * and neither puts the product-level form there.
     *
     * @param int $userId
     * @return ProductReview|null
     */
    protected function existingReviewFor($userId)
    {
        // A page that has already settled the answer for every row it
        // renders — the order-review page, whose rows are pending precisely
        // because no review exists in the slot — hands it in and no query
        // runs at all.
        if (array_key_exists('existingReview', $this->renderOptions)) {
            return $this->renderOptions['existingReview'];
        }

        $userId = $this->editableReviewUserId($userId);
        if (!$userId) {
            return null;
        }

        // The CTA and the form of one placement ask the same question; one
        // query per renderer answers both.
        $memoKey = (int) $userId;
        if (array_key_exists($memoKey, $this->existingReviewMemo)) {
            return $this->existingReviewMemo[$memoKey];
        }

        // topLevel() is load-bearing, not tidiness. A reply is a row on the
        // same product carrying the same user_id, so without it the first
        // match can be the visitor's reply to someone else's review — a store
        // owner who has answered a review being the ordinary case. The form
        // then opens bound to that reply: prefilled with its text, no rating,
        // and saving edits the reply instead of the review.
        //
        // whereNull('parent_id') is the topLevel() scope spelled out: model
        // scopes resolve through Builder::__call(), which static analysis
        // cannot see. Keep in step with that scope's predicate.
        return $this->existingReviewMemo[$memoKey] = ProductReviewService::scopeToItem(ProductReview::query(), $this->itemId())
            ->whereNull('parent_id')
            ->where('post_id', $this->postId)
            ->where('user_id', $userId)
            ->whereIn('status', [Status::REVIEW_APPROVED, Status::REVIEW_PENDING])
            ->first();
    }

    /**
     * Whether this product's reviews should be rendered.
     *
     * Reviews have to be enabled — the store switch and the product's own
     * toggle. On a single product page the page's display switch applies as
     * well, so turning it off clears the section however the page renders
     * it. Elsewhere the switch does not apply: a review block on another page
     * is a deliberate placement, which is how the relevant-products shortcode
     * behaves too.
     *
     * @return bool
     */
    protected function shouldRenderReviews(): bool
    {
        return static::isVisibleFor($this->postId);
    }

    /**
     * Whether the current page shows a product's reviews at all. The one
     * policy for everything that speaks about reviews on a page — the list
     * and the form here, the JSON-LD in ProductSchema — so none of them can
     * name a review the page does not render.
     *
     * @param int $postId
     */
    public static function isVisibleFor($postId): bool
    {
        // isReviewEnabledForProduct checks Enable Product Reviews — the module
        // switch in store settings — first, then the product's own toggle.
        if (!ProductReviewService::isReviewEnabledForProduct($postId)) {
            return false;
        }

        if (is_singular(FluentProducts::CPT_NAME)) {
            return TemplateActions::shouldShowReviewsOnProductPage($postId);
        }

        return true;
    }

    public function render()
    {
        if (!$this->shouldRenderReviews()) {
            return;
        }

        $summary = ProductReviewService::getProductRatingSummary($this->postId);
        $restInfo = Helper::getRestInfo();

        $perPageOption = (int) Arr::get($this->renderOptions, 'perPage', 0);
        $perPage = $perPageOption > 0 ? $perPageOption : $this->settings['reviews_per_page'];
        $starColor = sanitize_hex_color(Arr::get($this->renderOptions, 'starColor')) ?: self::DEFAULT_STAR_COLOR;
        $defaultSort = Arr::get($this->renderOptions, 'defaultSortBy', 'created_at') . '-' . Arr::get($this->renderOptions, 'defaultSortOrder', 'DESC');

        $showSummary = (bool) Arr::get($this->renderOptions, 'showSummary', true);
        // showReviewCount was used briefly by the first adapter draft. Keep it
        // as a fallback for callers that already passed it, while the shared
        // builder contract uses the shorter showCount name.
        $showCount = (bool) Arr::get(
            $this->renderOptions,
            'showCount',
            Arr::get($this->renderOptions, 'showReviewCount', true)
        );
        $summaryMode = Arr::get($this->renderOptions, 'summaryMode', 'side');
        $summaryMode = in_array($summaryMode, ['side', 'top', 'cta'], true) ? $summaryMode : 'side';
        $showSortControls = (bool) Arr::get($this->renderOptions, 'showSortControls', true);
        $showFilterChips = (bool) Arr::get($this->renderOptions, 'showFilterChips', true);
        $hasMedia = (bool) Arr::get($this->renderOptions, 'hasMedia', false);
        $showVerifiedBadge = (bool) Arr::get($this->renderOptions, 'showVerifiedBadge', true);
        $showReviewDate = (bool) Arr::get($this->renderOptions, 'showReviewDate', true);
        $showReviewerName = (bool) Arr::get($this->renderOptions, 'showReviewerName', true);
        $showViewReply = (bool) Arr::get($this->renderOptions, 'showViewReply', true);
        $minRating = (int) Arr::get($this->renderOptions, 'minRating', 0);
        $minRating = ($minRating >= 1 && $minRating <= 5) ? $minRating : 0;
        $viewMode = Arr::get($this->renderOptions, 'viewMode');
        $viewMode = ProductReviewService::resolveViewMode($viewMode);
        // The slider lays its slides out in columns too, so both modes carry
        // the column count and both drop the row's own bottom margin.
        $isGrid = $viewMode === 'grid';
        $isSlider = $viewMode === 'slider';
        // Masonry is columns too — CSS columns rather than grid ones, but it
        // reads the same --fct-review-columns and wants the same modifier.
        $hasColumns = $isGrid || $isSlider || $viewMode === 'masonry';
        // Two to six. One column is the list view. Four is as many as a card
        // carrying words can be cut into before a review is too narrow to read,
        // and the column control offers no more than that — but a row of
        // photographs is not read, and Photo Strip asks for six. A preset can
        // therefore ask past what the control offers; a merchant cannot.
        $gridColumns = min(6, max(2, (int) Arr::get($this->renderOptions, 'gridColumns', 2)));

        // In grid view the page has to hold whole rows. Three per row with a
        // page of two leaves a row that is permanently one short and a pager
        // that looks broken — the reader sees two of three reviews with an
        // empty column beside them. Rounded down, so a page never shows more
        // than was asked for, but never below a single full row.
        if ($isGrid) {
            $perPage = max($gridColumns, $perPage - ($perPage % $gridColumns));
        }

        // A slider with nothing to slide is the default case to avoid: two per
        // slide against the store's page of two puts every review on screen at
        // once and the arrows do nothing. So when no page length was chosen,
        // the slider takes three screens' worth instead of the store's number.
        //
        // Only when none was chosen. A page length set on the Review
        // Pagination block, in the shortcode or on the widget is the editor
        // saying how many reviews a page holds, and raising it past that gives
        // them a page they did not ask for while leaving the pager with fewer
        // pages than it should have — the slider swallowing its own
        // pagination. A slider that ends up with nothing to slide says so
        // instead: the arrows stay and mark themselves disabled.
        //
        // Three screens' worth, and no further. Taking the endpoint's maximum
        // would have every product page fetch, process and render up to a
        // hundred reviews to guarantee a second slide.
        if ($isSlider && $perPageOption <= 0) {
            $perPage = max($perPage, $gridColumns * 3);
        }

        $sliderSettings = static::normalizeSliderSettings(
            (array) Arr::get($this->renderOptions, 'sliderSettings', [])
        );

        // Swiper is 150kb of JS and CSS, so it loads only where a slider
        // actually is — not on every product page that shows reviews.
        if ($isSlider) {
            static::enqueueSliderAssets();
        }

        $maxWords = max(0, (int) Arr::get($this->renderOptions, 'maxWords', 0));
        $mediaVisible = static::mediaVisibleCount(Arr::get($this->renderOptions, 'mediaVisible', 0));
        $mediaWidth = static::mediaTileSize(Arr::get($this->renderOptions, 'mediaWidth', 0));
        $mediaHeight = static::mediaTileSize(Arr::get($this->renderOptions, 'mediaHeight', 0));
        $mediaFullWidth = (bool) Arr::get($this->renderOptions, 'mediaFullWidth', false);
        $mediaFlush = (bool) Arr::get($this->renderOptions, 'mediaFlush', false);
        $mediaBackdrop = (bool) Arr::get($this->renderOptions, 'mediaBackdrop', false);
        $photosFirst = (bool) Arr::get($this->renderOptions, 'photosFirst', false);
        $ratingFirst = (bool) Arr::get($this->renderOptions, 'ratingFirst', false);
        $badgeLast = (bool) Arr::get($this->renderOptions, 'badgeLast', false);
        $showMeta = (bool) Arr::get($this->renderOptions, 'showMeta', true);
        $itemClass = ReviewListRenderer::itemClass(Arr::get($this->renderOptions, 'itemClass', ''));
        $mediaMore = ReviewThreadMarkup::moreTilePlacement(Arr::get($this->renderOptions, 'mediaMore', 'overlay'));
        $paginationType = (string) Arr::get($this->renderOptions, 'paginationType', 'numbers');
        $paginationType = in_array($paginationType, ReviewListRenderer::paginationTypes(), true)
            ? $paginationType
            : 'numbers';

        // The first page renders on the server, so the list is real content
        // with scripting disabled and the script only takes over for sort,
        // filters and pagination. Same payload pipeline as the REST endpoint.
        $sortParts = explode('-', (string) $defaultSort);
        $initialPayload = ProductReviewService::getPublicReviewsPayload([
            'post_id'    => $this->postId,
            'status'     => 'approved',
            'sort_by'    => $sortParts[0] ?: 'created_at',
            'sort_order' => isset($sortParts[1]) && $sortParts[1] ? $sortParts[1] : 'DESC',
            'per_page'   => $perPage,
            'min_rating' => $minRating,
            'has_media'  => $hasMedia,
            // Explicit first page — without it the paginator resolves `page`
            // from the ambient request, so a stray ?page=2 on the product URL
            // would render a later review page while the script starts at 1.
            'page'       => 1,
        ], $this->postId);

        // What the count says, matching what the list is showing. The summary's
        // own total is the product's and stays that way for the breakdown
        // aside; a floor changes which reviews this list holds, so "N Reviews"
        // has to follow it. Reviews.js sets the same number from the list
        // response on every sort, filter and page, so the two agree from the
        // first render onward.
        $displayTotal = $minRating || $hasMedia
            ? (int) Arr::get($initialPayload, 'reviews.total', 0)
            : (int) Arr::get((array) $summary, 'total', 0);

        $initialListRenderer = new ReviewListRenderer([
            'show_reviewer'   => $showReviewerName,
            'show_date'       => $showReviewDate,
            'show_verified'   => $showVerifiedBadge,
            'show_view_reply' => $showViewReply,
            'show_avatar'     => (bool) Arr::get($this->renderOptions, 'showAvatar', true),
            'show_title'      => (bool) Arr::get($this->renderOptions, 'showTitle', true),
            'show_content'    => (bool) Arr::get($this->renderOptions, 'showContent', true),
            'show_photos'     => (bool) Arr::get($this->renderOptions, 'showPhotos', true),
            'show_footer'     => (bool) Arr::get($this->renderOptions, 'showFooter', true),
            'show_variation'  => (bool) Arr::get($this->renderOptions, 'showVariation', true),
            'can_thread'      => ProductReviewService::isMultipleRepliesAllowed() && is_user_logged_in(),
            'pagination_type' => $paginationType,
            'max_words'       => $maxWords,
            'media_visible'   => $mediaVisible,
            'media_width'     => $mediaWidth,
            'media_height'    => $mediaHeight,
            'media_full_width' => $mediaFullWidth,
            'media_flush'      => $mediaFlush,
            'media_backdrop'   => $mediaBackdrop,
            'media_more'       => $mediaMore,
            'photos_first'     => $photosFirst,
            'rating_first'     => $ratingFirst,
            'badge_last'       => $badgeLast,
            'show_meta'        => $showMeta,
            'item_class'       => $itemClass,
        ]);
        // Computed before the blocks render, because the pager block draws this
        // and the blocks are what render next.
        $initialPagination = $initialListRenderer->paginationHtml(Arr::get($initialPayload, 'reviews', []));

        // The blocks an editor placed, rendered once each in the order they sit
        // in. What they drew is read back afterwards so the renderer can fill
        // in the parts they left out.
        $composedBody = '';
        $composedDrew = ['header' => false, 'list' => false, 'pagination' => false];
        $rowsRenderer = Arr::get($this->renderOptions, 'rows_renderer');

        /**
         * Whether an editor built this list out of blocks.
         *
         * The difference between a part that is missing and a part that was
         * taken away. A list nobody has composed — the shortcode, the
         * single-product template, a Review List block left empty — has no
         * blocks to say what it wants, so the renderer draws the whole thing.
         * A composed list has already said: its blocks are the answer, and a
         * header absent from them is a header an editor removed.
         */
        $isComposed = is_callable($rowsRenderer);

        if ($isComposed) {
            $composedBody = (string) call_user_func(
                $rowsRenderer,
                Arr::get($initialPayload, 'reviews.data', []),
                [
                    'header'     => [
                        'post_id'      => $this->postId,
                        'total'        => $displayTotal,
                        'min_rating'   => $minRating,
                        'default_sort' => (string) $defaultSort,
                    ],
                    'pagination' => [
                        'inner_html' => $initialPagination,
                    ],
                ]
            );

            foreach (array_keys($composedDrew) as $part) {
                $composedDrew[$part] = InnerBlocks::wasDrawn($part);
            }
        }

        $initialRows = $composedDrew['list']
            ? ''
            : $initialListRenderer->renderReviewItems(Arr::get($initialPayload, 'reviews.data', []));

        $initialLastPage = (int) Arr::get($initialPayload, 'reviews.last_page', 1);

        ?>
        <?php
        $extraDataAttrs = apply_filters('fluent_cart/review/container_data_attrs', [], $this->postId, $this->renderOptions);
        $extraAttrHtml = '';
        foreach ($extraDataAttrs as $attrKey => $attrVal) {
            $extraAttrHtml .= ' data-' . esc_attr($attrKey) . '="' . esc_attr($attrVal) . '"';
        }
        ?>
        <div class="fct-product-reviews-section"
             data-fluent-cart-reviews
             data-post-id="<?php echo esc_attr($this->postId); ?>"
             data-product-name="<?php echo esc_attr(get_post_field('post_title', $this->postId, 'raw')); ?>"
             data-rest-url="<?php echo esc_url($restInfo['url']); ?>"
             data-rest-nonce="<?php echo esc_attr($restInfo['nonce']); ?>"
             data-per-page="<?php echo esc_attr($perPage); ?>"
             data-min-rating="<?php echo esc_attr($minRating); ?>"
             data-default-sort="<?php echo esc_attr($defaultSort); ?>"
             data-show-verified="<?php echo $showVerifiedBadge ? '1' : '0'; ?>"
             data-show-date="<?php echo $showReviewDate ? '1' : '0'; ?>"
             data-show-reviewer="<?php echo $showReviewerName ? '1' : '0'; ?>"
             data-show-view-reply="<?php echo $showViewReply ? '1' : '0'; ?>"
             data-show-avatar="<?php echo Arr::get($this->renderOptions, 'showAvatar', true) ? '1' : '0'; ?>"
             data-show-title="<?php echo Arr::get($this->renderOptions, 'showTitle', true) ? '1' : '0'; ?>"
             data-show-content="<?php echo Arr::get($this->renderOptions, 'showContent', true) ? '1' : '0'; ?>"
             data-show-photos="<?php echo Arr::get($this->renderOptions, 'showPhotos', true) ? '1' : '0'; ?>"
             data-show-footer="<?php echo Arr::get($this->renderOptions, 'showFooter', true) ? '1' : '0'; ?>"
             data-show-variation="<?php echo Arr::get($this->renderOptions, 'showVariation', true) ? '1' : '0'; ?>"
             data-star-required="<?php echo ProductReviewService::isStarRatingRequired() ? '1' : '0'; ?>"
             data-last-page="<?php echo esc_attr($initialLastPage); ?>"
             data-pagination-type="<?php echo esc_attr($paginationType); ?>"
             data-max-words="<?php echo esc_attr((string) $maxWords); ?>"
             data-media-visible="<?php echo esc_attr((string) $mediaVisible); ?>"
             data-media-width="<?php echo esc_attr((string) $mediaWidth); ?>"
             data-media-height="<?php echo esc_attr((string) $mediaHeight); ?>"
             data-media-full-width="<?php echo $mediaFullWidth ? '1' : '0'; ?>"
             data-media-flush="<?php echo $mediaFlush ? '1' : '0'; ?>"
             data-media-backdrop="<?php echo $mediaBackdrop ? '1' : '0'; ?>"
             data-media-more="<?php echo esc_attr($mediaMore); ?>"
             data-photos-first="<?php echo $photosFirst ? '1' : '0'; ?>"
             data-rating-first="<?php echo $ratingFirst ? '1' : '0'; ?>"
             data-badge-last="<?php echo $badgeLast ? '1' : '0'; ?>"
             data-show-meta="<?php echo $showMeta ? '1' : '0'; ?>"
             data-item-class="<?php echo esc_attr($itemClass); ?>"
             <?php /* A list narrowed to reviews with photos stays narrowed:
                      the script re-fetches every sort, filter and page from the
                      endpoint, and without this the second page would quietly
                      widen to every review. */ ?>
             data-only-with-photos="<?php echo $hasMedia ? '1' : '0'; ?>"
            <?php if (!$this->isDefaultStarColor($starColor)) : ?>
                style="--fct-star-color: <?php echo esc_attr($starColor); ?>"
            <?php endif; ?>
            <?php echo $extraAttrHtml; ?>
        >
            <div class="fct-reviews-layout<?php echo $showSummary ? ' fct-reviews-layout--summary-' . esc_attr($summaryMode) : ' fct-reviews-layout--single'; ?>">
                <?php if ($showSummary) : ?>
                    <aside class="fct-reviews-layout-left">
                        <?php if ($summaryMode === 'cta') : ?>
                            <?php // The same card the composed section builds:
                                  // its template puts the invitation inside a
                                  // summary group, which is what gives it the
                                  // border and the padding. Wrapped here rather
                                  // than inside renderWriteReviewCta(), which
                                  // also draws the standalone Write a Review
                                  // block and widget -- neither of which wants
                                  // a card around it. ?>
                            <div class="fct-review-summary-group">
                                <?php $this->renderWriteReviewCta(); ?>
                            </div>
                        <?php else : ?>
                            <?php $this->renderRatingSummary($summary); ?>
                        <?php endif; ?>
                    </aside>
                <?php endif; ?>

                <div class="fct-reviews-layout-right">
                    <?php // A composed list draws what its blocks say and
                          // nothing else: a header absent from them is one an
                          // editor removed, and each block — count, chips,
                          // sort — renders only itself, so placing one does
                          // not bring the other two along.
                          //
                          // This header is for the lists nobody composed: the
                          // shortcode, the single-product template, a Review
                          // List block left empty. Those have no blocks to
                          // state a preference, so they get the lot. ?>
                    <?php if (!$isComposed) : ?>
                    <?php if ($showCount || $showSortControls || $showFilterChips) : ?>
                    <div class="fct-reviews-list-header">
                        <?php if ($showCount) : ?>
                        <h3 class="fct-reviews-section-title">
                            <?php
                            /* translators: %s - total review count */
                            printf(
                                esc_html__('%s Reviews', 'fluent-cart'),
                                '<span data-reviews-total-count>' . esc_html($displayTotal) . '</span>'
                            );
                            ?>
                        </h3>
                        <?php endif; ?>

                        <?php if ($showSortControls || $showFilterChips) : ?>
                            <?php $this->renderControls($defaultSort, $showFilterChips, $showSortControls); ?>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>

                    <?php // Shown by Reviews.js while a sort, filter or page
                          // change is in flight. It takes no room of its own -
                          // the spinner floats over the rows, which stay where
                          // they are, dimmed, until the new ones land - so
                          // nothing above or below it moves. ?>
                    <div class="fct-reviews-loading" data-reviews-loading role="status">
                        <span class="fct-loader-spinner" aria-hidden="true"></span>
                        <span class="fct-sr-only"><?php esc_html_e('Loading reviews...', 'fluent-cart'); ?></span>
                    </div>

                    <p class="fct-reviews-error" data-reviews-error hidden>
                        <?php esc_html_e('Unable to load reviews. Please try again later.', 'fluent-cart'); ?>
                    </p>

                    <?php if ($composedBody !== '') : ?>
                        <?php echo $composedBody; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php endif; ?>

                    <?php if (!$composedDrew['list']) : ?>
                    <div class="fct-reviews-list<?php echo $hasColumns ? ' fct-reviews-list--' . $viewMode . ' fct-reviews-list--cols-' . (int) $gridColumns : ''; ?>"
                         <?php if ($hasColumns) : ?>style="--fct-review-columns: <?php echo (int) $gridColumns; ?>"<?php endif; ?>
                         <?php if ($isSlider) : ?>
                             data-reviews-slider="<?php echo (int) $gridColumns; ?>"
                             data-slider-settings="<?php echo esc_attr(wp_json_encode($sliderSettings)); ?>"
                         <?php endif; ?>
                         data-reviews-list>
                        <?php echo $initialRows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    </div>
                    <?php endif; ?>

                    <?php // Same rule, same reason: a pager an editor did not
                          // place is a pager they did not want. Reviews.js
                          // looks this element up before wiring it and again
                          // before refilling it, and steps over a list that
                          // has none — so the rows simply page no further
                          // than the first. ?>
                    <?php if (!$isComposed && Arr::get($this->renderOptions, 'showPagination', true) && (!$isSlider || $initialLastPage > 1)) : ?>
                    <div class="fct-reviews-pagination" data-reviews-pagination><?php echo $initialPagination; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($showSummary) : ?>
                <?php
                // The summary rendered its Write a Review CTA, so this block
                // must also supply the drawer that CTA opens — a standalone
                // reviews block otherwise emits a dead button. The drawer
                // dedupes per product, so the single-product template's
                // later renderForm() call cannot add a second one.
                $this->renderForm();
                ?>
            <?php endif; ?>

            <?php $this->renderThreadModalShell(); ?>

            <?php do_action('fluent_cart/review/after_section', $this->postId); ?>
        </div>
        <?php
    }

    /**
     * The slider's behaviour settings, every value checked.
     *
     * They are printed into a data attribute and handed straight to Swiper, so
     * an unrecognised value has to land on a default rather than reach the
     * library — a saved attribute is hand-editable.
     *
     * Public because the composed row prints the same attribute from the same
     * saved values, and two sets of rules for one attribute is one set that
     * will drift.
     *
     * @param array $settings
     * @return array
     */
    public static function normalizeSliderSettings(array $settings): array
    {
        $autoplay = Arr::get($settings, 'autoplay', 'no');
        $arrowsSize = Arr::get($settings, 'arrowsSize', 'md');

        return [
            'autoplay'      => in_array($autoplay, ['no', 'yes', 'hover'], true) ? $autoplay : 'no',
            // Floored at 300ms: below that the slides move faster than they
            // can be read, which is a carousel nobody can use.
            'autoplayDelay' => min(10000, max(300, (int) Arr::get($settings, 'autoplayDelay', 3000))),
            'arrows'        => Arr::get($settings, 'arrows') === 'no' ? 'no' : 'yes',
            'arrowsSize'    => in_array($arrowsSize, ['sm', 'md', 'lg'], true) ? $arrowsSize : 'md',
            // Where the arrows sit: over the first and last card, clear of
            // the track on either side, or in a row beneath it.
            'arrowsPosition' => in_array(Arr::get($settings, 'arrowsPosition'), ['overlap', 'outside', 'bottom'], true)
                ? Arr::get($settings, 'arrowsPosition')
                : 'overlap',
            'pagination'    => Arr::get($settings, 'pagination') === 'yes' ? 'yes' : 'no',
            'paginationType' => in_array(Arr::get($settings, 'paginationType'), ['bullets', 'fraction', 'progressbar', 'segmented'], true)
                ? Arr::get($settings, 'paginationType')
                : 'bullets',
            'infinite'      => Arr::get($settings, 'infinite') === 'yes' ? 'yes' : 'no',
        ];
    }

    /**
     * Swiper, for the slider view only.
     *
     * The same bundle the product carousel uses, enqueued the same way — one
     * copy of the library, whichever block asks for it first.
     */
    protected static function enqueueSliderAssets(): void
    {
        static $enqueued = false;

        if ($enqueued) {
            return;
        }

        $enqueued = true;
        $slug = fluentCart()->config->get('app.slug');

        Vite::enqueueStaticScript(
            $slug . '-fluentcart-swiper-js',
            'public/lib/swiper/swiper-bundle.min.js',
            [$slug . '-app']
        );

        Vite::enqueueStaticStyle(
            $slug . '-fluentcart-swiper-css',
            'public/lib/swiper/swiper-bundle.min.css'
        );
    }

    /**
     * Empty shell for the review thread modal.
     *
     * Printed hidden and shown by the storefront script, the same split the
     * cart drawer uses for its loader: the markup lives in PHP, the script
     * only toggles it and swaps in the panel fetched from the modal
     * endpoint. It carries no review data, so nothing here can drift from
     * ReviewModalRenderer.
     */
    protected function renderThreadModalShell()
    {
        ?>
        <div class="fct-review-modal-overlay" data-review-modal-shell role="dialog" aria-modal="true"
             aria-label="<?php esc_attr_e('Review thread', 'fluent-cart'); ?>" hidden>
            <div class="fct-review-modal" data-review-modal tabindex="-1">
                <div class="fct-review-modal-body" data-review-modal-body>
                    <div class="fct-review-modal-loading" data-review-modal-loading role="status"
                         aria-label="<?php esc_attr_e('Loading replies...', 'fluent-cart'); ?>">
                        <div class="fct-loader-wrap show">
                            <div class="fct-loader-spinner"></div>
                        </div>
                    </div>
                </div>

                <?php // Error body the script swaps in when the thread fails
                      // to load — cloned from here so no markup lives in JS. ?>
                <template data-modal-error-template>
                    <div class="fct-review-modal-empty"><?php esc_html_e('Could not load this review. Please try again.', 'fluent-cart'); ?></div>
                    <button type="button" class="fct-review-modal-close" data-modal-close aria-label="<?php esc_attr_e('Close', 'fluent-cart'); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true" focusable="false"><path d="M12.8337 1.16663L1.16699 12.8333M1.16699 1.16663L12.8337 12.8333" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                </template>
            </div>
        </div>
        <?php
    }

    /**
     * The submission form, in whichever shape the placement asked for.
     *
     * Two independent axes:
     *  - container: 'drawer' | 'modal' | 'none'  — the chrome around it
     *  - layout:    'steps'  | 'inline'          — how the fields are paced
     *
     * Every combination is valid: a stepped modal, a flat drawer, a stepped
     * card printed on the page, a flat card. The field markup and the whole
     * data-* contract are identical in all four, so ReviewForm.js and the
     * Pro upload zone never have to know which one they are driving.
     */
    public function renderForm()
    {
        if (!$this->shouldRenderReviews()) {
            return;
        }

        // One form per renderer. The single-product template asks twice —
        // once through render(), whose summary CTA needs the drawer it
        // opens, and once on its own — and both carry this instance's id,
        // so a second copy would leave the trigger bound to whichever
        // registered last while the other sat unused in the page.
        if ($this->formRendered) {
            return;
        }
        $this->formRendered = true;

        $restInfo = Helper::getRestInfo();
        $starColor = sanitize_hex_color(Arr::get($this->renderOptions, 'starColor')) ?: self::DEFAULT_STAR_COLOR;
        $permissionMode = $this->settings['review_permission_mode'] ?? 'verified_buyers';
        $userId = get_current_user_id();
        // A valid order grant is proof of purchase, so it answers the same
        // question logging in would — see ProductReviewService::resolveOrderGrant().
        $needsLogin = (!$this->orderGrant() && $permissionMode !== 'anyone' && !$userId);
        $layout = $this->formLayout();
        $container = $this->formContainer();
        $isStepped = ($layout === 'steps');

        // Only compute form state when the user can actually use it
        $extraFieldsHtml = '';
        $hasPhotosStep = false;
        $starEnabled = ProductReviewService::isStarRatingEnabled();
        $existingReview = null;
        $isEditMode = false;

        // Photos are attached after the review submission is authorized,
        // including submissions authorized by an order grant.
        if (!$needsLogin) {
            // Check if Pro photo step has content.
            //
            // The review itself rides along as a third argument: a consumer
            // that has to re-derive "the visitor's review" from (post, user)
            // will get it wrong in the two ways this renderer already handles
            // — a reply matches those columns, and so does the same person's
            // review of a different variation. Handing over the row this form
            // is actually bound to removes the question. Memoized, so asking
            // here costs nothing; null when the form is writing a new review.
            ob_start();
            do_action(
                'fluent_cart/review/form_extra_fields',
                $this->postId,
                $this->editableReviewUserId($userId),
                $this->existingReviewFor($userId)
            );
            $extraFieldsHtml = trim(ob_get_clean());
            $hasPhotosStep = !empty($extraFieldsHtml);
        }

        // Calculate total steps: Rating (if enabled) + Details + Photos (if Pro)
        $totalSteps = 1; // Details is always present
        if ($starEnabled) {
            $totalSteps++;
        }
        if ($hasPhotosStep) {
            $totalSteps++;
        }

        // Inline has no wizard: every field is on screen at once, so the whole
        // form is a single step and ReviewForm.js validates it as one.
        if (!$isStepped) {
            $totalSteps = 1;
        }

        // Check if logged-in user already has a review for this product
        if (!$needsLogin) {
            $existingReview = $this->existingReviewFor($userId);
        }
        $isEditMode = !empty($existingReview);
        ?>
        <div class="fct-review-form-section fct-review-form-section-<?php echo esc_attr($container); ?>"
             data-fluent-cart-review-form
             data-form-id="<?php echo esc_attr($this->instanceId); ?>"
             data-layout="<?php echo esc_attr($layout); ?>"
             data-container="<?php echo esc_attr($container); ?>"
             data-post-id="<?php echo esc_attr($this->postId); ?>"
             data-item-id="<?php echo esc_attr($this->itemId()); ?>"
             data-rest-url="<?php echo esc_url($restInfo['url']); ?>"
             data-rest-nonce="<?php echo esc_attr($restInfo['nonce']); ?>"
             data-star-enabled="<?php echo $starEnabled ? '1' : '0'; ?>"
             data-star-required="<?php echo ProductReviewService::isStarRatingRequired() ? '1' : '0'; ?>"
             data-total-steps="<?php echo esc_attr($totalSteps); ?>"
            <?php if ($isEditMode) : ?>
                data-edit-mode="1"
                data-review-id="<?php echo esc_attr($existingReview->id); ?>"
                data-existing-rating="<?php echo esc_attr($existingReview->rating); ?>"
                data-existing-title="<?php echo esc_attr($existingReview->title); ?>"
                data-existing-content="<?php echo esc_attr($existingReview->content); ?>"
            <?php endif; ?>
            <?php if (!$this->isDefaultStarColor($starColor)) : ?>
                style="--fct-star-color: <?php echo esc_attr($starColor); ?>"
            <?php endif; ?>
        >
            <?php if ($needsLogin && $container === 'none') : ?>
                <?php
                // A drawer or a modal has a trigger, and that trigger becomes
                // a log-in link for a visitor who needs one. Printed on the
                // page there is no trigger, so without this the visitor gets
                // an empty box: no form, no reason, nothing to click.
                $this->renderLoginRequired();
                ?>
            <?php endif; ?>

            <?php if (!$needsLogin) : ?>
                <?php if ($container === 'none') : ?>
                    <?php $this->renderStandalone($userId, $extraFieldsHtml, $hasPhotosStep, $totalSteps, $isStepped, $isEditMode); ?>
                <?php else : ?>
                    <?php
                    // Multiple sections for one product each carry an overlay;
                    // ReviewForm.js elects one primary controller per product,
                    // so only the first ever opens — the rest stay inert.
                    $this->renderOverlay($container, $userId, $extraFieldsHtml, $hasPhotosStep, $totalSteps, $isStepped, $isEditMode);
                    ?>
                <?php endif; ?>
            <?php endif; ?>

            <?php do_action('fluent_cart/review/after_form_section', $this->postId); ?>
        </div>
        <?php
    }

    /**
     * The form behind a trigger: a side drawer or a centred modal. Both carry
     * the same data-review-drawer contract — the difference is the class the
     * stylesheet hangs the animation and position off, so the open/close,
     * focus trap and Escape handling in ReviewForm.js serve both.
     */
    protected function renderOverlay($container, $userId, $extraFieldsHtml, $hasPhotos, $totalSteps, $isStepped, $isEditMode)
    {
        $isModal = ($container === 'modal');
        // Per instance, not per product: two of these blocks can sit on one
        // page for one product, and ids are document-wide. Duplicates break
        // the label-to-field pairing and leave both dialogs pointing
        // aria-labelledby at the same heading.
        $titleId = 'fct-review-drawer-title-' . $this->instanceId;
        ?>
        <?php
        // fct-review-form-modal, not fct-review-modal: the latter is the review
        // thread modal, which has its own rules and never takes the open class
        // this overlay animates on. Sharing the name made the thread modal
        // inherit this one's hidden state and render transparent.
        ?>
        <div class="<?php echo $isModal ? 'fct-review-form-modal-overlay' : 'fct-review-drawer-overlay'; ?>" data-review-drawer style="display:none;">
            <div class="<?php echo $isModal ? 'fct-review-form-modal' : 'fct-review-drawer'; ?>" data-review-drawer-panel role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($titleId); ?>" tabindex="-1">
                <div class="fct-review-drawer-header">
                    <h4 class="fct-review-drawer-title" data-review-drawer-title id="<?php echo esc_attr($titleId); ?>"><?php esc_html_e('Write a review', 'fluent-cart'); ?></h4>
                    <button type="button" class="fct-review-drawer-close" data-close-review-drawer aria-label="<?php esc_attr_e('Close', 'fluent-cart'); ?>"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true" focusable="false"><path d="M12.8337 1.16663L1.16699 12.8333M1.16699 1.16663L12.8337 12.8333" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
                </div>

                <?php $this->renderStepper($hasPhotos, $totalSteps, $isStepped); ?>

                <div class="fct-review-drawer-body">
                    <div class="fct-review-form-message" data-review-form-message role="alert" style="display: none;"></div>

                    <?php $this->renderFields($userId, $extraFieldsHtml, $hasPhotos, $isStepped, $this->instanceId); ?>
                </div>

                <div class="fct-review-drawer-footer">
                    <?php $this->renderFooterButtons($totalSteps, $isStepped, $isEditMode); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * The form printed straight onto the page — no trigger, no overlay. Still
     * honours the layout axis: a stepped card walks through the same steps in
     * place, an inline card shows everything at once.
     */
    protected function renderStandalone($userId, $extraFieldsHtml, $hasPhotos, $totalSteps, $isStepped, $isEditMode)
    {
        // Ids have to be unique across the whole document, not just against
        // the overlay form the product template may already have printed: two
        // of these blocks can sit on one page for one product. Duplicate ids
        // break the label-to-field pairing, so clicking the second form's
        // "Your review" would focus the first form's box.
        //
        // $instanceId is already unique per renderer — it is what pairs a
        // trigger with its own form — so it serves here too.
        $idSuffix = $this->instanceId;
        ?>
        <div class="fct-review-standalone">
            <div class="fct-review-form-message" data-review-form-message role="alert" style="display: none;"></div>

            <?php $this->renderStepper($hasPhotos, $totalSteps, $isStepped); ?>

            <?php $this->renderFields($userId, $extraFieldsHtml, $hasPhotos, $isStepped, $idSuffix); ?>

            <div class="fct-review-standalone-footer">
                <?php $this->renderFooterButtons($totalSteps, $isStepped, $isEditMode); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Shown in place of the form when the store requires a login the visitor
     * does not have. role="status" so the reason is announced rather than
     * only seen, and the link comes back here rather than to the product,
     * which on a review page is not where they were.
     */
    protected function renderLoginRequired()
    {
        $loginUrl = wp_login_url($this->loginRedirectUrl());
        ?>
        <div class="fct-review-login-required" role="status">
            <p class="fct-review-login-required-text">
                <?php esc_html_e('Please log in to write a review for this product.', 'fluent-cart'); ?>
            </p>
            <a href="<?php echo esc_url($loginUrl); ?>" class="fct-review-cta-btn">
                <?php
                $loginText = trim((string) Arr::get($this->renderOptions, 'ctaLoginText', ''));
                echo $loginText !== '' ? esc_html($loginText) : esc_html__('Log in to Review', 'fluent-cart');
                ?>
            </a>
        </div>
        <?php
    }

    /**
     * The numbered progress row. Steps only, and only when there is more than
     * one of them — a single-step wizard is just a form.
     */
    protected function renderStepper($hasPhotos, $totalSteps, $isStepped)
    {
        if (!$isStepped || $totalSteps <= 1) {
            return;
        }

        $starEnabled = ProductReviewService::isStarRatingEnabled();
        ?>
        <div class="fct-review-stepper" data-review-stepper>
            <?php if ($starEnabled) : ?>
            <div class="fct-review-step-indicator active" data-step-indicator="1">
                <span class="fct-step-circle">1</span>
                <span class="fct-step-label"><?php esc_html_e('Rating', 'fluent-cart'); ?></span>
            </div>
            <div class="fct-step-line"></div>
            <div class="fct-review-step-indicator" data-step-indicator="2">
                <span class="fct-step-circle">2</span>
                <span class="fct-step-label"><?php esc_html_e('Details', 'fluent-cart'); ?></span>
            </div>
            <?php if ($hasPhotos) : ?>
                <div class="fct-step-line"></div>
                <div class="fct-review-step-indicator" data-step-indicator="3">
                    <span class="fct-step-circle">3</span>
                    <span class="fct-step-label"><?php esc_html_e('Photos', 'fluent-cart'); ?></span>
                </div>
            <?php endif; ?>
            <?php else : ?>
            <div class="fct-review-step-indicator active" data-step-indicator="1">
                <span class="fct-step-circle">1</span>
                <span class="fct-step-label"><?php esc_html_e('Details', 'fluent-cart'); ?></span>
            </div>
            <?php if ($hasPhotos) : ?>
                <div class="fct-step-line"></div>
                <div class="fct-review-step-indicator" data-step-indicator="2">
                    <span class="fct-step-circle">2</span>
                    <span class="fct-step-label"><?php esc_html_e('Photos', 'fluent-cart'); ?></span>
                </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * The <form> itself. Stepped wraps each group in a data-review-step div
     * the wizard shows and hides; inline drops the same groups in flat.
     */
    protected function renderFields($userId, $extraFieldsHtml, $hasPhotos, $isStepped, $idSuffix)
    {
        $currentUser = $userId ? get_userdata($userId) : null;
        // Through the service, not the settings array: isStarRatingRequired()
        // already folds in "ratings are off entirely", and reading the two
        // keys here is how the storefront and the server paths drifted apart
        // before.
        $starEnabled = ProductReviewService::isStarRatingEnabled();
        $starRequired = ProductReviewService::isStarRatingRequired();
        $groupClass = $isStepped ? 'fct-review-step' : 'fct-review-inline-group';
        ?>
        <form class="fct-review-form" data-review-form>
            <?php
            // ReviewForm.js builds its payload with new FormData(form), so a
            // hidden field here is all it takes to send the hash back — no
            // client change, and the value never leaves the form it belongs to.
            $grantHash = $this->orderGrant() ? (string) Arr::get($this->renderOptions, 'orderHash', '') : '';
            ?>
            <?php if ($grantHash !== '') : ?>
                <input type="hidden" name="order_hash" value="<?php echo esc_attr($grantHash); ?>"/>
            <?php endif; ?>

            <?php if ($starEnabled) : ?>
                <div class="<?php echo esc_attr($groupClass); ?>" <?php echo $isStepped ? 'data-review-step="1"' : ''; ?>>
                    <?php $this->renderRatingField($starRequired); ?>
                </div>
            <?php endif; ?>

            <div class="<?php echo esc_attr($groupClass); ?>"
                <?php if ($isStepped) : ?>
                    data-review-step="<?php echo $starEnabled ? '2' : '1'; ?>" <?php echo $starEnabled ? 'style="display:none;"' : ''; ?>
                <?php endif; ?>
            >
                <?php $this->renderDetailsFields($userId, $currentUser, $idSuffix); ?>
            </div>

            <?php if ($hasPhotos) : ?>
                <div class="<?php echo esc_attr($groupClass); ?>"
                    <?php if ($isStepped) : ?>
                        data-review-step="<?php echo $starEnabled ? '3' : '2'; ?>" style="display:none;"
                    <?php endif; ?>
                >
                    <?php if ($isStepped) : ?>
                        <p class="fct-review-step-description">
                            <?php esc_html_e('Add photos to help other shoppers. Up to 5 images — JPG, PNG, or WEBP.', 'fluent-cart'); ?>
                        </p>
                    <?php endif; ?>
                    <?php echo $extraFieldsHtml; // Already escaped by Pro renderer ?>
                    <?php if ($isStepped) : ?>
                        <p class="fct-review-step-hint">
                            <?php esc_html_e('No photos? That\'s fine — you can skip this step.', 'fluent-cart'); ?>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </form>
        <?php
    }

    /**
     * Back / Next / Submit. Inline has nowhere to go, so it gets the submit
     * button alone, named for the write it performs — an existing review is
     * updated, not posted a second time.
     */
    protected function renderFooterButtons($totalSteps, $isStepped, $isEditMode)
    {
        ?>
        <?php if ($isStepped) : ?>
            <button type="button" class="fct-review-back-btn" data-review-back style="display:none;">
                <svg class="fct-nav-arrow-svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg> <?php esc_html_e('Back', 'fluent-cart'); ?>
            </button>
            <button type="button" class="fct-review-next-btn" data-review-next>
                <?php esc_html_e('Next', 'fluent-cart'); ?> <svg class="fct-nav-arrow-svg" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
            </button>
        <?php endif; ?>
        <button type="button" class="fct-review-submit-btn" data-review-submit <?php echo $isStepped ? 'style="display:none;"' : ''; ?>>
            <?php
            if ($isEditMode) {
                esc_html_e('Update review', 'fluent-cart');
            } else {
                esc_html_e('Submit review', 'fluent-cart');
            }
            ?><?php echo $isStepped ? ' &#10003;' : ''; ?>
        </button>
        <?php if ($isStepped) : ?>
            <div class="fct-review-step-info" data-review-step-info>
                <?php
                /* translators: 1: current step, 2: total steps */
                printf(esc_html__('Step %1$s of %2$s', 'fluent-cart'), '1', esc_html($totalSteps));
                ?>
            </div>
        <?php endif; ?>
        <?php
    }

    /**
     * Star selector plus the hidden input ReviewForm.js writes the value to.
     * Shared by every layout — stepped wraps it in a step, inline does not.
     */
    protected function renderRatingField($starRequired)
    {
        ?>
        <label class="fct-review-step-question">
            <?php esc_html_e('How would you rate this product?', 'fluent-cart'); ?>
            <?php if ($starRequired) : ?> <span class="required">*</span><?php endif; ?>
        </label>
        <?php
        // Each star carries its own tooltip, so the bubble is positioned
        // against the star it belongs to and nothing has to measure anything.
        // Rendered here rather than written by script: the words are known at
        // render time, and a rating that has been chosen keeps its tooltip up
        // with scripting disabled.
        $ratingWords = [
            1 => __('Poor', 'fluent-cart'),
            2 => __('Average', 'fluent-cart'),
            3 => __('Good', 'fluent-cart'),
            4 => __('Very Good', 'fluent-cart'),
            5 => __('Excellent', 'fluent-cart'),
        ];
        // Poor reads as a failure, Average a caution, Good neither, the top
        // two a success.
        $ratingTypes = [1 => 'danger', 2 => 'warning', 3 => 'info', 4 => 'success', 5 => 'success'];
        ?>
        <div class="fct-star-selector-wrap">
            <div class="fct-star-selector" data-star-selector role="radiogroup" aria-label="<?php esc_attr_e('Rating', 'fluent-cart'); ?>">
                <?php for ($i = 1; $i <= 5; $i++) : ?>
                    <button type="button" class="fct-star-select" data-star-value="<?php echo esc_attr($i); ?>"
                            role="radio" aria-checked="false"
                            aria-label="<?php printf(esc_attr__('%d star', 'fluent-cart'), $i); ?>"
                    ><?php echo ReviewThreadMarkup::starSvg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php
                        // aria-hidden: the button's own aria-label already says
                        // "3 star", and the tooltip would read as a second name
                        // for the same control.
                        ?><span class="fct-star-tooltip fct-<?php echo esc_attr($ratingTypes[$i]); ?>" aria-hidden="true"><?php echo esc_html($ratingWords[$i]); ?></span></button>
                <?php endfor; ?>
            </div>
        </div>
        <input type="hidden" name="rating" value="" data-review-rating/>
        <?php
    }

    /**
     * Reviewer identity (guests type it, members post it hidden), title and
     * content. $idSuffix keeps label/input ids unique when two forms for the
     * same product share a page.
     */
    protected function renderDetailsFields($userId, $currentUser, $idSuffix)
    {
        ?>
        <?php
        // A visitor holding an order grant already has an identity: the order's
        // customer. The submit path takes those values from the order itself
        // and ignores whatever arrives, so no identity fields are rendered at
        // all — not even hidden ones. Printing the buyer's email into a hidden
        // input would publish it to anyone the review link reaches, for a value
        // the server discards anyway.
        $grant = $this->orderGrant();
        $grantOwns = ProductReviewService::grantOwnsSubmission($grant);
        $grantIdentity = $grantOwns ? ProductReviewService::orderGrantIdentity($grant) : null;
        $grantName = $grantIdentity ? Arr::get($grantIdentity, 'name', '') : '';

        // An order whose customer row is gone resolves to no email, and the
        // submit path then falls back to the typed fields — so the form has to
        // show them rather than a byline the visitor cannot supply. Signed in
        // or not: the submit path files that review under nobody's account,
        // so the visitor's own name and address are not offered either.
        if ($grantIdentity && trim((string) Arr::get($grantIdentity, 'email', '')) === '') {
            $grantIdentity = null;
        }
        $typedIdentity = !$userId || ($grantOwns && !$grantIdentity);
        ?>
        <?php if ($grantIdentity) : ?>
            <p class="fct-review-posting-as">
                <?php
                /* translators: %1$s: the name the review will be published under */
                printf(esc_html__('Posting as %1$s', 'fluent-cart'), '<strong>' . esc_html($grantName) . '</strong>');
                ?>
            </p>
        <?php elseif ($typedIdentity) : ?>
            <div class="fct-review-form-row">
                <div class="fct-review-form-field">
                    <label for="fct-review-name-<?php echo esc_attr($idSuffix); ?>"><?php esc_html_e('Your name', 'fluent-cart'); ?> <span class="required">*</span></label>
                    <input type="text" id="fct-review-name-<?php echo esc_attr($idSuffix); ?>" name="reviewer_name" required placeholder="<?php esc_attr_e('Your name', 'fluent-cart'); ?>"/>
                </div>
                <div class="fct-review-form-field">
                    <label for="fct-review-email-<?php echo esc_attr($idSuffix); ?>"><?php esc_html_e('Email', 'fluent-cart'); ?> <span class="required">*</span> <span class="fct-label-hint">(<?php esc_html_e('private', 'fluent-cart'); ?>)</span></label>
                    <input type="email" id="fct-review-email-<?php echo esc_attr($idSuffix); ?>" name="reviewer_email" maxlength="192" required placeholder="<?php esc_attr_e('your@email.com', 'fluent-cart'); ?>"/>
                </div>
            </div>
        <?php else : ?>
            <input type="hidden" name="reviewer_name" value="<?php echo esc_attr($currentUser->display_name); ?>"/>
            <input type="hidden" name="reviewer_email" value="<?php echo esc_attr($currentUser->user_email); ?>"/>
        <?php endif; ?>

        <div class="fct-review-form-field">
            <label for="fct-review-title-<?php echo esc_attr($idSuffix); ?>"><?php esc_html_e('Review title', 'fluent-cart'); ?></label>
            <input type="text" id="fct-review-title-<?php echo esc_attr($idSuffix); ?>" name="title" maxlength="80" placeholder="<?php esc_attr_e('Summarize your experience', 'fluent-cart'); ?>"/>
            <span class="fct-char-count" data-char-count="fct-review-title-<?php echo esc_attr($idSuffix); ?>">0 / 80</span>
        </div>

        <div class="fct-review-form-field">
            <label for="fct-review-content-<?php echo esc_attr($idSuffix); ?>"><?php esc_html_e('Your review', 'fluent-cart'); ?> <span class="required">*</span></label>
            <textarea id="fct-review-content-<?php echo esc_attr($idSuffix); ?>" name="content" rows="5" maxlength="1500" placeholder="<?php esc_attr_e('What did you like or dislike? How did you use this product?', 'fluent-cart'); ?>"></textarea>
            <span class="fct-char-count" data-char-count="fct-review-content-<?php echo esc_attr($idSuffix); ?>">0 / 1500</span>
        </div>
        <?php
    }

    /**
     * How the fields are paced: 'steps' walks a wizard, 'inline' shows all.
     *
     * @return string
     */
    protected function formLayout(): string
    {
        $layout = Arr::get($this->renderOptions, 'layout');

        return $layout === 'inline' ? 'inline' : 'steps';
    }

    /**
     * The chrome around the fields: a side drawer, a centred modal, or none
     * at all (printed straight onto the page).
     *
     * @return string
     */
    /**
     * The default in any letter case is still the default: a colour picker
     * may hand back #F59E0B for the value the block stored as #f59e0b.
     *
     * @param string $starColor
     * @return bool
     */
    protected function isDefaultStarColor($starColor): bool
    {
        return strtolower((string) $starColor) === self::DEFAULT_STAR_COLOR;
    }

    protected function formContainer(): string
    {
        $container = Arr::get($this->renderOptions, 'container');

        return in_array($container, ['drawer', 'modal', 'none'], true) ? $container : 'drawer';
    }

    /**
     * The order this placement's hash names, when that order contains this
     * product. Non-null means the visitor may review it whatever the store's
     * permission mode says and whether or not they are logged in.
     *
     * @return \FluentCart\App\Models\Order|null
     */
    /**
     * Where a login link brings the visitor back to. The page they are on
     * when one exists — the order-review page in particular is reached from
     * an email and is not the product page, so a link there must not drop
     * them on the product afterwards — otherwise the product.
     *
     * @return string
     */
    protected function loginRedirectUrl(): string
    {
        if (Arr::get($this->renderOptions, 'orderHash', '') !== '') {
            $current = ProductReviewService::currentRequestUrl();
            if ($current !== '') {
                return $current;
            }
        }

        $queriedId = is_singular() ? get_queried_object_id() : 0;
        $redirect = $queriedId ? get_permalink($queriedId) : get_permalink($this->postId);

        return $redirect ?: home_url('/');
    }

    /**
     * The account whose existing review the form would open for editing.
     *
     * When an order grant owns the submission the review posts as the buyer,
     * whoever is signed in — so the visitor's own review of this product is
     * not the one this form edits. Seeding it would put the form in edit
     * mode and have it update the visitor's review under a byline naming the
     * buyer. The buyer signed in to their own account keeps their edit flow.
     *
     * @param int $userId
     * @return int 0 when no account's review is editable here
     */
    protected function editableReviewUserId($userId)
    {
        if ($userId && ProductReviewService::grantOwnsSubmission($this->orderGrant())) {
            return 0;
        }

        return (int) $userId;
    }

    protected function orderGrant()
    {
        return ProductReviewService::resolveOrderGrant(
            $this->postId,
            Arr::get($this->renderOptions, 'orderHash', ''),
            $this->itemId()
        );
    }
    /**
     * The rating summary card on its own — the standalone Rating Summary
     * block. Its summary-only marker refreshes these numbers without
     * initializing a review list.
     */
    public function renderSummarySection()
    {
        if (!$this->shouldRenderReviews()) {
            return;
        }

        $summary = ProductReviewService::getProductRatingSummary($this->postId);
        $restInfo = Helper::getRestInfo();
        $starColor = sanitize_hex_color(Arr::get($this->renderOptions, 'starColor')) ?: self::DEFAULT_STAR_COLOR;
        ?>
        <div class="fct-product-reviews-section fct-reviews-summary-only"
             data-fluent-cart-review-summary
             data-post-id="<?php echo esc_attr($this->postId); ?>"
             data-rest-url="<?php echo esc_url($restInfo['url']); ?>"
             data-rest-nonce="<?php echo esc_attr($restInfo['nonce']); ?>"
            <?php if (!$this->isDefaultStarColor($starColor)) : ?>
                style="--fct-star-color: <?php echo esc_attr($starColor); ?>"
            <?php endif; ?>
        >
            <?php // No built-in CTA — the Write a Review block is its own CTA ?>
            <?php $this->renderRatingSummary($summary, false); ?>
        </div>
        <?php
    }

    protected function renderRatingSummary($summary, $withCta = true)
    {
        ?>
        <div class="fct-reviews-summary" data-reviews-summary>
            <div class="fct-reviews-summary-left">
                <div class="fct-reviews-average">
                    <span class="fct-reviews-average-number" data-reviews-average><?php echo esc_html($summary['average']); ?></span>
                    <span class="fct-reviews-average-max">/5</span>
                </div>
                <div class="fct-reviews-summary-head-meta">
                <div class="fct-reviews-stars-display" data-reviews-stars role="img" aria-label="<?php printf(esc_attr__('Rated %s out of 5', 'fluent-cart'), esc_attr($summary['average'])); ?>">
                    <?php $this->renderStars($summary['average']); ?>
                </div>
                <div class="fct-reviews-total" data-reviews-total>
                    <?php
                    /* translators: %s - total review count formatted with commas */
                    printf(esc_html__('Based on %s reviews', 'fluent-cart'), esc_html(number_format_i18n($summary['total'])));
                    ?>
                </div>
                </div>
            </div>


            <div class="fct-reviews-summary-right">
                <?php foreach ([5, 4, 3, 2, 1] as $star) :
                    $count = $summary['breakdown'][$star] ?? 0;
                    $percentage = $summary['total'] > 0 ? round(($count / $summary['total']) * 100) : 0;
                    ?>
                    <div class="fct-reviews-bar-row" data-reviews-bar-row>
                        <div class="fct-reviews-bar-track">
                            <div class="fct-reviews-bar-fill" data-reviews-bar-fill style="width: <?php echo esc_attr($percentage); ?>%"></div>
                        </div>
                        <span class="fct-reviews-bar-label"><?php echo esc_html(number_format_i18n($star, 1)); ?> <span class="fct-bar-star-icon"><?php echo ReviewThreadMarkup::starSvg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span></span>
                        <span class="fct-reviews-bar-count" data-reviews-bar-count><?php echo esc_html(number_format_i18n($count)); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ($withCta) : ?>
                <?php $this->renderWriteReviewCta(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * The "Write a Review" / "Edit your review" / "Log in to Review" trigger
     * that opens the shared review drawer. Renders wherever a caller asks —
     * the rating summary (behind its toggle) and every Write a Review block.
     * ReviewForm.js binds triggers by delegation, so any of them opens the
     * product's single drawer.
     */
    public function renderWriteReviewCta()
    {
        if (!$this->shouldRenderReviews()) {
            return;
        }

        $permissionMode = $this->settings['review_permission_mode'] ?? 'verified_buyers';
        $userId = get_current_user_id();
        $needsLogin = (!$this->orderGrant() && $permissionMode !== 'anyone' && !$userId);

        if ($needsLogin) {
            $loginUrl = wp_login_url($this->loginRedirectUrl());
            ?>
            <a href="<?php echo esc_url($loginUrl); ?>" class="fct-review-cta-btn fct-reviews-summary-write-btn">
                <?php
                $loginText = trim((string) Arr::get($this->renderOptions, 'ctaLoginText', ''));
                echo $loginText !== '' ? esc_html($loginText) : esc_html__('Log in to Review', 'fluent-cart');
                ?>
            </a>
            <?php
        } else {
            $existingReview = $this->existingReviewFor($userId);
            $isEditMode = !empty($existingReview);
            ?>
            <?php
            // Both labels are rendered and one is hidden, so the script can
            // flip the button the moment a review is saved — the page is not
            // reloaded, and the visitor's next click must open their review
            // for editing, not offer to write another.
            $addText = trim((string) Arr::get($this->renderOptions, 'ctaAddText', ''));
            $editText = trim((string) Arr::get($this->renderOptions, 'ctaEditText', ''));
            ?>
            <?php // Names the form this button owns — see $instanceId. ?>
            <button type="button" class="fct-review-cta-btn fct-reviews-summary-write-btn" data-open-review-drawer data-post-id="<?php echo esc_attr($this->postId); ?>" data-review-form-target="<?php echo esc_attr($this->instanceId); ?>" data-item-id="<?php echo esc_attr($this->itemId()); ?>">
                <span class="fct-review-cta-label" data-cta-add<?php echo $isEditMode ? ' hidden' : ''; ?>>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" fill="currentColor" width="16" height="16" aria-hidden="true" focusable="false"><path d="M19.431 1.648a1.12 1.12 0 0 0-1.53.416l-.001.002l-3.972 6.88l-.48-.209a5.42 5.42 0 0 0-6.752 2.05l-.007.011l-.007.012l-.002-.001l-5.2 8.68l-.005.007v.008h-.002a3.506 3.506 0 0 0 1.279 4.78a3.44 3.44 0 0 0 2.05.464l-.058.102l-.001.001a2.8 2.8 0 0 0-.366 1.162l-.38 3.443v.008c-.063.813.848 1.326 1.511.87l.01-.006l2.752-2.082c.286-.202.535-.46.725-.754v.01a3.5 3.5 0 0 0 6.322 2.07q.19.18.405.34c1.04.768 2.399 1.09 3.783 1.09h9.24a2.24 2.24 0 0 0 2.226-1.99h.024V27.06h.002V18.2a2.82 2.82 0 0 0-1.704-2.585l-10.75-4.666l3.685-6.387l.002-.003a1.12 1.12 0 0 0-.416-1.53l-.003-.001l-2.375-1.378zm-6.508 9.038l-2.656 4.6a2.85 2.85 0 0 0-1.246 1.169l-3.208 5.551a1.52 1.52 0 0 1-1.308.757a1.45 1.45 0 0 1-.742-.2l-.004-.002A1.5 1.5 0 0 1 3.2 20.5l5.18-8.646a3.42 3.42 0 0 1 4.261-1.289h.004zm2.173 6.238l2.443-4.234L28.5 17.45l-.003-.004a.81.81 0 0 1 .5.753v.794h-.002v8.02h-5.81q-.382-.001-.748-.056l-.643-.141a4.57 4.57 0 0 1-2.421-1.693c-.8-1.094-2.27-1.37-3.414-.735l-.001.001c-.69.385-1.506.84-2.148 1.2l-1.113.62l-.011.007a2.005 2.005 0 0 1-2.735-.734a2.006 2.006 0 0 1 .736-2.735l4.225-2.44a1 1 0 0 0 .663-.857a4.3 4.3 0 0 0-.479-2.526m-9.567 8.58l2.585 1.506a1.8 1.8 0 0 1-.427.424l-.007.005l-1.515 1.146l-1.001-.583l.208-1.884v-.011c.02-.214.073-.418.157-.603M19.392 7.477l-2.6-1.492l.976-1.692l2.595 1.5z"/></svg>
                    <?php echo $addText !== '' ? esc_html($addText) : esc_html__('Write a Review', 'fluent-cart'); ?>
                </span>
                <span class="fct-review-cta-label" data-cta-edit<?php echo $isEditMode ? '' : ' hidden'; ?>>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.376 3.622a1 1 0 0 1 3.002 3.002L7.368 18.635a2 2 0 0 1-.855.506l-2.872.838a.5.5 0 0 1-.62-.62l.838-2.872a2 2 0 0 1 .506-.854z"/></svg>
                    <?php echo $editText !== '' ? esc_html($editText) : esc_html__('Edit your review', 'fluent-cart'); ?>
                </span>
            </button>
            <?php
        }
    }

    protected function renderControls($defaultSort = 'created_at-DESC', $showChips = true, $showSort = true)
    {
        $minRating = (int) Arr::get($this->renderOptions, 'minRating', 0);
        $minRating = ($minRating >= 1 && $minRating <= 5) ? $minRating : 0;
        $sortOptions = [
            'created_at-DESC' => __('Newest', 'fluent-cart'),
            'created_at-ASC'  => __('Oldest', 'fluent-cart'),
            'rating-DESC'     => __('Highest Rating', 'fluent-cart'),
            'rating-ASC'      => __('Lowest Rating', 'fluent-cart'),
        ];
        $sortOptions = apply_filters('fluent_cart/review/sort_options', $sortOptions);
        ?>
        <div class="fct-reviews-controls" data-reviews-controls>
            <?php if ($showChips) : ?>
            <div class="fct-reviews-filter-chips" data-reviews-filter-chips>
                <button type="button" class="fct-filter-chip active" data-filter-chip="all" aria-pressed="true"><?php esc_html_e('All', 'fluent-cart'); ?></button>
                <?php foreach ([5, 4, 3, 2, 1] as $star) : ?>
                    <?php if ($star < $minRating) { continue; } ?>
                    <button type="button" class="fct-filter-chip" data-filter-chip="<?php echo esc_attr($star); ?>" aria-pressed="false"><?php echo esc_html($star); ?> <?php echo ReviewThreadMarkup::starSvg(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
                <?php endforeach; ?>
                <?php do_action('fluent_cart/review/filter_chips', $this->postId); ?>
            </div>
            <?php endif; ?>

            <?php if ($showSort) : ?>
            <div class="fct-reviews-sort">
                <select data-reviews-sort aria-label="<?php esc_attr_e('Sort reviews', 'fluent-cart'); ?>">
                    <?php foreach ($sortOptions as $value => $label) : ?>
                        <option value="<?php echo esc_attr($value); ?>" <?php selected($defaultSort, $value); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * The average-star row as a string — the summary endpoint returns it so
     * the storefront script swaps markup instead of assembling it.
     *
     * @param float|int $rating
     * @return string
     */
    public function starsHtml($rating): string
    {
        ob_start();
        $this->renderStars($rating);

        return (string) ob_get_clean();
    }

    protected function renderStars($rating)
    {
        $fullStars = floor($rating);
        $halfStar = ($rating - $fullStars) >= 0.5;
        $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);

        for ($i = 0; $i < $fullStars; $i++) {
            echo '<span class="fct-star fct-star-filled">' . ReviewThreadMarkup::starSvg() . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        if ($halfStar) {
            echo '<span class="fct-star fct-star-half"><span class="fct-star-half-empty">' . ReviewThreadMarkup::starSvg() . '</span><span class="fct-star-half-fill">' . ReviewThreadMarkup::starSvg() . '</span></span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        for ($i = 0; $i < $emptyStars; $i++) {
            echo '<span class="fct-star fct-star-empty">' . ReviewThreadMarkup::starSvg() . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /**
     * One tile dimension in pixels, or 0 to leave it to the stylesheet.
     *
     * @param mixed $value
     * @return int
     */
    public static function mediaTileSize($value): int
    {
        $size = (int) $value;

        if ($size < 1) {
            return 0;
        }

        return min(static::MAX_MEDIA_TILE_PX, max(16, $size));
    }

    /**
     * How many tiles a gallery shows before the + tile, 0 for all of them.
     *
     * Capped at what a review may actually hold: showing 40 of a store that
     * allows 5 photos is a limit that never comes into play, and the number
     * an editor is offered should mean something.
     *
     * @param mixed $value
     * @return int
     */
    public static function mediaVisibleCount($value): int
    {
        return max(0, min(static::maxPhotosPerReview(), (int) $value));
    }

    /**
     * The store's photos-per-review limit.
     *
     * PRO owns the setting and enforces it on upload; this reads the same
     * value so the display side agrees with it without depending on PRO being
     * installed. Floored at 1, the way PRO floors its own.
     *
     * @return int
     */
    public static function maxPhotosPerReview(): int
    {
        $settings = ProductReviewService::getReviewSettings();

        return max(1, (int) Arr::get(
            $settings,
            'max_photos_per_review',
            static::DEFAULT_MAX_PHOTOS_PER_REVIEW
        ));
    }
}
