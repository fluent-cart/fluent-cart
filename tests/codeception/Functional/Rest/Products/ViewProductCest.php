<?php

namespace Tests\Functional\Rest\Products;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductDownloadFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ProductTermFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Viewing a single product" section, items 21-26.
 */
class ViewProductCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
    }

    /**
     * Spec: Cases/Functional/products.md [21]
     *   "Admin can open any product by id and see the product with its details and
     *    variations; the downloadable_files relation is never exposed through the
     *    `with` parameter, even when explicitly requested."
     *
     * Contract hardened by b4cb8f1ea (#2524, 2026-08-06): `with` is resolved
     * against ProductController::allowedWiths(), which deliberately keeps
     * `downloadable_files` (protected file paths) off the map — unknown keys are
     * silently dropped, not errored. The edit screen loads downloads through its
     * own endpoint (ProductController::get). A download row is still seeded so
     * the absence assertion is not vacuous.
     */
    public function adminCanOpenProductByIdWithDetailsAndVariationsButNotDownloadableFiles(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'OpenById Probe']);
        $download = (new ProductDownloadFactory())->create((int) $product->ID, [
            'title' => 'OpenById Download Sample',
        ]);

        [$status, $body] = $I->get('/fluent-cart/v2/products/' . $product->ID, [
            'with' => ['detail', 'variants', 'downloadable_files'],
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('product', $body, 'response must contain a product key');
        Assert::assertSame((int) $product->ID, (int) ($body['product']['ID'] ?? 0), 'response product ID must match the requested product');
        Assert::assertArrayHasKey('detail', $body['product'], 'product payload must include the detail relation');
        Assert::assertArrayHasKey('variants', $body['product'], 'product payload must include the variants relation');
        Assert::assertNotEmpty($body['product']['variants'], 'product must surface its initial variant');
        $expectedVariantId = (int) $product->variants->first()->id;
        $variantIds = array_map(static function ($v) {
            return (int) ($v['id'] ?? 0);
        }, (array) $body['product']['variants']);
        Assert::assertContains($expectedVariantId, $variantIds, 'response variants must include the seeded variant id for the requested product');
        $variantPostIds = array_map(static function ($v) {
            return (int) ($v['post_id'] ?? 0);
        }, (array) $body['product']['variants']);
        foreach ($variantPostIds as $variantPostId) {
            Assert::assertSame((int) $product->ID, $variantPostId, 'every returned variant must belong to the opened product');
        }
        Assert::assertNotEmpty($download->id, 'the download row must exist in the DB so the absence assertion below is not vacuous');
        Assert::assertArrayNotHasKey(
            'downloadable_files',
            (array) $body['product'],
            'downloadable_files must NOT be exposed via the with parameter — it is deliberately off the allowedWiths() map (protected file paths, #2524)'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [23]
     *   "Admin can view a product's pricing widgets when configuring storefront blocks."
     */
    public function adminCanViewProductPricingWidgets(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'Widgets Probe']);

        [$status, $body] = $I->get('/fluent-cart/v2/products/' . $product->ID . '/pricing-widgets');

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('widgets', $body, 'response must contain a widgets array');
        Assert::assertNotEmpty($body['widgets'], 'widgets array must contain at least one widget');
        $first = $body['widgets'][0];
        Assert::assertArrayHasKey('title', $first, 'each widget must carry a title');
        Assert::assertArrayHasKey('body', $first, 'each widget must carry a body');
    }

    /**
     * Spec: Cases/Functional/products.md [24]
     *   "Admin can view related products for a given product, optionally constrained by category or brand."
     */
    public function adminCanViewRelatedProductsConstrainedByCategoryOrBrand(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ProductFactory();
        $hub = $factory->create(['post_title' => 'Related Hub']);
        $catSibling = $factory->create(['post_title' => 'Related CatSibling']);
        $brandSibling = $factory->create(['post_title' => 'Related BrandSibling']);
        $unrelated = $factory->create(['post_title' => 'Related Unrelated']);

        $termFactory = new ProductTermFactory();
        $cat = $termFactory->createCategory(['name' => 'RelatedCat ' . wp_generate_password(4, false, false)]);
        $brand = $termFactory->createBrand(['name' => 'RelatedBrand ' . wp_generate_password(4, false, false)]);

        $termFactory->attachToProduct($hub->ID, ProductTermFactory::TAXONOMY_CATEGORY, [$cat['term_id']]);
        $termFactory->attachToProduct($catSibling->ID, ProductTermFactory::TAXONOMY_CATEGORY, [$cat['term_id']]);
        $termFactory->attachToProduct($hub->ID, ProductTermFactory::TAXONOMY_BRAND, [$brand['term_id']]);
        $termFactory->attachToProduct($brandSibling->ID, ProductTermFactory::TAXONOMY_BRAND, [$brand['term_id']]);

        // Category constraint
        [, $catBody] = $I->get('/fluent-cart/v2/products/' . $hub->ID . '/related-products', [
            'related_by_categories' => 'true',
            'related_by_brands'     => 'false',
            'posts_per_page'        => 12,
        ]);
        $I->seeResponseCodeIs(200);
        $catRows = is_array($catBody['products'] ?? null) ? $catBody['products'] : [];
        $catIds = array_map(static function ($p) {
            return (int) ($p['ID'] ?? $p['id'] ?? 0);
        }, $catRows);
        Assert::assertContains((int) $catSibling->ID, $catIds, 'product sharing the chosen category must appear when related_by_categories=true');
        Assert::assertNotContains((int) $unrelated->ID, $catIds, 'product without the shared category must be excluded when related_by_categories=true');
        Assert::assertNotContains((int) $brandSibling->ID, $catIds, 'brand-only sibling must be excluded when only related_by_categories=true');

        // Brand constraint
        [, $brandBody] = $I->get('/fluent-cart/v2/products/' . $hub->ID . '/related-products', [
            'related_by_categories' => 'false',
            'related_by_brands'     => 'true',
            'posts_per_page'        => 12,
        ]);
        $I->seeResponseCodeIs(200);
        $brandRows = is_array($brandBody['products'] ?? null) ? $brandBody['products'] : [];
        $brandIds = array_map(static function ($p) {
            return (int) ($p['ID'] ?? $p['id'] ?? 0);
        }, $brandRows);
        Assert::assertContains((int) $brandSibling->ID, $brandIds, 'product sharing the chosen brand must appear when related_by_brands=true');
        Assert::assertNotContains((int) $unrelated->ID, $brandIds, 'product without the shared brand must be excluded when related_by_brands=true');
        Assert::assertNotContains((int) $catSibling->ID, $brandIds, 'category-only sibling must be excluded when only related_by_brands=true');
    }

    /**
     * Spec: Cases/Functional/products.md [25]
     *   "Admin can read a product's bundle configuration when it is a bundle."
     */
    public function adminCanReadProductBundleConfiguration(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ProductFactory();
        $childA = $factory->create([
            'post_title' => 'BundleChildA',
        ]);
        $childA->variants->first()->update(['variation_title' => 'BundleChildA-Variant']);
        $childB = $factory->create([
            'post_title' => 'BundleChildB',
        ]);
        $childB->variants->first()->update(['variation_title' => 'BundleChildB-Variant']);

        $childIds = [
            (int) $childA->variants->first()->id,
            (int) $childB->variants->first()->id,
        ];
        $bundle = $factory->createBundle($childIds, ['post_title' => 'BundleParent']);

        [$status, $body] = $I->get('/fluent-cart/v2/products/get-bundle-info/' . $bundle->ID);

        $I->seeResponseCodeIs(200);
        Assert::assertIsArray($body, 'bundle info response must decode as array');
        Assert::assertNotEmpty($body, 'bundle info response must contain at least the parent variant row');

        $parentVariantId = (int) $bundle->variants->first()->id;
        $parentRow = null;
        foreach ($body as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ((int) ($row['id'] ?? 0) === $parentVariantId) {
                $parentRow = $row;
                break;
            }
        }
        Assert::assertNotNull($parentRow, 'bundle info response must include the bundle parent variant row');

        Assert::assertArrayHasKey('bundle_child_ids', $parentRow, 'controller must surface a top-level bundle_child_ids on the parent variant row');
        $topLevelChildIds = array_map('intval', (array) $parentRow['bundle_child_ids']);
        Assert::assertContains($childIds[0], $topLevelChildIds, 'top-level bundle_child_ids must include the first child variant id');
        Assert::assertContains($childIds[1], $topLevelChildIds, 'top-level bundle_child_ids must include the second child variant id');

        Assert::assertArrayHasKey('child_variants', $parentRow, 'controller must surface a child_variants map on the parent variant row');
        $childVariants = (array) $parentRow['child_variants'];
        Assert::assertArrayHasKey($childIds[0], $childVariants, 'child_variants map must include the first child variant id as key');
        Assert::assertArrayHasKey($childIds[1], $childVariants, 'child_variants map must include the second child variant id as key');
        Assert::assertSame('BundleChildA-Variant', $childVariants[$childIds[0]]['variation_title'] ?? '', 'child_variants entry must carry the first child variant title');
        Assert::assertSame('BundleChildB-Variant', $childVariants[$childIds[1]]['variation_title'] ?? '', 'child_variants entry must carry the second child variant title');
    }

    /**
     * Spec: Cases/Functional/products.md [26]
     *   "Admin can fetch a product's pricing summary on its own."
     */
    public function adminCanFetchProductPricingSummary(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create([
            'post_title' => 'PricingSummary ' . wp_generate_password(8, false, false),
        ]);
        $expectedId = (int) $product->ID;
        $expectedPrice = (int) $product->variants->first()->item_price;

        [$status, $body] = $I->get('/fluent-cart/v2/products/' . $expectedId . '/pricing');

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('product', $body, 'pricing summary must contain product key');
        $returnedId = (int) ($body['product']['ID'] ?? $body['product']['id'] ?? 0);
        Assert::assertSame($expectedId, $returnedId, 'pricing summary product ID must match the requested product (body keys: ' . implode(',', array_keys($body['product'] ?? [])) . ')');
        $detail = $body['product']['detail'] ?? null;
        Assert::assertNotNull($detail, 'pricing summary must embed product detail');
        Assert::assertArrayHasKey('min_price', $detail, 'detail must expose min_price for pricing summary');
        Assert::assertArrayHasKey('max_price', $detail, 'detail must expose max_price for pricing summary');
        Assert::assertSame($expectedPrice, (int) ($detail['min_price'] ?? -1), 'min_price must equal the seeded variant price stored in cents');
        Assert::assertSame($expectedPrice, (int) ($detail['max_price'] ?? -1), 'max_price must equal the seeded variant price stored in cents');
        Assert::assertArrayHasKey('variants', $body['product'], 'pricing summary must embed variants');
    }
}
