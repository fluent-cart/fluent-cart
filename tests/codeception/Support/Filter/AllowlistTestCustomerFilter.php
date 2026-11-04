<?php

namespace Tests\Support\Filter;

use FluentCart\App\Models\Customer;
use FluentCart\App\Services\Filter\BaseFilter;
use FluentCart\Framework\Support\Arr;

/**
 * Test-only BaseFilter subclass used to exercise the `with` and `scopes`
 * allow-list machinery in isolation, without touching any real filter's map.
 *
 * There is exactly ONE entry form, and it is the same in both maps:
 *
 *     'literal_request_key' => callable
 *
 * The callback receives a query builder, checks its own permission, applies its
 * own eager load / count / scope and returns the query. `false` — or anything
 * that is not a Builder — means the entry contributes nothing. The base never
 * learns a relation name, so there is nothing to alias, nothing to decompose and
 * nothing to prefix-walk.
 *
 * Keys are matched with array_key_exists() against the map KEYS only. Matching
 * against values is impossible by construction now: a value is a callable.
 *
 * Note what is deliberately NOT in the map: `wpUser`, `primary_billing_address`,
 * `labels`. An unlisted key is dropped, whether it is sent on its own or as a
 * segment of a nested path.
 */
class AllowlistTestCustomerFilter extends BaseFilter
{
    /**
     * callback name => number of times it was invoked.
     *
     * @var array
     */
    public static $invocations = [];

    public static function resetInvocations(): void
    {
        static::$invocations = [];
    }

    public static function invocationCount(string $callback): int
    {
        return isset(static::$invocations[$callback]) ? static::$invocations[$callback] : 0;
    }

    public static function record(string $callback): void
    {
        if (!isset(static::$invocations[$callback])) {
            static::$invocations[$callback] = 0;
        }
        static::$invocations[$callback]++;
    }

    protected function allowedWiths(): array
    {
        return [
            // Ordinary entry: the callback names the relation and owns the select.
            'orders'                   => [$this, 'withOrders'],

            // Permission-gated — the shape every real filter uses.
            'subscriptions'            => [$this, 'withSubscriptionsIfPermitted'],

            // Mutates the builder it is handed and THEN refuses. The base invokes
            // callbacks against a clone precisely so this cannot land.
            'primary_shipping_address' => [$this, 'withAddressDeniedAfterMutating'],

            // A Closure map value is a callable like any other.
            'shipping_address'         => function ($query) {
                static::record('shippingAddressClosure');

                return $query->with(['shipping_address' => function ($relation) {
                    $relation->select(['id', 'customer_id', 'type']);
                }]);
            },

            // A count is not a special case: it is a callback calling withCount().
            'ordersCount'              => [$this, 'countOrders'],

            // ...and a count can refuse like anything else.
            'labelsCount'              => [$this, 'countRefused'],

            // Nested path. The callback names the FULL path and re-states the
            // parent's constraint in the same with() call, because with('a.b')
            // injects `a` with an EMPTY closure otherwise.
            'orders.customer'          => [$this, 'withOrdersCustomerGated'],

            // A listed key is not enough: the value must be callable.
            'billing_address'          => 'billing_address',
        ];
    }

    protected function allowedScopes(): array
    {
        return [
            // Declared as ($query) — one parameter, so it can never read the
            // client's arguments. That is the default refusal of scope arguments.
            'ofActive'       => [$this, 'applyActiveScope'],

            // Declared as ($query, $args) — opts in, and validates.
            'archived_gated' => [$this, 'applyArchivedScope'],

            // Routes a BUILDER method name through Builder::scopes(). It must
            // still be unreachable: callNamedScope() prefixes with "scope", so
            // only a real scopeX() exists at the end of that path.
            'delete'         => [$this, 'routeDeleteThroughScopes'],

            // Listed but not callable.
            'ofArchived'     => 'ofArchived',
        ];
    }

    public function withOrders($query)
    {
        static::record('withOrders');

        return $query->with(['orders' => function ($relation) {
            $relation->select(['id', 'customer_id']);
        }]);
    }

    /**
     * Mutate first, refuse second — the exact shape that would be a silent
     * authorization bypass if the base handed callbacks the live query.
     *
     * Both mutations are discriminating: the eager load would show up in
     * getEagerLoads(), and where('id', 0) would empty the result set.
     */
    public function withAddressDeniedAfterMutating($query)
    {
        static::record('withAddressDeniedAfterMutating');

        $query->with(['primary_shipping_address' => function ($relation) {
            $relation->select(['id', 'customer_id']);
        }])->where('id', 0);

        return false;
    }

    public function withSubscriptionsIfPermitted($query)
    {
        static::record('withSubscriptionsIfPermitted');

        if (!$this->userCanAny(['subscriptions/view'])) {
            return false;
        }

        return $query->with(['subscriptions' => function ($relation) {
            $relation->select(['id', 'customer_id']);
        }]);
    }

    public function withOrdersCustomerGated($query)
    {
        static::record('withOrdersCustomerGated');

        if (!$this->userCanAny(['customers/view'])) {
            return false;
        }

        return $query->with([
            'orders'          => function ($relation) {
                $relation->select(['id', 'customer_id']);
            },
            'orders.customer' => function ($relation) {
                $relation->select(['id', 'email']);
            },
        ]);
    }

    public function countOrders($query)
    {
        static::record('countOrders');

        return $query->withCount('orders');
    }

    public function countRefused($query)
    {
        static::record('countRefused');

        return false;
    }

    public function applyActiveScope($query)
    {
        static::record('applyActiveScope');

        return $query->scopes(['ofActive']);
    }

    public function applyArchivedScope($query, $args)
    {
        static::record('applyArchivedScope');

        if (Arr::get($args, 0) !== 'yes') {
            return false;
        }

        return $query->scopes(['ofArchived']);
    }

    public function routeDeleteThroughScopes($query)
    {
        static::record('routeDeleteThroughScopes');

        return $query->scopes(['delete']);
    }

    public function applySimpleFilter(?string $search = null): void
    {
        //
    }

    public function applyActiveViewFilter(?string $activeView = null): void
    {
        //
    }

    public function tabsMap(): array
    {
        return [];
    }

    public function getModel(): string
    {
        return Customer::class;
    }

    public static function getFilterName(): string
    {
        return 'allowlist_test_customer';
    }
}
