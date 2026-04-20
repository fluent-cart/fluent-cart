<?php

namespace FluentCart\App\Modules\Shipping\Services\Filter;

use FluentCart\App\Models\ShippingClass;
use FluentCart\App\Services\Filter\BaseFilter;
use FluentCart\Framework\Database\Orm\Builder;

class ShippingClassFilter extends BaseFilter
{
    public function applySimpleFilter(?string $search = null): void
    {
        $search = $search ?? $this->search;
        if (!empty($search)) {
            $this->query->where(function (Builder $query) use ($search) {
                $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
                $query->where('name', 'LIKE', '%' . $escaped . '%');
            });
        }
    }

    public function tabsMap(): array
    {
        return [];
    }

    public function getModel(): string
    {
        return ShippingClass::class;
    }

    public static function getFilterName(): string
    {
        return 'shipping_classes';
    }

    public function applyActiveViewFilter(?string $activeView = null): void
    {
        // No tabs for shipping classes at this time
    }

    public static function getAdvanceFilterOptions(): ?array
    {
        return [
            'search' => [
                'type' => 'text',
                'label' => __('Search', 'fluent-cart')
            ],
            'type' => [
                'type' => 'selections',
                'label' => __('Type', 'fluent-cart'),
                'options' => [
                    'fixed' => __('Fixed', 'fluent-cart'),
                    'percentage' => __('Percentage', 'fluent-cart')
                ],
                'is_multiple' => true
            ]
        ];
    }
}