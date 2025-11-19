<?php

namespace FluentCart\App\Modules\Shipping\Services\Filter;

use FluentCart\App\Models\ShippingZone;
use FluentCart\App\Services\Filter\BaseFilter;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class ShippingZoneFilter extends BaseFilter
{
    public function applySimpleFilter()
    {
        if (!empty($this->search)) {
            $this->query->whereLike('name', $this->search);
        }
    }

    public function getModel(): string
    {
        return ShippingZone::class;
    }

    public static function getFilterName(): string
    {
        return 'shipping_zones';
    }

    protected function defaultSorting(): array
    {
        return [
            'column'    => 'order',
            'direction' => 'ASC'
        ];
    }

    public static function getAdvanceFilterOptions(): ?array
    {
        return [
            'search' => [
                'type'  => 'text',
                'label' => __('Search', 'fluent-cart')
            ]
        ];
    }

    public function applyActiveViewFilter()
    {
        // TODO: Implement applyActiveViewFilter() method.
    }

    public function tabsMap(): array
    {
        return [
            'publish'          => 'post_status',
            'draft'            => 'post_status',
            'physical'         => 'fulfillment_type',
            'digital'          => 'fulfillment_type',
            'subscribable'     => 'has_subscription',
            'not_subscribable' => 'has_subscription',
        ];
    }
}