<?php

namespace Tests\Integration\Http;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Http\Controllers\FrontendControllers\ProductReviewFrontendController;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * How long a page of reviews is.
 *
 * Route: GET /public/reviews/{postId}
 *
 * The store's "Reviews per page" used to be a ceiling as well as a default:
 *
 *     $perPage = max(1, min($requested, $settings['reviews_per_page']));
 *
 * So a caller asking for more than the store's number got it on the first,
 * server-rendered page and silently less on every page after — the list
 * changing length under the reader mid-visit. It is a default now, and the cap
 * is a fixed maximum, because per_page arrives from the query string and a
 * request must not be able to ask for the whole review table at once.
 *
 * Spec: Cases/reviews.md — "Review list paging"
 */
class ReviewsPageLengthTest extends WPTestCase
{
    /** @var int */
    private $productId;

    public function setUp(): void
    {
        parent::setUp();

        ModuleSettings::saveSettings([
            'reviews' => [
                'active'           => 'yes',
                'reviews_enabled'  => 'yes',
                'reviews_per_page' => 2,
            ],
        ]);
        ModuleSettings::getAllSettings(false);
        // resetRuntimeCache(), not just the settings: post ids repeat across
        // rolled-back tests, so a per-product answer cached by an earlier Cest
        // can otherwise decide that this Cest's product has reviews off.
        ProductReviewService::resetRuntimeCache();

        $product = (new ProductFactory())->create(['post_title' => 'Paged Widget']);
        $this->productId = (int) $product->ID;

        foreach (range(1, 5) as $index) {
            $review = new ProductReview([
                'post_id'       => $this->productId,
                'reviewer_name' => 'Dana Buyer',
                'review'        => 'Review number ' . $index . '.',
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

    /**
     * @param array $params
     * @return array{0: int, 1: int, 2: int} status, rows returned, last page
     */
    private function page(array $params): array
    {
        // The framework request is a process singleton that merges every REST
        // call's params; reset it (and its 'request' alias) or page=2 from an
        // earlier class sticks.
        $app = \FluentCart\App\App::getInstance();
        $fresh = new \FluentCart\Framework\Http\Request\Request($app, [], []);
        $app->instance(\FluentCart\Framework\Http\Request\Request::class, $fresh);
        $app->instance('request', $fresh);
        if ($app->bound('wprestrequest')) {
            $app->forgetInstance('wprestrequest');
        }
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/public/reviews/' . $this->productId);

        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        $response = rest_do_request($request);
        $body = (array) $response->get_data();

        return [
            $response->get_status(),
            substr_count((string) ($body['reviews_html'] ?? ''), 'class="fct-review-item"'),
            (int) ($body['reviews']['last_page'] ?? 0),
        ];
    }

    /**
     * The case this was written for: the store says two, the caller says four,
     * and four is what comes back.
     */
    public function test_aPageMayBeLongerThanTheStoreSetting(): void
    {
        list($status, $rows, $lastPage) = $this->page(['per_page' => 4]);

        $this->assertSame(200, $status);
        $this->assertSame(4, $rows, 'the caller\'s page length is honoured');
        $this->assertSame(2, $lastPage, 'and the paginator agrees: five reviews, four a page');
    }

    /**
     * A shorter page than the store's is honoured too — the setting is not a
     * floor either.
     */
    public function test_aPageMayBeShorterThanTheStoreSetting(): void
    {
        list(, $rows, $lastPage) = $this->page(['per_page' => 1]);

        $this->assertSame(1, $rows);
        $this->assertSame(5, $lastPage);
    }

    /**
     * per_page comes off the query string, so an absurd one is capped rather
     * than handed to the paginator. Without this a single request pulls every
     * review a product has ever had into one response.
     */
    public function test_anAbsurdPageLengthIsCapped(): void
    {
        $this->assertLessThanOrEqual(
            100,
            ProductReviewFrontendController::MAX_REVIEWS_PER_PAGE,
            'the cap stays a sane size'
        );

        // The public list validates per_page at 50 before the controller's own
        // clamp, so the ceiling a caller meets is the validator's.
        foreach ([100000, 51] as $value) {
            list($status) = $this->page(['per_page' => $value]);
            $this->assertSame(422, $status, $value . ' is refused by validation');
        }

        list($status, $rows) = $this->page(['per_page' => 50]);
        $this->assertSame(200, $status);
        $this->assertSame(5, $rows, 'every review this product has, and no more');
    }

    /**
     * Nothing that is not a positive number becomes a page length: zero and
     * below would reach the paginator as "no limit".
     */
    public function test_aPageLengthThatIsNotOneFallsBackToTheStoreSetting(): void
    {
        // An empty value falls back to the store setting; a negative or
        // non-numeric one fails ReviewRequest's min:1|numeric rule.
        list($status, $rows) = $this->page(['per_page' => 0]);
        $this->assertSame(200, $status, '0');
        $this->assertSame(2, $rows, '0 is not a page length, so the store answers');

        foreach ([-5, 'abc'] as $value) {
            list($status) = $this->page(['per_page' => $value]);
            $this->assertSame(422, $status, var_export($value, true) . ' is refused by validation');
        }
    }

    /**
     * Paging still walks the whole set at the caller's length.
     */
    public function test_theLastPageHoldsWhatIsLeft(): void
    {
        list(, $rows) = $this->page(['per_page' => 4, 'page' => 2]);

        $this->assertSame(1, $rows, 'five reviews, four on page one, one on page two');
    }
}
