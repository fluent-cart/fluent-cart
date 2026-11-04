<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Working with variations" section, items 92, 93.
 *
 * Action-named Cest covering PUT /fluent-cart/v2/products/variants/{variantId}/pricing-table.
 * The route persists the description into variation.other_info['description'] via
 * sanitize_textarea_field, which is documented to retain newlines.
 */
class UpdateVariationPricingTableCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    /**
     * Spec: Cases/Functional/products.md [92]
     *   "Admin can update a variation's pricing-table description."
     */
    public function adminCanUpdateVariationPricingTableDescription(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())
            ->withVariantAttributes(['payment_type' => 'onetime', 'other_info' => ['payment_type' => 'onetime', 'description' => '']])
            ->create(['post_title' => 'PricingTable92']);
        $variant = $product->variants->first();
        $other = is_array($variant->other_info) ? $variant->other_info : [];
        Assert::assertSame('', (string) ($other['description'] ?? ''), 'fixture must seed an empty pricing-table description so the change is observable');
        Assert::assertSame('onetime', (string) ($other['payment_type'] ?? ''), 'fixture must seed sibling other_info.payment_type so the test can prove the update does not clobber unrelated keys');

        $newDescription = 'Premium plan: includes priority support, a dedicated account manager, and SLA-backed response times.';
        [$status, $body] = $I->put(
            '/fluent-cart/v2/products/variants/' . $variant->id . '/pricing-table',
            ['description' => $newDescription]
        );

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('data', $body, 'response body must surface the updated row under the data key');
        $responseOther = (array) ($body['data']['other_info'] ?? []);
        Assert::assertSame($newDescription, (string) ($responseOther['description'] ?? ''), 'response body other_info.description must echo the new pricing-table description');
        $refreshed = ProductVariation::query()->find($variant->id);
        $refreshedOther = is_array($refreshed->other_info) ? $refreshed->other_info : [];
        Assert::assertSame($newDescription, (string) ($refreshedOther['description'] ?? ''), 'fct_product_variations.other_info.description must persist the new pricing-table description verbatim');
        Assert::assertSame('onetime', (string) ($refreshedOther['payment_type'] ?? ''), 'pricing-table update must preserve sibling other_info keys (payment_type) — not clobber the whole JSON column');
    }

    /**
     * Spec: Cases/Functional/products.md [93]
     *   "Admin sees newlines preserved in a variation's pricing-table description."
     */
    public function newlinesPreservedInVariationPricingTableDescription(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'Newlines93']);
        $variant = $product->variants->first();

        $multiline = "Line one of the description.\nLine two follows after a newline.\nLine three after another newline.";

        [$status, $body] = $I->put(
            '/fluent-cart/v2/products/variants/' . $variant->id . '/pricing-table',
            ['description' => $multiline]
        );

        $I->seeResponseCodeIs(200);
        Assert::assertArrayHasKey('data', $body, 'response body must surface the updated row under the data key');
        $responseOther = (array) ($body['data']['other_info'] ?? []);
        Assert::assertSame($multiline, (string) ($responseOther['description'] ?? ''), 'response body must echo the description with newlines intact (proves the API does not silently strip them on the way out)');
        Assert::assertSame(2, substr_count((string) ($responseOther['description'] ?? ''), "\n"), 'response body description must contain exactly the 2 submitted newlines (no collapse to spaces in the response)');
        $refreshed = ProductVariation::query()->find($variant->id);
        $refreshedOther = is_array($refreshed->other_info) ? $refreshed->other_info : [];
        $persisted = (string) ($refreshedOther['description'] ?? '');
        Assert::assertSame($multiline, $persisted, 'pricing-table description must round-trip with newlines preserved in the DB (sanitize_textarea_field, not sanitize_text_field)');
        Assert::assertSame(2, substr_count($persisted, "\n"), 'persisted description must contain exactly the 2 submitted newline characters (no collapse to spaces)');
    }
}
