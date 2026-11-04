<?php

namespace Tests\Functional\Rest\Products;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ProductTermFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Listing and searching products" section, items 1–20.
 */
class ListProductsCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/products.md [1]
     *   "Admin can list products, with the most recently created first by default."
     */
    public function adminCanListProductsWithNewestFirstByDefault(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ProductFactory();
        $first  = $factory->create(['post_title' => 'Order Probe Alpha']);
        $second = $factory->create(['post_title' => 'Order Probe Beta']);

        [$status, $body] = $I->get('/fluent-cart/v2/products');

        $I->seeResponseCodeIs(200);
        $rows = $body['products']['data'] ?? [];
        Assert::assertNotEmpty($rows, 'product list should not be empty');

        $ids = array_map(static function ($p) {
            return (int) ($p['ID'] ?? 0);
        }, $rows);

        Assert::assertContains($first->ID, $ids, 'first seeded product must appear in default listing');
        Assert::assertContains($second->ID, $ids, 'second seeded product must appear in default listing');

        $firstIdx  = array_search($first->ID, $ids, true);
        $secondIdx = array_search($second->ID, $ids, true);

        Assert::assertLessThan(
            $firstIdx,
            $secondIdx,
            'newest product (higher id) must precede older one in default order'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [2]
     *   "Admin can paginate the product list."
     */
    public function adminCanPaginateProductList(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ProductFactory();
        $createdIds = [];
        for ($i = 0; $i < 4; $i++) {
            $createdIds[] = (int) $factory->create(['post_title' => 'Paginate ' . $i])->ID;
        }
        // Default sort is ID DESC, so the most recently created should appear on page 1.
        rsort($createdIds);
        $expectedPage1Ids = array_slice($createdIds, 0, 2);
        $expectedPage2Ids = array_slice($createdIds, 2, 2);

        [$status, $page1] = $I->get('/fluent-cart/v2/products', ['per_page' => 2, 'page' => 1]);
        $I->seeResponseCodeIs(200);
        Assert::assertSame(1, (int) ($page1['products']['current_page'] ?? 0), 'page 1 response must report current_page=1');
        Assert::assertCount(2, $page1['products']['data'] ?? [], 'page 1 must contain exactly per_page items');

        [, $page2] = $I->get('/fluent-cart/v2/products', ['per_page' => 2, 'page' => 2]);
        Assert::assertSame(2, (int) ($page2['products']['current_page'] ?? 0), 'page 2 response must report current_page=2');
        Assert::assertCount(2, $page2['products']['data'] ?? [], 'page 2 must contain exactly per_page items');

        $page1Ids = array_map('intval', array_column($page1['products']['data'] ?? [], 'ID'));
        $page2Ids = array_map('intval', array_column($page2['products']['data'] ?? [], 'ID'));

        foreach ($expectedPage1Ids as $id) {
            Assert::assertContains($id, $page1Ids, "page 1 must contain seeded id {$id}");
        }
        foreach ($expectedPage2Ids as $id) {
            Assert::assertContains($id, $page2Ids, "page 2 must contain seeded id {$id}");
        }
        Assert::assertEmpty(
            array_intersect($page1Ids, $page2Ids),
            'page 1 and page 2 must contain disjoint products'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [3]
     *   "Admin can request a specific page size when listing products."
     */
    public function adminCanRequestSpecificPageSizeWhenListing(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ProductFactory();
        for ($i = 0; $i < 5; $i++) {
            $factory->create(['post_title' => 'PageSize ' . $i]);
        }

        [$status, $body] = $I->get('/fluent-cart/v2/products', ['per_page' => 3]);

        $I->seeResponseCodeIs(200);
        Assert::assertSame(3, (int) ($body['products']['per_page'] ?? 0), 'response must echo back the requested per_page value');
        Assert::assertCount(3, $body['products']['data'] ?? [], 'data array must hold exactly per_page items');
    }

    /**
     * Spec: Cases/Functional/products.md [4]
     *   "Admin receives an empty list, not an error, when requesting a page beyond the available results."
     */
    public function adminReceivesEmptyListWhenRequestingPageBeyondResults(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        (new ProductFactory())->create(['post_title' => 'OnlyOne']);

        [$status, $body] = $I->get('/fluent-cart/v2/products', ['per_page' => 5, 'page' => 999]);

        $I->seeResponseCodeIs(200);
        Assert::assertSame([], $body['products']['data'] ?? null, 'page beyond available results must return an empty data array, not an error');
    }

    /**
     * Spec: Cases/Functional/products.md [5]
     *   "Admin can search products by part of a product title and only matching products are returned."
     */
    public function adminCanSearchProductsByPartialTitle(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ProductFactory();
        $hit  = $factory->create(['post_title' => 'UniqueZebraNeedle']);
        $miss = $factory->create(['post_title' => 'TotallyUnrelatedHaystack']);

        [$status, $body] = $I->get('/fluent-cart/v2/products', ['search' => 'ZebraNeedle']);

        $I->seeResponseCodeIs(200);
        $ids = array_column($body['products']['data'] ?? [], 'ID');
        Assert::assertContains($hit->ID, $ids, 'product whose title matches the search must appear in results');
        Assert::assertNotContains($miss->ID, $ids, 'product whose title does not match the search must be excluded');
    }

    /**
     * Spec: Cases/Functional/products.md [6]
     *   "Admin can filter products by category and only products in that category are returned."
     */
    public function adminCanFilterProductsByCategory(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory  = new ProductFactory();
        $termFactory = new ProductTermFactory();

        $tagged   = $factory->create(['post_title' => 'CategoryTagged']);
        $untagged = $factory->create(['post_title' => 'CategoryUntagged']);

        $term = $termFactory->createCategory(['name' => 'TestFilterCat']);
        $termFactory->attachToProduct($tagged->ID, ProductTermFactory::TAXONOMY_CATEGORY, [$term['term_id']]);

        $advanced = wp_json_encode([
            [
                [
                    'source'      => ['taxonomy', 'product-categories'],
                    'operator'    => 'in',
                    'value'       => [(int) $term['term_id']],
                    'filter_type' => 'relation',
                    'relation'    => 'wpTerms',
                    'column'      => 'term_id',
                ],
            ],
        ]);

        [$status, $body] = $I->get('/fluent-cart/v2/products', [
            'filter_type'      => 'advanced',
            'advanced_filters' => $advanced,
        ]);

        $I->seeResponseCodeIs(200);
        $ids = array_column($body['products']['data'] ?? [], 'ID');
        Assert::assertContains($tagged->ID, $ids, 'product attached to the filtered category must appear in results');
        Assert::assertNotContains($untagged->ID, $ids, 'product without the filtered category must be excluded');
    }

    /**
     * Spec: Cases/Functional/products.md [7]
     *   "Admin can filter products by status and only products with that status are returned."
     */
    public function adminCanFilterProductsByStatus(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory   = new ProductFactory();
        $published = $factory->create(['post_title' => 'StatusFiltPub', 'post_status' => 'publish']);
        $draft     = $factory->create(['post_title' => 'StatusFiltDraft', 'post_status' => 'draft']);

        [$status, $body] = $I->get('/fluent-cart/v2/products', ['active_view' => 'draft']);

        $I->seeResponseCodeIs(200);
        $ids = array_column($body['products']['data'] ?? [], 'ID');
        Assert::assertContains($draft->ID, $ids, 'draft product must appear when active_view=draft');
        Assert::assertNotContains($published->ID, $ids, 'published product must be excluded when active_view=draft');
    }

    /**
     * Spec: Cases/Functional/products.md [8]
     *   "Admin sees product search input that resembles an SQL injection attempt treated as plain text without causing a server error."
     */
    public function productSearchSqlInjectionAttemptIsTreatedAsPlainText(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $normal = (new ProductFactory())->create(['post_title' => 'NormalGoodProduct']);

        $payload = "'; DROP TABLE wp_posts; --";
        [$status, $body] = $I->get('/fluent-cart/v2/products', ['search' => $payload]);

        $I->seeResponseCodeIs(200);
        Assert::assertIsArray($body['products']['data'] ?? null, 'injection-shaped search must still produce a paginator data array');

        $ids = array_map('intval', array_column($body['products']['data'] ?? [], 'ID'));
        Assert::assertNotContains(
            (int) $normal->ID,
            $ids,
            'injection-shaped search must be treated as a plain string and must not match the seeded normal product'
        );

        global $wpdb;
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
        Assert::assertGreaterThan(0, $count, 'wp_posts must still exist after injection probe');
    }

    /**
     * Spec: Cases/Functional/products.md [9]
     *   "Admin can fetch products by id when given a list of product ids."
     */
    public function adminCanFetchProductsByIds(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ProductFactory();
        $a = $factory->create(['post_title' => 'FetchIdA']);
        $b = $factory->create(['post_title' => 'FetchIdB']);
        $c = $factory->create(['post_title' => 'FetchIdC']);

        [$status, $body] = $I->get('/fluent-cart/v2/products/fetchProductsByIds', [
            'productIds' => [$a->ID, $b->ID],
        ]);

        $I->seeResponseCodeIs(200);
        $rows = $body['products']['data'] ?? ($body['products'] ?? []);
        $ids  = array_map(static function ($p) {
            return (int) ($p['id'] ?? $p['ID'] ?? 0);
        }, is_array($rows) ? $rows : []);

        Assert::assertContains($a->ID, $ids, 'first requested product id must be returned');
        Assert::assertContains($b->ID, $ids, 'second requested product id must be returned');
        Assert::assertNotContains($c->ID, $ids, 'product whose id was not requested must be excluded');
    }

    /**
     * Spec: Cases/Functional/products.md [10]
     *   "Admin can fetch variations by id when given a list of variation ids."
     */
    public function adminCanFetchVariationsByIds(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ProductFactory();
        $p1 = $factory->create(['post_title' => 'VarFetchOne']);
        $p2 = $factory->create(['post_title' => 'VarFetchTwo']);

        $v1 = $p1->variants->first();
        $v2 = $p2->variants->first();

        [$status, $body] = $I->get('/fluent-cart/v2/products/fetchVariationsByIds', [
            'productIds' => [$v1->id, $v2->id],
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertIsArray($body, 'fetchVariationsByIds response body must decode as array');
        $rows = $body['products'] ?? [];
        Assert::assertIsArray($rows, 'response must contain a products array');
        $returnedIds = array_map(static function ($row) {
            return (int) ($row['value'] ?? 0);
        }, $rows);
        Assert::assertContains((int) $v1->id, $returnedIds, 'first requested variation id must be returned');
        Assert::assertContains((int) $v2->id, $returnedIds, 'second requested variation id must be returned');
    }

    /**
     * Spec: Cases/Functional/products.md [11]
     *   "Admin can list product variations in a stable order."
     */
    public function adminCanListProductVariationsInStableOrder(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->createWithVariants([
            ['variation_title' => 'StableA'],
            ['variation_title' => 'StableB'],
            ['variation_title' => 'StableC'],
        ], ['post_title' => 'StableOrderProduct']);
        $createdIds = $product->variants->pluck('id')->map('intval')->toArray();

        [$status, $body1] = $I->get('/fluent-cart/v2/products/variants', [
            'params' => ['variant_ids' => $createdIds, 'order_by' => 'id', 'order_type' => 'ASC'],
        ]);
        $I->seeResponseCodeIs(200);

        [, $body2] = $I->get('/fluent-cart/v2/products/variants', [
            'params' => ['variant_ids' => $createdIds, 'order_by' => 'id', 'order_type' => 'ASC'],
        ]);

        $extract = static function ($body) {
            $variants = $body['variants'] ?? [];
            return array_map(static function ($v) {
                return (int) ($v['id'] ?? 0);
            }, is_array($variants) ? $variants : []);
        };

        $idsRun1 = $extract($body1);
        $idsRun2 = $extract($body2);

        foreach ($createdIds as $id) {
            Assert::assertContains($id, $idsRun1, "variant {$id} must appear in first listing");
        }

        Assert::assertSame(
            $idsRun1,
            $idsRun2,
            'two consecutive listings must return identical id ordering'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [12]
     *   "Admin can list every variant across all products for cross-product variant pickers."
     */
    public function adminCanListEveryVariantAcrossAllProducts(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ProductFactory();
        $multi = $factory->createWithVariants([
            ['variation_title' => 'CrossA1'],
            ['variation_title' => 'CrossA2'],
            ['variation_title' => 'CrossA3'],
        ], ['post_title' => 'CrossProdMulti']);
        $singleProduct = $factory->create(['post_title' => 'CrossProdSingle']);

        $createdVariantIds = $multi->variants->pluck('id')->map('intval')->toArray();
        $createdVariantIds[] = (int) $singleProduct->variants->first()->id;

        [$status, $body] = $I->get('/fluent-cart/v2/variants');

        $I->seeResponseCodeIs(200);
        Assert::assertIsArray($body, '/variants response must decode as array');

        $returnedVariantIds = array_map(static function ($v) {
            return (int) ($v['id'] ?? 0);
        }, $body);

        foreach ($createdVariantIds as $id) {
            Assert::assertContains(
                $id,
                $returnedVariantIds,
                "variant {$id} must appear in cross-product listing"
            );
        }
    }

    /**
     * Spec: Cases/Functional/products.md [13]
     *   "Admin can search variations by part of a variation title and only matching variations are returned."
     */
    public function adminCanSearchVariationsByPartialTitle(FunctionalTester $I): void
    {

        $I->loginAsSuperAdmin();
        $factory = new ProductFactory();
        $factory->withVariantAttributes(['variation_title' => 'ZinniaVariantUnique'])
                ->create(['post_title' => 'GenericProductOne']);
        $factory->withVariantAttributes(['variation_title' => 'OakVariantDifferent'])
                ->create(['post_title' => 'GenericProductTwo']);
        // A product matched through ONE variant must not leak its non-matching
        // sibling variants into the picker.
        $factory->createWithVariants([
            ['variation_title' => 'ZinniaSiblingMatch'],
            ['variation_title' => 'PlainSiblingNoMatch'],
        ], ['post_title' => 'GenericProductThree']);
        // A product matched by its own title returns ALL its variants.
        $factory->createWithVariants([
            ['variation_title' => 'AlphaChildOne'],
            ['variation_title' => 'AlphaChildTwo'],
        ], ['post_title' => 'ZinniaTitledProduct']);

        [$status, $body] = $I->get('/fluent-cart/v2/products/searchVariantByName', [
            'name' => 'Zinnia',
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertIsArray($body, 'searchVariantByName response must decode as array');

        $variantTitles = [];
        $productTitles = [];
        foreach ((array) $body as $product) {
            $productTitles[] = $product['label'] ?? '';
            foreach ((array) ($product['children'] ?? []) as $child) {
                $variantTitles[] = $child['label'] ?? '';
            }
        }
        $variantTitlesString = implode('|', $variantTitles);
        Assert::assertStringContainsString('ZinniaVariantUnique', $variantTitlesString, 'matching variation title must appear in response');
        Assert::assertStringNotContainsString('OakVariantDifferent', $variantTitlesString, 'non-matching variation title must be excluded');
        Assert::assertStringContainsString('ZinniaSiblingMatch', $variantTitlesString, 'the matching sibling on a mixed product must appear');
        Assert::assertStringNotContainsString('PlainSiblingNoMatch', $variantTitlesString, 'a non-matching sibling variant must not leak into the response when its product matched through another variant');
        Assert::assertStringContainsString('AlphaChildOne', $variantTitlesString, 'a product matched by its own title must return all its variants (first child)');
        Assert::assertStringContainsString('AlphaChildTwo', $variantTitlesString, 'a product matched by its own title must return all its variants (second child)');
    }

    /**
     * Spec: Cases/Functional/products.md [15]
     *   "Admin can request a list of subscription variations and only variations sold as subscriptions are returned."
     */
    public function adminCanRequestSubscriptionVariationsList(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ProductFactory();

        $subProduct = $factory->withVariantAttributes([
            'variation_title' => 'MonthlySub',
            'payment_type'    => 'subscription',
            'other_info'      => ['payment_type' => 'subscription'],
        ])->create(['post_title' => 'SubProd']);

        $oneTimeProduct = $factory->withVariantAttributes([
            'variation_title' => 'OneShotProd',
            'payment_type'    => 'onetime',
            'other_info'      => ['payment_type' => 'onetime'],
        ])->create(['post_title' => 'OneShotProd']);

        [$status, $body] = $I->get('/fluent-cart/v2/products/findSubscriptionVariants');

        $I->seeResponseCodeIs(200);
        Assert::assertIsArray($body, 'findSubscriptionVariants response must decode as array');
        $titles = array_map(static function ($v) {
            return $v['title'] ?? '';
        }, $body);

        Assert::assertContains('MonthlySub', $titles, 'subscription variation must appear in subscription-only listing');
        Assert::assertNotContains('OneShotProd', $titles, 'one-time variation must be excluded from subscription-only listing');
    }

    /**
     * Spec: Cases/Functional/products.md [16]
     *   "Admin can ask the system to suggest a unique SKU for a product title and the suggestion is not already in use."
     */
    public function adminCanRequestUniqueSkuSuggestionForProductTitle(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [, $firstBody] = $I->get('/fluent-cart/v2/products/suggest-sku', [
            'title' => 'Premium Widget',
        ]);
        $firstSku = $firstBody['sku'] ?? '';
        Assert::assertNotEmpty($firstSku, 'first response must contain a non-empty sku');

        $product = (new ProductFactory())->create(['post_title' => 'PreSeedSkuOwner']);
        $product->variants->first()->update(['sku' => $firstSku]);

        [$status, $body] = $I->get('/fluent-cart/v2/products/suggest-sku', [
            'title' => 'Premium Widget',
        ]);

        $I->seeResponseCodeIs(200);
        $sku = $body['sku'] ?? '';
        Assert::assertNotEmpty($sku, 'second response must contain a non-empty sku');
        Assert::assertNotSame(
            $firstSku,
            $sku,
            'suggested sku must differ from the seeded colliding sku'
        );

        global $wpdb;
        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fct_product_variations WHERE sku = %s",
            $sku
        ));
        Assert::assertSame(0, $existing, 'suggested sku must not already exist on any variation');
    }

    /**
     * Spec: Cases/Functional/products.md [17]
     *   "Admin is rejected when requesting an auto-generated SKU without providing a product title."
     */
    public function requestingSkuSuggestionWithoutTitleIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        [$status, $body] = $I->get('/fluent-cart/v2/products/suggest-sku');

        Assert::assertGreaterThanOrEqual(400, $status, 'request without title must not succeed');
        Assert::assertLessThan(500, $status, 'rejection must be a client error, not a server error');

        $message = $body['message'] ?? ($body['data']['message'] ?? null);
        Assert::assertNotEmpty($message, 'response must include an explanatory error message');
        Assert::assertStringContainsStringIgnoringCase(
            'title',
            (string) $message,
            'rejection message must identify the missing title field'
        );
        Assert::assertArrayNotHasKey(
            'sku',
            $body,
            'response must not return a generated sku when title is missing'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [18]
     *   "Admin can read the configured maximum excerpt word count for product descriptions."
     */
    public function adminCanReadMaxExcerptWordCount(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $sentinel = 73;
        $filter = static function () use ($sentinel) {
            return $sentinel;
        };
        add_filter('excerpt_length', $filter, 99);

        try {
            [$status, $body] = $I->get('/fluent-cart/v2/products/get-max-excerpt-word-count');

            $I->seeResponseCodeIs(200);
            Assert::assertArrayHasKey('count', $body, 'response must contain a count field');
            Assert::assertIsInt($body['count'], 'count must be an integer');
            Assert::assertSame(
                $sentinel,
                (int) $body['count'],
                'response count must reflect the configured excerpt_length filter value'
            );
        } finally {
            remove_filter('excerpt_length', $filter, 99);
        }
    }

    /**
     * Spec: Cases/Functional/products.md [19]
     *   "Admin can fetch the list of available product taxonomy terms — categories and brands; product tags are deliberately not shipped."
     *
     * Won't-ship decision 2026-08-06: FluentCart registers product-categories
     * and product-brands only. This pins the shipped catalog — a stray
     * product-tags registration reappearing would fail here.
     */
    public function adminCanFetchProductTaxonomyTermList(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $termFactory = new ProductTermFactory();
        $termFactory->createCategory(['name' => 'TermListCat']);
        $termFactory->createBrand(['name' => 'TermListBrand']);

        [$status, $body] = $I->get('/fluent-cart/v2/products/fetch-term');

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('taxonomies', $body, 'fetch-term response must contain a taxonomies key');
        $taxonomies = $body['taxonomies'];
        Assert::assertIsArray($taxonomies, 'taxonomies value must be an array');

        $names = array_map(static function ($t) {
            return $t['name'] ?? '';
        }, is_array($taxonomies) ? $taxonomies : iterator_to_array($taxonomies));
        Assert::assertContains('product-categories', $names, 'category taxonomy must be listed');
        Assert::assertContains('product-brands', $names, 'brands taxonomy must be listed');
        Assert::assertNotContains('product-tags', $names, 'product-tags is not shipped and must not appear in the taxonomy catalog');
    }

    /**
     * Spec: Cases/Functional/products.md [20]
     *   "Admin can fetch the child terms of a selected parent term."
     */
    public function adminCanFetchChildTermsOfParentTerm(FunctionalTester $I): void
    {

        $I->loginAsSuperAdmin();
        $termFactory = new ProductTermFactory();

        $parent = $termFactory->createCategory(['name' => 'SelectedParent']);
        $child  = $termFactory->createCategory([
            'name'   => 'SelectedChild',
            'parent' => $parent['term_id'],
        ]);

        $otherParent = $termFactory->createCategory(['name' => 'UnrelatedParent']);
        $otherChild  = $termFactory->createCategory([
            'name'   => 'UnrelatedChild',
            'parent' => $otherParent['term_id'],
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/products/fetch-term-by-parent', [
            'parents'   => [$parent['term_id']],
            'listeners' => [ProductTermFactory::TAXONOMY_CATEGORY],
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('data', $body, 'fetch-term-by-parent response must contain a data key');
        Assert::assertArrayHasKey(ProductTermFactory::TAXONOMY_CATEGORY, $body['data'], 'response data must contain entry for the requested taxonomy');

        $findInSubtree = static function ($subtree, int $needle) use (&$findInSubtree) {
            foreach ((array) $subtree as $node) {
                if ((int) ($node['value'] ?? $node['term_id'] ?? 0) === $needle) {
                    return true;
                }
                if (!empty($node['children']) && $findInSubtree($node['children'], $needle)) {
                    return true;
                }
            }
            return false;
        };

        $topLevelIds = array_map(static function ($n) {
            return (int) ($n['value'] ?? 0);
        }, (array) $body['data'][ProductTermFactory::TAXONOMY_CATEGORY]);

        Assert::assertContains(
            (int) $parent['term_id'],
            $topLevelIds,
            'selected parent must appear at the top level of the returned tree'
        );
        Assert::assertNotContains(
            (int) $otherParent['term_id'],
            $topLevelIds,
            'unrelated parent must not appear when only the selected parent is requested'
        );

        $selectedParentNode = null;
        foreach ((array) $body['data'][ProductTermFactory::TAXONOMY_CATEGORY] as $node) {
            if ((int) ($node['value'] ?? 0) === (int) $parent['term_id']) {
                $selectedParentNode = $node;
                break;
            }
        }

        Assert::assertNotNull(
            $selectedParentNode,
            'selected parent must appear in the returned tree'
        );
        Assert::assertTrue(
            $findInSubtree($selectedParentNode['children'] ?? [], (int) $child['term_id']),
            'selected parent subtree must contain its own child'
        );
        Assert::assertFalse(
            $findInSubtree($body['data'][ProductTermFactory::TAXONOMY_CATEGORY], (int) $otherChild['term_id']),
            'unrelated child must not appear anywhere in the response when only the selected parent is requested'
        );
    }
}
