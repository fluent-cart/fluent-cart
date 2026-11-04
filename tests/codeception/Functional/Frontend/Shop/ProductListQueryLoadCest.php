<?php

namespace Tests\Functional\Frontend\Shop;

use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ProductMetaFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\CountsDatabaseQueries;

/**
 * Spec: Cases/Functional/products.md → "Browsing the public storefront",
 * items 227-230.
 *
 * Route: GET /public/products (PublicPolicy, no auth) → ShopController::getProducts.
 *
 * These tests pin the SHAPE of the endpoint's database work, not a raw query
 * total. Each one grows the data and asserts the count for one query family
 * does not move: that is what an N+1 is, and a flat count is the only thing a
 * later refactor cannot silently break.
 *
 * The N+1s these cover all lived in SERIALIZATION, not in the list query —
 * they fire when the response is encoded, because ProductVariation::$appends
 * renders `thumbnail` from its `media` relation and ProductDetail::$appends
 * renders `featured_media` from `galleryImage`. The Functional module encodes
 * the body with wp_json_encode(), so dispatching through the actor exercises
 * them; a bare rest_do_request() would not.
 */
class ProductListQueryLoadCest
{
    use CountsDatabaseQueries;

    public function _before(FunctionalTester $I): void
    {
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/products.md [227]
     *   "Listing products reads variant thumbnails in one query however many variants are on the page."
     */
    public function variantThumbnailLookupsDoNotGrowWithVariantCount(FunctionalTester $I): void
    {
        (new ProductFactory())->createWithVariants([
            ['variation_title' => 'S'],
            ['variation_title' => 'M'],
            ['variation_title' => 'L'],
        ]);

        $smallPage = $this->recordQueries(function () use ($I) {
            $I->get('/fluent-cart/v2/public/products', ['per_page' => 10]);
        });

        $I->seeResponseCodeIs(200);

        $smallPageLookups = $this->countQueriesContaining($smallPage, ['fct_product_meta']);

        (new ProductFactory())->createWithVariants([
            ['variation_title' => 'XS'],
            ['variation_title' => 'S'],
            ['variation_title' => 'M'],
            ['variation_title' => 'L'],
            ['variation_title' => 'XL'],
            ['variation_title' => 'XXL'],
        ]);

        $largePage = $this->recordQueries(function () use ($I) {
            $I->get('/fluent-cart/v2/public/products', ['per_page' => 10]);
        });

        $I->seeResponseCodeIs(200);

        $largePageLookups = $this->countQueriesContaining($largePage, ['fct_product_meta']);

        Assert::assertSame(
            $smallPageLookups,
            $largePageLookups,
            'Product meta queries must not grow with the number of variants on the page — '
            . "3 variants issued {$smallPageLookups}, 9 variants issued {$largePageLookups}. "
            . 'ProductVariation::$appends renders `thumbnail` from the `media` relation, so '
            . 'the relation has to be eager loaded (ShopResource::expandAppendRelations).'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [228]
     *   "Listing products reads product gallery images in one query however many products are on the page."
     */
    public function galleryImageLookupsDoNotGrowWithProductCount(FunctionalTester $I): void
    {
        (new ProductFactory())->create();

        $onePage = $this->recordQueries(function () use ($I) {
            $I->get('/fluent-cart/v2/public/products', ['per_page' => 10]);
        });

        $I->seeResponseCodeIs(200);

        $oneProductLookups = $this->countQueriesContaining($onePage, ['postmeta', 'fluent-products-gallery-image']);

        (new ProductFactory())->create();
        (new ProductFactory())->create();
        (new ProductFactory())->create();

        $fourPage = $this->recordQueries(function () use ($I) {
            $I->get('/fluent-cart/v2/public/products', ['per_page' => 10]);
        });

        $I->seeResponseCodeIs(200);

        $fourProductLookups = $this->countQueriesContaining($fourPage, ['postmeta', 'fluent-products-gallery-image']);

        Assert::assertSame(
            $oneProductLookups,
            $fourProductLookups,
            'Gallery image queries must not grow with the number of products on the page — '
            . "1 product issued {$oneProductLookups}, 4 products issued {$fourProductLookups}. "
            . 'ProductDetail::$appends renders `featured_media` from the `galleryImage` relation.'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [229]
     *   "Listing products resolves product permalinks without one post lookup per product."
     */
    public function permalinkLookupsDoNotGrowWithProductCount(FunctionalTester $I): void
    {
        $products = [(new ProductFactory())->create()];

        // wp_insert_post() leaves every seeded product sitting in the object cache,
        // so get_permalink() would resolve it without touching the database and this
        // test would pass whether or not the N+1 exists. A real request starts cold;
        // dropping the seeded posts from the cache is what restores that.
        foreach ($products as $product) {
            clean_post_cache($product->ID);
        }

        // Built from $wpdb->posts, not hard-coded: the test database runs a
        // different table prefix from a real install, and a fragment that never
        // matches counts zero every time — the test would pass either way.
        global $wpdb;
        $singlePostRead = [$wpdb->posts . ' where id ='];

        $onePage = $this->recordQueries(function () use ($I) {
            $I->get('/fluent-cart/v2/public/products', ['per_page' => 10]);
        });

        $I->seeResponseCodeIs(200);

        $oneProductLookups = $this->countQueriesContaining($onePage, $singlePostRead);

        $products[] = (new ProductFactory())->create();
        $products[] = (new ProductFactory())->create();
        $products[] = (new ProductFactory())->create();

        foreach ($products as $product) {
            clean_post_cache($product->ID);
        }

        $fourPage = $this->recordQueries(function () use ($I) {
            $I->get('/fluent-cart/v2/public/products', ['per_page' => 10]);
        });

        $I->seeResponseCodeIs(200);

        $fourProductLookups = $this->countQueriesContaining($fourPage, $singlePostRead);

        Assert::assertSame(
            $oneProductLookups,
            $fourProductLookups,
            'Single-post lookups must not grow with the number of products on the page — '
            . "1 product issued {$oneProductLookups}, 4 products issued {$fourProductLookups}. "
            . 'The `view_url` append calls get_permalink(), which falls through to get_post() '
            . 'unless ShopResource::primePostCaches() has warmed the page.'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [230]
     *   "A variant's thumbnail is still returned in the product list."
     */
    public function variantThumbnailIsStillReturnedInTheListing(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create();
        $variant = $product->variants->first();

        (new ProductMetaFactory())->create($variant->id, [
            'meta_key'   => 'product_thumbnail',
            'meta_value' => [['url' => 'https://example.test/thumb.png']],
        ]);

        $I->get('/fluent-cart/v2/public/products', ['per_page' => 10]);
        $I->seeResponseCodeIs(200);

        $body = $I->grabResponseJson();
        $rows = $body['products']['products']['data'] ?? [];

        Assert::assertCount(1, $rows, 'The seeded product should be the only row in the listing.');
        Assert::assertArrayHasKey('variants', $rows[0], 'The listing row should carry its variants.');
        Assert::assertSame(
            'https://example.test/thumb.png',
            $rows[0]['variants'][0]['thumbnail'] ?? null,
            'Eager loading the media relation must not change what `thumbnail` serializes to.'
        );
    }
}
