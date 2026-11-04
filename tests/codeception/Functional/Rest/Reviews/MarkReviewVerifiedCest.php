<?php

namespace Tests\Functional\Rest\Reviews;

use FluentCart\Api\Resource\ProductReviewResource;
use FluentCart\App\Models\ProductReview;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/reviews.md → "Marking a review as verified", items 16-18.
 *
 * Route: PUT /fluent-cart/v2/reviews/{id} (meta: reviews/manage)
 * Controller: ProductReviewController::update — is_verified joins the
 * whitelist of updatable fields and is written by direct property assignment
 * in ProductReviewResource::update(), because the column is guarded against
 * mass assignment.
 */
class MarkReviewVerifiedCest
{
    /**
     * Spec: Cases/Functional/reviews.md [16]
     *   "Admin can mark an existing review as a verified purchase."
     */
    public function adminCanMarkReviewAsVerifiedPurchase(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();
        $review = ProductReviewResource::create([
            'post_id'        => $product->ID,
            'rating'         => 5,
            'content'        => 'Unverified when it arrived.',
            'status'         => 'approved',
            'is_verified'    => 0,
            'reviewer_name'  => 'Unverified Reviewer',
            'reviewer_email' => 'unverified@example.com',
        ]);

        $I->put('/fluent-cart/v2/reviews/' . $review->id, ['is_verified' => 1]);

        $I->seeResponseCodeIs(200);
        Assert::assertSame(
            1,
            (int) ProductReview::query()->find($review->id)->is_verified,
            'the verified flag is persisted, not only echoed back'
        );
    }

    /**
     * Spec: Cases/Functional/reviews.md [17]
     *   "Admin can remove the verified-purchase mark from a review."
     */
    public function adminCanRemoveVerifiedPurchaseMark(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();
        $review = ProductReviewResource::create([
            'post_id'        => $product->ID,
            'rating'         => 5,
            'content'        => 'Verified by mistake.',
            'status'         => 'approved',
            'is_verified'    => 1,
            'reviewer_name'  => 'Verified Reviewer',
            'reviewer_email' => 'verified@example.com',
        ]);

        $I->put('/fluent-cart/v2/reviews/' . $review->id, ['is_verified' => 0]);

        $I->seeResponseCodeIs(200);
        Assert::assertSame(
            0,
            (int) ProductReview::query()->find($review->id)->is_verified,
            'clearing the flag persists, so the toggle is not one-way'
        );
    }

    /**
     * Spec: Cases/Functional/reviews.md [18]
     *   "Updating only the verified mark leaves the review's content and status untouched."
     */
    public function updatingOnlyVerifiedMarkLeavesContentAndStatusUntouched(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create();
        $review = ProductReviewResource::create([
            'post_id'        => $product->ID,
            'rating'         => 3,
            'title'          => 'Original title',
            'content'        => 'Original body that must survive the toggle.',
            'status'         => 'pending',
            'is_verified'    => 0,
            'reviewer_name'  => 'Partial Update',
            'reviewer_email' => 'partial-update@example.com',
        ]);

        $I->put('/fluent-cart/v2/reviews/' . $review->id, ['is_verified' => 1]);

        $I->seeResponseCodeIs(200);

        $row = ProductReview::query()->find($review->id);

        Assert::assertSame('Original body that must survive the toggle.', $row->content, 'content is untouched');
        Assert::assertSame('Original title', $row->title, 'title is untouched');
        Assert::assertSame('pending', $row->status, 'status is untouched');
        Assert::assertSame(3, (int) $row->rating, 'rating is untouched');
        Assert::assertSame(1, (int) $row->is_verified, 'only the verified flag changed');
    }
}
