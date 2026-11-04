<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Meta;
use FluentCart\App\Models\Order;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\OrderItemFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Managing upgrade paths", items 144-149.
 *
 * Routes (perm products/view, products/edit, products/delete):
 *   GET    /products/{id}/upgrade-paths
 *   POST   /products/{id}/upgrade-path
 *   POST   /products/upgrade-path/{id}/update
 *   DELETE /products/upgrade-path/{id}/delete
 *   GET    /products/variation/{variantId}/upgrade-paths
 *
 * Upgrade-path data is stored as fct_meta rows with object_type='variant_upgrade'
 * and meta_key='variant_upgrade_path'. Each meta_value carries to_variants,
 * is_prorate, and discount_amount; the controller delegates persistence to
 * PlanUpgradeService.
 */
class ManageProductUpgradePathCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/products.md [144]
     *   "Admin can add an upgrade path to a product so customers can move to a higher-priced variation later."
     */
    public function adminCanAddUpgradePathToProductForLaterVariationMove(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $fromProduct = (new ProductFactory())->create(['post_title' => 'UpgradeFrom144']);
        $toProduct   = (new ProductFactory())
            ->withVariantAttributes(['item_price' => Helper::toCent(99.00)])
            ->create(['post_title' => 'UpgradeTo144']);
        $fromVariantId = (int) $fromProduct->variants->first()->id;
        $toVariantId   = (int) $toProduct->variants->first()->id;
        Assert::assertSame(0, Meta::query()->where('object_type', 'variant_upgrade')->count(), 'fixture must start with no upgrade-path meta rows so the saved row is the route-applied delta');

        [, $body] = $I->post(
            '/fluent-cart/v2/products/' . (int) $fromProduct->ID . '/upgrade-path',
            [
                'from_variant'    => $fromVariantId,
                'to_variants'     => [$toVariantId],
                'is_prorate'      => 0,
                'discount_amount' => 5.0,
            ]
        );

        $I->seeResponseCodeIs(200);
        Assert::assertNotEmpty($body['message'] ?? '', 'success response must include a confirmation message so the admin UI can surface it');
        $row = Meta::query()
            ->where('object_type', 'variant_upgrade')
            ->where('meta_key', 'variant_upgrade_path')
            ->where('object_id', $fromVariantId)
            ->first();
        Assert::assertNotNull($row, 'a fct_meta row must persist for the from-variant so the upgrade path is durable across requests');
        $stored = (array) ($row->meta_value ?? []);
        Assert::assertSame([$toVariantId], array_map('intval', (array) ($stored['to_variants'] ?? [])), 'meta_value.to_variants must persist exactly the requested target variation ids');
        Assert::assertSame('5', (string) ($stored['discount_amount'] ?? ''), 'meta_value.discount_amount must persist the requested numeric value (sanitize_text_field stringifies it)');
    }

    /**
     * Spec: Cases/Functional/products.md [145]
     *   "Admin can view the upgrade-path settings configured on a product."
     */
    public function adminCanViewUpgradePathSettingsConfiguredOnProduct(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $fromProduct = (new ProductFactory())->create(['post_title' => 'UpgradeView145From']);
        $toProduct   = (new ProductFactory())->create(['post_title' => 'UpgradeView145To']);
        $fromVariantId = (int) $fromProduct->variants->first()->id;
        $toVariantId   = (int) $toProduct->variants->first()->id;
        $meta = Meta::query()->create([
            'object_id'   => $fromVariantId,
            'object_type' => 'variant_upgrade',
            'meta_key'    => 'variant_upgrade_path',
            'meta_value'  => ['to_variants' => [$toVariantId], 'is_prorate' => 0, 'discount_amount' => 7],
        ]);

        [, $body] = $I->get('/fluent-cart/v2/products/' . (int) $fromProduct->ID . '/upgrade-paths');

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('data', $body, 'response must expose the upgrade-path collection under the data key');
        $rows = (array) ($body['data'] ?? []);
        Assert::assertNotEmpty($rows, 'response data must include the seeded upgrade-path meta row, not an empty list');

        $matched = null;
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === (int) $meta->id) {
                $matched = (array) $row;
                break;
            }
        }
        Assert::assertNotNull($matched, 'response must include the seeded meta row id so the admin UI can target it for edit/delete');

        $value = (array) ($matched['meta_value'] ?? []);
        Assert::assertSame([$toVariantId], array_map('intval', (array) ($value['to_variants'] ?? [])), 'meta_value.to_variants must surface in the view-payload so the admin UI can render the configured target variations');
        Assert::assertSame(0, (int) ($value['is_prorate'] ?? -1), 'meta_value.is_prorate must surface so the admin UI can show whether prorate is enabled');
        Assert::assertSame(7, (int) ($value['discount_amount'] ?? -1), 'meta_value.discount_amount must surface so the admin UI can display the configured pricing rule');
    }

    /**
     * Spec: Cases/Functional/products.md [146]
     *   "Admin can update an existing upgrade path's pricing rule."
     */
    public function adminCanUpdateExistingUpgradePathPricingRule(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $fromProduct = (new ProductFactory())->create(['post_title' => 'UpgradeUpdate146From']);
        $toProduct   = (new ProductFactory())->create(['post_title' => 'UpgradeUpdate146To']);
        $fromVariantId = (int) $fromProduct->variants->first()->id;
        $toVariantId   = (int) $toProduct->variants->first()->id;
        $meta = Meta::query()->create([
            'object_id'   => $fromVariantId,
            'object_type' => 'variant_upgrade',
            'meta_key'    => 'variant_upgrade_path',
            'meta_value'  => ['to_variants' => [$toVariantId], 'is_prorate' => 0, 'discount_amount' => 1],
        ]);

        [, $body] = $I->post('/fluent-cart/v2/products/upgrade-path/' . (int) $meta->id . '/update', [
            'from_variant'    => $fromVariantId,
            'to_variants'     => [$toVariantId],
            'is_prorate'      => 1,
            'discount_amount' => 25,
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertNotEmpty($body['message'] ?? '', 'success response must include a confirmation message');
        $refreshed = Meta::query()->find($meta->id);
        $value     = (array) ($refreshed->meta_value ?? []);
        Assert::assertSame(25, (int) ($value['discount_amount'] ?? -1), 'meta_value.discount_amount must persist the new pricing rule');
        Assert::assertSame(1, (int) ($value['is_prorate'] ?? -1), 'meta_value.is_prorate must persist the new prorate toggle');
    }

    /**
     * Spec: Cases/Functional/products.md [147]
     *   "Admin can delete an upgrade path from a product."
     */
    public function adminCanDeleteUpgradePathFromProduct(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $fromProduct = (new ProductFactory())->create(['post_title' => 'UpgradeDelete147From']);
        $toProduct   = (new ProductFactory())->create(['post_title' => 'UpgradeDelete147To']);
        $fromVariantId = (int) $fromProduct->variants->first()->id;
        $toVariantId   = (int) $toProduct->variants->first()->id;
        $meta = Meta::query()->create([
            'object_id'   => $fromVariantId,
            'object_type' => 'variant_upgrade',
            'meta_key'    => 'variant_upgrade_path',
            'meta_value'  => ['to_variants' => [$toVariantId], 'is_prorate' => 0, 'discount_amount' => 1],
        ]);
        Assert::assertSame(1, Meta::query()->where('id', $meta->id)->count(), 'fixture must persist the upgrade-path row before the delete request');

        [, $body] = $I->delete('/fluent-cart/v2/products/upgrade-path/' . (int) $meta->id . '/delete');

        $I->seeResponseCodeIs(200);
        Assert::assertNotEmpty($body['message'] ?? '', 'success response must include a confirmation message');
        Assert::assertSame(0, Meta::query()->where('id', $meta->id)->count(), 'fct_meta row for the upgrade path must be removed after delete');
    }

    /**
     * Spec: Cases/Functional/products.md [148]
     *   "Admin can list a variation's available upgrade paths."
     */
    public function adminCanListVariationAvailableUpgradePaths(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $fromProduct = (new ProductFactory())
            ->withVariantAttributes(['item_price' => Helper::toCent(50.00)])
            ->create(['post_title' => 'UpgradeListFrom148']);
        $toProduct = (new ProductFactory())
            ->withVariantAttributes([
                'variation_title' => 'Premium Plan 148',
                'item_price'      => Helper::toCent(120.00),
            ])
            ->create(['post_title' => 'UpgradeListTo148']);
        $fromVariantId = (int) $fromProduct->variants->first()->id;
        $toVariantId   = (int) $toProduct->variants->first()->id;

        Meta::query()->create([
            'object_id'   => $fromVariantId,
            'object_type' => 'variant_upgrade',
            'meta_key'    => 'variant_upgrade_path',
            'meta_value'  => ['to_variants' => [$toVariantId], 'is_prorate' => 0, 'discount_amount' => 5],
        ]);

        $customer = (new CustomerFactory())->create();
        $order    = (new OrderFactory())
            ->withAttributes([
                'customer_id'    => (int) $customer->id,
                'status'         => 'completed',
                'payment_status' => 'paid',
                'type'           => 'payment',
                'subtotal'       => Helper::toCent(50.00),
                'total_amount'   => Helper::toCent(50.00),
                'total_paid'     => Helper::toCent(50.00),
            ])
            ->create();
        (new OrderItemFactory())->create((int) $order->id, [
            'object_id'    => $fromVariantId,
            'post_id'      => (int) $fromProduct->ID,
            'unit_price'   => Helper::toCent(50.00),
            'subtotal'     => Helper::toCent(50.00),
            'line_total'   => Helper::toCent(50.00),
            'payment_type' => 'onetime',
        ]);
        $orderHash = (string) Order::query()->find($order->id)->uuid;
        Assert::assertNotEmpty($orderHash, 'order fixture must auto-assign a uuid (used as order_hash) so the controller can resolve the order');

        [, $body] = $I->get('/fluent-cart/v2/products/variation/' . $fromVariantId . '/upgrade-paths', [
            'params' => ['order_hash' => $orderHash],
        ]);

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('upgradePaths', $body, 'response must expose the upgrade-path collection under the upgradePaths key');
        $paths = (array) ($body['upgradePaths'] ?? []);
        Assert::assertCount(1, $paths, 'exactly one upgrade-path row must be returned for the seeded from-variant');
        $row = $paths[0];
        Assert::assertSame($toVariantId, (int) ($row['to_variant'] ?? 0), 'upgrade-path row must reference the seeded target variation id');
        Assert::assertSame('Premium Plan 148', (string) ($row['title'] ?? ''), 'upgrade-path row must echo the target variation_title so the customer-facing UI can render the upgrade label');
    }

    /**
     * Spec: Cases/Functional/products.md [149]
     *   "Admin is rejected when adding an upgrade path with invalid pricing input."
     */
    public function addingUpgradePathWithInvalidPricingInputIsRejected(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $fromProduct = (new ProductFactory())->create(['post_title' => 'UpgradeInvalid149From']);
        $toProduct   = (new ProductFactory())->create(['post_title' => 'UpgradeInvalid149To']);
        $fromVariantId = (int) $fromProduct->variants->first()->id;
        $toVariantId   = (int) $toProduct->variants->first()->id;
        Assert::assertSame(0, Meta::query()->where('object_type', 'variant_upgrade')->count(), 'fixture must start with no upgrade-path rows so we can prove a rejected request did not persist anything');

        [$status, $body] = $I->post(
            '/fluent-cart/v2/products/' . (int) $fromProduct->ID . '/upgrade-path',
            [
                'from_variant'    => $fromVariantId,
                'to_variants'     => [$toVariantId],
                'is_prorate'      => 0,
                'discount_amount' => 'not-a-number',
            ]
        );

        $I->seeResponseCodeIs(422);
        Assert::assertArrayHasKey('discount_amount', $body, 'validation error body must call out the discount_amount field so the admin UI can highlight it');
        $fieldErrors = (array) ($body['discount_amount'] ?? []);
        Assert::assertArrayHasKey('numeric', $fieldErrors, 'validation error body must surface the failing rule key (numeric) so the UI can render the right hint');
        Assert::assertSame(0, Meta::query()->where('object_type', 'variant_upgrade')->count(), 'rejected save must not persist any fct_meta rows');
    }
}
