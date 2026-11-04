<?php

namespace Tests\Functional\Frontend\Shop;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Models\ProductDetail;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Browsing the public storefront",
 * items 200-211 + 215.
 *
 * Routes (under /public, PublicPolicy, no auth required):
 *   GET /public/products        ShopController::getProducts
 *   GET /public/product-views   ShopController::getProductViews
 *
 * Response shape: { "products": { "products": <paginator>, "total": N } }
 * The paginator's data array is at $body['products']['products']['data'].
 */
class BrowsePublicStorefrontCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    public function _after(FunctionalTester $I): void
    {
        // Always reset the stock_management module flag back to its default-off
        // state so a test that flipped it on (items 202/203) does not leak
        // into sibling tests within this Cest, into other Cests in the suite,
        // or into the wp_options row that survives Codeception's per-test DB
        // reset (resetFluentCartTables only truncates fct_* tables).
        delete_option(ModuleSettings::MODULE_SETTINGS_OPTION);
        // Bust the in-process static cache inside ModuleSettings::getAllSettings.
        ModuleSettings::getAllSettings(false);
    }

    private function enableStockManagementModule(): void
    {
        $settings = ModuleSettings::getAllSettings(false);
        $settings['stock_management'] = ['active' => 'yes'];
        ModuleSettings::saveSettings($settings);
        // Reset the static cache.
        ModuleSettings::getAllSettings(false);
    }

    private function extractProductRows(array $body): array
    {
        return (array) ($body['products']['products']['data'] ?? []);
    }

    private function extractProductIds(array $body): array
    {
        return array_map(static fn ($r) => (int) ($r['ID'] ?? 0), $this->extractProductRows($body));
    }

    /**
     * Spec: Cases/Functional/products.md [200]
     *   "A logged-out visitor can list public products and only published products are returned."
     */
    public function logoutVisitorCanListPublicProductsOnlyPublishedReturned(FunctionalTester $I): void
    {
        $I->logout();
        $published = (new ProductFactory())->create(['post_title' => 'PublicPublished200', 'post_status' => 'publish']);
        $draft     = (new ProductFactory())->create(['post_title' => 'PublicDraft200',     'post_status' => 'draft']);

        [, $body] = $I->get('/fluent-cart/v2/public/products');

        $I->seeResponseCodeIs(200);
        $ids = $this->extractProductIds($body);
        Assert::assertContains((int) $published->ID, $ids, 'public list must include the published product');
        Assert::assertNotContains((int) $draft->ID, $ids, 'public list must NOT include any draft product');
    }

    /**
     * Spec: Cases/Functional/products.md [201]
     *   "A logged-out visitor sees draft and pending products hidden from the public product list."
     */
    public function logoutVisitorSeesDraftAndPendingProductsHiddenFromPublicProductList(FunctionalTester $I): void
    {
        $I->logout();
        $publish = (new ProductFactory())->create(['post_title' => 'PublishHidden201', 'post_status' => 'publish']);
        $draft   = (new ProductFactory())->create(['post_title' => 'DraftHidden201',   'post_status' => 'draft']);
        $pending = (new ProductFactory())->create(['post_title' => 'PendingHidden201', 'post_status' => 'pending']);

        [, $body] = $I->get('/fluent-cart/v2/public/products');

        $I->seeResponseCodeIs(200);
        $ids = $this->extractProductIds($body);
        Assert::assertContains((int) $publish->ID, $ids, 'publish product must appear');
        Assert::assertNotContains((int) $draft->ID, $ids, 'draft product must NOT appear');
        Assert::assertNotContains((int) $pending->ID, $ids, 'pending product must NOT appear');
    }

    /**
     * Spec: Cases/Functional/products.md [202]
     *   "A logged-out visitor sees out-of-stock products hidden from the public product list by default."
     */
    public function logoutVisitorSeesOutOfStockProductsHiddenFromPublicProductListByDefault(FunctionalTester $I): void
    {
        $this->enableStockManagementModule();
        $I->logout();
        $inStock = (new ProductFactory())
            ->withDetailAttributes(['stock_availability' => 'in-stock'])
            ->create(['post_title' => 'InStock202']);
        $outOfStock = (new ProductFactory())
            ->withDetailAttributes(['stock_availability' => 'out-of-stock'])
            ->create(['post_title' => 'OutOfStock202']);

        [, $body] = $I->get('/fluent-cart/v2/public/products');

        $I->seeResponseCodeIs(200);
        $ids = $this->extractProductIds($body);
        Assert::assertContains((int) $inStock->ID, $ids, 'in-stock product must appear in default public list');
        Assert::assertNotContains((int) $outOfStock->ID, $ids, 'out-of-stock product must NOT appear when stock_management is active and allow_out_of_stock=false');
    }

    /**
     * Spec: Cases/Functional/products.md [203]
     *   "A logged-out visitor sees out-of-stock products included in the public product list when choosing that option."
     */
    public function logoutVisitorSeesOutOfStockProductsIncludedWhenChoosingThatOption(FunctionalTester $I): void
    {
        $this->enableStockManagementModule();
        $I->logout();
        $outOfStock = (new ProductFactory())
            ->withDetailAttributes(['stock_availability' => 'out-of-stock'])
            ->create(['post_title' => 'OutOfStock203']);

        [, $body] = $I->get('/fluent-cart/v2/public/products', ['allow_out_of_stock' => 1]);

        $I->seeResponseCodeIs(200);
        $ids = $this->extractProductIds($body);
        Assert::assertContains((int) $outOfStock->ID, $ids, 'out-of-stock product must appear when allow_out_of_stock=1 is requested');
    }

    /**
     * Spec: Cases/Functional/products.md [204]
     *   "A logged-out visitor does not see item cost or raw stock levels on the public product list."
     */
    public function logoutVisitorDoesNotSeeItemCostOrRawStockLevelsOnPublicProductList(FunctionalTester $I): void
    {
        $I->logout();
        (new ProductFactory())
            ->withDetailAttributes([
                'item_cost'   => 999,
                'manage_stock' => 1,
            ])
            ->withVariantAttributes([
                'available'   => 42,
                'total_stock' => 42,
                'item_cost'   => 999,
            ])
            ->create(['post_title' => 'NoCost204', 'post_content' => 'long body content here']);

        [, $body] = $I->get('/fluent-cart/v2/public/products', ['with' => ['detail', 'variants']]);

        $I->seeResponseCodeIs(200);
        $row = $this->extractProductRows($body)[0] ?? [];
        Assert::assertNotEmpty($row, 'public list must include the seeded product as a sanity check before assertion of hidden fields');
        $detail = (array) ($row['detail'] ?? []);
        Assert::assertArrayNotHasKey('item_cost', $detail, 'detail.item_cost must be hidden from the public payload (admin-only field)');
        Assert::assertArrayNotHasKey('stock', $detail, 'detail.stock must be hidden from the public payload (admin-only field)');
        Assert::assertArrayNotHasKey('manage_stock', $detail, 'detail.manage_stock must be hidden from the public payload');
        Assert::assertArrayNotHasKey('manage_cost', $detail, 'detail.manage_cost must be hidden from the public payload');
        // Variant raw stock levels: also forbidden from the public payload.
        $variants = (array) ($row['variants'] ?? []);
        Assert::assertNotEmpty($variants, 'public list must surface the variants relation when requested via with[]=variants (sanity check before asserting raw stock hidden)');
        foreach ($variants as $variant) {
            $variantRow = (array) $variant;
            Assert::assertArrayNotHasKey('item_cost', $variantRow, 'variant.item_cost must be hidden from the public payload (admin-only)');
            Assert::assertArrayNotHasKey('available', $variantRow, 'variant.available raw stock count must be hidden from the public payload');
            Assert::assertArrayNotHasKey('total_stock', $variantRow, 'variant.total_stock raw stock count must be hidden from the public payload');
        }
    }

    /**
     * Spec: Cases/Functional/products.md [204a]
     *   "A logged-out visitor does not see item cost or raw stock levels even when variants are
     *    not explicitly requested (the has_subscription accessor lazy-loads them)."
     */
    public function logoutVisitorDoesNotSeeVariantCostOrStockWhenVariantsNotRequested(FunctionalTester $I): void
    {
        $I->logout();
        (new ProductFactory())
            ->withVariantAttributes([
                'available'   => 42,
                'total_stock' => 42,
                'item_cost'   => 999,
            ])
            ->create(['post_title' => 'NoCost204a']);

        // No with[]=variants — the appended has_subscription accessor still lazy-loads the
        // variants relation during serialization, so the payload must not surface raw fields.
        [, $body] = $I->get('/fluent-cart/v2/public/products');

        $I->seeResponseCodeIs(200);
        $row = $this->extractProductRows($body)[0] ?? [];
        Assert::assertNotEmpty($row, 'public list must include the seeded product');
        Assert::assertArrayHasKey('has_subscription', $row, 'has_subscription accessor must still be present (it lazy-loads variants)');

        $variants = (array) ($row['variants'] ?? []);
        Assert::assertNotEmpty($variants, 'has_subscription lazy-loads variants, so they appear in the serialized payload even without with[]=variants (sanity check before asserting fields hidden)');
        foreach ($variants as $variant) {
            $variantRow = (array) $variant;
            Assert::assertArrayNotHasKey('item_cost', $variantRow, 'lazy-loaded variant.item_cost must be hidden even without with[]=variants');
            Assert::assertArrayNotHasKey('manage_cost', $variantRow, 'lazy-loaded variant.manage_cost must be hidden even without with[]=variants');
            Assert::assertArrayNotHasKey('available', $variantRow, 'lazy-loaded variant.available raw stock count must be hidden even without with[]=variants');
            Assert::assertArrayNotHasKey('total_stock', $variantRow, 'lazy-loaded variant.total_stock raw stock count must be hidden even without with[]=variants');
        }
    }

    /**
     * Spec: Cases/Functional/products.md [204b]
     *   "A logged-out visitor does not see admin-only variant other_info keys (tax class,
     *    tax exempt, package slug, bundle child ids) on the public product list."
     */
    public function logoutVisitorDoesNotSeeAdminOnlyOtherInfoKeysOnPublicVariants(FunctionalTester $I): void
    {
        $I->logout();
        (new ProductFactory())
            ->withVariantAttributes([
                'other_info' => [
                    'payment_type'      => 'onetime',
                    'repeat_interval'   => 'yearly',
                    'tax_class'         => 'reduced-rate',
                    'tax_exempt'        => 'yes',
                    'package_slug'      => 'internal-pkg',
                    'bundle_child_ids'  => [101, 102],
                    'variation_type'    => 'simple',
                    'is_bundle_product' => 'no',
                ],
            ])
            ->create(['post_title' => 'OtherInfo204b']);

        [, $body] = $I->get('/fluent-cart/v2/public/products', ['with' => ['variants']]);

        $I->seeResponseCodeIs(200);
        $row = $this->extractProductRows($body)[0] ?? [];
        Assert::assertNotEmpty($row, 'public list must include the seeded product');
        $variants = (array) ($row['variants'] ?? []);
        Assert::assertNotEmpty($variants, 'variants must be present (sanity check)');
        foreach ($variants as $variant) {
            $info = (array) (((array) $variant)['other_info'] ?? []);
            foreach (['tax_class', 'tax_exempt', 'package_slug', 'bundle_child_ids', 'variation_type', 'is_bundle_product'] as $internalKey) {
                Assert::assertArrayNotHasKey($internalKey, $info, "variant other_info.{$internalKey} must be redacted from the public payload");
            }
            // Display keys the storefront needs must survive the redaction.
            Assert::assertArrayHasKey('payment_type', $info, 'variant other_info.payment_type (storefront display) must remain public');
        }
    }

    /**
     * Spec: Cases/Functional/products.md [205]
     *   "A logged-out visitor does not see the long product description on the public product list."
     */
    public function logoutVisitorDoesNotSeeLongProductDescriptionOnPublicProductList(FunctionalTester $I): void
    {
        $I->logout();
        (new ProductFactory())->create([
            'post_title'   => 'NoLongDesc205',
            'post_content' => 'This is a long description that should not appear in the public product list payload.',
        ]);

        [, $body] = $I->get('/fluent-cart/v2/public/products');

        $I->seeResponseCodeIs(200);
        $row = $this->extractProductRows($body)[0] ?? [];
        Assert::assertNotEmpty($row, 'public list must include the seeded product as a sanity check');
        Assert::assertArrayNotHasKey('post_content', $row, 'post_content must be hidden from the public payload (long description belongs on the product detail page only)');
    }

    /**
     * Spec: Cases/Functional/products.md [206]
     *   "A logged-out visitor can filter the public product list by category, tag, or brand term and only matching products are returned."
     */
    public function logoutVisitorCanFilterPublicProductListByCategoryOrBrandTermAndOnlyMatchingProductsAreReturned(FunctionalTester $I): void
    {
        $I->logout();
        $catMatch     = (new ProductFactory())->create(['post_title' => 'CatMatch206']);
        $brandMatch   = (new ProductFactory())->create(['post_title' => 'BrandMatch206']);
        $nonMatching  = (new ProductFactory())->create(['post_title' => 'NoMatch206']);

        // product-categories: one term assigned to the cat-match product only.
        $catTerm   = wp_insert_term('Functional Cat 206', 'product-categories');
        $catTermId = (int) ($catTerm['term_id'] ?? 0);
        Assert::assertGreaterThan(0, $catTermId, 'fixture must successfully create a product-categories term');
        wp_set_object_terms((int) $catMatch->ID, [$catTermId], 'product-categories');

        // product-brands: one term assigned to the brand-match product only.
        $brandTerm   = wp_insert_term('Functional Brand 206', 'product-brands');
        $brandTermId = (int) ($brandTerm['term_id'] ?? 0);
        Assert::assertGreaterThan(0, $brandTermId, 'fixture must successfully create a product-brands term');
        wp_set_object_terms((int) $brandMatch->ID, [$brandTermId], 'product-brands');

        // Filter by category — must include only the cat-tagged product.
        [, $catBody] = $I->get('/fluent-cart/v2/public/products', [
            'filters' => ['product-categories' => [$catTermId]],
        ]);
        $I->seeResponseCodeIs(200);
        $catIds = $this->extractProductIds($catBody);
        Assert::assertContains((int) $catMatch->ID, $catIds, 'category filter must include the product carrying the chosen category term');
        Assert::assertNotContains((int) $brandMatch->ID, $catIds, 'category filter must NOT include products carrying only a brand term');
        Assert::assertNotContains((int) $nonMatching->ID, $catIds, 'category filter must NOT include products without that category term');

        // Filter by brand — must include only the brand-tagged product.
        [, $brandBody] = $I->get('/fluent-cart/v2/public/products', [
            'filters' => ['product-brands' => [$brandTermId]],
        ]);
        $I->seeResponseCodeIs(200);
        $brandIds = $this->extractProductIds($brandBody);
        Assert::assertContains((int) $brandMatch->ID, $brandIds, 'brand filter must include the product carrying the chosen brand term');
        Assert::assertNotContains((int) $catMatch->ID, $brandIds, 'brand filter must NOT include products carrying only a category term');
        Assert::assertNotContains((int) $nonMatching->ID, $brandIds, 'brand filter must NOT include products without that brand term');

        // No tag case: product tags are not shipped (products.md item 19, won't-ship
        // decision 2026-08-06), so only categories and brands can be filtered.
    }

    /**
     * Spec: Cases/Functional/products.md [207]
     *   "A logged-out visitor can sort the public product list and the requested order is honoured."
     */
    public function logoutVisitorCanSortPublicProductListAndRequestedOrderIsHonoured(FunctionalTester $I): void
    {
        $I->logout();
        // Seed three with deliberately non-alphabetical post_titles so name-asc
        // produces a different order than insertion order.
        $b = (new ProductFactory())->create(['post_title' => 'Beta Sort 207']);
        $a = (new ProductFactory())->create(['post_title' => 'Alpha Sort 207']);
        $c = (new ProductFactory())->create(['post_title' => 'Charlie Sort 207']);

        [, $bodyAsc] = $I->get('/fluent-cart/v2/public/products', [
            'filters' => ['sort_by' => 'name-asc'],
        ]);
        $I->seeResponseCodeIs(200);
        $ascIds = $this->extractProductIds($bodyAsc);
        Assert::assertSame(
            [(int) $a->ID, (int) $b->ID, (int) $c->ID],
            $ascIds,
            'name-asc must order Alpha → Beta → Charlie regardless of insertion order'
        );

        [, $bodyDesc] = $I->get('/fluent-cart/v2/public/products', [
            'filters' => ['sort_by' => 'name-desc'],
        ]);
        $I->seeResponseCodeIs(200);
        $descIds = $this->extractProductIds($bodyDesc);
        Assert::assertSame(
            [(int) $c->ID, (int) $b->ID, (int) $a->ID],
            $descIds,
            'name-desc must reverse the order to Charlie → Beta → Alpha'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [207]
     *   "A logged-out visitor can sort the public product list and the requested order is honoured."
     *
     * (Companion to the name-asc / name-desc method: also covers the price-low
     * and price-high sort_by mappings, which join fct_product_details and order
     * by min_price. Split into its own method so the _before reset gives the
     * fixture a clean DB before seeding distinct min_price values.)
     */
    public function logoutVisitorCanSortPublicProductListByPriceAndRequestedOrderIsHonoured(FunctionalTester $I): void
    {
        $I->logout();
        $cheap = (new ProductFactory())
            ->withDetailAttributes(['min_price' => 1000, 'max_price' => 1000])
            ->withVariantAttributes(['item_price' => 1000])
            ->create(['post_title' => 'Cheap 207']);
        $mid = (new ProductFactory())
            ->withDetailAttributes(['min_price' => 5000, 'max_price' => 5000])
            ->withVariantAttributes(['item_price' => 5000])
            ->create(['post_title' => 'Mid 207']);
        $expensive = (new ProductFactory())
            ->withDetailAttributes(['min_price' => 9000, 'max_price' => 9000])
            ->withVariantAttributes(['item_price' => 9000])
            ->create(['post_title' => 'Expensive 207']);
        // ProductFactory's post-create update of detail.min_price/max_price hits
        // a model-cache edge case in this test process (read-back returns 0).
        // Force the DB write directly with $wpdb so the price-* sort columns are
        // deterministic regardless of the factory's caching behavior.
        global $wpdb;
        $detailsTable = $wpdb->prefix . 'fct_product_details';
        $wpdb->update($detailsTable, ['min_price' => 1000, 'max_price' => 1000], ['post_id' => (int) $cheap->ID]);
        $wpdb->update($detailsTable, ['min_price' => 5000, 'max_price' => 5000], ['post_id' => (int) $mid->ID]);
        $wpdb->update($detailsTable, ['min_price' => 9000, 'max_price' => 9000], ['post_id' => (int) $expensive->ID]);
        $minPrices = [
            (int) $wpdb->get_var($wpdb->prepare("SELECT min_price FROM {$detailsTable} WHERE post_id = %d", (int) $cheap->ID)),
            (int) $wpdb->get_var($wpdb->prepare("SELECT min_price FROM {$detailsTable} WHERE post_id = %d", (int) $mid->ID)),
            (int) $wpdb->get_var($wpdb->prepare("SELECT min_price FROM {$detailsTable} WHERE post_id = %d", (int) $expensive->ID)),
        ];
        Assert::assertSame([1000, 5000, 9000], $minPrices, 'fixture must seed distinct min_price values so price-low/price-high produce a deterministic order');

        [, $bodyPriceLow] = $I->get('/fluent-cart/v2/public/products', [
            'filters' => ['sort_by' => 'price-low'],
        ]);
        $I->seeResponseCodeIs(200);
        $priceLowIds = $this->extractProductIds($bodyPriceLow);
        Assert::assertSame(
            [(int) $cheap->ID, (int) $mid->ID, (int) $expensive->ID],
            $priceLowIds,
            'price-low must order Cheap (1000) → Mid (5000) → Expensive (9000)'
        );

        [, $bodyPriceHigh] = $I->get('/fluent-cart/v2/public/products', [
            'filters' => ['sort_by' => 'price-high'],
        ]);
        $I->seeResponseCodeIs(200);
        $priceHighIds = $this->extractProductIds($bodyPriceHigh);
        Assert::assertSame(
            [(int) $expensive->ID, (int) $mid->ID, (int) $cheap->ID],
            $priceHighIds,
            'price-high must reverse to Expensive (9000) → Mid (5000) → Cheap (1000)'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [208]
     *   "A logged-out visitor sees the chosen number of products per page on the public product list when requesting a specific page size."
     */
    public function logoutVisitorSeesChosenNumberOfProductsPerPageWhenRequestingSpecificPageSize(FunctionalTester $I): void
    {
        $I->logout();
        (new ProductFactory())->create(['post_title' => 'Page208 P1']);
        (new ProductFactory())->create(['post_title' => 'Page208 P2']);
        (new ProductFactory())->create(['post_title' => 'Page208 P3']);
        (new ProductFactory())->create(['post_title' => 'Page208 P4']);
        (new ProductFactory())->create(['post_title' => 'Page208 P5']);

        [, $body] = $I->get('/fluent-cart/v2/public/products', ['per_page' => 2]);

        $I->seeResponseCodeIs(200);
        Assert::assertCount(2, $this->extractProductRows($body), 'response data array must contain exactly two products when per_page=2 is requested (paginator must respect the requested page size, not the default 10)');
    }

    /**
     * Spec: Cases/Functional/products.md [209]
     *   "A logged-out visitor can include or exclude specific products when listing the public catalogue."
     */
    public function logoutVisitorCanIncludeOrExcludeSpecificProductsWhenListingPublicCatalogue(FunctionalTester $I): void
    {
        $I->logout();
        $a = (new ProductFactory())->create(['post_title' => 'Include209 A']);
        $b = (new ProductFactory())->create(['post_title' => 'Include209 B']);
        $c = (new ProductFactory())->create(['post_title' => 'Include209 C']);

        // include_ids: should restrict to only the two requested ids
        [, $bodyInclude] = $I->get('/fluent-cart/v2/public/products', [
            'include_ids' => [(int) $a->ID, (int) $b->ID],
        ]);
        $I->seeResponseCodeIs(200);
        $includeIds = $this->extractProductIds($bodyInclude);
        Assert::assertContains((int) $a->ID, $includeIds, 'include_ids list must include product A');
        Assert::assertContains((int) $b->ID, $includeIds, 'include_ids list must include product B');
        Assert::assertNotContains((int) $c->ID, $includeIds, 'include_ids list must EXCLUDE product C (not in the include set)');

        // exclude_ids: should drop the requested id while keeping the others
        [, $bodyExclude] = $I->get('/fluent-cart/v2/public/products', [
            'exclude_ids' => [(int) $a->ID],
        ]);
        $I->seeResponseCodeIs(200);
        $excludeIds = $this->extractProductIds($bodyExclude);
        Assert::assertNotContains((int) $a->ID, $excludeIds, 'exclude_ids list must drop product A');
        Assert::assertContains((int) $b->ID, $excludeIds, 'exclude_ids list must keep product B');
        Assert::assertContains((int) $c->ID, $excludeIds, 'exclude_ids list must keep product C');
    }

    /**
     * Spec: Cases/Functional/products.md [210]
     *   "A logged-out visitor can request only on-sale products on the public list."
     */
    public function logoutVisitorCanRequestOnlyOnSaleProductsOnPublicList(FunctionalTester $I): void
    {
        $I->logout();
        // On-sale: variant has compare_price > item_price (the original price was higher than current)
        $onSale = (new ProductFactory())
            ->withVariantAttributes(['item_price' => 1000, 'compare_price' => 2000])
            ->create(['post_title' => 'OnSale210']);
        $regular = (new ProductFactory())
            ->withVariantAttributes(['item_price' => 1500, 'compare_price' => 0])
            ->create(['post_title' => 'Regular210']);

        [, $body] = $I->get('/fluent-cart/v2/public/products', ['on_sale' => 1]);

        $I->seeResponseCodeIs(200);
        $ids = $this->extractProductIds($body);
        Assert::assertContains((int) $onSale->ID, $ids, 'on-sale product (item_price 1000 < compare_price 2000) must appear when on_sale=1 is requested');
        Assert::assertNotContains((int) $regular->ID, $ids, 'regular product (compare_price=0) must NOT appear when on_sale=1 is requested');
    }

    /**
     * Spec: Cases/Functional/products.md [211]
     *   "A logged-out visitor sees the configured storefront product list view when one is provided."
     */
    public function logoutVisitorSeesConfiguredStorefrontProductListViewWhenOneIsProvided(FunctionalTester $I): void
    {
        $I->logout();
        (new ProductFactory())->create(['post_title' => 'TemplateView211']);

        $marker         = '<!-- functional-211-template-marker -->';
        $expectedView   = $marker . '<div class="custom-shop">rendered</div>';
        $templateName   = 'functional211';
        $filterName     = 'fluent_cart/products_views/preload_collection_' . $templateName;
        $callback       = static function ($default) use ($expectedView) {
            return $expectedView;
        };
        add_filter($filterName, $callback, 10, 1);

        try {
            [, $body] = $I->get('/fluent-cart/v2/public/product-views', [
                'template_provider' => $templateName,
                'client_id'         => 'fct-test-211',
            ]);
        } finally {
            remove_filter($filterName, $callback, 10);
        }

        $I->seeResponseCodeIs(200);
        $views = (string) ($body['products']['views'] ?? '');
        Assert::assertStringContainsString($marker, $views, 'response must surface the configured template view rendered by the registered preload_collection_<provider> filter');
    }

    /**
     * Spec: Cases/Functional/products.md [215]
     *   "Customer sees the same public product list as a logged-out visitor when browsing the storefront."
     */
    public function customerSeesSamePublicProductListAsLoggedOutVisitorWhenBrowsingStorefront(FunctionalTester $I): void
    {
        $a = (new ProductFactory())->create(['post_title' => 'Same215 A']);
        $b = (new ProductFactory())->create(['post_title' => 'Same215 B']);

        $I->logout();
        [, $guestBody] = $I->get('/fluent-cart/v2/public/products');
        $I->seeResponseCodeIs(200);
        $guestIds = $this->extractProductIds($guestBody);

        $I->loginAs('customer');
        [, $customerBody] = $I->get('/fluent-cart/v2/public/products');
        $I->seeResponseCodeIs(200);
        $customerIds = $this->extractProductIds($customerBody);

        Assert::assertSame(
            $guestIds,
            $customerIds,
            'customer-facing public list must return the exact same id set in the same order as the guest-facing list'
        );
        Assert::assertContains((int) $a->ID, $customerIds, 'customer list must include the seeded product A (sanity check)');
        Assert::assertContains((int) $b->ID, $customerIds, 'customer list must include the seeded product B (sanity check)');
    }
}
