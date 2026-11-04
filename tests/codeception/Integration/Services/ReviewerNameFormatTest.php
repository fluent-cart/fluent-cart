<?php

namespace Tests\Integration\Services;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\App\Services\Schema\ProductSchema;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Traits\ComposesReviewLists;

/**
 * The fluent_cart/review/reviewer_name_format filter: how a customer's name
 * reads on public review surfaces. Applied at display time, so existing
 * reviews follow it and the stored name never changes. Store replies keep
 * their name.
 */
class ReviewerNameFormatTest extends WPTestCase
{
    use ComposesReviewLists;

    /** @var ProductReview */
    private $review;

    public function setUp(): void
    {
        parent::setUp();
        $this->bootComposedReviewList();

        ProductReview::query()->where('post_id', $this->productId)
            ->update(['reviewer_name' => 'Dana Marie Buyer']);

        $this->review = ProductReview::query()->where('post_id', $this->productId)->orderBy('id')->first();
    }

    public function tearDown(): void
    {
        remove_all_filters('fluent_cart/review/reviewer_name_format');
        remove_all_filters('fluent_cart/review/show_reviewer_name');
        remove_all_filters('fluent_cart/review/reviewer_display_name');
        $this->restoreComposedReviewList();
        parent::tearDown();
    }

    /**
     * @param mixed $format
     */
    private function setFormat($format): void
    {
        remove_all_filters('fluent_cart/review/reviewer_name_format');
        add_filter('fluent_cart/review/reviewer_name_format', function () use ($format) {
            return $format;
        });
    }

    private function endpoint(string $path): array
    {
        // The framework request is a process singleton that keeps params from
        // earlier REST calls (per_page=1 from a paging test would trim the replies).
        $app = \FluentCart\App\App::getInstance();
        $fresh = new \FluentCart\Framework\Http\Request\Request($app, [], []);
        $app->instance(\FluentCart\Framework\Http\Request\Request::class, $fresh);
        $app->instance('request', $fresh);
        if ($app->bound('wprestrequest')) {
            $app->forgetInstance('wprestrequest');
        }
        $response = rest_do_request(new \WP_REST_Request('GET', '/fluent-cart/v2/public/reviews/' . $this->productId . $path));
        $this->assertSame(200, $response->get_status());

        return (array) $response->get_data();
    }

    private function createReply(array $overrides = []): ProductReview
    {
        $reply = new ProductReview(array_merge([
            'post_id'       => $this->productId,
            'parent_id'     => $this->review->id,
            'reviewer_name' => 'Riley Quinn Replier',
            'review'        => 'Same here.',
            'rating'        => 0,
        ], $overrides));
        $reply->status = Status::REVIEW_APPROVED;
        $reply->is_admin_reply = !empty($overrides['is_admin_reply']) ? 1 : 0;
        $reply->save();

        return $reply;
    }

    public function test_each_format_reads_the_name_as_expected(): void
    {
        $cases = [
            'full'          => 'Dana Marie Buyer',
            'first'         => 'Dana',
            'first_initial' => 'Dana B.',
            'anonymous'     => 'Anonymous',
            'masked'        => 'Dan***yer',
        ];

        foreach ($cases as $format => $expected) {
            $this->assertSame($expected, ProductReviewService::formatReviewerName('  Dana   Marie Buyer ', $format), $format);
        }

        $this->assertSame('Dana', ProductReviewService::formatReviewerName('Dana', 'first_initial'), 'one word has no initial to add');
        $this->assertSame('Élise Ž.', ProductReviewService::formatReviewerName('Élise žák', 'first_initial'), 'multibyte initial');
        $this->assertSame('', ProductReviewService::formatReviewerName('', 'first'), 'no name stays no name');
    }

    public function test_masked_takes_the_start_of_the_first_name_and_the_end_of_the_last(): void
    {
        $cases = [
            'Jordan Hughes'    => 'Jor***hes',
            'J Hughes'         => 'J***hes',   // the first name is all the start there is
            'Jamie Lee'        => 'Jam***Lee',
            'Dana Marie Buyer' => 'Dan***yer', // middle names are hidden
            'Élise Žák'        => 'Éli***Žák', // counted by letter, not byte
            "Mary-Jane O'Neil" => 'Mar***eil', // punctuation is not a letter
            'Priya N.'         => 'Pri***N',   // a stored initial does not end the mask with a dot
        ];

        foreach ($cases as $name => $expected) {
            $this->assertSame($expected, ProductReviewService::formatReviewerName($name, 'masked'), $name);
        }
    }

    public function test_masked_never_spells_the_whole_name(): void
    {
        // Both parts short enough to show whole, or one word of six letters
        // or fewer: only the first letter shows.
        foreach (['Al Bo' => 'A***', 'Jo Lee' => 'J***', 'Sam' => 'S***', 'Alice' => 'A***', 'Taylor' => 'T***'] as $name => $expected) {
            $this->assertSame($expected, ProductReviewService::formatReviewerName($name, 'masked'), $name);
        }

        // One word longer than six: three letters at each end.
        $this->assertSame('Chr***her', ProductReviewService::formatReviewerName('Christopher', 'masked'));
        $this->assertSame('', ProductReviewService::formatReviewerName('', 'masked'), 'no name stays no name');
    }

    public function test_masked_reaches_the_list_payload(): void
    {
        $this->setFormat('masked');

        $data = $this->endpoint('');

        $this->assertSame('Dan***yer', $data['reviews']['data'][0]['reviewer_name']);
        $this->assertStringContainsString('Dan***yer', $data['reviews_html']);
        $this->assertStringNotContainsString('Marie', $data['reviews_html']);
    }

    public function test_full_is_the_default_and_an_unknown_value_falls_back_to_it(): void
    {
        $this->assertSame('full', ProductReviewService::reviewerNameFormat());

        foreach (['<script>', 'FIRST', null, ['first']] as $unknown) {
            $this->setFormat($unknown);
            $this->assertSame('full', ProductReviewService::reviewerNameFormat(), var_export($unknown, true));
        }

        $this->assertSame('Dana Marie Buyer', $this->endpoint('')['reviews']['data'][0]['reviewer_name']);
    }

    public function test_the_format_is_a_filter_not_a_store_setting(): void
    {
        $this->assertArrayNotHasKey('reviewer_name_format', ProductReviewService::getReviewSettings());
    }

    public function test_the_list_and_its_payload_follow_the_format(): void
    {
        $this->setFormat('first_initial');

        $data = $this->endpoint('');

        $this->assertSame('Dana B.', $data['reviews']['data'][0]['reviewer_name']);
        $this->assertStringContainsString('Dana B.', $data['reviews_html']);
        $this->assertStringNotContainsString('Buyer', $data['reviews_html']);
        $this->assertSame('Dana Marie Buyer', ProductReview::query()->find($this->review->id)->reviewer_name, 'the stored name is untouched');
    }

    public function test_composed_author_blocks_follow_the_format(): void
    {
        $this->setFormat('first');

        $html = $this->renderList('<!-- wp:fluent-cart/review-item-author-name /--><!-- wp:fluent-cart/review-item-content /-->');

        $this->assertStringContainsString('>Dana<', $html);
        $this->assertStringNotContainsString('Buyer', $html);
    }

    public function test_the_modal_and_replies_format_customers_but_keep_the_store_name(): void
    {
        $this->createReply();
        $this->createReply(['reviewer_name' => 'Acme Support Team', 'is_admin_reply' => 1]);
        $this->setFormat('first_initial');

        $view = $this->endpoint('/' . $this->review->id . '/modal')['view'];
        $this->assertStringContainsString('Dana B.', $view);
        $this->assertStringContainsString('Riley R.', $view);
        $this->assertStringContainsString('Acme Support Team', $view, 'a store reply keeps its name');
        $this->assertStringNotContainsString('Buyer', $view);

        $names = array_column($this->endpoint('/' . $this->review->id . '/replies')['replies']['data'], 'reviewer_name');
        $this->assertSame(['Riley R.', 'Acme Support Team'], $names);
    }

    public function test_the_hide_filter_wins_over_the_format(): void
    {
        $this->setFormat('first');
        add_filter('fluent_cart/review/show_reviewer_name', '__return_false');

        $data = $this->endpoint('');

        $this->assertSame('', $data['reviews']['data'][0]['reviewer_name']);
        $this->assertStringNotContainsString('Dana', $data['reviews_html']);
    }

    public function test_a_filter_can_supply_its_own_format(): void
    {
        $this->setFormat('first');
        add_filter('fluent_cart/review/reviewer_display_name', function ($display, $context) {
            return $context['format'] === 'first' ? strtoupper($display) : $display;
        }, 10, 2);

        $this->assertSame('DANA', $this->endpoint('')['reviews']['data'][0]['reviewer_name']);
    }

    public function test_the_schema_is_not_changed_by_the_format(): void
    {
        $this->setFormat('anonymous');

        $this->assertSame('Dana Marie Buyer', ProductSchema::get($this->productId)['review'][0]['author']['name']);
    }
}
