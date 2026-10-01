<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors\ProductReviewList;

use FluentCart\App\Hooks\Handlers\BlockEditors\BlockEditor;
use FluentCart\App\Hooks\Handlers\BlockEditors\ProductReviewList\InnerBlocks\InnerBlocks;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ProductReviewRenderer;
use FluentCart\App\Services\Renderer\ReviewListRenderer;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\App\App;

class ProductReviewListBlockEditor extends BlockEditor
{
    protected static string $editorName = 'product-review-list';


    public function supports(): array
    {
        return [
            'html'                 => false,
            'align'                => true,
            'typography'           => [
                'fontSize'                      => true,
                'lineHeight'                    => true,
                '__experimentalFontFamily'      => true,
                '__experimentalFontWeight'      => true,
                '__experimentalDefaultControls' => [
                    'fontSize' => true,
                ],
            ],
            'color'                => [
                'text'       => true,
                'background' => true,
            ],
            'spacing'              => [
                'margin'  => true,
                'padding' => true,
            ],
            '__experimentalBorder' => [
                'color'  => true,
                'radius' => true,
                'style'  => true,
                'width'  => true,
            ],
        ];
    }

    public function blockAttributes(): array
    {
        return [
            // Must mirror the JS registration — WordPress prepares dynamic
            // block attributes against this server-side schema, so an
            // attribute missing here never reaches the render callback.
            'query_type'             => ['type' => 'string', 'default' => 'default'],
            'product_id'             => ['type' => ['string', 'number'], 'default' => ''],
            'viewMode'               => ['type' => 'string', 'default' => 'list'],
            'gridColumns'            => ['type' => 'number', 'default' => 2],
            'hasMedia'               => ['type' => 'boolean', 'default' => false],
            // Slider behaviour, shaped like the Product Carousel's
            // carousel_settings so the two blocks read the same way.
            'sliderSettings'         => ['type' => 'object', 'default' => [
                'autoplay'      => 'no',
                'autoplayDelay' => 3000,
                'arrows'        => 'yes',
                'arrowsSize'    => 'md',
                'arrowsPosition' => 'overlap',
                'pagination'    => 'no',
                'paginationType'=> 'bullets',
                'infinite'      => 'no',
            ]],
            // Read on render, set by the blocks that own them: the sort by
            // the Review Sorting block, the page length by the Review
            // Pagination block. A list with neither falls back to these.
            'showSortControls'       => ['type' => 'boolean', 'default' => true],
            'defaultSortBy'          => ['type' => 'string', 'default' => 'created_at'],
            'defaultSortOrder'       => ['type' => 'string', 'default' => 'DESC'],
            'perPage'                => ['type' => 'number', 'default' => 0],
        ];
    }

    public function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/ProductReviewList/ProductReviewListBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components', 'wp-data', 'wp-block-editor', 'wp-element']
            ]
        ];
    }

    public function getStyles(): array
    {
        return [
            // The review block editor previews share one stylesheet — it is
            // scoped to every review block wrapper. Declaring it here keeps
            // this block styled on production even if the Product Reviews
            // container block ever stops enqueuing it.
            'admin/BlockEditor/ProductReviews/style/product-reviews-block-editor.scss'
        ];
    }

    public function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'        => $this->slugPrefix,
                'name'        => static::getEditorName(),
                'title'       => __('Review List', 'fluent-cart'),
                'description' => __('Customer reviews for a product with sorting and pagination.', 'fluent-cart'),
                // The stand-in every other block's editor draws where a real
                // image would go. Review photos only exist with PRO and are
                // never part of the saved block, so the canvas has nothing to
                // show otherwise.
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg'),
                // What a review may actually hold, so the Review Photos
                // block's limit cannot be set past it. PRO owns the setting
                // and enforces it on upload; the editor only follows it.
                'max_photos_per_review' => ProductReviewRenderer::maxPhotosPerReview(),
                // Whether Grid, Slider and Masonry may be chosen. The editor
                // only dims what it offers; ProductReviewService::resolveViewMode()
                // is what actually decides, on every render path.
                'is_pro'                => App::isProActive(),
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    /**
     * The list brings its own children.
     *
     * Registered from here rather than from actions.php, the way ShopApp does
     * it: every one of them names this block as its only parent, so a child
     * registered without it is a block that can never be inserted, and one
     * registered before it would be describing a parent that does not yet
     * exist. Hanging them off the list's own init makes that ordering
     * structural instead of a rule someone has to remember.
     */
    public function init(): void
    {
        parent::init();

        InnerBlocks::register();
    }

    /**
     * The children are drawn by render(), once per review — so WordPress must
     * not draw them first.
     *
     * Without this they are rendered before the callback runs, with no review
     * in their context to read: work thrown away, every child's
     * callback and whatever it hooks run for nothing, and a child that draws
     * nothing hits the empty-block script dequeue WP 6.x does. The same
     * override every other container here uses, including this block's own
     * parent.
     *
     * @return bool
     */
    protected function skipInnerBlocks(): bool
    {
        return true;
    }

    public function useContext()
    {
        return ['fluent-cart/review_container_query'];
    }

    /**
     * What this block publishes to everything inside it.
     *
     * in which name the data will be received => which attr, the same map
     * ShopApp hands its product loop and filters. The list is the block that
     * owns the layout, so the blocks inside it read it from here rather than
     * being handed a copy.
     *
     * @return array
     */
    public function provideContext(): array
    {
        return static::contextMap();
    }

    /**
     * The same map, reachable without an instance.
     *
     * WordPress resolves provides_context when it builds a block's inner
     * blocks — which it never does here, because this block renders its
     * children itself, at the point in the section where the renderer puts
     * them. So the map is applied by hand against the same attributes, which
     * is all WordPress would have done with it.
     *
     * @return array
     */
    protected static function contextMap(): array
    {
        return [
            'fluent-cart/review_view_mode'    => 'viewMode',
            'fluent-cart/review_grid_columns' => 'gridColumns',
            'fluent-cart/review_slider_settings' => 'sliderSettings',
        ];
    }

    /**
     * This block's attributes, under the names it publishes them by.
     *
     * @param array $attributes
     * @return array
     */
    protected static function providedContext(array $attributes): array
    {
        $provided = [];

        foreach (static::contextMap() as $contextName => $attribute) {
            if (array_key_exists($attribute, $attributes)) {
                $provided[$contextName] = $attributes[$attribute];
            }
        }

        return $provided;
    }

    public function render(array $shortCodeAttribute, $block = null)
    {
        // Nested under a review container (server-visible via the provided
        // context key) the parent owns the product — a stale custom pick
        // saved on this child must not win. Standalone, the block resolves
        // its own query.
        $insideContainer = $block instanceof \WP_Block
            && isset($block->context['fluent-cart/review_container_query']);

        $product = $insideContainer ? fluent_cart_get_current_product() : $this->resolveProduct($shortCodeAttribute);

        if (!$product) {
            return '';
        }

        AssetLoader::loadSingleProductAssets();

        $options = [
            // The list block is exactly the combined section minus the
            // summary aside and the drawer — the Rating Summary and Write a
            // Review blocks own those now.
            //
            // Nothing here for the individual fields: each is its own block,
            // and deleting it is how a field is turned off. ProductReviewRenderer
            // keeps its own defaults for the callers that still draw a whole
            // row themselves — the shortcode and the product page.
            'showSummary'       => false,
            'hasMedia'          => (bool) Arr::get($shortCodeAttribute, 'hasMedia', false),
            // How the rows are laid out. The list's own, unlike the sort and
            // the page length: it is the arrangement of the rows, not a
            // property of any one control inside it.
            'viewMode'          => ProductReviewService::resolveViewMode(Arr::get($shortCodeAttribute, 'viewMode')),
            'gridColumns'       => (int) Arr::get($shortCodeAttribute, 'gridColumns', 2),
            'sliderSettings'    => (array) Arr::get($shortCodeAttribute, 'sliderSettings', []),
            // The saved values, which a Review Sorting block overrides below
            // when one is present. Hardcoding the fallback here is what made a
            // list forget the order it was configured in.
            'defaultSortBy'     => Arr::get($shortCodeAttribute, 'defaultSortBy', 'created_at'),
            'defaultSortOrder'  => Arr::get($shortCodeAttribute, 'defaultSortOrder', 'DESC'),
            'showSortControls'  => Arr::get($shortCodeAttribute, 'showSortControls', true),
            'perPage'           => (int) Arr::get($shortCodeAttribute, 'perPage', 0),
        ];

        // The rows, when an editor has put blocks inside this one. The list
        // does not repeat anything itself: it renders each child once, in the
        // order they were placed, and the Review Item block among them is what
        // repeats — the same split ShopApp makes between its shop app and its
        // product loop. With no inner blocks the renderer draws its own row,
        // which is what every existing list and the shortcode get.
        $childBlocks = static::parsedInnerBlocks($block);

        if ($childBlocks) {
            $options['rows_renderer'] = static::composedRowsRenderer($childBlocks, $block);

            // Where the endpoint can find this same composition again. The
            // first render is the block's; every sort, filter and page after it
            // is the endpoint's, and without this it would fall back to the
            // fixed row — the saved layout lasting exactly one page load.

            // The Review Sorting block's own setting, lifted onto the list: the
            // list runs the first query and stamps data-default-sort on the
            // container, so it is the list that has to know which order to open
            // in. Found by looking through the children for it rather than by
            // sorting them into zones first — one question about one block,
            // asked of whatever the editor placed.
            $defaultSort = static::defaultSortOf($childBlocks);

            if ($defaultSort !== []) {
                $options['defaultSortBy'] = $defaultSort[0];
                $options['defaultSortOrder'] = $defaultSort[1];
            }

            // The pager block's own settings, lifted the same way the sort is:
            // the list runs the first query and stamps them on the container,
            // so the list has to know the page length and which pager to draw.
            // Asked of the children as they are, not of a zone they were
            // sorted into first.
            $pagerSettings = static::paginationSettingsOf($childBlocks);

            if ($pagerSettings['type'] !== '') {
                $options['paginationType'] = $pagerSettings['type'];
            }

            // Only a positive length is a length. Unset, zero, negative and
            // unparseable all leave perPage at 0, which the renderer reads as
            // "use the store's Reviews per page" — and so does a list with no
            // pager block, which is the right answer: nothing is offering a
            // page length, so the store's stands.
            if ($pagerSettings['per_page'] > 0) {
                $options['perPage'] = $pagerSettings['per_page'];
            }

            // The row's own floor, lifted into the query for the same reason
            // the page length is.
            $options['minRating'] = static::minRatingOf($childBlocks);
        }

        $wrapper_attributes = get_block_wrapper_attributes([
            'class' => 'fct-review-list-block',
        ]);

        ob_start();
        (new ProductReviewRenderer($product->ID, $options))->render();
        $inner = ob_get_clean();

        return static::wrapUnlessEmpty($wrapper_attributes, $inner);
    }





    /**
     * The first block at or under $parsedBlock whose name is one of $slugs,
     * or null.
     *
     * Returns the block rather than a yes/no so the one traversal answers both
     * questions asked of it: which zone a child belongs to, and what the pager
     * inside it is set to.
     *
     * @param array $parsedBlock
     * @param array<int, string> $slugs
     * @return array|null
     */
    protected static function findDescendantBlock(array $parsedBlock, array $slugs)
    {
        if (in_array(Arr::get($parsedBlock, 'blockName'), $slugs, true)) {
            return $parsedBlock;
        }

        foreach ((array) Arr::get($parsedBlock, 'innerBlocks', []) as $child) {
            if (!is_array($child)) {
                continue;
            }

            $found = static::findDescendantBlock($child, $slugs);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The order chosen on the Review Sorting block, as [column, direction],
     * or [] when there is no such block or its value is not one this sorts by.
     *
     * @param array $childBlocks parsed blocks, the list's own children
     * @return array
     */
    protected static function defaultSortOf(array $childBlocks): array
    {
        foreach ($childBlocks as $parsedBlock) {
            $sorting = static::findDescendantBlock(
                $parsedBlock,
                ['fluent-cart/review-list-sorting']
            );

            if ($sorting === null) {
                continue;
            }

            $value = (string) Arr::get($sorting, 'attrs.defaultSort', '');

            if (!isset(InnerBlocks::sortOptions()[$value])) {
                return [];
            }

            $parts = explode('-', $value);

            return [$parts[0], isset($parts[1]) ? $parts[1] : 'DESC'];
        }

        return [];
    }

    /**
     * The Review Pagination block's own settings, lifted onto the list.
     *
     * Both of them describe the pager, so both are saved on the pager — but
     * the list is what has to act on them: it builds the query with the page
     * length and prints both on the container, which is where the storefront
     * script reads them and sends them back on every page change.
     *
     * @param array $childBlocks parsed blocks, the list's own children
     * @return array{type: string, per_page: int} '' and 0 mean "not set"
     */
    /**
     * The lowest rating the list will show, set on the Review Item block.
     *
     * Lifted the way the page length is lifted off the pager: the setting
     * belongs to the row an editor is configuring, and the query belongs to
     * the list. A row cannot filter — it is handed one review at a time, and
     * refusing to draw one would leave a gap in the grid, a count that
     * disagrees with what is on screen, and pages of nothing. So the list
     * asks the row what it wants and narrows the query instead.
     *
     * @param array $childBlocks parsed blocks, the list's own children
     * @return int 1-5, or 0 for no floor
     */
    public static function minRatingOf(array $childBlocks): int
    {
        foreach ($childBlocks as $parsedBlock) {
            $row = static::findDescendantBlock($parsedBlock, ['fluent-cart/review-item']);

            if ($row === null) {
                continue;
            }

            $minRating = (int) Arr::get($row, 'attrs.minRating', 0);

            return ($minRating >= 1 && $minRating <= 5) ? $minRating : 0;
        }

        return 0;
    }

    /**
     * The same floor, for a request that has only the composition token.
     *
     * The endpoint answers sorts, filters and page changes, and each of those
     * has to narrow the query the way the first render did. The stored
     * composition is the Review Item itself, attributes and all, so the answer
     * is already there — and reading it from the store rather than the query
     * string is what keeps it the editor's setting: a floor sent by the caller
     * could be lowered by the caller.
     *
     * @param string $token the composition token the page printed
     * @return int 1-5, or 0 for no floor
     */
    public static function minRatingOfComposition($token): int
    {
        $stored = InnerBlocks::composition((string) $token);
        $markup = (string) Arr::get($stored, 'markup', '');

        if ($markup === '') {
            return 0;
        }

        return static::minRatingOf(parse_blocks($markup));
    }

    protected static function paginationSettingsOf(array $childBlocks): array
    {
        foreach ($childBlocks as $parsedBlock) {
            $pager = static::findDescendantBlock($parsedBlock, InnerBlocks::paginationBlockSlugs());

            if ($pager === null) {
                continue;
            }

            $type = (string) Arr::get($pager, 'attrs.paginationType', '');

            return [
                'type'     => in_array($type, ReviewListRenderer::paginationTypes(), true) ? $type : '',
                'per_page' => (int) Arr::get($pager, 'attrs.perPage', 0),
            ];
        }

        return ['type' => '', 'per_page' => 0];
    }

    /**
     * A block's children as parsed-block arrays.
     *
     * Arrays rather than WP_Block objects because the endpoint rebuilds this
     * same list from post_content, where parse_blocks() is all there is — one
     * shape means one renderer for both paths.
     *
     * @param mixed $block
     * @return array
     */
    protected static function parsedInnerBlocks($block): array
    {
        if (!($block instanceof \WP_Block) || empty($block->inner_blocks)) {
            return [];
        }

        $parsed = [];

        foreach ($block->inner_blocks as $innerBlock) {
            $parsed[] = $innerBlock->parsed_block;
        }

        return $parsed;
    }

    /**
     * The row builder for a Review List the page has already rendered.
     *
     * This is what the reviews endpoint calls so that a sort, a filter or a
     * page renders the same composed row the page was built with. Null when
     * the token is unknown or that list has no children — and the endpoint
     * then draws the fixed row, which is what a list that was never composed
     * should get anyway.
     *
     * @param string $token the composition token the page printed
     * @return callable|null
     */
    public static function savedRowRenderer($token)
    {
        $token = (string) $token;

        if (!preg_match('#^[A-Za-z0-9_-]{1,80}$#', $token)) {
            return null;
        }

        $stored = InnerBlocks::composition($token);
        $markup = (string) Arr::get($stored, 'markup', '');

        if ($markup === '') {
            return null;
        }

        $parsed = parse_blocks($markup);
        $row = isset($parsed[0]) ? $parsed[0] : null;

        if (!$row || Arr::get($row, 'blockName') !== 'fluent-cart/review-item') {
            return null;
        }

        InnerBlocks::renewComposition($token, $stored);

        // Rows only: the script puts what comes back inside the rows element
        // already on the page, so the block draws its rows without the element
        // around them. The stored row is a Review Item, which carries no list
        // attributes of its own.
        return static::composedRowsRenderer([$row], null, [], true);
    }

    /**
     * The rows for one page, drawn by the blocks an editor placed.
     *
     * Handed the page's reviews, it publishes them for the Review Item block
     * to loop over and renders the list's children once each. Nothing here
     * repeats: the repeating is that block's business, which is what keeps the
     * list from having to sort its children into things that repeat and things
     * that do not.
     *
     * @param array $childBlocks parsed blocks, the list's own children
     * @param mixed $block
     * @return callable
     */
    protected static function composedRowsRenderer(array $childBlocks, $block, array $listAttributes = [], bool $rowsOnly = false)
    {
        $blockContext = $block instanceof \WP_Block ? $block->context : [];
        $attributes = $block instanceof \WP_Block ? $block->attributes : $listAttributes;

        // The list's own attributes, under the names provideContext() gives
        // them — what WordPress would have handed the children itself, had it
        // been the one building them.
        $provided = static::providedContext((array) $attributes);

        return function (array $reviews, array $listContext = []) use ($childBlocks, $blockContext, $provided, $rowsOnly) {
            // The page's reviews, handed to the children as block context —
            // the way ShopApp hands its blocks what they draw. Nothing to save
            // and restore: context belongs to the blocks built with it, so a
            // list nested inside another gets its own and leaves the outer
            // one's alone.
            $context = array_merge($blockContext, $provided, [
                'fluent-cart/review_list_reviews'   => $reviews,
                'fluent-cart/review_list_header'    => (array) ($listContext['header'] ?? []),
                'fluent-cart/review_list_pagination' => (array) ($listContext['pagination'] ?? []),
                'fluent-cart/review_list_rows_only' => $rowsOnly,
            ]);

            // What the blocks draw is the one answer travelling back up, so it
            // is the one thing that cannot be context. Opened and closed here,
            // around the only blocks it can be about.
            InnerBlocks::beginDrawnPass();

            $html = '';
            foreach ($childBlocks as $parsedBlock) {
                $html .= (new \WP_Block($parsedBlock, $context))->render();
            }

            InnerBlocks::endDrawnPass();

            return $html;
        };
    }
}
