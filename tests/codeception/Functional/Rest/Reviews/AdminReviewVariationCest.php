<?php

namespace Tests\Functional\Rest\Reviews;

use FluentCart\App\Models\ProductReview;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/reviews.md → "Choosing a variation for a new review",
 * items 34-40.
 *
 * Routes: GET  /fluent-cart/v2/advance_filter/get-filter-options
 *              ?remote_data_key=review_products
 *         POST /fluent-cart/v2/reviews  (item_id)
 *
 * Providers: ReviewModule::registerProductPickerOptions(), which carries a
 * product's variations on the product row, and
 * ProductReviewController::create(), which resolves item_id through
 * ProductReviewService::resolveReviewItem() — the same resolver the storefront
 * submission uses, so a moderator and a buyer can never disagree about which
 * slot a review occupies.
 *
 * The order grant is deliberately NOT required here, unlike the storefront
 * path: that link exists to prove a guest bought the item, and reviews/manage
 * is already the decision that this person may write the review by hand.
 */
class AdminReviewVariationCest
{
    public function _before(FunctionalTester $I): void
    {
        // A role holding reviews/manage and nothing else — the same fixture the
        // product picker's cases use, for the same reason: the variation lookup
        // must not need products/view either, or the workflow stops halfway.
        add_filter('fluent_cart/permission/all_roles', function ($roles) {
            $roles['fc_reviews_only'] = [
                'title'        => 'Reviews only',
                'descriptions' => 'Can moderate reviews and nothing else',
                'permissions'  => ['reviews/manage'],
            ];

            return $roles;
        });
    }

    public function _after(FunctionalTester $I): void
    {
        remove_all_filters('fluent_cart/permission/all_roles');
    }

    /**
     * A product whose variations are worth telling apart.
     */
    private function variantProduct(string $title = 'Variation Widget')
    {
        return (new ProductFactory())
            ->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([
                ['variation_title' => 'Red'],
                ['variation_title' => 'Blue'],
            ], ['post_title' => $title]);
    }

    /**
     * Spec: Cases/Functional/reviews.md [34]
     *   "Admin can look up the variations of the product a review is being
     *    written about."
     */
    public function adminCanLookUpTheProductsVariations(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = $this->variantProduct();

        [, $body] = $I->get('/fluent-cart/v2/advance_filter/get-filter-options', [
            'remote_data_key' => 'review_products',
            'include_ids'     => [$product->ID],
        ]);

        $I->seeResponseCodeIs(200);

        $row = $body['options']['data'][0];

        Assert::assertSame((int) $product->ID, (int) $row['id'], 'the product asked for by id');
        Assert::assertSame(
            ['Red', 'Blue'],
            array_column($row['variations'], 'title'),
            'with its variations, in the order the product lists them'
        );
    }

    /**
     * Spec: Cases/Functional/reviews.md [35]
     *   "A simple product offers no variations, so the picker has nothing to
     *    show."
     */
    public function aSimpleProductOffersNoVariations(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        // The default factory product: one variation row, variation_type simple.
        $product = (new ProductFactory())->create(['post_title' => 'Plain Widget']);

        [, $body] = $I->get('/fluent-cart/v2/advance_filter/get-filter-options', [
            'remote_data_key' => 'review_products',
            'include_ids'     => [$product->ID],
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertSame(
            [],
            $body['options']['data'][0]['variations'],
            'a lone default variation is not an item — the row stays unexpandable'
        );
    }

    /**
     * Spec: Cases/Functional/reviews.md [36]
     *   "A user who may manage reviews but not view products can still choose a
     *    variation."
     */
    public function reviewsOnlyRoleCanChooseAVariation(FunctionalTester $I): void
    {
        $product = $this->variantProduct('Reviews Only Variants');
        $I->loginAs('fc_reviews_only');

        [, $body] = $I->get('/fluent-cart/v2/advance_filter/get-filter-options', [
            'remote_data_key' => 'review_products',
            'include_ids'     => [$product->ID],
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertNotEmpty(
            $body['options']['data'][0]['variations'],
            'reviews/manage is enough to see a product\'s variations, as it is to see the product'
        );
    }

    /**
     * Spec: Cases/Functional/reviews.md [37]
     *   "A review added against a variation is stored against that variation."
     */
    public function aReviewAddedAgainstAVariationIsStoredAgainstIt(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = $this->variantProduct();
        $red = $product->variants->firstWhere('variation_title', 'Red');

        [$status, $body] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'       => $product->ID,
            'item_id'       => $red->id,
            'rating'        => 5,
            'content'       => 'The red one is the right red.',
            'reviewer_name' => 'Dana Guest',
            'status'        => 'approved',
        ]);

        Assert::assertSame(200, $status, 'the review is accepted');

        $row = ProductReview::query()->find($body['review']['id']);

        Assert::assertSame((int) $red->id, (int) $row->item_id, 'the chosen variation is the review\'s item');
        Assert::assertSame((int) $product->ID, (int) $row->post_id, 'and it still belongs to the product');
    }

    /**
     * Spec: Cases/Functional/reviews.md [38]
     *   "A review added without a variation stays a product-level review."
     */
    public function aReviewAddedWithoutAVariationStaysProductLevel(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = $this->variantProduct();

        [$status, $body] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'       => $product->ID,
            'rating'        => 4,
            'content'       => 'About the product as a whole.',
            'reviewer_name' => 'Dana Guest',
            'status'        => 'approved',
        ]);

        Assert::assertSame(200, $status, 'the review is accepted');

        $row = ProductReview::query()->find($body['review']['id']);

        Assert::assertNull(
            $row->item_id,
            'the product-level slot is item_id NULL — the picker being optional is what every review filed before it relied on'
        );
    }

    /**
     * Spec: Cases/Functional/reviews.md [39]
     *   "A variation belonging to another product is refused, not quietly filed
     *    at product level."
     */
    public function aVariationOfAnotherProductIsRefused(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = $this->variantProduct('Reviewed Widget');
        $other = $this->variantProduct('Someone Else\'s Widget');
        $stranger = $other->variants->first();

        $before = ProductReview::query()->where('post_id', $product->ID)->count();

        [$status, $body] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'       => $product->ID,
            'item_id'       => $stranger->id,
            'rating'        => 5,
            'content'       => 'Filed against a variation of a different product.',
            'reviewer_name' => 'Dana Guest',
            'status'        => 'approved',
        ]);

        Assert::assertSame(422, $status, 'a stray variation is refused rather than dropped');
        Assert::assertArrayHasKey(
            'item_id',
            $body,
            'the message is keyed to the field, so the form prints it under the variation picker'
        );
        Assert::assertSame(
            $before,
            ProductReview::query()->where('post_id', $product->ID)->count(),
            'and nothing was written — the response never claims a review it did not file'
        );
    }

    /**
     * Spec: Cases/Functional/reviews.md [40]
     *   "A variation sent for a simple product files the review against the
     *    product as a whole."
     */
    public function aVariationSentForASimpleProductFilesAtProductLevel(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'Plain Widget']);
        $defaultVariation = $product->variants->first();

        [$status, $body] = $I->post('/fluent-cart/v2/reviews', [
            'post_id'       => $product->ID,
            'item_id'       => $defaultVariation->id,
            'rating'        => 4,
            'content'       => 'A simple product has no items to tell apart.',
            'reviewer_name' => 'Dana Guest',
            'status'        => 'approved',
        ]);

        Assert::assertSame(200, $status, 'the review is accepted — the id is this product\'s, it is just not an item');

        $row = ProductReview::query()->find($body['review']['id']);

        Assert::assertNull(
            $row->item_id,
            'a simple product files at product level whatever was sent, the same rule resolveReviewItem() applies on the storefront'
        );
    }
}
