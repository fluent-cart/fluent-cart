<?php

namespace Tests\Integration\Helpers;

use FluentCart\App\Helpers\Helper;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * Helper::loadBundleChild() combines every parent variation's
 * other_info.bundle_child_ids into one flat, deduplicated list, then must
 * remove parent ids from that list (a parent should never resolve itself as
 * its own child). It removed them with Arr::except(), which deletes by
 * ARRAY KEY, not by value. array_unique()/Arr::flatten() leave the flat list
 * numerically keyed from 0..n-1, so whenever a parent variation id happens to
 * equal one of those positional keys (0, 1, 2, ...), Arr::except() deletes
 * the child sitting at that position instead of the parent id — silently
 * dropping an unrelated bundle child from child_variants, and therefore from
 * the cart/checkout render.
 */
class HelperLoadBundleChildTest extends DatabaseTestCase
{
    private ProductFactory $productFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->productFactory = new ProductFactory();
    }

    private function makeChildVariant(string $title): int
    {
        $product = $this->productFactory
            ->withVariantAttributes(['variation_title' => $title])
            ->create(['post_title' => $title . ' Product']);

        return (int) $product->variants->first()->id;
    }

    private function parentVariant(int $id, array $childIds): array
    {
        return [
            'id'         => $id,
            'other_info' => ['bundle_child_ids' => $childIds],
        ];
    }

    /**
     * Case 2: parent id collides with the flattened list's array key (not a
     * value). Index 1 holds the second child, so a parent id of 1 must not
     * delete it. A filler variant is created first so the real child ids are
     * guaranteed to be > 1 — proving this is a pure key collision, not a
     * coincidental self-reference (parent id equal to a real child id).
     */
    public function test_child_at_colliding_array_key_is_not_dropped(): void
    {
        $this->makeChildVariant('BundleChildKeyFiller');
        $childA = $this->makeChildVariant('BundleChildKey0');
        $childB = $this->makeChildVariant('BundleChildKey1');
        $this->assertGreaterThan(1, $childA, 'fixture must guarantee real child ids are not 1, so the parent id below only collides with the array key');

        // Flattened list is [0 => $childA, 1 => $childB]; parent id 1 collides with childB's key.
        $variants = [$this->parentVariant(1, [$childA, $childB])];

        $result = Helper::loadBundleChild($variants, ['id', 'variation_title']);

        $childVariants = $result[0]['child_variants'] ?? [];
        $this->assertArrayHasKey($childA, $childVariants, 'childA (index 0) must be present');
        $this->assertArrayHasKey($childB, $childVariants, 'childB (index 1) must survive even though the parent id equals its array index');
    }

    /**
     * Case 2 (index 0 variant): parent id collides with key 0.
     */
    public function test_child_at_key_zero_is_not_dropped(): void
    {
        $childA = $this->makeChildVariant('BundleChildKeyZeroA');
        $childB = $this->makeChildVariant('BundleChildKeyZeroB');

        $variants = [$this->parentVariant(0, [$childA, $childB])];

        $result = Helper::loadBundleChild($variants, ['id', 'variation_title']);

        $childVariants = $result[0]['child_variants'] ?? [];
        $this->assertArrayHasKey($childA, $childVariants, 'childA (index 0) must survive even though the parent id equals its array index');
        $this->assertArrayHasKey($childB, $childVariants, 'childB (index 1) must be present');
    }

    /**
     * Case 4: parent id does not match any array key or child value — no
     * unrelated child should ever be removed. This must pass both before and
     * after the fix; it guards against a regression in the other direction.
     */
    public function test_non_colliding_parent_id_keeps_all_children(): void
    {
        $childA = $this->makeChildVariant('BundleChildNoCollideA');
        $childB = $this->makeChildVariant('BundleChildNoCollideB');

        $variants = [$this->parentVariant(999_999, [$childA, $childB])];

        $result = Helper::loadBundleChild($variants, ['id', 'variation_title']);

        $childVariants = $result[0]['child_variants'] ?? [];
        $this->assertArrayHasKey($childA, $childVariants);
        $this->assertArrayHasKey($childB, $childVariants);
        $this->assertCount(2, $childVariants);
    }

    /**
     * Case 3: a parent that lists its own id as a child (self-reference)
     * must exclude that id from child_variants, without disturbing the
     * other real children.
     */
    public function test_self_referencing_parent_id_is_excluded_but_siblings_survive(): void
    {
        $childA = $this->makeChildVariant('BundleChildSelfRefA');
        $childB = $this->makeChildVariant('BundleChildSelfRefB');
        $parentId = $childB + 1000; // guaranteed not to equal a real child id

        $variants = [$this->parentVariant($parentId, [$childA, $childB, $parentId])];

        $result = Helper::loadBundleChild($variants, ['id', 'variation_title']);

        $childVariants = $result[0]['child_variants'] ?? [];
        $this->assertArrayHasKey($childA, $childVariants);
        $this->assertArrayHasKey($childB, $childVariants);
        $this->assertArrayNotHasKey($parentId, $childVariants, 'a parent must never resolve itself as its own child');
    }

    /**
     * Case 6: two bundle parents sharing the same child variation must both
     * see it, and one parent's id must not evict the shared child for the
     * other parent.
     */
    public function test_multiple_parents_sharing_a_child_both_see_it(): void
    {
        $this->makeChildVariant('BundleSharedFiller1');
        $this->makeChildVariant('BundleSharedFiller2');
        $shared = $this->makeChildVariant('BundleSharedChild');
        $onlyA = $this->makeChildVariant('BundleOnlyChildA');
        $this->assertGreaterThan(2, $shared, 'fixture must guarantee real child ids are not 1 or 2, so the parent ids below only collide with array keys');

        // Flattened+unique list: [0 => shared, 1 => onlyA] (order from Arr::flatten/array_unique).
        $variants = [
            $this->parentVariant(1, [$shared]),
            $this->parentVariant(2, [$shared, $onlyA]),
        ];

        $result = Helper::loadBundleChild($variants, ['id', 'variation_title']);

        $this->assertArrayHasKey($shared, $result[0]['child_variants'] ?? [], 'parent 1 must see the shared child');
        $this->assertArrayHasKey($shared, $result[1]['child_variants'] ?? [], 'parent 2 must see the shared child');
        $this->assertArrayHasKey($onlyA, $result[1]['child_variants'] ?? [], 'parent 2 must see its own unshared child');
    }

    /**
     * Case 7/8: duplicate child ids and a non-bundle variant (empty
     * bundle_child_ids) mixed into the same batch must not break resolution
     * for the real bundle parent.
     */
    public function test_duplicate_child_ids_and_plain_variant_do_not_break_resolution(): void
    {
        $childA = $this->makeChildVariant('BundleDupChildA');

        $variants = [
            $this->parentVariant(1, [$childA, $childA]), // duplicate before array_unique()
            ['id' => 5, 'other_info' => []], // plain, non-bundle variant
        ];

        $result = Helper::loadBundleChild($variants, ['id', 'variation_title']);

        $this->assertArrayHasKey($childA, $result[0]['child_variants'] ?? []);
        $this->assertSame([], $result[1]['child_variants'] ?? null, 'non-bundle variant must resolve to an empty child_variants list');
    }
}
