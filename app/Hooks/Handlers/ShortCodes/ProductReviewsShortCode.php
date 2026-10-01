<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Http\Controllers\FrontendControllers\ProductReviewFrontendController;
use FluentCart\App\Models\Product;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\App\Services\Renderer\ProductReviewRenderer;
use FluentCart\App\Services\Renderer\ReviewThreadMarkup;
use FluentCart\App\Services\Renderer\ReviewListRenderer;
use FluentCart\Framework\Support\Arr;

/**
 * [fluent_cart_product_reviews] — the reviews section, outside the editor.
 *
 * Everything the Review List block can be set to, as attributes: the view, the
 * columns, the page length and pager, the chips, the sort, the photos. It draws
 * through the same ProductReviewRenderer the block and the single-product
 * template use, so a page built with this and a page built with blocks are the
 * same section — the shortcode only decides what to ask it for.
 *
 * On a product page it needs no attributes at all. Anywhere else it wants an
 * id, because there is no current product to read.
 */
class ProductReviewsShortCode extends ShortCode
{
    protected static string $shortCodeName = 'fluent_cart_product_reviews';

    /**
     * Columns, and what the storefront will actually honour.
     *
     * One column is the list view by another name, and past four a review is
     * too narrow to read — the block caps itself at the same numbers, so a
     * shortcode cannot ask for an arrangement the page has no styles for.
     */
    const MIN_COLUMNS = 2;
    const MAX_COLUMNS = 4;

    public function render(?array $viewData = null)
    {
        $data = $viewData ?? $this->shortCodeAttributes ?? [];

        $product = $this->resolveProduct($data);

        if (!$product) {
            return;
        }

        // The storefront's review script and styles. The block calls this for
        // the same reason: a page that is not the single-product template has
        // loaded none of them, and without it the section renders as unstyled
        // markup that does not sort, filter or page.
        AssetLoader::loadSingleProductAssets();

        (new ProductReviewRenderer($product->ID, $this->renderOptions($data)))->render();
    }

    /**
     * Which product's reviews.
     *
     * An explicit id wins; otherwise the current product, which is what makes
     * the shortcode work with no attributes inside a product template. The id
     * is checked against published products only — a hand-typed one must not
     * be able to surface a draft product's reviews on a public page, which is
     * the same rule the blocks resolve by.
     *
     * @param array $data
     * @return Product|null
     */
    protected function resolveProduct(array $data)
    {
        $productId = absint(Arr::get($data, 'id', 0));

        if (!$productId) {
            return fluent_cart_get_current_product();
        }

        return Product::query()
            ->where('post_status', 'publish')
            ->find($productId);
    }

    /**
     * The attributes, as the renderer's own options.
     *
     * Every value is checked here rather than trusted: a shortcode is typed by
     * hand into a post, so "grid" and "Grid" and "griid" all arrive equally,
     * and an unknown one has to fall back to something the page can draw
     * rather than reaching the renderer as-is.
     *
     * @param array $data
     * @return array
     */
    protected function renderOptions(array $data): array
    {
        $viewMode = ProductReviewService::resolveViewMode(Arr::get($data, 'view_mode', 'list'));

        $pagination = strtolower(trim((string) Arr::get($data, 'pagination', 'numbers')));
        $pagination = in_array($pagination, ReviewListRenderer::paginationTypes(), true)
            ? $pagination
            : 'numbers';

        $options = [
            'viewMode'          => $viewMode,
            'gridColumns'       => $this->columns($data),
            'perPage'           => $this->perPage($data),
            'maxWords'          => $this->maxWords($data),
            // media_visible="3" media_width="96" media_height="96" — how many
            // photo/video tiles a review shows before the + tile, and how big
            // each one is. Bounded in the renderer, which is where the block
            // attributes carrying the same three are bounded.
            'mediaVisible'      => ProductReviewRenderer::mediaVisibleCount(Arr::get($data, 'media_visible', 0)),
            'mediaWidth'        => ProductReviewRenderer::mediaTileSize(Arr::get($data, 'media_width', 0)),
            'mediaHeight'       => ProductReviewRenderer::mediaTileSize(Arr::get($data, 'media_height', 0)),
            'mediaFullWidth'    => Helper::toBool(Arr::get($data, 'media_full_width', false), false),
            'mediaMore'         => ReviewThreadMarkup::moreTilePlacement(Arr::get($data, 'media_more', 'overlay')),
            'paginationType'    => $pagination,
            'defaultSortBy'     => $this->sortBy($data),
            'defaultSortOrder'  => $this->sortOrder($data),
            // yes/no attributes, through the helper the other shortcodes use so
            // "yes", "true", "1" and "on" all read the same way. The second
            // argument is the fallback for anything else typed: a misspelt
            // filter="ye" leaves the chips where the default put them rather
            // than silently removing them.
            'showFilterChips'   => Helper::toBool(Arr::get($data, 'filter', true), true),
            'showSortControls'  => Helper::toBool(Arr::get($data, 'sorting', true), true),
            'showSummary'       => Helper::toBool(Arr::get($data, 'summary', true), true),
            'showReviewDate'    => Helper::toBool(Arr::get($data, 'date', true), true),
            'showReviewerName'  => Helper::toBool(Arr::get($data, 'reviewer_name', true), true),
            'showViewReply'     => Helper::toBool(Arr::get($data, 'replies', true), true),
            // photos="only" narrows the query to reviews that carry them.
            // Anything else leaves the list alone — including photos="yes",
            // which reads as "photos are welcome here" rather than "only
            // these", and is what a list shows anyway.
            'hasMedia'          => strtolower(trim((string) Arr::get($data, 'photos', ''))) === 'only',
            // No min_rating here. The block has one and the refresh endpoint
            // resolves it from that block's composition token rather than
            // from the query string, because a floor is the shop's setting
            // and not the reader's - sent by the client, anyone could ask for
            // the one-star reviews a shop had chosen not to publish. A
            // shortcode has no such token, so it could only filter the first
            // paint: the first sort, filter or page change would bring the
            // excluded reviews back, and the count and the pager with them.
            // Better absent than a filter that stops filtering.
            //
            // summary="side|top|cta" — where the rating summary goes, which is
            // a separate question from whether it appears at all. Anything else
            // typed reads as the default rather than removing it.
            'summaryMode'       => $this->summaryMode($data),
            'showCount'         => Helper::toBool(Arr::get($data, 'count', true), true),
            // The parts of a card. The block turns these off by removing the
            // field block; a shortcode has no blocks to remove, so it says so
            // by name. Named for what a reader sees rather than for the option
            // behind it - avatar="no", not show_avatar="no".
            'showAvatar'        => Helper::toBool(Arr::get($data, 'avatar', true), true),
            'showTitle'         => Helper::toBool(Arr::get($data, 'title', true), true),
            'showContent'       => Helper::toBool(Arr::get($data, 'text', true), true),
            'showPhotos'        => Helper::toBool(Arr::get($data, 'show_photos', true), true),
            'showFooter'        => Helper::toBool(Arr::get($data, 'footer', true), true),
            'showVariation'     => Helper::toBool(Arr::get($data, 'variation', true), true),
            'showMeta'          => Helper::toBool(Arr::get($data, 'meta', true), true),
            // The order within a card, each off unless asked for, which is the
            // arrangement every layout but the photo ones uses.
            'photosFirst'       => Helper::toBool(Arr::get($data, 'photos_first', false), false),
            'ratingFirst'       => Helper::toBool(Arr::get($data, 'rating_first', false), false),
            'badgeLast'         => Helper::toBool(Arr::get($data, 'badge_last', false), false),
            // Pro draws these two, and refuses them without it in
            // ReviewThreadMarkup - the same gate the block and the Elementor
            // widgets pass through, so asking here cannot get round it. They
            // travel as intent: a page written before Pro arrives draws as
            // written the day it does.
            'mediaBackdrop'     => Helper::toBool(Arr::get($data, 'media_backdrop', false), false),
            'mediaFlush'        => Helper::toBool(Arr::get($data, 'media_flush', false), false),
        ];

        // When omitted, let the renderer inherit the store's badge setting.
        if (isset($data['verified_badge'])) {
            $settings = ProductReviewService::getReviewSettings();
            $options['showVerifiedBadge'] = Helper::toBool(
                $data['verified_badge'],
                $settings['show_verified_badge'] === 'yes'
            );
        }

        $starColor = sanitize_hex_color((string) Arr::get($data, 'star_color', ''));

        if ($starColor) {
            $options['starColor'] = $starColor;
        }

        if ($viewMode === 'slider') {
            $options['sliderSettings'] = $this->sliderSettings($data);
        }

        return $options;
    }

    /**
     * How many across, for the views that lay out in columns.
     *
     * List view ignores it, so an out-of-range number there costs nothing; in
     * grid and slider it is clamped rather than refused, because a shop asking
     * for six columns wants as many as it can have, not the default two.
     */
    protected function columns(array $data): int
    {
        $columns = (int) Arr::get($data, 'columns', self::MIN_COLUMNS);

        return min(self::MAX_COLUMNS, max(self::MIN_COLUMNS, $columns));
    }

    /**
     * Reviews per page. 0 — unset, zero, negative or unparseable — is the
     * renderer's "use the store's Reviews per page".
     */
    protected function perPage(array $data): int
    {
        $perPage = (int) Arr::get($data, 'per_page', 0);

        if ($perPage < 1) {
            return 0;
        }

        // Capped at the endpoint's own ceiling rather than a number of our
        // own: every page after the first is fetched through it, so a larger
        // first page would silently shrink on the first click.
        return min(ProductReviewFrontendController::MAX_REVIEWS_PER_PAGE, $perPage);
    }

    /**
     * Words a review shows before a Read more, 0 for the whole review.
     *
     * Bounded the way the endpoint bounds it rather than by a number of our
     * own: every page after the first is drawn there, so a first page trimmed
     * to a limit it would not accept would untrim itself on the first click.
     */
    /**
     * summary="side|top|cta". Anything else typed reads as 'side', the
     * arrangement it has always had - a misspelt summary="tpo" should not
     * rearrange the section.
     */
    protected function summaryMode(array $data): string
    {
        $mode = strtolower(trim((string) Arr::get($data, 'summary_position', 'side')));

        return in_array($mode, ['side', 'top', 'cta'], true) ? $mode : 'side';
    }

    protected function maxWords(array $data): int
    {
        $maxWords = (int) Arr::get($data, 'max_words', 0);

        if ($maxWords < 1) {
            return 0;
        }

        return min(ProductReviewFrontendController::MAX_REVIEW_WORDS, $maxWords);
    }

    /**
     * Which column the list opens on.
     *
     * Only the two the sort control offers, because sort_by and sort_order
     * together are also what that control opens showing — a pair the select
     * has no option for would leave it displaying its first entry while the
     * list was ordered by something else.
     */
    protected function sortBy(array $data): string
    {
        $sortBy = strtolower(trim((string) Arr::get($data, 'sort_by', 'created_at')));

        return in_array($sortBy, ['created_at', 'rating'], true) ? $sortBy : 'created_at';
    }

    protected function sortOrder(array $data): string
    {
        $order = strtoupper(trim((string) Arr::get($data, 'sort_order', 'DESC')));

        return $order === 'ASC' ? 'ASC' : 'DESC';
    }

    /**
     * The slider's behaviour, in the shape normalizeSliderSettings() expects.
     *
     * Passed through rather than validated here: that method is the one the
     * block's settings go through too, so both arrive at the storefront having
     * been checked by the same code.
     */
    protected function sliderSettings(array $data): array
    {
        return [
            'autoplay'      => strtolower(trim((string) Arr::get($data, 'autoplay', 'no'))),
            'autoplayDelay' => (int) Arr::get($data, 'autoplay_delay', 3000),
            'arrows'        => Helper::toBool(Arr::get($data, 'arrows', true), true) ? 'yes' : 'no',
            'arrowsSize'    => strtolower(trim((string) Arr::get($data, 'arrow_size', 'md'))),
            'arrowsPosition'=> strtolower(trim((string) Arr::get($data, 'arrow_position', 'overlap'))),
            'infinite'      => Helper::toBool(Arr::get($data, 'infinite', false)) ? 'yes' : 'no',
            // Off unless asked for. A slider already sits above the Review
            // Pagination block, and the two page different things — these move
            // between loaded slides, that one loads more reviews. An existing
            // shortcode must not acquire a second pager by upgrading.
            'pagination'    => Helper::toBool(
                Arr::get($data, 'slider_pagination', Arr::get($data, 'show_pagination', false)),
                false
            ) ? 'yes' : 'no',
            'paginationType'=> strtolower(trim((string) Arr::get(
                $data,
                'slider_pagination_type',
                Arr::get($data, 'pagination_type', 'bullets')
            ))),
        ];
    }
}
