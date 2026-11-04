<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Working with variations" section, items 86, 87, 88, 89, 96, 99.
 *
 * Action-named Cest covering POST /fluent-cart/v2/products/variants/{variantId}.
 * Variation-update permission denials live next to the happy-path methods,
 * mirroring CreateProductCest / UpdateProductCest convention.
 */
class UpdateProductVariationCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    private function buildVariantPayload(ProductVariation $variant, array $overrides = []): array
    {
        $payload = array_merge([
            'id'               => (int) $variant->id,
            'post_id'          => (int) $variant->post_id,
            'variation_title'  => (string) $variant->variation_title,
            'sku'              => 'SKU-' . wp_generate_password(6, false, false),
            'item_price'       => \FluentCart\App\Helpers\Helper::toCent(5),
            'compare_price'    => 0,
            'manage_cost'      => 'false',
            'fulfillment_type' => (string) ($variant->fulfillment_type ?: 'physical'),
            'manage_stock'     => 0,
            'stock_status'     => 'in-stock',
            'total_stock'      => 0,
            'available'        => 0,
            'committed'        => 0,
            'on_hold'          => 0,
            'serial_index'     => (int) $variant->serial_index,
            'other_info'       => ['payment_type' => 'onetime'],
        ], $overrides);

        return ['variants' => $payload];
    }

    /**
     * Spec: Cases/Functional/products.md [86]
     *   "Admin can rename a variation."
     */
    public function adminCanRenameVariation(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        // Two-variant fixture so we can prove the rename targets the variant
        // identified by the route param and does not flip every row of the product.
        $product = (new ProductFactory())->createWithVariants(
            [
                ['variation_title' => 'OldName86'],
                ['variation_title' => 'Untouched86'],
            ],
            ['post_title' => 'Rename86']
        );
        $target = $product->variants->firstWhere('variation_title', 'OldName86');
        $sibling = $product->variants->firstWhere('variation_title', 'Untouched86');
        Assert::assertNotNull($target, 'fixture must seed the rename target so the test exercises the named variant');
        Assert::assertNotNull($sibling, 'fixture must seed an untouched sibling variant so the test can prove the rename is targeted');

        $payload = $this->buildVariantPayload($target, ['variation_title' => 'NewName86']);

        [$status, $body] = $I->post('/fluent-cart/v2/products/variants/' . $target->id, $payload);

        $I->seeResponseCodeIs(200);
        $refreshed = ProductVariation::query()->find($target->id);
        Assert::assertSame('NewName86', (string) $refreshed->variation_title, 'fct_product_variations row matching the route variantId must persist the new title after rename');
        $siblingAfter = ProductVariation::query()->find($sibling->id);
        Assert::assertSame('Untouched86', (string) $siblingAfter->variation_title, 'rename must be targeted: the sibling variant on the same product must remain untouched');
    }

    /**
     * Spec: Cases/Functional/products.md [87]
     *   "Admin can change a variation's price."
     */
    public function adminCanChangeVariationPrice(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->createWithVariants(
            [
                ['variation_title' => 'TargetPrice87',  'item_price' => \FluentCart\App\Helpers\Helper::toCent(10)],
                ['variation_title' => 'SiblingPrice87', 'item_price' => \FluentCart\App\Helpers\Helper::toCent(40)],
            ],
            ['post_title' => 'Price87']
        );
        $target = $product->variants->firstWhere('variation_title', 'TargetPrice87');
        $sibling = $product->variants->firstWhere('variation_title', 'SiblingPrice87');
        Assert::assertNotNull($target, 'fixture must seed the price-change target');
        Assert::assertSame(1000, (int) $target->item_price, 'fixture must seed item_price=1000c on target so the price change is observable');
        Assert::assertSame(4000, (int) $sibling->item_price, 'fixture must seed item_price=4000c on sibling so the test can prove the price change is targeted');

        $payload = $this->buildVariantPayload($target, ['item_price' => \FluentCart\App\Helpers\Helper::toCent(25.50)]);

        [$status, $body] = $I->post('/fluent-cart/v2/products/variants/' . $target->id, $payload);

        $I->seeResponseCodeIs(200);
        $refreshed = ProductVariation::query()->find($target->id);
        Assert::assertSame(2550, (int) $refreshed->item_price, 'fct_product_variations.item_price for the route-targeted variant must persist the new price as cents (2550 for $25.50)');
        $siblingAfter = ProductVariation::query()->find($sibling->id);
        Assert::assertSame(4000, (int) $siblingAfter->item_price, 'price update must be targeted: sibling variant on the same product must keep its original 4000c price');
    }

    /**
     * Spec: Cases/Functional/products.md [88]
     *   "Admin sees a variation's price stored consistently in cents."
     */
    public function variationPriceStoredConsistentlyInCents(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->createWithVariants(
            [
                ['variation_title' => 'TargetCents88',  'item_price' => \FluentCart\App\Helpers\Helper::toCent(1)],
                ['variation_title' => 'SiblingCents88', 'item_price' => \FluentCart\App\Helpers\Helper::toCent(99)],
            ],
            ['post_title' => 'Cents88']
        );
        $target = $product->variants->firstWhere('variation_title', 'TargetCents88');
        $sibling = $product->variants->firstWhere('variation_title', 'SiblingCents88');
        Assert::assertNotNull($target, 'fixture must seed the cents round-trip target');

        $payload = $this->buildVariantPayload($target, ['item_price' => \FluentCart\App\Helpers\Helper::toCent(12.34)]);

        [$status, $body] = $I->post('/fluent-cart/v2/products/variants/' . $target->id, $payload);

        $I->seeResponseCodeIs(200);
        $refreshed = ProductVariation::query()->find($target->id);
        Assert::assertSame(1234, (int) $refreshed->item_price, '$12.34 must round-trip to 1234 cents in the DB on the route-targeted variant');
        $siblingAfter = ProductVariation::query()->find($sibling->id);
        Assert::assertSame(9900, (int) $siblingAfter->item_price, 'cents round-trip must be targeted: sibling variant must retain 9900c (proves the update is not a global price overwrite)');
    }

    /**
     * Spec: Cases/Functional/products.md [89]
     *   "Admin can change a variation's payment type to subscription."
     */
    public function adminCanChangeVariationPaymentTypeToSubscription(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->createWithVariants(
            [
                ['variation_title' => 'TargetSub89',  'payment_type' => 'onetime', 'other_info' => ['payment_type' => 'onetime']],
                ['variation_title' => 'SiblingSub89', 'payment_type' => 'onetime', 'other_info' => ['payment_type' => 'onetime']],
            ],
            ['post_title' => 'Sub89']
        );
        $target = $product->variants->firstWhere('variation_title', 'TargetSub89');
        $sibling = $product->variants->firstWhere('variation_title', 'SiblingSub89');
        Assert::assertNotNull($target, 'fixture must seed the subscription-flip target');
        Assert::assertSame('onetime', (string) $target->payment_type, 'fixture must start target as onetime so the flip to subscription is observable');
        Assert::assertSame('onetime', (string) $sibling->payment_type, 'fixture must start sibling as onetime so the test can prove the flip is targeted');

        $payload = $this->buildVariantPayload($target, [
            'other_info' => [
                'payment_type'     => 'subscription',
                'repeat_interval'  => 'monthly',
                'times'            => 0,
                'trial_days'       => 0,
                'manage_setup_fee' => 'no',
                'billing_summary'  => '',
            ],
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/products/variants/' . $target->id, $payload);

        $I->seeResponseCodeIs(200);
        $refreshed = ProductVariation::query()->find($target->id);
        Assert::assertSame('subscription', (string) $refreshed->payment_type, 'fct_product_variations.payment_type for the route-targeted variant must persist the flip to subscription');
        $other = is_array($refreshed->other_info) ? $refreshed->other_info : [];
        Assert::assertSame('subscription', (string) ($other['payment_type'] ?? ''), 'other_info.payment_type must mirror the column-level subscription flip');
        Assert::assertSame('monthly', (string) ($other['repeat_interval'] ?? ''), 'other_info.repeat_interval must persist the submitted billing cadence');
        $siblingAfter = ProductVariation::query()->find($sibling->id);
        Assert::assertSame('onetime', (string) $siblingAfter->payment_type, 'subscription flip must be targeted: sibling variant must remain onetime');
    }

    /**
     * Spec: Cases/Functional/products.md [96]
     *   "A worker without the "edit products" permission cannot update a variation."
     */
    public function workerWithoutEditPermissionCannotUpdateVariation(FunctionalTester $I): void
    {
        $product = (new ProductFactory())
            ->withVariantAttributes(['variation_title' => 'WorkerPre96', 'item_price' => \FluentCart\App\Helpers\Helper::toCent(7)])
            ->create(['post_title' => 'WorkerVar96']);
        $variant = $product->variants->first();
        $I->loginAsWorker();

        [$status, $body] = $I->post(
            '/fluent-cart/v2/products/variants/' . $variant->id,
            $this->buildVariantPayload($variant, ['variation_title' => 'WorkerAttempt96', 'item_price' => \FluentCart\App\Helpers\Helper::toCent(99)])
        );

        Assert::assertSame(403, $status, 'worker without products/edit must be rejected with 403 from POST /products/variants/{id}');
        Assert::assertSame('rest_forbidden', (string) ($body['code'] ?? ''), 'WP REST permission_callback denial must surface the rest_forbidden code on the body');
        Assert::assertNotEmpty($body['message'] ?? '', 'rejected variant update must include a permission-denied message');
        $refreshed = ProductVariation::query()->find($variant->id);
        Assert::assertSame('WorkerPre96', (string) $refreshed->variation_title, 'rejected update must not mutate the variation_title');
        Assert::assertSame(700, (int) $refreshed->item_price, 'rejected update must not mutate the item_price');
    }

    /**
     * Spec: Cases/Functional/products.md [99]
     *   "An accountant cannot update a variation."
     */
    public function accountantCannotUpdateVariation(FunctionalTester $I): void
    {
        $product = (new ProductFactory())
            ->withVariantAttributes(['variation_title' => 'AcctPre99', 'item_price' => \FluentCart\App\Helpers\Helper::toCent(7)])
            ->create(['post_title' => 'AcctVar99']);
        $variant = $product->variants->first();
        $I->loginAsAccountant();

        [$status, $body] = $I->post(
            '/fluent-cart/v2/products/variants/' . $variant->id,
            $this->buildVariantPayload($variant, ['variation_title' => 'AcctAttempt99', 'item_price' => \FluentCart\App\Helpers\Helper::toCent(99)])
        );

        Assert::assertSame(403, $status, 'accountant must be rejected with 403 from POST /products/variants/{id}');
        Assert::assertSame('rest_forbidden', (string) ($body['code'] ?? ''), 'WP REST permission_callback denial must surface the rest_forbidden code on the body');
        Assert::assertNotEmpty($body['message'] ?? '', 'rejected variant update must include a permission-denied message');
        $refreshed = ProductVariation::query()->find($variant->id);
        Assert::assertSame('AcctPre99', (string) $refreshed->variation_title, 'rejected update must not mutate the variation_title');
        Assert::assertSame(700, (int) $refreshed->item_price, 'rejected update must not mutate the item_price');
    }
}
