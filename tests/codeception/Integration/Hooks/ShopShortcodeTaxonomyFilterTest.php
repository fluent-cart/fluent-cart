<?php

namespace Tests\Integration\Hooks;

use FluentCart\Api\Taxonomy;
use FluentCart\App\CPT\FluentProducts;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * [fluent_cart_products] — filtering by any product taxonomy.
 *
 * FluentCart registers categories and brands only, but a store can register
 * its own taxonomies on fluent-products (product-tags is the common one).
 * Up to 1.6.0 `tag_id=` filtered on product-tags; 1.6.1 removed it and the
 * shortcode silently rendered the whole catalog. The `taxonomies` attribute
 * replaces it for any taxonomy: "taxonomy:term-id,…|taxonomy:…", OR inside a taxonomy,
 * AND across them. A segment that resolves to no term (unknown taxonomy, or
 * a term from another taxonomy) must match nothing, never everything.
 */
class ShopShortcodeTaxonomyFilterTest extends WPTestCase
{
    /** @var array<string, int> */
    private $productIds = [];

    /** @var int */
    private $saleTagId;

    /** @var int */
    private $newTagId;

    /** @var int */
    private $acmeBrandId;

    public function setUp(): void
    {
        parent::setUp();

        register_taxonomy('product-tags', FluentProducts::CPT_NAME, [
            'public'       => true,
            'hierarchical' => false,
        ]);

        $this->saleTagId = (int) wp_insert_term('Sale', 'product-tags', ['slug' => 'sale'])['term_id'];
        $this->newTagId = (int) wp_insert_term('New', 'product-tags', ['slug' => 'new'])['term_id'];
        $this->acmeBrandId = (int) wp_insert_term('Acme', 'product-brands', ['slug' => 'acme'])['term_id'];

        $factory = new ProductFactory();
        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta'] as $name) {
            $this->productIds[$name] = (int) $factory->create(['post_title' => 'TaxFilter ' . $name])->ID;
        }

        // Alpha: sale + acme · Bravo: sale · Charlie: new + acme · Delta: none
        wp_set_object_terms($this->productIds['Alpha'], [$this->saleTagId], 'product-tags');
        wp_set_object_terms($this->productIds['Bravo'], [$this->saleTagId], 'product-tags');
        wp_set_object_terms($this->productIds['Charlie'], [$this->newTagId], 'product-tags');
        wp_set_object_terms($this->productIds['Alpha'], [$this->acmeBrandId], 'product-brands');
        wp_set_object_terms($this->productIds['Charlie'], [$this->acmeBrandId], 'product-brands');
    }

    public function tearDown(): void
    {
        unregister_taxonomy('product-tags');
        parent::tearDown();
    }

    private function render(string $attributes): string
    {
        return do_shortcode('[fluent_cart_products per_page="20" ' . $attributes . ']');
    }

    private function renderedNames(string $attributes): array
    {
        $output = $this->render($attributes);

        $names = [];
        foreach (array_keys($this->productIds) as $name) {
            if (strpos($output, 'TaxFilter ' . $name) !== false) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function test_without_a_taxonomy_attribute_every_product_renders(): void
    {
        $this->assertSame(['Alpha', 'Bravo', 'Charlie', 'Delta'], $this->renderedNames(''));
    }

    public function test_a_term_id_filters_on_a_store_registered_taxonomy(): void
    {
        $this->assertSame(['Alpha', 'Bravo'], $this->renderedNames('taxonomies="product-tags:' . $this->saleTagId . '"'));
    }

    public function test_a_slug_is_not_a_term_id(): void
    {
        $this->assertSame([], $this->renderedNames('taxonomies="product-tags:sale"'));
    }

    public function test_terms_inside_one_taxonomy_match_any(): void
    {
        $this->assertSame(
            ['Alpha', 'Bravo', 'Charlie'],
            $this->renderedNames('taxonomies="product-tags:' . $this->saleTagId . ',' . $this->newTagId . '"')
        );
    }

    public function test_taxonomies_combine_with_and(): void
    {
        $this->assertSame(
            ['Alpha'],
            $this->renderedNames('taxonomies="product-tags:' . $this->saleTagId . '|product-brands:' . $this->acmeBrandId . '"')
        );
    }

    public function test_a_short_name_is_not_a_taxonomy(): void
    {
        $this->assertSame([], $this->renderedNames('taxonomies="tag:' . $this->saleTagId . '"'));
    }

    public function test_an_invalid_term_inside_a_valid_segment_is_dropped(): void
    {
        $this->assertSame(
            ['Alpha', 'Bravo'],
            $this->renderedNames('taxonomies="product-tags:' . $this->saleTagId . ',999999"')
        );
    }

    public function test_a_term_from_another_taxonomy_matches_nothing(): void
    {
        // The query joins term relationships without checking the taxonomy, so
        // an unchecked brand id under product-tags would match Alpha and Charlie.
        $this->assertSame([], $this->renderedNames('taxonomies="product-tags:' . $this->acmeBrandId . '"'));
    }

    public function test_an_unknown_taxonomy_matches_nothing_instead_of_everything(): void
    {
        $this->assertSame([], $this->renderedNames('taxonomies="not-a-taxonomy:' . $this->saleTagId . '"'));
    }

    public function test_term_lookups_do_not_grow_with_the_number_of_ids(): void
    {
        $oneId = (string) $this->saleTagId;
        $manyIds = implode(',', [$this->saleTagId, $this->newTagId, $this->acmeBrandId, 999998, 999999]);
        $repeatedSegments = 'product-tags:' . $this->saleTagId . '|product-tags:' . $this->newTagId . '|product-tags:999999';

        $baseline = $this->termQueryCount('product-tags:' . $oneId);

        $this->assertSame($baseline, $this->termQueryCount('product-tags:' . $manyIds), 'more ids in one segment');
        $this->assertSame($baseline, $this->termQueryCount($repeatedSegments), 'the same taxonomy in several segments');
    }

    public function test_repeated_segments_of_one_taxonomy_match_any(): void
    {
        $this->assertSame(
            ['Alpha', 'Bravo', 'Charlie'],
            $this->renderedNames('taxonomies="product-tags:' . $this->saleTagId . '|product-tags:' . $this->newTagId . '|product-tags:999999"')
        );
    }

    private function termQueryCount(string $attribute): int
    {
        global $wpdb;

        wp_cache_flush();
        $count = 0;
        $counter = function ($query) use (&$count, $wpdb) {
            if (strpos($query, $wpdb->terms) !== false) {
                $count++;
            }
            return $query;
        };

        add_filter('query', $counter);
        Taxonomy::parseShortcodeTaxonomies($attribute);
        remove_filter('query', $counter);

        return $count;
    }

    public function test_the_filter_is_carried_into_the_default_filters_used_by_the_pager(): void
    {
        $output = $this->render('taxonomies="product-tags:' . $this->saleTagId . '"');

        $this->assertSame(1, preg_match('/data-default-filters="([^"]*)"/', $output, $match));
        $defaultFilters = json_decode(html_entity_decode($match[1]), true);

        $this->assertSame([$this->saleTagId], array_map('intval', array_values($defaultFilters['product-tags'])));
    }
}
