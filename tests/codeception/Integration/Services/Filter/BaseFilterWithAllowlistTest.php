<?php

namespace Tests\Integration\Services\Filter;

use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Order;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerAddressFactory;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\UserRoleFactory;
use Tests\Support\Filter\AllowlistTestCustomerFilter;
use Tests\Support\Filter\AllowlistTestOrderFilter;
use Tests\Support\Filter\NestedPathTestCustomerFilter;

/**
 * BaseFilter::applyWith() used to hand the raw `with` request parameter to the
 * ORM. The ORM resolves a relation by literally calling the method, so any
 * public method on the model ran — including mutators that save. These tests
 * pin the allow-list that replaced it.
 *
 * The allow-list is one form: literal request key => callable. The callback owns
 * the permission check, the relation name and the select; the base only decides
 * whether the key is in the map and whether a Builder came back.
 */
class BaseFilterWithAllowlistTest extends DatabaseTestCase
{
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        AllowlistTestCustomerFilter::resetInvocations();
        NestedPathTestCustomerFilter::resetInvocations();
    }

    // =========================================================================
    // Arbitrary public-method invocation
    // =========================================================================

    public function test_recount_stats_mutator_is_not_invoked_through_with()
    {
        $this->customerFactory->create();
        $countBefore = Customer::query()->count();

        $error = null;
        try {
            AllowlistTestCustomerFilter::make(['with' => ['recountStats']])->get();
        } catch (\Throwable $exception) {
            $error = $exception;
        }

        $this->assertNull(
            $error,
            'with[]=recountStats must be dropped, not executed. Got: '
            . ($error ? get_class($error) . ': ' . $error->getMessage() : '')
        );
        $this->assertSame(
            $countBefore,
            Customer::query()->count(),
            'Customer::recountStats() ends in save() — invoking it through with[] must not write a customer row'
        );
    }

    public function test_order_recount_mutator_is_not_invoked_through_with()
    {
        $customer = $this->customerFactory->create();
        $this->orderFactory->create(['customer_id' => $customer->id]);
        $countBefore = Order::query()->count();

        $error = null;
        try {
            AllowlistTestOrderFilter::make(['with' => ['recountTotalPaidAndRefund']])->get();
        } catch (\Throwable $exception) {
            $error = $exception;
        }

        $this->assertNull(
            $error,
            'with[]=recountTotalPaidAndRefund must be dropped, not executed. Got: '
            . ($error ? get_class($error) . ': ' . $error->getMessage() : '')
        );
        $this->assertSame(
            $countBefore,
            Order::query()->count(),
            'Order::recountTotalPaidAndRefund() ends in save() — invoking it through with[] must not write an order row'
        );
    }

    // =========================================================================
    // Keys are literal — nothing is parsed out of them
    // =========================================================================

    public function test_recount_is_not_turned_into_a_with_count_of_re()
    {
        $this->customerFactory->create();

        $error = null;
        $results = null;
        try {
            $results = AllowlistTestCustomerFilter::make(['with' => ['recount']])->get();
        } catch (\Throwable $exception) {
            $error = $exception;
        }

        $this->assertNull(
            $error,
            'with[]=recount must be dropped, not chopped into withCount("re"). Got: '
            . ($error ? get_class($error) . ': ' . $error->getMessage() : '')
        );
        $this->assertArrayNotHasKey(
            're_count',
            $results->first()->getAttributes(),
            'The 5-character blind chop must be gone — no re_count aggregate may appear'
        );
    }

    public function test_client_supplied_column_suffix_is_not_a_listed_key()
    {
        $customer = $this->customerFactory->create();
        $this->orderFactory->create(['customer_id' => $customer->id]);

        // `orders:id,uuid,total_amount` is not the string `orders`, and nothing
        // decomposes it, so it is simply not in the map. The client can neither
        // widen a select nor reach a relation through a suffix.
        $eagerLoads = AllowlistTestCustomerFilter::make([
            'with' => ['orders:id,uuid,total_amount'],
        ])->buildQuery()->getEagerLoads();

        $this->assertSame(
            [],
            array_keys($eagerLoads),
            'A suffixed key is a different literal key — it matches nothing and is dropped whole'
        );
    }

    // =========================================================================
    // Deny by default
    // =========================================================================

    public function test_unlisted_relation_is_dropped_silently()
    {
        $customer = $this->customerFactory->create();
        $this->orderFactory->create(['customer_id' => $customer->id]);

        // A perfectly resolvable nested path that is simply not a key in the map.
        $results = AllowlistTestCustomerFilter::make(['with' => ['orders.wpUser']])->get();

        $this->assertGreaterThan(0, $results->count(), 'The query still returns rows');
        $this->assertFalse(
            $results->first()->relationLoaded('orders'),
            'An unlisted path is refused whole — it must not smuggle its parent segment in either'
        );
    }

    public function test_wp_user_is_not_reachable_directly_or_through_a_nested_path()
    {
        $this->customerFactory->createWithUser();

        foreach (['wpUser', 'orders.customer.wpUser', 'subscriptions.customer.wpUser'] as $payload) {
            $eagerLoads = AllowlistTestCustomerFilter::make(['with' => [$payload]])->buildQuery()->getEagerLoads();

            $this->assertSame(
                [],
                array_keys($eagerLoads),
                "`{$payload}` is not a key in the map — wpUser exposes the WP user row and must stay unreachable, "
                . 'as a whole key and as a path segment'
            );
        }
    }

    public function test_unlisted_count_key_is_dropped()
    {
        $this->customerFactory->create();

        $results = AllowlistTestCustomerFilter::make(['with' => ['subscriptionsCount']])->get();

        // Nothing parses a trailing "Count" any more; a count is just a callback.
        // An unlisted key is therefore indistinguishable from any other miss.
        $this->assertArrayNotHasKey(
            'subscriptions_count',
            $results->first()->getAttributes(),
            'An undeclared count is dropped'
        );
    }

    public function test_a_listed_key_whose_value_is_not_callable_is_refused()
    {
        $customer = $this->customerFactory->create();
        (new CustomerAddressFactory())->create((int)$customer->id, [
            'type'       => 'billing',
            'is_primary' => 1,
        ]);

        // `billing_address` IS a key in the map, and its value is the string
        // 'billing_address' — a real relation name. Under the old grammar that
        // string was an alias and would have loaded. It is not callable, so it
        // contributes nothing: there is exactly one entry form and this is not it.
        $eagerLoads = AllowlistTestCustomerFilter::make([
            'with' => ['billing_address'],
        ])->buildQuery()->getEagerLoads();

        $this->assertSame(
            [],
            array_keys($eagerLoads),
            'A non-callable entry is refused — no alias resolution, no method-name sniffing'
        );
    }

    // =========================================================================
    // Callback contract
    // =========================================================================

    public function test_callback_returning_a_builder_is_adopted_with_its_constraint()
    {
        $customer = $this->customerFactory->create();
        $this->orderFactory->create(['customer_id' => $customer->id]);

        $results = AllowlistTestCustomerFilter::make(['with' => ['orders']])->get();

        $order = $results->first()->orders->first();

        $this->assertArrayHasKey('id', $order->getAttributes(), 'The selected id column is present');
        $this->assertArrayHasKey('customer_id', $order->getAttributes(), 'The selected customer_id column is present');
        $this->assertArrayNotHasKey(
            'total_amount',
            $order->getAttributes(),
            'Columns the callback did not select must not be loaded'
        );
        $this->assertSame(
            1,
            AllowlistTestCustomerFilter::invocationCount('withOrders'),
            'Each callback runs EXACTLY ONCE — the eager load is applied on the same pass that decides'
        );
    }

    public function test_closure_map_value_is_invoked_once_and_constrains()
    {
        $customer = $this->customerFactory->create();
        (new CustomerAddressFactory())->create((int)$customer->id, ['type' => 'shipping']);

        $loaded = AllowlistTestCustomerFilter::make(['with' => ['shipping_address']])->get()->first();

        $this->assertTrue(
            $loaded->relationLoaded('shipping_address'),
            'A Closure map value is a callable like any other'
        );
        $this->assertSame(
            1,
            AllowlistTestCustomerFilter::invocationCount('shippingAddressClosure'),
            'There is no decision pass and no application pass — there is one pass'
        );
        $this->assertArrayNotHasKey(
            'country',
            $loaded->shipping_address->first()->getAttributes(),
            'The closure owns the select, so unselected columns stay out'
        );
    }

    public function test_callback_returning_false_contributes_nothing()
    {
        $this->customerFactory->create();

        $results = AllowlistTestCustomerFilter::make(['with' => ['primary_shipping_address']])->get();

        $this->assertFalse(
            $results->first()->relationLoaded('primary_shipping_address'),
            'A callback returning false must contribute nothing at all'
        );
        $this->assertSame(
            1,
            AllowlistTestCustomerFilter::invocationCount('withAddressDeniedAfterMutating'),
            'The refusal is decided on the one and only pass'
        );
    }

    /**
     * THE hazard the clone form exists to close.
     *
     * `$query->with([...])` mutates the builder in place and returns the same
     * instance, so a callback that mutates and THEN returns false has already
     * changed the query — the refusal does not undo it. withAddressDeniedAfterMutating()
     * does exactly that, and adds a discriminating `where('id', 0)` on top, which
     * would empty the result set if it landed.
     */
    public function test_a_refusing_callback_cannot_mutate_the_main_query()
    {
        $this->customerFactory->create();
        $this->customerFactory->create();

        $baselineSql = AllowlistTestCustomerFilter::make([])->buildQuery()->toSql();
        $baselineRows = AllowlistTestCustomerFilter::make([])->get()->count();

        $filter = AllowlistTestCustomerFilter::make(['with' => ['primary_shipping_address']]);
        $query = $filter->buildQuery();

        $this->assertSame(
            [],
            array_keys($query->getEagerLoads()),
            'The eager load the callback registered before refusing must not survive — it was applied to a clone'
        );
        $this->assertSame(
            $baselineSql,
            $query->toSql(),
            'The where("id", 0) the callback added before refusing must not appear in the main query'
        );
        $this->assertSame(
            $baselineRows,
            AllowlistTestCustomerFilter::make(['with' => ['primary_shipping_address']])->get()->count(),
            'Every row still comes back; a leaked where("id", 0) would have returned none'
        );
        $this->assertGreaterThan(0, $baselineRows, 'The baseline itself is discriminating');
    }

    public function test_a_clone_is_a_genuinely_independent_builder()
    {
        $this->customerFactory->create();

        // Direct evidence for the mechanism the contract rests on: Orm\Builder
        // clones the underlying Query\Builder in __clone(), and wheres, bindings
        // and eager loads are plain arrays, which PHP copies by value.
        $original = AllowlistTestCustomerFilter::make([])->buildQuery();
        $before = $original->toSql();

        $copy = clone $original;
        $copy->where('id', 0)->with('orders')->withCount('orders');

        $this->assertSame($before, $original->toSql(), 'A where added to the clone does not reach the original');
        $this->assertSame([], array_keys($original->getEagerLoads()), 'Nor does an eager load');
        $this->assertNotSame($before, $copy->toSql(), 'The clone really did change — the assertion above is not vacuous');
        $this->assertSame(['orders'], array_keys($copy->getEagerLoads()), 'The clone kept its own eager load');
    }

    // =========================================================================
    // Permission gating
    // =========================================================================

    public function test_permission_gated_callback_drops_the_relation_for_an_anonymous_caller()
    {
        $this->customerFactory->create();
        wp_set_current_user(0);

        $results = AllowlistTestCustomerFilter::make(['with' => ['subscriptions']])->get();

        $this->assertFalse(
            $results->first()->relationLoaded('subscriptions'),
            'userCanAny() is false with no current user, so the relation is refused — this is the cron/CLI caveat'
        );
    }

    public function test_permission_gated_callback_loads_the_relation_for_a_permitted_caller()
    {
        $this->customerFactory->create();
        wp_set_current_user(UserRoleFactory::createAdmin());

        $results = AllowlistTestCustomerFilter::make(['with' => ['subscriptions']])->get();

        wp_set_current_user(0);

        $this->assertTrue(
            $results->first()->relationLoaded('subscriptions'),
            'A caller holding subscriptions/view gets the relation'
        );
    }

    // =========================================================================
    // Counts are ordinary callbacks
    // =========================================================================

    public function test_count_callback_registers_the_aggregate()
    {
        $customer = $this->customerFactory->create();
        $this->orderFactory->create(['customer_id' => $customer->id]);
        $this->orderFactory->create(['customer_id' => $customer->id]);

        $loaded = AllowlistTestCustomerFilter::make(['with' => ['ordersCount']])->get()->first();

        $this->assertArrayHasKey(
            'orders_count',
            $loaded->getAttributes(),
            'A count is a callback that calls withCount() — there is no second map'
        );
        $this->assertSame(2, (int)$loaded->getAttributes()['orders_count'], 'Both seeded orders are counted');
        $this->assertFalse(
            $loaded->relationLoaded('orders'),
            'Counting must not eager load the rows behind the count'
        );
    }

    public function test_count_callback_returning_false_drops_the_aggregate()
    {
        $this->customerFactory->create();

        $attributes = AllowlistTestCustomerFilter::make(['with' => ['labelsCount']])->get()->first()->getAttributes();

        $this->assertArrayNotHasKey(
            'labels_count',
            $attributes,
            'A count callback returning false drops the aggregate entirely'
        );
        $this->assertSame(
            1,
            AllowlistTestCustomerFilter::invocationCount('countRefused'),
            'One pass, one invocation'
        );
    }

    // =========================================================================
    // Nested paths
    // =========================================================================

    public function test_nested_path_callback_loads_both_segments_and_carries_the_parent_gate()
    {
        $customer = $this->customerFactory->create();
        $this->orderFactory->create(['customer_id' => $customer->id]);
        wp_set_current_user(UserRoleFactory::createAdmin());

        $eagerLoads = array_keys(
            AllowlistTestCustomerFilter::make(['with' => ['orders.customer']])->buildQuery()->getEagerLoads()
        );

        $this->assertContains('orders', $eagerLoads, 'with("a.b") registers the parent segment too');
        $this->assertContains('orders.customer', $eagerLoads, 'and the full path the callback named');

        wp_set_current_user(0);

        $denied = AllowlistTestCustomerFilter::make(['with' => ['orders.customer']])->buildQuery()->getEagerLoads();

        $this->assertSame(
            [],
            array_keys($denied),
            'The nested key repeats the parent gate itself, so refusing it takes the injected parent with it'
        );
    }

    /**
     * The one hazard this contract creates. Each callback issues its OWN with()
     * call, and Builder::with() array_merges into $eagerLoad. addNestedWiths()
     * refuses to clobber only within a single call, so a later entry that names
     * `orders.customer` injects an EMPTY closure for `orders` over the
     * constrained one an earlier entry registered.
     *
     * The rule for subclasses: re-state the parent constraint inside the nested
     * callback's own with() call. NestedPathTestCustomerFilter deliberately does
     * not, so this test stays red-by-design as documentation.
     */
    public function test_a_later_nested_entry_clobbers_an_earlier_parent_constraint()
    {
        $customer = $this->customerFactory->create();
        $this->orderFactory->create(['customer_id' => $customer->id]);

        $order = NestedPathTestCustomerFilter::make([
            'with' => ['orders', 'orders.customer'],
        ])->get()->first()->orders->first();

        $this->assertSame(
            1,
            NestedPathTestCustomerFilter::$parentCallbackCalls,
            'The parent callback did run'
        );
        $this->assertArrayHasKey(
            'total_amount',
            $order->getAttributes(),
            'Its select was nevertheless overwritten by the empty closure the nested entry injected. A nested '
            . 'callback MUST re-state the parent constraint in its own with() call — see '
            . 'AllowlistTestCustomerFilter::withOrdersCustomerGated()'
        );
    }

    // =========================================================================
    // Malformed input
    // =========================================================================

    public function test_non_string_with_entries_do_not_fatal()
    {
        $customer = $this->customerFactory->create();
        $this->orderFactory->create(['customer_id' => $customer->id]);

        $error = null;
        $results = null;
        try {
            $results = AllowlistTestCustomerFilter::make([
                'with' => ['orders', ['bad' => 'array'], 123, null],
            ])->get();
        } catch (\Throwable $exception) {
            $error = $exception;
        }

        $this->assertNull(
            $error,
            'with[foo][]=bar used to fatal inside Str::of(array). Got: '
            . ($error ? get_class($error) . ': ' . $error->getMessage() : '')
        );
        $this->assertTrue(
            $results->first()->relationLoaded('orders'),
            'The well-formed sibling entry still resolves'
        );
    }

    public function test_array_nested_with_form_does_not_bypass_the_allow_list()
    {
        $customer = $this->customerFactory->create();
        $this->orderFactory->create(['customer_id' => $customer->id]);

        // Builder::prepareNestedWithRelationships() understands with[orders][]=customer
        // as the nested path orders.customer. Dropping non-strings kills that form.
        $error = null;
        $results = null;
        try {
            $results = AllowlistTestCustomerFilter::make([
                'with' => ['orders' => ['customer']],
            ])->get();
        } catch (\Throwable $exception) {
            $error = $exception;
        }

        $this->assertNull(
            $error,
            'The array-nested with form must be discarded quietly. Got: '
            . ($error ? get_class($error) . ': ' . $error->getMessage() : '')
        );

        $loaded = $results->first();

        $this->assertFalse(
            $loaded->relationLoaded('orders'),
            'An array value is not a request key — nothing is resolved from it'
        );
        $this->assertFalse(
            $loaded->orders->first()->relationLoaded('customer'),
            'with[orders][]=customer must not smuggle orders.customer past the allow-list'
        );
    }

    // =========================================================================
    // Hooks
    // =========================================================================

    public function test_allowed_withs_filter_hook_can_extend_the_map()
    {
        $this->customerFactory->create();
        wp_set_current_user(0);

        // Without the hook an anonymous caller loses the permission-gated
        // `subscriptions` relation — that is the documented cron/WP-CLI caveat.
        $denied = AllowlistTestCustomerFilter::make(['with' => ['subscriptions']])->get()->first();

        $this->assertFalse(
            $denied->relationLoaded('subscriptions'),
            'Baseline: the gate refuses an anonymous caller'
        );

        $listener = function ($map) {
            $map['subscriptions'] = function ($query) {
                return $query->with('subscriptions');
            };
            return $map;
        };
        add_filter('fluent_cart/allowlist_test_customer_allowed_withs', $listener);

        $results = AllowlistTestCustomerFilter::make(['with' => ['subscriptions']])->get();

        remove_filter('fluent_cart/allowlist_test_customer_allowed_withs', $listener);

        $this->assertTrue(
            $results->first()->relationLoaded('subscriptions'),
            'The _allowed_withs filter is the escape valve for cron/CLI and add-ons — replacing the entry '
            . 'with an ungated callback lifts the gate'
        );
    }

    public function test_allowed_withs_filter_hook_receives_the_filter_in_its_context()
    {
        $this->customerFactory->create();

        $seen = null;
        $listener = function ($map, $context = null) use (&$seen) {
            $seen = $context;
            return $map;
        };
        add_filter('fluent_cart/allowlist_test_customer_allowed_withs', $listener, 10, 2);

        AllowlistTestCustomerFilter::make(['with' => ['orders']])->buildQuery();

        remove_filter('fluent_cart/allowlist_test_customer_allowed_withs', $listener, 10);

        $this->assertInstanceOf(
            AllowlistTestCustomerFilter::class,
            $seen['filter'],
            'The hook is 3-arg: the map, then a read-only context carrying the filter instance'
        );
    }
}
