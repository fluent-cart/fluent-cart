<?php

namespace Tests\Integration\Services\Filter;

use FluentCart\App\Models\Customer;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Filter\AllowlistTestCustomerFilter;

/**
 * BaseFilter::applyScopes() used to invoke whatever method name the request
 * named on the query builder, before any WHERE clause had been added:
 *
 *     foreach (Arr::wrap($this->scopes) as $scope) {
 *         if (is_array($scope)) { $this->query->{$scope[0]}($scope[1]); continue; }
 *         $this->query->{$scope}();
 *     }
 *
 * Orm\Builder::delete() and ::update() are real public methods, and __call
 * forwards anything else to the query builder. `scopes[]=delete` therefore ran
 * an unconstrained DELETE over the whole table.
 *
 * The map is now the same single form as allowedWiths(): literal request key =>
 * callable. Scope callbacks are invoked as `($query, $args)`; a callback that
 * declares only `($query)` never sees the client's arguments, which is the
 * default refusal.
 *
 * These tests run against the disposable test database only.
 */
class BaseFilterScopeAllowlistTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        AllowlistTestCustomerFilter::resetInvocations();
    }

    // =========================================================================
    // Destructive builder methods
    // =========================================================================

    public function test_scopes_delete_does_not_delete_every_row()
    {
        $this->customerFactory->create();
        $this->customerFactory->create();
        $countBefore = Customer::query()->count();

        $error = null;
        try {
            AllowlistTestCustomerFilter::make(['scopes' => ['delete']])->get();
        } catch (\Throwable $exception) {
            $error = $exception;
        }

        $this->assertSame(
            $countBefore,
            Customer::query()->count(),
            'scopes[]=delete reached Orm\Builder::delete() on an unconstrained query and emptied the table'
        );

        // 'delete' IS a key in this filter's allowedScopes(), and its callback
        // routes it through Builder::scopes(). It is STILL unreachable, because
        // scopes() resolves via Model::callNamedScope(), which prefixes with
        // "scope" — only a real scopeX() exists at the end of that path. The
        // refusal is structural and surfaces as a BadMethodCallException.
        $this->assertInstanceOf(
            \BadMethodCallException::class,
            $error,
            'Resolution goes through callNamedScope(), so a non-scope method name must not resolve at all'
        );
        $this->assertStringContainsString(
            'scopeDelete',
            $error->getMessage(),
            'The undefined method is the "scope"-prefixed name, which is what makes Orm\Builder::delete() '
            . 'unreachable from a request'
        );
    }

    public function test_scopes_update_does_not_mass_update_every_row()
    {
        $customer = $this->customerFactory->create(['status' => 'active']);
        $this->customerFactory->create(['status' => 'active']);

        $error = null;
        try {
            AllowlistTestCustomerFilter::make([
                'scopes' => [['update', ['status' => 'archived']]],
            ])->get();
        } catch (\Throwable $exception) {
            $error = $exception;
        }

        $this->assertSame(
            'active',
            Customer::query()->find($customer->id)->status,
            'The array scope form fed an attacker-chosen array straight into Orm\Builder::update()'
        );
        $this->assertSame(
            0,
            Customer::query()->where('status', 'archived')->count(),
            'No row may be mass-updated through scopes[]'
        );
        $this->assertNull(
            $error,
            'The refused scope is dropped quietly. Got: '
            . ($error ? get_class($error) . ': ' . $error->getMessage() : '')
        );
    }

    public function test_scopes_truncate_leaves_the_table_intact()
    {
        $this->customerFactory->create();
        $countBefore = Customer::query()->count();

        $error = null;
        try {
            AllowlistTestCustomerFilter::make(['scopes' => ['truncate']])->get();
        } catch (\Throwable $exception) {
            $error = $exception;
        }

        $this->assertSame(
            $countBefore,
            Customer::query()->count(),
            '__call forwards truncate() to the query builder — it must never be reachable from a request'
        );
        $this->assertNull(
            $error,
            'The refused scope is dropped quietly. Got: '
            . ($error ? get_class($error) . ': ' . $error->getMessage() : '')
        );
    }

    public function test_scopes_where_raw_does_not_inject_sql()
    {
        $this->customerFactory->create(['status' => 'active']);
        $this->customerFactory->create(['status' => 'archived']);
        $countAll = Customer::query()->count();

        // The payload MUST be discriminating. `1=1` matches every row, so an
        // injected clause and no clause at all give identical result sets and the
        // assertion proves nothing. `1=0` matches none: pre-fix this returned 0
        // rows out of 2, with the fragment visible in the SQL as
        // `select * from test_fct_customers where 1=0`.
        $error = null;
        $results = null;
        try {
            $results = AllowlistTestCustomerFilter::make([
                'scopes' => [['whereRaw', '1=0']],
            ])->get();
        } catch (\Throwable $exception) {
            $error = $exception;
        }

        $this->assertNull(
            $error,
            'whereRaw is not a key in the map and must be refused. Got: '
            . ($error ? get_class($error) . ': ' . $error->getMessage() : '')
        );
        $this->assertSame(
            $countAll,
            $results->count(),
            'A refused whereRaw must leave the result set whole; 0 rows here means the fragment executed'
        );

        // Direct evidence, independent of what the rows happen to be: the raw
        // fragment must not appear in the generated SQL at all.
        $sql = AllowlistTestCustomerFilter::make([
            'scopes' => [['whereRaw', '1=0']],
        ])->buildQuery()->toSql();

        $this->assertStringNotContainsString(
            '1=0',
            $sql,
            'forwardCallTo() executes the forwarded method against the shared Query\Builder, so an '
            . 'unguarded whereRaw appends to its wheres in place — the clause must never get that far'
        );
    }

    // =========================================================================
    // Allowed scopes
    // =========================================================================

    public function test_allowlisted_named_scope_is_applied()
    {
        $this->customerFactory->create(['status' => 'active']);
        $this->customerFactory->create(['status' => 'archived']);

        $results = AllowlistTestCustomerFilter::make(['scopes' => ['ofActive']])->get();

        $this->assertGreaterThan(0, $results->count(), 'The scope returns the active rows');
        $this->assertSame(
            0,
            $results->where('status', 'archived')->count(),
            'scopeOfActive() actually constrained the query'
        );
        $this->assertSame(
            1,
            AllowlistTestCustomerFilter::invocationCount('applyActiveScope'),
            'The callback runs exactly once'
        );
    }

    public function test_a_listed_scope_whose_value_is_not_callable_is_refused()
    {
        $this->customerFactory->create(['status' => 'active']);
        $this->customerFactory->create(['status' => 'archived']);
        $countAll = Customer::query()->count();

        // `ofArchived` IS a key in the map, and its value is the string
        // 'ofArchived' — a real named scope. It is not callable, so it
        // contributes nothing. There is one entry form and this is not it.
        $results = AllowlistTestCustomerFilter::make(['scopes' => ['ofArchived']])->get();

        $this->assertSame(
            $countAll,
            $results->count(),
            'A non-callable entry is refused — no alias resolution, no bare-name invocation'
        );
    }

    // =========================================================================
    // Scope arguments
    // =========================================================================

    public function test_a_one_parameter_callback_never_sees_a_client_argument()
    {
        $this->customerFactory->create(['status' => 'active']);
        $this->customerFactory->create(['status' => 'archived']);

        // applyActiveScope() is declared ($query). The base still invokes every
        // entry as ($query, $args); a callback that does not declare the second
        // parameter simply cannot read it, which is the default refusal of
        // request-supplied arguments.
        $error = null;
        $results = null;
        try {
            $results = AllowlistTestCustomerFilter::make([
                'scopes' => [['ofActive', 'archived']],
            ])->get();
        } catch (\Throwable $exception) {
            $error = $exception;
        }

        $this->assertNull(
            $error,
            'Passing an argument to a one-parameter callback must not fatal. Got: '
            . ($error ? get_class($error) . ': ' . $error->getMessage() : '')
        );
        $this->assertSame(
            0,
            $results->where('status', 'archived')->count(),
            'The client argument is unreachable; the scope runs exactly as the callback wrote it'
        );
    }

    public function test_scope_callback_refusing_the_argument_drops_the_scope()
    {
        $this->customerFactory->create(['status' => 'active']);
        $this->customerFactory->create(['status' => 'archived']);
        $countAll = Customer::query()->count();

        $results = AllowlistTestCustomerFilter::make([
            'scopes' => [['archived_gated', 'no']],
        ])->get();

        $this->assertSame(
            $countAll,
            $results->count(),
            'A callback returning false drops the scope entirely, leaving the query unconstrained'
        );
        $this->assertSame(
            1,
            AllowlistTestCustomerFilter::invocationCount('applyArchivedScope'),
            'The callback was consulted exactly once'
        );
    }

    public function test_scope_callback_accepting_the_argument_applies_the_scope()
    {
        $this->customerFactory->create(['status' => 'active']);
        $this->customerFactory->create(['status' => 'archived']);

        $results = AllowlistTestCustomerFilter::make([
            'scopes' => [['archived_gated', 'yes']],
        ])->get();

        $this->assertGreaterThan(0, $results->count(), 'The archived rows come back');
        $this->assertSame(
            0,
            $results->where('status', 'active')->count(),
            'The callback applied scopeOfArchived() after validating the argument'
        );
    }

    // =========================================================================
    // Deny by default and malformed input
    // =========================================================================

    public function test_unlisted_scope_is_dropped_silently()
    {
        $this->customerFactory->create(['status' => 'active']);
        $this->customerFactory->create(['status' => 'archived']);
        $countAll = Customer::query()->count();

        $error = null;
        $results = null;
        try {
            $results = AllowlistTestCustomerFilter::make(['scopes' => ['ofNotAThing']])->get();
        } catch (\Throwable $exception) {
            $error = $exception;
        }

        $this->assertNull(
            $error,
            'An unlisted scope is dropped before it can reach the builder. Got: '
            . ($error ? get_class($error) . ': ' . $error->getMessage() : '')
        );
        $this->assertSame(
            $countAll,
            $results->count(),
            'Dropping the scope must leave the result set unnarrowed, not silently filter it'
        );
    }

    public function test_non_string_scope_entries_do_not_fatal()
    {
        $this->customerFactory->create(['status' => 'active']);
        $this->customerFactory->create(['status' => 'archived']);

        $error = null;
        $results = null;
        try {
            $results = AllowlistTestCustomerFilter::make([
                'scopes' => [['nested' => 'array'], 123, null, 'ofActive'],
            ])->get();
        } catch (\Throwable $exception) {
            $error = $exception;
        }

        $this->assertNull(
            $error,
            'Malformed scope entries are discarded. Got: '
            . ($error ? get_class($error) . ': ' . $error->getMessage() : '')
        );
        $this->assertSame(
            0,
            $results->where('status', 'archived')->count(),
            'The well-formed sibling entry still applies'
        );
    }

    public function test_a_refusing_scope_callback_cannot_mutate_the_main_query()
    {
        $this->customerFactory->create(['status' => 'active']);
        $this->customerFactory->create(['status' => 'archived']);

        $baselineSql = AllowlistTestCustomerFilter::make([])->buildQuery()->toSql();

        // applyArchivedScope() calls nothing before refusing, but the guarantee is
        // structural, not a property of that callback: the entry is invoked
        // against a clone, so the refusal path can never leave a clause behind.
        $refusedSql = AllowlistTestCustomerFilter::make([
            'scopes' => [['archived_gated', 'no']],
        ])->buildQuery()->toSql();

        $this->assertSame(
            $baselineSql,
            $refusedSql,
            'A refused scope leaves the query byte-identical to one that never named it'
        );
    }

    public function test_allowed_scopes_filter_hook_can_extend_the_map()
    {
        $this->customerFactory->create(['status' => 'active']);
        $this->customerFactory->create(['status' => 'archived']);

        $listener = function ($map) {
            $map['ofArchived'] = function ($query) {
                return $query->scopes(['ofArchived']);
            };
            return $map;
        };
        add_filter('fluent_cart/allowlist_test_customer_allowed_scopes', $listener);

        $results = AllowlistTestCustomerFilter::make(['scopes' => ['ofArchived']])->get();

        remove_filter('fluent_cart/allowlist_test_customer_allowed_scopes', $listener);

        $this->assertSame(
            0,
            $results->where('status', 'active')->count(),
            'The _allowed_scopes filter is the escape valve for add-ons'
        );
    }
}
