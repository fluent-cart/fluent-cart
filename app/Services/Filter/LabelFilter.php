<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\App\Models\Label;
use FluentCart\App\Models\Order;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class LabelFilter extends BaseFilter
{

    public function applySimpleFilter(?string $search = null): void
    {
        $this->query = $this->query->when($search ?? $this->search, function ($query, $search) {
            return $query->where('value', 'LIKE', "%{$search}%");
        });
    }

    public function tabsMap(): array
    {
        return [];
    }

    public function getModel(): string
    {
        return Label::class;
    }

    public static function getFilterName(): string
    {
        return 'label';
    }

    /**
     * Intentionally empty. This filter cannot receive a request `with` today:
     * its only caller is AdvanceFilterController::getFilterOption(), which
     * hand-builds a five-key argument array (remote_data_key, search,
     * include_ids, limit, parent_id) and never forwards the raw request.
     *
     * That is the reason the map is empty, NOT an oversight — if this filter is
     * ever refactored to take `$request->all()`, an empty map is what keeps it
     * closed, and any relation it then needs must be added here deliberately as
     * a context key mapped to a callable.
     *
     * @return array<string, callable>
     */
    protected function allowedWiths(): array
    {
        return [];
    }


    public function applyActiveViewFilter(?string $activeView = null): void
    {

    }

    public static function getSelectFilterOptions(array $args): array
    {
        return static::make($args)->get()->pluck('value', 'id')->toArray();
    }

    public static function advanceFilterOptions()
    {
        return null;
    }

    public static function advanceFilterOptionsForOther($otherFilters = []): array
    {
        return array_merge(
            $otherFilters,
            [
                'labels' => [
                    'label'    => __('Labels', 'fluent-cart'),
                    'value'    => 'labels',
                    'children' => [
                        [
                            'label'           => __('Label Name', 'fluent-cart'),
                            'value'           => 'customer_email',
                            'type'            => 'selections',
                            'filter_type'     => 'relation',
                            'column'          => 'label_id',
                            'relation'        => 'labels',
                            'remote'          => true,
                            'remote_data_key' => 'labels',
                            'is_multiple' => true
                        ]
                    ]
                ]
            ]
        );
    }
}