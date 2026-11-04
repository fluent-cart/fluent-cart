<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Models\AttributeTerm;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductMeta;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\ProductTermFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsProductUpdatePayloads;

/**
 * Spec: Cases/Functional/products.md → "Updating a product" section, items 47-70.
 */
class UpdateProductCest
{
    use BuildsProductUpdatePayloads;

    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/products.md [47]
     *   "Admin can rename a product."
     */
    public function adminCanRenameProduct(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'OldName']);
        $payload = $this->buildUpdatePayload($product, ['post_title' => 'RenamedProduct']);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);

        $I->seeResponseCodeIs(200);
        $refreshed = Product::query()->find($product->ID);
        Assert::assertSame('RenamedProduct', $refreshed->post_title, 'wp_posts row must persist the renamed title');
    }

    /**
     * Spec: Cases/Functional/products.md [48]
     *   "Admin can change a product's description."
     */
    public function adminCanChangeProductDescription(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'Desc48']);
        $payload = $this->buildUpdatePayload($product, ['post_content' => 'Updated long description body']);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);

        $I->seeResponseCodeIs(200);
        $refreshed = get_post($product->ID);
        Assert::assertSame('Updated long description body', $refreshed->post_content, 'wp_posts row must persist the updated post_content');
    }

    /**
     * Spec: Cases/Functional/products.md [49]
     *   "Admin can update a product's status."
     */
    public function adminCanUpdateProductStatus(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'Status49', 'post_status' => 'publish']);
        $payload = $this->buildUpdatePayload($product, ['post_status' => 'draft']);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);

        $I->seeResponseCodeIs(200);
        $refreshed = get_post($product->ID);
        Assert::assertSame('draft', $refreshed->post_status, 'wp_posts row must persist the new draft status after update');
    }

    /**
     * Spec: Cases/Functional/products.md [50]
     *   "Admin can change a product's variation prices."
     */
    public function adminCanChangeProductVariationPrices(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'PriceChange50']);
        $payload = $this->buildUpdatePayload($product, [], [['item_price' => \FluentCart\App\Helpers\Helper::toCent(88.50)]]);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);

        $I->seeResponseCodeIs(200);
        $variant = ProductVariation::query()->where('post_id', $product->ID)->first();
        Assert::assertSame(8850, (int) $variant->item_price, 'variant price must persist as cents (8850 for $88.50)');
    }

    /**
     * Spec: Cases/Functional/products.md [51]
     *   "Admin sees variation prices stored consistently in cents (e.g. $12.34 round-trips correctly)."
     */
    public function variationPricesAreStoredConsistentlyInCents(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'Cents51']);
        $payload = $this->buildUpdatePayload($product, [], [['item_price' => \FluentCart\App\Helpers\Helper::toCent(12.34)]]);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);

        $I->seeResponseCodeIs(200);
        $variant = ProductVariation::query()->where('post_id', $product->ID)->first();
        Assert::assertSame(1234, (int) $variant->item_price, '$12.34 must round-trip to 1234 cents in the DB');
    }

    /**
     * The product editor save reads variant money as CENTS — the same unit it returns on
     * read and stores — so a client can send back exactly what it was given.
     *
     * This is the integrator-reported case that motivated the contract change: sending
     * 129900 meaning $1,299.00 previously produced a $129,900.00 product, because the
     * write path multiplied by 100 while every read returned cents.
     *
     * The literal 129900 is deliberate. Writing Helper::toCent(1299) would hide the thing
     * under test, which is that the endpoint does not scale what it is handed.
     *
     * See dev-docs/PRICING-AND-TAX.md section 6.
     */
    public function productPricingSaveReadsMoneyAsCentsAndDoesNotScaleItAgain(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'CentsContract']);
        $payload = $this->buildUpdatePayload($product, [], [['item_price' => 129900]]);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);

        $I->seeResponseCodeIs(200);
        $variant = ProductVariation::query()->where('post_id', $product->ID)->first();
        Assert::assertSame(
            129900,
            (int) $variant->item_price,
            'submitting 129900 must store $1,299.00, not $129,900.00. Body: ' . wp_json_encode($body)
        );

        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame(
            129900,
            (int) $detail->max_price,
            'the recalculated detail max_price must be in the same cents unit as the variant it came from'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [52]
     *   "Admin sees the product's minimum and maximum price recalculated when variation prices change."
     */
    public function productMinMaxPriceRecalculatedAfterVariantPriceChange(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => 'simple_variations']);
        $product = $factory->createWithVariants(
            [
                ['variation_title' => 'Cheap', 'item_price' => \FluentCart\App\Helpers\Helper::toCent(10)],
                ['variation_title' => 'Mid', 'item_price' => \FluentCart\App\Helpers\Helper::toCent(20)],
                ['variation_title' => 'Pricey', 'item_price' => \FluentCart\App\Helpers\Helper::toCent(50)],
            ],
            ['post_title' => 'MinMax52']
        );
        $payload = $this->buildUpdatePayload($product, [], [
            ['item_price' => \FluentCart\App\Helpers\Helper::toCent(5)],
            ['item_price' => \FluentCart\App\Helpers\Helper::toCent(35)],
            ['item_price' => \FluentCart\App\Helpers\Helper::toCent(90)],
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);

        $I->seeResponseCodeIs(200);
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame(500, (int) $detail->min_price, 'detail.min_price must equal the cheapest new variant price in cents');
        Assert::assertSame(9000, (int) $detail->max_price, 'detail.max_price must equal the most expensive new variant price in cents');
    }

    /**
     * Spec: Cases/Functional/products.md [53]
     *   "Admin sees a "product updated" notification triggered when updating a product."
     */
    public function productUpdateTriggersUpdatedNotification(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'Notify53']);
        $captured = ['count' => 0, 'product_id' => 0];
        $hook = static function ($payload) use (&$captured) {
            $captured['count']++;
            $captured['product_id'] = (int) ($payload['product']['ID'] ?? 0);
        };
        add_action('fluent_cart/product_updated', $hook, 99);

        try {
            $payload = $this->buildUpdatePayload($product, ['post_title' => 'NotifyAfter53']);
            [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);
            $I->seeResponseCodeIs(200);
        } finally {
            remove_action('fluent_cart/product_updated', $hook, 99);
        }

        Assert::assertSame(1, $captured['count'], 'fluent_cart/product_updated action must fire exactly once per update request');
        Assert::assertSame((int) $product->ID, $captured['product_id'], 'fired notification payload must reference the updated product id');
    }

    /**
     * Spec: Cases/Functional/products.md [54]
     *   "Admin sees the product's modification timestamp recorded in UTC regardless of the site's timezone."
     */
    public function productModificationTimestampRecordedInUtc(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $originalTimezone = get_option('timezone_string');
        update_option('timezone_string', 'America/Los_Angeles');

        try {
            $product = (new ProductFactory())->create(['post_title' => 'Modified54']);

            $payload = $this->buildUpdatePayload($product, ['post_title' => 'Modified54-After']);
            $beforeUtc = gmdate('Y-m-d H:i:s');
            [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);
            $afterUtc = gmdate('Y-m-d H:i:s');

            $I->seeResponseCodeIs(200);
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d",
                $product->ID
            ), ARRAY_A);
            $modGmt = (string) ($row['post_modified_gmt'] ?? '');
            Assert::assertNotEmpty($modGmt, 'post_modified_gmt must persist after update');
            Assert::assertNotSame('0000-00-00 00:00:00', $modGmt, 'post_modified_gmt must be a real timestamp');
            Assert::assertGreaterThanOrEqual(
                strtotime($beforeUtc . ' UTC'),
                strtotime($modGmt . ' UTC'),
                'post_modified_gmt must be >= UTC time captured before the update'
            );
            Assert::assertLessThanOrEqual(
                strtotime($afterUtc . ' UTC') + 5,
                strtotime($modGmt . ' UTC'),
                'post_modified_gmt must be <= UTC time captured after the update'
            );
        } finally {
            update_option('timezone_string', (string) $originalTimezone);
        }
    }

    /**
     * Spec: Cases/Functional/products.md [55]
     *   "Admin is rejected when updating a simple-variation product without sending any variant data."
     */
    public function updatingSimpleVariationProductWithoutVariantDataIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        // The controller's explicit guard fires only when detail.variation_type === 'simple'
        // AND the variants array is empty: ProductController::update returns sendError before
        // reaching ProductResource::update. Force the type to 'simple' so the test exercises
        // that branch deterministically rather than relying on factory defaults.
        $product = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => 'simple'])
            ->create(['post_title' => 'NoVariants55']);
        $payload = $this->buildUpdatePayload($product);
        $payload['variants'] = [];
        Assert::assertSame('simple', $payload['detail']['variation_type'] ?? '', 'fixture must produce variation_type=simple to exercise the controller guard');

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);

        Assert::assertSame(422, $status, 'simple-variation update without variants must be rejected with 422 client error');
        Assert::assertArrayHasKey('message', $body, 'rejection body must use the top-level "message" key set by ProductController::update sendError');
        Assert::assertSame('Variation info is not present', (string) $body['message'], 'rejection message must match the exact controller-level guard message');

        $variant = ProductVariation::query()->where('post_id', $product->ID)->first();
        Assert::assertSame('Default', (string) $variant->variation_title, 'rejected update must leave the original variant_title untouched on the persisted variant');
    }

    /**
     * Spec: Cases/Functional/products.md [56]
     *   "Admin can change the long-description editor mode on a product."
     */
    public function adminCanChangeLongDescriptionEditorMode(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'EditorMode56']);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/update-long-desc-editor-mode', [
            'active_editor' => 'block-editor',
        ]);

        $I->seeResponseCodeIs(200);
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame('block-editor', $detail->other_info['active_editor'] ?? '', 'detail.other_info.active_editor must persist the chosen editor mode');
    }

    /**
     * Spec: Cases/Functional/products.md [57]
     *   "Admin can save the variant option configuration on a product."
     */
    public function adminCanSaveVariantOptionConfiguration(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => \FluentCart\App\Helpers\Helper::PRODUCT_TYPE_ADVANCE_VARIATION])
            ->create(['post_title' => 'VariantOpt57']);
        $group = AttributeGroup::query()->create([
            'title' => 'Color',
            'slug'  => 'color-variant-opt-57',
        ]);
        $red = AttributeTerm::query()->create([
            'group_id' => (int) $group->id,
            'title'    => 'Red',
            'slug'     => 'red-variant-opt-57',
        ]);
        $blue = AttributeTerm::query()->create([
            'group_id' => (int) $group->id,
            'title'    => 'Blue',
            'slug'     => 'blue-variant-opt-57',
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/update-variant-option', [
            'variation_type' => \FluentCart\App\Helpers\Helper::PRODUCT_TYPE_ADVANCE_VARIATION,
            'options'        => [
                [
                    'group_id' => (int) $group->id,
                    'variants' => [(int) $red->id, (int) $blue->id],
                ],
            ],
        ]);

        $I->seeResponseCodeIs(200);
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame(\FluentCart\App\Helpers\Helper::PRODUCT_TYPE_ADVANCE_VARIATION, $detail->variation_type, 'detail.variation_type must remain advanced_variations after sync');
        $other = is_array($detail->other_info) ? $detail->other_info : [];
        $config = (array) ($other['attribute_config'] ?? []);
        Assert::assertNotEmpty($config, 'detail.other_info.attribute_config must persist the synced option configuration');
        Assert::assertArrayHasKey(0, $config, 'persisted option configuration must include the first option group');
        Assert::assertSame((int) $group->id, (int) ($config[0]['group_id'] ?? 0), 'persisted option group id must match the submitted group id');
        $variants = array_map('intval', (array) ($config[0]['variants'] ?? []));
        Assert::assertContains((int) $red->id, $variants, 'persisted option group must include the first submitted term id');
        Assert::assertContains((int) $blue->id, $variants, 'persisted option group must include the second submitted term id');
    }

    /**
     * Spec: Cases/Functional/products.md [58]
     *   "Admin can create new category or brand terms for product assignment; the unshipped product-tags taxonomy is rejected."
     *
     * Won't-ship decision 2026-08-06: product-tags is not registered, so
     * addTaxonomyTerms refuses it and the endpoint answers with its clean 423
     * instead of creating orphan terms.
     */
    public function adminCanCreateCategoryAndBrandTermsWhileUnshippedTagTaxonomyIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $catName = 'NewCat-' . wp_generate_password(4, false, false);
        [$catStatus, $catBody] = $I->post('/fluent-cart/v2/products/add-product-terms', [
            'term' => ['name' => $catName, 'taxonomy' => ProductTermFactory::TAXONOMY_CATEGORY],
        ]);
        Assert::assertSame(200, $catStatus, 'category term creation must succeed');
        Assert::assertNotEmpty($catBody['term_ids'] ?? [], 'category response must include the created term_ids array');
        // Re-read each returned id: an id from the wrong taxonomy (or an
        // unrelated existing term) would satisfy a bare non-empty check
        // (review-gate round 6).
        foreach ((array) $catBody['term_ids'] as $termId) {
            $term = get_term((int) $termId);
            Assert::assertSame($catName, $term->name ?? null, 'returned category term id must resolve to the submitted name');
            Assert::assertSame(ProductTermFactory::TAXONOMY_CATEGORY, $term->taxonomy ?? null, 'returned category term must live in the product-categories taxonomy');
        }

        $brandName = 'NewBrand-' . wp_generate_password(4, false, false);
        [$brandStatus, $brandBody] = $I->post('/fluent-cart/v2/products/add-product-terms', [
            'term' => ['name' => $brandName, 'taxonomy' => ProductTermFactory::TAXONOMY_BRAND],
        ]);
        Assert::assertSame(200, $brandStatus, 'brand term creation must succeed');
        Assert::assertNotEmpty($brandBody['term_ids'] ?? [], 'brand response must include the created term_ids array');
        foreach ((array) $brandBody['term_ids'] as $termId) {
            $term = get_term((int) $termId);
            Assert::assertSame($brandName, $term->name ?? null, 'returned brand term id must resolve to the submitted name');
            Assert::assertSame(ProductTermFactory::TAXONOMY_BRAND, $term->taxonomy ?? null, 'returned brand term must live in the product-brands taxonomy');
        }

        $tagName = 'NewTag-' . wp_generate_password(4, false, false);
        [$tagStatus, $tagBody] = $I->post('/fluent-cart/v2/products/add-product-terms', [
            'term' => ['name' => $tagName, 'taxonomy' => 'product-tags'],
        ]);
        Assert::assertSame(423, $tagStatus, 'the unshipped product-tags taxonomy must be rejected, not create orphan terms. Body: ' . wp_json_encode($tagBody));
        Assert::assertStringContainsString(
            'Unable To Create Term',
            (string) ($tagBody['message'] ?? ''),
            'tags rejection must carry the endpoint\'s clean error message rather than fataling'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [59]
     *   "Admin can sync the full set of taxonomy term assignments for a product."
     */
    public function adminCanSyncFullTaxonomyTermAssignments(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'Sync59']);
        $termFactory = new ProductTermFactory();
        $stale = $termFactory->createCategory(['name' => 'SyncStale-' . wp_generate_password(4, false, false)]);
        $a = $termFactory->createCategory(['name' => 'SyncA-' . wp_generate_password(4, false, false)]);
        $b = $termFactory->createCategory(['name' => 'SyncB-' . wp_generate_password(4, false, false)]);
        // Pre-attach a stale term so we can prove sync is a full replacement, not append-only.
        $termFactory->attachToProduct($product->ID, ProductTermFactory::TAXONOMY_CATEGORY, [(int) $stale['term_id']]);

        [$status, $body] = $I->post('/fluent-cart/v2/products/sync-taxonomy-term/' . $product->ID, [
            'taxonomy' => ProductTermFactory::TAXONOMY_CATEGORY,
            'terms'    => [(int) $a['term_id'], (int) $b['term_id']],
        ]);

        $I->seeResponseCodeIs(200);
        $assigned = wp_get_object_terms($product->ID, ProductTermFactory::TAXONOMY_CATEGORY, ['fields' => 'ids']);
        $assignedIds = array_map('intval', (array) $assigned);
        Assert::assertContains((int) $a['term_id'], $assignedIds, 'first synced term must be assigned to the product');
        Assert::assertContains((int) $b['term_id'], $assignedIds, 'second synced term must be assigned to the product');
        Assert::assertNotContains((int) $stale['term_id'], $assignedIds, 'pre-existing stale term must be removed by full-sync (sync is replace, not append)');
        Assert::assertCount(2, $assignedIds, 'after full-sync the product must carry exactly the 2 submitted terms');
    }

    /**
     * Spec: Cases/Functional/products.md [60]
     *   "Admin can remove a single taxonomy term assignment from a product."
     */
    public function adminCanRemoveSingleTaxonomyTermAssignment(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'RemoveTerm60']);
        $termFactory = new ProductTermFactory();
        $keep = $termFactory->createCategory(['name' => 'Keep-' . wp_generate_password(4, false, false)]);
        $drop = $termFactory->createCategory(['name' => 'Drop-' . wp_generate_password(4, false, false)]);
        $termFactory->attachToProduct($product->ID, ProductTermFactory::TAXONOMY_CATEGORY, [(int) $keep['term_id'], (int) $drop['term_id']]);

        [$status, $body] = $I->post('/fluent-cart/v2/products/delete-taxonomy-term/' . $product->ID, [
            'taxonomy' => ProductTermFactory::TAXONOMY_CATEGORY,
            'term'     => (int) $drop['term_id'],
        ]);

        $I->seeResponseCodeIs(200);
        $remaining = wp_get_object_terms($product->ID, ProductTermFactory::TAXONOMY_CATEGORY, ['fields' => 'ids']);
        $remainingIds = array_map('intval', (array) $remaining);
        sort($remainingIds);
        Assert::assertSame(
            [(int) $keep['term_id']],
            $remainingIds,
            'after removing one of two assigned terms, the surviving set must equal exactly [keep_term_id] — proves removal is targeted and does not detach unrelated terms'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [61]
     *   "Admin can save a single product detail field on a product."
     */
    public function adminCanSaveSingleProductDetailField(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => 'simple'])
            ->create(['post_title' => 'Detail61']);
        $detailId = (int) $product->detail->id;

        [$status, $body] = $I->post('/fluent-cart/v2/products/detail/' . $detailId, [
            'variation_type' => 'simple_variations',
            'action'         => 'change_variation_type',
        ]);

        $I->seeResponseCodeIs(200);
        $detail = ProductDetail::query()->where('id', $detailId)->first();
        Assert::assertSame('simple_variations', $detail->variation_type, 'detail.variation_type must persist the new value through the single-field endpoint');
    }

    /**
     * Spec: Cases/Functional/products.md [61a]
     *   "Switching a multi-variation product to Simple deletes the image on the variation it keeps."
     *
     * WPManageNinja portal ticket #176695: the Simple editor has no control to view,
     * replace, or clear a variation image (VariantTitleMedia only renders for
     * simple_variations / advanced_variations), so leaving it attached let the
     * storefront gallery keep showing an image the merchant could no longer find or
     * remove.
     */
    public function adminSwitchingMultiVariationProductToSimpleDeletesTheKeptVariantsImage(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([
                ['variation_title' => 'Small'],
                ['variation_title' => 'Large'],
            ], ['post_title' => 'Detail61a']);
        $keptVariant = $product->variants->first();

        ProductMeta::query()->create([
            'object_id'   => $keptVariant->id,
            'object_type' => 'product_variant_info',
            'meta_key'    => 'product_thumbnail',
            'meta_value'  => [['id' => 1, 'url' => 'http://example.com/kept.jpg', 'title' => 'Kept']],
        ]);

        $detailId = (int) $product->detail->id;

        [$status, $body] = $I->post('/fluent-cart/v2/products/detail/' . $detailId, [
            'variation_type' => 'simple',
            'variation_ids'  => [$keptVariant->id],
            'action'         => 'change_variation_type',
        ]);

        $I->seeResponseCodeIs(200);

        $remainingThumbnail = ProductMeta::query()
            ->where('object_id', $keptVariant->id)
            ->where('object_type', 'product_variant_info')
            ->where('meta_key', 'product_thumbnail')
            ->first();

        Assert::assertNull(
            $remainingThumbnail,
            'the surviving variant\'s product_thumbnail row must be deleted when the product switches to Simple, since the Simple editor has no control to show, edit, or remove it'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [61b]
     *   "Switching a product to Simple with a foreign variation id does not delete another product's image."
     *
     * Code review finding on PR #2629: variation_ids is client-supplied, so the
     * surviving-variant image cleanup must confirm the id belongs to the product
     * being updated before deleting its media — otherwise a caller could point it
     * at an unrelated product's variation and wipe that variation's image instead.
     */
    public function adminSwitchingToSimpleWithForeignVariationIdCannotDeleteAnotherProductsImage(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();

        $productA = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([
                ['variation_title' => 'A-Small'],
                ['variation_title' => 'A-Large'],
            ], ['post_title' => 'Detail61bA']);

        $productB = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([
                ['variation_title' => 'B-Only'],
            ], ['post_title' => 'Detail61bB']);
        $foreignVariant = $productB->variants->first();

        ProductMeta::query()->create([
            'object_id'   => $foreignVariant->id,
            'object_type' => 'product_variant_info',
            'meta_key'    => 'product_thumbnail',
            'meta_value'  => [['id' => 2, 'url' => 'http://example.com/foreign.jpg', 'title' => 'Foreign']],
        ]);

        $detailIdA = (int) $productA->detail->id;

        [$status, $body] = $I->post('/fluent-cart/v2/products/detail/' . $detailIdA, [
            'variation_type' => 'simple',
            // A's own variant list is ignored on purpose here — this simulates a
            // request naming a variation id that belongs to product B, not A.
            'variation_ids'  => [$foreignVariant->id],
            'action'         => 'change_variation_type',
        ]);

        $I->seeResponseCodeIs(200);

        $foreignThumbnailStillExists = ProductMeta::query()
            ->where('object_id', $foreignVariant->id)
            ->where('object_type', 'product_variant_info')
            ->where('meta_key', 'product_thumbnail')
            ->exists();

        Assert::assertTrue(
            $foreignThumbnailStillExists,
            'a variation id belonging to a different product must not have its image deleted just because it was submitted while updating an unrelated product'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [62]
     *   "Admin can load products into the bulk-edit spreadsheet with the fields needed for editing."
     */
    public function adminCanLoadProductsForBulkEdit(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ProductFactory();
        $a = $factory->create(['post_title' => 'BulkEdit62A']);
        $b = $factory->create(['post_title' => 'BulkEdit62B']);

        [$status, $body] = $I->get('/fluent-cart/v2/products/bulk-edit-data', ['per_page' => 50]);

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('products', $body, 'bulk-edit response must contain a products list');
        $rows = (array) $body['products'];
        $ids = array_map(static function ($p) { return (int) ($p['ID'] ?? 0); }, $rows);
        Assert::assertContains((int) $a->ID, $ids, 'first seeded product must appear in the bulk-edit list');
        Assert::assertContains((int) $b->ID, $ids, 'second seeded product must appear in the bulk-edit list');
        $rowA = null;
        foreach ($rows as $row) {
            if ((int) ($row['ID'] ?? 0) === (int) $a->ID) {
                $rowA = $row;
                break;
            }
        }
        Assert::assertNotNull($rowA, 'must locate row for product A in bulk-edit response');
        Assert::assertArrayHasKey('variants', $rowA, 'bulk-edit row must include variants array for inline editing');
        Assert::assertArrayHasKey('detail', $rowA, 'bulk-edit row must include detail block for inline editing');
        Assert::assertArrayHasKey('item_price', $rowA['variants'][0] ?? [], 'bulk-edit variant row must include item_price for inline editing');
    }

    /**
     * The editor stages only what the merchant touched, so `detail` on a partial
     * save carries just {id, fulfillment_type, variation_type}. ProductResource
     * used to materialise `default_variation_id` as null from that partial row and
     * ProductDetailResource read the absent key as "clear it", so changing one
     * variant's price silently wiped the product's Default Variant — invisible
     * until the next reload.
     *
     * See dev-docs/framework-update-2.12.6-qa.md.
     */
    public function updatingAProductWithoutMentioningTheDefaultVariantKeepsIt(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'KeepsDefaultVariant']);
        $variantId = (int) $product->variants->first()->id;

        ProductDetail::query()->where('post_id', $product->ID)
            ->update(['default_variation_id' => $variantId]);

        $payload = $this->buildUpdatePayload($product, ['post_title' => 'KeepsDefaultVariant']);
        unset($payload['detail']['default_variation_id']);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);

        $I->seeResponseCodeIs(200);
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame(
            $variantId,
            (int) $detail->default_variation_id,
            'a save that never mentions the default variant must leave it alone. Body: ' . wp_json_encode($body)
        );
    }

    public function updatingAProductWithAnEmptyDefaultVariantClearsIt(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'ClearsDefaultVariant']);
        $variantId = (int) $product->variants->first()->id;

        ProductDetail::query()->where('post_id', $product->ID)
            ->update(['default_variation_id' => $variantId]);

        // Explicitly empty is the merchant clearing the picker — that must still work.
        $payload = $this->buildUpdatePayload($product, ['post_title' => 'ClearsDefaultVariant']);
        $payload['detail']['default_variation_id'] = '';

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);

        $I->seeResponseCodeIs(200);
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertNull(
            $detail->default_variation_id,
            'an explicitly emptied default variant must still be cleared. Body: ' . wp_json_encode($body)
        );
    }

    /**
     * Spec: Cases/Functional/products.md [63]
     *   "Admin can bulk-update up to ten products' shared fields in a single request."
     */
    public function adminCanBulkUpdateUpToTenProductsInOneRequest(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ProductFactory();
        $payload = [];
        $expectedTitles = [];
        for ($i = 0; $i < 10; $i++) {
            $product = $factory->create(['post_title' => 'BulkUpdate63-' . $i]);
            $newTitle = 'BulkUpdate63-Updated-' . $i . '-' . wp_generate_password(4, false, false);
            $expectedTitles[(int) $product->ID] = $newTitle;
            $payload[] = [
                'ID'          => (int) $product->ID,
                'post_title'  => $newTitle,
                'post_status' => 'publish',
                'detail'      => ['variation_type' => 'simple', 'fulfillment_type' => 'digital'],
                'variants'    => [[
                    'id'              => (int) $product->variants->first()->id,
                    'variation_title' => $newTitle,
                    'sku'             => 'BU63-' . $i . '-' . wp_generate_password(4, false, false),
                    'item_price'      => 500,
                    'other_info'      => ['payment_type' => 'onetime'],
                ]],
            ];
        }

        [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', ['products' => $payload]);

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('updated', $body, 'bulk-update response must include an updated id list');
        Assert::assertCount(10, (array) $body['updated'], 'all 10 products must be reported as updated');
        foreach ($expectedTitles as $id => $title) {
            $row = get_post($id);
            Assert::assertNotNull($row, "post id {$id} must persist in wp_posts after bulk-update");
            Assert::assertSame($title, $row->post_title, "wp_posts row {$id} must persist the bulk-updated title");
            $variant = ProductVariation::query()->where('post_id', $id)->first();
            Assert::assertSame(500, (int) $variant->item_price, "wp post {$id} variant must persist the submitted 500 cents unchanged");
            Assert::assertSame($title, (string) $variant->variation_title, "wp post {$id} variant_title must mirror the bulk-updated title");
        }
    }

    /**
     * Bulk Edit now speaks cents like every other product write path, and that includes
     * the subscription setup fee — the field most likely to be missed, because getting it
     * wrong stores a $20.00 fee as $0.20 with nothing else on the row looking off.
     *
     * The literals here are cents and are deliberately not written as Helper::toCent(30),
     * which would hide the thing under test: that bulk-update stores what it is handed.
     *
     * See dev-docs/PRICING-AND-TAX.md section 6.
     */
    public function bulkUpdateReadsPricesAndSetupFeesAsCents(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'BulkSetupFee']);

        [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', [
            'products' => [[
                'ID'          => (int) $product->ID,
                'post_title'  => 'BulkSetupFee',
                'post_status' => 'publish',
                'detail'      => ['variation_type' => 'simple', 'fulfillment_type' => 'digital'],
                'variants'    => [[
                    'id'              => (int) $product->variants->first()->id,
                    'variation_title' => 'BulkSetupFee',
                    'item_price'      => 3000,
                    'other_info'      => [
                        'payment_type'     => 'subscription',
                        'repeat_interval'  => 'yearly',
                        'manage_setup_fee' => 'yes',
                        'signup_fee_name'  => 'Setup Fee',
                        'signup_fee'       => 2000,
                    ],
                ]],
            ]],
        ]);

        $I->seeResponseCodeIs(200);

        $variant = ProductVariation::query()->where('post_id', $product->ID)->first();
        Assert::assertSame(
            3000,
            (int) $variant->item_price,
            'a submitted 3000 must persist as 3000 cents ($30.00), not be scaled again. Body: ' . wp_json_encode($body)
        );

        $otherInfo = $variant->other_info;
        Assert::assertSame(
            2000,
            (int) ($otherInfo['signup_fee'] ?? 0),
            'a submitted setup fee of 2000 must persist as 2000 cents ($20.00), not 200000'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [64]
     *   "Admin is rejected when bulk-updating more than ten products in a single request."
     */
    public function bulkUpdatingMoreThanTenProductsIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = new ProductFactory();
        $payload = [];
        for ($i = 0; $i < 11; $i++) {
            $product = $factory->create(['post_title' => 'OverflowU64-' . $i]);
            $payload[] = [
                'ID'          => (int) $product->ID,
                'post_title'  => 'OverflowU64-' . $i . '-attempted',
                'post_status' => 'publish',
                'detail'      => ['variation_type' => 'simple', 'fulfillment_type' => 'digital'],
                'variants'    => [[
                    'id'              => (int) $product->variants->first()->id,
                    'variation_title' => 'V',
                    'item_price'      => 1,
                    'other_info'      => ['payment_type' => 'onetime'],
                ]],
            ];
        }

        [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', ['products' => $payload]);

        Assert::assertSame(422, $status, 'over-cap (11) bulk-update must be rejected with 422 client error');
        Assert::assertArrayHasKey('message', $body, 'rejection body must use the top-level "message" key set by ProductController::bulkUpdate sendError');
        Assert::assertSame(
            'Maximum 10 products per chunk allowed',
            (string) $body['message'],
            'rejection message must match the exact controller-level cap-guard message'
        );

        $first = $payload[0];
        $persisted = get_post((int) $first['ID']);
        Assert::assertNotNull($persisted, 'first seeded product must still exist after rejected bulk-update');
        Assert::assertSame('OverflowU64-0', $persisted->post_title, 'rejected bulk-update must NOT mutate the wp_posts row of any candidate (verifies the cap-guard short-circuits before any persistence)');
    }

    /**
     * Spec: Cases/Functional/products.md [65]
     *   "A worker without the "edit products" permission cannot update a product."
     */
    public function workerWithoutEditPermissionCannotUpdateProduct(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'WorkerEdit65']);
        $payload = $this->buildUpdatePayload($product, ['post_title' => 'AttemptedRename']);
        $I->loginAsWorker();

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);

        Assert::assertSame(403, $status, 'worker without products/edit must be rejected with 403');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $refreshed = get_post($product->ID);
        Assert::assertSame('WorkerEdit65', $refreshed->post_title, 'rejected update must not mutate the wp_posts row');
    }

    /**
     * Spec: Cases/Functional/products.md [66]
     *   "An accountant cannot update a product."
     */
    public function accountantCannotUpdateProduct(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'Accountant66']);
        $payload = $this->buildUpdatePayload($product, ['post_title' => 'AttemptedRename66']);
        $I->loginAsAccountant();

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);

        Assert::assertSame(403, $status, 'accountant must be rejected with 403 from product update');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $refreshed = get_post($product->ID);
        Assert::assertSame('Accountant66', $refreshed->post_title, 'rejected update must not mutate the wp_posts row');
    }

    /**
     * Spec: Cases/Functional/products.md [67]
     *   "A worker without the "edit products" permission cannot bulk-update products."
     */
    public function workerWithoutEditPermissionCannotBulkUpdateProducts(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'WorkerBulkU67']);
        $I->loginAsWorker();

        [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', [
            'products' => [[
                'ID'          => (int) $product->ID,
                'post_title'  => 'WorkerBulkU67-Attempt',
                'post_status' => 'publish',
                'detail'      => ['variation_type' => 'simple', 'fulfillment_type' => 'digital'],
                'variants'    => [['variation_title' => 'V', 'item_price' => 1, 'other_info' => ['payment_type' => 'onetime']]],
            ]],
        ]);

        Assert::assertSame(403, $status, 'worker without products/edit must be rejected with 403 from bulk-update');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $refreshed = get_post($product->ID);
        Assert::assertSame('WorkerBulkU67', $refreshed->post_title, 'rejected bulk-update must not mutate the wp_posts row');
    }

    /**
     * Spec: Cases/Functional/products.md [68]
     *   "A worker without the "edit products" permission cannot change a product's editor mode."
     */
    public function workerWithoutEditPermissionCannotChangeEditorMode(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'WorkerEditor68']);
        $I->loginAsWorker();

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/update-long-desc-editor-mode', [
            'active_editor' => 'block-editor',
        ]);

        Assert::assertSame(403, $status, 'worker without products/edit must be rejected with 403 from editor-mode update');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertArrayNotHasKey('active_editor', is_array($detail->other_info) ? $detail->other_info : [], 'rejected editor-mode update must not persist active_editor in detail.other_info');
    }

    /**
     * Spec: Cases/Functional/products.md [69]
     *   "A worker without the "edit products" permission cannot save variant option configuration."
     */
    public function workerWithoutEditPermissionCannotSaveVariantOptionConfig(FunctionalTester $I): void
    {
        $product = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => 'simple'])
            ->create(['post_title' => 'WorkerVarOpt69']);
        $I->loginAsWorker();

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/update-variant-option', [
            'variation_type' => \FluentCart\App\Helpers\Helper::PRODUCT_TYPE_ADVANCE_VARIATION,
            'options'        => [['id' => 1, 'name' => 'Color', 'variants' => ['Red']]],
        ]);

        Assert::assertSame(403, $status, 'worker without products/edit must be rejected with 403 from variant-option save');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame('simple', $detail->variation_type, 'rejected variant-option save must not flip variation_type');
    }

    /**
     * Spec: Cases/Functional/products.md [70]
     *   "A worker without the "edit products" permission cannot sync taxonomy term assignments."
     */
    public function workerWithoutEditPermissionCannotSyncTaxonomyTermAssignments(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'WorkerSync70']);
        $term = (new ProductTermFactory())->createCategory(['name' => 'WorkerSyncCat-' . wp_generate_password(4, false, false)]);
        $I->loginAsWorker();

        [$status, $body] = $I->post('/fluent-cart/v2/products/sync-taxonomy-term/' . $product->ID, [
            'taxonomy' => ProductTermFactory::TAXONOMY_CATEGORY,
            'terms'    => [(int) $term['term_id']],
        ]);

        Assert::assertSame(403, $status, 'worker without products/edit must be rejected with 403 from sync-taxonomy-term');
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $assigned = wp_get_object_terms($product->ID, ProductTermFactory::TAXONOMY_CATEGORY, ['fields' => 'ids']);
        Assert::assertEmpty(array_map('intval', (array) $assigned), 'rejected sync must not attach any terms to the product');
    }

    /**
     * Spec: Cases/Functional/products.md [231]
     *   "Admin clearing a Simple product's SKU (explicit empty string) persists the cleared value; a previously stored SKU does not remain after reload."
     */
    public function adminClearingSimpleProductSkuPersistsClearedValue(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => 'simple'])
            ->withVariantAttributes(['sku' => 'OLD-SKU-231'])
            ->create(['post_title' => 'ClearSku231']);

        $payload = $this->buildUpdatePayload($product, [], [['sku' => '']]);
        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);

        $I->seeResponseCodeIs(200);
        $variant = ProductVariation::query()->where('post_id', $product->ID)->first();
        Assert::assertNull($variant->sku, 'explicitly clearing sku must persist as NULL, not retain the previous stored value');

        // A non-empty sku on a later save must continue to save normally.
        $payload = $this->buildUpdatePayload($product, [], [['sku' => 'NEW-SKU-231']]);
        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);

        $I->seeResponseCodeIs(200);
        $variant = ProductVariation::query()->where('post_id', $product->ID)->first();
        Assert::assertSame('NEW-SKU-231', $variant->sku, 'a non-empty sku must save normally after a prior clear');
    }

    /**
     * Spec: Cases/Functional/products.md [231a]
     *   "Admin omitting the sku field entirely on a Simple product update leaves the previously stored SKU unchanged."
     */
    public function adminOmittingSkuFieldOnSimpleProductUpdateLeavesStoredSkuUnchanged(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => 'simple'])
            ->withVariantAttributes(['sku' => 'KEEP-SKU-231A'])
            ->create(['post_title' => 'KeepSku231A']);

        // buildUpdatePayload does not include a 'sku' key unless explicitly overridden,
        // so this reproduces a client submitting an update without touching the field.
        $payload = $this->buildUpdatePayload($product, ['post_title' => 'KeepSku231A-Renamed']);
        Assert::assertArrayNotHasKey('sku', $payload['variants'][0], 'fixture payload must omit sku to exercise the "key absent" path');

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);

        $I->seeResponseCodeIs(200);
        $variant = ProductVariation::query()->where('post_id', $product->ID)->first();
        Assert::assertSame('KEEP-SKU-231A', $variant->sku, 'omitting the sku key must leave the previously stored sku untouched');
    }

    /**
     * Spec: Cases/Functional/products.md [231b]
     *   "Admin clearing one variant's SKU on a Simple Variations product persists the cleared value without affecting sibling variants' SKUs."
     */
    public function adminClearingSkuOnSimpleVariationsProductPersistsClearedValue(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = (new ProductFactory())->withDetailAttributes(['variation_type' => 'simple_variations']);
        $product = $factory->createWithVariants(
            [
                ['variation_title' => 'Small', 'sku' => 'SM-SKU-231B'],
                ['variation_title' => 'Large', 'sku' => 'LG-SKU-231B'],
            ],
            ['post_title' => 'ClearSku231B']
        );

        $payload = $this->buildUpdatePayload($product, [], [
            ['sku' => ''],
            [],
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);

        $I->seeResponseCodeIs(200);
        $variants = ProductVariation::query()->where('post_id', $product->ID)->orderBy('serial_index', 'ASC')->get();
        Assert::assertNull($variants[0]->sku, 'explicitly clearing a variant sku must persist as NULL on a Simple Variations product');
        Assert::assertSame('LG-SKU-231B', $variants[1]->sku, 'clearing one variant sku must not affect a sibling variant sku');
    }

    /**
     * Spec: Cases/Functional/products.md [231c]
     *   "Admin clearing one variant's SKU on an Advanced Variations product persists the cleared value without affecting sibling variants' SKUs."
     */
    public function adminClearingSkuOnAdvancedVariationsProductPersistsClearedValue(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = (new ProductFactory())->withDetailAttributes([
            'variation_type' => \FluentCart\App\Helpers\Helper::PRODUCT_TYPE_ADVANCE_VARIATION,
        ]);
        $product = $factory->createWithVariants(
            [
                ['variation_title' => 'Red', 'sku' => 'RED-SKU-231C'],
                ['variation_title' => 'Blue', 'sku' => 'BLUE-SKU-231C'],
            ],
            ['post_title' => 'ClearSku231C']
        );

        $payload = $this->buildUpdatePayload($product, [], [
            ['sku' => null],
            [],
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);

        $I->seeResponseCodeIs(200);
        $variants = ProductVariation::query()->where('post_id', $product->ID)->orderBy('serial_index', 'ASC')->get();
        Assert::assertNull($variants[0]->sku, 'explicitly clearing a variant sku with null must persist as NULL on an Advanced Variations product');
        Assert::assertSame('BLUE-SKU-231C', $variants[1]->sku, 'clearing one variant sku must not affect a sibling variant sku');
    }

    /**
     * Spec: Cases/Functional/products.md [231d]
     *   "Admin clearing a variant's SKU through the bulk-edit spreadsheet on a Simple Variations product persists the cleared value, even though that path bypasses the standard product-update sanitizer."
     *
     * BulkProductUpdateService::updateSingleProduct() calls ProductResource::update() directly
     * with the raw request payload — it does not go through ProductUpdateRequest, whose
     * sanitize() step normally converts an empty-string sku to null before ProductResource
     * ever sees it. This is the one path that actually exercises ProductResource's own
     * multi-variant sku-clearing logic rather than relying on that upstream sanitizer.
     */
    public function adminClearingSkuThroughBulkUpdateOnSimpleVariationsProductPersistsClearedValue(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = (new ProductFactory())->withDetailAttributes(['variation_type' => 'simple_variations']);
        $product = $factory->createWithVariants(
            [
                ['variation_title' => 'Small', 'sku' => 'SM-SKU-231D'],
                ['variation_title' => 'Large', 'sku' => 'LG-SKU-231D'],
            ],
            ['post_title' => 'BulkClearSku231D']
        );
        $variants = ProductVariation::query()->where('post_id', $product->ID)->orderBy('serial_index', 'ASC')->get();

        [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', [
            'products' => [[
                'ID'          => (int) $product->ID,
                'post_title'  => 'BulkClearSku231D',
                'post_status' => 'publish',
                'detail'      => ['variation_type' => 'simple_variations', 'fulfillment_type' => 'digital'],
                'variants'    => [
                    [
                        'id'              => (int) $variants[0]->id,
                        'variation_title' => 'Small',
                        'sku'             => '',
                        'item_price'      => 1000,
                        'other_info'      => ['payment_type' => 'onetime'],
                    ],
                    [
                        'id'              => (int) $variants[1]->id,
                        'variation_title' => 'Large',
                        'sku'             => 'LG-SKU-231D',
                        'item_price'      => 2000,
                        'other_info'      => ['payment_type' => 'onetime'],
                    ],
                ],
            ]],
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('updated', $body, 'bulk-update response must include an updated id list. Body: ' . wp_json_encode($body));
        Assert::assertContains((int) $product->ID, array_map('intval', (array) $body['updated']), 'product must be reported as successfully bulk-updated');

        $refreshedVariants = ProductVariation::query()->where('post_id', $product->ID)->orderBy('serial_index', 'ASC')->get();
        Assert::assertNull($refreshedVariants[0]->sku, 'the bulk-edit path (which bypasses ProductUpdateRequest sanitization) must still persist an explicitly cleared sku as NULL, not an empty string or the stale value');
        Assert::assertSame('LG-SKU-231D', $refreshedVariants[1]->sku, 'clearing one variant sku via bulk-update must not affect a sibling variant sku');
    }

    /**
     * Spec: Cases/Functional/products.md [231e]
     *   "Admin setting a variant's SKU to the literal string "0" persists it as "0", not NULL."
     *
     * ProductUpdateRequest::getSanitizersForExistingFields()'s sku sanitizer used
     * `empty($value) ? null : sanitize_text_field($value)`. PHP's empty() treats the
     * string "0" as empty, so a merchant-entered SKU of "0" was silently converted to
     * NULL before ProductResource ever saw it — even though "0" is a legitimate,
     * distinct textual identifier (sku is VARCHAR(30), not numeric) and nothing else
     * in the codebase treats "0" as a clear signal (contrast api/Resource/ProductResource.php's
     * own sku-clearing checks, which deliberately use `=== '' || === null` for exactly
     * this reason).
     */
    public function adminSettingVariantSkuToLiteralZeroPersistsAsZero(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => 'simple'])
            ->withVariantAttributes(['sku' => 'OLD-SKU-231E'])
            ->create(['post_title' => 'ZeroSku231E']);

        $payload = $this->buildUpdatePayload($product, [], [['sku' => '0']]);
        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);

        $I->seeResponseCodeIs(200);
        $variant = ProductVariation::query()->where('post_id', $product->ID)->first();
        Assert::assertSame('0', $variant->sku, 'a literal sku of "0" must persist as the string "0", not be silently converted to NULL. Body: ' . wp_json_encode($body));
    }

    /**
     * Spec: Cases/Functional/products.md [231f]
     *   "Admin attempting to give two variants the same non-empty SKU of the literal string
     *   \"0\" never ends up with both variants persisting that duplicate value — the
     *   sku_unique database constraint applies to \"0\" exactly as it does to any other
     *   real SKU, once the sku=\"0\" sanitizer fix stops \"0\" from being treated as empty."
     *
     * Before the sku="0" fix, this exact scenario was a silent data-loss trap rather than a
     * blocked duplicate: `empty('0')` sanitized BOTH variants' sku to NULL, and MySQL's
     * sku_unique index permits any number of NULLs, so the save "succeeded" with two SKUs
     * silently wiped instead of one real duplicate being rejected. After the fix, both
     * variants are genuinely attempted at sku="0", the sku_unique index rejects the real
     * duplicate at the database layer, and the batch UPDATE's pre-existing sku values are
     * left in place — proving the fix does not let "0" bypass duplicate-SKU protection.
     * (Separately, and unrelated to this fix, that database-level rejection is not currently
     * surfaced as a non-200 response — this test asserts on the persisted data, which is the
     * invariant this fix must not weaken, not on the response status.)
     */
    public function adminAttemptingDuplicateSkuNeverPersistsTheDuplicate(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $factory = (new ProductFactory())->withDetailAttributes(['variation_type' => 'simple_variations']);
        $product = $factory->createWithVariants(
            [
                ['variation_title' => 'Small', 'sku' => 'SM-SKU-231F'],
                ['variation_title' => 'Large', 'sku' => 'LG-SKU-231F'],
            ],
            ['post_title' => 'DuplicateSku231F']
        );

        $payload = $this->buildUpdatePayload($product, [], [
            ['sku' => '0'],
            ['sku' => '0'],
        ]);

        $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);

        $variants = ProductVariation::query()->where('post_id', $product->ID)->orderBy('serial_index', 'ASC')->get();
        // The single CASE-WHEN UPDATE statement is atomic, so a real duplicate-key
        // rejection at the database layer must leave both pre-existing, distinct
        // skus exactly as they were — not just "different from each other" (which
        // a partial write, e.g. one sku becoming "0" and the other staying null,
        // would also satisfy without proving the invariant this test is for).
        Assert::assertSame('SM-SKU-231F', $variants[0]->sku, 'a rejected duplicate-sku save must leave the first variant\'s sku untouched');
        Assert::assertSame('LG-SKU-231F', $variants[1]->sku, 'a rejected duplicate-sku save must leave the second variant\'s sku untouched');
    }
}
