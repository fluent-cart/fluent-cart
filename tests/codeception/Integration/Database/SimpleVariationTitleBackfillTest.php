<?php

namespace Tests\Integration\Database;

use FluentCart\App\Models\ProductVariation;
use FluentCart\Database\DataBackfills;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ProductFactory;

class SimpleVariationTitleBackfillTest extends DatabaseTestCase
{
    private const SLUG = 'simple_variation_title';

    protected function setUp(): void
    {
        parent::setUp();

        $option = (array) fluent_cart_get_option('_db_migrations', [], false);
        unset($option['backfills'][self::SLUG]);
        fluent_cart_update_option('_db_migrations', $option);
    }

    public function test_an_empty_simple_variation_title_gets_the_product_title(): void
    {
        global $wpdb;

        $migrated = (new ProductFactory())
            ->withVariantAttributes(['variation_title' => ''])
            ->create(['post_title' => 'Imported Mug']);
        $titled = (new ProductFactory())
            ->withVariantAttributes(['variation_title' => 'Kept Title'])
            ->create(['post_title' => 'Other Mug']);
        $variable = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->withVariantAttributes(['variation_title' => ''])
            ->create(['post_title' => 'Variable Mug']);
        $untitled = (new ProductFactory())
            ->withVariantAttributes(['variation_title' => ''])
            ->create(['post_title' => 'Untitled Mug']);
        $wpdb->update($wpdb->posts, ['post_title' => ''], ['ID' => $untitled->ID]);

        DataBackfills::processPending();

        $this->assertSame('Imported Mug', ProductVariation::query()->where('post_id', $migrated->ID)->value('variation_title'), 'An empty simple title gets the product title');
        $this->assertSame('Kept Title', ProductVariation::query()->where('post_id', $titled->ID)->value('variation_title'), 'A set title is kept');
        $this->assertSame('', ProductVariation::query()->where('post_id', $variable->ID)->value('variation_title'), 'A variable product is left alone');
        $this->assertSame('', ProductVariation::query()->where('post_id', $untitled->ID)->value('variation_title'), 'A product with no title has nothing to copy');
        $this->assertNotContains(self::SLUG, DataBackfills::getPending(), 'The backfill completes');
    }

    public function test_the_backfill_runs_in_chunks_and_resumes_from_the_cursor(): void
    {
        $budget = function ($budget, $context) {
            return $context['slug'] === self::SLUG
                ? ['chunk_size' => 1, 'max_chunks_per_run' => 1]
                : $budget;
        };
        add_filter('fluent_cart/data_backfills/chunk_budget', $budget, 10, 2);

        $variable = (new ProductFactory())
            ->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->withVariantAttributes(['variation_title' => ''])
            ->create(['post_title' => 'Variable Mug']);
        $first = (new ProductFactory())
            ->withVariantAttributes(['variation_title' => ''])
            ->create(['post_title' => 'First Mug']);
        $second = (new ProductFactory())
            ->withVariantAttributes(['variation_title' => ''])
            ->create(['post_title' => 'Second Mug']);
        $variableId = (int) ProductVariation::query()->where('post_id', $variable->ID)->value('id');

        $run = DataBackfills::processPending();

        $this->assertSame('running', $run['status'], 'A full chunk at the budget leaves the backfill running');
        $this->assertSame('', ProductVariation::query()->where('post_id', $first->ID)->value('variation_title'), 'The first run reads only one row');
        $this->assertSame($variableId, (int) fluent_cart_get_option('_fluent_cart_simple_variation_title_cursor', 0, false), 'The cursor moves past the skipped row');

        DataBackfills::processPending();

        $this->assertSame('First Mug', ProductVariation::query()->where('post_id', $first->ID)->value('variation_title'), 'The second run resumes from the cursor');
        $this->assertSame('', ProductVariation::query()->where('post_id', $second->ID)->value('variation_title'), 'The second run stops at its budget');

        DataBackfills::processPending();
        DataBackfills::processPending();
        remove_filter('fluent_cart/data_backfills/chunk_budget', $budget, 10);

        $this->assertSame('Second Mug', ProductVariation::query()->where('post_id', $second->ID)->value('variation_title'), 'The last row is filled');
        $this->assertSame('', ProductVariation::query()->where('post_id', $variable->ID)->value('variation_title'), 'The variable product stays empty');
        $this->assertNotContains(self::SLUG, DataBackfills::getPending(), 'The backfill completes');
        $this->assertSame(0, (int) fluent_cart_get_option('_fluent_cart_simple_variation_title_cursor', 0, false), 'The cursor is removed on completion');
    }

    public function test_a_failed_write_keeps_the_rows_for_the_next_run(): void
    {
        global $wpdb;

        $migrated = (new ProductFactory())
            ->withVariantAttributes(['variation_title' => ''])
            ->create(['post_title' => 'Imported Mug']);

        $variationsTable = $this->fullTableName('fct_product_variations');
        $breakWrite = function ($query) use ($variationsTable) {
            return (stripos($query, 'UPDATE') === 0 && strpos($query, $variationsTable) !== false && strpos($query, 'variation_title') !== false)
                ? 'UPDATE fct_no_such_table SET id = 1'
                : $query;
        };
        add_filter('query', $breakWrite);
        $suppressed = $wpdb->suppress_errors(true);
        $failed = DataBackfills::processPending();
        $wpdb->suppress_errors($suppressed);
        remove_filter('query', $breakWrite);

        $this->assertNotSame('completed', $failed['status'], 'A failed write is not completion');
        $this->assertContains(self::SLUG, DataBackfills::getPending(), 'The backfill stays pending');
        $this->assertSame('', ProductVariation::query()->where('post_id', $migrated->ID)->value('variation_title'), 'The failed write changed nothing');
        $this->assertSame(0, (int) fluent_cart_get_option('_fluent_cart_simple_variation_title_cursor', 0, false), 'The cursor does not move past the failed rows');

        DataBackfills::processPending();

        $this->assertSame('Imported Mug', ProductVariation::query()->where('post_id', $migrated->ID)->value('variation_title'), 'The next run retries the same rows');
        $this->assertNotContains(self::SLUG, DataBackfills::getPending(), 'The backfill completes on retry');
    }
}
