<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\App\Models\Label;
use FluentCart\App\Models\Order;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class LabelFilter extends BaseFilter
{

    public function applySimpleFilter()
    {
        $this->query = $this->query->when($this->search, function ($query, $search) {
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


    public function applyActiveViewFilter()
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