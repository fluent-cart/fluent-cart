<?php

namespace Tests\Support\Filter;

use FluentCart\App\Models\Customer;
use FluentCart\App\Services\Filter\BaseFilter;

/**
 * Test-only filter that documents the ONE hazard the "callback applies its own
 * eager load" contract creates.
 *
 * Every callback issues its own `with()` call, and Builder::with() array_merges
 * into $eagerLoad. Builder::addNestedWiths() only refuses to clobber entries
 * inside the SAME call — across two calls, the later one wins. So an entry that
 * asks for `orders.customer` injects a parent `orders` carrying an EMPTY closure,
 * which overwrites the constrained `orders` an earlier entry registered.
 *
 * Both keys below are separately listed, and `withOrdersRestricted` narrows
 * `orders` to two columns. Requesting them together in this order proves the
 * clobber: `total_amount` comes back on the orders rows.
 *
 * The rule that follows for every subclass: a nested-path callback must
 * re-declare its parent's constraint in its own with() call, the way
 * AllowlistTestCustomerFilter::withOrdersCustomerGated() does.
 */
class NestedPathTestCustomerFilter extends BaseFilter
{
    /**
     * @var int
     */
    public static $parentCallbackCalls = 0;

    public static function resetInvocations(): void
    {
        static::$parentCallbackCalls = 0;
    }

    protected function allowedWiths(): array
    {
        return [
            'orders'          => [$this, 'withOrdersRestricted'],
            'orders.customer' => [$this, 'withOrdersCustomerUnrestricted'],
        ];
    }

    public function withOrdersRestricted($query)
    {
        static::$parentCallbackCalls++;

        return $query->with(['orders' => function ($relation) {
            $relation->select(['id', 'customer_id']);
        }]);
    }

    /**
     * Deliberately does NOT re-state the parent constraint — this is the wrong
     * way to write a nested entry, kept so the hazard stays pinned.
     */
    public function withOrdersCustomerUnrestricted($query)
    {
        return $query->with('orders.customer');
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
        return 'nested_path_test_customer';
    }
}
