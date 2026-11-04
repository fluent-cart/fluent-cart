<?php

namespace Tests\Functional\Rest\Reviews;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\ProductReview;
use FluentCart\App\Services\ProductReviewService;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * A customer editing their own review, from the product page or from the
 * dashboard's My Reviews list.
 *
 * Route: PUT /public/reviews/{postId}/{reviewId}
 *
 * A reply is a row on the same product carrying the same user_id, which is
 * what makes the ownership predicate alone insufficient: without topLevel()
 * this endpoint edits replies too, and the renderer that decides between
 * "write" and "edit" binds the form to one. A store owner who has answered a
 * review is the ordinary way both happen — their reply is newer and matches
 * every column the lookup pinned.
 */
class EditOwnReviewCest
{
    public function _before(FunctionalTester $I): void
    {
        ModuleSettings::saveSettings([
            'reviews' => [
                'active'                 => 'yes',
                'reviews_enabled'        => 'yes',
                'review_permission_mode' => 'anyone',
            ],
        ]);
        ModuleSettings::getAllSettings(false);
        // resetRuntimeCache(), not just the settings: post ids repeat across
        // rolled-back tests, so a per-product answer cached by an earlier Cest
        // can otherwise decide that this Cest's product has reviews off.
        ProductReviewService::resetRuntimeCache();
    }

    public function _after(FunctionalTester $I): void
    {
        ProductReviewService::resetRuntimeCache();
    }

    /**
     * The visitor's own review, plus their reply to it — the shape that
     * produced the bug.
     *
     * @return array{product: object, review: ProductReview, reply: ProductReview}
     */
    private function reviewAndReplyBy(int $userId): array
    {
        $product = (new ProductFactory())->create(['post_title' => 'Edited Widget']);

        $review = new ProductReview([
            'post_id'       => $product->ID,
            'reviewer_name' => 'Dana Buyer',
            'review'        => 'The original review text.',
            'title'         => 'Original title',
            'rating'        => 5,
        ]);
        $review->user_id = $userId;
        $review->status = Status::REVIEW_APPROVED;
        $review->save();

        $reply = new ProductReview([
            'post_id'       => $product->ID,
            'parent_id'     => $review->id,
            'reviewer_name' => 'Store Owner',
            'review'        => 'Thanks for the note.',
        ]);
        $reply->user_id = $userId;
        $reply->status = Status::REVIEW_APPROVED;
        $reply->is_admin_reply = 1;
        $reply->save();

        return ['product' => $product, 'review' => $review, 'reply' => $reply];
    }

    /**
     * The visitor has replied to someone else's review on this product and has
     * written no review of their own.
     *
     * Deterministic on purpose: their reply is then the ONLY row matching
     * (post_id, user_id, approved), so a lookup missing topLevel() can only
     * return it. Giving them a review as well would leave the outcome to
     * whichever row the database happened to hand back first, and the test
     * would pass against the bug about half the time.
     *
     * @return array{product: object, reply: ProductReview}
     */
    private function replyOnlyBy(int $userId): array
    {
        $product = (new ProductFactory())->create(['post_title' => 'Answered Widget']);

        $someoneElse = new ProductReview([
            'post_id'       => $product->ID,
            'reviewer_name' => 'Another Buyer',
            'review'        => 'Someone else wrote this.',
            'rating'        => 4,
        ]);
        $someoneElse->status = Status::REVIEW_APPROVED;
        $someoneElse->save();

        $reply = new ProductReview([
            'post_id'       => $product->ID,
            'parent_id'     => $someoneElse->id,
            'reviewer_name' => 'Store Owner',
            'review'        => 'Thanks for the note.',
        ]);
        $reply->user_id = $userId;
        $reply->status = Status::REVIEW_APPROVED;
        $reply->is_admin_reply = 1;
        $reply->save();

        return ['product' => $product, 'reply' => $reply];
    }

    /**
     * Having replied to a review is not having written one: the form opens
     * blank, ready to write, not bound to the reply.
     */
    public function aReplyDoesNotPutTheFormIntoEditMode(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        (new CustomerFactory())->createForUser($userId);
        $fixture = $this->replyOnlyBy($userId);

        [$status, $body] = $I->get('/fluent-cart/v2/customer-profile/reviews/submission-form', [
            'post_id' => $fixture['product']->ID,
        ]);

        Assert::assertSame(200, $status);
        $html = (string) ($body['html'] ?? '');

        Assert::assertStringNotContainsString(
            'data-review-id="' . $fixture['reply']->id . '"',
            $html,
            'the form is never bound to a reply'
        );
        Assert::assertStringNotContainsString(
            'data-edit-mode="1"',
            $html,
            'and does not claim to be editing something the visitor never wrote'
        );
        Assert::assertStringNotContainsString(
            'Thanks for the note.',
            $html,
            'nor prefilled with the reply\'s text'
        );
    }

    /**
     * The form the dashboard opens is bound to the review when there is one.
     */
    public function theEditFormBindsToTheReview(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        (new CustomerFactory())->createForUser($userId);
        $fixture = $this->reviewAndReplyBy($userId);

        [$status, $body] = $I->get('/fluent-cart/v2/customer-profile/reviews/submission-form', [
            'post_id' => $fixture['product']->ID,
        ]);

        Assert::assertSame(200, $status);
        $html = (string) ($body['html'] ?? '');

        Assert::assertStringContainsString(
            'data-review-id="' . $fixture['review']->id . '"',
            $html,
            'the form edits the review'
        );
        Assert::assertStringContainsString(
            'data-existing-rating="5"',
            $html,
            'prefilled from the review — a reply has no rating to show'
        );
    }

    /**
     * A product-level review does not put a variation's form into edit mode.
     *
     * The case: someone reviews Air Max 97 as a whole, later buys the Stripe 1
     * variation, and opens the form for it. That variation has never been
     * reviewed, so the form must be ready to write — offering to "update" would
     * edit their review of the product instead, silently replacing an opinion
     * about one thing with an opinion about another.
     */
    public function aProductLevelReviewDoesNotEditAVariationsForm(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        (new CustomerFactory())->createForUser($userId);

        $product = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([
                ['variation_title' => 'Stripe 1'],
                ['variation_title' => 'Stripe 2'],
            ], ['post_title' => 'Air Max 97']);

        $stripeOne = $product->variants->firstWhere('variation_title', 'Stripe 1');

        $productLevel = new ProductReview([
            'post_id'       => $product->ID,
            'reviewer_name' => 'Dana Buyer',
            'review'        => 'About the shoe in general.',
            'rating'        => 5,
        ]);
        $productLevel->user_id = $userId;
        $productLevel->status = Status::REVIEW_APPROVED;
        $productLevel->save();

        [$status, $body] = $I->get('/fluent-cart/v2/customer-profile/reviews/submission-form', [
            'post_id' => $product->ID,
            'item_id' => $stripeOne->id,
        ]);

        Assert::assertSame(200, $status);
        $html = (string) ($body['html'] ?? '');

        Assert::assertStringNotContainsString(
            'data-edit-mode="1"',
            $html,
            'the variation has no review of its own, so the form writes one'
        );
        Assert::assertStringNotContainsString(
            'data-review-id="' . $productLevel->id . '"',
            $html,
            'and is never bound to the product-level review'
        );
    }

    /**
     * And the reverse: a variation's review does not put the product-level
     * form into edit mode.
     */
    public function aVariationReviewDoesNotEditTheProductLevelForm(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        (new CustomerFactory())->createForUser($userId);

        $product = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([
                ['variation_title' => 'Stripe 1'],
                ['variation_title' => 'Stripe 2'],
            ], ['post_title' => 'Air Max 97']);

        $stripeOne = $product->variants->firstWhere('variation_title', 'Stripe 1');

        $variationReview = new ProductReview([
            'post_id'       => $product->ID,
            'item_id'       => $stripeOne->id,
            'reviewer_name' => 'Dana Buyer',
            'review'        => 'About Stripe 1 only.',
            'rating'        => 4,
        ]);
        $variationReview->user_id = $userId;
        $variationReview->status = Status::REVIEW_APPROVED;
        $variationReview->save();

        [$status, $body] = $I->get('/fluent-cart/v2/customer-profile/reviews/submission-form', [
            'post_id' => $product->ID,
        ]);

        Assert::assertSame(200, $status);
        $html = (string) ($body['html'] ?? '');

        Assert::assertStringNotContainsString(
            'data-edit-mode="1"',
            $html,
            'the product-level slot is empty, whatever a variation carries'
        );
        Assert::assertStringNotContainsString(
            'data-review-id="' . $variationReview->id . '"',
            $html
        );
    }

    /**
     * Editing changes the review and leaves the reply alone.
     */
    public function editingUpdatesTheReview(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        (new CustomerFactory())->createForUser($userId);
        $fixture = $this->reviewAndReplyBy($userId);

        [$status] = $I->put(
            '/fluent-cart/v2/public/reviews/' . $fixture['product']->ID . '/' . $fixture['review']->id,
            [
                'rating'  => 4,
                'title'   => 'Revised title',
                'content' => 'The revised review text.',
            ]
        );

        Assert::assertSame(200, $status, 'the owner may edit their review');

        $review = ProductReview::query()->find($fixture['review']->id);
        $reply = ProductReview::query()->find($fixture['reply']->id);

        Assert::assertSame('The revised review text.', $review->review, 'the review carries the new text');
        Assert::assertSame(4, (int) $review->rating, 'and the new rating');
        Assert::assertSame('Thanks for the note.', $reply->review, 'the reply is untouched');
    }

    /**
     * A reply id sent to the review endpoint is refused. The browser no longer
     * offers one, but a hidden control is not a closed door: accepted, this
     * would put a title and a rating on a row that has neither and re-moderate
     * an approved reply out of its thread.
     */
    public function areplyCannotBeEditedThroughTheReviewEndpoint(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        (new CustomerFactory())->createForUser($userId);
        $fixture = $this->reviewAndReplyBy($userId);

        [$status] = $I->put(
            '/fluent-cart/v2/public/reviews/' . $fixture['product']->ID . '/' . $fixture['reply']->id,
            [
                'rating'  => 1,
                'content' => 'Rewriting a reply through the review endpoint.',
            ]
        );

        Assert::assertSame(404, $status, 'the endpoint edits reviews, and a reply is not one');

        $reply = ProductReview::query()->find($fixture['reply']->id);

        Assert::assertSame('Thanks for the note.', $reply->review, 'the reply text stands');
        Assert::assertSame(Status::REVIEW_APPROVED, $reply->status, 'and it is still in its thread');
    }
}
