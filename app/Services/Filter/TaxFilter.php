<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\App\App;
use FluentCart\Framework\Support\Str;
use FluentCart\App\Models\OrderTaxRate;
use FluentCart\App\Services\Localization\LocalizationManager;

class TaxFilter extends BaseFilter
{
    public function applySimpleFilter()
    {
        $isApplied = $this->applySimpleOperatorFilter();

        if ($isApplied) {
            return;
        }

        $this->query->when($this->search, function ($query, $search) {
            return $query
                ->where(function ($query) use ($search) {
                    $search = trim($search);
                    $search = Str::of($search)->remove('#')->toString();

                    $query->when(
                        is_numeric($search),
                        fn ($query) => $query->where('id', $search)->orWhere('order_id', $search)
                    )->orWhereHas(
                        'tax_rate',
                        fn ($query) => $query->where('country', $search)
                            ->orWhere('state', $search)
                            ->orWhere('postcode', $search)
                            ->orWhere('name', 'LIKE', "%{$search}%")
                    );
                });
        });
    }

    public function tabsMap(): array
    {
        return [
            'filed'     => 'filed_at',
            'not_filed' => 'filed_at',
        ];
    }

    public function getModel(): string
    {
        return OrderTaxRate::class;
    }

    public static function getFilterName(): string
    {
        return 'taxes';
    }

    public function applyActiveViewFilter()
    {
        if (!$this->activeView) {
            return;
        }

        $whereMethod = $this->activeView === 'filed' ? 'whereNotNull' : 'whereNull';

        $this->query->{$whereMethod}('filed_at');
    }

    public static function advanceFilterOptions(): array
    {
        return [
            'tax_rates' => [
                'label'    => __('Tax Property', 'fluent-cart'),
                'value'    => 'tax_rates',
                'children' => [
                    [
                        'label'           => __('Country', 'fluent-cart'),
                        'value'           => 'country',
                        'column'          => 'country',
                        'filter_type'     => 'relation',
                        'relation'        => 'tax_rate',
                        'remote_data_key' => 'countries',
                        'type'            => 'selections',
                        'is_multiple'     => true,
                        'options'         => TaxFilter::getCountriesOptions(),
                    ],
                    [
                        'label'           => __('Region', 'fluent-cart'),
                        'value'           => 'region',
                        'column'          => 'state',
                        'filter_type'     => 'relation',
                        'relation'        => 'tax_rate',
                        'remote_data_key' => 'tax_rate_states',
                        'type'            => 'selections',
                        'options'         => static::getStatesOptions(),
                        'is_multiple'     => true,
                        'limit'           => 10,
                    ],
                    [
                        'label'       => __('Tax Name', 'fluent-cart'),
                        'value'       => 'name',
                        'column'      => 'name',
                        'filter_type' => 'relation',
                        'relation'    => 'tax_rate',
                        'type'        => 'text',
                    ],
                    [
                        'label'       => __('Filed', 'fluent-cart'),
                        'value'       => 'filed',
                        'column'      => 'filed_at',
                        'filter_type' => 'custom',
                        'type'        => 'selections',
                        'options'     => [
                            'filed'     => __('Filed', 'fluent-cart'),
                            'not_filed' => __('Not Filed', 'fluent-cart'),
                        ],
                        'callback' => function ($query, $data) {
                            if ($data === 'filed') {
                                $query->whereNotNull('filed_at');
                            } else {
                                $query->whereNull('filed_at');
                            }
                        },
                    ],
                ],
            ],
        ];
    }

    public static function getCountriesOptions()
    {
        return LocalizationManager::getInstance()->countryIsoList();
    }

    public static function getStatesOptions()
    {
        $statesFromDB = App::db()->table('fct_tax_rates')
            ->select(['country', 'state'])
            ->whereNotNull('state')
            ->where('state', '!=', '')
            ->groupBy(['country', 'state'])
            ->get();

        $states = LocalizationManager::getInstance()->states();

        $statesList = [];

        foreach ($statesFromDB as $state) {
            if (isset($states[$state->country]) && isset($states[$state->country][$state->state])) {
                $statesList[$state->state] = $states[$state->country][$state->state];
            }
        }

        return $statesList;
    }
}
