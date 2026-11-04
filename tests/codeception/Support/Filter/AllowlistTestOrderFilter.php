<?php

namespace Tests\Support\Filter;

use FluentCart\App\Models\Order;
use FluentCart\App\Services\Filter\BaseFilter;

/**
 * Test-only filter over the Order model. Used to prove that a public model
 * method that mutates and saves (Order::recountTotalPaidAndRefund) can no
 * longer be invoked through the `with` request parameter.
 */
class AllowlistTestOrderFilter extends BaseFilter
{
    protected function allowedWiths(): array
    {
        return [
            'customer' => [$this, 'withCustomer'],
        ];
    }

    public function withCustomer($query)
    {
        return $query->with('customer');
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
        return Order::class;
    }

    public static function getFilterName(): string
    {
        return 'allowlist_test_order';
    }
}
