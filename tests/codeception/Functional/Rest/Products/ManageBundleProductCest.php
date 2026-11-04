<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Managing bundle products", items 139-143.
 *
 * Routes:
 *   GET  /products/get-bundle-info/{productId}    perm products/view
 *   POST /products/save-bundle-info/{variationId} perm products/edit
 *
 * The save route persists `bundle_child_ids` into the variation's
 * `other_info` JSON column on fct_product_variations. The read route returns
 * the same list (loaded via Helper::loadBundleChild) so the bundle editor
 * UI can populate. Permission denials cover both worker (no edit caps) and
 * accountant (no products/* caps).
 */
class ManageBundleProductCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/products.md [139]
     *   "Admin can save a bundle's child variation list against a parent variation."
     */
    public function adminCanSaveBundleChildVariationListAgainstParentVariation(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $childA = (new ProductFactory())->create(['post_title' => 'BundleChildA139']);
        $childB = (new ProductFactory())->create(['post_title' => 'BundleChildB139']);
        $childAVariantId = (int) $childA->variants->first()->id;
        $childBVariantId = (int) $childB->variants->first()->id;
        $parent = (new ProductFactory())
            ->withDetailAttributes(['other_info' => ['is_bundle_product' => 'yes']])
            ->withVariantAttributes([
                'other_info' => [
                    'payment_type'      => 'onetime',
                    'is_bundle_product' => 'yes',
                    'bundle_child_ids'  => [],
                ],
            ])
            ->create(['post_title' => 'BundleParent139']);
        $parentVariantId = (int) $parent->variants->first()->id;
        Assert::assertSame([], (array) ($parent->variants->first()->other_info['bundle_child_ids'] ?? []), 'fixture must start with no bundle children to prove the save is the route-applied delta');

        [$status] = $I->post(
            '/fluent-cart/v2/products/save-bundle-info/' . $parentVariantId,
            ['bundle_child_ids' => [$childAVariantId, $childBVariantId]]
        );

        $I->seeResponseCodeIs(200);
        $refreshed = ProductVariation::query()->find($parentVariantId);
        $stored    = (array) ($refreshed->other_info['bundle_child_ids'] ?? []);
        Assert::assertSame(
            [$childAVariantId, $childBVariantId],
            array_map('intval', $stored),
            'parent variation.other_info.bundle_child_ids must persist exactly the two child variation ids from the request body'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [140]
     *   "Admin receives a clear "not found" error when saving bundle information for a variation that does not exist."
     */
    public function adminReceivesNotFoundErrorSavingBundleForMissingVariation(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $missingVariantId = 999_999;
        Assert::assertNull(ProductVariation::query()->find($missingVariantId), 'fixture must guarantee the target variation id does not exist before the request');

        [$status, $body] = $I->post(
            '/fluent-cart/v2/products/save-bundle-info/' . $missingVariantId,
            ['bundle_child_ids' => [1, 2, 3]]
        );

        $I->seeResponseCodeIs(404);
        $message = (string) ($body['message'] ?? ($body['data']['message'] ?? ''));
        Assert::assertNotEmpty($message, 'response must include a not-found message so the admin UI can surface a useful error');
        Assert::assertMatchesRegularExpression(
            '/(not\s+found|no\s+query\s+results)/i',
            $message,
            'message must communicate not-found semantics (literal "not found" or Eloquent\'s "No query results for model ...") so generic "Bad Request" / "Server Error" bodies do not slip through'
        );
        Assert::assertStringContainsString(
            'ProductVariation',
            $message,
            'message must reference the missing entity type (ProductVariation) so the admin UI surfaces the precise resource that was not found'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [141]
     *   "Admin can read a product's bundle configuration to populate the bundle editor."
     */
    public function adminCanReadBundleConfigurationToPopulateBundleEditor(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $childA = (new ProductFactory())
            ->withVariantAttributes(['variation_title' => 'BundleChildA141Title'])
            ->create(['post_title' => 'BundleReadChildA141']);
        $childB = (new ProductFactory())
            ->withVariantAttributes(['variation_title' => 'BundleChildB141Title'])
            ->create(['post_title' => 'BundleReadChildB141']);
        $childAVariantId = (int) $childA->variants->first()->id;
        $childBVariantId = (int) $childB->variants->first()->id;
        $parent = (new ProductFactory())->createBundle(
            [$childAVariantId, $childBVariantId],
            ['post_title' => 'BundleReadParent141']
        );

        [$status, $body] = $I->get('/fluent-cart/v2/products/get-bundle-info/' . $parent->ID);

        $I->seeResponseCodeIs(200);
        Assert::assertIsArray($body, 'bundle-info response must be an array of parent-variation rows');
        Assert::assertCount(1, $body, 'bundle parent product must yield exactly one variation row in the response (one variation seeded by the factory)');
        $row = $body[0] ?? [];
        Assert::assertArrayHasKey('id', $row, 'each variation row must expose its id so the editor can correlate');
        Assert::assertArrayHasKey('other_info', $row, 'each variation row must expose other_info to surface bundle metadata');
        $storedChildIds = array_map('intval', (array) ($row['other_info']['bundle_child_ids'] ?? []));
        Assert::assertSame(
            [$childAVariantId, $childBVariantId],
            $storedChildIds,
            'response must echo the exact child variation ids stored against the parent so the editor can pre-populate the picker'
        );

        // Editor relies on child_variants enrichment (Helper::loadBundleChild) so it
        // can render rows beyond the raw id list. If the route stops enriching, the
        // editor renders empty rows even though bundle_child_ids is intact.
        Assert::assertArrayHasKey('child_variants', $row, 'response must enrich the parent row with child_variants so the editor can render each child');
        $childVariants = (array) ($row['child_variants'] ?? []);
        Assert::assertArrayHasKey($childAVariantId, $childVariants, 'child_variants must be keyed by the saved child variation id (childA)');
        Assert::assertArrayHasKey($childBVariantId, $childVariants, 'child_variants must be keyed by the saved child variation id (childB)');
        Assert::assertSame($childAVariantId, (int) ($childVariants[$childAVariantId]['id'] ?? 0), 'enriched childA row must expose its variation id');
        Assert::assertSame($childBVariantId, (int) ($childVariants[$childBVariantId]['id'] ?? 0), 'enriched childB row must expose its variation id');
        Assert::assertSame('BundleChildA141Title', (string) ($childVariants[$childAVariantId]['variation_title'] ?? ''), 'childA enrichment must echo the variation_title from the seeded child (proves the join hits the right row, not just any row)');
        Assert::assertSame('BundleChildB141Title', (string) ($childVariants[$childBVariantId]['variation_title'] ?? ''), 'childB enrichment must echo the variation_title from the seeded child');
    }

    /**
     * Spec: Cases/Functional/products.md [142]
     *   "A worker without the "edit products" permission cannot save bundle information."
     */
    public function workerWithoutEditProductsPermissionCannotSaveBundleInformation(FunctionalTester $I): void
    {
        $parent = (new ProductFactory())
            ->withVariantAttributes([
                'other_info' => [
                    'payment_type'      => 'onetime',
                    'is_bundle_product' => 'yes',
                    'bundle_child_ids'  => [],
                ],
            ])
            ->create(['post_title' => 'WorkerBundle142']);
        $parentVariantId = (int) $parent->variants->first()->id;
        $before = ProductVariation::query()->find($parentVariantId)->toArray();
        $I->loginAsWorker();

        [, $body] = $I->post(
            '/fluent-cart/v2/products/save-bundle-info/' . $parentVariantId,
            ['bundle_child_ids' => [777, 888]]
        );

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $after = ProductVariation::query()->find($parentVariantId)->toArray();
        unset($before['updated_at'], $after['updated_at']);
        Assert::assertSame($before, $after, 'rejected save-bundle-info must not mutate any column on fct_product_variations (full-row snapshot equality)');
    }

    /**
     * Spec: Cases/Functional/products.md [143]
     *   "An accountant cannot save bundle information."
     */
    public function accountantCannotSaveBundleInformation(FunctionalTester $I): void
    {
        $parent = (new ProductFactory())
            ->withVariantAttributes([
                'other_info' => [
                    'payment_type'      => 'onetime',
                    'is_bundle_product' => 'yes',
                    'bundle_child_ids'  => [],
                ],
            ])
            ->create(['post_title' => 'AccountantBundle143']);
        $parentVariantId = (int) $parent->variants->first()->id;
        $before = ProductVariation::query()->find($parentVariantId)->toArray();
        $I->loginAsAccountant();

        [, $body] = $I->post(
            '/fluent-cart/v2/products/save-bundle-info/' . $parentVariantId,
            ['bundle_child_ids' => [777, 888]]
        );

        $I->seeResponseCodeIs(403);
        Assert::assertSame('rest_forbidden', $body['code'] ?? '', 'denial response must surface the rest_forbidden code');
        Assert::assertNotEmpty($body['message'] ?? '', 'denial response must include a permission-denied message');
        $after = ProductVariation::query()->find($parentVariantId)->toArray();
        unset($before['updated_at'], $after['updated_at']);
        Assert::assertSame($before, $after, 'rejected save-bundle-info must not mutate any column on fct_product_variations (full-row snapshot equality)');
    }
}
