<?php

namespace Tests\Functional\Rest\Reviews;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/reviews.md → "Choosing a product for a new review", items 27-30.
 *
 * Route: GET /fluent-cart/v2/advance_filter/get-filter-options
 *        ?remote_data_key=review_products
 * Provider: ReviewModule::registerProductPickerOptions()
 *
 * No route of its own: that endpoint already serves this shape of lookup and
 * resolves its permission per data key, so the reviews module registers the
 * `review_products` key and answers for it.
 *
 * The add-review modal used to search through products/searchProductByName,
 * which is gated on products/view. A role granted only reviews/manage could
 * therefore open the reviews screen and the modal but be refused by the
 * product picker — unable to finish the workflow its capability grants. Item
 * 29 is the regression guard for that.
 */
class ReviewProductLookupCest
{
    public function _before(FunctionalTester $I): void
    {
        // A role holding reviews/manage and nothing else.
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
     * Spec: Cases/Functional/reviews.md [27]
     *   "Admin can look up products to attach a review to."
     */
    public function adminCanLookUpProducts(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'Lookup Widget']);

        [, $body] = $I->get('/fluent-cart/v2/advance_filter/get-filter-options', [
            'remote_data_key' => 'review_products',
            'search'          => 'Lookup Widget',
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertContains(
            (int) $product->ID,
            array_column($body['options']['data'], 'id'),
            'the product the admin searched for is offered'
        );
    }

    /**
     * Spec: Cases/Functional/reviews.md [28]
     *   "The product lookup returns only what the picker renders."
     */
    public function lookupReturnsOnlyTheFieldsThePickerNeeds(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        (new ProductFactory())->create(['post_title' => 'Lean Payload Widget']);

        [, $body] = $I->get('/fluent-cart/v2/advance_filter/get-filter-options', [
            'remote_data_key' => 'review_products',
            'search'          => 'Lean Payload Widget',
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertNotEmpty($body['options']['data'], 'the search matched something to inspect');

        $row = $body['options']['data'][0];

        Assert::assertSame(
            ['id', 'title', 'variations'],
            array_keys($row),
            'what the picker renders and nothing else — a reviews capability must not hand out prices, stock or payment type'
        );
        Assert::assertSame('Lean Payload Widget', $row['title'], 'the title the picker prints');
        Assert::assertSame([], $row['variations'], 'a simple product has no items to tell apart');
    }

    /**
     * Spec: Cases/Functional/reviews.md [41]
     *   "A product's variations come down with the product, so the picker needs
     *    no second request."
     */
    public function variationsRideAlongOnTheProductRow(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        (new ProductFactory())
            ->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([
                ['variation_title' => 'Red'],
                ['variation_title' => 'Blue'],
            ], ['post_title' => 'Inline Variants Widget']);

        [, $body] = $I->get('/fluent-cart/v2/advance_filter/get-filter-options', [
            'remote_data_key' => 'review_products',
            'search'          => 'Inline Variants Widget',
        ]);

        $I->seeResponseCodeIs(200);

        $row = $body['options']['data'][0];

        Assert::assertSame(
            ['Red', 'Blue'],
            array_column($row['variations'], 'title'),
            'in the order the product lists them'
        );
        Assert::assertSame(
            ['id', 'title'],
            array_keys($row['variations'][0]),
            'a variation carries what the row prints and nothing more'
        );
    }

    /**
     * Spec: Cases/Functional/reviews.md [29]
     *   "A user who may manage reviews but not view products can still choose a
     *    product for a review."
     */
    public function reviewsOnlyRoleCanChooseAProduct(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'Reviews Only Widget']);
        $I->loginAs('fc_reviews_only');

        [, $body] = $I->get('/fluent-cart/v2/advance_filter/get-filter-options', [
            'remote_data_key' => 'review_products',
            'search'          => 'Reviews Only Widget',
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertContains(
            (int) $product->ID,
            array_column($body['options']['data'], 'id'),
            'reviews/manage is enough to populate the picker'
        );

        // The contrast is the point: the products route this modal used to call
        // refuses the same user, which is what left them stuck.
        [$productsStatus] = $I->get('/fluent-cart/v2/products/searchProductByName', ['name' => 'Reviews Only Widget']);
        Assert::assertSame(
            403,
            $productsStatus,
            'the products lookup still requires products/view — this route exists because that one refuses them'
        );
    }

    /**
     * Spec: Cases/Functional/reviews.md [30]
     *   "A logged-out visitor cannot look up products through the reviews screen."
     */
    public function loggedOutVisitorCannotLookUpProducts(FunctionalTester $I): void
    {
        (new ProductFactory())->create(['post_title' => 'Private Widget']);
        $I->logout();

        [$status] = $I->get('/fluent-cart/v2/advance_filter/get-filter-options', [
            'remote_data_key' => 'review_products',
            'search'          => 'Private Widget',
        ]);

        Assert::assertSame(401, $status, 'the shared endpoint keeps non-admins out entirely');
    }
}
