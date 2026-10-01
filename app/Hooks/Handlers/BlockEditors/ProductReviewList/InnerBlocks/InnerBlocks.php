<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors\ProductReviewList\InnerBlocks;

use FluentCart\Api\Contracts\CanEnqueue;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\App\Services\Renderer\ProductReviewRenderer;
use FluentCart\App\Services\Renderer\ReviewListRenderer;
use FluentCart\App\Services\Renderer\ReviewThreadMarkup;
use FluentCart\Framework\Support\Arr;

/**
 * The review item, taken apart into blocks.
 *
 * One loop block that owns the query, and a child block per field of a review
 * — avatar, name, badge, variation, rating, date, title, content, photos,
 * reply. The loop builds its inner blocks once per review with that review in
 * their context, and each child reads it from there and knows nothing else. The same shape ShopApp's product loop and its children use, so
 * there is one mechanism here rather than two.
 *
 * Every child renders through ReviewThreadMarkup, the markup the storefront
 * list already emits, so a review composed out of blocks and a review from
 * ReviewListRenderer are the same review — not two renderings to keep in step.
 */
class InnerBlocks
{
    use CanEnqueue;

    /**
     * The block a review field belongs inside — the Review List, which repeats
     * its children once per review. Registered as each child's ancestor, so the
     * editor offers them anywhere inside a list, at any nesting depth, and
     * nowhere else.
     */
    public static $parentBlock = 'fluent-cart/product-review-list';

    /**
     * What each list's blocks have drawn, one frame per list being rendered.
     *
     * The only thing the blocks do not read out of their context: context
     * flows from a block down to its descendants, and this is an answer
     * travelling the other way — the renderer asks, afterwards, which of the
     * parts the editor's blocks already drew, so it can fill in the rest
     * without drawing a second one over what they kept.
     *
     * @var array
     */
    protected static $drawn = [];

    /**
     * The answers of the pass that finished most recently, which is what
     * wasDrawn() reads. The renderer asks straight after the pass it
     * triggered, so that pass is always its own, nested or not.
     *
     * @var array
     */
    protected static $lastDrawn = [];

    /**
     * Whether a Review Item is already drawing its rows.
     *
     * The editor can nest one inside another — it satisfies its own ancestor
     * rule — and the inner one reads the same collection off context and loops
     * it again, once per row of the outer. Ten reviews become a hundred and
     * ten cards, and the endpoint repeats it on every page change.
     *
     * @var bool
     */
    protected static $insideRow = false;

    /**
     * Where composed rows are kept, how long an unused one is kept for, and
     * how many are kept at most.
     */
    const COMPOSITION_PREFIX = 'fct_review_item_composition_';
    const COMPOSITION_RETENTION_DAYS = 30;
    /**
     * How many rows one sweep looks at. A bound on the query, not on how many
     * rows the store may hold: a site is entitled to as many composed rows as
     * it has composed. Whatever a sweep does not reach, the next one does.
     */
    const COMPOSITION_SWEEP_BATCH = 200;

    /**
     * Where the last sweep stopped. Deliberately not under COMPOSITION_PREFIX:
     * the sweep would otherwise read its own cursor back as one of the rows.
     */
    const SWEEP_CURSOR_KEY = 'fct_review_composition_sweep_cursor';

    /**
     * How many rows one sweep may ask the content about. post_content is
     * LONGTEXT and nothing indexes it, so each of those is a scan of the posts
     * table - and this runs inside a page render. Whatever is left over keeps
     * its place and is asked on a later sweep; nothing is deleted unasked.
     */
    const COMPOSITION_SWEEP_CHECKS = 10;

    /**
     * Shared support set for the text-shaped children. Colour, size and
     * spacing are the controls an editor reaches for on a line of text; the
     * rest would be noise on a field this small.
     */
    public static function textBlockSupport(): array
    {
        return [
            'html'       => false,
            'align'      => ['left', 'center', 'right'],
            'typography' => [
                'fontSize'   => true,
                'lineHeight' => true,
            ],
            'spacing'    => [
                'margin'  => true,
                'padding' => true,
            ],
            'color'      => [
                'text' => true,
            ],
        ];
    }

    public static function register()
    {
        $registry = new self();

        foreach ($registry->getInnerBlocks() as $block) {
            register_block_type($block['slug'], [
                'apiVersion'      => 3,
                'api_version'     => 3,
                'title'           => $block['title'],
                'category'        => 'fluent-cart',
                // ancestor, not parent: parent means the IMMEDIATE parent, and
                // the row template nests most of these inside core/group. With
                // parent, a field deleted from inside a group could never be
                // put back — the inserter there offers only blocks whose parent
                // is that group. ancestor asks the same question of every level
                // up, which is what "belongs inside a Review List" means.
                // A field belongs inside a Review Item, not merely somewhere
                // in the list: placed beside one it has no review to read and
                // renders nothing, while the canvas still shows it against the
                // stand-in. The Review Item itself belongs to the list.
                'ancestor'        => Arr::get($block, 'ancestor', ['fluent-cart/review-item']),
                'render_callback' => $block['callback'],
                'supports'        => Arr::get($block, 'supports', []),
                'attributes'      => Arr::get($block, 'attributes', []),
                // Only a declared key reaches $block->context. Undeclared, the
                // value still travels past this block to its descendants — it
                // is simply not handed to this one.
                'uses_context'    => Arr::get($block, 'uses_context', []),
                // The row draws its own children, once per review. Without
                // this WordPress renders them first, with no review to read —
                // work thrown away, and a Review Item nested in another would
                // have its callback run before the one around it.
                'skip_inner_blocks' => Arr::get($block, 'skip_inner_blocks', false),
            ]);
        }

        add_action('enqueue_block_editor_assets', function () use ($registry) {
            $registry->enqueueScripts();
            // The row's own styles. The editor never loads the storefront
            // review stylesheet, so without this the composed row draws
            // unstyled inside a list preview that looks finished.
            $registry->enqueueStyles();
        });

        // And again, for the canvas.
        //
        // Since WP 6.3 the editor draws the blocks inside an iframe, and what
        // reaches that document is what was enqueued on enqueue_block_assets —
        // the hook for the content itself. The one above is the admin page
        // around it, which is where the stylesheet was stopping: it loaded, the
        // network tab showed it, and every rule in it missed the blocks it was
        // written for.
        //
        // The flag is cleared first because the enqueue above already set it
        // and enqueueStyles() answers once per instance. The same pair
        // BlockEditor::init() registers, which is how the container blocks —
        // ShopApp among them — have always reached the canvas.
        add_action('enqueue_block_assets', function () use ($registry) {
            if (!is_admin()) {
                return;
            }

            $registry->isStylesLoaded = false;
            $registry->enqueueStyles();
        });
    }

    /**
     * The block definitions, shared with the editor.
     *
     * `component` names the JSX module that draws the editor preview — the
     * registry on the JS side maps it, so a block is described once and both
     * halves read the same description.
     *
     * @return array
     */
    public function getInnerBlocks(): array
    {
        return [
            // The list header — rendered once above the rows, not once per
            // review. The Review List pulls these out of its children and
            // hands them to the renderer's header slot; see headerBlockSlugs().
            [
                'title'     => __('Review Count', 'fluent-cart'),
                'slug'      => 'fluent-cart/review-list-count',
                'callback'  => [$this, 'renderListCount'],
                'ancestor' => [static::$parentBlock],
                'uses_context' => [
                    'fluent-cart/review_list_header',
                    'fluent-cart/review_list_rows_only',
                ],
                'component' => 'ReviewListCountBlock',
                'icon'      => 'editor-ol',
                'supports'  => static::textBlockSupport(),
            ],

            [
                'title'     => __('Review Filter', 'fluent-cart'),
                'slug'      => 'fluent-cart/review-list-filter',
                'callback'  => [$this, 'renderListFilter'],
                'ancestor' => [static::$parentBlock],
                'uses_context' => [
                    'fluent-cart/review_list_header',
                    'fluent-cart/review_list_rows_only',
                ],
                'component' => 'ReviewListFilterBlock',
                'icon'      => 'filter',
                'supports'  => [
                    'html'    => false,
                    'spacing' => ['margin' => true, 'padding' => true],
                ],
            ],

            [
                'title'      => __('Review Sorting', 'fluent-cart'),
                'slug'       => 'fluent-cart/review-list-sorting',
                'callback'   => [$this, 'renderListSorting'],
                'ancestor' => [static::$parentBlock],
                'uses_context' => [
                    'fluent-cart/review_list_header',
                    'fluent-cart/review_list_rows_only',
                ],
                'component'  => 'ReviewListSortingBlock',
                'icon'       => 'sort',
                'supports'   => [
                    'html'    => false,
                    'spacing' => ['margin' => true, 'padding' => true],
                ],
                // Which order the list opens in. Saved on the control that
                // changes it; the list lifts it, because the first query and
                // the container attribute are both the list's.
                'attributes' => [
                    'defaultSort' => ['type' => 'string', 'default' => 'created_at-DESC'],
                ],
            ],

            // Below the rows, and rendered once for the same reason the header
            // blocks are.
            [
                'title'      => __('Review Pagination', 'fluent-cart'),
                'slug'       => 'fluent-cart/review-list-pagination',
                'callback'   => [$this, 'renderListPagination'],
                'uses_context' => [
                    'fluent-cart/review_list_pagination',
                    'fluent-cart/review_list_rows_only',
                ],
                'component'  => 'ReviewPaginationBlock',
                'icon'       => 'controls-forward',
                'supports'   => [
                    'html'    => false,
                    'spacing' => ['margin' => true, 'padding' => true],
                ],
                // Empty means "leave it to the stylesheet", so a list that has
                // always centred its pager keeps doing so untouched.
                'attributes' => [
                    'justify'        => ['type' => 'string', 'default' => ''],
                    'paginationType' => ['type' => 'string', 'default' => 'numbers'],
                    // 0 means "ask the store", which is what an untouched
                    // pager and a list with no pager at all both come to.
                    'perPage'        => ['type' => 'number', 'default' => 0],
                ],
            ],

            [
                'title'     => __('Review Item', 'fluent-cart'),
                'slug'              => 'fluent-cart/review-item',
                'callback'          => [$this, 'renderReviewItem'],
                'ancestor'          => [static::$parentBlock],
                'skip_inner_blocks' => true,
                'attributes'        => [
                    'wp_client_id' => ['type' => 'string', 'default' => ''],
                    // The lowest rating this list will show. 0 is every
                    // review, which is what a list nobody has set a floor on
                    // shows. Read by the list, which owns the query — the row
                    // itself is handed one review at a time and cannot decide
                    // which ones arrive.
                    'minRating'    => ['type' => 'number', 'default' => 0],
                ],
                'uses_context' => [
                    'fluent-cart/review_list_reviews',
                    'fluent-cart/review_list_rows_only',
                    // The list's own attributes, under the names its
                    // provideContext() publishes them by.
                    'fluent-cart/review_view_mode',
                    'fluent-cart/review_grid_columns',
                    'fluent-cart/review_slider_settings',
                ],
                'component' => 'ReviewItemBlock',
                'icon'      => 'format-status',
                'supports'  => [
                    'html'    => false,
                    'spacing' => ['margin' => true, 'padding' => true],
                ],
            ],

            [
                'title'     => __('Review Author Avatar', 'fluent-cart'),
                'slug'      => 'fluent-cart/review-item-avatar',
                'callback'  => [$this, 'renderAvatar'],
                'uses_context' => [
                    'fluent-cart/review',
                ],
                'component' => 'ReviewAvatarBlock',
                'icon'      => 'admin-users',
                'supports'  => [
                    'html'    => false,
                    'spacing' => ['margin' => true, 'padding' => true],
                ],
            ],

            [
                'title'     => __('Review Author Name', 'fluent-cart'),
                'slug'      => 'fluent-cart/review-item-author-name',
                'callback'  => [$this, 'renderAuthorName'],
                'uses_context' => [
                    'fluent-cart/review',
                ],
                'component' => 'ReviewAuthorNameBlock',
                'icon'      => 'nametag',
                'supports'  => static::textBlockSupport(),
            ],

            [
                'title'     => __('Review Verified Badge', 'fluent-cart'),
                'slug'      => 'fluent-cart/review-item-verified-badge',
                'callback'  => [$this, 'renderVerifiedBadge'],
                'uses_context' => [
                    'fluent-cart/review',
                ],
                'component' => 'ReviewVerifiedBadgeBlock',
                'icon'      => 'yes-alt',
                'supports'  => static::textBlockSupport(),
            ],

            [
                'title'     => __('Review Variation Title', 'fluent-cart'),
                'slug'      => 'fluent-cart/review-item-variation-title',
                'callback'  => [$this, 'renderVariationTitle'],
                'uses_context' => [
                    'fluent-cart/review',
                ],
                'component' => 'ReviewVariationTitleBlock',
                'icon'      => 'tag',
                'supports'  => static::textBlockSupport(),
            ],

            [
                'title'      => __('Review Rating', 'fluent-cart'),
                'slug'       => 'fluent-cart/review-item-rating',
                'callback'   => [$this, 'renderRating'],
                'uses_context' => [
                    'fluent-cart/review',
                ],
                // The star colour belongs to the block that draws the stars.
                // Empty means inherit, which is what keeps a list that already
                // sets --fct-star-color on its container in charge of rows
                // that have not overridden it.
                'attributes' => [
                    'starColor' => ['type' => 'string', 'default' => ''],
                ],
                'component' => 'ReviewRatingBlock',
                'icon'      => 'star-filled',
                'supports'  => [
                    'html'    => false,
                    'align'   => ['left', 'center', 'right'],
                    'spacing' => ['margin' => true, 'padding' => true],
                    'color'   => ['text' => true],
                ],
            ],

            [
                'title'     => __('Review Date', 'fluent-cart'),
                'slug'      => 'fluent-cart/review-item-date',
                'callback'  => [$this, 'renderDate'],
                'uses_context' => [
                    'fluent-cart/review',
                ],
                'component' => 'ReviewDateBlock',
                'icon'      => 'calendar-alt',
                'supports'  => static::textBlockSupport(),
            ],

            [
                'title'     => __('Review Title', 'fluent-cart'),
                'slug'      => 'fluent-cart/review-item-title',
                'callback'  => [$this, 'renderTitle'],
                'uses_context' => [
                    'fluent-cart/review',
                ],
                'component' => 'ReviewTitleBlock',
                'icon'      => 'heading',
                'supports'  => static::textBlockSupport(),
            ],

            [
                'title'     => __('Review Content', 'fluent-cart'),
                'slug'      => 'fluent-cart/review-item-content',
                'callback'  => [$this, 'renderContent'],
                'uses_context' => [
                    'fluent-cart/review',
                ],
                'component' => 'ReviewContentBlock',
                'icon'      => 'editor-alignleft',
                'supports'  => static::textBlockSupport(),
                // 0 is the whole review, which is what a list that has never
                // been given a number shows. Words rather than characters, so
                // a cut never lands mid-word.
                'attributes' => [
                    'maxWords' => ['type' => 'number', 'default' => 0],
                ],
            ],

            [
                'title'     => __('Review Photos', 'fluent-cart'),
                'slug'      => 'fluent-cart/review-item-photos',
                'callback'  => [$this, 'renderPhotos'],
                'uses_context' => [
                    'fluent-cart/review',
                ],
                'component' => 'ReviewPhotosBlock',
                'icon'      => 'format-gallery',
                'supports'  => [
                    'html'    => false,
                    'spacing' => ['margin' => true, 'padding' => true],
                ],
                // Registered server-side too: this block renders on the
                // server, and an attribute the PHP registration does not know
                // never reaches the render callback.
                'attributes' => [
                    'visibleCount' => ['type' => 'number', 'default' => 0],
                    'mediaWidth'   => ['type' => 'number', 'default' => 72],
                    'mediaHeight'  => ['type' => 'number', 'default' => 72],
                    'mediaFullWidth' => ['type' => 'boolean', 'default' => false],
                    'mediaFlush' => ['type' => 'boolean', 'default' => false],
                    'mediaBackdrop' => ['type' => 'boolean', 'default' => false],
                    'mediaMore' => ['type' => 'string', 'default' => 'overlay'],
                ],
            ],

            [
                'title'     => __('Review Votes', 'fluent-cart'),
                'slug'      => 'fluent-cart/review-item-votes',
                'callback'  => [$this, 'renderVotes'],
                'uses_context' => [
                    'fluent-cart/review',
                ],
                'component' => 'ReviewVotesBlock',
                'icon'      => 'thumbs-up',
                'supports'  => [
                    'html'    => false,
                    'spacing' => ['margin' => true, 'padding' => true],
                ],
            ],

            [
                'title'     => __('Review Reply', 'fluent-cart'),
                'slug'      => 'fluent-cart/review-item-reply',
                'callback'  => [$this, 'renderReply'],
                'uses_context' => [
                    'fluent-cart/review',
                ],
                'component' => 'ReviewReplyBlock',
                'icon'      => 'format-chat',
                'supports'  => static::textBlockSupport(),
            ],
        ];
    }

    /**
     * The orders a list may open in, as value => label.
     *
     * Filterable, so a store adding a sort gets it in the block's dropdown and
     * in the rendered select from the same place.
     *
     * @return array<string, string>
     */
    public static function sortOptions(): array
    {
        return apply_filters('fluent_cart/review/sort_options', [
            'created_at-DESC' => __('Newest', 'fluent-cart'),
            'created_at-ASC'  => __('Oldest', 'fluent-cart'),
            'rating-DESC'     => __('Highest Rating', 'fluent-cart'),
            'rating-ASC'      => __('Lowest Rating', 'fluent-cart'),
        ]);
    }

    /**
     * The children that belong to the list header rather than to a review.
     *
     * The Review List repeats its children once per review, so these would be
     * drawn three times in a three-review list if they were treated like the
     * rest. It partitions on this list instead and renders them once, above
     * the rows.
     *
     * @return array<int, string>
     */
    public static function headerBlockSlugs(): array
    {
        return [
            'fluent-cart/review-list-count',
            'fluent-cart/review-list-filter',
            'fluent-cart/review-list-sorting',
        ];
    }

    /**
     * The children that belong below the rows rather than to a review.
     *
     * Kept apart from headerBlockSlugs() because the two go to different
     * slots, not because they behave differently — both are rendered once.
     *
     * @return array<int, string>
     */
    public static function paginationBlockSlugs(): array
    {
        return ['fluent-cart/review-list-pagination'];
    }

    /**
     * The review each child is drawing, or null outside a loop.
     *
     * An array, not a model: the loop renders the same payload the storefront
     * list does, already decorated by getPublicReviewsPayload() and by
     * whatever PRO attached to it.
     *
     * @return array|null
     */
    protected function currentReview($block)
    {
        $review = static::contextValue($block, 'fluent-cart/review');

        return is_array($review) ? $review : null;
    }

    /**
     * One value out of a block's context.
     *
     * Blocks are rendered by WordPress, which builds them itself — a callback
     * can be reached with no block at all (a direct call, a filter rendering a
     * parsed block by hand), so the absence of one is a normal answer here
     * rather than a broken call.
     *
     * @param mixed $block
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected static function contextValue($block, string $key, $default = null)
    {
        if (!($block instanceof \WP_Block)) {
            return $default;
        }

        return Arr::get($block->context, $key, $default);
    }

    /**
     * Whether this pass is drawing the rows on their own.
     *
     * The endpoint behind a page change is asked for the contents of the rows
     * element, so everything sitting outside it draws nothing.
     *
     * @param mixed $block
     * @return bool
     */
    protected static function isRowsOnly($block): bool
    {
        return !empty(static::contextValue($block, 'fluent-cart/review_list_rows_only'));
    }

    /**
     * What the list header's blocks are drawing — the total and the sort the
     * list opened on. Set by the Review List around its header pass, the same
     * way the review itself is put in context around each row.
     *
     * Never null. A header block outside that pass resolves the same values
     * for itself, the way the product blocks fall back to their own product_id
     * when there is no loop to read a product from. Returning nothing would
     * make a misplaced block vanish silently, which is the hardest kind of
     * empty to explain.
     *
     * @return array
     */
    protected function currentListHeader($block): array
    {
        $header = static::contextValue($block, 'fluent-cart/review_list_header');

        if (is_array($header) && $header) {
            return $header;
        }

        // Outside the list's header pass, the same way the product blocks
        // resolve a product when there is no loop to read one from: ask for
        // what the context would have carried rather than drawing nothing.
        // A header block with no product to count still has a sort to show.
        $product = fluent_cart_get_current_product();
        $summary = $product ? ProductReviewService::getProductRatingSummary($product->ID) : [];

        return [
            'total'        => (int) Arr::get((array) $summary, 'total', 0),
            'default_sort' => 'created_at-DESC',
        ];
    }

    /**
     * The pager below the rows.
     *
     * The block owns the whole [data-reviews-pagination] element rather than
     * sitting inside one: the storefront script replaces that element's
     * innerHTML on every page change, so anything the block drew inside it
     * would be thrown away on the first click.
     *
     * The buttons themselves come from ReviewListRenderer, already rendered —
     * a second implementation of the same pager here is a second thing to keep
     * in step with the markup the script re-renders into it.
     */
    public function renderListPagination($attributes, $content, $block): string
    {
        // The endpoint answers with the rows for one page; the pager sits below
        // them and the script re-renders it separately, so it draws nothing
        // there.
        if (static::isRowsOnly($block)) {
            return '';
        }

        // A slider carries its own paging, so the pager stands down for one.
        // Resolved rather than read raw: grid, slider and masonry are Pro, and
        // a list saved as a slider on a lapsed site falls back to a plain list
        // -- which needs its pager, or every review past the first page
        // becomes unreachable. The list element itself already resolves the
        // mode this way; reading the saved value here made the two disagree.
        if (ProductReviewService::resolveViewMode(
            static::contextValue($block, 'fluent-cart/review_view_mode', 'list')
        ) === 'slider') {
            return '';
        }

        static::markDrawn('pagination');

        // Outside the list's pagination pass there are no buttons to show —
        // but the element still goes out. It is the element the storefront
        // script fills on every page change, so drawing nothing here is how a
        // list ends up with nowhere to put its pager. Empty is a state this
        // already has to handle: a single page of reviews renders it empty
        // too, and a filter that takes three pages down to one and back needs
        // it waiting either way.
        $pagerContext = static::contextValue($block, 'fluent-cart/review_list_pagination');
        $pagerButtons = is_array($pagerContext)
            ? (string) Arr::get($pagerContext, 'inner_html', '')
            : '';

        // The alignment goes on the wrapper, never on the buttons: the script
        // replaces this element's innerHTML on every page change, so anything
        // set on what is inside lasts until the first click.
        $wrapperClasses = ['fct-reviews-pagination'];
        $justify = (string) Arr::get((array) $attributes, 'justify', '');

        if (in_array($justify, ['left', 'center', 'right'], true)) {
            $wrapperClasses[] = 'is-justified-' . $justify;
        }

        // Printed even with one page of reviews: filtering to a single star can
        // take a list from three pages to one and back, and the script needs
        // somewhere to put the pager when it returns.
        return sprintf(
            '<div %s data-reviews-pagination>%s</div>',
            get_block_wrapper_attributes(['class' => implode(' ', $wrapperClasses)]),
            $pagerButtons
        );
    }

    /**
     * "12 Reviews". The count itself sits in its own span because the
     * storefront script rewrites it in place on every filter and page change
     * — the wrapper is markup, the span is the contract.
     */
    public function renderListCount($attributes, $content, $block): string
    {
        // The endpoint is answering with the rows for one page; the header sits
        // above them and is not re-rendered, so it draws nothing there.
        if (static::isRowsOnly($block)) {
            return '';
        }

        $header = $this->currentListHeader($block);

        static::markDrawn('header');

        $total = (int) Arr::get($header, 'total', 0);

        return sprintf(
            '<h3 %s>%s</h3>',
            get_block_wrapper_attributes(['class' => 'fct-reviews-section-title']),
            sprintf(
                /* translators: %s - total review count */
                esc_html__('%s Reviews', 'fluent-cart'),
                '<span data-reviews-total-count>' . esc_html((string) $total) . '</span>'
            )
        );
    }

    /**
     * The star filter chips. Emitted as one control, not one block per star:
     * the storefront script and PRO both treat [data-reviews-filter-chips] as
     * a single container — PRO appends its own chip into it — so splitting it
     * would leave them with nowhere to write.
     */
    public function renderListFilter($attributes, $content, $block): string
    {
        // The endpoint is answering with the rows for one page; the header sits
        // above them and is not re-rendered, so it draws nothing there.
        if (static::isRowsOnly($block)) {
            return '';
        }

        $header = $this->currentListHeader($block);

        static::markDrawn('header');

        $chips = sprintf(
            '<button type="button" class="fct-filter-chip active" data-filter-chip="all" aria-pressed="true">%s</button>',
            esc_html__('All', 'fluent-cart')
        );

        foreach ([5, 4, 3, 2, 1] as $star) {
            if ($star < (int) Arr::get($header, 'min_rating', 0)) {
                continue;
            }
            $chips .= sprintf(
                '<button type="button" class="fct-filter-chip" data-filter-chip="%1$d" aria-pressed="false">%1$d %2$s</button>',
                $star,
                ReviewThreadMarkup::starSvg()
            );
        }

        ob_start();
        do_action('fluent_cart/review/filter_chips', (int) Arr::get($header, 'post_id', 0));
        $chips .= (string) ob_get_clean();

        return sprintf(
            '<div %s data-reviews-filter-chips>%s</div>',
            get_block_wrapper_attributes(['class' => 'fct-reviews-filter-chips']),
            $chips
        );
    }

    /**
     * The sort select, with the list's own default already selected — the
     * script reads the current value from it rather than being told, so a
     * select that opened on the wrong option would sort against the rows
     * beneath it.
     */
    public function renderListSorting($attributes, $content, $block): string
    {
        // The endpoint is answering with the rows for one page; the header sits
        // above them and is not re-rendered, so it draws nothing there.
        if (static::isRowsOnly($block)) {
            return '';
        }

        $header = $this->currentListHeader($block);

        static::markDrawn('header');

        $sortOptions = static::sortOptions();

        $current = (string) Arr::get($header, 'default_sort', 'created_at-DESC');
        $options = '';

        foreach ($sortOptions as $value => $label) {
            $options .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr($value),
                selected($current, $value, false),
                esc_html($label)
            );
        }

        return sprintf(
            '<div %s><select data-reviews-sort aria-label="%s">%s</select></div>',
            get_block_wrapper_attributes(['class' => 'fct-reviews-sort']),
            esc_attr__('Sort reviews', 'fluent-cart'),
            $options
        );
    }

    public function renderAvatar($attributes, $content, $block): string
    {
        $review = $this->currentReview($block);

        if (!$review) {
            return '';
        }

        return sprintf(
            '<div %s>%s</div>',
            get_block_wrapper_attributes(['class' => 'fct-review-block-avatar']),
            ReviewThreadMarkup::avatarHtml((string) Arr::get($review, 'photo', ''))
        );
    }

    public function renderAuthorName($attributes, $content, $block): string
    {
        $review = $this->currentReview($block);
        $name = $review ? trim((string) Arr::get($review, 'reviewer_name', '')) : '';

        if ($name === '') {
            return '';
        }

        return sprintf(
            '<div %s>%s</div>',
            get_block_wrapper_attributes(['class' => 'fct-review-item-author']),
            esc_html($name)
        );
    }

    /**
     * Only for a review that carries the badge, and only while the store shows
     * it — the same switch the storefront list reads, so turning the badge off
     * turns it off wherever a review is rendered.
     */
    public function renderVerifiedBadge($attributes, $content, $block): string
    {
        $review = $this->currentReview($block);

        if (!$review || empty($review['is_verified'])) {
            return '';
        }

        $settings = ProductReviewService::getReviewSettings();
        if (Arr::get($settings, 'show_verified_badge', 'yes') !== 'yes') {
            return '';
        }

        return sprintf(
            '<div %s>%s</div>',
            get_block_wrapper_attributes(['class' => 'fct-review-verified']),
            esc_html__('Verified Purchase', 'fluent-cart')
        );
    }

    /**
     * The item the review is about, when it names one — the label the buyer
     * saw on their order, attached by attachItemLabels().
     */
    public function renderVariationTitle($attributes, $content, $block): string
    {
        $review = $this->currentReview($block);
        $label = $review ? trim((string) Arr::get($review, 'item_label', '')) : '';

        if ($label === '') {
            return '';
        }

        return sprintf(
            '<div %s>%s</div>',
            get_block_wrapper_attributes(['class' => 'fct-review-item-variant']),
            esc_html($label)
        );
    }

    public function renderRating($attributes, $content, $block): string
    {
        $review = $this->currentReview($block);
        $rating = $review ? (int) Arr::get($review, 'rating', 0) : 0;

        if ($rating < 1) {
            return '';
        }

        // Set as a custom property rather than a colour: the star glyphs read
        // --fct-star-color, and the empty stars must keep their own grey. An
        // invalid value is dropped rather than printed — sanitize_hex_color()
        // returns null for anything that is not a hex colour.
        $wrapperArgs = ['class' => 'fct-review-item-stars'];
        $starColor = sanitize_hex_color((string) Arr::get((array) $attributes, 'starColor', ''));

        if ($starColor) {
            $wrapperArgs['style'] = '--fct-star-color:' . $starColor;
        }

        return sprintf(
            '<div %s>%s</div>',
            get_block_wrapper_attributes($wrapperArgs),
            ReviewThreadMarkup::starsHtml($rating)
        );
    }

    public function renderDate($attributes, $content, $block): string
    {
        $review = $this->currentReview($block);
        $createdAt = $review ? (string) Arr::get($review, 'created_at', '') : '';

        if ($createdAt === '') {
            return '';
        }

        return sprintf(
            '<div %s>%s</div>',
            get_block_wrapper_attributes(['class' => 'fct-review-item-date']),
            esc_html(ReviewThreadMarkup::relativeDate($createdAt))
        );
    }

    public function renderTitle($attributes, $content, $block): string
    {
        $review = $this->currentReview($block);
        $title = $review ? trim((string) Arr::get($review, 'title', '')) : '';

        if ($title === '') {
            return '';
        }

        return sprintf(
            '<div %s>%s</div>',
            get_block_wrapper_attributes(['class' => 'fct-review-item-title']),
            esc_html($title)
        );
    }

    public function renderContent($attributes, $content, $block): string
    {
        $review = $this->currentReview($block);
        $content_text = $review ? trim((string) Arr::get($review, 'content', '')) : '';

        if ($content_text === '') {
            return '';
        }

        // The word limit travels as an attribute; the whole review still goes
        // out. review-clamp.js does the cutting and hands back a Read more
        // that restores the rest.
        //
        // Deliberately not wp_trim_words() here. The clamp is applied in the
        // browser precisely so the text is never unreachable — trimming on the
        // server would leave a reader without JavaScript holding a review that
        // stops mid-sentence and no way to open it, and the toggle with
        // nothing to reveal.
        $maxWords = max(0, (int) Arr::get((array) $attributes, 'maxWords', 0));

        $wrapperAtts = ['class' => 'fct-review-item-content'];

        if ($maxWords > 0) {
            $wrapperAtts['data-max-words'] = (string) $maxWords;
        }

        return sprintf(
            '<div %s>%s</div>',
            get_block_wrapper_attributes($wrapperAtts),
            esc_html($content_text)
        );
    }

    /**
     * The photos PRO attached to the review. Free ships no media pipeline, so
     * the key is simply absent and the block renders nothing.
     */
    public function renderPhotos($attributes, $content, $block): string
    {
        $review = $this->currentReview($block);
        $media = $review ? Arr::get($review, 'media', []) : [];

        if (!$media || !is_array($media)) {
            return '';
        }

        $options = [
            'visible' => ProductReviewRenderer::mediaVisibleCount(Arr::get($attributes, 'visibleCount', 0)),
            'width'   => ProductReviewRenderer::mediaTileSize(Arr::get($attributes, 'mediaWidth', 0)),
            'height'  => ProductReviewRenderer::mediaTileSize(Arr::get($attributes, 'mediaHeight', 0)),
            'fullWidth' => (bool) Arr::get($attributes, 'mediaFullWidth', false),
            // Pro. A saved attribute is left alone so a lapsed site keeps its
            // setting and gets it back on renewal; it simply stops being drawn.
            'flush'     => ProductReviewService::isPhotoStylingAllowed() && (bool) Arr::get($attributes, 'mediaFlush', false),
            'backdrop'  => ProductReviewService::isPhotoStylingAllowed() && (bool) Arr::get($attributes, 'mediaBackdrop', false),
            'more'      => ReviewThreadMarkup::moreTilePlacement(Arr::get($attributes, 'mediaMore', 'overlay')),
        ];

        $tiles = ReviewThreadMarkup::mediaTilesHtml($media, 'list', $options);

        if ($tiles === '') {
            return '';
        }

        // The block's wrapper IS the gallery, not a box around one: spacing
        // support writes its margin here, and a second element would stack
        // that on top of the gallery's own. get_block_wrapper_attributes
        // merges the tile-size declaration with whatever the block's style
        // supports have already written.
        return sprintf(
            '<div %s>%s</div>',
            get_block_wrapper_attributes(array_filter([
                // The modifier is what lets a photograph at the head of a card
                // bleed to the card's own edges and take a fixed shape. Asked
                // for, never inferred: full width alone is still an ordinary
                // photograph with the card's padding around it.
                'class' => 'fct-review-media-gallery'
                    . ($options['flush'] ? ' fct-review-media-gallery--flush' : '')
                    . ($options['backdrop'] ? ' fct-review-media-gallery--backdrop' : ''),
                'style' => ReviewThreadMarkup::mediaSizeStyle($options),
            ])),
            $tiles
        );
    }

    /**
     * The View Reply trigger.
     *
     * The markup ReviewListRenderer::renderViewReplyButton() emits, attribute
     * for attribute: the class carries the storefront's styling and
     * data-view-replies is what Reviews.js binds the thread modal to. A class
     * of this block's own would render an unstyled button that does nothing
     * when clicked.
     *
     * Shown for a review that has replies, or as "Reply" to the review's own
     * author while threaded replies are on — the same two cases the rendered
     * list shows it for. The row it sits in is also the Helpful votes' row:
     * .fct-review-footer is what PRO's script looks for.
     */
    public function renderReply($attributes, $content, $block): string
    {
        $review = $this->currentReview($block);

        if (!$review) {
            return '';
        }

        $replyCount = (int) Arr::get($review, 'reply_count', 0);
        $canThread = ProductReviewService::isMultipleRepliesAllowed() && is_user_logged_in();

        $label = '';
        if ($replyCount > 0) {
            $label = __('View Reply', 'fluent-cart');
        } elseif ($canThread && !empty($review['is_owner'])) {
            $label = __('Reply', 'fluent-cart');
        }

        if ($label === '') {
            return '';
        }

        $icon = ReviewThreadMarkup::replyIconSvg();

        $button = sprintf(
            '<button type="button" class="fct-review-view-replies" data-view-replies aria-haspopup="dialog" data-review-id="%s">%s<span>%s</span></button>',
            esc_attr((string) Arr::get($review, 'id', '')),
            $icon,
            esc_html($label)
        );

        // No footer wrapper of its own: the row's template puts this and the
        // votes inside one .fct-review-footer, which is both what lines them
        // up and what PRO's script appends the Helpful buttons to.
        return sprintf(
            '<div %s>%s</div>',
            get_block_wrapper_attributes(['class' => 'fct-review-reply-action']),
            $button
        );
    }

    /**
     * One review, drawn once per review on the page.
     *
     * The repeating happens here rather than in the list, the way ShopApp's
     * product loop repeats its own children rather than having the shop app
     * sort its children into things that repeat and things that do not. The
     * list renders each of its children once, in the order an editor placed
     * them; this block is the one that renders many times, and what it repeats
     * is what is inside it.
     *
     * Each pass builds the inner blocks with its review in their context, so
     * a field block reads the review it is drawing straight off itself. There
     * is nothing to put back: context belongs to the block it was built with,
     * so a list nested inside another cannot disturb the review the outer one
     * is in the middle of.
     */
    public function renderReviewItem($attributes, $content, $block): string
    {
        // A Review Item inside a Review Item. The inner one is handed the same
        // collection and would loop it once per row of the outer, so it draws
        // nothing instead. Caught here rather than only in the inserter,
        // because a page saved before that restriction still renders.
        if (static::$insideRow) {
            return '';
        }

        // A second Review Item beside the first, in one list. Each would draw
        // its own rows element, and the storefront script reads the first one
        // only — so the second would sit there showing page one for good,
        // under a pager that says otherwise.
        if (static::drewThisPass('list')) {
            return '';
        }

        $token = static::rememberComposition($attributes, $block);

        $reviews = static::contextValue($block, 'fluent-cart/review_list_reviews');
        $reviews = is_array($reviews) ? $reviews : [];

        if (!($block instanceof \WP_Block) || empty($block->inner_blocks)) {
            return '';
        }

        // Nothing to draw yet, but the element and the id still go out: the
        // storefront script refills this element and sends that id back, and a
        // product whose first reviews arrive later would otherwise have
        // neither — the first sort or filter coming back as the fixed row.
        $html = $reviews ? '' : ReviewListRenderer::emptyStateHtml();

        // The block's own classes and styles go on the card, not on the element
        // around the cards: an editor setting padding on Review Item means
        // padding on a review, and this block draws one review per pass.
        $cardAttributes = get_block_wrapper_attributes(['class' => 'fct-review-item']);
        $blockContext = $block->context;

        static::$insideRow = true;

        foreach ($reviews as $review) {
            // Rebuilt per review rather than rendered in place: the review is
            // context, and a block is handed its context once, when it is
            // built. Nothing to put back afterwards — each pass gets its own
            // blocks, so a list nested inside another cannot disturb the
            // review the outer one is in the middle of.
            $rowContext = ['fluent-cart/review' => (array) $review];

            $fields = '';
            foreach ($block->inner_blocks as $innerBlock) {
                if (isset($innerBlock->parsed_block)) {
                    $innerContext = array_merge($innerBlock->context, $blockContext, $rowContext);
                    $instance = new \WP_Block($innerBlock->parsed_block, $innerContext);
                    $fields .= $instance->render();
                }
            }

            $html .= static::reviewItemWrapper((array) $review, $fields, $cardAttributes);
        }

        static::$insideRow = false;

        static::markDrawn('list');

        // The element the storefront script empties and refills on every page
        // change. It belongs to whatever draws the rows, which is this block —
        // except when the endpoint is answering a page change, because then
        // what is being asked for is the contents of that element, and
        // wrapping the rows in another one would nest a second list inside it.
        if (static::isRowsOnly($block)) {
            return $html;
        }

        // The layout the list resolved. It goes on this element because this is
        // the element the storefront script empties and refills — the class has
        // to outlive a page change without travelling with the request.
        $viewMode = ProductReviewService::resolveViewMode(
            static::contextValue($block, 'fluent-cart/review_view_mode', 'list')
        );
        // Two to six, the same clamp the renderer applies to its own copy.
        // Six rather than four because a photograph row is not read: the
        // column control still offers no more than four, so only a preset —
        // Photo Strip — reaches past it. These arrive from saved block
        // attributes, which are hand-editable, and go straight into a
        // stylesheet variable and into Swiper, so they stay clamped.
        $columns = min(6, max(2, (int) static::contextValue($block, 'fluent-cart/review_grid_columns', 2)));
        $hasColumns = $viewMode !== 'list';

        $classes = ['fct-reviews-list'];
        if ($hasColumns) {
            $classes[] = 'fct-reviews-list--' . $viewMode;
            // The count as a class as well as a custom property. A property
            // cannot be selected on, and how narrow a list may get before it
            // drops a column depends on how many it was asked for -- three at
            // 285px each reads, four at 200px does not.
            $classes[] = 'fct-reviews-list--cols-' . $columns;
        }

        $attributes = '';

        if ($hasColumns) {
            $attributes .= ' style="--fct-review-columns: ' . $columns . '"';
        }

        // What the storefront script reads to build the Swiper. It belongs on
        // this element for the same reason the class does: the script empties
        // and refills it rather than replacing it, so what is on it survives a
        // page change.
        if ($viewMode === 'slider') {
            $sliderSettings = ProductReviewRenderer::normalizeSliderSettings(
                (array) static::contextValue($block, 'fluent-cart/review_slider_settings', [])
            );
            $attributes .= ' data-reviews-slider="' . $columns . '"';
            $attributes .= ' data-slider-settings="' . esc_attr(wp_json_encode($sliderSettings)) . '"';
        }

        return sprintf(
            '<div class="%s"%s%s data-reviews-list>%s</div>',
            esc_attr(implode(' ', $classes)),
            $attributes,
            $token ? ' data-client-id="' . esc_attr($token) . '"' : '',
            $html
        );
    }

    /**
     * Keep this row's markup under the id the editor minted for the block, so
     * the endpoint can draw the same row.
     *
     * Where it is kept, and why, is on compositionKey() below.
     *
     * @param array $attributes
     * @param mixed $block
     * @return string the id the page prints, or '' when there is nothing to remember
     */
    protected static function rememberComposition($attributes, $block): string
    {
        $clientId = Arr::get($attributes, 'wp_client_id', '');

        if (!$clientId || !($block instanceof \WP_Block)) {
            return '';
        }

        $markup = $block->parsed_block['blockName'] ? serialize_block($block->parsed_block) : '';
        $owner = (int) get_the_ID();

        // The key is the block's id and what it draws, together.
        //
        // The id alone is not enough: Gutenberg hands a duplicated or pasted
        // block the original's, so two posts can arrive claiming one — and
        // while their rows are identical that is harmless, right up until one
        // of them is edited. Keyed on the id alone, that edit rewrites the
        // entry both are pointing at and the other post's pages quietly start
        // drawing someone else's layout.
        //
        // With what it draws in the key, two rows share an entry only while
        // they are the same row, which is safe because such an entry can never
        // change meaning. An edit writes a new one and leaves the old where it
        // was, for whoever is still pointing at it, until it ages out.
        $token = $clientId . '-' . substr(hash('sha256', $markup), 0, 12);

        // A preview renders the post on the front end under the same id, so an
        // unsaved draft would otherwise replace the row a published page is
        // still pointing at — and every visitor sorting or paging that page
        // would be served the unpublished one. The same goes for a post that
        // is not published at all.
        //
        // The token still goes out, and it is the token for what is being
        // previewed: unchanged, it resolves to the stored row and the preview
        // pages like the published page does. Edited, nothing answers to it
        // and the preview falls back to the fixed row — which is the right
        // answer, because the alternative is writing the draft where visitors
        // would read it.
        if (static::isPreviewRender($owner)) {
            return $token;
        }

        $entry = static::composition($token);

        // A storefront page view is a read; rewriting an unchanged row on
        // every one of them would be a write per visitor.
        if ($entry && !static::isStale($entry)) {
            return $token;
        }

        $isNew = !$entry;

        // Its own row: two requests composing different rows write different
        // options and cannot overwrite one another, which a single shared map
        // could not promise — the loser's page would print a token nothing
        // could resolve.
        // The owner and the id travel with the row so the sweep can ask the
        // post whether it still draws this: an entry the content still points
        // at is in use however long ago it was written.
        update_option(static::compositionKey($token), [
            'set_at'    => gmdate('Y-m-d H:i:s'),
            'markup'    => $markup,
            'owner'     => $owner,
            'client_id' => $clientId,
        ], false);

        // Only a row the store has not seen can grow it, so that is the only
        // place that has to clear the old ones out.
        if ($isNew) {
            static::pruneCompositions();
        }

        return $token;
    }

    /**
     * Whether this render is one that must not write.
     *
     * @param int $owner
     * @return bool
     */
    protected static function isPreviewRender($owner): bool
    {
        if (is_preview() || is_customize_preview()) {
            return true;
        }

        if (!$owner) {
            // No post of its own — a template part, a widget area. There is
            // nothing to be a draft of.
            return false;
        }

        $status = get_post_status($owner);

        return $status && $status !== 'publish';
    }

    /**
     * A read means the row is still in use, even when a filter finds no
     * reviews and the block never renders. Renewed at most once a day, so
     * those reads keep it alive without writing on every request.
     *
     * Only this row's own option is touched, so a renewal cannot undo another
     * request's write.
     *
     * @param string $clientId
     * @param array $stored
     * @return void
     */
    public static function renewComposition($clientId, array $stored): void
    {
        if (!static::isStale($stored)) {
            return;
        }

        $stored['set_at'] = gmdate('Y-m-d H:i:s');

        update_option(static::compositionKey($clientId), $stored, false);
    }

    /**
     * The row stored under an id, or an empty array.
     *
     * The id arrives from the query string, so the shape check is the only
     * thing standing between it and an option name.
     *
     * @param string $clientId
     * @return array
     */
    public static function composition($clientId): array
    {
        if (!preg_match('#^[A-Za-z0-9_-]{1,80}$#', (string) $clientId)) {
            return [];
        }

        $stored = get_option(static::compositionKey($clientId));

        return is_array($stored) ? $stored : [];
    }

    /**
     * Where one composed row is kept.
     *
     * A non-autoloaded option, not a transient. The row is written when the
     * block renders and read when the endpoint answers, and on a page-cached
     * site the first of those stops happening. A transient on a site with a
     * persistent object cache lives only in that cache, where it can be
     * evicted or flushed — and the cached page goes on printing an id that no
     * longer resolves, so every sort, filter and page change quietly falls
     * back to the fixed row.
     *
     * @param string $clientId
     * @return string
     */
    public static function compositionKey($clientId): string
    {
        return static::COMPOSITION_PREFIX . $clientId;
    }

    /**
     * Whether a row has gone long enough without being written to be worth
     * writing again. A day, so a busy list is not a write per request and a
     * quiet one is still refreshed well inside the retention window.
     *
     * @param array $entry
     * @return bool
     */
    protected static function isStale(array $entry): bool
    {
        $setAt = strtotime((string) Arr::get($entry, 'set_at', ''));

        return !$setAt || (time() - $setAt) > DAY_IN_SECONDS;
    }

    /**
     * Clear out the rows nothing draws any more.
     *
     * A deleted list leaves its row behind, and those would otherwise stay for
     * as long as the site does. Run only when a row the store has not seen is
     * written, which is the only thing that can grow it.
     *
     * Two things have to be true before a row goes. It has to have gone the
     * retention window without being written or read — a rendered row rewrites
     * its date and the endpoint renews one it answers with, so an old date
     * means nothing has drawn or asked for this in a month. And the post it
     * was composed in has to have stopped pointing at it.
     *
     * The second is what makes the first safe. A page cache can keep a page
     * off the server for longer than the window, and that page goes on
     * printing its token: the row is old and still very much in use. Asking
     * the content settles it, because the content is what the token is a
     * shorthand for.
     *
     * There is no cap on how many rows may be kept. A count cannot tell a live
     * row from a dead one, so trimming to one deletes the other.
     *
     * @return void
     */
    protected static function pruneCompositions(): void
    {
        global $wpdb;

        $like = $wpdb->esc_like(static::COMPOSITION_PREFIX) . '%';

        // Ordered, and picked up where the last sweep stopped. Without both,
        // every sweep reads the same rows back and a store larger than one
        // batch keeps the orphans past that point forever.
        $cursor = (int) get_option(static::SWEEP_CURSOR_KEY, 0);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_id, option_name, option_value FROM {$wpdb->options}
             WHERE option_name LIKE %s AND option_id > %d
             ORDER BY option_id ASC LIMIT %d",
            $like,
            $cursor,
            static::COMPOSITION_SWEEP_BATCH
        ));

        if (!is_array($rows)) {
            return;
        }

        $cutoff = time() - (static::COMPOSITION_RETENTION_DAYS * DAY_IN_SECONDS);
        $checks = 0;
        $completed = true;

        foreach ($rows as $row) {
            $entry = (array) maybe_unserialize($row->option_value);
            $setAt = strtotime((string) Arr::get($entry, 'set_at', ''));

            if (!$setAt || $setAt < $cutoff) {
                if ($checks >= static::COMPOSITION_SWEEP_CHECKS) {
                    $completed = false;
                    break;
                }

                $checks++;

                if (!static::isStillDrawn($entry)) {
                    delete_option($row->option_name);
                }
            }

            // Advance only after examining this entry, including retained
            // entries. The next sweep must reach the first unchecked row.
            $cursor = (int) $row->option_id;
        }

        update_option(
            static::SWEEP_CURSOR_KEY,
            $completed && count($rows) < static::COMPOSITION_SWEEP_BATCH ? 0 : $cursor,
            false
        );
    }

    /**
     * Check the exact saved row, including its fields and styles. The stable
     * client id alone also matches every obsolete version of that row.
     * Revisions and discarded posts are not active composition sources.
     */
    protected static function isStillDrawn(array $entry): bool
    {
        global $wpdb;

        $owner = (int) Arr::get($entry, 'owner', 0);
        $clientId = (string) Arr::get($entry, 'client_id', '');

        if (!$clientId) {
            return false;
        }

        if ($owner) {
            $post = get_post($owner);

            if ($post && $post->post_type !== 'revision'
                && !in_array($post->post_status, ['trash', 'auto-draft'], true)
                && static::containsComposition(parse_blocks($post->post_content), $entry)) {
                return true;
            }
        }

        // The queried product may not own the row: templates, template parts,
        // synced patterns and copies on other pages can still use it. Read
        // candidates in bounded batches and compare their parsed blocks so
        // equivalent block-comment formatting does not invalidate a layout.
        $cursor = 0;
        $batchSize = 50;

        do {
            $posts = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_content FROM {$wpdb->posts}
                 WHERE ID > %d AND ID != %d AND post_type != 'revision'
                   AND post_status NOT IN ('trash', 'auto-draft') AND post_content LIKE %s
                 ORDER BY ID ASC LIMIT %d",
                $cursor,
                $owner,
                '%' . $wpdb->esc_like($clientId) . '%',
                $batchSize
            ));

            // A failed lookup cannot establish that the composition is unused.
            if (!is_array($posts) || $wpdb->last_error) {
                return true;
            }

            foreach ($posts as $post) {
                if (static::containsComposition(parse_blocks($post->post_content), $entry)) {
                    return true;
                }
                $cursor = (int) $post->ID;
            }
        } while (count($posts) === $batchSize);

        return false;
    }

    /**
     * Match a row wherever it is nested, without rendering any block callbacks.
     * Older entries without markup retain the conservative client-id lookup.
     */
    protected static function containsComposition(array $blocks, array $entry): bool
    {
        $clientId = (string) Arr::get($entry, 'client_id', '');
        $markup = (string) Arr::get($entry, 'markup', '');

        foreach ($blocks as $block) {
            if (Arr::get($block, 'blockName') === 'fluent-cart/review-item'
                && Arr::get($block, 'attrs.wp_client_id') === $clientId
                && ($markup === '' || serialize_block($block) === $markup)) {
                return true;
            }

            if (static::containsComposition(Arr::get($block, 'innerBlocks', []), $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Say that a block drew one of the list's parts.
     *
     * The list has a header, a set of rows and a pager. An editor can place a
     * block for each, or leave one out, and the renderer has to fill in what
     * they left out without drawing a second one over what they kept.
     *
     * So each block says what it drew, and the renderer reads that afterwards.
     * The alternative is for the list to sort its children into those three
     * parts before rendering any of them, which means knowing what every block
     * is for -- and getting it wrong for a block nested one level deeper than
     * expected.
     *
     * @param string $part 'header', 'list' or 'pagination'
     */
    public static function markDrawn(string $part): void
    {
        if (!static::$drawn) {
            return;
        }

        static::$drawn[count(static::$drawn) - 1][$part] = true;
    }

    /**
     * Start collecting what a list's blocks draw.
     *
     * A frame per pass rather than one set of answers: a list nested inside
     * another renders while the outer one is still waiting for its own, and
     * must neither read nor overwrite them.
     */
    public static function beginDrawnPass(): void
    {
        static::$drawn[] = [];
    }

    /**
     * Close the pass and leave its answers where the renderer can read them.
     *
     * Closed by whoever opened it rather than by whoever reads it: the rows
     * are also rendered for the page-change endpoint, which returns them
     * straight to the browser and never asks what was drawn — a frame left for
     * that caller to close would simply stay open.
     */
    public static function endDrawnPass(): void
    {
        $finished = array_pop(static::$drawn);

        static::$lastDrawn = is_array($finished) ? $finished : [];
    }

    /**
     * Whether a block has drawn that part in the pass now running.
     *
     * wasDrawn() answers about the pass that finished, which is what the
     * renderer asks afterwards. This is the same question asked from inside,
     * where the answer decides whether a block is the first of its kind.
     *
     * @param string $part
     * @return bool
     */
    protected static function drewThisPass(string $part): bool
    {
        if (!static::$drawn) {
            return false;
        }

        $current = static::$drawn[count(static::$drawn) - 1];

        return !empty($current[$part]);
    }

    /**
     * Whether a block drew that part while the list rendered its children.
     *
     * @param string $part
     * @return bool
     */
    public static function wasDrawn(string $part): bool
    {
        return !empty(static::$lastDrawn[$part]);
    }

    /**
     * The card one review is drawn in.
     *
     * The same attributes ReviewListRenderer puts on a row. The vote counts are
     * not decoration: PRO reads them straight off this element, so a row
     * without them shows no votes at all, or shows them stuck at zero on a
     * review that has some. data-composed-row says the fields inside are the
     * merchant's choice rather than a fixed set, which is how PRO tells an
     * emptied row from the one the renderer draws.
     *
     * @param array $review
     * @param string $fields
     * @param string $cardAttributes the block's own classes and styles
     * @return string
     */
    protected static function reviewItemWrapper(array $review, string $fields, string $cardAttributes): string
    {
        return '<div ' . $cardAttributes . ' data-composed-row'
            . ' data-review-id="' . esc_attr((string) Arr::get($review, 'id', '')) . '"'
            . ' data-user-vote="' . (int) Arr::get($review, 'user_vote', 0) . '"'
            . ' data-helpful-count="' . (int) Arr::get($review, 'helpful_count', 0) . '"'
            . ' data-not-helpful-count="' . (int) Arr::get($review, 'not_helpful_count', 0) . '"'
            . '>'
            . $fields
            . '</div>';
    }

    /**
     * The Helpful votes.
     *
     * No buttons are drawn here: they are PRO's, built client-side by
     * review-storefront.js, and rendering them server-side would produce
     * buttons that cannot be clicked. What is drawn is the place they go.
     *
     * PRO used to find .fct-review-footer and append to that, which meant this
     * block decided nothing: the votes appeared in the footer whether or not
     * the merchant kept the block, and stayed there when they moved it. The
     * slot below is the contract instead — PRO fills it where it is, and a
     * composed row without one is a row whose merchant removed the votes.
     *
     * Whether they appear at all is still the store's Helpful Votes setting
     * and whether PRO is active.
     */
    public function renderVotes($attributes, $content, $block): string
    {
        $review = $this->currentReview($block);

        if (!$review) {
            return '';
        }

        if (Arr::get(ProductReviewService::getReviewSettings(), 'enable_helpful_votes') !== 'yes') {
            return '';
        }

        // The spot, named. PRO puts its buttons inside this element rather
        // than wherever .fct-review-footer happens to be, so moving the block
        // moves the votes and removing it leaves PRO nothing to fill -- which
        // is what makes this a field a merchant can actually compose with.
        return sprintf(
            '<div %s data-review-votes-slot></div>',
            get_block_wrapper_attributes(['class' => 'fct-review-votes-slot'])
        );
    }

    /**
     * The editor script. The trait enqueues it and attaches localizeData().
     */
    public function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/ReactSupport.js',
                'dependencies' => ['wp-blocks', 'wp-components'],
            ],
            [
                'source'       => 'admin/BlockEditor/ProductReviewList/InnerBlocks/InnerBlocks.jsx',
                'dependencies' => ['wp-blocks', 'wp-components', 'wp-data', 'wp-block-editor', 'wp-element'],
            ],
        ];
    }

    public function getStyles(): array
    {
        return [
            [
                'source' => 'admin/BlockEditor/ProductReviewList/style/product-review-list-block-editor.css',
            ],
        ];
    }

    protected function generateEnqueueSlug(): string
    {
        return 'fluent_cart_review_inner_blocks';
    }

    protected function getLocalizationKey(): string
    {
        return 'fluent_cart_review_inner_blocks';
    }

    /**
     * The block list the editor registers from.
     *
     * Only what the editor needs: the render callbacks are the server's
     * business and never leave it — a Closure would not survive the trip
     * anyway. Described once here and on the server from the same array, so
     * the two halves cannot disagree about a slug or where a block may sit.
     */
    public function localizeData(): array
    {
        $blocks = array_map(function ($block) {
            return [
                'slug'      => $block['slug'],
                'title'     => $block['title'],
                'icon'      => Arr::get($block, 'icon'),
                'component' => Arr::get($block, 'component'),
                'ancestor'  => Arr::get($block, 'ancestor', ['fluent-cart/review-item']),
            ];
        }, $this->getInnerBlocks());

        return [
            $this->getLocalizationKey() => [
                'blocks'      => $blocks,
                // The Review Sorting block's dropdown, from the same
                // filterable list the rendered select is built from.
                'sortOptions' => static::sortOptions(),
                // What the store falls back to, so a Reviews Per Page control
                // can show the number that will actually be used rather than
                // the 0 that stands for "ask the store".
                'storePerPage'  => (int) Arr::get(
                    ProductReviewService::getReviewSettings(),
                    'reviews_per_page',
                    10
                ),
            ],
        ];
    }
}
