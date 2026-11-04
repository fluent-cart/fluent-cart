<?php

namespace Tests\Integration\Services\Filter;

use FluentCart\App\Services\Filter\OrderFilter;
use Tests\Support\DatabaseTestCase;

/**
 * BaseFilter::supportedOperators() is the engine's operator vocabulary, and
 * resolveOperator() the only place a requested operator is translated into the
 * one applied to the query.
 *
 * The indirection exists because Query\Builder::invalidOperator() does not reject
 * an operator it fails to recognise — it rewrites it to `=` and compares the column
 * against the operator STRING. So `where('uuid', 'equals', 'ABC123')` becomes
 * `where('uuid', '=', 'equals')`: the user's value is discarded and the filter
 * silently matches nothing instead of erroring. Core's own options advertise named
 * operators ('equals' on Order Hash (UUID)), so the vocabulary has to be
 * extensible without editing the engine.
 *
 * Resolution is asserted directly here rather than through generated SQL:
 * BaseFilter::applyAdvancedFilter() returns early when Pro is inactive, so no
 * advanced condition reaches a query in this suite at all. The end-to-end SQL
 * proof lives in the Pro suite.
 *
 * These tests run against the disposable test database only.
 */
class BaseFilterOperatorVocabularyTest extends DatabaseTestCase
{
    private const HOOK = 'fluent_cart/filter/supported_operators';

    /** @var callable[] */
    private array $registered = [];

    public function tearDown(): void
    {
        foreach ($this->registered as $callback) {
            remove_filter(self::HOOK, $callback);
        }

        $this->registered = [];

        parent::tearDown();
    }

    private function listen(callable $callback): void
    {
        add_filter(self::HOOK, $callback, 10, 2);
        $this->registered[] = $callback;
    }

    public function test_shipped_vocabulary_is_an_identity_map(): void
    {
        foreach (OrderFilter::supportedOperators() as $requested => $applied) {
            $this->assertSame(
                $requested,
                $applied,
                "Core must ship '{$requested}' as an identity mapping — translation exists for listeners, not for core"
            );
        }
    }

    public function test_a_shipped_operator_resolves_to_itself(): void
    {
        $filter = new OperatorProbeOrderFilter([]);

        $this->assertSame('=', $filter->resolve('='));
        $this->assertSame('in', $filter->resolve('in'));
        $this->assertSame('date_equal', $filter->resolve('date_equal'));
    }

    public function test_a_listener_can_teach_the_engine_a_named_operator(): void
    {
        $filter = new OperatorProbeOrderFilter([]);

        $this->assertSame(
            'equals',
            $filter->resolve('equals'),
            'Unmapped, the operator passes through — and the builder then compares the column against the string "equals"'
        );

        $this->listen(function ($operators) {
            return array_merge($operators, ['equals' => '=']);
        });

        $this->assertSame('=', $filter->resolve('equals'), 'Once mapped, the condition means what it says');
    }

    public function test_an_unmapped_operator_is_left_untouched(): void
    {
        $this->listen(function ($operators) {
            return array_merge($operators, ['equals' => '=']);
        });

        $filter = new OperatorProbeOrderFilter([]);

        $this->assertSame(
            'no_such_operator',
            $filter->resolve('no_such_operator'),
            'Resolution must not change behaviour for operators nobody has mapped'
        );
    }

    public function test_a_non_string_operator_is_returned_unchanged(): void
    {
        $filter = new OperatorProbeOrderFilter([]);

        $this->assertNull($filter->resolve(null));
        $this->assertSame(5, $filter->resolve(5));
    }

    /**
     * The third argument is a read-only context ARRAY, not a bare scalar — see
     * the hook conventions in .claude/skills/coding-rules/hooks-filters.md. The
     * shape is part of the public contract: changing it later would break every
     * listener, so it is pinned here.
     */
    public function test_the_filter_receives_a_context_array(): void
    {
        $seen = 'not-called';

        $this->listen(function ($operators, $context = null) use (&$seen) {
            $seen = $context;

            return $operators;
        });

        OrderFilter::supportedOperators();

        $this->assertIsArray($seen, 'Context must travel as an array, not a positional scalar');
        $this->assertSame(
            ['filter_name' => OrderFilter::getFilterName()],
            $seen,
            'Listeners need the filter name to scope a mapping to one table'
        );
    }
}

/**
 * Exposes the protected resolution step.
 */
class OperatorProbeOrderFilter extends OrderFilter
{
    public function resolve($operator)
    {
        return $this->resolveOperator($operator);
    }
}
