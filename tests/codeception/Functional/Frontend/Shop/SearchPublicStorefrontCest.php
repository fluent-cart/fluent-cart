<?php

namespace Tests\Functional\Frontend\Shop;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Browsing the public storefront",
 * search items 212-214.
 *
 * Route: GET /public/product-search  ShopController::searchProduct
 *
 * The endpoint performs a like_all wildcard match on post_title (publish-only)
 * and returns rendered HTML under the htmlView key.
 */
class SearchPublicStorefrontCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/products.md [212]
     *   "A logged-out visitor can search the public storefront by product name and only matching products are returned."
     */
    public function logoutVisitorCanSearchPublicStorefrontByProductNameAndOnlyMatchingProductsAreReturned(FunctionalTester $I): void
    {
        $I->logout();
        $matching    = (new ProductFactory())->create(['post_title' => 'Searchable Hat 212', 'post_status' => 'publish']);
        $nonMatching = (new ProductFactory())->create(['post_title' => 'Other Bag 212',      'post_status' => 'publish']);

        [, $body] = $I->get('/fluent-cart/v2/public/product-search', ['post_title' => 'Hat']);

        $I->seeResponseCodeIs(200);
        $html = (string) ($body['htmlView'] ?? '');
        Assert::assertStringContainsString('Searchable Hat 212', $html, 'rendered htmlView must include the matching product title (Hat)');
        Assert::assertStringNotContainsString('Other Bag 212', $html, 'rendered htmlView must NOT include the non-matching product (no "Hat" in its title)');
    }

    /**
     * Spec: Cases/Functional/products.md [213]
     *   "A logged-out visitor receives an empty list when searching with a term that matches no published products."
     */
    public function logoutVisitorReceivesEmptyListWhenSearchingWithTermThatMatchesNoPublishedProducts(FunctionalTester $I): void
    {
        $I->logout();
        (new ProductFactory())->create(['post_title' => 'Existing Coat 213', 'post_status' => 'publish']);

        [, $body] = $I->get('/fluent-cart/v2/public/product-search', ['post_title' => 'NoMatchString213XYZ']);

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('htmlView', $body, 'search response must always carry an htmlView key, even when no products match');
        $html = (string) ($body['htmlView'] ?? '');
        Assert::assertStringNotContainsString('Existing Coat 213', $html, 'no-match search must NOT surface any product titles in the rendered htmlView');
    }

    /**
     * Spec: Cases/Functional/products.md [214]
     *   "A logged-out visitor sees public storefront search input that resembles an SQL injection attempt treated as plain text without causing a server error."
     */
    public function logoutVisitorSeesPublicStorefrontSearchInputResemblingSqlInjectionTreatedAsPlainTextWithoutCausingServerError(FunctionalTester $I): void
    {
        $I->logout();
        (new ProductFactory())->create(['post_title' => 'Real Product 214', 'post_status' => 'publish']);
        $injectionPayload = "' OR '1'='1' --";

        [, $body] = $I->get('/fluent-cart/v2/public/product-search', ['post_title' => $injectionPayload]);

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('htmlView', $body, 'injection-shaped search input must produce a normal htmlView response, not a 5xx server error');
        $html = (string) ($body['htmlView'] ?? '');
        Assert::assertStringNotContainsString('Real Product 214', $html, 'injection payload must be treated as plain text — like_all on post_title cannot match the seeded product, so the response must not include it');
    }
}
