<?php

namespace Tests\Integration\Blocks;

use FluentCart\App\Hooks\Handlers\BlockEditors\ProductReviewList\InnerBlocks\InnerBlocks;
use FluentCart\App\Services\ProductReviewService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Traits\ComposesReviewLists;

/**
 * How the rows are laid out: list or grid.
 *
 * Its children are re-rendered once per review — that is what makes a row
 * composable out of blocks. The list header is not per review, so a Review
 * Count left in that loop is drawn once per row: three totals and three sort
 * dropdowns in a three-review list, each one a control the storefront script
 * would then bind to and fight over.
 *
 * The block partitions on InnerBlocks::headerBlockSlugs() and renders the
 * header once above the rows. The partition asks about a child's whole
 * subtree, not its own name: the header arrives wrapped in a core/group, which
 * knows nothing about reviews, so a check on the top-level child alone would
 * put the entire header inside every row.
 *
 * Spec: Cases/reviews.md — "Review List block"
 */
class ReviewListViewModeTest extends WPTestCase
{
    use ComposesReviewLists;

    public function setUp(): void
    {
        parent::setUp();

        $this->bootComposedReviewList();
    }

    public function tearDown(): void
    {
        $this->restoreComposedReviewList();

        parent::tearDown();
    }

    /**
     * List view is the default and adds nothing.
     */
    public function test_list_view_is_the_default_and_adds_no_grid_markup(): void
    {
        foreach ([[], ['viewMode' => 'list']] as $attributes) {
            $html = $this->renderList('<!-- wp:fluent-cart/review-item-content /-->', $attributes);

            $this->assertStringNotContainsString('fct-reviews-list--grid', $html);
            $this->assertStringNotContainsString('--fct-review-columns', $html);
        }
    }

    /**
     * A view mode nothing lays out is the list, not a class on the loose.
     */
    public function test_an_unknown_view_mode_is_the_list_view(): void
    {
        $html = $this->renderList(
            '<!-- wp:fluent-cart/review-item-content /-->',
            ['viewMode' => 'masonry"><script>']
        );

        $this->assertStringNotContainsString('fct-reviews-list--grid', $html);
        $this->assertStringNotContainsString('fct-reviews-list--slider', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    /**
     * List view is untouched by that: one column, so every page is already a
     * whole number of rows.
     */
    public function test_list_view_keeps_the_page_length_it_was_given(): void
    {
        $html = $this->renderList(
            '<!-- wp:fluent-cart/review-item-content /-->' . $this->pagerBlock(['perPage' => 10])
        );

        $this->assertStringContainsString('data-per-page="10"', $html);
    }

    /**
     * The other half of the layout contract, and the half that matters most:
     * without Pro, every arrangement collapses to the single column. Grid,
     * slider and masonry are proven to draw correctly next door, in
     * IntegrationPro/Reviews/ReviewListViewModeProTest — a suite that loads Pro,
     * because the gate is a constant check and deliberately not filterable.
     *
     * Asserted at the service, which is what every render path asks. The
     * renderer, the composed row, the list block and the shortcode each used to
     * carry their own copy of this decision; one answer is why they cannot
     * drift apart again.
     */
    public function test_the_laid_out_view_modes_are_pro_only()
    {
        $this->assertFalse(
            \FluentCart\App\App::isProActive(),
            'this suite loads core alone, which is what makes the free contract testable here'
        );

        foreach (['grid', 'slider', 'masonry'] as $mode) {
            $this->assertSame(
                'list',
                ProductReviewService::resolveViewMode($mode),
                $mode . ' view is a Pro arrangement and must come back as a single column without Pro'
            );
        }
    }

    /**
     * A hand-edited attribute or a typed shortcode reaches the same answer. The
     * gate is not the editor refusing to save the value — the value can arrive
     * however it likes.
     */
    public function test_a_hand_written_view_mode_cannot_open_the_gate()
    {
        foreach (['GRID', ' slider ', 'Masonry', 'grid ', 'SLIDER'] as $mode) {
            $this->assertSame(
                'list',
                ProductReviewService::resolveViewMode($mode),
                'a view mode written as "' . $mode . '" must not reach past the gate'
            );
        }
    }

    /**
     * Anything unrecognised is the list too, which is the behaviour that
     * predates the gate: a typo has never been a layout.
     */
    public function test_an_unrecognised_view_mode_is_the_list()
    {
        foreach (['', 'carousel', 'wall', 'nonsense', '0'] as $mode) {
            $this->assertSame(
                'list',
                ProductReviewService::resolveViewMode($mode),
                '"' . $mode . '" is not a view mode and must fall back to the list'
            );
        }
    }

    /**
     * Flush and backdrop change what a review card looks like rather than how
     * many fit a row, and belong to Pro for the same reason the arrangements do.
     */
    public function test_photograph_styling_is_pro_only()
    {
        $this->assertFalse(
            ProductReviewService::isPhotoStylingAllowed(),
            'flush and backdrop photographs are Pro and must be refused without it'
        );
    }

    /**
     * The gate where it is actually felt: a composed list asking for a grid
     * writes no layout class onto the element the storefront script binds to.
     */
    public function test_a_composed_grid_renders_as_a_list_without_pro()
    {
        $html = $this->renderList(
            '<!-- wp:fluent-cart/review-item --><!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->',
            ['viewMode' => 'grid', 'gridColumns' => 3]
        );

        $this->assertStringContainsString('fct-reviews-list', $html, 'the list still renders');
        $this->assertStringNotContainsString(
            'fct-reviews-list--grid',
            $html,
            'a grid asked for without Pro must not mark the list element'
        );
        $this->assertStringNotContainsString(
            '--fct-review-columns',
            $html,
            'and must not carry a column count for a layout it is not drawing'
        );
    }

    /**
     * A list saved as a slider on a site without Pro renders as a plain list,
     * and a plain list needs its pager: the rows past the first page are only
     * reachable through it.
     *
     * Written against a reported failure this does not reproduce: the pager
     * renders here either way, so this is a guard on the behaviour rather than
     * the regression test for a bug. Kept because the guarantee is worth
     * pinning -- whatever decides the pager must follow the same fallback the
     * list element takes, and nothing else asserts that it does.
     */
    public function test_a_lapsed_slider_keeps_its_pager(): void
    {
        $this->setStorePerPage(2);

        $html = $this->renderList(
            '<!-- wp:fluent-cart/review-item-content /-->' . $this->pagerBlock(),
            ['viewMode' => 'slider', 'gridColumns' => 3]
        );

        $this->assertStringNotContainsString(
            'fct-reviews-list--slider',
            $html,
            'the slider itself is Pro and did not render'
        );
        $this->assertStringContainsString(
            'fct-reviews-pagination',
            $html,
            'so the list it fell back to must keep its pager'
        );
        $this->assertStringContainsString(
            'fct-reviews-page-btn',
            $html,
            'with pages to move between'
        );
    }

    /**
     * The same list on a Pro site is a real slider, which carries its own
     * paging -- so the pager still stands down there. Pinned next door, in
     * IntegrationPro, where a slider can actually be drawn.
     */
    public function test_a_plain_list_keeps_its_pager(): void
    {
        $this->setStorePerPage(2);

        $html = $this->renderList(
            '<!-- wp:fluent-cart/review-item-content /-->' . $this->pagerBlock(),
            ['viewMode' => 'list']
        );

        $this->assertStringContainsString('fct-reviews-pagination', $html);
    }
}
