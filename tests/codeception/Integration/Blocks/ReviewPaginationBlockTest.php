<?php

namespace Tests\Integration\Blocks;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Hooks\Handlers\BlockEditors\ProductReviewList\InnerBlocks\InnerBlocks;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * The Review Pagination block.
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
class ReviewPaginationBlockTest extends WPTestCase
{
    /** @var int */
    private $productId;

    /** @var int the store's Reviews per page before a test changed it */
    private $originalPerPage;

    public function setUp(): void
    {
        parent::setUp();

        $modules = ModuleSettings::getAllSettings();
        $modules['reviews']['active'] = 'yes';
        $modules['reviews']['reviews_enabled'] = 'yes';
        ModuleSettings::saveSettings($modules);
        ModuleSettings::getAllSettings(false);
        ProductReviewService::resetRuntimeCache();

        $this->originalPerPage = (int) ProductReviewService::getReviewSettings()['reviews_per_page'];

        $product = (new ProductFactory())->create(['post_title' => 'Composed Widget']);
        $this->productId = (int) $product->ID;

        foreach (['First opinion.', 'Second opinion.', 'Third opinion.'] as $text) {
            $review = new ProductReview([
                'post_id'       => $this->productId,
                'reviewer_name' => 'Dana Buyer',
                'review'        => $text,
                'rating'        => 5,
            ]);
            $review->status = Status::REVIEW_APPROVED;
            $review->save();
        }
    }


    public function tearDown(): void
    {
        $this->setStorePerPage($this->originalPerPage);
        ProductReviewService::resetRuntimeCache();
        parent::tearDown();
    }

    /**
     * The review ids in the order a rendered list put them.
     *
     * @param string $html
     * @return array<int, string>
     */
    private function reviewIdsIn(string $html): array
    {
        preg_match_all('#class="fct-review-item" data-review-id="(\\d+)"#', $html, $matches);

        return $matches[1];
    }


    /**
     * A Review Pagination block carrying $attributes.
     *
     * Page length lives here now, not on the list, so a test that wants short
     * pages says so on the pager.
     *
     * @param array $attributes
     * @return string
     */
    private function pagerBlock(array $attributes = []): string
    {
        return '<!-- wp:fluent-cart/review-list-pagination '
            . ($attributes ? wp_json_encode($attributes) . ' ' : '')
            . '/-->';
    }


    /**
     * @param string $innerBlocks serialized children of the list block
     * @param array $attributes extra list-block attributes
     */
    private function renderList(string $innerBlocks, array $attributes = []): string
    {
        $attributes = array_merge([
            'query_type' => 'custom',
            'product_id' => (string) $this->productId,
        ], $attributes);

        $markup = '<!-- wp:fluent-cart/product-review-list ' . wp_json_encode($attributes) . ' -->'
            . $innerBlocks
            . '<!-- /wp:fluent-cart/product-review-list -->';

        return (string) preg_replace('#<style.*?</style>#s', '', do_blocks($markup));
    }


    /**
     * The header nested in its own group, which is how the block's template
     * inserts it.
     */
    private function headerGroup(): string
    {
        return '<!-- wp:group {"className":"fct-reviews-header-blocks"} --><div class="wp-block-group fct-reviews-header-blocks">'
            . '<!-- wp:fluent-cart/review-list-count /-->'
            . '<!-- wp:group {"className":"fct-reviews-controls"} --><div class="wp-block-group fct-reviews-controls">'
            . '<!-- wp:fluent-cart/review-list-filter /-->'
            . '<!-- wp:fluent-cart/review-list-sorting /-->'
            . '</div><!-- /wp:group -->'
            . '</div><!-- /wp:group -->';
    }





    /**
     * Set the store's Reviews per page for the duration of one test.
     *
     * @param int $perPage
     */
    private function setStorePerPage(int $perPage): void
    {
        $modules = ModuleSettings::getAllSettings();
        $modules['reviews']['reviews_per_page'] = $perPage;
        ModuleSettings::saveSettings($modules);
        ModuleSettings::getAllSettings(false);
        ProductReviewService::resetRuntimeCache();

        // Asserted, not assumed: a helper that quietly failed to change the
        // setting would leave every test below it passing for the wrong
        // reason — which is exactly how the first version of these tests
        // stayed green against the bug they were written for.
        $this->assertSame(
            $perPage,
            (int) ProductReviewService::getReviewSettings()['reviews_per_page'],
            'the store setting actually changed'
        );
    }


    /**
     * @param array $params
     * @return string the rows the endpoint returns
     */
    private function rowsFromEndpoint(array $params): string
    {
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/public/reviews/' . $this->productId);

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        $data = rest_do_request($request)->get_data();

        return (string) ($data['reviews_html'] ?? '');
    }


    /**
     * The pager is a block too, and there is exactly one of it.
     *
     * Two [data-reviews-pagination] elements would be worse than none: the
     * storefront script takes the first it finds and re-renders into that, so
     * the other would sit there showing whatever page the list opened on, for
     * good.
     */
    public function test_the_pager_block_replaces_the_lists_own(): void
    {
        $html = $this->renderList(
            '<!-- wp:fluent-cart/review-item --><!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->' . $this->pagerBlock(['perPage' => 2])
        );

        $this->assertSame(
            1,
            substr_count($html, 'data-reviews-pagination'),
            'one pager element, not the block\'s plus the renderer\'s'
        );
        $this->assertSame(
            1,
            substr_count($html, 'wp-block-fluent-cart-review-list-pagination'),
            'and it is the block that drew it'
        );
        $this->assertStringContainsString('fct-reviews-page-btn', $html, 'with the pager buttons inside it');
    }


    /**
     * The pager sits after the rows, where the script looks for it.
     */
    public function test_the_pager_lands_below_the_list(): void
    {
        $html = $this->renderList(
            '<!-- wp:fluent-cart/review-item --><!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->' . $this->pagerBlock(['perPage' => 2])
        );

        $this->assertGreaterThan(
            strpos($html, 'data-reviews-list'),
            strpos($html, 'data-reviews-pagination')
        );
    }


    /**
     * A list with no blocks at all keeps the renderer's own pager, like every
     * list saved before the blocks existed. A composed list (one with a
     * review-item block) gets no fallback: a pager the editor did not place is
     * one they did not want (ProductReviewRenderer, the pager slot).
     */
    public function test_a_list_without_a_pager_block_keeps_the_default_pager(): void
    {
        $html = $this->renderList('');

        $this->assertSame(1, substr_count($html, 'data-reviews-pagination'), 'the uncomposed list draws its own');
        $this->assertStringNotContainsString('wp-block-fluent-cart-review-list-pagination', $html);

        $composed = $this->renderList('<!-- wp:fluent-cart/review-item --><!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->');

        $this->assertStringContainsString('fct-review-item', $composed, 'the composed list rendered');
        $this->assertSame(0, substr_count($composed, 'data-reviews-pagination'), 'a composed list has no fallback pager');
        $this->assertStringNotContainsString('wp-block-fluent-cart-review-list-pagination', $composed);
    }


    /**
     * The pager element is printed even when everything fits on one page:
     * filtering to a single star can take a list from several pages to one and
     * back, and the script needs somewhere to put the pager when it returns.
     */
    public function test_the_pager_element_survives_a_single_page_list(): void
    {
        $html = $this->renderList(
            '<!-- wp:fluent-cart/review-item-content /--><!-- wp:fluent-cart/review-list-pagination /-->'
        );

        $this->assertSame(3, substr_count($html, 'data-helpful-count='), 'all three fit on one page');
        $this->assertSame(1, substr_count($html, 'data-reviews-pagination'), 'the element is still there');
        $this->assertStringNotContainsString('fct-reviews-page-btn', $html, 'with no buttons in it');
    }


    /**
     * The pager block outside the list's pagination pass still draws its
     * element, with nothing in it.
     *
     * The element is the contract, not the buttons: the storefront script
     * replaces its innerHTML on every page change, so a block that drew
     * nothing left the list with nowhere to put a pager. Empty is a state this
     * already handles — a single page renders it empty too, and a filter that
     * takes three pages down to one and back needs it waiting either way.
     */
    public function test_a_pager_block_outside_a_list_draws_an_empty_element(): void
    {
        $html = do_blocks('<!-- wp:fluent-cart/review-list-pagination /-->');

        $this->assertStringContainsString('data-reviews-pagination', $html, 'the script has somewhere to render into');
        $this->assertStringNotContainsString('fct-reviews-page-btn', $html, 'and nothing is invented to put in it');
    }


    /**
     * Each pager type draws its own pager.
     */
    public function test_the_pager_type_selects_the_pager(): void
    {
        $cases = [
            'numbers'  => ['fct-reviews-page-btn', 'fct-reviews-page-fraction'],
            'fraction' => ['fct-reviews-page-fraction', 'fct-reviews-page-bullet'],
            'bullets'  => ['fct-reviews-page-bullet', 'fct-reviews-page-fraction'],
        ];

        foreach ($cases as $type => $expectations) {
            list($present, $absent) = $expectations;

            $html = $this->renderList(
                $this->pagerBlock(['paginationType' => $type, 'perPage' => 1])
            );

            $this->assertStringContainsString($present, $html, $type . ' draws its own pager');
            $this->assertStringNotContainsString($absent, $html, $type . ' draws only its own');
        }
    }


    /**
     * The type reaches the container, which is the only way it survives a page
     * change: the script sends it back and the endpoint re-renders the pager
     * from it. Without this the first click swaps the chosen pager for the
     * default one — and the chosen one never returns.
     */
    public function test_the_pager_type_reaches_the_container(): void
    {
        $html = $this->renderList(
            $this->pagerBlock(['paginationType' => 'fraction', 'perPage' => 1])
        );

        $this->assertStringContainsString('data-pagination-type="fraction"', $html);
    }


    /**
     * A type nothing renders falls back to the numbered pager rather than to
     * no pager at all, on the container and in the markup alike.
     */
    public function test_an_unknown_pager_type_falls_back_to_numbers(): void
    {
        $html = $this->renderList(
            $this->pagerBlock(['paginationType' => 'carousel', 'perPage' => 1])
        );

        $this->assertStringContainsString('data-pagination-type="numbers"', $html);
        $this->assertStringContainsString('fct-reviews-page-btn', $html);
    }


    /**
     * Alignment is a class on the wrapper, and only ever one of the three the
     * block offers — it is rendered into a class attribute, so a hand-edited
     * value in the saved markup is how something else would reach it.
     */
    public function test_the_pager_alignment_is_a_wrapper_class(): void
    {
        $html = $this->renderList(
            $this->pagerBlock(['justify' => 'right', 'perPage' => 1])
        );

        $this->assertStringContainsString('is-justified-right', $html);

        foreach (['x;y', 'right"><script>', 'flex-end'] as $value) {
            $bogus = $this->renderList(
                $this->pagerBlock(['justify' => $value, 'perPage' => 1])
            );

            $this->assertStringNotContainsString('is-justified', $bogus, $value . ' is not an alignment');
            $this->assertStringNotContainsString('<script>', $bogus);
        }
    }


    /**
     * The chosen pager survives a page change.
     *
     * The half that is easy to lose: the first render is the block's, but
     * every click after it is the endpoint's, and the endpoint only knows
     * which pager to draw because the type rides along with the request. Drop
     * that and the chosen pager is replaced by the numbered one on the first
     * click, for the rest of the visit.
     *
     * @param string $type
     * @return string the pager markup the endpoint returns for page 2
     */
    private function pagerFromEndpoint(string $type): string
    {
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/public/reviews/' . $this->productId);
        $request->set_param('page', 2);
        $request->set_param('per_page', 1);

        if ($type !== '') {
            $request->set_param('pagination_type', $type);
        }

        $data = rest_do_request($request)->get_data();

        return (string) ($data['pagination_html'] ?? '');
    }

    public function test_the_chosen_pager_is_what_comes_back_on_a_page_change(): void
    {
        $this->assertStringContainsString(
            'fct-reviews-page-fraction',
            $this->pagerFromEndpoint('fraction'),
            'page two of a fraction pager is still a fraction pager'
        );
        $this->assertStringContainsString(
            'fct-reviews-page-bullet',
            $this->pagerFromEndpoint('bullets')
        );
    }


    /**
     * And an unknown type is not passed through to the renderer: it selects a
     * render path, so an unrecognised one has to land on the default rather
     * than on nothing.
     */
    public function test_the_endpoint_refuses_a_pager_type_it_does_not_know(): void
    {
        foreach (['carousel', '', 'numbers'] as $type) {
            $html = $this->pagerFromEndpoint($type);

            $this->assertStringContainsString('fct-reviews-page-btn', $html, $type . ' falls back to numbers');
            $this->assertStringNotContainsString('fct-reviews-page-fraction', $html);
        }
    }


    /**
     * Reviews Per Page is the pager's setting, and unset means the store's.
     *
     * It is saved on the Review Pagination block and lifted onto the list at
     * render: the list is what builds the query and stamps data-per-page on
     * the container, which is where the storefront script reads the page
     * length it pages by.
     */
    public function test_the_pager_block_supplies_the_page_length(): void
    {
        $html = $this->renderList(
            '<!-- wp:fluent-cart/review-item --><!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->'
            . '<!-- wp:fluent-cart/review-list-pagination {"perPage":1} /-->'
        );

        $this->assertStringContainsString('data-per-page="1"', $html);
        $this->assertSame(1, substr_count($html, 'data-helpful-count='));
    }


    /**
     * Unset on the pager, and no pager at all, both come to the store's
     * setting — there is nowhere else left for a page length to come from.
     */
    public function test_per_page_falls_back_to_the_store_setting(): void
    {
        $storePerPage = (int) ProductReviewService::getReviewSettings()['reviews_per_page'];

        $this->assertGreaterThan(0, $storePerPage, 'the store has a page length to fall back to');

        $cases = [
            'pager with nothing set' => '<!-- wp:fluent-cart/review-list-pagination /-->',
            'pager set back to 0'    => '<!-- wp:fluent-cart/review-list-pagination {"perPage":0} /-->',
            'no pager block'         => '',
        ];

        foreach ($cases as $label => $pager) {
            $html = $this->renderList('<!-- wp:fluent-cart/review-item --><!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->' . $pager);

            $this->assertStringContainsString('data-per-page="' . $storePerPage . '"', $html, $label);
            $this->assertSame(
                min($storePerPage, 3),
                substr_count($html, 'data-helpful-count='),
                $label . ': and the first page is that long'
            );
        }
    }


    /**
     * A page length that is not a positive number means the store's too.
     *
     * Zero or less would otherwise reach the paginator, which reads it as
     * "no limit" — the whole review table on one page.
     */
    public function test_a_nonsense_page_length_falls_back_rather_than_unbounding_the_query(): void
    {
        $storePerPage = (int) ProductReviewService::getReviewSettings()['reviews_per_page'];

        foreach ([-5, 0, 'abc'] as $value) {
            $html = $this->renderList(
                '<!-- wp:fluent-cart/review-item --><!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->'
                . '<!-- wp:fluent-cart/review-list-pagination ' . wp_json_encode(['perPage' => $value]) . ' /-->'
            );

            $this->assertStringContainsString(
                'data-per-page="' . $storePerPage . '"',
                $html,
                var_export($value, true) . ' is not a page length'
            );
        }
    }


    /**
     * Bullets give way to the numbered pager past ten pages.
     *
     * Beyond that the dots stop being a control anyone can aim at. This was
     * the one pager path no test reached — the fixture never had enough pages
     * — and the branch was calling numberedPagination() with two variables
     * that do not exist in it, which PHPStan caught and a reader would have
     * met as a TypeError.
     */
    public function test_bullets_give_way_to_numbers_past_ten_pages(): void
    {
        $renderer = new \FluentCart\App\Services\Renderer\ReviewListRenderer([
            'pagination_type' => 'bullets',
        ]);

        $short = $renderer->paginationHtml(['last_page' => 4, 'current_page' => 2]);
        $long = $renderer->paginationHtml(['last_page' => 25, 'current_page' => 12]);

        $this->assertStringContainsString('fct-reviews-page-bullet', $short, 'four pages is countable');
        $this->assertStringNotContainsString('fct-reviews-page-bullet', $long, 'twenty-five is not');
        $this->assertStringContainsString('fct-reviews-page-btn', $long, 'so the numbered pager answers');
        $this->assertStringContainsString('>12</button>', $long, 'on the page the reader is on');
    }

    /**
     * The pager a block gets before anyone chooses one.
     *
     * Pinned on the registered block type rather than on a render, because
     * what it guards is the attribute schema: a default of '' there would
     * reach the container as an empty data-pagination-type, and the script
     * would send an empty type back on the first page change.
     */
    public function test_the_default_pager_type_is_numbers(): void
    {
        $pager = \WP_Block_Type_Registry::get_instance()
            ->get_registered('fluent-cart/review-list-pagination');

        $this->assertSame('numbers', $pager->attributes['paginationType']['default']);

        $html = $this->renderList(
            '<!-- wp:fluent-cart/review-item --><!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->' . $this->pagerBlock(['perPage' => 1])
        );

        $this->assertStringContainsString('data-pagination-type="numbers"', $html);
        $this->assertStringContainsString('fct-reviews-page-btn', $html);
    }

}
