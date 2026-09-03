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

        $params = \FluentCart\App\App::request()->all();
        if (array_key_exists('shipping_class_id', $params)) {
            $classId = $params['shipping_class_id'];
            if ($classId && $classId !== '0') {
                $this->query->where('shipping_class_id', (int) $classId);
            } else {
                $this->query->where(function ($q) {
                    $q->whereNull('shipping_class_id')->orWhere('shipping_class_id', 0);
                });
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

    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function sortableColumns(): array
    {
        return [
            'name'  => ['label' => __('Zone Name', 'fluent-cart'), 'column' => 'name'],
            'order' => ['label' => __('Order', 'fluent-cart'), 'column' => 'order'],
        ];
    }

    /**
     * SCREEN key — `admin_shipping_zone_list` is what ShippingZoneTable.js
     * sends, and a method COUNT is the whole of what that screen renders.
     *
     * PUBLIC key — `methodsCount` is the name the client sent before this
     * allow-list existed, so it stays an accepted value rather than becoming a
     * silently ignored one for anybody else who was already sending it.
     *
     * A count is not a separate mechanism: both entries are callbacks that call
     * withCount(). The method ROWS themselves stay unlisted — no caller renders
     * them, and the number is not the rows.
     *
     * No further permission bar on either entry: the whole `shipping` route
     * group mounts under `StoreSensitivePolicy` (`store/sensitive`), so a caller
     * here is already trusted with shipping configuration.
     *
     * @return array<string, callable>
     */
    protected function allowedWiths(): array
    {
        return [
            'admin_shipping_zone_list' => [$this, 'adminShippingZoneList'],

            'methodsCount' => [$this, 'publicMethodsCount'],
        ];
    }

    /**
     * `GET /shipping/zones`, sent by ShippingZoneTable.js — ShippingZonesTable.vue
     * renders the resulting `methods_count` attribute.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    protected function adminShippingZoneList($query)
    {
        return $query->withCount('methods');
    }

    /**
     * `with[]=methodsCount` — a public entry point, covered by the route
     * group's own `store/sensitive` policy.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    protected function publicMethodsCount($query)
    {
        return $query->withCount('methods');
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