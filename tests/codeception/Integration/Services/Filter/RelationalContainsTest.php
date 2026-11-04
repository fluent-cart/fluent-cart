<?php

namespace Tests\Integration\Services\Filter;

use FluentCart\App\Services\Filter\OrderFilter;
use ReflectionMethod;
use Tests\Support\DatabaseTestCase;

/**
 * `contains` / `not_contains` on a relation-backed field.
 *
 * The UI labels these "Includes" and "Doesn't includes" (see the text operator
 * map in _FilterItem.vue), so on a text field they are substring searches. They
 * were being answered with an exact match instead: a string search term was
 * normalised into a single-element array alongside the genuinely array-based
 * operators, which routed it to `whereIn`. Filtering Customer Email by
 * "Includes gmail" therefore returned nothing, while the full address returned
 * rows — an empty table that reads as a legitimate answer.
 *
 * The distinction that has to survive is the VALUE type, not the operator:
 * `contains` also carries an array of ids for `remote_tree_select` fields such as
 * Order Items, and those must stay an exact `whereIn`.
 *
 * handleRelation() is private to the trait, so these drive it through reflection
 * and assert the generated SQL. BaseFilter::applyAdvancedFilter() returns early
 * when Pro is inactive, so no advanced condition reaches a query in this suite
 * by the normal route.
 *
 * These tests run against the disposable test database only.
 */
class RelationalContainsTest extends DatabaseTestCase
{
    /**
     * Run one condition through the same sequence applyAdvancedFilter() uses:
     * mergeRelationFilters() first, then handleRelation() per surviving item.
     *
     * Both steps matter. mergeRelationFilters() normalises values before
     * handleRelation() sees them, so driving handleRelation() alone tests a path
     * production never takes — an earlier version of this test did exactly that
     * and passed while the bug was still live.
     */
    private function relationSql(array $filter): array
    {
        $orderFilter = OrderFilter::make([]);
        $query = $orderFilter->query();

        $merge = new ReflectionMethod(OrderFilter::class, 'mergeRelationFilters');
        $merge->setAccessible(true);
        $items = $merge->invoke($orderFilter, [$filter]);

        $handle = new ReflectionMethod(OrderFilter::class, 'handleRelation');
        $handle->setAccessible(true);

        foreach ($items as $item) {
            $handle->invokeArgs($orderFilter, [&$query, $item]);
        }

        return [strtolower($query->toSql()), $query->getBindings()];
    }

    private function utmFilter(string $operator, $value): array
    {
        return [
            'property'    => 'utm_source',
            'relation'    => 'orderOperation',
            'column'      => 'utm_source',
            'operator'    => $operator,
            'value'       => $value,
            'filter_type' => 'relation',
        ];
    }

    public function test_contains_on_a_string_is_a_substring_match(): void
    {
        list($sql, $bindings) = $this->relationSql($this->utmFilter('contains', 'goog'));

        $this->assertStringContainsString('like', $sql, '"Includes" must generate a LIKE, not an equality test');
        $this->assertContains('%goog%', $bindings, 'The term must be wrapped in wildcards');
        $this->assertNotContains('goog', $bindings, 'The bare term would mean an exact match');
    }

    public function test_not_contains_on_a_string_excludes_every_matching_relation(): void
    {
        list($sql, $bindings) = $this->relationSql($this->utmFilter('not_contains', 'goog'));

        $this->assertStringContainsString('like', $sql);
        $this->assertContains('%goog%', $bindings);
        $this->assertStringContainsString(
            'not exists',
            $sql,
            '"Doesn\'t include" must exclude a row when ANY related record matches — for a to-many relation that is not the same as requiring one that does not match'
        );
    }

    /**
     * Regression guard: `contains` also carries an array of ids from
     * remote_tree_select fields (Order Items). Those are membership tests and
     * must not become LIKE queries.
     */
    public function test_contains_on_an_array_stays_an_exact_membership_test(): void
    {
        list($sql, $bindings) = $this->relationSql([
            'property'    => 'order_items',
            'relation'    => 'order_items',
            'column'      => 'post_id',
            'operator'    => 'contains',
            'value'       => ['2603', '2607'],
            'filter_type' => 'relation',
        ]);

        $this->assertStringContainsString('in (', $sql, 'An array of ids must stay a whereIn');
        $this->assertStringNotContainsString('like', $sql);
        $this->assertSame(['2603', '2607'], array_values($bindings));
    }

    public function test_in_on_a_string_stays_an_exact_match(): void
    {
        list($sql, $bindings) = $this->relationSql($this->utmFilter('in', 'google'));

        $this->assertStringNotContainsString('like', $sql, '"in" is a membership test, not a substring search');
        $this->assertContains('google', $bindings);
    }

    public function test_not_in_on_an_array_is_unchanged(): void
    {
        list($sql, $bindings) = $this->relationSql($this->utmFilter('not_in', ['google', 'bing']));

        $this->assertStringContainsString('not exists', $sql);
        $this->assertStringNotContainsString('like', $sql);
        $this->assertSame(['google', 'bing'], array_values($bindings));
    }

    public function test_in_all_on_an_array_is_unchanged(): void
    {
        list($sql, $bindings) = $this->relationSql($this->utmFilter('in_all', ['google', 'bing']));

        $this->assertStringNotContainsString('like', $sql);
        $this->assertSame(['google', 'bing'], array_values($bindings));
    }

    public function test_an_empty_term_adds_no_condition(): void
    {
        list($sql, $bindings) = $this->relationSql($this->utmFilter('contains', ''));

        $this->assertStringNotContainsString('like', $sql);
        $this->assertSame([], $bindings);
    }
}
