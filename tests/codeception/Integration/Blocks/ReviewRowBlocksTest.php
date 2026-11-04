<?php

namespace Tests\Integration\Blocks;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\App\Hooks\Handlers\BlockEditors\ProductReviewList\InnerBlocks\InnerBlocks;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * The review row, composed out of blocks.
 *
 * The Review List repeats its children once per review — that is what makes a
 * row composable. A list with no children keeps drawing the row the renderer
 * has always drawn, which is what every existing list and the shortcode get.
 *
 * Spec: Cases/reviews.md — "Review List block"
 */
class ReviewRowBlocksTest extends WPTestCase
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
        unset($GLOBALS['post']);
        ProductReviewService::resetRuntimeCache();
        parent::tearDown();
    }

    /**
     * A list whose row is composed of $rowFields.
     *
     * The fields go inside a Review Item block, which is the thing that repeats
     * — the list renders each of its children once and leaves the repeating to
     * that. Passing them as the list's own children would put them outside the
     * loop, where they draw once for the whole list rather than once per
     * review.
     *
     * An empty $rowFields means a list with no children at all, which is the
     * fixed row the shortcode and the product page draw.
     *
     * @param string $rowFields serialized field blocks for one review
     * @param array $attributes extra list-block attributes
     */
    private function renderList(string $rowFields, array $attributes = []): string
    {
        $attributes = array_merge([
            'query_type' => 'custom',
            'product_id' => (string) $this->productId,
        ], $attributes);

        $clientId = isset($attributes['wp_client_id']) ? $attributes['wp_client_id'] : 'row-under-test';
        unset($attributes['wp_client_id']);

        $rowAttributes = ['wp_client_id' => $clientId];

        $children = $rowFields === ''
            ? ''
            : '<!-- wp:fluent-cart/review-item ' . wp_json_encode($rowAttributes) . ' -->'
                . $rowFields
                . '<!-- /wp:fluent-cart/review-item -->';

        $markup = '<!-- wp:fluent-cart/product-review-list ' . wp_json_encode($attributes) . ' -->'
            . $children
            . '<!-- /wp:fluent-cart/product-review-list -->';

        return (string) preg_replace('#<style.*?</style>#s', '', do_blocks($markup));
    }

    /**
     * The blocks are drawn once per review, not once for the list.
     */
    public function test_the_row_blocks_are_drawn_once_per_review(): void
    {
        $html = $this->renderList('<!-- wp:fluent-cart/review-item-content /-->', ['perPage' => 3]);

        $this->assertSame(3, substr_count($html, 'data-helpful-count='));
        $this->assertSame(3, substr_count($html, 'wp-block-fluent-cart-review-item-content'));

        foreach (['First opinion.', 'Second opinion.', 'Third opinion.'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    /**
     * Each field block reads the review the loop is on, and nothing else —
     * a field never has to be told which review it belongs to.
     */
    public function test_each_field_block_reads_the_review_being_drawn(): void
    {
        $html = $this->renderList(
            '<!-- wp:fluent-cart/review-item-avatar /-->'
            . '<!-- wp:fluent-cart/review-item-author-name /-->'
            . '<!-- wp:fluent-cart/review-item-rating /-->'
            . '<!-- wp:fluent-cart/review-item-date /-->'
            . '<!-- wp:fluent-cart/review-item-content /-->',
            ['perPage' => 3]
        );

        foreach (['avatar', 'author-name', 'rating', 'date', 'content'] as $field) {
            $this->assertSame(
                3,
                substr_count($html, 'wp-block-fluent-cart-review-item-' . $field),
                $field . ' is drawn once per review'
            );
        }
    }

    /**
     * A row block outside a list draws nothing — the review it draws is
     * context, and only the loop puts one there.
     */
    public function test_a_row_block_outside_a_list_renders_nothing(): void
    {
        $this->assertSame('', do_blocks('<!-- wp:fluent-cart/review-item-content /-->'));
        $this->assertSame('', do_blocks('<!-- wp:fluent-cart/review-item-avatar /-->'));
    }

    /**
     * A list with no children renders the row the renderer has always drawn.
     * Every list saved before this existed is that case, and so is the
     * shortcode.
     */
    public function test_a_list_with_no_children_keeps_the_default_row(): void
    {
        $html = $this->renderList('', ['perPage' => 3]);

        $this->assertSame(3, substr_count($html, 'data-helpful-count='));
        $this->assertStringNotContainsString('wp-block-fluent-cart-review-item', $html);
        $this->assertStringContainsString('fct-review-item-header', $html, 'the renderer\'s own row');
    }

    /**
     * The row carries what PRO reads off it. The vote counts are not
     * decoration: PRO injects the Helpful buttons client-side and reads the
     * counts straight off this element, so a composed row without them shows
     * no votes at all.
     */
    public function test_a_composed_row_carries_the_attributes_pro_reads(): void
    {
        $html = $this->renderList('<!-- wp:fluent-cart/review-item-content /-->', ['perPage' => 3]);

        $this->assertSame(3, substr_count($html, 'data-review-id="'));
        $this->assertSame(3, substr_count($html, 'data-helpful-count="'));
        $this->assertSame(3, substr_count($html, 'data-not-helpful-count="'));
    }

    /**
     * Star colour belongs to the block that draws the stars, and applies to
     * every review. Set as --fct-star-color, not as a text colour: the filled
     * stars read that property and the empty ones keep their own grey, which
     * colouring the element would flatten.
     */
    public function test_the_rating_block_carries_its_own_star_colour(): void
    {
        $html = $this->renderList(
            '<!-- wp:fluent-cart/review-item-rating {"starColor":"#ff0066"} /-->',
            ['perPage' => 3]
        );

        $this->assertSame(3, substr_count($html, '--fct-star-color:#ff0066'));
    }

    public function test_the_rating_block_sets_no_colour_of_its_own_by_default(): void
    {
        $html = $this->renderList('<!-- wp:fluent-cart/review-item-rating /-->', ['perPage' => 3]);

        $this->assertStringNotContainsString('--fct-star-color', $html);
        $this->assertStringContainsString('fct-review-item-stars', $html, 'the stars still render');
    }

    /**
     * A value that is not a hex colour is dropped rather than printed. The
     * colour lands in a style attribute, so a hand-edited attribute in the
     * saved markup is how something else would get in there.
     */
    public function test_a_star_colour_that_is_not_a_colour_is_dropped(): void
    {
        foreach (['javascript:alert(1)', 'red;background:url(x)', '"><script>'] as $value) {
            $html = $this->renderList(
                '<!-- wp:fluent-cart/review-item-rating ' . wp_json_encode(['starColor' => $value]) . ' /-->',
                ['perPage' => 3]
            );

            $this->assertStringNotContainsString('--fct-star-color', $html, $value . ' is not a colour');
            $this->assertStringNotContainsString('<script>', $html);
        }
    }

    /**
     * The composed row survives a sort, a filter and a page change.
     *
     * The first render is the block's; every list interaction after it is the
     * endpoint's. Without a way back to the saved composition the endpoint
     * draws the fixed row, so the editor's layout lasted exactly one page load
     * — removed fields reappeared and reordered ones reverted on the first
     * click.
     *
     * The way back is a token the page prints, derived from the composition
     * itself. Not a position in the post: a Review List can live in a template
     * part, a reusable block or a widget area, none of which are in
     * post_content, and render order need not match parse order.
     */
    public function test_the_composed_row_survives_a_list_refresh(): void
    {
        $html = $this->renderList(
            '<!-- wp:fluent-cart/review-item-avatar /--><!-- wp:fluent-cart/review-item-content /-->',
            ['perPage' => 1]
        );

        $rows = $this->rowsFromEndpoint([
            'page'      => 2,
            'per_page'  => 1,
            'client_id' => $this->compositionTokenIn($html),
        ]);

        $this->assertStringContainsString(
            'wp-block-fluent-cart-review-item-avatar',
            $rows,
            'page two is drawn with the same blocks page one was'
        );
        $this->assertStringNotContainsString(
            'fct-review-item-header"',
            $rows,
            'and not with the fixed row'
        );
    }

    /**
     * Re-composing the row changes what the page asks for.
     *
     * The token is the composition, so a row built differently is a different
     * token — the page cannot go on pointing at the row it used to have. This
     * is what an editor who changes the row and saves again depends on: the
     * page paints the new row, and the first page change, sort or filter has
     * to agree with it rather than reverting.
     */
    public function test_recomposing_the_row_changes_what_the_endpoint_serves(): void
    {
        $first = $this->renderList('<!-- wp:fluent-cart/review-item-avatar /-->', ['perPage' => 1]);
        // Recomposed: the stored row no longer matches what the block draws,
        // which is what tells the store it is out of date.
        $second = $this->renderList('<!-- wp:fluent-cart/review-item-content /-->', ['perPage' => 1]);

        $firstToken = $this->compositionTokenIn($first);
        $secondToken = $this->compositionTokenIn($second);

        $this->assertNotSame(
            $firstToken,
            $secondToken,
            'a recomposed row is a new entry, so the one it replaced is left for whoever still points at it'
        );
        $this->assertStringStartsWith('row-under-test-', $secondToken, 'still the block\'s own id');

        $rows = $this->rowsFromEndpoint(['page' => 2, 'per_page' => 1, 'client_id' => $secondToken]);

        $this->assertStringContainsString(
            'wp-block-fluent-cart-review-item-content',
            $rows,
            'the endpoint serves the row as it was last composed'
        );
        $this->assertStringNotContainsString(
            'wp-block-fluent-cart-review-item-avatar',
            $rows,
            'and not the composition it replaced'
        );
    }

    /**
     * Two lists composed differently keep their own rows.
     *
     * Two rows are two blocks with two ids, and the key carries what each one
     * draws as well — so a list built one way cannot be served a list built
     * another way, however many a page carries and whatever they share.
     */
    public function test_two_lists_composed_differently_keep_their_own_rows(): void
    {
        $first = $this->renderList('<!-- wp:fluent-cart/review-item-avatar /-->', [
            'perPage' => 1, 'wp_client_id' => 'row-one',
        ]);
        $second = $this->renderList('<!-- wp:fluent-cart/review-item-content /-->', [
            'perPage' => 1, 'wp_client_id' => 'row-two',
        ]);

        $firstRows = $this->rowsFromEndpoint([
            'page' => 2, 'per_page' => 1, 'client_id' => $this->compositionTokenIn($first),
        ]);
        $secondRows = $this->rowsFromEndpoint([
            'page' => 2, 'per_page' => 1, 'client_id' => $this->compositionTokenIn($second),
        ]);

        $this->assertStringContainsString('wp-block-fluent-cart-review-item-avatar', $firstRows);
        $this->assertStringNotContainsString('wp-block-fluent-cart-review-item-content', $firstRows);

        $this->assertStringContainsString('wp-block-fluent-cart-review-item-content', $secondRows);
        $this->assertStringNotContainsString('wp-block-fluent-cart-review-item-avatar', $secondRows);
    }

    /**
     * Two lists composed the same way share one entry, which is not a clash:
     * the row they would each be served is the row they each asked for.
     */
    public function test_two_lists_composed_alike_answer_to_one_token(): void
    {
        $row = '<!-- wp:fluent-cart/review-item-content /-->';

        $first = $this->renderList($row, ['perPage' => 1]);
        $second = $this->renderList($row, ['perPage' => 1]);

        $this->assertSame(
            $this->compositionTokenIn($first),
            $this->compositionTokenIn($second)
        );
    }

    /**
     * The page prints the id only for a list that actually composes its row.
     */
    public function test_only_a_composed_list_advertises_its_id(): void
    {
        $composed = $this->renderList('<!-- wp:fluent-cart/review-item-content /-->');
        $plain = $this->renderList('');

        $this->assertMatchesRegularExpression(
            '#data-client-id="row-under-test-[0-9a-f]{12}"#',
            $composed,
            'a composed list prints the id its row is stored under'
        );
        $this->assertStringNotContainsString('data-client-id', $plain, 'nothing to look up');
    }

    /**
     * A token nothing was saved under draws the fixed row rather than failing.
     * It arrives from the query string, so it is also the way something else
     * would try to reach the store — including with something that is not a
     * token at all, which is turned away before it can become a cache key.
     */
    public function test_an_unknown_client_id_falls_back_to_the_fixed_row(): void
    {
        $unknown = str_repeat('a', 64);

        foreach ([$unknown, 'no-such-id', '', str_repeat('z', 64)] as $value) {
            $rows = $this->rowsFromEndpoint(['per_page' => 1, 'client_id' => $value]);

            $this->assertStringNotContainsString('wp-block-fluent-cart-review-item', $rows, var_export($value, true));
            $this->assertStringContainsString('fct-review-item-header"', $rows, 'the fixed row answers');
        }

        // Markup is not a token: ReviewRequest's sanitizeText rule refuses a
        // client_id that sanitize_text_field would change, before any lookup.
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/public/reviews/' . $this->productId);
        $request->set_param('per_page', 1);
        $request->set_param('client_id', '<script>alert(1)</script>');
        $response = rest_do_request($request);

        $this->assertSame(422, $response->get_status(), 'the request fails validation, it does not crash');
        $this->assertArrayHasKey('client_id', (array) $response->get_data(), 'the client_id rule is the one that refused it');
        $this->assertArrayNotHasKey('reviews_html', (array) $response->get_data());
        $this->assertStringNotContainsString('<script>alert', wp_json_encode($response->get_data()));
    }

    /**
     * The stored row is found again after the cache is cleared.
     *
     * A transient is a cache, not storage. With a persistent object cache it
     * lives only in that cache, where it can be evicted under memory pressure
     * or flushed outright whatever its expiry says — and the row is rewritten
     * only when the block renders, which on a page-cached site stops
     * happening. Losing it means the list quietly reverts to the fixed row on
     * the first click with nothing to put it back.
     *
     * So it is an option, and not an autoloaded one: it is read by the list's
     * own render and by one endpoint, not by every request.
     *
     * The flush below is what an object cache being cleared does to anything
     * held only in memory: the row has to still be there afterwards, which is
     * true of an option and is not true of a transient.
     */
    public function test_the_stored_composition_outlives_the_cache(): void
    {
        global $wpdb;

        $html = $this->renderList('<!-- wp:fluent-cart/review-item-content /-->', ['perPage' => 1]);
        $token = $this->compositionTokenIn($html);

        $this->assertStringStartsWith('row-under-test-', $token);

        $name = \FluentCart\App\Hooks\Handlers\BlockEditors\ProductReviewList\InnerBlocks\InnerBlocks::compositionKey($token);

        $this->assertLessThanOrEqual(191, strlen($name), 'a name longer than the column is never stored');

        $autoload = $wpdb->get_var(
            $wpdb->prepare("SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name)
        );

        $this->assertNotNull($autoload, 'the row is in the options table, not only in a cache');

        // What a persistent object cache holds, and nothing else. An entry
        // that lived only here is gone after this.
        wp_cache_delete($name, 'options');
        $this->assertNotContains($autoload, ['yes', 'on', 'auto'], 'and is not loaded on every request');

        wp_cache_flush();

        $rows = $this->rowsFromEndpoint(['page' => 2, 'per_page' => 1, 'client_id' => $token]);

        $this->assertStringContainsString(
            'wp-block-fluent-cart-review-item-content',
            $rows,
            'a flushed cache still answers with the composed row'
        );
    }

    /**
     * The Votes block is the contract PRO fills.
     *
     * The buttons are PRO's and are added in the browser, so this side cannot
     * assert that they appear — only that the markup says where they go and
     * whether they go anywhere at all. PRO used to append to
     * .fct-review-footer, which meant the block decided nothing: the votes
     * showed up whether or not a merchant kept it, and stayed in the footer
     * when they moved it.
     *
     * So a composed row says it is composed, and the Votes block names its
     * slot. A composed row with no slot is a row whose merchant removed the
     * votes, which is the half that cannot be inferred from anything else.
     */
    public function test_the_votes_block_marks_where_the_buttons_go(): void
    {
        // The block draws its slot only where the store offers votes at all,
        // so say so here rather than depend on what the store happens to be
        // set to.
        $modules = ModuleSettings::getAllSettings();
        $modules['reviews']['enable_helpful_votes'] = 'yes';
        ModuleSettings::saveSettings($modules);
        ModuleSettings::getAllSettings(false);
        ProductReviewService::resetRuntimeCache();

        $withVotes = $this->renderList(
            '<!-- wp:fluent-cart/review-item-content /--><!-- wp:fluent-cart/review-item-votes /-->'
        );
        $withoutVotes = $this->renderList('<!-- wp:fluent-cart/review-item-content /-->');

        $this->assertStringContainsString('data-composed-row', $withVotes, 'the row says it was composed');
        $this->assertStringContainsString('data-composed-row', $withoutVotes, 'with or without votes in it');

        $this->assertStringContainsString(
            'data-review-votes-slot',
            $withVotes,
            'the block names the spot the buttons go in'
        );
        $this->assertStringNotContainsString(
            'data-review-votes-slot',
            $withoutVotes,
            'and a row without the block offers none'
        );
    }

    /**
     * The fixed row is not a composed one and must not claim to be — it is the
     * row every existing list and the shortcode still draw, and PRO has always
     * added the buttons to its footer.
     */
    public function test_the_fixed_row_is_not_marked_composed(): void
    {
        $this->assertStringNotContainsString('data-composed-row', $this->renderList(''));
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
     * A page change does not nest a second list inside the first.
     *
     * The script puts what the endpoint returns inside the rows element that
     * is already on the page. If the block wraps its rows again on the way
     * out, every page change buries another list element in the last one —
     * and the script, which looks for the first, ends up refilling a wrapper
     * instead of the rows.
     */
    public function test_a_page_change_returns_rows_without_the_list_element(): void
    {
        $html = $this->renderList('<!-- wp:fluent-cart/review-item-content /-->', ['perPage' => 1]);

        $this->assertSame(
            1,
            substr_count($html, 'data-reviews-list'),
            'the page draws one rows element'
        );

        $rows = $this->rowsFromEndpoint([
            'page' => 2, 'per_page' => 1, 'client_id' => $this->compositionTokenIn($html),
        ]);

        $this->assertStringContainsString('wp-block-fluent-cart-review-item-content', $rows);
        $this->assertStringNotContainsString(
            'data-reviews-list',
            $rows,
            'and the endpoint answers with what goes inside it, not another one'
        );
    }

    /**
     * A row copied into another post does not overwrite the original's.
     *
     * Gutenberg hands a duplicated or pasted block the original's id. Within a
     * post the editor re-mints, but a paste into another post is not something
     * the first post can see — so the render is where it has to be caught, or
     * whichever rendered last decides what the other one's pages show.
     */
    public function test_a_copied_row_does_not_overwrite_the_original(): void
    {
        $GLOBALS['post'] = get_post(static::factory()->post->create());
        $original = $this->renderList('<!-- wp:fluent-cart/review-item-avatar /-->', [
            'perPage' => 1, 'wp_client_id' => 'pasted-id',
        ]);

        $GLOBALS['post'] = get_post(static::factory()->post->create());
        $copy = $this->renderList('<!-- wp:fluent-cart/review-item-content /-->', [
            'perPage' => 1, 'wp_client_id' => 'pasted-id',
        ]);

        $originalToken = $this->compositionTokenIn($original);
        $copyToken = $this->compositionTokenIn($copy);

        $this->assertNotSame($originalToken, $copyToken, 'the copy takes a key of its own');

        $originalRows = $this->rowsFromEndpoint([
            'page' => 2, 'per_page' => 1, 'client_id' => $originalToken,
        ]);
        $copyRows = $this->rowsFromEndpoint([
            'page' => 2, 'per_page' => 1, 'client_id' => $copyToken,
        ]);

        $this->assertStringContainsString(
            'wp-block-fluent-cart-review-item-avatar',
            $originalRows,
            'the original still serves the row it was composed with'
        );
        $this->assertStringContainsString(
            'wp-block-fluent-cart-review-item-content',
            $copyRows,
            'and the copy serves its own'
        );
    }

    /**
     * A row in use does not expire out from under a cached page.
     *
     * The entry is written when the block renders, and on a page-cached site
     * that stops happening — so a list people use every day would still lose
     * its row when the transient ran out. The endpoint is what still runs, so
     * a read pushes the expiry back.
     */
    public function test_a_read_keeps_a_live_row_from_expiring(): void
    {
        $html = $this->renderList('<!-- wp:fluent-cart/review-item-content /-->', ['perPage' => 1]);
        $token = $this->compositionTokenIn($html);
        $key = \FluentCart\App\Hooks\Handlers\BlockEditors\ProductReviewList\InnerBlocks\InnerBlocks::compositionKey($token);

        // As it would look after days of the page being served from a cache.
        $stored = (array) get_option($key, []);
        $stored['set_at'] = gmdate('Y-m-d H:i:s', time() - (5 * DAY_IN_SECONDS));
        update_option($key, $stored, false);

        $this->rowsFromEndpoint(['page' => 2, 'per_page' => 1, 'client_id' => $token]);

        $refreshed = get_transient($key);

        $this->assertGreaterThan(
            time() - DAY_IN_SECONDS,
            strtotime(((array) get_option($key, []))['set_at']),
            'the read pushed the expiry back'
        );
    }

    /**
     * A filter that finds nothing still counts as the row being in use.
     *
     * The rows renderer is only called when there are reviews to draw, so a
     * list filtered down to an empty result never re-renders the block — and
     * the block is what normally pushes the expiry back. Left at that, a list
     * people use every day could still lose its row for having been filtered.
     */
    public function test_an_empty_result_still_keeps_the_row_alive(): void
    {
        $html = $this->renderList('<!-- wp:fluent-cart/review-item-content /-->', ['perPage' => 1]);
        $token = $this->compositionTokenIn($html);
        $key = \FluentCart\App\Hooks\Handlers\BlockEditors\ProductReviewList\InnerBlocks\InnerBlocks::compositionKey($token);

        // As it would look after days of the page being served from a cache.
        $stored = (array) get_option($key, []);
        $stored['set_at'] = gmdate('Y-m-d H:i:s', time() - (5 * DAY_IN_SECONDS));
        update_option($key, $stored, false);

        // A rating nothing matches: the endpoint answers, but with no rows to
        // draw the block never renders.
        $rows = $this->rowsFromEndpoint([
            'page' => 1, 'per_page' => 1, 'rating' => 1, 'client_id' => $token,
        ]);

        $this->assertStringNotContainsString(
            'wp-block-fluent-cart-review-item-content',
            $rows,
            'nothing matched, so no row was drawn'
        );

        $this->assertGreaterThan(
            time() - DAY_IN_SECONDS,
            strtotime(((array) get_option($key, []))['set_at']),
            'and the read alone pushed the expiry back'
        );
    }

    public function test_a_preview_prints_a_token_that_resolves(): void
    {
        global $wp_query;

        $row = '<!-- wp:fluent-cart/review-item-avatar /--><!-- wp:fluent-cart/review-item-content /-->';

        $published = $this->compositionTokenIn($this->renderList($row, ['perPage' => 1]));

        $wasPreview = $wp_query->is_preview;
        $wp_query->is_preview = true;

        $previewed = $this->compositionTokenIn($this->renderList($row, ['perPage' => 1]));

        $wp_query->is_preview = $wasPreview;

        $this->assertSame($published, $previewed, 'the same row previews under the same token');

        // The point of the finding: a preview must not write, but the token it
        // prints still has to be one the refresh endpoint can answer, or every
        // sort and page change inside the preview drops to the fixed row.
        $this->assertStringContainsString(
            'review-item-avatar',
            (string) Arr::get(\FluentCart\App\Hooks\Handlers\BlockEditors\ProductReviewList\InnerBlocks\InnerBlocks::composition($previewed), 'markup'),
            'and that token resolves to the stored row'
        );
    }

    /**
     * A product with no reviews yet still advertises its composed row.
     *
     * The block owns the element the storefront script refills and the id it
     * sends back with every sort, filter and page change. Draw neither while
     * the list is empty and the first request that does find reviews comes
     * back as the fixed row, losing the composed layout on the page where it
     * matters most — the one that just got its first review.
     */
    public function test_an_empty_list_still_draws_its_element_and_id(): void
    {
        ProductReview::query()->where('post_id', $this->productId)->delete();
        ProductReviewService::resetRuntimeCache();

        $html = $this->renderList('<!-- wp:fluent-cart/review-item-content /-->');

        $this->assertStringContainsString(
            'data-reviews-empty',
            $html,
            'the empty state is still what a reader sees'
        );
        $this->assertStringContainsString(
            'data-reviews-list',
            $html,
            'inside the element the script refills'
        );
        $this->assertMatchesRegularExpression(
            '#data-client-id="row-under-test-[0-9a-f]{12}"#',
            $html,
            'which carries the id the script sends back'
        );
    }

    /**
     * An old row the post still draws is kept.
     *
     * A page cache can keep a page off the server for longer than the
     * retention window while that page goes on printing its token. The date
     * says nobody has drawn it in a month; the post says it is what the list
     * is made of. The post is the one that knows.
     */
    public function test_a_row_its_post_still_draws_outlives_the_window(): void
    {
        $owner = self::factory()->post->create([
            'post_content' => '<!-- wp:fluent-cart/review-item {"wp_client_id":"still-drawn"} -->'
                . '<!-- wp:fluent-cart/review-item-content /-->'
                . '<!-- /wp:fluent-cart/review-item -->',
        ]);

        $key = InnerBlocks::compositionKey('still-drawn-abcdef123456');

        update_option($key, [
            'set_at'    => gmdate('Y-m-d H:i:s', time() - (90 * DAY_IN_SECONDS)),
            'markup'    => '',
            'owner'     => $owner,
            'client_id' => 'still-drawn',
        ], false);

        // A row the store has not seen is what sets a sweep going.
        $this->renderList('<!-- wp:fluent-cart/review-item-content /-->');

        $this->assertIsArray(
            get_option($key),
            'ninety days old and still what the post is made of'
        );
    }

    /**
     * A row nothing draws any more goes, once it is past the window.
     */
    public function test_a_row_its_post_no_longer_draws_is_cleared(): void
    {
        $owner = self::factory()->post->create([
            'post_content' => '<!-- wp:paragraph --><p>The list was taken out.</p><!-- /wp:paragraph -->',
        ]);

        $key = InnerBlocks::compositionKey('taken-out-abcdef123456');

        update_option($key, [
            'set_at'    => gmdate('Y-m-d H:i:s', time() - (90 * DAY_IN_SECONDS)),
            'markup'    => '',
            'owner'     => $owner,
            'client_id' => 'taken-out',
        ], false);

        $this->renderList('<!-- wp:fluent-cart/review-item-content /-->');

        $this->assertFalse(get_option($key), 'the post stopped pointing at it a month ago');
    }

    /**
     * A row inside the window is kept whatever its post says.
     *
     * The content check is what makes an old row safe to keep, not a second
     * thing a fresh row has to pass: a list in a template is drawn from
     * content no post holds, and it is written and renewed like any other.
     */
    public function test_a_recent_row_is_kept_without_asking_the_post(): void
    {
        $key = InnerBlocks::compositionKey('no-owner-recorded-abcdef123456');

        update_option($key, [
            'set_at' => gmdate('Y-m-d H:i:s'),
            'markup' => '',
        ], false);

        $this->renderList('<!-- wp:fluent-cart/review-item-content /-->');

        $this->assertIsArray(get_option($key), 'written today, so nothing has to vouch for it');
    }

    /**
     * There is no cap on how many rows may be kept.
     *
     * A count cannot tell a live row from a dead one. Held to one, a site with
     * more composed lists than the cap loses the layout of whichever it
     * touched least recently — and the page, still printing that token, falls
     * back to the fixed row on its first sort or page change.
     */
    public function test_the_store_is_not_trimmed_to_a_count(): void
    {
        $keys = [];

        for ($i = 0; $i < 60; $i++) {
            $keys[$i] = InnerBlocks::compositionKey('live-' . $i . '-abcdef123456');

            update_option($keys[$i], [
                'set_at' => gmdate('Y-m-d H:i:s', time() - $i),
                'markup' => '',
            ], false);
        }

        $this->renderList('<!-- wp:fluent-cart/review-item-content /-->');

        $this->assertIsArray(get_option($keys[0]), 'the first is still there');
        $this->assertIsArray(get_option($keys[59]), 'and so is the sixtieth');
    }

    /**
     * A row a template still draws is kept.
     *
     * The list lives in the template, not in the post being viewed, so the
     * owner recorded against the row is the one post that cannot vouch for
     * it. Asking only the owner would delete every template-placed row and
     * leave every other kind alone.
     */
    public function test_a_row_a_template_still_draws_is_kept(): void
    {
        $viewed = self::factory()->post->create([
            'post_content' => '<!-- wp:paragraph --><p>A product, drawn by a template.</p><!-- /wp:paragraph -->',
        ]);

        self::factory()->post->create([
            'post_type'    => 'wp_template',
            'post_content' => '<!-- wp:fluent-cart/review-item {"wp_client_id":"in-a-template"} -->'
                . '<!-- wp:fluent-cart/review-item-content /-->'
                . '<!-- /wp:fluent-cart/review-item -->',
        ]);

        $key = InnerBlocks::compositionKey('in-a-template-abcdef123456');

        update_option($key, [
            'set_at'    => gmdate('Y-m-d H:i:s', time() - (90 * DAY_IN_SECONDS)),
            'markup'    => '',
            'owner'     => $viewed,
            'client_id' => 'in-a-template',
        ], false);

        $this->renderList('<!-- wp:fluent-cart/review-item-content /-->');

        $this->assertIsArray(get_option($key), 'the template still draws it');
    }

    /**
     * The sweep works its way through the whole store.
     *
     * Reading the same first batch back on every sweep leaves everything past
     * it untouched however many rows are written, so a store larger than one
     * batch keeps its orphans for good.
     */
    public function test_the_sweep_moves_past_its_first_batch(): void
    {
        $batch = InnerBlocks::COMPOSITION_SWEEP_BATCH;
        $dead = gmdate('Y-m-d H:i:s', time() - (90 * DAY_IN_SECONDS));

        // Orphans: no post has ever mentioned any of these ids.
        for ($i = 0; $i < $batch + 5; $i++) {
            update_option(
                InnerBlocks::compositionKey('orphan-' . $i . '-abcdef123456'),
                ['set_at' => $dead, 'markup' => '', 'owner' => 0, 'client_id' => 'orphan-' . $i],
                false
            );
        }

        $last = InnerBlocks::compositionKey('orphan-' . ($batch + 4) . '-abcdef123456');

        // One sweep cannot reach the far end of the store.
        $this->renderList('<!-- wp:fluent-cart/review-item-content /-->');
        $this->assertIsArray(get_option($last), 'past the first batch, so not looked at yet');

        // Each sweep examines at most ten expired rows. Render new
        // compositions to trigger enough sweeps to examine every orphan.
        $sweeps = (int) ceil(($batch + 5) / InnerBlocks::COMPOSITION_SWEEP_CHECKS);
        for ($i = 1; $i < $sweeps; $i++) {
            $this->renderList('<!-- wp:fluent-cart/review-item-content /-->', [
                'wp_client_id' => 'sweep-trigger-' . $i,
            ]);
        }

        $this->assertFalse(get_option($last), 'successive bounded sweeps reach the last orphan');
    }

    /**
     * One sweep only asks the content about a handful of rows.
     *
     * Nothing indexes post_content, so each of those questions is a scan of
     * the posts table - and the sweep runs inside a page render. A store full
     * of expired rows would otherwise put hundreds of them in one request.
     */
    public function test_one_sweep_asks_the_content_about_only_a_few_rows(): void
    {
        $dead = gmdate('Y-m-d H:i:s', time() - (90 * DAY_IN_SECONDS));
        $keys = [];

        for ($i = 0; $i < 40; $i++) {
            $keys[] = InnerBlocks::compositionKey('spent-' . $i . '-abcdef123456');

            update_option(
                end($keys),
                ['set_at' => $dead, 'markup' => '', 'owner' => 0, 'client_id' => 'spent-' . $i],
                false
            );
        }

        $this->renderList('<!-- wp:fluent-cart/review-item-content /-->');

        $gone = 0;
        foreach ($keys as $key) {
            if (get_option($key) === false) {
                $gone++;
            }
        }

        $this->assertSame(
            InnerBlocks::COMPOSITION_SWEEP_CHECKS,
            $gone,
            'as many as it was allowed to ask about, and no more'
        );
    }

    /**
     * A Review Item nested inside another draws nothing.
     *
     * It satisfies its own ancestor rule, so the editor allows it, and it is
     * handed the same collection as the one around it — so it would loop every
     * review once per row of the outer. Ten reviews become a hundred and ten
     * cards, and the endpoint repeats it on every page change.
     */
    public function test_a_nested_review_item_does_not_multiply_the_rows(): void
    {
        $nested = '<!-- wp:fluent-cart/review-item {"wp_client_id":"inner"} -->'
            . '<!-- wp:fluent-cart/review-item-content /-->'
            . '<!-- /wp:fluent-cart/review-item -->';

        $html = $this->renderList('<!-- wp:fluent-cart/review-item-content /-->' . $nested, ['perPage' => 3]);

        $this->assertSame(
            3,
            substr_count($html, 'data-review-id='),
            'three reviews draw three cards, not nine'
        );
    }

    /**
     * A second Review Item beside the first draws nothing.
     *
     * Each would draw its own rows element, and the storefront script reads
     * the first one only — so the second would sit showing page one for good,
     * under a pager that says otherwise.
     */
    public function test_a_second_review_item_does_not_draw_a_second_list(): void
    {
        $html = $this->renderList(
            '<!-- wp:fluent-cart/review-item-content /-->',
            ['perPage' => 1]
        );

        $this->assertSame(1, substr_count($html, 'data-reviews-list'), 'one list on the page');

        $two = '<!-- wp:fluent-cart/review-item {"wp_client_id":"first"} -->'
            . '<!-- wp:fluent-cart/review-item-content /-->'
            . '<!-- /wp:fluent-cart/review-item -->'
            . '<!-- wp:fluent-cart/review-item {"wp_client_id":"second"} -->'
            . '<!-- wp:fluent-cart/review-item-avatar /-->'
            . '<!-- /wp:fluent-cart/review-item -->';

        $markup = '<!-- wp:fluent-cart/product-review-list ' . wp_json_encode([
            'query_type' => 'custom',
            'product_id' => (string) $this->productId,
            'perPage'    => 1,
        ]) . ' -->' . $two . '<!-- /wp:fluent-cart/product-review-list -->';

        $rendered = (string) preg_replace('#<style.*?</style>#s', '', do_blocks($markup));

        $this->assertSame(
            1,
            substr_count($rendered, 'data-reviews-list'),
            'still one, however many Review Items are placed'
        );
        $this->assertStringNotContainsString(
            'wp-block-fluent-cart-review-item-avatar',
            $rendered,
            'and the second one draws nothing at all'
        );
    }

    /**
     * A preview does not replace the row the published page is serving.
     *
     * A preview renders on the front end under the same post id and the same
     * client id, so an unsaved draft would overwrite the stored row — and the
     * next visitor to sort or page the published page would be served the
     * unpublished one, with no way back until someone rendered it again.
     */
    public function test_a_preview_does_not_overwrite_the_published_row(): void
    {
        global $wp_query;

        $GLOBALS['post'] = get_post(static::factory()->post->create(['post_status' => 'publish']));

        $html = $this->renderList('<!-- wp:fluent-cart/review-item-content /-->', ['perPage' => 1]);

        $option = \FluentCart\App\Hooks\Handlers\BlockEditors\ProductReviewList\InnerBlocks\InnerBlocks::compositionKey($this->compositionTokenIn($html));
        $published = (string) Arr::get((array) get_option($option, []), 'markup');

        $this->assertStringContainsString('review-item-content', $published);

        // The same post, previewed with the row composed differently.
        $wasPreview = $wp_query->is_preview;
        $wp_query->is_preview = true;

        $this->renderList('<!-- wp:fluent-cart/review-item-avatar /-->', ['perPage' => 1]);

        $wp_query->is_preview = $wasPreview;

        $this->assertSame(
            $published,
            (string) Arr::get((array) get_option($option, []), 'markup'),
            'what is stored is still the published row'
        );
    }

    /**
     * A preview of an unchanged page still resolves to the stored row.
     *
     * The preview must not write — an unsaved draft would replace what
     * visitors are being served — but it still has to print a token the
     * endpoint can answer, or paging inside the preview silently drops to the
     * fixed row and the preview stops showing what the page will do.
     */
    /**
     * Nor does a draft that is rendered on the front end.
     */
    public function test_a_draft_does_not_overwrite_the_published_row(): void
    {
        $GLOBALS['post'] = get_post(static::factory()->post->create(['post_status' => 'publish']));

        $html = $this->renderList('<!-- wp:fluent-cart/review-item-content /-->', ['perPage' => 1]);

        $option = \FluentCart\App\Hooks\Handlers\BlockEditors\ProductReviewList\InnerBlocks\InnerBlocks::compositionKey($this->compositionTokenIn($html));
        $published = (string) Arr::get((array) get_option($option, []), 'markup');

        // The same post, taken back to a draft — so the owner matches and the
        // copy-disambiguation branch cannot be what saves it.
        wp_update_post(['ID' => $GLOBALS['post']->ID, 'post_status' => 'draft']);
        $GLOBALS['post'] = get_post($GLOBALS['post']->ID);

        $this->renderList('<!-- wp:fluent-cart/review-item-avatar /-->', ['perPage' => 1]);

        $this->assertSame(
            $published,
            (string) Arr::get((array) get_option($option, []), 'markup'),
            'a draft render leaves the stored row alone'
        );
    }

    /**
     * Two requests composing different rows do not lose one another's write.
     *
     * Both read the store, both write it back. Held in one shared value, the
     * second write would carry the first request's stale copy and drop its
     * row — and that request's page would already have gone out printing a
     * token nothing could resolve, so its first sort or filter would fall back
     * to the fixed row for good.
     *
     * Interleaved by hand: request A reads, request B reads and writes, then A
     * writes. Each row is its own option, so A cannot carry B away with it.
     */
    public function test_interleaved_writes_keep_both_rows(): void
    {
        // B reads and writes while A is still working.
        $bHtml = $this->renderList('<!-- wp:fluent-cart/review-item-avatar /-->', [
            'perPage' => 1, 'wp_client_id' => 'request-b',
        ]);

        // A writes, against the store as it was before B touched it.
        $aHtml = $this->renderList('<!-- wp:fluent-cart/review-item-content /-->', [
            'perPage' => 1, 'wp_client_id' => 'request-a',
        ]);

        $bToken = $this->compositionTokenIn($bHtml);
        $aToken = $this->compositionTokenIn($aHtml);

        $this->assertNotSame('', $bToken);
        $this->assertNotSame('', $aToken);

        $this->assertStringContainsString(
            'review-item-avatar',
            (string) Arr::get((array) get_option(\FluentCart\App\Hooks\Handlers\BlockEditors\ProductReviewList\InnerBlocks\InnerBlocks::compositionKey($bToken)), 'markup'),
            "B's row survives A's later write"
        );
        $this->assertStringContainsString(
            'review-item-content',
            (string) Arr::get((array) get_option(\FluentCart\App\Hooks\Handlers\BlockEditors\ProductReviewList\InnerBlocks\InnerBlocks::compositionKey($aToken)), 'markup'),
            "and A's row is there too"
        );

        delete_option(\FluentCart\App\Hooks\Handlers\BlockEditors\ProductReviewList\InnerBlocks\InnerBlocks::compositionKey($aToken));
        delete_option(\FluentCart\App\Hooks\Handlers\BlockEditors\ProductReviewList\InnerBlocks\InnerBlocks::compositionKey($bToken));
    }

    /**
     * Editing one post's row does not change another post's.
     *
     * A block copied into a second post arrives holding the first one's id, so
     * while both draw the same row they point at one entry — harmless, until
     * the first is edited. Keyed on the id alone that edit rewrites the entry
     * both are using, and the second post's pages quietly start drawing a
     * layout its editor never chose.
     */
    public function test_editing_one_post_does_not_change_another(): void
    {
        $shared = '<!-- wp:fluent-cart/review-item-content /-->';

        $GLOBALS['post'] = get_post(static::factory()->post->create(['post_status' => 'publish']));
        $first = $this->renderList($shared, ['perPage' => 1, 'wp_client_id' => 'pasted-id']);

        // The same block, pasted into another post and left alone.
        $GLOBALS['post'] = get_post(static::factory()->post->create(['post_status' => 'publish']));
        $second = $this->renderList($shared, ['perPage' => 1, 'wp_client_id' => 'pasted-id']);

        $this->assertSame(
            $this->compositionTokenIn($first),
            $this->compositionTokenIn($second),
            'the same row drawn twice is one entry, wherever it is shown'
        );

        // Now the first post's row is edited.
        $GLOBALS['post'] = get_post((int) $GLOBALS['post']->ID);
        $this->renderList('<!-- wp:fluent-cart/review-item-avatar /-->', [
            'perPage' => 1, 'wp_client_id' => 'pasted-id',
        ]);

        $untouched = (string) Arr::get(
            (array) get_option(\FluentCart\App\Hooks\Handlers\BlockEditors\ProductReviewList\InnerBlocks\InnerBlocks::compositionKey($this->compositionTokenIn($second))),
            'markup'
        );

        $this->assertStringContainsString(
            'review-item-content',
            $untouched,
            'the row the second post points at still says what it always said'
        );
        $this->assertStringNotContainsString(
            'review-item-avatar',
            $untouched,
            'and not what the first post was changed to'
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
     * Every field block registers, and only under the list — each names it as
     * its only parent, so one registered without it could never be inserted.
     */
    public function test_the_field_blocks_register_under_the_review_item(): void
    {
        $registry = \WP_Block_Type_Registry::get_instance();
        $found = 0;

        foreach ($registry->get_all_registered() as $name => $type) {
            if (strpos($name, 'fluent-cart/review-item-') !== 0) {
                continue;
            }

            $found++;
            $this->assertSame(
                ['fluent-cart/review-item'],
                (array) $type->ancestor,
                $name . ' belongs inside a Review Item, which is what supplies the review'
            );
            // ancestor, not parent: parent means the IMMEDIATE parent, and the
            // row template nests most of these inside core/group. With parent,
            // a field deleted from inside a group could never be put back —
            // the inserter there offers only blocks whose parent is that group.
            $this->assertEmpty(
                $type->parent,
                $name . ' must not pin an immediate parent, or it cannot be inserted in a nested group'
            );
        }

        $this->assertSame(11, $found, 'one block per field of a review');

        $item = $registry->get_registered('fluent-cart/review-item');

        $this->assertSame(
            ['fluent-cart/product-review-list'],
            (array) $item->ancestor,
            'and the Review Item itself belongs to the list, never inside another one'
        );
    }
}
