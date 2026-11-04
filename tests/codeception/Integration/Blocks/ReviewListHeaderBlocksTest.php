<?php

namespace Tests\Integration\Blocks;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use FluentCart\App\Hooks\Handlers\BlockEditors\ProductReviewList\InnerBlocks\InnerBlocks;
use Tests\Support\Factory\ProductFactory;

/**
 * The list header, composed out of blocks.
 *
 * The list repeats its children once per review, so a Review Count left in
 * that loop is drawn once per row — three totals and three sort dropdowns in a
 * three-review list, each one a control the storefront script would then bind
 * to and fight over. The block partitions its children instead and renders the
 * header once, above the rows.
 *
 * The partition asks about a child's whole subtree, not its own name: the
 * header arrives wrapped in a core/group, which knows nothing about reviews,
 * so a check on the top-level child alone would put the entire header inside
 * every row.
 *
 * Spec: Cases/reviews.md — "Review List block"
 */
class ReviewListHeaderBlocksTest extends WPTestCase
{
    /** @var int */
    private $productId;

    public function setUp(): void
    {
        parent::setUp();

        $modules = ModuleSettings::getAllSettings();
        $modules['reviews']['active'] = 'yes';
        $modules['reviews']['reviews_enabled'] = 'yes';
        ModuleSettings::saveSettings($modules);
        ModuleSettings::getAllSettings(false);
        ProductReviewService::resetRuntimeCache();

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
        ProductReviewService::resetRuntimeCache();
        parent::tearDown();
    }

    public function test_minimum_rating_limits_filter_chips_and_counts(): void
    {
        \FluentCart\Api\Resource\ProductReviewResource::create([
            'post_id' => $this->productId,
            'rating' => 2,
            'status' => 'approved',
            'reviewer_name' => 'Lower Rating Buyer',
            'reviewer_email' => 'lower-rating@example.test',
            'content' => 'A review below the configured minimum.',
        ]);

        foreach ([0, 4, 5] as $minimum) {
            $html = $this->renderList(
                '<!-- wp:fluent-cart/review-list-count /-->'
                . '<!-- wp:fluent-cart/review-list-filter /-->'
                . '<!-- wp:fluent-cart/review-item {"minRating":' . $minimum . '} -->'
                . '<!-- wp:fluent-cart/review-item-content /-->'
                . '<!-- /wp:fluent-cart/review-item -->'
            );
            $this->assertStringContainsString('data-min-rating="' . $minimum . '"', $html);
            $this->assertStringContainsString('data-filter-chip="all"', $html);
            foreach ([1, 2, 3, 4, 5] as $rating) {
                $this->assertSame(
                    $rating >= $minimum,
                    strpos($html, 'data-filter-chip="' . $rating . '"') !== false
                );
            }
            $this->assertStringContainsString(
                'data-reviews-total-count>' . ($minimum ? '3' : '4') . '</span>',
                $html
            );
        }
    }

    public function test_standard_renderer_limits_chips_and_preserves_photo_only_count(): void
    {
        foreach ([0, 4, 5, 6] as $minimum) {
            ob_start();
            try {
                (new \FluentCart\App\Services\Renderer\ProductReviewRenderer($this->productId, [
                    'minRating' => $minimum,
                    'hasMedia' => true,
                ]))->render();
                $html = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            foreach ([1, 2, 3, 4, 5] as $rating) {
                $this->assertSame(
                    $minimum > 5 || $rating >= $minimum,
                    strpos($html, 'data-filter-chip="' . $rating . '"') !== false
                );
            }
            $this->assertStringContainsString('data-reviews-total-count>0</span>', $html);
        }
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
     * The header blocks answer to the list, not to a row.
     *
     * The fields inside a row were moved under Review Item, since that is what
     * supplies the review they read. The count, the chips and the sort read
     * the list's own header instead, so they have to stay insertable beside
     * the row rather than inside it.
     */
    public function test_the_header_blocks_belong_to_the_list(): void
    {
        $registry = \WP_Block_Type_Registry::get_instance();

        foreach (InnerBlocks::headerBlockSlugs() as $slug) {
            $type = $registry->get_registered($slug);

            $this->assertNotNull($type, $slug . ' is registered');
            $this->assertSame(
                ['fluent-cart/product-review-list'],
                (array) $type->ancestor,
                $slug . ' belongs to the list'
            );
        }
    }

    /**
     * The composition token a rendered list advertises.
     *
     * @param string $html
     * @return string
     */
    private function compositionTokenIn(string $html): string
    {
        preg_match('#data-client-id="([^"]*)"#', $html, $matches);

        return isset($matches[1]) ? $matches[1] : '';
    }

    /**
     * The rows the public reviews endpoint returns.
     *
     * @param array $params
     * @return string
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
     * The header nested in its own group, which is how the block's template
     * inserts it.
     */
    private function headerGroup(string $sortingAttrs = ''): string
    {
        return '<!-- wp:group {"className":"fct-reviews-header-blocks"} --><div class="wp-block-group fct-reviews-header-blocks">'
            . '<!-- wp:fluent-cart/review-list-count /-->'
            . '<!-- wp:group {"className":"fct-reviews-controls"} --><div class="wp-block-group fct-reviews-controls">'
            . '<!-- wp:fluent-cart/review-list-filter /-->'
            . '<!-- wp:fluent-cart/review-list-sorting ' . $sortingAttrs . '/-->'
            . '</div><!-- /wp:group -->'
            . '</div><!-- /wp:group -->';
    }

    /**
     * @return array<int, string> the review ids in the order they rendered
     */
    private function reviewIdsIn(string $html): array
    {
        // Keyed on the vote counts, which only the row card carries, and not
        // anchored to whatever attribute or class happens to sit next to them:
        // pinning this to markup shape turns any change to the card into a
        // failure about sorting, which it is not.
        preg_match_all('#data-review-id="(\\d+)"[^>]*data-helpful-count=#', $html, $matches);

        return $matches[1];
    }

    /**
     * The bug the partition exists to prevent, stated as a count: three rows,
     * one of each header control.
     */
    public function test_the_header_renders_once_however_many_reviews_there_are(): void
    {
        $html = $this->renderList(
            $this->headerGroup() . '<!-- wp:fluent-cart/review-item {"wp_client_id":"row-under-test"} --><!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->',
            ['perPage' => 3]
        );

        $this->assertSame(3, substr_count($html, 'data-helpful-count='), 'the fixture has three reviews');
        $this->assertSame(1, substr_count($html, 'data-reviews-total-count'), 'one total, not one per review');
        $this->assertSame(1, substr_count($html, 'data-reviews-filter-chips'), 'one chip strip');
        $this->assertSame(1, substr_count($html, 'data-reviews-sort'), 'one sort dropdown');

        // And it is the BLOCK's header, not the renderer's fallback. Counting
        // the controls alone cannot tell the two apart: a header block that
        // ends up in the row zone finds no header context and draws nothing,
        // so the renderer falls back and the counts come out at one either
        // way. WordPress stamps wp-block-<slug> on a block it renders, which
        // is the part only the composed header has.
        $this->assertSame(1, substr_count($html, 'wp-block-fluent-cart-review-list-count'));
        $this->assertSame(1, substr_count($html, 'wp-block-fluent-cart-review-list-filter'));
        $this->assertSame(1, substr_count($html, 'wp-block-fluent-cart-review-list-sorting'));
    }

    /**
     * The header is drawn above the rows, because that is where it was placed.
     *
     * There is no header slot to land in any more. The list renders its
     * children once each, in order, so a header block placed before the Review
     * Item is drawn before it — and the renderer, seeing that a block already
     * drew the header, does not draw a second one over it.
     */
    public function test_the_header_is_drawn_above_the_rows(): void
    {
        $html = $this->renderList(
            $this->headerGroup() . '<!-- wp:fluent-cart/review-item {"wp_client_id":"row-under-test"} --><!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->',
            ['perPage' => 3]
        );

        $this->assertLessThan(
            strpos($html, 'First opinion.'),
            strpos($html, 'data-reviews-total-count'),
            'the header comes before the rows'
        );

        $this->assertSame(
            1,
            substr_count($html, 'data-reviews-total-count'),
            'and the renderer did not draw a second header over the one the blocks drew'
        );
    }

    /**
     * A composed list only draws the header blocks its author included.
     */
    public function test_a_composed_list_without_header_blocks_omits_the_header(): void
    {
        $html = $this->renderList('<!-- wp:fluent-cart/review-item {"wp_client_id":"row-under-test"} --><!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->', ['perPage' => 3]);

        $this->assertSame(0, substr_count($html, 'data-reviews-total-count'));
        $this->assertSame(0, substr_count($html, 'data-reviews-sort'));
        $this->assertStringNotContainsString(
            'wp-block-fluent-cart-review-list-count',
            $html,
            'no Review Count block was placed'
        );
    }

    /**
     * Removing the Filter and Sorting blocks is how a list turns its controls
     * off — there is no setting beside them.
     *
     * The count is left in place, so this pins that the header renders and the
     * two controls are simply absent, rather than the whole header having
     * fallen back to the renderer's own (which would bring the controls back).
     */
    public function test_the_controls_are_gone_when_their_blocks_are(): void
    {
        $html = $this->renderList(
            '<!-- wp:group {"className":"fct-reviews-header-blocks"} --><div class="wp-block-group fct-reviews-header-blocks">'
            . '<!-- wp:fluent-cart/review-list-count /-->'
            . '</div><!-- /wp:group -->'
            . '<!-- wp:fluent-cart/review-item {"wp_client_id":"row-under-test"} --><!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->',
            ['perPage' => 3]
        );

        $this->assertSame(1, substr_count($html, 'wp-block-fluent-cart-review-list-count'));
        $this->assertSame(0, substr_count($html, 'data-reviews-filter-chips'));
        $this->assertSame(0, substr_count($html, 'data-reviews-sort'));
    }

    /**
     * Default Sort is the Review Sorting block's setting, and decides three
     * things that have to agree: the order of the rendered rows, the option
     * the select opens on, and data-default-sort on the container — which is
     * what the storefront script sorts by before anyone touches the dropdown.
     */
    public function test_the_sorting_block_supplies_the_default_sort(): void
    {
        foreach (['created_at-ASC', 'rating-DESC', 'rating-ASC'] as $sort) {
            $html = $this->renderList(
                '<!-- wp:fluent-cart/review-list-sorting ' . wp_json_encode(['defaultSort' => $sort]) . ' /-->'
                . '<!-- wp:fluent-cart/review-item {"wp_client_id":"row-under-test"} --><!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->',
                ['perPage' => 3]
            );

            $this->assertStringContainsString('data-default-sort="' . $sort . '"', $html);
            $this->assertSame(
                1,
                preg_match('#<option value="' . preg_quote($sort, '#') . '"[^>]*selected#', $html),
                $sort . ': the select opens on the order the rows are in'
            );
        }
    }

    public function test_the_sorting_block_reorders_the_rows(): void
    {
        $newest = $this->reviewIdsIn($this->renderList(
            '<!-- wp:fluent-cart/review-list-sorting {"defaultSort":"created_at-DESC"} /-->'
            . '<!-- wp:fluent-cart/review-item {"wp_client_id":"row-under-test"} --><!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->',
            ['perPage' => 3]
        ));
        $oldest = $this->reviewIdsIn($this->renderList(
            '<!-- wp:fluent-cart/review-list-sorting {"defaultSort":"created_at-ASC"} /-->'
            . '<!-- wp:fluent-cart/review-item {"wp_client_id":"row-under-test"} --><!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->',
            ['perPage' => 3]
        ));

        $this->assertNotSame([], $newest);
        $this->assertSame(array_reverse($newest), $oldest, 'oldest-first is newest-first backwards');
    }

    /**
     * A sort nothing orders by falls back to Newest rather than reaching the
     * query: it is a saved attribute, so a hand-edited one is how something
     * else would get near the ORDER BY.
     */
    public function test_an_unknown_sort_falls_back_to_newest(): void
    {
        foreach (['price-DESC', '"><script>', 'created_at; DROP'] as $value) {
            $html = $this->renderList(
                '<!-- wp:fluent-cart/review-list-sorting ' . wp_json_encode(['defaultSort' => $value]) . ' /-->'
                . '<!-- wp:fluent-cart/review-item {"wp_client_id":"row-under-test"} --><!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->',
                ['perPage' => 3]
            );

            $this->assertStringContainsString('data-default-sort="created_at-DESC"', $html, $value);
            $this->assertStringNotContainsString('<script>', $html);
        }
    }

    /**
     * A header block outside the list's header pass still draws.
     *
     * It used to draw nothing: the global is set only around that pass, and a
     * block that could not read it returned ''. That is the worst thing a
     * block can do — it disappears from the page while still sitting in List
     * View, with nothing to say why.
     *
     * So it resolves the same values for itself, the way the product blocks
     * fall back to their own product_id when there is no loop to read a
     * product from. The chips are the same five stars wherever they are, the
     * dropdown opens on the default order, and the count asks the product.
     */
    public function test_a_header_block_outside_a_list_still_draws(): void
    {
        $count = do_blocks('<!-- wp:fluent-cart/review-list-count /-->');
        $filter = do_blocks('<!-- wp:fluent-cart/review-list-filter /-->');
        $sorting = do_blocks('<!-- wp:fluent-cart/review-list-sorting /-->');

        $this->assertStringContainsString('fct-reviews-section-title', $count);
        $this->assertStringContainsString('fct-reviews-filter-chips', $filter);
        $this->assertStringContainsString('fct-reviews-sort', $sorting);

        // All five stars plus All, whatever the context — they do not depend
        // on it, which is why bailing out on a missing one made no sense.
        $this->assertSame(6, substr_count($filter, 'data-filter-chip='));

        $this->assertStringContainsString(
            'value="created_at-DESC" selected',
            $sorting,
            'the dropdown opens on the default order rather than on nothing'
        );
    }

    /**
     * A container holding both kinds is split, not assigned to one zone.
     *
     * An editor can drag a Review Count into the row's group, or an avatar
     * into the header's — the inserter allows it. Sending the whole container
     * to one zone rendered the other kind with no review in its context, so
     * the block returned '' and vanished from the page while still sitting in
     * List View.
     */
    public function test_a_group_holding_both_kinds_renders_both(): void
    {
        $html = $this->renderList(
            '<!-- wp:group {"className":"mixed"} --><div class="wp-block-group mixed">'
            . '<!-- wp:fluent-cart/review-list-count /-->'
            . '<!-- wp:fluent-cart/review-item {"wp_client_id":"row-under-test"} --><!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->'
            . '</div><!-- /wp:group -->',
            ['perPage' => 3]
        );

        $this->assertSame(
            1,
            substr_count($html, 'wp-block-fluent-cart-review-list-count'),
            'the header half is drawn once'
        );
        $this->assertSame(
            3,
            substr_count($html, 'wp-block-fluent-cart-review-item-content'),
            'and the row half once per review, rather than disappearing'
        );
        $this->assertSame(3, substr_count($html, 'data-helpful-count='));
    }

    /**
     * A group holding a header block and the rows is rendered once, as it is.
     *
     * It used to be split in two and rendered into both zones, so its wrapper
     * came out once in the header and again around every review. Nothing is
     * split now: the group renders once, the count inside it draws once, and
     * the Review Item inside it repeats the rows within that single wrapper.
     */
    public function test_a_group_holding_both_is_rendered_once(): void
    {
        $html = $this->renderList(
            '<!-- wp:group {"className":"mixed"} --><div class="wp-block-group mixed">'
            . '<!-- wp:fluent-cart/review-list-count /-->'
            . '<!-- wp:fluent-cart/review-item {"wp_client_id":"row-under-test"} --><!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->'
            . '</div><!-- /wp:group -->',
            ['perPage' => 3]
        );

        $this->assertSame(1, substr_count($html, 'mixed'), 'the wrapper is drawn once, not per review');
        $this->assertSame(1, substr_count($html, 'wp-block-fluent-cart-review-list-count'), 'the count once');
        $this->assertSame(3, substr_count($html, 'wp-block-fluent-cart-review-item-content'), 'the row once per review');
        $this->assertSame(3, substr_count($html, 'data-helpful-count='));
    }

    /**
     * A container with nothing of a zone in it is not rendered into that zone
     * — no empty wrappers left behind by the split.
     */
    public function test_a_split_leaves_no_empty_wrappers(): void
    {
        $html = $this->renderList(
            '<!-- wp:group {"className":"header-only"} --><div class="wp-block-group header-only">'
            . '<!-- wp:fluent-cart/review-list-count /-->'
            . '</div><!-- /wp:group -->'
            . '<!-- wp:fluent-cart/review-item {"wp_client_id":"row-under-test"} --><!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->',
            ['perPage' => 3]
        );

        $this->assertSame(
            1,
            substr_count($html, 'header-only'),
            'the header group is not also drawn once per review'
        );
    }

    /**
     * Settings a list saved before the header blocks existed still reach the
     * renderer.
     *
     * WordPress strips attributes the block does not declare before the render
     * callback runs, so dropping one from the schema does not merely stop
     * offering it — it silently discards what a saved list already chose.
     */
    public function test_a_legacy_list_keeps_the_settings_it_was_saved_with(): void
    {
        $html = $this->renderList('', [
            'defaultSortBy'    => 'rating',
            'defaultSortOrder' => 'ASC',
            'perPage'          => 1,
        ]);

        $this->assertStringContainsString(
            'data-default-sort="rating-ASC"',
            $html,
            'the order it was configured in, not newest-first'
        );
        $this->assertStringContainsString('data-per-page="1"', $html);
        $this->assertSame(1, substr_count($html, 'data-helpful-count='));
    }

    /**
     * And a Review Sorting block still overrides them when one is present.
     */
    public function test_a_sorting_block_overrides_the_saved_sort(): void
    {
        $html = $this->renderList(
            '<!-- wp:fluent-cart/review-list-sorting {"defaultSort":"created_at-ASC"} /-->'
            . '<!-- wp:fluent-cart/review-item {"wp_client_id":"row-under-test"} --><!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->',
            ['defaultSortBy' => 'rating', 'defaultSortOrder' => 'DESC', 'perPage' => 3]
        );

        $this->assertStringContainsString('data-default-sort="created_at-ASC"', $html);
    }

    /**
     * The two zones are described in one place. A slug added to the header
     * family without a block behind it would partition children into a zone
     * that renders nothing.
     */
    public function test_every_header_slug_is_a_registered_block(): void
    {
        $registry = \WP_Block_Type_Registry::get_instance();

        foreach (InnerBlocks::headerBlockSlugs() as $slug) {
            $this->assertTrue($registry->is_registered($slug), $slug . ' is registered');
        }
    }

    /**
     * A page change draws rows, not a header per row.
     *
     * The first render splits the children into zones and draws the header
     * once above the rows. Every interaction after it is the endpoint's, which
     * replays the whole saved block -- so without the same split it hands each
     * review the header too: a count line, a set of filter chips and a sort
     * dropdown inside every card, each one a control the storefront script
     * then binds to and fights over. Exactly what the partition exists to
     * prevent, on the path that does not go through render().
     */
    public function test_a_page_change_draws_rows_without_the_header(): void
    {
        $html = $this->renderList(
            $this->headerGroup() . '<!-- wp:fluent-cart/review-item {"wp_client_id":"row-under-test"} --><!-- wp:fluent-cart/review-item-content /--><!-- /wp:fluent-cart/review-item -->',
            ['perPage' => 1]
        );

        $rows = $this->rowsFromEndpoint([
            'page'      => 2,
            'per_page'  => 1,
            'client_id' => $this->compositionTokenIn($html),
        ]);

        $this->assertStringContainsString(
            'wp-block-fluent-cart-review-item-content',
            $rows,
            'the composed row still comes back'
        );
        // The header's field blocks draw nothing without the header's loop
        // context, so they are not what shows. Their wrappers are: a core/group
        // renders whatever is inside it, and these carry the header's layout
        // and its spacing into every card.
        $this->assertStringNotContainsString(
            'fct-reviews-header-blocks',
            $rows,
            'the header belongs above the rows, not inside one'
        );
        $this->assertStringNotContainsString(
            'fct-reviews-controls',
            $rows,
            'nor does the row it puts the chips and the dropdown on'
        );
    }
}
