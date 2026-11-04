<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Managing inventory and stock", items 101-106.
 *
 * Side-effects Cest. Each method asserts the cascading DB state changes
 * (variation.stock_status, variation.manage_stock, detail.stock_availability,
 * detail.manage_stock) that the inventory routes are documented to produce.
 *
 * Since 38700d9ec (#2643) both inventory routes refuse with 422 while the
 * Stock Management module is off, and the functional suite boots with it off.
 * _before arms the module for every method; the one method that needs it off
 * disarms it explicitly and _after restores the suite default.
 */
class ManageProductStockCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
        $I->armStockListeners();
    }

    public function _after(FunctionalTester $I): void
    {
        $I->disarmStockListeners();
        // The module flag lives in wp_options, which resetFluentCartTables does
        // not touch; drop it and refresh the memoized ModuleSettings cache so no
        // sibling Cest inherits an armed module.
        delete_option(ModuleSettings::MODULE_SETTINGS_OPTION);
        ModuleSettings::getAllSettings(false);
    }

    /**
     * Spec: Cases/Functional/products.md [101]
     *   "Admin can update a variation's available stock count."
     */
    public function adminCanUpdateVariationAvailableStockCount(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1, 'stock_availability' => 'in-stock'])
            ->withVariantAttributes(['manage_stock' => 1, 'stock_status' => 'in-stock', 'available' => 7, 'total_stock' => 7])
            ->create(['post_title' => 'StockCount101']);
        $variant = $product->variants->first();
        Assert::assertSame(7, (int) $variant->available, 'fixture must start with available=7 to prove the new value 42 is the route-applied delta and not a tautological echo');
        Assert::assertSame(7, (int) $variant->total_stock, 'fixture must start with total_stock=7 to prove the new total 42 is the route-applied delta');

        [$status, $body] = $I->put(
            '/fluent-cart/v2/products/' . $product->ID . '/update-inventory/' . $variant->id,
            ['total_stock' => 42, 'available' => 42]
        );

        $I->seeResponseCodeIs(200);
        Assert::assertNotEmpty($body['message'] ?? '', 'success response must include a confirmation message so the admin UI can surface it');
        Assert::assertStringContainsStringIgnoringCase('updated', (string) ($body['message'] ?? ''), 'success message must communicate the update happened (e.g. "Inventory updated successfully")');
        $refreshed = ProductVariation::query()->find($variant->id);
        Assert::assertSame(42, (int) $refreshed->available, 'variation.available must persist the new count from the request body');
        Assert::assertSame(42, (int) $refreshed->total_stock, 'variation.total_stock must persist the new total from the request body');
    }

    /**
     * Spec: Cases/Functional/products.md [102]
     *   "Admin sees a variation's stock status recalculated automatically based on the new available quantity."
     */
    public function variationStockStatusRecalculatedWhenAvailableChanges(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1, 'stock_availability' => 'out-of-stock'])
            ->withVariantAttributes(['manage_stock' => 1, 'stock_status' => 'out-of-stock', 'available' => 0, 'total_stock' => 0])
            ->create(['post_title' => 'StockRecalc102']);
        $variant = $product->variants->first();
        Assert::assertSame('out-of-stock', (string) $variant->stock_status, 'fixture must start out-of-stock to prove recalculation flips it');

        [$status] = $I->put(
            '/fluent-cart/v2/products/' . $product->ID . '/update-inventory/' . $variant->id,
            ['total_stock' => 25, 'available' => 25]
        );

        $I->seeResponseCodeIs(200);
        $refreshed = ProductVariation::query()->find($variant->id);
        Assert::assertSame('in-stock', (string) $refreshed->stock_status, 'variation.stock_status must auto-flip to in-stock when available > 0');
        Assert::assertSame(25, (int) $refreshed->available, 'variation.available must persist the new quantity');
        Assert::assertSame(25, (int) $refreshed->total_stock, 'variation.total_stock must persist the new total');
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame('in-stock', (string) $detail->stock_availability, 'detail.stock_availability must reflect the new in-stock cascade');
    }

    /**
     * Spec: Cases/Functional/products.md [103]
     *   "Admin sees a variation marked out of stock when its available stock is set to zero."
     */
    public function variationMarkedOutOfStockWhenAvailableIsZero(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1, 'stock_availability' => 'in-stock'])
            ->withVariantAttributes(['manage_stock' => 1, 'stock_status' => 'in-stock', 'available' => 5, 'total_stock' => 5])
            ->create(['post_title' => 'StockZero103']);
        $variant = $product->variants->first();
        Assert::assertSame('in-stock', (string) $variant->stock_status, 'fixture must start in-stock to prove zero quantity flips it');

        [$status] = $I->put(
            '/fluent-cart/v2/products/' . $product->ID . '/update-inventory/' . $variant->id,
            ['total_stock' => 0, 'available' => 0]
        );

        $I->seeResponseCodeIs(200);
        $refreshed = ProductVariation::query()->find($variant->id);
        Assert::assertSame('out-of-stock', (string) $refreshed->stock_status, 'variation.stock_status must auto-flip to out-of-stock when available is set to 0');
        Assert::assertSame(0, (int) $refreshed->available, 'variation.available must persist the zero quantity');
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame('out-of-stock', (string) $detail->stock_availability, 'detail.stock_availability must reflect the cascade when the only variation hits zero');
    }

    /**
     * Spec: Cases/Functional/products.md [104]
     *   "Admin can enable stock management on a product so all of its variations begin tracking stock."
     */
    public function enablingStockManagementCascadesToAllVariations(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 0, 'stock_availability' => 'in-stock'])
            ->createWithVariants([
                ['variation_title' => 'Small',  'manage_stock' => 0, 'stock_status' => 'in-stock', 'available' => 0, 'total_stock' => 0],
                ['variation_title' => 'Medium', 'manage_stock' => 0, 'stock_status' => 'in-stock', 'available' => 0, 'total_stock' => 0],
                ['variation_title' => 'Large',  'manage_stock' => 0, 'stock_status' => 'in-stock', 'available' => 0, 'total_stock' => 0],
            ], ['post_title' => 'StockEnable104']);
        $variantIds = $product->variants->pluck('id')->map('intval')->all();
        Assert::assertCount(3, $variantIds, 'fixture must create exactly three variations to prove the cascade is not single-row only');

        [$status] = $I->put(
            '/fluent-cart/v2/products/' . $product->ID . '/update-manage-stock',
            ['manage_stock' => 1]
        );

        $I->seeResponseCodeIs(200);
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame(1, (int) $detail->manage_stock, 'detail.manage_stock must be enabled on the parent product');
        $cascaded = ProductVariation::query()->where('post_id', $product->ID)->get();
        Assert::assertCount(3, $cascaded, 'cascade must touch every existing variation, not a subset');
        foreach ($cascaded as $row) {
            Assert::assertSame(1, (int) $row->manage_stock, 'every variation must have manage_stock=1 after the cascade (variation id ' . $row->id . ')');
        }
    }

    /**
     * Spec: Cases/Functional/products.md [105]
     *   "Admin sees every variation marked in stock regardless of stored quantity when stock management is disabled."
     */
    public function disablingStockManagementMarksAllVariationsInStock(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1, 'stock_availability' => 'out-of-stock'])
            ->createWithVariants([
                ['variation_title' => 'Empty', 'manage_stock' => 1, 'stock_status' => 'out-of-stock', 'available' => 0, 'total_stock' => 0],
                ['variation_title' => 'Some',  'manage_stock' => 1, 'stock_status' => 'in-stock',     'available' => 3, 'total_stock' => 3],
            ], ['post_title' => 'StockDisable105']);
        Assert::assertSame(
            ['out-of-stock', 'in-stock'],
            ProductVariation::query()->where('post_id', $product->ID)->orderBy('serial_index')->pluck('stock_status')->all(),
            'fixture must include one out-of-stock and one in-stock variation to prove the override applies regardless of qty'
        );

        [$status] = $I->put(
            '/fluent-cart/v2/products/' . $product->ID . '/update-manage-stock',
            ['manage_stock' => 0]
        );

        $I->seeResponseCodeIs(200);
        $detail = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame(0, (int) $detail->manage_stock, 'detail.manage_stock must be disabled on the parent product');
        Assert::assertSame('in-stock', (string) $detail->stock_availability, 'detail.stock_availability must be in-stock when management is disabled');
        $cascaded = ProductVariation::query()->where('post_id', $product->ID)->get();
        Assert::assertCount(2, $cascaded, 'cascade must preserve all two pre-existing variations; an empty or shrunken set would let the per-row foreach below trivially pass');
        foreach ($cascaded as $row) {
            Assert::assertSame('in-stock', (string) $row->stock_status, 'every variation must be marked in-stock after disabling stock management (variation id ' . $row->id . ', stored available ' . (int) $row->available . ')');
            Assert::assertSame(0, (int) $row->manage_stock, 'every variation must have manage_stock=0 after the cascade so the in-stock override is not just a stock_status patch (variation id ' . $row->id . ')');
        }
    }

    /**
     * Spec: Cases/Functional/products.md [106]
     *   "Admin receives a clear "not found" error when updating inventory for a variation that does not exist."
     */
    public function adminReceivesNotFoundErrorUpdatingMissingVariationInventory(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1, 'stock_availability' => 'in-stock'])
            ->withVariantAttributes(['manage_stock' => 1, 'stock_status' => 'in-stock', 'available' => 5, 'total_stock' => 5])
            ->create(['post_title' => 'StockMissing106']);
        $missingVariantId = ((int) $product->variants->first()->id) + 999_999;
        Assert::assertNull(ProductVariation::query()->find($missingVariantId), 'fixture must guarantee the target variant id does not exist before the request');

        [$status, $body] = $I->put(
            '/fluent-cart/v2/products/' . $product->ID . '/update-inventory/' . $missingVariantId,
            ['total_stock' => 10, 'available' => 10]
        );

        Assert::assertSame(404, $status, 'updating inventory for a missing variation must return 404. Body: ' . wp_json_encode($body));
        Assert::assertNotEmpty($body['message'] ?? '', 'response must include a clear not-found message');
        Assert::assertStringContainsStringIgnoringCase('not found', (string) ($body['message'] ?? ''), 'message must communicate not-found semantics so the admin UI can surface a useful error');
    }

    /**
     * Spec: Cases/Functional/products.md [234]
     *   "Admin cannot update a variation's inventory while the Stock Management module is disabled."
     */
    public function adminCannotUpdateVariationInventoryWhileStockManagementModuleIsDisabled(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1, 'stock_availability' => 'in-stock'])
            ->withVariantAttributes(['manage_stock' => 1, 'stock_status' => 'in-stock', 'available' => 5, 'total_stock' => 5])
            ->create(['post_title' => 'StockModuleOff108']);
        $variant = $product->variants->first();
        $I->disarmStockListeners();
        Assert::assertFalse(ModuleSettings::isActive('stock_management'), 'fixture must have the Stock Management module off so the 422 below is the module gate, not a validation error');

        [$status, $body] = $I->put(
            '/fluent-cart/v2/products/' . $product->ID . '/update-inventory/' . $variant->id,
            ['total_stock' => 42, 'available' => 42]
        );

        Assert::assertSame(422, $status, 'inventory update must be refused while the module is disabled. Body: ' . wp_json_encode($body));
        Assert::assertStringContainsStringIgnoringCase('Stock Management module is disabled', (string) ($body['message'] ?? ''), 'refusal must name the module so the admin knows where to enable it');
        $refreshed = ProductVariation::query()->find($variant->id);
        Assert::assertSame(5, (int) $refreshed->available, 'a refused update must leave variation.available untouched');
        Assert::assertSame(5, (int) $refreshed->total_stock, 'a refused update must leave variation.total_stock untouched');
    }
}
