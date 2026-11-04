<?php

namespace Tests\Integration\Http;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\RestTestCase;

/**
 * The Stock Management module (Settings -> Modules) is the master switch for
 * inventory. With it off, the inventory endpoints must refuse to turn stock
 * tracking on — otherwise a variation can end up with manage_stock = 1 while
 * the store has inventory globally disabled, and every stock consumer
 * (canPurchase, renderers, StockManagement listeners) disagrees about whether
 * the product tracks stock.
 *
 * Turning inventory OFF stays allowed while the module is off, so a store that
 * disables the module can still clean up products left with manage_stock = 1.
 */
class ProductInventoryModuleGateTest extends RestTestCase
{
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->productFactory = new ProductFactory();
        $this->setStockModule('no');
    }

    public function tearDown(): void
    {
        delete_option(ModuleSettings::MODULE_SETTINGS_OPTION);
        ModuleSettings::getAllSettings(false);
        wp_cache_flush();
        parent::tearDown();
    }

    /**
     * ModuleSettings::getAllSettings() memoizes in a static, so the option write
     * alone is not visible to isActive(). Force the uncached read after writing.
     */
    private function setStockModule(string $active): void
    {
        update_option(ModuleSettings::MODULE_SETTINGS_OPTION, [
            'stock_management' => ['active' => $active],
        ]);
        ModuleSettings::getAllSettings(false);
    }

    public function test_update_manage_stock_is_rejected_while_the_module_is_disabled(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        $request = new \WP_REST_Request('PUT', '/fluent-cart/v2/products/' . $product->ID . '/update-manage-stock');
        $request->set_body_params(['manage_stock' => '1']);

        $response = $this->dispatchRequestSilently($request);

        $this->assertEquals(422, $response->get_status());

        $this->assertEquals(
            0,
            (int) ProductDetail::query()->where('post_id', $product->ID)->value('manage_stock'),
            'Product detail must not enable manage_stock while the module is off.'
        );
        $this->assertEquals(
            0,
            (int) ProductVariation::query()->find($variant->id)->manage_stock,
            'Variation must not enable manage_stock while the module is off.'
        );
    }

    public function test_update_manage_stock_can_still_disable_inventory_while_the_module_is_disabled(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        ProductDetail::query()->where('post_id', $product->ID)->update(['manage_stock' => 1]);
        ProductVariation::query()->where('id', $variant->id)->update(['manage_stock' => 1]);

        $request = new \WP_REST_Request('PUT', '/fluent-cart/v2/products/' . $product->ID . '/update-manage-stock');
        $request->set_body_params(['manage_stock' => '0']);

        $response = $this->dispatchRequestSilently($request);

        $this->assertEquals(200, $response->get_status());
        $this->assertEquals(
            0,
            (int) ProductDetail::query()->where('post_id', $product->ID)->value('manage_stock')
        );
        $this->assertEquals(
            0,
            (int) ProductVariation::query()->find($variant->id)->manage_stock
        );
    }

    public function test_update_inventory_is_rejected_while_the_module_is_disabled(): void
    {
        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        $request = new \WP_REST_Request('PUT', '/fluent-cart/v2/products/' . $product->ID . '/update-inventory/' . $variant->id);
        $request->set_body_params(['total_stock' => 25, 'available' => 25]);

        $response = $this->dispatchRequestSilently($request);

        $this->assertEquals(422, $response->get_status());

        $reloaded = ProductVariation::query()->find($variant->id);
        $this->assertEquals(0, (int) $reloaded->manage_stock, 'updateInventory must not force manage_stock on while the module is off.');
        $this->assertEquals(
            0,
            (int) ProductDetail::query()->where('post_id', $product->ID)->value('manage_stock')
        );
    }

    public function test_update_inventory_is_allowed_once_the_module_is_enabled(): void
    {
        $this->setStockModule('yes');

        $product = $this->productFactory->create();
        $variant = $product->variants->first();

        $request = new \WP_REST_Request('PUT', '/fluent-cart/v2/products/' . $product->ID . '/update-inventory/' . $variant->id);
        $request->set_body_params(['total_stock' => 25, 'available' => 25]);

        $response = $this->dispatchRequestSilently($request);

        $this->assertEquals(200, $response->get_status());

        $reloaded = ProductVariation::query()->find($variant->id);
        $this->assertEquals(25, (int) $reloaded->total_stock);
        $this->assertEquals(25, (int) $reloaded->available);
    }
}
