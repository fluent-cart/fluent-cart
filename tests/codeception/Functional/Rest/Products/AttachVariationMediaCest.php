<?php

namespace Tests\Functional\Rest\Products;

use FluentCart\App\Models\ProductMeta;
use PHPUnit\Framework\Assert;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\FunctionalTester;

/**
 * Spec: Cases/Functional/products.md → "Working with variations" section, items 90, 91.
 *
 * Action-named Cest covering POST /fluent-cart/v2/products/variants/{variantId}/setMedia.
 * The route stores the gallery as a single fct_meta row (object_type=product_variant_info,
 * meta_key=product_thumbnail) keyed on the variation id; subsequent calls update the
 * existing row in place rather than appending a new one — the spec calls this "replace".
 */
class AttachVariationMediaCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->resetFluentCartTables();
        $I->purgeFluentProductPosts();
    }

    private function findThumbnailRow(int $variantId): ?ProductMeta
    {
        return ProductMeta::query()
            ->where('object_id', $variantId)
            ->where('object_type', 'product_variant_info')
            ->where('meta_key', 'product_thumbnail')
            ->first();
    }

    /**
     * Spec: Cases/Functional/products.md [90]
     *   "Admin can attach images to a variation."
     */
    public function adminCanAttachImagesToVariation(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'Media90']);
        $variant = $product->variants->first();
        Assert::assertNull($this->findThumbnailRow((int) $variant->id), 'fixture must start without a stored thumbnail row so the create branch is exercised');

        [$status, $body] = $I->post('/fluent-cart/v2/products/variants/' . $variant->id . '/setMedia', [
            'media' => [
                ['id' => 1001, 'url' => 'https://example.test/img-90-a.jpg', 'title' => 'Image A'],
                ['id' => 1002, 'url' => 'https://example.test/img-90-b.jpg', 'title' => 'Image B'],
            ],
        ]);

        $I->seeResponseCodeIs(200);
        $row = $this->findThumbnailRow((int) $variant->id);
        Assert::assertNotNull($row, 'POST /setMedia must persist a fct_meta row for the variant on first attach');
        $value = is_array($row->meta_value) ? $row->meta_value : json_decode((string) $row->meta_value, true);
        Assert::assertIsArray($value, 'persisted meta_value must be an array of media descriptors');
        Assert::assertCount(2, $value, 'persisted meta_value must contain exactly the two submitted media descriptors');
        Assert::assertSame(1001, (int) ($value[0]['id'] ?? 0), 'first persisted media id must match the first submitted id');
        Assert::assertSame('https://example.test/img-90-a.jpg', (string) ($value[0]['url'] ?? ''), 'first persisted media url must match the first submitted url');
        Assert::assertSame('Image B', (string) ($value[1]['title'] ?? ''), 'second persisted media title must match the second submitted title');
    }

    /**
     * Spec: Cases/Functional/products.md [91]
     *   "Admin sees a variation's previous gallery replaced when new images are attached."
     */
    public function adminSeesPreviousVariationGalleryReplacedWhenNewImagesAttached(FunctionalTester $I): void
    {
        $I->loginAsSuperAdmin();
        $product = (new ProductFactory())->create(['post_title' => 'Replace91']);
        $variant = $product->variants->first();

        [$initialStatus] = $I->post('/fluent-cart/v2/products/variants/' . $variant->id . '/setMedia', [
            'media' => [
                ['id' => 2001, 'url' => 'https://example.test/img-91-old-a.jpg', 'title' => 'Old A'],
                ['id' => 2002, 'url' => 'https://example.test/img-91-old-b.jpg', 'title' => 'Old B'],
                ['id' => 2003, 'url' => 'https://example.test/img-91-old-c.jpg', 'title' => 'Old C'],
            ],
        ]);
        Assert::assertSame(200, $initialStatus, 'initial setMedia must succeed so the replace path can be exercised');
        $rowBefore = $this->findThumbnailRow((int) $variant->id);
        Assert::assertNotNull($rowBefore, 'first setMedia must persist a fct_meta row used as the replace target');
        $beforeId = (int) $rowBefore->id;

        [$status] = $I->post('/fluent-cart/v2/products/variants/' . $variant->id . '/setMedia', [
            'media' => [
                ['id' => 3001, 'url' => 'https://example.test/img-91-new.jpg', 'title' => 'Brand New'],
            ],
        ]);

        $I->seeResponseCodeIs(200);
        $rowAfter = $this->findThumbnailRow((int) $variant->id);
        Assert::assertNotNull($rowAfter, 'replace call must keep a fct_meta row for the variant');
        Assert::assertSame($beforeId, (int) $rowAfter->id, 'replace must update the existing fct_meta row in place (proves not append-then-insert)');
        $value = is_array($rowAfter->meta_value) ? $rowAfter->meta_value : json_decode((string) $rowAfter->meta_value, true);
        Assert::assertIsArray($value, 'persisted meta_value must remain an array after replace');
        Assert::assertCount(1, $value, 'replace must store only the new gallery entry, not append to the previous three');
        Assert::assertSame(3001, (int) ($value[0]['id'] ?? 0), 'persisted media id must equal the new submitted id, proving the previous gallery was discarded');
        Assert::assertSame('https://example.test/img-91-new.jpg', (string) ($value[0]['url'] ?? ''), 'persisted url must match the new submitted url');
        $extraRows = ProductMeta::query()
            ->where('object_id', $variant->id)
            ->where('object_type', 'product_variant_info')
            ->where('meta_key', 'product_thumbnail')
            ->count();
        Assert::assertSame(1, (int) $extraRows, 'replace must not insert a second fct_meta row for the same variant + meta_key');
    }
}
