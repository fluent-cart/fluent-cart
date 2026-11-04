<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Working with variations" section, items 84, 85, 95, 98.
 *
 * Action-named Cest covering POST /fluent-cart/v2/products/variants. Variation
 * permission denials for the create route live here next to the happy-path,
 * mirroring the convention already used by CreateProductCest.
 */
class CreateProductVariationCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    private function buildVariantPayload(int $productId, array $overrides = []): array
    {
        $variant = array_merge([
            'post_id'          => $productId,
            'variation_title'  => 'Medium',
            'sku'              => 'SKU-' . wp_generate_password(6, false, false),
            'item_price'       => Helper::toCent(12.34),
            // compare_price intentionally omitted: ProductVariationRequest enforces
            // compare_price >= item_price when present and non-empty; the resource
            // defaults a missing/empty value to 0c, which is the desired baseline.
            'manage_cost'      => 'false',
            'fulfillment_type' => 'physical',
            'manage_stock'     => 1,
            'stock_status'     => 'in-stock',
            'total_stock'      => 10,
            'available'        => 10,
            'committed'        => 0,
            'on_hold'          => 0,
            'serial_index'     => 1,
            'other_info'       => ['payment_type' => 'onetime'],
        ], $overrides);

        return ['variants' => $variant];
    }

    /**
     * Spec: Cases/Functional/products.md [84]
     *   "Admin can add a new variation to a product."
     */
    public function adminCanAddNewVariationToProduct(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'AddVar84']);
        $beforeCount = ProductVariation::query()->where('post_id', $product->ID)->count();
        Assert::assertSame(1, $beforeCount, 'fixture must start with the single seeded default variation so post-create count proves a new row was inserted');

        $payload = $this->buildVariantPayload((int) $product->ID, [
            'variation_title' => 'NewVar84',
            'item_price'      => Helper::toCent(19.99),
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/products/variants', $payload);

        $I->seeResponseCodeIs(200);
        $afterRows = ProductVariation::query()->where('post_id', $product->ID)->get();
        Assert::assertCount($beforeCount + 1, $afterRows, 'POST /products/variants must persist exactly one additional fct_product_variations row for this product');
        $created = ProductVariation::query()
            ->where('post_id', $product->ID)
            ->where('variation_title', 'NewVar84')
            ->first();
        Assert::assertNotNull($created, 'created variation must be discoverable by its submitted title');
        Assert::assertSame((int) $product->ID, (int) $created->post_id, 'persisted variation must be linked to the requesting product via post_id');
        Assert::assertSame(1999, (int) $created->item_price, 'persisted item_price must be stored as cents (1999 for $19.99)');
        Assert::assertSame('physical', (string) $created->fulfillment_type, 'persisted fulfillment_type must match the submitted value');
    }

    /**
     * Spec: Cases/Functional/products.md [85]
     *   "Admin sees a new variation linked to the product's detail record automatically."
     */
    public function adminSeesNewVariationLinkedToProductDetailRecordAutomatically(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1, 'stock_availability' => 'out-of-stock'])
            ->withVariantAttributes(['manage_stock' => 1, 'stock_status' => 'out-of-stock', 'available' => 0, 'total_stock' => 0])
            ->create(['post_title' => 'Link85']);
        $detailBefore = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame('out-of-stock', (string) $detailBefore->stock_availability, 'fixture detail must start out-of-stock so the cascade-driven flip is observable');

        $payload = $this->buildVariantPayload((int) $product->ID, [
            'variation_title' => 'Linked85',
            'manage_stock'    => 1,
            'stock_status'    => 'in-stock',
            'available'       => 5,
            'total_stock'     => 5,
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/products/variants', $payload);

        $I->seeResponseCodeIs(200);
        $created = ProductVariation::query()
            ->where('post_id', $product->ID)
            ->where('variation_title', 'Linked85')
            ->first();
        Assert::assertNotNull($created, 'newly created variation must persist tied to the parent product post_id');
        Assert::assertSame((int) $product->ID, (int) $created->post_id, 'variation.post_id must equal the parent product id (the link to the detail row that shares the same post_id)');
        $detailAfter = ProductDetail::query()->where('post_id', $product->ID)->first();
        Assert::assertSame((int) $detailBefore->id, (int) $detailAfter->id, 'detail row identity must be preserved (cascade updates the existing detail, does not insert a second one)');
        Assert::assertSame('in-stock', (string) $detailAfter->stock_availability, 'creating an in-stock variation must trigger ProductDetailResource::update with action=variant_modified, flipping detail.stock_availability from out-of-stock to in-stock');
    }

    /**
     * Spec: Cases/Functional/products.md [95]
     *   "A worker without the "create products" permission cannot create a variation."
     */
    public function workerWithoutCreateProductsPermissionCannotCreateVariation(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'WorkerVar95']);
        $beforeCount = ProductVariation::query()->where('post_id', $product->ID)->count();
        $I->loginAsWorker();

        [$status, $body] = $I->post('/fluent-cart/v2/products/variants', $this->buildVariantPayload((int) $product->ID, [
            'variation_title' => 'WorkerAttempt95',
        ]));

        Assert::assertSame(403, $status, 'worker without products/create must be rejected with 403 from POST /products/variants');
        Assert::assertSame('rest_forbidden', (string) ($body['code'] ?? ''), 'WP REST permission_callback denial must surface the rest_forbidden code on the body');
        Assert::assertNotEmpty($body['message'] ?? '', 'rejected variant create must include a permission-denied message');
        Assert::assertSame(
            $beforeCount,
            ProductVariation::query()->where('post_id', $product->ID)->count(),
            'rejected variant create must not insert any new fct_product_variations row for the product'
        );
    }

    /**
     * Spec: Cases/Functional/products.md [98]
     *   "An accountant cannot create a variation."
     */
    public function accountantCannotCreateVariation(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'AcctVar98']);
        $beforeCount = ProductVariation::query()->where('post_id', $product->ID)->count();
        $I->loginAsAccountant();

        [$status, $body] = $I->post('/fluent-cart/v2/products/variants', $this->buildVariantPayload((int) $product->ID, [
            'variation_title' => 'AcctAttempt98',
        ]));

        Assert::assertSame(403, $status, 'accountant must be rejected with 403 from POST /products/variants');
        Assert::assertSame('rest_forbidden', (string) ($body['code'] ?? ''), 'WP REST permission_callback denial must surface the rest_forbidden code on the body');
        Assert::assertNotEmpty($body['message'] ?? '', 'rejected variant create must include a permission-denied message');
        Assert::assertSame(
            $beforeCount,
            ProductVariation::query()->where('post_id', $product->ID)->count(),
            'rejected variant create must not insert any new fct_product_variations row for the product'
        );
    }
}
