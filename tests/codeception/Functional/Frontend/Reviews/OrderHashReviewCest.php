<?php

namespace Tests\Functional\Frontend\Reviews;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\App\Services\Renderer\OrderReviewRenderer;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\WPUserFactory;
use Tests\Support\FunctionalTester;

/**
 * Submitting a review through the public order-review page, as the guest that
 * page is built for.
 *
 * Spec: Cases/reviews.md → "Reviewing from an order link" section.
 */
class OrderHashReviewCest
{
    public function _before(FunctionalTester $I): void
    {
        // verified_buyers is the shipped default and the mode the order hash
        // has to work around; pinned so the cases do not depend on store state.
        ModuleSettings::saveSettings([
            'reviews' => [
                'active'                 => 'yes',
                'review_permission_mode' => Status::REVIEW_PERMISSION_VERIFIED_BUYERS,
                'auto_approve_reviews'   => 'no',
            ],
        ]);
        ModuleSettings::getAllSettings(false);
        ProductReviewService::resetRuntimeCache();

        $I->logout();
    }

    public function _after(FunctionalTester $I): void
    {
        ProductReviewService::resetRuntimeCache();
        remove_all_filters('fluent_cart/review/submit_response');
    }

    /**
     * Spec: Cases/reviews.md [10]
     *   "A logged-out visitor holding an order hash can submit a review for a product on that order."
     */
    public function loggedOutVisitorWithOrderHashCanSubmitReview(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create();
        $product = (new ProductFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $product->ID]);

        [$status, $body] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'         => 5,
            'title'          => 'Exactly what I wanted',
            'content'        => 'Arrived quickly and works as described.',
            'order_hash'     => $order->uuid,
            'reviewer_name'  => 'Ignored Name',
            'reviewer_email' => 'ignored@example.com',
        ]);

        Assert::assertSame(200, $status, 'The order hash must let a guest through. Body: ' . wp_json_encode($body));

        $review = ProductReview::query()->where('post_id', $product->ID)->first();
        Assert::assertNotNull($review, 'The submission must actually persist a review row');
        Assert::assertSame('Exactly what I wanted', $review->title, 'The review keeps the submitted title');

        // The form turns itself into the edit form from this answer, so it
        // carries the saved review and the photos on it (none here).
        Assert::assertSame((int) $review->id, (int) $body['review']['id'], 'the response names the saved review');
        Assert::assertSame([], $body['media'], 'and lists its photos, an empty list without any');
    }

    /**
     * An extension that stored the photos sent with the request reports on
     * them through the response, so the form can tell the reviewer what
     * happened to each.
     */
    public function anExtensionCanReportOnTheSubmissionInTheResponse(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create();
        $product = (new ProductFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $product->ID]);

        $seen = null;
        add_filter('fluent_cart/review/submit_response', function ($payload, $review, $request, $isUpdate) use (&$seen) {
            $seen = ['review_id' => (int) $review->id, 'is_update' => $isUpdate];
            $payload['media_rejected'] = [['name' => 'blurry.png', 'reason' => 'type']];
            $payload['message'] .= ' One photo was not saved.';

            return $payload;
        }, 10, 4);

        [$status, $body] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'     => 4,
            'content'    => 'The photo did not make it, the words did.',
            'order_hash' => $order->uuid,
        ]);

        Assert::assertSame(200, $status, wp_json_encode($body));
        Assert::assertSame((int) $body['review']['id'], $seen['review_id'], 'the filter sees the saved review');
        Assert::assertFalse($seen['is_update'], 'and knows this was a new review');
        Assert::assertSame([['name' => 'blurry.png', 'reason' => 'type']], $body['media_rejected'], 'what it adds reaches the form');
        Assert::assertStringEndsWith('One photo was not saved.', $body['message'], 'and so does what it says');
    }

    /**
     * Spec: Cases/reviews.md [11]
     *   "A review submitted through an order link is published under the order customer's name, not the name the visitor typed."
     */
    public function reviewIdentityComesFromTheOrderNotTheRequest(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create([
            'first_name' => 'Real',
            'last_name'  => 'Buyer',
            'email'      => 'real-buyer@example.com',
        ]);
        $product = (new ProductFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $product->ID]);

        [$status] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'         => 4,
            'content'        => 'Posting with someone else\'s details on purpose.',
            'order_hash'     => $order->uuid,
            'reviewer_name'  => 'Impostor',
            'reviewer_email' => 'impostor@evil.test',
        ]);

        Assert::assertSame(200, $status, 'The submission itself is legitimate; only the identity is overridden');

        $review = ProductReview::query()->where('post_id', $product->ID)->first();
        Assert::assertNotNull($review, 'The review must exist to be checked');
        Assert::assertSame('Real Buyer', $review->reviewer_name, 'The typed name must not reach the database');
        Assert::assertSame('real-buyer@example.com', $review->reviewer_email, 'The typed email must not reach the database');
        Assert::assertSame((int) $customer->id, (int) $review->customer_id, 'The review is attributed to the order customer');
        Assert::assertSame((int) $order->id, (int) $review->order_id, 'The review records the order that authorised it');
    }

    /**
     * Spec: Cases/reviews.md [12]
     *   "A logged-out visitor with no order hash cannot submit a review in verified buyers mode."
     */
    public function loggedOutVisitorWithoutOrderHashIsRejected(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create();

        [$status] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'         => 5,
            'content'        => 'No proof of purchase attached.',
            'reviewer_name'  => 'Passer By',
            'reviewer_email' => 'passer-by@example.com',
        ]);

        Assert::assertSame(403, $status, 'Without a hash the permission mode must still refuse a guest');
        Assert::assertSame(
            0,
            ProductReview::query()->where('post_id', $product->ID)->count(),
            'A refused submission must write nothing'
        );
    }

    /**
     * Spec: Cases/reviews.md [13]
     *   "An order hash cannot be used to review a product that order does not contain."
     */
    public function orderHashDoesNotUnlockAProductAbsentFromThatOrder(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create();
        $bought = (new ProductFactory())->create();
        $notBought = (new ProductFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $bought->ID]);

        [$status] = $I->post('/fluent-cart/v2/public/reviews/' . $notBought->ID, [
            'rating'         => 5,
            'content'        => 'Trying to review something I never bought.',
            'order_hash'     => $order->uuid,
            'reviewer_name'  => 'Opportunist',
            'reviewer_email' => 'opportunist@example.com',
        ]);

        Assert::assertSame(403, $status, 'A hash is scoped to its own order, not to the catalogue');
        Assert::assertSame(
            0,
            ProductReview::query()->where('post_id', $notBought->ID)->count(),
            'The out-of-scope product must gain no review'
        );
    }

    /**
     * Spec: Cases/reviews.md [16b]
     *   "A grant whose customer record is gone still allows only one review per product, whatever email is typed, and records the order."
     */
    public function grantWithNoCustomerStillHoldsOneSlotPerProduct(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create();

        // Same shape as case 16: build the order with a customer, then take
        // the customer away, as a deleted customer row would.
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $product->ID]);

        $order->customer_id = null;
        $order->save();
        $customer->delete();

        [$first] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'         => 5,
            'content'        => 'Typed identity, first address.',
            'order_hash'     => $order->uuid,
            'reviewer_name'  => 'First Name',
            'reviewer_email' => 'first-address@example.com',
        ]);
        Assert::assertSame(200, $first, 'The typed identity is accepted when the order has no customer');

        $review = ProductReview::query()->where('post_id', $product->ID)->first();
        Assert::assertNotNull($review);
        Assert::assertSame((int) $order->id, (int) $review->order_id, 'The authorising order is recorded even without a customer');

        ProductReviewService::resetRuntimeCache();

        [$second] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'         => 1,
            'content'        => 'Same link, a different address.',
            'order_hash'     => $order->uuid,
            'reviewer_name'  => 'Second Name',
            'reviewer_email' => 'second-address@example.com',
        ]);

        Assert::assertSame(403, $second, 'The slot is the order, not the typed address');
        Assert::assertSame(1, ProductReview::query()->where('post_id', $product->ID)->count());

        // Reopening the link must show the row as reviewed, not offer a form
        // the guard would refuse: the page's reviewed state keys on the order
        // exactly as the guard does.
        ProductReviewService::resetRuntimeCache();
        ob_start();
        (new OrderReviewRenderer($order->uuid))->render();
        $page = (string) ob_get_clean();

        Assert::assertStringNotContainsString('data-order-review-pending', $page, 'No pending row is offered once the order has reviewed the product');
        Assert::assertStringContainsString('fct-order-review-item-note', $page, 'The reviewed state is rendered instead');
    }

    /**
     * Spec: Cases/reviews.md [17]
     *   "A customer can review every product on a large order in one sitting, past the per-IP submission cap."
     */
    public function aGrantIsNotSubjectToThePerIpSubmissionCap(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create([
            'customer_id' => $customer->id,
            'status'      => Status::ORDER_COMPLETED,
        ]);

        // Six products — one more than the five-per-hour cap allows.
        $products = [];
        for ($i = 0; $i < 6; $i++) {
            $product = (new ProductFactory())->create();
            (new OrderItemFactory())->create($order->id, ['post_id' => $product->ID]);
            $products[] = $product;
        }

        foreach ($products as $index => $product) {
            [$status] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
                'rating'     => 5,
                'content'    => 'Review number ' . ($index + 1) . ' of six on one order.',
                'order_hash' => $order->uuid,
            ]);

            Assert::assertSame(
                200,
                $status,
                'Review ' . ($index + 1) . ' of 6 on one order must be accepted; the cap must not apply to a grant'
            );
        }

        Assert::assertSame(
            6,
            ProductReview::query()->where('order_id', $order->id)->count(),
            'All six reviews must have persisted'
        );
    }

    /**
     * Spec: Cases/reviews.md [18]
     *   "Submissions with no order link are still capped per IP."
     */
    public function submissionsWithoutAGrantAreStillCapped(FunctionalTester $I): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.44';

        // Without a grant these are refused on permission grounds (403) until
        // the cap trips, at which point the cap answers first (429). Seeing the
        // 429 at all is what proves the limiter is still armed for everyone
        // else — the exemption above is not a blanket removal.
        $sawRateLimit = false;
        for ($i = 0; $i < 7; $i++) {
            $product = (new ProductFactory())->create();
            [$status] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
                'rating'         => 5,
                'content'        => 'Ungranted submission number ' . ($i + 1) . '.',
                'reviewer_name'  => 'Passer By',
                'reviewer_email' => 'passer-' . $i . '@example.com',
            ]);

            if ($status === 429) {
                $sawRateLimit = true;
                break;
            }
        }

        Assert::assertTrue($sawRateLimit, 'The per-IP cap must still fire for submissions carrying no order grant');
    }

    /**
     * Spec: Cases/reviews.md [15]
     *   "A forwarded order link does not let a signed-in stranger publish the review under their own name."
     */
    public function forwardedHashDoesNotCreditTheReviewToWhoeverOpensIt(FunctionalTester $I): void
    {
        $buyer = (new CustomerFactory())->create([
            'first_name' => 'Actual',
            'last_name'  => 'Buyer',
            'email'      => 'actual-buyer@example.com',
        ]);
        $product = (new ProductFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $buyer->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $product->ID]);

        // Someone else entirely, signed in to their own account, opening a
        // review link that was forwarded to them.
        $strangerId = WPUserFactory::create(['user_email' => 'stranger@example.com']);
        wp_set_current_user($strangerId);

        [$status] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'     => 1,
            'content'    => 'Reviewing a product I never bought, from a forwarded link.',
            'order_hash' => $order->uuid,
        ]);

        wp_set_current_user(0);

        $review = ProductReview::query()->where('post_id', $product->ID)->first();

        Assert::assertSame(200, $status, 'The link is the buyer\'s credential: the review is accepted, as the buyer\'s');
        Assert::assertNotNull($review, 'An accepted submission must have persisted');
        Assert::assertSame(
            'actual-buyer@example.com',
            $review->reviewer_email,
            'The review must be attributed to the buyer, never to whoever opened the link'
        );
        Assert::assertNotSame(
            $strangerId,
            (int) $review->user_id,
            'A signed-in stranger must not have the review credited to their account'
        );
        Assert::assertSame((int) $buyer->id, (int) $review->customer_id, 'The row carries the buyer\'s customer record');
        Assert::assertSame(
            (int) $order->id,
            (int) $review->order_id,
            'A grant-authorised review records the order that authorised it'
        );
    }

    /**
     * Spec: Cases/reviews.md [15d]
     *   "The buyer signed in to their own account keeps their own identity through their order link, and the order is still recorded."
     */
    public function buyerSignedInKeepsTheirOwnIdentityThroughTheLink(FunctionalTester $I): void
    {
        $buyerUserId = WPUserFactory::create(['user_email' => 'signed-in-buyer@example.com']);
        $buyer = (new CustomerFactory())->create([
            'first_name' => 'Signed',
            'last_name'  => 'In',
            'email'      => 'signed-in-buyer@example.com',
        ]);
        $buyer->user_id = $buyerUserId;
        $buyer->save();
        $product = (new ProductFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $buyer->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $product->ID]);

        wp_set_current_user($buyerUserId);
        ProductReviewService::resetRuntimeCache();

        // Their own form, not a "Posting as" byline: it is their account.
        ob_start();
        (new OrderReviewRenderer($order->uuid))->render();
        $page = (string) ob_get_clean();
        Assert::assertStringContainsString('data-order-review-pending', $page);
        Assert::assertStringNotContainsString('fct-review-posting-as', $page, 'The buyer signed in posts as themselves');

        [$status] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'     => 5,
            'content'    => 'Reviewing my own order, signed in.',
            'order_hash' => $order->uuid,
        ]);
        Assert::assertSame(200, $status);

        $review = ProductReview::query()->where('post_id', $product->ID)->first();
        Assert::assertNotNull($review);
        Assert::assertSame($buyerUserId, (int) $review->user_id, 'Filed under the buyer\'s own account, so they can edit it');
        Assert::assertSame((int) $buyer->id, (int) $review->customer_id);
        Assert::assertSame((int) $order->id, (int) $review->order_id, 'The authorising order is still recorded');
        Assert::assertSame(1, (int) $review->is_verified);

        // And reopening the link now offers the edit flow, not a second form.
        ProductReviewService::resetRuntimeCache();
        ob_start();
        (new OrderReviewRenderer($order->uuid))->render();
        $page = (string) ob_get_clean();
        wp_set_current_user(0);

        Assert::assertStringNotContainsString('data-order-review-pending', $page, 'The product reads as reviewed');
    }

    /**
     * Spec: Cases/reviews.md [15e]
     *   "A valid order link does not override reviews switched off for one of its products."
     */
    public function grantDoesNotOverrideAProductWithReviewsOff(FunctionalTester $I): void
    {
        $buyer = (new CustomerFactory())->create();
        $off = (new ProductFactory())->withDetailAttributes(['other_info' => ['reviews_enabled' => 'no']])->create();
        $on = (new ProductFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $buyer->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $off->ID]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $on->ID]);

        ob_start();
        (new OrderReviewRenderer($order->uuid))->render();
        $page = (string) ob_get_clean();

        Assert::assertStringContainsString('Reviews are turned off for this product.', $page, 'The row says so instead of offering a form');
        Assert::assertStringContainsString('data-post-id="' . $on->ID . '"', $page, 'The other product is still offered');
        Assert::assertStringNotContainsString('data-post-id="' . $off->ID . '"', $page, 'No form for the product with reviews off');

        [$status] = $I->post('/fluent-cart/v2/public/reviews/' . $off->ID, [
            'rating'     => 5,
            'content'    => 'Trying anyway, with a valid link.',
            'order_hash' => $order->uuid,
        ]);

        Assert::assertSame(403, $status, 'The link does not switch reviews back on');
        Assert::assertSame(0, ProductReview::query()->where('post_id', $off->ID)->count());
    }

    /**
     * Spec: Cases/reviews.md [15b]
     *   "A forwarded order link opened by a signed-in stranger yields at most one review for the buyer, however often it is reopened."
     */
    public function forwardedHashCannotInsertASecondReviewUnderTheBuyer(FunctionalTester $I): void
    {
        $buyer = (new CustomerFactory())->create(['email' => 'once-only-buyer@example.com']);
        $product = (new ProductFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $buyer->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $product->ID]);

        $strangerId = WPUserFactory::create(['user_email' => 'reopening-stranger@example.com']);
        wp_set_current_user($strangerId);

        [$first] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'     => 5,
            'content'    => 'First submission from a forwarded link.',
            'order_hash' => $order->uuid,
        ]);
        Assert::assertSame(200, $first, 'The first submission through the link is the buyer\'s one review');

        ProductReviewService::resetRuntimeCache();

        [$second] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'     => 1,
            'content'    => 'Reopening the same link and submitting again.',
            'order_hash' => $order->uuid,
        ]);

        wp_set_current_user(0);

        Assert::assertSame(403, $second, 'The duplicate guard must look for the buyer\'s review, not the stranger\'s');
        Assert::assertSame(
            1,
            ProductReview::query()->where('post_id', $product->ID)->count(),
            'A forwarded link yields exactly one review under the buyer, however often it is reopened'
        );
    }

    /**
     * Spec: Cases/reviews.md [15c]
     *   "A forwarded order link opened by a signed-in stranger who already reviewed the product offers the buyer a fresh form, not the stranger's edit form."
     */
    public function forwardedHashDoesNotOpenTheStrangersOwnReviewForEditing(FunctionalTester $I): void
    {
        $buyer = (new CustomerFactory())->create(['email' => 'unreviewed-buyer@example.com']);
        $product = (new ProductFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $buyer->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $product->ID]);

        // A stranger who reviewed this product on their own account earlier.
        $strangerId = WPUserFactory::create(['user_email' => 'reviewed-stranger@example.com']);
        $review = new ProductReview();
        $review->post_id = $product->ID;
        $review->user_id = $strangerId;
        $review->reviewer_name = 'Reviewed Stranger';
        $review->reviewer_email = 'reviewed-stranger@example.com';
        $review->rating = 4;
        $review->content = 'My own review, on my own account.';
        $review->status = Status::REVIEW_APPROVED;
        $review->save();

        wp_set_current_user($strangerId);
        ProductReviewService::resetRuntimeCache();

        ob_start();
        (new OrderReviewRenderer($order->uuid))->render();
        $page = (string) ob_get_clean();

        wp_set_current_user(0);

        // The buyer has not reviewed, so the row is offered — and the form it
        // carries posts as the buyer. Seeding it with the stranger's review
        // would make ReviewForm.js PUT the stranger's own review under a
        // "Posting as" byline naming the buyer.
        Assert::assertStringContainsString('data-order-review-pending', $page, 'The buyer has not reviewed, so the row must be offered');
        Assert::assertStringContainsString('fct-review-posting-as', $page, 'The form posts as the buyer');
        Assert::assertStringNotContainsString('data-edit-mode="1"', $page, 'The buyer\'s form must not open the stranger\'s review for editing');
        Assert::assertStringNotContainsString('My own review, on my own account.', $page, 'The stranger\'s review text must not be seeded into the buyer\'s form');
    }

    /**
     * Spec: Cases/reviews.md [16]
     *   "An order whose customer record is gone still offers a usable form rather than an unsubmittable one."
     */
    public function grantWithNoCustomerFallsBackToTheTypedIdentity(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create();

        // customer_id is nullable on fct_orders, and the customer row can be
        // deleted after the order was placed. The factory insists on one, so
        // build the order normally and then take the customer away.
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $product->ID]);

        $order->customer_id = null;
        $order->save();
        $customer->delete();

        [$status, $body] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'         => 4,
            'content'        => 'The order has no customer row left, so I typed my details.',
            'order_hash'     => $order->uuid,
            'reviewer_name'  => 'Typed Name',
            'reviewer_email' => 'typed@example.com',
        ]);

        Assert::assertSame(200, $status, 'A customerless grant must not produce an unsubmittable form. Body: ' . wp_json_encode($body));

        $review = ProductReview::query()->where('post_id', $product->ID)->first();
        Assert::assertNotNull($review, 'The submission must persist');
        Assert::assertSame('Typed Name', $review->reviewer_name, 'With no customer to read, the typed name is used');
        Assert::assertSame('typed@example.com', $review->reviewer_email, 'With no customer to read, the typed email is used');
    }

    /**
     * Spec: Cases/reviews.md [16d]
     *   "A guest buyer whose address changed since their first review still gets one review per product through a later order link."
     */
    public function guestBuyerWithAChangedEmailStillHoldsOneSlot(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create();

        // The factory always attaches a WP user; a guest checkout has none.
        $guest = (new CustomerFactory())->create(['email' => 'guest-then@example.com']);
        $guest->user_id = null;
        $guest->save();

        $orderA = (new OrderFactory())->create(['customer_id' => $guest->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($orderA->id, ['post_id' => $product->ID]);

        [$first] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'     => 5,
            'content'    => 'Reviewed from my first order, under my old address.',
            'order_hash' => $orderA->uuid,
        ]);
        Assert::assertSame(200, $first);

        $review = ProductReview::query()->where('post_id', $product->ID)->first();
        Assert::assertNotNull($review);
        Assert::assertSame('guest-then@example.com', $review->reviewer_email);
        Assert::assertSame((int) $guest->id, (int) $review->customer_id, 'The row carries the customer, not only the address');

        // The address changes, and a second completed order for the same
        // product hands out a second link.
        $guest->email = 'guest-now@example.com';
        $guest->save();
        $orderB = (new OrderFactory())->create(['customer_id' => $guest->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($orderB->id, ['post_id' => $product->ID]);
        ProductReviewService::resetRuntimeCache();

        [$second] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'     => 1,
            'content'    => 'Same buyer, new address, second link.',
            'order_hash' => $orderB->uuid,
        ]);

        Assert::assertSame(403, $second, 'The customer row outlives its address: the slot is still taken');
        Assert::assertSame(1, ProductReview::query()->where('post_id', $product->ID)->count());

        // And the page from the second link reads the product as reviewed.
        ProductReviewService::resetRuntimeCache();
        ob_start();
        (new OrderReviewRenderer($orderB->uuid))->render();
        $page = (string) ob_get_clean();

        Assert::assertStringNotContainsString('data-order-review-pending', $page, 'No form is offered for a product this buyer already reviewed');
        Assert::assertStringContainsString('fct-order-review-item-note', $page);
    }

    /**
     * Spec: Cases/reviews.md [16e]
     *   "A customerless order link opened by a signed-in visitor files the review under nobody's account, from the typed fields."
     */
    public function customerlessLinkOpenedSignedInTakesTypedFieldsAndOwnsNoAccount(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create();

        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $product->ID]);
        $order->customer_id = null;
        $order->save();
        $customer->delete();

        // A visitor signed in to an unrelated account, with a customer row of
        // their own, opens the link.
        $visitorId = WPUserFactory::create(['user_email' => 'unrelated-visitor@example.com']);
        $visitorCustomer = (new CustomerFactory())->create(['email' => 'unrelated-visitor@example.com', 'user_id' => $visitorId]);
        wp_set_current_user($visitorId);
        ProductReviewService::resetRuntimeCache();

        // The page offers the typed fields, not the visitor's account, and no
        // "Posting as" byline — there is nobody to post as.
        ob_start();
        (new OrderReviewRenderer($order->uuid))->render();
        $page = (string) ob_get_clean();

        Assert::assertStringContainsString('data-order-review-pending', $page);
        Assert::assertStringContainsString('type="text" id="fct-review-name-', $page, 'The name is typed, even signed in');
        Assert::assertStringNotContainsString('fct-review-posting-as', $page);
        Assert::assertStringNotContainsString('type="hidden" name="reviewer_email"', $page, 'The visitor\'s own address is not offered');

        [$status] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'         => 4,
            'content'        => 'Typed in while signed in to an account that never bought this.',
            'order_hash'     => $order->uuid,
            'reviewer_name'  => 'The Buyer',
            'reviewer_email' => 'the-buyer@example.com',
        ]);

        wp_set_current_user(0);

        Assert::assertSame(200, $status);
        $review = ProductReview::query()->where('post_id', $product->ID)->first();
        Assert::assertNotNull($review);
        Assert::assertSame('the-buyer@example.com', $review->reviewer_email);
        Assert::assertSame(0, (int) $review->user_id, 'The signed-in visitor does not own the buyer\'s review');
        Assert::assertNull($review->customer_id, 'Nor does their customer row');
        Assert::assertSame((int) $order->id, (int) $review->order_id);
        Assert::assertSame(0, (int) $review->is_verified);
        Assert::assertNotSame((int) $visitorCustomer->id, (int) $review->customer_id);
    }

    /**
     * Spec: Cases/reviews.md [38]
     *   "The order-review page opens each product's form with every field at once, not as a wizard."
     */
    public function theOrderReviewFormIsInlineNotStepped(FunctionalTester $I): void
    {
        $buyer = (new CustomerFactory())->create();
        $product = (new ProductFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $buyer->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $product->ID]);

        ob_start();
        (new OrderReviewRenderer($order->uuid))->render();
        $page = (string) ob_get_clean();

        Assert::assertStringContainsString('data-order-review-pending', $page);
        Assert::assertStringContainsString('data-layout="inline"', $page, 'The form shows every field at once');
        Assert::assertStringNotContainsString('data-layout="steps"', $page);
    }

    /**
     * Spec: Cases/reviews.md [14]
     *   "A second review through the same order link is refused even with a different email."
     */
    public function secondSubmissionIsRefusedEvenWithADifferentEmail(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'one-review@example.com']);
        $product = (new ProductFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $product->ID]);

        [$firstStatus] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'     => 5,
            'content'    => 'My one and only review of this product.',
            'order_hash' => $order->uuid,
        ]);
        Assert::assertSame(200, $firstStatus, 'The first review must be accepted');

        ProductReviewService::resetRuntimeCache();

        [$secondStatus] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'         => 1,
            'content'        => 'Second bite at the apple under a new address.',
            'order_hash'     => $order->uuid,
            'reviewer_email' => 'brand-new-address@example.com',
        ]);

        Assert::assertSame(403, $secondStatus, 'A fresh email must not open a second review slot');
        Assert::assertSame(
            1,
            ProductReview::query()->where('post_id', $product->ID)->count(),
            'Exactly one review may exist for this order and product'
        );
    }

    // ─── Item-level reviews from an order link ───────────────────────────

    /**
     * A variable product with two items, both bought on one completed order.
     *
     * @return array{product: \FluentCart\App\Models\Product, red: \FluentCart\App\Models\ProductVariation, blue: \FluentCart\App\Models\ProductVariation, order: \FluentCart\App\Models\Order}
     */
    private function orderWithTwoItems(array $bought = ['Red', 'Blue']): array
    {
        $customer = (new CustomerFactory())->create();
        $product = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([
                ['variation_title' => 'Red'],
                ['variation_title' => 'Blue'],
            ]);
        $red = $product->variants->firstWhere('variation_title', 'Red');
        $blue = $product->variants->firstWhere('variation_title', 'Blue');

        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED]);
        foreach ($bought as $title) {
            $variant = $title === 'Red' ? $red : $blue;
            (new OrderItemFactory())->create($order->id, [
                'post_id'   => $product->ID,
                'object_id' => $variant->id,
                'title'     => $title,
            ]);
        }

        return ['product' => $product, 'red' => $red, 'blue' => $blue, 'order' => $order];
    }

    /**
     * Spec: Cases/reviews.md [24]
     *   "A buyer of two items of one product can review each item once through the order link."
     */
    public function eachItemOnTheOrderTakesItsOwnReview(FunctionalTester $I): void
    {
        ['product' => $product, 'red' => $red, 'blue' => $blue, 'order' => $order] = $this->orderWithTwoItems();

        [$redStatus, $redBody] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'     => 5,
            'content'    => 'The red one is great.',
            'order_hash' => $order->uuid,
            'item_id'    => $red->id,
        ]);
        Assert::assertSame(200, $redStatus, 'Red must be accepted. Body: ' . wp_json_encode($redBody));

        ProductReviewService::resetRuntimeCache();

        [$blueStatus, $blueBody] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'     => 2,
            'content'    => 'The blue one, less so.',
            'order_hash' => $order->uuid,
            'item_id'    => $blue->id,
        ]);
        Assert::assertSame(200, $blueStatus, 'Blue is a separate slot and must be accepted. Body: ' . wp_json_encode($blueBody));

        $itemIds = ProductReview::query()->where('post_id', $product->ID)->pluck('item_id')->map('intval')->sort()->values()->all();
        $expected = [(int) $red->id, (int) $blue->id];
        sort($expected);
        Assert::assertSame($expected, $itemIds, 'Each review records the item it was written for');

    }

    /**
     * Spec: Cases/reviews.md [25]
     *   "A second review of the same item through the order link is refused."
     */
    public function aSecondReviewOfTheSameItemIsRefused(FunctionalTester $I): void
    {
        ['product' => $product, 'red' => $red, 'order' => $order] = $this->orderWithTwoItems();

        [$first] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'     => 5,
            'content'    => 'First take on red.',
            'order_hash' => $order->uuid,
            'item_id'    => $red->id,
        ]);
        Assert::assertSame(200, $first);

        ProductReviewService::resetRuntimeCache();

        [$second] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'     => 1,
            'content'    => 'Second take on red.',
            'order_hash' => $order->uuid,
            'item_id'    => $red->id,
        ]);
        Assert::assertSame(403, $second, 'One review per (product, item) slot');
        Assert::assertSame(1, ProductReview::query()->where('post_id', $product->ID)->count());
    }

    /**
     * Spec: Cases/reviews.md [43]
     *   "Having reviewed the product as a whole does not block reviewing one
     *    of its items through the order link."
     *
     * The slot lock and the first duplicate check are both keyed per
     * (product, item). The recheck that runs once the lock is held was not,
     * so it asked about the product-level slot instead and refused a buyer
     * who had reviewed the product as a whole — the exact person this page
     * exists for, told they had already reviewed something they had not.
     */
    public function aProductLevelReviewDoesNotBlockReviewingAnItem(FunctionalTester $I): void
    {
        ['product' => $product, 'red' => $red, 'order' => $order] = $this->orderWithTwoItems();

        // The buyer's own review of the product as a whole, filed under the
        // identity the order link resolves to.
        $identity = ProductReviewService::effectiveReviewerIdentity($order);

        $productLevel = new ProductReview([
            'post_id'        => $product->ID,
            'reviewer_name'  => 'The Buyer',
            'reviewer_email' => $identity['emails'][0] ?? '',
            'review'         => 'About the shoe in general.',
            'rating'         => 5,
        ]);
        $productLevel->user_id = $identity['user_id'] ?: null;
        $productLevel->customer_id = $identity['customer_id'] ?: null;
        $productLevel->status = Status::REVIEW_APPROVED;
        $productLevel->save();

        ProductReviewService::resetRuntimeCache();

        [$status, $body] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'     => 4,
            'content'    => 'And this is about Red in particular.',
            'order_hash' => $order->uuid,
            'item_id'    => $red->id,
        ]);

        Assert::assertSame(
            200,
            $status,
            'Red has never been reviewed; the product-level review is a different slot. Body: ' . wp_json_encode($body)
        );

        Assert::assertSame(
            1,
            ProductReview::query()->where('post_id', $product->ID)->where('item_id', $red->id)->count(),
            'and the item review is stored against the item'
        );
    }

    /**
     * Spec: Cases/reviews.md [26]
     *   "An order link for an order holding only Red cannot review Blue."
     */
    public function theOrderLinkDoesNotUnlockAnItemThatWasNotBought(FunctionalTester $I): void
    {
        ['product' => $product, 'blue' => $blue, 'order' => $order] = $this->orderWithTwoItems(['Red']);

        [$status, $body] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'     => 3,
            'content'    => 'Never bought the blue one.',
            'order_hash' => $order->uuid,
            'item_id'    => $blue->id,
        ]);

        Assert::assertSame(403, $status, 'A grant for Red must not unlock Blue. Body: ' . wp_json_encode($body));
        Assert::assertSame(0, ProductReview::query()->where('post_id', $product->ID)->count(), 'A refused item review writes nothing — it is not downgraded to a product review');
    }

    /**
     * Spec: Cases/reviews.md [27]
     *   "An item id without an order link is refused, even for a signed-in verified buyer."
     */
    public function anItemIdWithoutAnOrderLinkIsRefused(FunctionalTester $I): void
    {
        ['product' => $product, 'red' => $red, 'order' => $order] = $this->orderWithTwoItems();

        // The buyer, signed in: they pass verified_buyers on their own for a
        // product-level review, but an item-level one only comes from the
        // order-review page.
        $customer = $order->customer;
        $userId = WPUserFactory::create(['user_email' => $customer->email]);
        $customer->user_id = $userId;
        $customer->save();
        wp_set_current_user($userId);

        [$status, $body] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'  => 4,
            'content' => 'Trying to file under an item from the product page.',
            'item_id' => $red->id,
        ]);

        wp_set_current_user(0);

        Assert::assertSame(403, $status, 'Without a grant the item id must be refused, not dropped. Body: ' . wp_json_encode($body));
        Assert::assertSame(0, ProductReview::query()->where('post_id', $product->ID)->count());
    }

    /**
     * Spec: Cases/reviews.md [28]
     *   "An item id that belongs to another product is refused."
     */
    public function anItemOfAnotherProductIsRefused(FunctionalTester $I): void
    {
        ['product' => $product, 'order' => $order] = $this->orderWithTwoItems();
        $other = (new ProductFactory())->create();
        $foreignItem = $other->variants->first();

        [$status] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'     => 3,
            'content'    => 'Wrong product entirely.',
            'order_hash' => $order->uuid,
            'item_id'    => $foreignItem->id,
        ]);

        Assert::assertSame(422, $status, 'An item of another product is not a valid item for this one');
        Assert::assertSame(0, ProductReview::query()->where('post_id', $product->ID)->count());
    }

    /**
     * Spec: Cases/reviews.md [29]
     *   "A simple product files at product level even when its default variation id is sent."
     */
    public function aSimpleProductFilesAtProductLevel(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create();
        $product = (new ProductFactory())->create();
        $default = $product->variants->first();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id, 'status' => Status::ORDER_COMPLETED]);
        (new OrderItemFactory())->create($order->id, ['post_id' => $product->ID, 'object_id' => $default->id]);

        [$status, $body] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'     => 5,
            'content'    => 'Only one kind of this exists.',
            'order_hash' => $order->uuid,
            'item_id'    => $default->id,
        ]);

        Assert::assertSame(200, $status, 'The order line does cover it. Body: ' . wp_json_encode($body));

        $review = ProductReview::query()->where('post_id', $product->ID)->first();
        Assert::assertNotNull($review);
        Assert::assertNull($review->item_id, 'A simple product has no items to tell apart');
    }

    /**
     * Spec: Cases/reviews.md [30]
     *   "A product-level review and an item-level review of the same product are separate slots."
     */
    public function productLevelAndItemLevelAreSeparateSlots(FunctionalTester $I): void
    {
        ['product' => $product, 'red' => $red, 'order' => $order] = $this->orderWithTwoItems();

        [$itemStatus] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'     => 5,
            'content'    => 'About the red one.',
            'order_hash' => $order->uuid,
            'item_id'    => $red->id,
        ]);
        Assert::assertSame(200, $itemStatus);

        ProductReviewService::resetRuntimeCache();

        [$productStatus, $body] = $I->post('/fluent-cart/v2/public/reviews/' . $product->ID, [
            'rating'     => 4,
            'content'    => 'About the product overall.',
            'order_hash' => $order->uuid,
        ]);
        Assert::assertSame(200, $productStatus, 'The product-level slot is its own. Body: ' . wp_json_encode($body));

        Assert::assertSame(2, ProductReview::query()->where('post_id', $product->ID)->count());
        Assert::assertSame(1, ProductReview::query()->where('post_id', $product->ID)->whereNull('item_id')->count());
    }
}
