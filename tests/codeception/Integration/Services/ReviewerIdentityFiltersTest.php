<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\Schema\ProductSchema;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Traits\ComposesReviewLists;

/**
 * Store-wide switches for the reviewer's name and avatar on public surfaces.
 *
 * A block can already hide either one; these filters hide them everywhere a
 * shopper can read another shopper's review — the rendered rows, the JSON
 * they come from, the replies endpoint and the thread modal.
 */
class ReviewerIdentityFiltersTest extends WPTestCase
{
    use ComposesReviewLists;

    /** @var ProductReview */
    private $review;

    public function setUp(): void
    {
        parent::setUp();
        update_option('show_avatars', 1);
        $this->bootComposedReviewList();

        ProductReview::query()->where('post_id', $this->productId)
            ->update(['reviewer_email' => 'dana.buyer@example.com']);

        $this->review = ProductReview::query()->where('post_id', $this->productId)->orderBy('id')->first();
    }

    public function tearDown(): void
    {
        remove_all_filters('fluent_cart/review/show_reviewer_name');
        remove_all_filters('fluent_cart/review/show_reviewer_avatar');
        $this->restoreComposedReviewList();
        parent::tearDown();
    }

    private function hideName(): void
    {
        add_filter('fluent_cart/review/show_reviewer_name', '__return_false');
    }

    private function hideAvatar(): void
    {
        add_filter('fluent_cart/review/show_reviewer_avatar', '__return_false');
    }

    private function endpoint(string $path): array
    {
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/public/reviews/' . $this->productId . $path);
        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status(), 'the public endpoint answered');

        return (array) $response->get_data();
    }

    private function createReply(): ProductReview
    {
        $reply = new ProductReview([
            'post_id'        => $this->productId,
            'parent_id'      => $this->review->id,
            'reviewer_name'  => 'Riley Replier',
            'reviewer_email' => 'riley@example.com',
            'review'         => 'Same here.',
            'rating'         => 0,
        ]);
        $reply->status = Status::REVIEW_APPROVED;
        $reply->save();

        return $reply;
    }

    public function test_name_and_avatar_are_public_by_default(): void
    {
        $data = $this->endpoint('');
        $row = $data['reviews']['data'][0];

        $this->assertSame('Dana Buyer', $row['reviewer_name']);
        $this->assertNotSame('', $row['photo'], 'a reviewer with an email has an avatar url');
        $this->assertStringContainsString('Dana Buyer', $data['reviews_html']);
        $this->assertStringContainsString('fct-review-avatar-circle', $data['reviews_html']);
    }

    public function test_hiding_the_name_drops_it_from_the_rows_and_the_payload(): void
    {
        $this->hideName();

        $data = $this->endpoint('');
        $row = $data['reviews']['data'][0];

        $this->assertArrayHasKey('reviewer_name', $row, 'the key stays so the response shape does not change');
        $this->assertSame('', $row['reviewer_name']);
        $this->assertStringNotContainsString('Dana Buyer', $data['reviews_html']);
        $this->assertStringContainsString('fct-review-avatar-circle', $data['reviews_html'], 'the avatar is a separate switch');
    }

    public function test_hiding_the_avatar_drops_the_url_and_the_circle(): void
    {
        $this->hideAvatar();

        $data = $this->endpoint('');
        $row = $data['reviews']['data'][0];

        $this->assertArrayHasKey('photo', $row);
        $this->assertSame('', $row['photo'], 'no gravatar url, so no email hash reaches the page');
        $this->assertStringNotContainsString('fct-review-avatar-circle', $data['reviews_html']);
        $this->assertStringContainsString('Dana Buyer', $data['reviews_html'], 'the name is a separate switch');
    }

    public function test_the_filters_win_over_a_block_that_asks_to_show_both(): void
    {
        $this->hideName();
        $this->hideAvatar();

        $html = do_blocks('<!-- wp:fluent-cart/product-reviews ' . wp_json_encode([
            'query_type' => 'custom',
            'product_id' => (string) $this->productId,
        ]) . ' /-->');

        $this->assertStringContainsString('fct-review-item', $html, 'the reviews still render');
        $this->assertStringNotContainsString('Dana Buyer', $html);
        $this->assertStringNotContainsString('fct-review-avatar-circle', $html);
    }

    public function test_composed_avatar_and_author_blocks_render_nothing_when_hidden(): void
    {
        $children = '<!-- wp:fluent-cart/review-item-avatar /--><!-- wp:fluent-cart/review-item-author-name /--><!-- wp:fluent-cart/review-item-content /-->';

        $shown = $this->renderList($children);
        $this->assertStringContainsString('Dana Buyer', $shown);
        $this->assertStringContainsString('fct-review-avatar-circle', $shown);

        $this->hideName();
        $this->hideAvatar();
        $hidden = $this->renderList($children);

        $this->assertStringContainsString('First opinion.', $hidden, 'the review itself still renders');
        $this->assertStringNotContainsString('Dana Buyer', $hidden);
        $this->assertStringNotContainsString('fct-review-avatar-circle', $hidden);
    }

    public function test_the_replies_endpoint_follows_the_filters(): void
    {
        $this->createReply();
        $this->hideName();
        $this->hideAvatar();

        $data = $this->endpoint('/' . $this->review->id . '/replies');
        $reply = $data['replies']['data'][0];

        $this->assertSame('', $reply['reviewer_name']);
        $this->assertSame('', $reply['photo']);
    }

    public function test_the_thread_modal_follows_the_filters(): void
    {
        $this->createReply();

        $shown = $this->endpoint('/' . $this->review->id . '/modal')['view'];
        $this->assertStringContainsString('Dana Buyer', $shown);
        $this->assertStringContainsString('Riley Replier', $shown);
        $this->assertStringContainsString('fct-review-avatar-circle', $shown);

        $this->hideName();
        $this->hideAvatar();
        $hidden = $this->endpoint('/' . $this->review->id . '/modal')['view'];

        $this->assertStringContainsString('Same here.', $hidden, 'the thread still renders');
        $this->assertStringNotContainsString('Dana Buyer', $hidden);
        $this->assertStringNotContainsString('Riley Replier', $hidden);
        $this->assertStringNotContainsString('fct-review-avatar-circle', $hidden);
    }

    /**
     * The switches are frontend display only. The schema author is left to
     * the store, through the schema's own filters.
     */
    public function test_the_schema_author_is_not_changed_by_the_filters(): void
    {
        $this->hideName();
        $this->hideAvatar();

        $this->assertSame('Dana Buyer', ProductSchema::get($this->productId)['review'][0]['author']['name']);
    }
}
