<?php

namespace FluentCart\App\Modules\Shipping\Services\Filter;

use FluentCart\App\Models\ShippingZone;
use FluentCart\App\Services\Filter\BaseFilter;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class ShippingZoneFilter extends BaseFilter
{
    public function applySimpleFilter(?string $search = null): void
    {
        $search = $search ?? $this->search;
        if (!empty($search)) {
            $escaped = str_replace(['%', '_'], ['\\%', '\\_'], $search);
            $this->query->whereLike('name', $escaped);
        }

        $request = \FluentCart\App\App::request();
        $classId = $request->get('shipping_class_id', '__not_set__');
        if ($classId !== '__not_set__') {
            if ($classId && $classId !== '0') {
                $this->query->where('shipping_class_id', (int) $classId);
            } else {
                $this->query->whereNull('shipping_class_id');
            }
        }

        $request = \FluentCart\App\App::request();
        $classId = $request->get('shipping_class_id', '__not_set__');
        if ($classId !== '__not_set__') {
            if ($classId && $classId !== '0') {
                $this->query->where('shipping_class_id', (int) $classId);
            } else {
                $this->query->whereNull('shipping_class_id');
            }
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

    public function applyActiveViewFilter(?string $activeView = null): void
    {
        // No active view filters for shipping zones
    }

    public function tabsMap(): array
    {
        return [];
    }
}