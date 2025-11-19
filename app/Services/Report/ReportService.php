<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\Api\CurrencySettings;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Database\Query\Builder as Query;

abstract class ReportService
{
    protected $data = null;

    protected $selects = '*';

    protected array $filters = [];

    protected bool $loaded = false;

    protected array $amountColumns = [];

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
        if (!isset($this->filters['currency']) || empty($this->filters['currency'])) {
            $this->filters['currency'] = CurrencySettings::get()['currency'];
        }
    }

    public function getModel(): string
    {
        return '';
    }

    protected function prepareReportData(): void
    {

    }

    public function setAmountColumns(array $columns)
    {
        $this->amountColumns = $columns;
        return $this;
    }

    public function mergeAmountColumns(array $columns)
    {
        $this->amountColumns = array_merge(
            $this->amountColumns,
            $columns
        );
        return $this;
    }

    public function getAmountColumns(): array
    {
        return $this->amountColumns;
    }

    public static function make(array $filters = [])
    {
        return new static($filters);
    }

    public function setSelects($selects)
    {
        $this->selects = $selects;
        return $this;
    }

    protected function getFilters(): array
    {
        return $this->filters ?? [];
    }

    protected function buildQuery(): Builder
    {
        return $this->getModel()::search($this->getFilters())
            ->select($this->selects);
    }

    protected function modifyQuery(Builder $query): Builder
    {
        return $query;
    }

    protected function transformAmountColumns()
    {
        if (!empty($this->amountColumns)) {
            $this->data = $this->data->transform(function ($data) {
                foreach ($this->amountColumns as $column) {
                    $columnName = is_array($column) ? $column['name'] : $column;
                    // $withCurrency = is_array($column) ? $column['with_currency'] : false;
                    // $currency = is_array($column) ? $column['currency_sign'] : null;
                    if (is_array($data)) {
                        $data[$columnName] = Helper::toDecimalWithoutComma($data[$columnName]);
                    } else {
                        $data->{$columnName} = Helper::toDecimalWithoutComma($data[$columnName]);
                    }
                }
                return $data;
            });
        }
    }

    public function getOriginalData()
    {
        return $this->data;
    }


    public function generate()
    {
     

        $this->data = $this->modifyQuery(
            $this->buildQuery()
        )->get();

        $this->transformAmountColumns();
        $this->prepareReportData();

        $this->loaded = true;
        return $this;
    }


    /**
     * Begin a fluent query against a database table.
     * @return \FluentCart\Framework\Database\Query\Builder
     */
    public function addFiltersToQuery($query, $filters, $table = null): Query
    {
        foreach ($filters as $key => $value) {
            if ($table) {
                $key = $table . '.' . $key;
            }

            if (is_array($value)) {
                $query->whereIn($key, $value);
            } else if (!empty($value) && $value !== 'all') {
                $query->where($key, $value);
            }
        }

        return $query;
    }

    public function applyFilters(Query $query, array $filters): Query
    {
        $defaults = [
            'currency'      => null,
            'orderTypes'    => null,
            'orderStatus'   => null,
            'variationIds'  => null,
            'paymentStatus' => null,
        ];

        $filters = wp_parse_args($filters, $defaults);

        $query->whereBetween("o.created_at", [
            $filters['startDate'], $filters['endDate']
        ])
        ->when($filters['currency'], fn($q) => 
            $q->where("o.currency", $filters['currency'])
        )
        ->when($filters['paymentStatus'], fn($q) => 
            $q->whereIn("o.payment_status", $filters['paymentStatus'])
        )
        ->when($filters['orderStatus'], fn($q) => 
            $q->whereNotIn("o.status", $filters['orderStatus'])
        )
        ->when($filters['orderTypes'], fn($q) => 
            $q->whereIn("o.type", $filters['orderTypes'])
        )
        ->when($filters['variationIds'], fn($q) => 
            $q->whereExists(fn($q) =>
                $q->selectRaw('1')
                  ->from('fct_order_items as oi')
                  ->whereRaw("oi.order_id = o.id")
                  ->whereIn('oi.object_id', $filters['variationIds'])
            )
        )
        // ->where('o.payment_method', '!=', 'manual_purchases')
        ;

        return $query;
    }

    protected function getPeriodRange($startDate, $endDate, $groupKey, $keys = [])
    {   
        $interval = 'P1D';

        if ($groupKey === 'monthly') {
            $interval = 'P1M';
        } else if ($groupKey === 'yearly') {
            $interval = 'P1Y';
        }

        // force include the end date in the period to support php < 8.0
        $endDate = $endDate->copy()->add(new \DateInterval('PT1S'));

        $period = new \DatePeriod($startDate, new \DateInterval($interval), $endDate);

        $range = [];

        foreach ($period as $date) {
            $year = $date->format('Y');

            if ($groupKey === 'daily') {
                $group = $date->format('Y-m-d');
            } else if ($groupKey === 'monthly') {
                $group = $date->format('Y-m');
            } else {
                $group = $date->format('Y');
            }

            $range[$group] = array_merge(
                [
                    'year'  => $year,
                    'group' => $group,
                ],
                array_fill_keys($keys, 0)
            );
        }

        return $range;
    }

    public function getFutureInstallments($params = [])
    {
        $query = App::db()->table('fct_subscriptions as s')
            ->selectRaw("SUM((s.bill_times - s.bill_count) * s.recurring_total) / 100 AS amount")
            ->join('fct_orders as o', 'o.id', '=', 's.parent_order_id')
            ->where('s.bill_times', '>', 0)
            ->where('s.bill_count', '<', App::db()->raw('s.bill_times'));

        $query = $this->applyFilters($query, $params);

        return $query->first()->amount;
    }
}
