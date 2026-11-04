<?php

namespace Tests\Functional\Frontend\Shop;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * The public product list is open to anyone, so a caller must not be able to
 * pull an unbounded page. WordPress core caps REST collections at 100.
 */
class PublicProductPageSizeCest
{
    public function publicProductListCapsThePageSize(FunctionalTester $I): void
    {
        $I->logout();
        for ($i = 0; $i < 105; $i++) {
            (new ProductFactory())->create(['post_title' => 'Bulk ' . $i, 'post_status' => 'publish']);
        }

        [, $body] = $I->get('/fluent-cart/v2/public/products', ['per_page' => 105]);

        $rows = $body['products']['products']['data'] ?? [];
        Assert::assertNotEmpty($rows, 'precondition: the list returned products. Keys: ' . implode(',', array_keys((array) $body)));
        Assert::assertLessThanOrEqual(100, count($rows), 'an anonymous caller must not get more than 100 products in one page (got ' . count($rows) . ')');
    }

    // per_page is the divisor for last_page, so 0 used to divide by zero.
    public function productViewsSurvivesAZeroPageSize(FunctionalTester $I): void
    {
        $I->logout();
        (new ProductFactory())->create(['post_title' => 'Zero page size', 'post_status' => 'publish']);

        [$status, $body] = $I->get('/fluent-cart/v2/public/product-views', ['per_page' => 0]);

        Assert::assertSame(200, $status, 'per_page=0 must not crash the product views endpoint. Body: ' . wp_json_encode($body));
        Assert::assertGreaterThanOrEqual(1, (int) ($body['products']['per_page'] ?? 0), 'per_page is clamped to at least 1');
    }
}
