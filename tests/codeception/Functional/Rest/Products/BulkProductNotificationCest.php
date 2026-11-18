<?php

namespace Tests\Functional\Rest\Products;

use Codeception\Example;
use FluentCart\App\App;
use FluentCart\App\Models\Activity;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsProductUpdatePayloads;

class BulkProductNotificationCest
{
    use BuildsProductUpdatePayloads;

    public function _before(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
    }

    public function successfulBulkSaveNotifiesOnlyAfterAllWritesCommit(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([['item_price' => 1000]]);
        $payload = $this->buildUpdatePayload($product, ['categories' => ['Notification category']], [['item_price' => 2000]]);
        $observations = [];
        $listener = function () use ($product, &$observations) {
            $observations[] = [
                'hook' => current_filter(),
                'depth' => App::db()->transactionLevel(),
                'price' => (int) ProductVariation::query()->where('post_id', $product->ID)->value('item_price'),
                'categories' => wp_get_post_terms($product->ID, 'product-categories', ['fields' => 'names']),
            ];
        };
        add_action('fluent_cart/product/variants_updated', $listener, 10, 1);
        add_action('fluent_cart/product_stock_changed', $listener, 10, 1);
        try {
            [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', ['products' => [$payload]]);
        } finally {
            remove_action('fluent_cart/product/variants_updated', $listener, 10);
            remove_action('fluent_cart/product_stock_changed', $listener, 10);
        }

        Assert::assertSame(200, $status, wp_json_encode($body));
        Assert::assertSame([
            ['hook' => 'fluent_cart/product/variants_updated', 'depth' => 0, 'price' => 2000, 'categories' => ['Notification category']],
            ['hook' => 'fluent_cart/product_stock_changed', 'depth' => 0, 'price' => 2000, 'categories' => ['Notification category']],
        ], $observations, 'Both notifications observe the committed price and completed category update exactly once.');
    }

    public function lateFailureDiscardsNotificationsBeforeRetry(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([['sku' => 'TRANSFER', 'item_price' => 1000]]);
        $payload = $this->buildBulkSkuTransferPayload($product, 'TRANSFER');
        $payload['categories'] = ['Rejected category'];
        $notifications = [];
        $listener = function () use (&$notifications) {
            $notifications[] = current_filter();
        };
        $failCategory = function ($term) {
            if ($term === 'Rejected category') {
                throw new \RuntimeException('Category write refused');
            }
            return $term;
        };
        add_action('fluent_cart/product/variants_updated', $listener, 10, 1);
        add_action('fluent_cart/product_stock_changed', $listener, 10, 1);
        add_filter('pre_insert_term', $failCategory, 10, 1);
        try {
            [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', ['products' => [$payload]]);
            Assert::assertSame(422, $status, wp_json_encode($body));
            Assert::assertSame([], $notifications, 'A later category failure emits no notification.');
            Assert::assertSame(['TRANSFER'], ProductVariation::query()->where('post_id', $product->ID)
                ->pluck('sku')->toArray(), 'Rollback restores the donor and removes the new variation.');
            Assert::assertSame(1000, (int) ProductVariation::query()->where('post_id', $product->ID)
                ->value('item_price'), 'The failed save preserves the original price.');

            unset($payload['categories']);
            [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', ['products' => [$payload]]);
            Assert::assertSame(200, $status, wp_json_encode($body));
            Assert::assertSame(['fluent_cart/product/variants_updated', 'fluent_cart/product_stock_changed'], $notifications,
                'Retry emits only its own notifications; rolled-back callbacks do not leak.');
            Assert::assertSame([null, 'TRANSFER'], ProductVariation::query()->where('post_id', $product->ID)
                ->orderBy('id')->pluck('sku')->toArray(), 'Retry creates exactly one recipient.');
        } finally {
            remove_action('fluent_cart/product/variants_updated', $listener, 10);
            remove_action('fluent_cart/product_stock_changed', $listener, 10);
            remove_filter('pre_insert_term', $failCategory, 10);
        }
    }

    /** @dataProvider notificationHooks */
    public function aThrowingListenerDoesNotReportACommittedProductAsFailed(FunctionalTester $I, Example $example): void
    {
        $product = (new ProductFactory())->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([['item_price' => 1000]]);
        $payload = $this->buildUpdatePayload($product, [], [['item_price' => 2000]]);
        $notifications = [];
        $listener = function () use ($example, &$notifications) {
            $notifications[] = current_filter();
            if (current_filter() === $example['hook']) {
                throw new \RuntimeException('Product listener failed');
            }
        };
        $loggingAttempts = 0;
        $failLog = function ($models) use ($example, &$loggingAttempts) {
            $loggingAttempts++;
            if ($example['failLog']) {
                throw new \RuntimeException('Notification log unavailable');
            }
            return $models;
        };
        add_action('fluent_cart/product/variants_updated', $listener, 10, 1);
        add_action('fluent_cart/product_stock_changed', $listener, 10, 1);
        add_filter('fluent_cart/logs/allowed_models', $failLog, 10, 1);
        try {
            [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', ['products' => [$payload]]);
        } finally {
            remove_action('fluent_cart/product/variants_updated', $listener, 10);
            remove_action('fluent_cart/product_stock_changed', $listener, 10);
            remove_filter('fluent_cart/logs/allowed_models', $failLog, 10);
        }

        Assert::assertSame(200, $status, wp_json_encode($body));
        Assert::assertSame([(int) $product->ID], array_map('intval', $body['updated']), 'The committed product remains reported saved.');
        Assert::assertSame([], $body['errors'], 'A notification error does not invite retrying a committed save.');
        Assert::assertSame(2000, (int) ProductVariation::query()->where('post_id', $product->ID)
            ->value('item_price'), 'The saved price remains committed.');
        Assert::assertSame(['fluent_cart/product/variants_updated', 'fluent_cart/product_stock_changed'], $notifications,
            'Failure in one notification does not discard the other notification.');
        Assert::assertSame(1, $loggingAttempts, 'Logging is attempted even when its storage fails.');
        Assert::assertSame($example['failLog'] ? 0 : 1, Activity::query()->where('module_id', $product->ID)->where('module_name', 'product')
            ->where('status', 'error')->where('content', 'Product listener failed')->count(), 'The notification failure is logged.');
        Assert::assertSame(0, App::db()->transactionLevel(), 'No transaction remains open.');
    }

    protected function notificationHooks(): array
    {
        return [
            ['hook' => 'fluent_cart/product/variants_updated', 'failLog' => false],
            ['hook' => 'fluent_cart/product_stock_changed', 'failLog' => false],
            ['hook' => 'fluent_cart/product/variants_updated', 'failLog' => true],
            ['hook' => 'fluent_cart/product_stock_changed', 'failLog' => true],
        ];
    }
}
