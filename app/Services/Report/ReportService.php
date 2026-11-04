<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\BaseCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\BaseAmountSql;
use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Database\Query\Builder as Query;
use FluentCart\Framework\Support\DateTime;

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
            $this->filters['currency'] = BaseCurrency::get();
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
            'paymentMode'   => null,
        ];

        $filters = wp_parse_args($filters, $defaults);

        $query->whereBetween("o.created_at", [
            $filters['startDate'], $filters['endDate']
        ])
        // The ALL mode reads a derived table that already holds only
        // converted, rate-trusted orders (ReportCurrency::ordersTable)
        ->when($filters['currency'] && $filters['currency'] !== ReportCurrency::ALL, fn($q) =>
            $q->where("o.currency", $filters['currency'])
        )
        ->when($filters['paymentStatus'], fn($q) => 
            $q->whereIn("o.payment_status", $filters['paymentStatus'])
        )
        // Live or test orders only; reports count both unless asked
        ->when(in_array($filters['paymentMode'], ['live', 'test'], true), fn($q) =>
            $q->where("o.mode", $filters['paymentMode'])
        )
        ->when($filters['orderStatus'], fn($q) => 
            $q->whereNotIn("o.status", $filters['orderStatus'])
        )
        // Sale types: renewal leaves out installment payments, which have their own types
        ->when($filters['orderTypes'], fn($q) =>
            $q->whereRaw(ReportHelper::saleTypeSql() . ' IN (' . implode(', ', array_fill(0, count((array) $filters['orderTypes']), '?')) . ')', array_values((array) $filters['orderTypes']))
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

        // Bucket keys are store days, months and years
        $startDate = ReportHelper::local($startDate);
        $endDate = ReportHelper::local($endDate);

        // force include the end date in the period to support php < 8.0
        $endDate = $this->clampToToday($endDate, $groupKey)->add(new \DateInterval('PT1S'));

        $periodStart = $startDate->copy();
        if ($groupKey === 'monthly') {
            $periodStart->startOfMonth();
        } else if ($groupKey === 'yearly') {
            $periodStart->startOfYear();
        }

        $period = new \DatePeriod($periodStart, new \DateInterval($interval), $endDate);

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

    // Clamp to the end of the current bucket, not just today, so a range
    // starting mid-month (or mid-year) still yields the current bucket.
    protected function clampToToday($endDate, $groupKey = 'daily')
    {
        // "Today" and its month are the store's; the result keeps the caller's zone, since a UTC end gets bound
        $today = ReportHelper::local(DateTime::now())->endOfDay();

        if ($endDate < $today) {
            return $endDate->copy();
        }

        $limit = $today->copy();

        if ($groupKey === 'monthly') {
            $limit = $limit->endOfMonth();
        } else if ($groupKey === 'yearly') {
            $limit = $limit->endOfYear();
        }

        return $limit->setTimezone($endDate->getTimezone());
    }

    // Chart queries and their zero-filled buckets must share this end date,
    // or a future-dated row lands in a bucket after today.
    protected function clampParamsToToday(array $params)
    {
        // Auto grouping follows the requested range, not the clamped one.
        if (empty($params['groupKey']) || $params['groupKey'] === 'default') {
            $params['groupKey'] = ReportHelper::defineGroupKey($params['startDate'], $params['endDate']);
        }

        $params['endDate'] = $this->clampToToday($params['endDate']);

        return $params;
    }

    /**
     * Per-order discount split (ProductReportService::orderDiscountSplitQuery()) for the orders in scope, so line
     * amounts read net of order-level discounts and upgrade credits, as on the Products page.
     */
    protected function orderDiscountsQuery(array $params)
    {
        $converted = ReportCurrency::isConverted($params);
        [$trusted, $bindings] = BaseAmountSql::trustedRate('o.');

        $orders = $this->applyFilters(App::db()->table('fct_orders as o'), $params)
            ->when($converted, fn($q) => $q->whereRaw("{$trusted} = 1", $bindings));

        return ProductReportService::orderDiscountSplitQuery($orders, $converted);
    }

    /**
     * Percent change per key, null when there was nothing before: no change, rather than a made-up +100%.
     *
     * @param string[] $keys
     */
    protected function percentChanges(array $current, array $previous, array $keys): array
    {
        $changes = [];
        foreach ($keys as $key) {
            $before = $previous[$key] ?? 0;
            $changes[$key] = $before > 0 ? ((($current[$key] ?? 0) - $before) / $before) * 100 : null;
        }

        return $changes;
    }
}
