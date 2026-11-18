<?php

namespace Tests\Integration\Services;

use FluentCart\App\Services\TermProductsService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * The term screens attach and detach products by sending only the changes,
 * so products the page never loaded keep their terms.
 */
class TermProductsServiceTest extends DatabaseTestCase
{
    private $termId;

    public function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->termId = (int) wp_insert_term('Term Products', 'product-categories')['term_id'];
    }

    private function product(string $status = 'publish'): int
    {
        return (int) (new ProductFactory())->create(['post_status' => $status])->ID;
    }

    private function termIdsOf(int $productId): array
    {
        return wp_get_object_terms($productId, 'product-categories', ['fields' => 'ids']);
    }

    public function test_adds_and_removes_only_the_sent_products(): void
    {
        $kept = $this->product();
        $removed = $this->product();
        $added = $this->product('draft');
        wp_set_object_terms($kept, [$this->termId], 'product-categories');
        wp_set_object_terms($removed, [$this->termId], 'product-categories');

        TermProductsService::sync($this->termId, 'product-categories', [$added], [$removed]);

        $this->assertSame([$this->termId], $this->termIdsOf($kept), 'a product not in either list is untouched');
        $this->assertSame([$this->termId], $this->termIdsOf($added), 'drafts can be attached');
        $this->assertSame([], $this->termIdsOf($removed));
    }

    public function test_adding_keeps_the_product_other_terms(): void
    {
        $product = $this->product();
        $other = (int) wp_insert_term('Other Term', 'product-categories')['term_id'];
        wp_set_object_terms($product, [$other], 'product-categories');

        TermProductsService::sync($this->termId, 'product-categories', [$product], []);

        $this->assertEqualsCanonicalizing([$other, $this->termId], $this->termIdsOf($product));
    }

    public function test_ignores_non_product_and_trashed_ids(): void
    {
        $post = self::factory()->post->create();
        $trashed = $this->product('trash');

        TermProductsService::sync($this->termId, 'product-categories', [$post, $trashed, 0, 999999], []);

        $this->assertSame([], wp_get_object_terms($post, 'product-categories', ['fields' => 'ids']));
        $this->assertSame([], $this->termIdsOf($trashed));
    }

    public function test_changes_nothing_without_the_edit_products_permission(): void
    {
        $product = $this->product();
        wp_set_current_user(self::factory()->user->create(['role' => 'subscriber']));

        TermProductsService::sync($this->termId, 'product-categories', [$product], []);

        $this->assertSame([], $this->termIdsOf($product));
    }

    public function test_lists_current_products_up_to_the_limit(): void
    {
        $first = $this->product();
        $second = $this->product('draft');
        wp_set_object_terms($first, [$this->termId], 'product-categories');
        wp_set_object_terms($second, [$this->termId], 'product-categories');

        $result = TermProductsService::getProducts($this->termId, 'product-categories', 1);

        $this->assertSame(2, $result['total']);
        $this->assertCount(1, $result['products']);
        $this->assertSame(['id', 'title'], array_keys($result['products'][0]));
    }
}
