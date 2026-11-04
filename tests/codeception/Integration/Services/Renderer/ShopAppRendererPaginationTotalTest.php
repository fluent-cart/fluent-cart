<?php

namespace Tests\Integration\Services\Renderer;

use FluentCart\App\Services\Renderer\ShopAppRenderer;
use FluentCart\Framework\Pagination\CursorPaginator;
use FluentCart\Framework\Pagination\LengthAwarePaginator;
use FluentCart\Framework\Support\Collection;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Classic/fallback product taxonomy archive pagination summary bug.
 *
 * Root cause: TemplateActions::renderProductArchive() passed the raw
 * LengthAwarePaginator returned by ProductQuery::get() straight into
 * ShopAppRenderer's constructor instead of the normalized
 * ['products' => $paginator, 'total' => $paginator->total()] shape that
 * every other caller (block template's InnerBlocks::getInitialProducts(),
 * the Bricks pagination renderer) already uses.
 *
 * ShopAppRenderer's constructor reads the total via
 * Arr::get($products, 'total', 0). A LengthAwarePaginator implements
 * ArrayAccess, but its offsetGet()/offsetExists() proxy to the underlying
 * item Collection (see AbstractPaginator::offsetGet()), not to the
 * paginator's own `total` property — so Arr::get() always misses and
 * silently returns 0. That made renderPaginatorResultWrapper() compute
 * lastPage = 1 and print "Showing 1 to 0 of 0 Items" (page 1) / "Showing
 * 11 to 0 of 0 Items" (page 2), even though the product cards and
 * pagination links themselves were correct.
 *
 * Fixed by (1) normalizing the archive caller to pass the explicit
 * ['products' => ..., 'total' => ...] shape, and (2) a defensive fallback
 * in ShopAppRenderer itself: if the computed total is falsy and the
 * resolved products are a LengthAwarePaginator, fall back to
 * $products->total() directly. The CursorPaginator branch is untouched —
 * it has no total() method, so it must never be called for it.
 */
class ShopAppRendererPaginationTotalTest extends WPTestCase
{
    private function makeLengthAwarePaginator(int $total, int $perPage, int $currentPage): LengthAwarePaginator
    {
        // Only the current page's slice of items is ever materialized by a
        // real paginator; a handful of placeholder items is enough since
        // these tests only assert the "Showing X to Y of Z" summary math,
        // not the product card markup.
        $itemsOnPage = min($perPage, max(0, $total - ($currentPage - 1) * $perPage));
        $items = Collection::make(array_fill(0, $itemsOnPage, (object) ['ID' => 1]));

        return new LengthAwarePaginator($items, $total, $perPage, $currentPage);
    }

    private function renderResultWrapper(ShopAppRenderer $renderer): string
    {
        ob_start();
        $renderer->renderPaginatorResultWrapper();
        return (string) ob_get_clean();
    }

    private function renderPager(ShopAppRenderer $renderer): string
    {
        ob_start();
        $renderer->renderPerPageSelector();
        return (string) ob_get_clean();
    }

    /**
     * The "Showing X to Y of Z Items" sentence is rendered with each number
     * wrapped in its own <span data-...> element, so it is never a literal
     * substring of the markup. Strip tags to assert on the visible sentence.
     */
    private function visibleSummarySentence(string $html): string
    {
        return trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($html)));
    }

    // =========================================================================
    // Normalized ['products' => ..., 'total' => ...] contract (the fix as
    // applied to TemplateActions::renderProductArchive())
    // =========================================================================

    public function test_page_one_of_412_products_at_10_per_page_reports_correct_range_and_total(): void
    {
        $paginator = $this->makeLengthAwarePaginator(412, 10, 1);

        $renderer = new ShopAppRenderer([
            'products' => $paginator,
            'total'    => $paginator->total(),
        ], ['per_page' => 10]);

        $html = $this->renderResultWrapper($renderer);

        $this->assertStringContainsString(
            'Showing 1 to 10 of 412 Items',
            $this->visibleSummarySentence($html),
            'page 1 must report the correct from/to/total, not "1 to 0 of 0"'
        );
    }

    public function test_page_two_of_412_products_at_10_per_page_reports_correct_range_and_total(): void
    {
        $paginator = $this->makeLengthAwarePaginator(412, 10, 2);

        $renderer = new ShopAppRenderer([
            'products' => $paginator,
            'total'    => $paginator->total(),
        ], ['per_page' => 10]);

        $html = $this->renderResultWrapper($renderer);

        $this->assertStringContainsString(
            'Showing 11 to 20 of 412 Items',
            $this->visibleSummarySentence($html),
            'page 2 must report the correct from/to/total, not "11 to 0 of 0"'
        );
    }

    public function test_numeric_pagination_is_rendered_when_total_exceeds_one_page(): void
    {
        $paginator = $this->makeLengthAwarePaginator(412, 10, 1);

        $renderer = new ShopAppRenderer([
            'products' => $paginator,
            'total'    => $paginator->total(),
        ], ['per_page' => 10]);

        $html = $this->renderPager($renderer);

        $this->assertStringContainsString('fct-shop-paginator-pager', $html);
        $this->assertSame(
            42,
            substr_count($html, 'pager-number'),
            '412 items at 10 per page must render ceil(412/10) = 42 numbered pages'
        );
    }

    public function test_no_numeric_pagination_when_total_fits_in_one_page(): void
    {
        $paginator = $this->makeLengthAwarePaginator(7, 10, 1);

        $renderer = new ShopAppRenderer([
            'products' => $paginator,
            'total'    => $paginator->total(),
        ], ['per_page' => 10]);

        $html = $this->renderPager($renderer);

        $this->assertStringNotContainsString('fct-shop-paginator-pager', $html);
    }

    // =========================================================================
    // Defensive fallback: a raw LengthAwarePaginator passed directly (the
    // exact shape that caused the bug) must still resolve the correct total.
    // =========================================================================

    public function test_raw_paginator_passed_directly_still_resolves_the_correct_total(): void
    {
        $paginator = $this->makeLengthAwarePaginator(412, 10, 2);

        // This is the buggy call shape: the paginator itself, not wrapped in
        // ['products' => ..., 'total' => ...].
        $renderer = new ShopAppRenderer($paginator, ['per_page' => 10]);

        $html = $this->renderResultWrapper($renderer);

        $this->assertStringContainsString(
            'Showing 11 to 20 of 412 Items',
            $this->visibleSummarySentence($html),
            'ShopAppRenderer must defensively resolve the total from a raw LengthAwarePaginator'
        );
    }

    // =========================================================================
    // Cursor pagination must remain untouched — it has no total() method, so
    // it must never be invoked, and the summary total must stay 0.
    // =========================================================================

    public function test_cursor_paginator_total_stays_zero_and_is_never_recalculated(): void
    {
        $items = Collection::make([(object) ['ID' => 1], (object) ['ID' => 2]]);
        $cursorPaginator = new CursorPaginator($items, 10);

        $renderer = new ShopAppRenderer([
            'products' => $cursorPaginator,
            'total'    => 999, // must be ignored/zeroed for cursor pagination
        ], ['per_page' => 10]);

        $ref = new \ReflectionProperty(ShopAppRenderer::class, 'total');
        $ref->setAccessible(true);

        $this->assertSame(
            0,
            $ref->getValue($renderer),
            'cursor pagination has no reliable total; it must stay 0, not be miscalculated'
        );
    }
}
