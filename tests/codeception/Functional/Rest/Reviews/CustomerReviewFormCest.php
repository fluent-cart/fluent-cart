<?php

namespace Tests\Functional\Rest\Reviews;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Services\ProductReviewService;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * The My Reviews page opens the storefront review form in a modal instead
 * of sending the customer to the product page. It fetches that form per
 * product from customer-profile/reviews/submission-form; these cases pin what comes
 * back and who may ask.
 */
class CustomerReviewFormCest
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
        ProductReviewService::resetReviewSettingsCache();
    }

    public function _after(FunctionalTester $I): void
    {
        // The whole runtime cache, not just the settings: the per-product
        // "are reviews on here" answer is keyed by post id, and post ids
        // repeat across tests once their transactions roll back. Left behind,
        // a product this Cest switched reviews off for answers for whatever
        // product takes that id next.
        ProductReviewService::resetRuntimeCache();
    }

    public function aCustomerGetsTheModalFormForAProduct(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        (new CustomerFactory())->createForUser($userId);
        $product = (new ProductFactory())->create();

        [$status, $body] = $I->get('/fluent-cart/v2/customer-profile/reviews/submission-form', [
            'post_id' => $product->ID,
        ]);

        Assert::assertSame(200, $status);
        $html = (string) ($body['html'] ?? '');
        Assert::assertStringContainsString('data-fluent-cart-review-form', $html, 'The storefront form, for ReviewForm.js to take over');
        Assert::assertStringContainsString('data-post-id="' . $product->ID . '"', $html, 'bound to the product asked for');
        Assert::assertStringContainsString('data-container="modal"', $html, 'in a modal, as on the order-review page');
        Assert::assertStringContainsString('data-layout="inline"', $html, 'every field at once, as on the order-review page');
        Assert::assertStringContainsString('data-review-drawer', $html, 'the overlay is part of the markup');
        Assert::assertStringNotContainsString('data-open-review-drawer', $html, 'no trigger button: the page has the row\'s button');
    }

    /**
     * The History tab edits through this same endpoint, and an item-level
     * review has to open ITS slot's form. The renderer decides between "write"
     * and "edit" by looking the visitor's review up in the (product, item)
     * slot, so without the item the form comes back blank and a save collides
     * with the duplicate guard.
     */
    public function theFormCanBeAskedForOneVariation(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        (new CustomerFactory())->createForUser($userId);

        $product = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([
                ['variation_title' => 'Red'],
                ['variation_title' => 'Blue'],
            ]);
        $red = $product->variants->firstWhere('variation_title', 'Red');

        [$status, $body] = $I->get('/fluent-cart/v2/customer-profile/reviews/submission-form', [
            'post_id' => $product->ID,
            'item_id' => $red->id,
        ]);

        Assert::assertSame(200, $status);
        Assert::assertStringContainsString(
            'data-item-id="' . $red->id . '"',
            (string) ($body['html'] ?? ''),
            'the form is bound to the variation, not the product as a whole'
        );
    }

    /**
     * A variation of a different product is refused rather than quietly
     * downgraded to the product-level form — the same rule the submission
     * applies, so the two cannot disagree about which slot exists.
     */
    public function aVariationOfAnotherProductIsRefused(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        (new CustomerFactory())->createForUser($userId);

        $product = (new ProductFactory())->create();
        $other = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([
                ['variation_title' => 'Red'],
                ['variation_title' => 'Blue'],
            ]);

        [$status] = $I->get('/fluent-cart/v2/customer-profile/reviews/submission-form', [
            'post_id' => $product->ID,
            'item_id' => $other->variants->first()->id,
        ]);

        Assert::assertSame(422, $status, 'a stray item is refused, never quietly dropped');
    }

    public function aVisitorWhoIsNotLoggedInCannotGetTheForm(FunctionalTester $I): void
    {
        $I->logout();
        $product = (new ProductFactory())->create();

        [$status] = $I->get('/fluent-cart/v2/customer-profile/reviews/submission-form', [
            'post_id' => $product->ID,
        ]);

        Assert::assertContains($status, [401, 403], 'The customer-profile policy refuses a logged-out request');
    }

    public function anUnpublishedProductHasNoForm(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        (new CustomerFactory())->createForUser($userId);
        $product = (new ProductFactory())->create();
        wp_update_post(['ID' => $product->ID, 'post_status' => 'draft']);

        [$status] = $I->get('/fluent-cart/v2/customer-profile/reviews/submission-form', [
            'post_id' => $product->ID,
        ]);

        Assert::assertSame(404, $status);
    }

    public function aProductWithReviewsOffHasNoForm(FunctionalTester $I): void
    {
        $userId = $I->loginAs('customer');
        (new CustomerFactory())->createForUser($userId);
        $product = (new ProductFactory())->withDetailAttributes(['other_info' => ['reviews_enabled' => 'no']])->create();
        ProductReviewService::resetRuntimeCache();

        [$status] = $I->get('/fluent-cart/v2/customer-profile/reviews/submission-form', [
            'post_id' => $product->ID,
        ]);

        Assert::assertSame(404, $status);
    }
}
