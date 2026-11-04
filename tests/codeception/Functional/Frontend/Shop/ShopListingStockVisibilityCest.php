<?php

namespace Tests\Functional\Frontend\Shop;

use FluentCart\Api\ModuleSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\ProductDetail;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/stock.md → "Browsing the shop" section, items 3-4.
 *
 * Tests the variant→aggregate→listing chain end-to-end:
 *   1. variants change state through real consumption (POST /orders)
 *   2. StockManagement listener at app/Modules/StockManagement/StockManagement.php:208-233
 *      recomputes detail.stock_availability from `exists(stock_status='in-stock')`
 *      across the product's variants
 *   3. filterShopQuery (lines 88-114) reads the aggregate flag and hides/shows
 *      the product accordingly
 *
 * Driving the aggregate via consumption (not direct DB writes) means a
 * production bug in the derivation path would surface here. armStockListeners
 * wires the order_created handler since the suite's plugin boot happened with
 * stock_management=off.
 */
class ShopListingStockVisibilityCest
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
        // Reset stock_management module flag so tests that flip it on do not leak
        // into sibling methods. resetFluentCartTables only truncates fct_*; the
        // module setting lives in the wp_options row.
        delete_option(ModuleSettings::MODULE_SETTINGS_OPTION);
        ModuleSettings::getAllSettings(false);
    }

    private function enableStockManagementModule(): void
    {
        $settings = ModuleSettings::getAllSettings(false);
        $settings['stock_management'] = ['active' => 'yes'];
        ModuleSettings::saveSettings($settings);
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

    private function findRowById(array $body, int $productId): ?array
    {
        foreach ($this->extractProductRows($body) as $candidate) {
            if ((int) ($candidate['ID'] ?? 0) === $productId) {
                return (array) $candidate;
            }
        }
        return null;
    }

    /**
     * Spec: Cases/Functional/stock.md [3]
     *   "A product whose every variant is out of stock is shown as out-of-stock in the listing."
     */
    public function productWithAllVariantsOutOfStockIsShownAsOutOfStockInListing(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'stock-listing-3@example.test']);

        // Factory writes detail.stock_availability='in-stock' (the default) and seeds two
        // in-stock variants. After the order consumes the last unit of each, the
        // listener-driven aggregate is the only place the OOS flag can come from.
        $product = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1])
            ->createWithVariants(
                [
                    [
                        'variation_title' => 'AllOOSVar1',
                        'manage_stock'    => 1,
                        'stock_status'    => 'in-stock',
                        'available'       => 1,
                        'total_stock'     => 1,
                        'item_price'      => Helper::toCent(10),
                    ],
                    [
                        'variation_title' => 'AllOOSVar2',
                        'manage_stock'    => 1,
                        'stock_status'    => 'in-stock',
                        'available'       => 1,
                        'total_stock'     => 1,
                        'item_price'      => Helper::toCent(20),
                    ],
                ],
                ['post_title' => 'AllVariantsOOS3']
            );
        $variants = $product->variants->all();
        Assert::assertCount(2, $variants, 'fixture must seed exactly two variants so the aggregate has both to inspect');

        $baselineDetail = ProductDetail::query()->where('post_id', (int) $product->ID)->first();
        Assert::assertSame(
            'in-stock',
            (string) $baselineDetail->stock_availability,
            'baseline detail.stock_availability must read in-stock before consumption — factory default — so the post-consumption flip proves the derivation ran'
        );

        // Consume the last unit of each variant in a single REST call.
        $I->loginAsSuperAdmin();
        [$createStatus, $createBody] = $I->createOrder([
            'customer_id' => (int) $customer->id,
            'order_items' => [
                [
                    'post_id'          => (int) $product->ID,
                    'object_id'        => (int) $variants[0]->id,
                    'quantity'         => 1,
                    'unit_price'       => Helper::toCent(10),
                    'fulfillment_type' => 'digital',
                    'payment_type'     => 'onetime',
                    'other_info'       => ['payment_type' => 'onetime'],
                ],
                [
                    'post_id'          => (int) $product->ID,
                    'object_id'        => (int) $variants[1]->id,
                    'quantity'         => 1,
                    'unit_price'       => Helper::toCent(20),
                    'fulfillment_type' => 'digital',
                    'payment_type'     => 'onetime',
                    'other_info'       => ['payment_type' => 'onetime'],
                ],
            ],
        ]);
        Assert::assertSame(200, $createStatus, 'consume-both-variants order must succeed so the listener runs the aggregate derivation. Body: ' . wp_json_encode($createBody));

        // Sanity check on the listener-driven aggregate before asserting listing-visibility.
        $derivedDetail = ProductDetail::query()->where('post_id', (int) $product->ID)->first();
        Assert::assertSame(
            'out-of-stock',
            (string) $derivedDetail->stock_availability,
            'StockManagement listener must derive detail.stock_availability=out-of-stock once every variant has stock_status=out-of-stock'
        );

        // Default listing — guest shopper, allow_out_of_stock=false — must hide the product
        // because filterShopQuery reads the derived aggregate.
        $I->logout();
        [$defaultStatus, $defaultBody] = $I->get('/fluent-cart/v2/public/products');
        Assert::assertSame(
            200,
            $defaultStatus,
            'public products endpoint must respond 200 on the default listing. Body: ' . wp_json_encode($defaultBody)
        );
        Assert::assertNotContains(
            (int) $product->ID,
            $this->extractProductIds($defaultBody),
            'all-variants-OOS product must be hidden from the default listing once the listener-derived aggregate is out-of-stock'
        );

        // Opt-in listing — allow_out_of_stock=1 — must include the product and surface its
        // listener-derived OOS aggregate on the detail relation.
        [$optInStatus, $optInBody] = $I->get('/fluent-cart/v2/public/products', [
            'allow_out_of_stock' => 1,
            'with'               => ['detail'],
        ]);
        Assert::assertSame(
            200,
            $optInStatus,
            'public products endpoint must respond 200 on the allow_out_of_stock listing. Body: ' . wp_json_encode($optInBody)
        );
        $row = $this->findRowById($optInBody, (int) $product->ID);
        Assert::assertNotNull(
            $row,
            'all-variants-OOS product must appear in the opt-in listing once allow_out_of_stock=1 is requested. Body: ' . wp_json_encode($optInBody)
        );
        Assert::assertSame(
            'out-of-stock',
            (string) ($row['detail']['stock_availability'] ?? ''),
            'opt-in listing must surface the listener-derived detail.stock_availability=out-of-stock to the shopper'
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [4]
     *   "A product with at least one in-stock variant is shown as in-stock in the listing."
     */
    public function productWithAtLeastOneInStockVariantIsShownAsInStockInListing(FunctionalTester $I): void
    {
        $customer = (new CustomerFactory())->create(['email' => 'stock-listing-4@example.test']);

        // Mixed variants — first in-stock with room to spare, second already at zero.
        // Consuming a single unit of the in-stock one triggers the listener-driven
        // aggregate path and must leave detail.stock_availability=in-stock because
        // the surviving variant still reports stock_status=in-stock.
        $product = (new ProductFactory())
            ->withDetailAttributes(['manage_stock' => 1])
            ->createWithVariants(
                [
                    [
                        'variation_title' => 'MixedInStock',
                        'manage_stock'    => 1,
                        'stock_status'    => 'in-stock',
                        'available'       => 5,
                        'total_stock'     => 5,
                        'item_price'      => Helper::toCent(10),
                    ],
                    [
                        'variation_title' => 'MixedOOS',
                        'manage_stock'    => 1,
                        'stock_status'    => 'out-of-stock',
                        'available'       => 0,
                        'total_stock'     => 0,
                        'item_price'      => Helper::toCent(20),
                    ],
                ],
                ['post_title' => 'AtLeastOneInStock4']
            );
        $inStockVariant = $product->variants->firstWhere('stock_status', 'in-stock');
        Assert::assertNotNull($inStockVariant, 'fixture must seed at least one in-stock variant to exercise the in-stock branch of the aggregate derivation');

        // Consume one unit of the in-stock variant so the listener fires the aggregate
        // derivation. Available drops to 4 (still in-stock), so the listener must keep
        // detail.stock_availability=in-stock — that is exactly what the spec asserts.
        $I->loginAsSuperAdmin();
        [$createStatus, $createBody] = $I->createOrder([
            'customer_id' => (int) $customer->id,
            'order_items' => [
                [
                    'post_id'          => (int) $product->ID,
                    'object_id'        => (int) $inStockVariant->id,
                    'quantity'         => 1,
                    'unit_price'       => Helper::toCent(10),
                    'fulfillment_type' => 'digital',
                    'payment_type'     => 'onetime',
                    'other_info'       => ['payment_type' => 'onetime'],
                ],
            ],
        ]);
        Assert::assertSame(200, $createStatus, 'consume-one-unit order must succeed so the listener runs the aggregate derivation. Body: ' . wp_json_encode($createBody));

        // Prove the listener actually ran by inspecting the consumed variant — its
        // counters must reflect the decrement+reserve diff. Without this evidence the
        // post-derivation aggregate assertion below would still pass against the
        // factory default detail.stock_availability='in-stock' even if the listener
        // never executed.
        $freshVariant = \FluentCart\App\Models\ProductVariation::query()->find((int) $inStockVariant->id);
        Assert::assertSame(
            4,
            (int) $freshVariant->available,
            'consumed in-stock variant must decrement available from 5 to 4 — direct proof the StockManagement listener ran'
        );
        Assert::assertSame(
            1,
            (int) $freshVariant->on_hold,
            'consumed in-stock variant must reserve 1 unit on on_hold — direct proof the StockManagement listener ran'
        );
        Assert::assertSame(
            'in-stock',
            (string) $freshVariant->stock_status,
            'consumed variant must remain stock_status=in-stock since 4 units survive — feeds the aggregate hasInStock check'
        );

        // Sanity check on the listener-driven aggregate.
        $derivedDetail = ProductDetail::query()->where('post_id', (int) $product->ID)->first();
        Assert::assertSame(
            'in-stock',
            (string) $derivedDetail->stock_availability,
            'StockManagement listener must derive detail.stock_availability=in-stock when at least one variant still has stock_status=in-stock'
        );

        // Default listing — guest shopper, allow_out_of_stock=false — must include the
        // product since the listener-derived aggregate is in-stock.
        $I->logout();
        [$status, $body] = $I->get('/fluent-cart/v2/public/products', [
            'with' => ['detail'],
        ]);
        Assert::assertSame(
            200,
            $status,
            'public products endpoint must respond 200 on the default listing. Body: ' . wp_json_encode($body)
        );

        Assert::assertContains(
            (int) $product->ID,
            $this->extractProductIds($body),
            'at-least-one-in-stock product must appear in the default listing once the listener-derived aggregate is in-stock'
        );

        $row = $this->findRowById($body, (int) $product->ID);
        Assert::assertNotNull(
            $row,
            'at-least-one-in-stock product row must be returned in the response. Body: ' . wp_json_encode($body)
        );
        Assert::assertSame(
            'in-stock',
            (string) ($row['detail']['stock_availability'] ?? ''),
            'default listing must surface the listener-derived detail.stock_availability=in-stock to the shopper'
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [1]
     *   "A logged-out visitor browsing the shop sees only in-stock products by default."
     */
    public function guestBrowsingShopSeesOnlyInStockProductsByDefault(FunctionalTester $I): void
    {
        $this->enableStockManagementModule();

        $inStock = (new ProductFactory())
            ->withDetailAttributes(['stock_availability' => 'in-stock'])
            ->create(['post_title' => 'StockSpec1 InStock']);
        $outOfStock = (new ProductFactory())
            ->withDetailAttributes(['stock_availability' => 'out-of-stock'])
            ->create(['post_title' => 'StockSpec1 OOS']);

        $I->logout();
        [$status, $body] = $I->get('/fluent-cart/v2/public/products');

        Assert::assertSame(
            200,
            $status,
            'public products endpoint must respond 200 on the default listing. Body: ' . wp_json_encode($body)
        );
        $ids = $this->extractProductIds($body);
        Assert::assertContains(
            (int) $inStock->ID,
            $ids,
            'in-stock product must appear in the default shop listing for a guest visitor'
        );
        Assert::assertNotContains(
            (int) $outOfStock->ID,
            $ids,
            'out-of-stock product must be hidden from the default shop listing for a guest visitor when stock_management is active and allow_out_of_stock is not requested'
        );
    }

    /**
     * Spec: Cases/Functional/stock.md [2]
     *   "A logged-out visitor can opt in to see out-of-stock products, and they are then included in the listing."
     */
    public function guestCanOptInToSeeOutOfStockProductsAndTheyAreIncludedInListing(FunctionalTester $I): void
    {
        $this->enableStockManagementModule();

        $inStock = (new ProductFactory())
            ->withDetailAttributes(['stock_availability' => 'in-stock'])
            ->create(['post_title' => 'StockSpec2 InStock']);
        $outOfStock = (new ProductFactory())
            ->withDetailAttributes(['stock_availability' => 'out-of-stock'])
            ->create(['post_title' => 'StockSpec2 OOS']);

        $I->logout();

        // Baseline — the OOS product is hidden from the default listing so the
        // opt-in flip below is observable. Without this baseline an inactive
        // allow_out_of_stock filter would pass the opt-in assertion vacuously.
        [$defStatus, $defBody] = $I->get('/fluent-cart/v2/public/products');
        Assert::assertSame(
            200,
            $defStatus,
            'baseline default listing must respond 200. Body: ' . wp_json_encode($defBody)
        );
        $defIds = $this->extractProductIds($defBody);
        Assert::assertNotContains(
            (int) $outOfStock->ID,
            $defIds,
            'baseline — out-of-stock product must be hidden from the default listing so the opt-in inclusion below is a real flip'
        );

        // Opt-in — the same guest requests allow_out_of_stock=1 and the OOS
        // product must now be included in the listing.
        [$optStatus, $optBody] = $I->get('/fluent-cart/v2/public/products', [
            'allow_out_of_stock' => 1,
        ]);
        Assert::assertSame(
            200,
            $optStatus,
            'opt-in listing must respond 200. Body: ' . wp_json_encode($optBody)
        );
        $optIds = $this->extractProductIds($optBody);
        Assert::assertContains(
            (int) $outOfStock->ID,
            $optIds,
            'out-of-stock product must be included in the listing when the guest opts in via allow_out_of_stock=1'
        );
        Assert::assertContains(
            (int) $inStock->ID,
            $optIds,
            'in-stock product must still appear when the guest opts in (allow_out_of_stock is a superset of the default, not a swap)'
        );
    }
}
