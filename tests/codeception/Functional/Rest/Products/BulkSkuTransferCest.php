<?php

namespace Tests\Functional\Rest\Products;

use Codeception\Example;
use FluentCart\App\App;
use FluentCart\App\Models\ProductVariation;
use PHPUnit\Framework\Assert;
use Tests\Support\Doubles\FailingQueries;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;
use Tests\Support\Traits\BuildsProductUpdatePayloads;

class BulkSkuTransferCest
{
    use BuildsProductUpdatePayloads;

    public function _before(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
    }

    /** @dataProvider clearedSkuPayloads */
    public function adminCanTransferAClearedSkuToANewVariation(FunctionalTester $I, Example $example): void
    {
        $sku = isset($example['sku']) ? $example['sku'] : 'TRANSFER';
        $product = (new ProductFactory())->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([['sku' => $sku, 'item_price' => 1000]]);
        $payload = $this->buildBulkSkuTransferPayload($product, ' ' . $sku . ' ');
        $donorId = $payload['variants'][0]['id'];
        $payload['variants'][0]['sku'] = $example['cleared'];
        if ($example['recipientFirst']) {
            $payload['variants'] = array_reverse($payload['variants']);
        }

        [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', ['products' => [$payload]]);

        Assert::assertSame(200, $status, wp_json_encode($body));
        Assert::assertSame([(int) $product->ID], array_map('intval', $body['updated']), 'The transfer is reported saved.');
        $variants = ProductVariation::query()->where('post_id', $product->ID)->orderBy('id')->get();
        Assert::assertSame([null, $sku], $variants->pluck('sku')->toArray(), 'Only the new variation owns the transferred SKU.');
        Assert::assertSame($donorId, (int) $variants[0]->id, 'The donor keeps its identity.');
        Assert::assertSame([1500, 1500], array_map('intval', $variants->pluck('item_price')->toArray()), 'Both price edits persist.');
    }

    protected function clearedSkuPayloads(): array
    {
        return [
            ['cleared' => '', 'recipientFirst' => false],
            ['cleared' => null, 'recipientFirst' => true],
            ['cleared' => '   ', 'recipientFirst' => false],
            ['cleared' => "\t \n", 'recipientFirst' => true],
            ['cleared' => ' ', 'recipientFirst' => false, 'sku' => '0'],
        ];
    }

    public function failedBulkTransferRestoresTheDonorAndCanBeRetried(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([['sku' => 'TRANSFER', 'item_price' => 1000]]);
        $payload = $this->buildBulkSkuTransferPayload($product, 'TRANSFER');
        $failure = FailingQueries::matching('/^update `[^`]*fct_product_variations`.*CASE/is', 1);

        try {
            [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', ['products' => [$payload]]);
        } finally {
            $failure->remove();
        }

        Assert::assertSame(1, $failure->hits(), 'The existing variation write is reached after creating the recipient.');
        Assert::assertSame(422, $status, wp_json_encode($body));
        Assert::assertNotEmpty($body['errors'], 'The merchant can retry the failed product.');
        $variants = ProductVariation::query()->where('post_id', $product->ID)->get();
        Assert::assertSame(['TRANSFER'], $variants->pluck('sku')->toArray(), 'Rollback restores the donor and removes the new recipient.');
        Assert::assertSame(1000, (int) $variants[0]->item_price, 'The donor price is restored.');
        Assert::assertSame(0, App::db()->transactionLevel(), 'The failed save leaves no open transaction.');

        [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', ['products' => [$payload]]);

        Assert::assertSame(200, $status, wp_json_encode($body));
        Assert::assertSame([null, 'TRANSFER'], ProductVariation::query()->where('post_id', $product->ID)
            ->orderBy('id')->pluck('sku')->toArray(), 'Retry creates exactly one recipient and transfers the SKU.');
    }

    public function aNewVariationCannotTakeAnOmittedDonorSku(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([['sku' => 'TRANSFER', 'item_price' => 1000]]);
        $payload = $this->buildBulkSkuTransferPayload($product, 'TRANSFER');
        unset($payload['variants'][0]['sku']);

        [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', ['products' => [$payload]]);

        Assert::assertSame(422, $status, wp_json_encode($body));
        Assert::assertSame(['TRANSFER'], ProductVariation::query()->where('post_id', $product->ID)
            ->pluck('sku')->toArray(), 'An omitted SKU stays with its owner and no recipient is created.');
        Assert::assertSame(1000, (int) ProductVariation::query()->where('post_id', $product->ID)
            ->value('item_price'), 'The rejected save preserves the original price.');
    }

    public function aNegativeDonorIdCannotReleaseARealVariationsSku(FunctionalTester $I): void
    {
        $product = (new ProductFactory())->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([['sku' => 'TRANSFER', 'item_price' => 1000]]);
        $payload = $this->buildBulkSkuTransferPayload($product, 'TRANSFER');
        $payload['variants'][0]['id'] = -$payload['variants'][0]['id'];

        [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', ['products' => [$payload]]);

        Assert::assertSame(422, $status, wp_json_encode($body));
        Assert::assertSame(['TRANSFER'], ProductVariation::query()->where('post_id', $product->ID)
            ->pluck('sku')->toArray(), 'A negative ID cannot release the real donor or create a recipient.');
        Assert::assertSame(1000, (int) ProductVariation::query()->where('post_id', $product->ID)
            ->value('item_price'), 'The rejected save preserves the original price.');
    }

    public function aNewVariationCannotTakeAnotherProductsSku(FunctionalTester $I): void
    {
        $factory = (new ProductFactory())->withDetailAttributes(['variation_type' => 'simple_variations']);
        $product = $factory->createWithVariants([['sku' => 'OWN']]);
        $other = $factory->createWithVariants([['sku' => 'FOREIGN']]);
        $payload = $this->buildBulkSkuTransferPayload($product, 'FOREIGN');
        $foreignPayload = $this->buildUpdatePayload($other, [], [['sku' => '']]);
        $payload['variants'][] = $foreignPayload['variants'][0];

        [$status, $body] = $I->post('/fluent-cart/v2/products/bulk-update', ['products' => [$payload]]);

        Assert::assertSame(422, $status, wp_json_encode($body));
        Assert::assertSame(['OWN'], ProductVariation::query()->where('post_id', $product->ID)
            ->pluck('sku')->toArray(), 'The rejected save leaves the edited product unchanged.');
        Assert::assertSame('FOREIGN', ProductVariation::query()->where('post_id', $other->ID)
            ->value('sku'), 'A foreign variation cannot release its SKU through this product.');
    }
}
