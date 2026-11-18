<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\App;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Doubles\FailingQueries;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsProductUpdatePayloads;

class ProductSaveSafetyCest
{
    use BuildsProductUpdatePayloads;

    public function _before(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
    }

    public function adminCanClearWhitespaceSkusWhileChangingPrices(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([['sku' => 'FIRST'], ['sku' => 'SECOND']]);
        $payload = $this->buildUpdatePayload($product, [], [
            ['sku' => '   ', 'item_price' => 1500],
            ['sku' => "\t ", 'item_price' => 2500],
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);

        Assert::assertSame(200, $status, wp_json_encode($body));
        $variants = ProductVariation::query()->where('post_id', $product->ID)->orderBy('id')->get();
        Assert::assertSame([null, null], $variants->pluck('sku')->toArray(), 'Both blank SKUs are cleared.');
        Assert::assertSame([1500, 2500], array_map('intval', $variants->pluck('item_price')->toArray()), 'The price edits are saved.');
    }

    public function adminCanMoveAClearedSkuToAnEarlierVariation(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([['sku' => 'RECIPIENT'], ['sku' => 'TRANSFER']]);
        $payload = $this->buildUpdatePayload($product, [], [
            ['sku' => 'TRANSFER', 'item_price' => 1500],
            ['sku' => '', 'item_price' => 2500],
        ]);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);

        Assert::assertSame(200, $status, wp_json_encode($body));
        Assert::assertSame(['TRANSFER', null], ProductVariation::query()->where('post_id', $product->ID)
            ->orderBy('id')->pluck('sku')->toArray(), 'The SKU moves to the earlier variation in one save.');
    }

    public function adminCannotTakeAnUnchangedSiblingSku(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([['sku' => 'RECIPIENT'], ['sku' => 'TAKEN']]);
        $payload = $this->buildUpdatePayload($product, [], [['sku' => 'TAKEN'], []]);

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);

        Assert::assertSame(422, $status, wp_json_encode($body));
        Assert::assertSame(['RECIPIENT', 'TAKEN'], ProductVariation::query()->where('post_id', $product->ID)
            ->orderBy('id')->pluck('sku')->toArray(), 'An omitted SKU is unchanged and still belongs to its owner.');
    }

    public function failedSkuTransferRestoresBothSkus(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([['sku' => 'RECIPIENT'], ['sku' => 'TRANSFER']]);
        $payload = $this->buildUpdatePayload($product, [], [['sku' => 'TRANSFER'], ['sku' => '']]);
        $failure = FailingQueries::matching('/^update `[^`]*fct_product_variations`.*CASE/is', 1);
        $announcements = 0;
        $listener = function () use (&$announcements) {
            $announcements++;
        };
        add_action('fluent_cart/product/variants_updated', $listener, 10, 1);

        try {
            [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);
        } finally {
            $failure->remove();
            remove_action('fluent_cart/product/variants_updated', $listener, 10);
        }

        Assert::assertSame(1, $failure->hits(), 'The assignment fails after the donor SKU is cleared.');
        Assert::assertSame(500, $status, wp_json_encode($body));
        Assert::assertSame(['RECIPIENT', 'TRANSFER'], ProductVariation::query()->where('post_id', $product->ID)
            ->orderBy('id')->pluck('sku')->toArray(), 'Rollback restores the released SKU and its recipient.');
        Assert::assertSame(0, $announcements, 'A failed transfer does not announce updated variations.');
    }

    public function anotherProductsSkuCannotBeReleasedInThisSave(FunctionalTester $I): void
    {
        $factory = (new ProductFactory())->withDetailAttributes(['variation_type' => 'simple_variations']);
        $product = $factory->createWithVariants([['sku' => 'RECIPIENT']]);
        $other = $factory->createWithVariants([['sku' => 'FOREIGN']]);
        $payload = $this->buildUpdatePayload($product, [], [['sku' => 'FOREIGN']]);
        $foreignPayload = $this->buildUpdatePayload($other, [], [['sku' => '']]);
        $payload['variants'][] = $foreignPayload['variants'][0];

        [$status, $body] = $I->post('/fluent-cart/v2/products/' . $product->ID . '/pricing', $payload);

        Assert::assertSame(422, $status, wp_json_encode($body));
        Assert::assertSame('FOREIGN', ProductVariation::query()->where('post_id', $other->ID)->value('sku'), 'A foreign owner cannot release its SKU through this product.');
        Assert::assertSame('RECIPIENT', ProductVariation::query()->where('post_id', $product->ID)->value('sku'), 'The rejected recipient is unchanged.');
    }

    public function failedBulkEditRollsBackNewVariationsAndCanBeRetried(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([['sku' => 'ORIGINAL', 'item_price' => 1000]], ['post_title' => 'Original product']);
        $payload = $this->buildUpdatePayload($product, ['post_title' => 'Updated product'], [
            ['sku' => 'ORIGINAL', 'item_price' => 1500],
        ]);
        $copy = $payload['variants'][0];
        unset($copy['id'], $copy['rowId']);
        $copy['sku'] = '';
        $copy['variation_title'] = 'New copy';
        $payload['variants'][] = $copy;
        $failure = FailingQueries::matching('/^update `[^`]*fct_product_variations`/i', 1);

        try {
            [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', ['products' => [$payload]]);
        } finally {
            $failure->remove();
        }

        Assert::assertSame(1, $failure->hits(), 'The existing variation update fails.');
        Assert::assertSame(422, $status, wp_json_encode($body));
        Assert::assertNotEmpty($body['errors'], 'The failed product is reported to the merchant.');
        Assert::assertSame(1, ProductVariation::query()->where('post_id', $product->ID)->count(), 'The failed save leaves no new variation.');
        Assert::assertSame(1000, (int) ProductVariation::query()->where('post_id', $product->ID)->value('item_price'), 'The old price is preserved.');
        Assert::assertSame('Original product', get_post($product->ID)->post_title, 'The old title is preserved.');

        [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', ['products' => [$payload]]);

        Assert::assertSame(200, $status, wp_json_encode($body));
        Assert::assertContains((int) $product->ID, array_map('intval', $body['updated']), 'The retry succeeds.');
        Assert::assertSame(2, ProductVariation::query()->where('post_id', $product->ID)->count(), 'Retrying creates exactly one copy.');
        Assert::assertSame('Updated product', get_post($product->ID)->post_title, 'The retry saves the title.');
    }

    public function failedProductDoesNotDiscardSuccessfulBulkEdits(FunctionalTester $I): void
    {
        $factory = (new ProductFactory())->withDetailAttributes(['variation_type' => 'simple_variations']);
        $products = [];
        $payloads = [];
        for ($index = 0; $index < 3; $index++) {
            $product = $factory->createWithVariants([['sku' => 'BULK-' . $index, 'item_price' => 1000]]);
            $products[] = $product;
            $payloads[] = $this->buildUpdatePayload($product, [], [['sku' => 'BULK-' . $index, 'item_price' => 2000]]);
        }
        $copy = $payloads[1]['variants'][0];
        unset($copy['id'], $copy['rowId']);
        $copy['sku'] = '';
        $payloads[1]['variants'][] = $copy;
        $variantId = $payloads[1]['variants'][0]['id'];
        $failure = FailingQueries::matching('/^update `[^`]*fct_product_variations`.*WHERE `id` IN\(\x27' . $variantId . '\x27\)/is', 1);
        $transactionLevel = App::db()->transactionLevel();

        try {
            [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', ['products' => $payloads]);
        } finally {
            $failure->remove();
        }

        Assert::assertSame(1, $failure->hits(), 'Only the middle product update fails.');
        Assert::assertSame(200, $status, wp_json_encode($body));
        Assert::assertSame([(int) $products[0]->ID, (int) $products[2]->ID], array_map('intval', $body['updated']), 'Both neighboring products succeed.');
        Assert::assertSame((int) $products[1]->ID, (int) $body['errors'][0]['post_id'], 'The middle product is reported as failed.');
        foreach ($products as $index => $product) {
            Assert::assertSame(1, ProductVariation::query()->where('post_id', $product->ID)->count(), 'No failed duplicate is left behind.');
            Assert::assertSame($index === 1 ? 1000 : 2000, (int) ProductVariation::query()->where('post_id', $product->ID)->value('item_price'), 'Only successful product prices change.');
        }
        Assert::assertSame($transactionLevel, App::db()->transactionLevel(), 'The chunk closes its transactions.');
    }

    public function transactionWideFailureKeepsOnlyCommittedProductsInUpdated(FunctionalTester $I): void
    {
        $factory = (new ProductFactory())->withDetailAttributes(['variation_type' => 'simple_variations']);
        $products = [];
        $payloads = [];
        for ($index = 0; $index < 4; $index++) {
            $product = $factory->createWithVariants([['sku' => 'ROLLBACK-' . $index, 'item_price' => 1000]]);
            $products[] = $product;
            $payload = $this->buildUpdatePayload($product, [], [['sku' => 'ROLLBACK-' . $index, 'item_price' => 2000]]);
            if ($index === 1 || $index === 2) {
                $copy = $payload['variants'][0];
                unset($copy['id'], $copy['rowId']);
                $copy['sku'] = '';
                $payload['variants'][] = $copy;
            }
            $payloads[] = $payload;
        }

        $rolledBack = false;
        $frameworkDepthAfterRollback = 0;
        $rollbackProduct = function ($variant, $postId) use ($products, &$rolledBack, &$frameworkDepthAfterRollback) {
            if ((int) $postId === (int) $products[1]->ID && !$rolledBack) {
                global $wpdb;
                // Like a deadlock, the server rolls back without updating framework bookkeeping.
                $rolledBack = $wpdb->dbh->rollback();
                $frameworkDepthAfterRollback = App::db()->transactionLevel();
                throw new \RuntimeException('Transaction-wide rollback');
            }
            return $variant;
        };
        add_filter('fluent_cart/product/variant_save_data', $rollbackProduct, 10, 2);
        $variantId = $payloads[2]['variants'][0]['id'];
        $failure = FailingQueries::matching('/^update `[^`]*fct_product_variations`.*WHERE `id` IN\(\x27' . $variantId . '\x27\)/is', 1);

        try {
            [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', ['products' => $payloads]);
        } finally {
            remove_filter('fluent_cart/product/variant_save_data', $rollbackProduct, 10);
            $failure->remove();
        }

        Assert::assertTrue($rolledBack, 'The second product loses its entire transaction.');
        Assert::assertGreaterThan(0, $frameworkDepthAfterRollback, 'The framework still believes its transaction is open when the server rolls back.');
        Assert::assertSame(1, $failure->hits(), 'The third product also fails after creating a variation.');
        Assert::assertSame(200, $status, wp_json_encode($body));
        Assert::assertSame([(int) $products[0]->ID, (int) $products[3]->ID], array_map('intval', $body['updated']), 'Only the first and last products are reported saved.');
        Assert::assertSame([(int) $products[1]->ID, (int) $products[2]->ID], array_map('intval', array_column($body['errors'], 'post_id')), 'Both failed products remain retryable.');
        foreach ($products as $index => $product) {
            Assert::assertSame(1, ProductVariation::query()->where('post_id', $product->ID)->count(), 'Neither failed product leaves a new variation behind.');
            $expectedPrice = ($index === 0 || $index === 3) ? 2000 : 1000;
            Assert::assertSame($expectedPrice, (int) ProductVariation::query()->where('post_id', $product->ID)->value('item_price'), 'The response agrees with the persisted price of every product.');
        }
        Assert::assertSame(0, App::db()->transactionLevel(), 'No transaction is left open.');
    }
}
