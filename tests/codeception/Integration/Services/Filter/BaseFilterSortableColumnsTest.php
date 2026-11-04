<?php

namespace Tests\Integration\Services\Filter;

use FluentCart\App\Services\Filter\OrderFilter;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * Sort options for the admin tables are declared server side the same way the
 * advanced filter options and the custom columns are: the filter class ships
 * the defaults, add-ons append through `fluent_cart/{filterName}_table_sorts`,
 * and the result reaches the Vue table on `table_config.{table}.filters.sorts`.
 *
 * Each entry is `value => ['label' => ..., 'column' => 'column'|callable]`. The
 * column stays server side — the frontend only ever receives `value => label`.
 * A callable takes the ordering over entirely, which is how a sort that is not
 * a plain column on this table gets applied.
 *
 * The same map is the allow-list parseSortBy() validates `sort_by` against,
 * without which a registered option would render in the popover and then be
 * silently dropped back to the default column.
 *
 * These tests run against the disposable test database only.
 */
class BaseFilterSortableColumnsTest extends DatabaseTestCase
{
    private OrderFactory $orderFactory;

    private CustomerFactory $customerFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->orderFactory = new OrderFactory();
        $this->customerFactory = new CustomerFactory();
    }

    public function tearDown(): void
    {
        remove_all_filters('fluent_cart/orders_table_sorts');
        parent::tearDown();
    }

    // =========================================================================
    // Declaration
    // =========================================================================

    public function test_the_filter_class_ships_its_own_sort_defaults()
    {
        $columns = OrderFilter::getSortableColumns();

        $this->assertArrayHasKey('id', $columns);
        $this->assertArrayHasKey('total_amount', $columns);
        $this->assertSame('total_amount', $columns['total_amount']['column']);
    }

    public function test_the_frontend_payload_is_a_value_to_label_map()
    {
        $sorts = OrderFilter::getTableFilterOptions()['sorts'];

        $this->assertSame('Order ID', $sorts['id']);
        $this->assertSame('Total', $sorts['total_amount']);
    }

    public function test_a_callable_column_never_reaches_the_frontend_payload()
    {
        $this->registerBestSellerSort();

        $sorts = OrderFilter::getSortOptions();

        $this->assertSame('Best Selling', $sorts['best_seller']);
        foreach ($sorts as $label) {
            $this->assertIsString($label);
        }
    }

    // =========================================================================
    // sort_by validation
    // =========================================================================

    public function test_a_registered_option_survives_sort_by_validation()
    {
        // updated_at is a real orders column but deliberately NOT in
        // Order::$fillable, so only the declared allow-list can admit it.
        add_filter('fluent_cart/orders_table_sorts', function ($sorts) {
            $sorts['updated_at'] = ['label' => 'Last Updated', 'column' => 'updated_at'];
            return $sorts;
        });

        $filter = OrderFilter::make(['sort_by' => 'updated_at']);

        $this->assertSame('updated_at', $filter->sortBy);
    }

    public function test_an_unregistered_column_still_falls_back_to_the_default()
    {
        $filter = OrderFilter::make(['sort_by' => 'not_a_column']);

        $this->assertSame('id', $filter->sortBy);
    }

    // =========================================================================
    // Ordering
    // =========================================================================

    public function test_a_declared_option_orders_by_its_column()
    {
        $cheap = $this->makeOrder(['total_amount' => 1000]);
        $expensive = $this->makeOrder(['total_amount' => 900000]);

        $ids = $this->sortedIds(['sort_by' => 'total_amount', 'sort_type' => 'DESC']);

        $this->assertLessThan(
            array_search($cheap->id, $ids, true),
            array_search($expensive->id, $ids, true)
        );
    }

    public function test_a_callable_column_applies_its_own_ordering()
    {
        $low = $this->makeOrder(['total_paid' => 500]);
        $high = $this->makeOrder(['total_paid' => 50000]);

        $this->registerBestSellerSort();

        $ids = $this->sortedIds(['sort_by' => 'best_seller', 'sort_type' => 'DESC']);

        $this->assertLessThan(
            array_search($low->id, $ids, true),
            array_search($high->id, $ids, true)
        );
    }

    public function test_a_callable_that_orders_in_place_still_returns_rows()
    {
        $order = $this->makeOrder();

        add_filter('fluent_cart/orders_table_sorts', function ($sorts) {
            $sorts['in_place'] = [
                'label'  => 'In Place',
                'column' => function ($query, $direction) {
                    // Mutates the builder and returns nothing on purpose.
                    $query->orderBy('id', $direction);
                },
            ];
            return $sorts;
        });

        $ids = $this->sortedIds(['sort_by' => 'in_place']);

        $this->assertContains($order->id, $ids);
    }

    public function test_a_callable_returning_a_non_builder_does_not_replace_the_query()
    {
        $order = $this->makeOrder();

        add_filter('fluent_cart/orders_table_sorts', function ($sorts) {
            $sorts['bad_return'] = [
                'label'  => 'Bad Return',
                'column' => function ($query, $direction) {
                    $query->orderBy('id', $direction);
                    return 'not a builder';
                },
            ];
            return $sorts;
        });

        $ids = $this->sortedIds(['sort_by' => 'bad_return']);

        $this->assertContains($order->id, $ids);
    }

    public function test_an_empty_column_falls_back_to_the_sort_by_value()
    {
        $order = $this->makeOrder();

        add_filter('fluent_cart/orders_table_sorts', function ($sorts) {
            $sorts['created_at'] = ['label' => 'Date', 'column' => ''];
            return $sorts;
        });

        $ids = $this->sortedIds(['sort_by' => 'created_at']);

        $this->assertContains($order->id, $ids);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function registerBestSellerSort(): void
    {
        add_filter('fluent_cart/orders_table_sorts', function ($sorts) {
            $sorts['best_seller'] = [
                'label'  => 'Best Selling',
                'column' => function ($query, $direction) {
                    return $query->orderBy('total_paid', $direction);
                },
            ];
            return $sorts;
        });
    }

    private function makeOrder(array $overrides = [])
    {
        $customer = $this->customerFactory->create();

        return $this->orderFactory->create(
            array_merge(['customer_id' => $customer->id], $overrides)
        );
    }

    private function sortedIds(array $args): array
    {
        $rows = OrderFilter::make($args)->get();

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row->id;
        }

        return $ids;
    }
}
