<?php

namespace Tests\Functional\Rest\Reviews;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/reviews.md → "Adding a review from the admin", items 1-15.
 *
 * Route: POST /fluent-cart/v2/reviews (meta: reviews/manage)
 * Controller: ProductReviewController::create — validates through
 * Http\Requests\ReviewRequest, assembles the row via
 * ProductReviewService::buildAdminReviewData() and writes through
 * ProductReviewResource::create(). The storefront submission gates
 * (permission mode, duplicate-per-identity, rate limit, slot lock) are
 * deliberately absent on this path, which is what items 3 and 5 pin.
 */
class AddReviewCest
{
    /**
     * Spec: Cases/Functional/reviews.md [1]
     *   "Admin can add a review to a product on behalf of a named guest reviewer."
     */
    public function adminCanAddReviewForNamedGuestReviewer(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();

        [$status, $body] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'        => $product->ID,
            'rating'         => 4,
            'title'          => 'Solid build',
            'content'        => 'Arrived quickly and works as described.',
            'reviewer_name'  => 'Dana Guest',
            'reviewer_email' => 'dana-guest@example.com',
            'status'         => 'approved',
        ]);

        $I->seeResponseCodeIs(200);

        $row = ProductReview::query()->find($body['review']['id']);

        Assert::assertSame((int) $product->ID, (int) $row->post_id, 'review is stored against the chosen product');
        Assert::assertSame('Dana Guest', $row->reviewer_name, 'reviewer name is stored as typed by the admin');
        Assert::assertSame('dana-guest@example.com', $row->reviewer_email, 'reviewer email is stored as typed by the admin');
        Assert::assertSame(4, (int) $row->rating, 'rating is stored as chosen');
        Assert::assertSame('approved', $row->status, 'requested status is stored');
        Assert::assertNull($row->parent_id, 'an admin-added review is a top-level review, not a reply');
        Assert::assertSame(0, (int) $row->is_admin_reply, 'an admin-added review is not flagged as a store reply');
    }

    /**
     * Spec: Cases/Functional/reviews.md [2]
     *   "A review added from the admin is not attributed to the admin's own account."
     */
    public function reviewAddedFromAdminIsNotAttributedToTheAdmin(FunctionalTester $I): void
    {
        $adminId = $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();

        [, $body] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'        => $product->ID,
            'rating'         => 5,
            'content'        => 'Written up by the store owner from a phone call.',
            'reviewer_name'  => 'Phone Caller',
            'reviewer_email' => 'phone-caller@example.com',
        ]);

        $row = ProductReview::query()->find($body['review']['id']);

        Assert::assertNull($row->user_id, 'the review must not carry the acting admin as its author');
        Assert::assertNotSame($adminId, (int) $row->user_id, 'the acting admin is never the reviewer');
        Assert::assertNotSame(
            wp_get_current_user()->user_email,
            $row->reviewer_email,
            'the reviewer email is the typed one, not the admin session email'
        );
    }

    /**
     * Spec: Cases/Functional/reviews.md [3]
     *   "Admin can add a second review for the same product and the same reviewer
     *    email, which the storefront would refuse as a duplicate."
     */
    public function adminCanAddSecondReviewForSameProductAndEmail(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();

        $payload = [
            'post_id'        => $product->ID,
            'rating'         => 3,
            'content'        => 'First pass at this product.',
            'reviewer_name'  => 'Repeat Reviewer',
            'reviewer_email' => 'repeat-reviewer@example.com',
        ];

        $I->post('/fluent-cart/v2/reviews', $payload);
        $I->seeResponseCodeIs(200);

        $payload['content'] = 'Second pass, after the replacement arrived.';
        $I->post('/fluent-cart/v2/reviews', $payload);

        $I->seeResponseCodeIs(200);

        $stored = ProductReview::query()
            ->where('post_id', $product->ID)
            ->where('reviewer_email', 'repeat-reviewer@example.com')
            ->count();

        Assert::assertSame(2, $stored, 'both reviews are stored; the guest duplicate rule does not apply to the admin path');
    }

    /**
     * Spec: Cases/Functional/reviews.md [4]
     *   "Adding a review with an email that belongs to an existing customer links
     *    the review to that customer without granting them the account."
     */
    public function reviewWithKnownCustomerEmailIsLinkedToThatCustomer(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();
        $customer = (new CustomerFactory())->create(['email' => 'known-buyer@example.com']);

        [, $body] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'        => $product->ID,
            'rating'         => 5,
            'content'        => 'Emailed this in after buying.',
            'reviewer_name'  => 'Known Buyer',
            'reviewer_email' => 'known-buyer@example.com',
        ]);

        $row = ProductReview::query()->find($body['review']['id']);

        Assert::assertSame((int) $customer->id, (int) $row->customer_id, 'the matching customer is linked to the review');
        Assert::assertNull($row->user_id, 'linking a customer must not hand the review a WP account that could edit it');
    }

    /**
     * Spec: Cases/Functional/reviews.md [5]
     *   "Admin can add a review while the store only allows verified buyers to review."
     */
    public function adminCanAddReviewWhileStoreAllowsVerifiedBuyersOnly(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();

        $modules = ModuleSettings::getAllSettings();
        $modules['reviews']['review_permission_mode'] = 'verified_buyers';
        ModuleSettings::saveSettings($modules);
        ProductReviewService::resetReviewSettingsCache();

        [, $body] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'        => $product->ID,
            'rating'         => 5,
            'content'        => 'Store owner entered this from a support ticket.',
            'reviewer_name'  => 'Never Bought',
            'reviewer_email' => 'never-bought@example.com',
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertNotEmpty(
            ProductReview::query()->find($body['review']['id']),
            'the verified-buyers permission mode gates the storefront form, not the admin path'
        );

        // The contrast is the point: the same store setting refuses this
        // reviewer on the storefront while the admin route accepts them.
        $storefrontVerdict = ProductReviewService::canSubmitReview(
            $product->ID,
            new \FluentCart\Framework\Http\Request\Request(\FluentCart\App\App::getInstance(), [], [])
        );
        Assert::assertFalse(
            $storefrontVerdict['can_submit'],
            'the storefront gate must actually be refusing, or this test proves nothing'
        );
    }

    /**
     * Spec: Cases/Functional/reviews.md [6]
     *   "Adding a review without a product is rejected."
     */
    public function addingReviewWithoutProductIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        // The form sends the field as "null" when nothing is picked; the
        // message must ask for a product, not talk about numbers.
        [, $body] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'       => 'null',
            'rating'        => 5,
            'content'       => 'Nothing to attach this to.',
            'reviewer_name' => 'No Product',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('post_id', $body, 'the refusal names the field, for the form to print under it');
        Assert::assertContains('Please select a product for this review.', array_values($body['post_id']));
        Assert::assertSame(0, ProductReview::query()->count(), 'a rejected submission writes no row');
    }

    /**
     * Spec: Cases/Functional/reviews.md [7]
     *   "Adding a review without reviewer name is rejected."
     */
    public function addingReviewWithoutReviewerNameIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();

        [, $body] = $I->post('/fluent-cart/v2/reviews', [
            'post_id' => $product->ID,
            'rating'  => 5,
            'content' => 'Anonymous body with no name attached.',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('reviewer_name', $body, 'the refusal names the field, for the form to print under it');
        Assert::assertSame(0, ProductReview::query()->count(), 'a rejected submission writes no row');
    }

    /**
     * Spec: Cases/Functional/reviews.md [8]
     *   "Adding a review with no content is rejected."
     */
    public function addingReviewWithoutContentIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();

        [, $body] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'       => $product->ID,
            'rating'        => 5,
            'content'       => '   ',
            'reviewer_name' => 'Blank Body',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('content', $body, 'the refusal names the field, for the form to print under it');
        Assert::assertSame(0, ProductReview::query()->count(), 'whitespace-only content is not content');
    }

    /**
     * A body that is only markup is empty once sanitized; "required" must see
     * the stored form, not the raw one, or an empty review is saved.
     */
    public function addingReviewWithMarkupOnlyContentIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();

        [, $body] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'       => $product->ID,
            'rating'        => 4,
            'content'       => '<script>alert(1)</script>',
            'reviewer_name' => '<b></b>',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('content', $body, 'markup-only content is not content');
        Assert::assertArrayHasKey('reviewer_name', $body, 'a markup-only name is not a name');
        Assert::assertSame(0, ProductReview::query()->count(), 'a rejected submission writes no row');

        // An array where text belongs is not text either — and must not be
        // cast to the word "Array" and accepted.
        [, $body] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'       => $product->ID,
            'rating'        => 4,
            'content'       => [],
            'reviewer_name' => ['x' => 'y'],
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('content', $body, 'an empty array is not content');
        Assert::assertArrayHasKey('reviewer_name', $body, 'an array is not a name');
        Assert::assertSame(0, ProductReview::query()->count(), 'a rejected submission writes no row');
    }

    /**
     * Spec: Cases/Functional/reviews.md [9]
     *   "Adding a review with a malformed email address is rejected."
     */
    public function addingReviewWithMalformedEmailIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();

        [, $body] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'        => $product->ID,
            'rating'         => 5,
            'content'        => 'Body is fine, address is not.',
            'reviewer_name'  => 'Bad Address',
            'reviewer_email' => 'not-an-email',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('reviewer_email', $body, 'the refusal names the field, for the form to print under it');
        Assert::assertSame(0, ProductReview::query()->count(), 'a rejected submission writes no row');
    }

    /**
     * Spec: Cases/Functional/reviews.md [10]
     *   "Adding a review with a status that is not a review status is rejected."
     */
    public function addingReviewWithUnknownStatusIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();

        [, $body] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'       => $product->ID,
            'rating'        => 5,
            'content'       => 'Body is fine, status is not.',
            'reviewer_name' => 'Bad Status',
            'status'        => 'published',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('status', $body, 'the refusal names the field, for the form to print under it');
        Assert::assertSame(0, ProductReview::query()->count(), 'a rejected submission writes no row');
    }

    /**
     * Spec: Cases/Functional/reviews.md [11]
     *   "Admin can add a review with no email address at all."
     */
    public function adminCanAddReviewWithoutEmailAddress(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();

        [, $body] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'       => $product->ID,
            'rating'        => 5,
            'content'       => 'Dictated over the phone, no address given.',
            'reviewer_name' => 'Anonymous Caller',
        ]);

        $I->seeResponseCodeIs(200);

        $row = ProductReview::query()->find($body['review']['id']);

        Assert::assertSame('', $row->reviewer_email, 'a missing email is stored as empty, not rejected');
        Assert::assertNull($row->customer_id, 'with no email there is no customer to link');
    }

    /**
     * Spec: Cases/Functional/reviews.md [12]
     *   "An approved review added from the admin updates the product's average rating."
     */
    public function approvedAdminReviewUpdatesProductAverageRating(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();

        $I->post('/fluent-cart/v2/reviews', [
            'post_id'       => $product->ID,
            'rating'        => 4,
            'content'       => 'Four stars, entered by the store owner.',
            'reviewer_name' => 'Aggregate Probe',
            'status'        => 'approved',
        ]);

        $I->seeResponseCodeIs(200);

        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();

        Assert::assertSame(
            4.0,
            (float) ($detail->other_info['average_rating'] ?? 0),
            'the cached average rating reflects the approved review'
        );
    }

    /**
     * Spec: Cases/Functional/reviews.md [13]
     *   "Admin can add a review already marked as a verified purchase."
     */
    public function adminCanAddReviewMarkedAsVerifiedPurchase(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();

        [, $body] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'       => $product->ID,
            'rating'        => 5,
            'content'       => 'Bought offline, verified by the store owner.',
            'reviewer_name' => 'Offline Buyer',
            'is_verified'   => 1,
        ]);

        $row = ProductReview::query()->find($body['review']['id']);

        Assert::assertSame(1, (int) $row->is_verified, 'the verified flag chosen by the admin is stored');
    }

    /**
     * Spec: Cases/Functional/reviews.md [19]
     *   "Adding a review without a rating is rejected while the store requires
     *    star ratings."
     */
    public function addingReviewWithoutRatingIsRejectedWhileStoreRequiresThem(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();

        $modules = ModuleSettings::getAllSettings();
        $modules['reviews']['enable_star_rating'] = 'yes';
        $modules['reviews']['star_rating_required'] = 'yes';
        ModuleSettings::saveSettings($modules);
        ProductReviewService::resetReviewSettingsCache();

        $I->post('/fluent-cart/v2/reviews', [
            'post_id'       => $product->ID,
            'rating'        => 0,
            'content'       => 'No stars given.',
            'reviewer_name' => 'Starless',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertSame(0, ProductReview::query()->count(), 'a rejected submission writes no row');
    }

    /**
     * Spec: Cases/Functional/reviews.md [20]
     *   "Adding a review without a rating is allowed while the store treats star
     *    ratings as optional."
     */
    public function addingReviewWithoutRatingIsAllowedWhileStoreTreatsThemAsOptional(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();

        $modules = ModuleSettings::getAllSettings();
        $modules['reviews']['enable_star_rating'] = 'yes';
        $modules['reviews']['star_rating_required'] = 'no';
        ModuleSettings::saveSettings($modules);
        ProductReviewService::resetReviewSettingsCache();

        [, $body] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'       => $product->ID,
            'rating'        => 0,
            'content'       => 'Words only, the store does not insist on stars.',
            'reviewer_name' => 'Words Only',
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertSame(
            0,
            (int) ProductReview::query()->find($body['review']['id'])->rating,
            'the review is stored unrated rather than given a rating the admin never chose'
        );
    }

    /**
     * Spec: Cases/Functional/reviews.md [21]
     *   "A review added from the admin runs the same submission extension points
     *    a storefront review does, so extensions do not have to be wired twice."
     */
    public function adminAddedReviewRunsTheStorefrontSubmissionHooks(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();

        $seen = ['filter' => null, 'action' => null];

        add_filter('fluent_cart/review/submit_data', function ($data, $request, $postId) use (&$seen) {
            $seen['filter'] = ['post_id' => $postId, 'has_request' => is_object($request)];
            $data['meta'] = ['pending_media_ids' => [4242]];

            return $data;
        }, 10, 3);

        add_action('fluent_cart/review/after_submit', function ($review, $request) use (&$seen) {
            $seen['action'] = ['review_id' => (int) $review->id, 'has_request' => is_object($request)];
        }, 10, 2);

        [, $body] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'       => $product->ID,
            'rating'        => 5,
            'content'       => 'Extension points must fire on this path too.',
            'reviewer_name' => 'Hook Probe',
            'media_ids'     => [4242],
        ]);

        remove_all_filters('fluent_cart/review/submit_data');
        remove_all_actions('fluent_cart/review/after_submit');

        $I->seeResponseCodeIs(200);

        Assert::assertSame(
            ['post_id' => (int) $product->ID, 'has_request' => true],
            $seen['filter'],
            'submit_data must fire with the product id and the request, the shape its listeners register for'
        );
        Assert::assertSame(
            ['review_id' => (int) $body['review']['id'], 'has_request' => true],
            $seen['action'],
            'after_submit must fire with the saved review and the request'
        );

        // The media pipeline reads its pending ids back off the stored row,
        // so what the filter wrote has to survive the create.
        $row = ProductReview::query()->find($body['review']['id']);
        Assert::assertSame(
            [4242],
            $row->meta['pending_media_ids'] ?? null,
            'meta written by a submit_data listener is persisted for the after_submit listener to read'
        );
    }

    /**
     * Spec: Cases/Functional/reviews.md [22]
     *   "A submission extension cannot rewrite who a review belongs to, its
     *    status, or its verified mark."
     */
    public function submissionFilterCannotRewriteTrustFields(FunctionalTester $I): void
    {
        $adminId = $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();
        $otherProduct = (new ProductFactory())->create();

        add_filter('fluent_cart/review/submit_data', function ($data) use ($adminId, $otherProduct) {
            $data['status'] = 'spam';
            $data['is_verified'] = 1;
            $data['user_id'] = $adminId;
            $data['post_id'] = $otherProduct->ID;
            $data['is_admin_reply'] = 1;

            return $data;
        }, 10, 3);

        [, $body] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'       => $product->ID,
            'rating'        => 5,
            'content'       => 'A hostile filter must not be able to move this row.',
            'reviewer_name' => 'Trust Probe',
            'status'        => 'approved',
        ]);

        remove_all_filters('fluent_cart/review/submit_data');

        $I->seeResponseCodeIs(200);

        $row = ProductReview::query()->find($body['review']['id']);

        Assert::assertSame('approved', $row->status, 'the status the admin chose survives the filter');
        Assert::assertSame(0, (int) $row->is_verified, 'the filter cannot award a verified badge');
        Assert::assertNull($row->user_id, 'the filter cannot attribute the review to an account');
        Assert::assertSame((int) $product->ID, (int) $row->post_id, 'the filter cannot move the review to another product');
        Assert::assertSame(0, (int) $row->is_admin_reply, 'the filter cannot turn a review into a store reply');
    }

    /**
     * Spec: Cases/Functional/reviews.md [31]
     *   "A title longer than the column can hold is rejected rather than stored
     *    truncated."
     */
    public function titleLongerThanTheColumnIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();

        // fct_product_reviews.title is VARCHAR(192).
        $I->post('/fluent-cart/v2/reviews', [
            'post_id'       => $product->ID,
            'rating'        => 5,
            'title'         => str_repeat('a', 193),
            'content'       => 'The title is one character too long for the column.',
            'reviewer_name' => 'Long Title',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertSame(0, ProductReview::query()->count(), 'nothing is written when the title cannot fit');
    }

    /**
     * Spec: Cases/Functional/reviews.md [32]
     *   "An email address longer than the column can hold is rejected rather
     *    than stored truncated."
     */
    public function reviewerEmailLongerThanTheColumnIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();

        // fct_product_reviews.reviewer_email is VARCHAR(192). A truncated
        // address is worse than a refused one: it silently becomes a different
        // person's identity for the duplicate and customer-matching queries.
        $local = str_repeat('a', 190);

        $I->post('/fluent-cart/v2/reviews', [
            'post_id'        => $product->ID,
            'rating'         => 5,
            'content'        => 'The address is longer than the column.',
            'reviewer_name'  => 'Long Email',
            'reviewer_email' => $local . '@example.com',
        ]);

        $I->seeResponseCodeIs(422);
        Assert::assertSame(0, ProductReview::query()->count(), 'nothing is written when the address cannot fit');
    }

    /**
     * Spec: Cases/Functional/reviews.md [33]
     *   "A title and email that exactly fill the column are accepted."
     */
    public function titleAndEmailAtTheColumnLimitAreAccepted(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();

        $title = str_repeat('a', 192);
        $email = str_repeat('b', 180) . '@example.com'; // 192 exactly

        [, $body] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'        => $product->ID,
            'rating'         => 5,
            'title'          => $title,
            'content'        => 'Exactly at the limit, which must still be allowed.',
            'reviewer_name'  => 'Exact Fit',
            'reviewer_email' => $email,
        ]);

        $I->seeResponseCodeIs(200);

        $row = ProductReview::query()->find($body['review']['id']);

        Assert::assertSame($title, $row->title, 'a title at the limit is stored whole, not truncated');
        Assert::assertSame($email, $row->reviewer_email, 'an address at the limit is stored whole, not truncated');
    }

    /**
     * Spec: Cases/Functional/reviews.md [14]
     *   "A logged-out visitor cannot add a review from the admin endpoint."
     */
    public function loggedOutVisitorCannotAddReviewFromAdminEndpoint(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create();
        $I->logout();

        [$status] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'       => $product->ID,
            'rating'        => 5,
            'content'       => 'Should never land.',
            'reviewer_name' => 'Anonymous',
        ]);

        Assert::assertSame(401, $status, 'the admin add-review route rejects anonymous callers');
        Assert::assertSame(0, ProductReview::query()->count(), 'a denied caller writes no row');
    }

    /**
     * Spec: Cases/Functional/reviews.md [15]
     *   "A worker without the manage-reviews permission cannot add a review
     *    from the admin endpoint."
     */
    public function workerWithoutManageReviewsPermissionCannotAddReview(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create();
        $I->loginAsWorker();

        [$status] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'       => $product->ID,
            'rating'        => 5,
            'content'       => 'Should never land.',
            'reviewer_name' => 'Worker Attempt',
        ]);

        Assert::assertSame(403, $status, 'reviews/manage is required to add a review');
        Assert::assertSame(0, ProductReview::query()->count(), 'a denied caller writes no row');
    }
}
