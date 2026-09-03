<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\App\App;
use FluentCart\Framework\Support\Str;
use FluentCart\App\Models\OrderTaxRate;
use FluentCart\App\Services\Localization\LocalizationManager;

class TaxFilter extends BaseFilter
{
    public function applySimpleFilter(?string $search = null): void
    {
        $isApplied = $this->applySimpleOperatorFilter($search);

        if ($isApplied) {
            return;
        }

        $this->query->when($search ?? $this->search, function ($query, $search) {
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

    /**
     * @return array<string, array<string, mixed>>
     */
    protected static function sortableColumns(): array
    {
        return [
            'id' => ['label' => __('ID', 'fluent-cart'), 'column' => 'id'],
        ];
    }

    /**
     * `GET /taxes` mounts under `AdminPolicy` with no per-route permission, so
     * a caller here is already a super admin and no entry on this map needs a
     * further bar of its own.
     *
     * SCREEN key — `admin_tax_report` loads exactly what the report prints, and
     * that is why it keeps the narrow `order` select: the report shows a
     * currency, and a whole order row per line is both wasteful and more than
     * the screen asked for.
     *
     * PUBLIC keys — `tax_rate` and `order` are the two relation names a
     * consumer can reasonably ask a tax-report endpoint for. They give the
     * plain, unnarrowed relation; the screen key's select is a property of that
     * screen, not of the endpoint's published shape.
     *
     * @return array<string, callable>
     */
    protected function allowedWiths(): array
    {
        return [
            'admin_tax_report' => [$this, 'adminTaxReport'],

            'tax_rate' => [$this, 'publicTaxRate'],
            'order'    => [$this, 'publicOrder'],
        ];
    }

    /**
     * `GET /taxes`, sent by TaxesTable.js — the rate the row was charged at and
     * the order's currency.
     *
     * No select on `tax_rate`: the report prints the rate's country, state,
     * postcode and name, and the advanced filter matches on the same row. The
     * `order` select lives INSIDE the relation closure — applied to `$query` it
     * would narrow the tax-rate query itself, which is a different table.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    protected function adminTaxReport($query)
    {
        return $query->with([
            'tax_rate',
            'order' => function ($orderQuery) {
                $orderQuery->select(['id', 'currency']);
            },
        ]);
    }

    /**
     * `with[]=tax_rate` — a public entry point. Covered by the route's own
     * AdminPolicy, which admits nobody below a super admin.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    protected function publicTaxRate($query)
    {
        return $query->with(['tax_rate']);
    }

    /**
     * `with[]=order` — a public entry point, covered by the same AdminPolicy.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    protected function publicOrder($query)
    {
        return $query->with(['order']);
    }

    /**
     * Sent by TaxesTable.js so the tax report only counts rows whose order was
     * actually paid.
     *
     * @return array<string, callable>
     */
    protected function allowedScopes(): array
    {
        return [
            'validOrder' => [$this, 'validOrderScope'],
        ];
    }

    /**
     * Declared `($query)` on purpose: the array form of a `scopes` entry would
     * otherwise hand this callback raw client arguments, and OrderTaxRate's
     * `scopeValidOrder()` takes none.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    protected function validOrderScope($query)
    {
        return $query->scopes(['validOrder']);
    }

    public function applyActiveViewFilter(?string $activeView = null): void
    {
        $activeView = $activeView ?? $this->activeView;
        if (!$activeView) {
            return;
        }

        $whereMethod = $activeView === 'filed' ? 'whereNotNull' : 'whereNull';

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
