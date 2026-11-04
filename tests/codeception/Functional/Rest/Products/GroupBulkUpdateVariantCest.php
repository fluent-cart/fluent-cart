<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Working with variations" section, items 227, 228.
 *
 * Covers POST /fluent-cart/v2/products/variants/group-bulk-update, specifically
 * the sku.maxLength custom-message regression: maxLength is a
 * Validator::extend()-registered custom rule (Rule::add() in
 * app/Hooks/includes.php), and the framework's Validator::__call() dispatcher
 * for extend()-registered rules always uses the rule callback's own returned
 * message, never GroupBulkUpdateVariantRequest::messages(). Confirms the fix
 * in app/Http/Rules/MaxLengthRule.php (withMessage()) restores the custom
 * message without touching the framework.
 */
class GroupBulkUpdateVariantCest
{
    /**
     * Spec: Cases/Functional/products.md [227]
     *   "Admin sees the custom SKU length message when group-bulk-updating
     *    a variation with a SKU longer than 30 characters."
     */
    public function adminSeesCustomSkuLengthMessageOnGroupBulkUpdate(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())
            ->withVariantAttributes(['variation_title' => 'TooLongSku227', 'sku' => 'ORIGINAL-SKU-227'])
            ->create(['post_title' => 'GroupBulkTooLong227']);
        $variant = $product->variants->first();
        $overLengthSku = str_repeat('X', 31);

        [$status, $body] = $I->post('/fluent-cart/v2/products/variants/group-bulk-update', [
            'variant_ids' => [$variant->id],
            'sku'         => $overLengthSku,
        ]);

        Assert::assertSame(422, $status, 'a 31-character SKU must be rejected by the maxLength:30 rule. Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('sku', (array) $body, 'validation payload must key the failed rule by field name (sku). Body: ' . wp_json_encode($body));
        Assert::assertArrayHasKey('maxLength', (array) ($body['sku'] ?? []), 'sku rejection must surface the maxLength rule key. Body: ' . wp_json_encode($body));
        Assert::assertSame(
            'SKU may not be greater than 30 characters.',
            (string) ($body['sku']['maxLength'] ?? ''),
            'sku.maxLength must return the custom message registered on the rule, not the framework\'s generic wording'
        );

        $refreshed = ProductVariation::query()->find($variant->id);
        Assert::assertSame('ORIGINAL-SKU-227', (string) $refreshed->sku, 'rejected group-bulk-update must not mutate the stored sku');
    }

    /**
     * Spec: Cases/Functional/products.md [228]
     *   "Admin can group-bulk-update a variation's SKU when it is 30
     *    characters or fewer."
     */
    public function adminCanGroupBulkUpdateSkuWithinLengthLimit(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())
            ->withVariantAttributes(['variation_title' => 'WithinLimitSku228', 'sku' => 'ORIGINAL-SKU-228'])
            ->create(['post_title' => 'GroupBulkWithinLimit228']);
        $variant = $product->variants->first();
        $thirtyCharSku = str_repeat('Y', 30);

        [$status, $body] = $I->post('/fluent-cart/v2/products/variants/group-bulk-update', [
            'variant_ids' => [$variant->id],
            'sku'         => $thirtyCharSku,
        ]);

        Assert::assertSame(200, $status, 'a 30-character SKU must pass the maxLength:30 rule. Body: ' . wp_json_encode($body));
        $refreshed = ProductVariation::query()->find($variant->id);
        Assert::assertSame($thirtyCharSku, (string) $refreshed->sku, 'fct_product_variations.sku must persist the new 30-character sku');
    }
}
