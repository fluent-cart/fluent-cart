<?php

namespace FluentCart\App\Services\Report;

use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Modules\MultiCurrency\Services\ReportCurrency;
use FluentCart\Framework\Support\Arr;

/**
 * Orders, customers and money by country, state or city.
 *
 * An order's location: its latest address of the chosen type (shipping falls back to billing), else for a renewal
 * its parent order's billing address, else the customer record's country (country only), else Unknown.
 */
class LocationReportService extends ReportService
{
    public const LEVELS = ['country', 'state', 'city'];

    public const UNKNOWN = '__unknown__';

    // A state holding at least this share of a country's located orders, and 3× the next one, gets a note
    private const OUTSIZED_SHARE = 50;

    /**
     * @param array $params processed report params plus level (country|state|city), country, state, address_type, with_summary
     * @return array{rows: array, totals: array, sources: array, outsized: string|null, level: string, summary: array,
     *     previous: ?array, movers: ?array}
     */
    public function getReport(array $params): array
    {
        // States, cities, lifetime value and the biggest movers are FluentCart Pro reports
        return $this->report(array_merge($params, ['level' => 'country']), false);
    }

    /**
     * @param bool $full with lifetime value, repeat rate and movers, at any level
     */
    protected function report(array $params, bool $full): array
    {
        $level = in_array(Arr::get($params, 'level'), self::LEVELS, true) ? $params['level'] : 'country';
        $shipping = Arr::get($params, 'address_type') === 'shipping';
        $previousParams = !empty($params['comparePeriod'])
            ? array_merge($params, ['startDate' => $params['comparePeriod'][0], 'endDate' => $params['comparePeriod'][1]])
            : null;

        $current = $this->locate($params, $level, $shipping, $full);
        $previous = $previousParams ? $this->locate($previousParams, $level, $shipping, false) : null;
        $previousNet = [];
        foreach ($previous ? $previous['rows'] : [] as $row) {
            $previousNet[self::match($row['key'])] = $row['net_sales'];
        }
        foreach ($current['rows'] as &$row) {
            $before = $previous ? (float) ($previousNet[self::match($row['key'])] ?? 0) : null;
            $row['previous_net'] = $before;
            $row['net_change'] = $before ? round(($row['net_sales'] - $before) / abs($before) * 100, 1) : null;
        }
        unset($row);

        // The cards are store-wide, so a drill-down reads every country again only when asked (after a filter change)
        $withSummary = $level === 'country' || !empty($params['with_summary']);
        $countries = $withSummary ? ($level === 'country' ? $current : $this->locate($params, 'country', $shipping, false)) : null;
        $previousCountries = $withSummary && $previousParams
            ? ($level === 'country' ? $previous : $this->locate($previousParams, 'country', $shipping, false))
            : null;
        $storeCountry = strtoupper((string) (new StoreSettings())->get('store_country', ''));

        return [
            'level'    => $level,
            'rows'     => $current['rows'],
            'totals'   => $current['totals'],
            'sources'  => ['parent_order' => $current['totals']['via_parent'], 'customer_record' => $current['totals']['via_customer']],
            'outsized' => $level === 'state' ? $this->outsizedState($current['rows']) : null,
            'summary'  => $countries ? $this->summary($countries, $storeCountry) : null,
            'previous' => $previousCountries ? $this->summary($previousCountries, $storeCountry) : null,
            'movers'   => $full && $previous ? $this->movers($current['rows'], $previous['rows']) : null,
            // Whether this is the full report (states, cities, lifetime value, movers), which FluentCart Pro serves
            'full'     => $full,
        ];
    }

    /**
     * Rows and totals at one level. With $withLifetime, each row also gets the lifetime value and repeat rate
     * of the new customers it won.
     */
    private function locate(array $params, string $level, bool $shipping, bool $withLifetime): array
    {
        // One address row per order: the first row with a country wins for every field, so a renewal's own address
        // is never mixed with its parent's
        $address = function ($field) use ($shipping) {
            return "(CASE" . ($shipping ? " WHEN COALESCE(sa.country, '') <> '' THEN sa.{$field}" : '') . "
                WHEN COALESCE(ba.country, '') <> '' THEN ba.{$field}
                WHEN COALESCE(pa.country, '') <> '' THEN pa.{$field} END)";
        };
        $country = "COALESCE(NULLIF(" . $address('country') . ", ''), NULLIF(c.country, ''))";
        $hasOwn = $shipping ? "(COALESCE(sa.country, '') <> '' OR COALESCE(ba.country, '') <> '')" : "COALESCE(ba.country, '') <> ''";
        $source = "(CASE WHEN {$hasOwn} THEN 'order' WHEN COALESCE(pa.country, '') <> '' THEN 'parent'
            WHEN COALESCE(c.country, '') <> '' THEN 'customer' ELSE 'unknown' END)";

        $keys = [
            'country' => "UPPER({$country})",
            'state'   => "NULLIF(TRIM(" . $address('state') . "), '')",
            'city'    => "NULLIF(TRIM(" . $address('city') . "), '')",
        ];
        $locKey = "COALESCE({$keys[$level]}, '" . self::UNKNOWN . "')";

        $scoped = function () use ($params, $shipping, $level, $country, $keys) {
            $query = $this->locatedOrders($params, $shipping);
            if ($level !== 'country') {
                $query->whereRaw("{$country} = ?", [(string) Arr::get($params, 'country')]);
            }
            if ($level === 'city') {
                $state = (string) Arr::get($params, 'state');
                $state === self::UNKNOWN ? $query->whereRaw("{$keys['state']} IS NULL") : $query->whereRaw("{$keys['state']} = ?", [$state]);
            }

            return $query;
        };

        $products = !empty($params['variationIds']);
        $gross = $products ? 'COALESCE(ps.paid, 0)' : 'o.total_paid';
        $refund = $products ? 'COALESCE(ps.refund, 0)' : 'o.total_refund';
        $net = $products ? 'COALESCE(ps.net, 0)' : ReportHelper::netSalesSql($params);
        // New is the shared first-purchase rule, as on the Customers report; a renewal is its own type
        $type = "(CASE WHEN o.type = '" . Status::ORDER_TYPE_RENEWAL . "' THEN 'renewal' WHEN o.customer_id IS NULL THEN 'guest'
            WHEN fpn.id IS NOT NULL THEN 'new' ELSE 'returning' END)";

        $rows = $scoped()
            ->selectRaw("{$locKey} AS loc_key,
                COUNT(DISTINCT o.id) AS orders,
                COUNT(DISTINCT o.customer_id) AS customers,
                COUNT(DISTINCT CASE WHEN {$type} = 'new' THEN o.customer_id END) AS new_customers,
                -- One kind per customer, as on the Customers report: new wins over returning
                COUNT(DISTINCT CASE WHEN {$type} IN ('new', 'returning') THEN o.customer_id END)
                    - COUNT(DISTINCT CASE WHEN {$type} = 'new' THEN o.customer_id END) AS returning_customers,
                SUM({$type} = 'new') AS new_orders,
                SUM({$type} = 'returning') AS returning_orders,
                SUM({$type} = 'renewal') AS renewal_orders,
                SUM({$refund} > 0) AS refunded_orders,
                SUM({$gross}) AS gross,
                SUM({$refund}) AS refunds,
                SUM({$net}) AS net,
                SUM({$source} = 'parent') AS via_parent,
                SUM({$source} = 'customer') AS via_customer")
            // The rollup row is the totals; COUNT(DISTINCT) keeps a customer seen in two places once
            ->groupByRaw('loc_key WITH ROLLUP')
            ->get();

        $lifetimes = [];
        if ($withLifetime) {
            // The new customers each location won, read once, then their spend to date and purchases in the
            // report currency, looked up by customer id
            $won = $scoped()->whereRaw("{$type} = 'new'")->selectRaw("{$locKey} AS loc_key, o.customer_id")->distinct()->get();
            $spent = [];
            foreach (array_chunk(array_values(array_unique(array_map(function ($row) {
                return (int) $row->customer_id;
            }, $won->all()))), 5000) as $ids) {
                foreach (ReportHelper::joinShippingRefund(ReportCurrency::ordersTable($params), $params, $ids)
                    ->where('o.total_paid', '>', 0)
                    ->when(!empty($params['paymentStatus']), fn($q) => $q->whereIn('o.payment_status', $params['paymentStatus']))
                    ->when(!empty($params['currency']) && $params['currency'] !== ReportCurrency::ALL, fn($q) => $q->where('o.currency', $params['currency']))
                    ->whereIn('o.customer_id', $ids)
                    ->selectRaw("o.customer_id, SUM(" . ReportHelper::netSalesSql($params) . ") AS lifetime, SUM(o.type <> '" . Status::ORDER_TYPE_RENEWAL . "') AS purchases")
                    ->groupBy('o.customer_id')
                    ->get() as $row) {
                    $spent[(int) $row->customer_id] = $row;
                }
            }
            foreach ($won as $row) {
                $key = self::match($row->loc_key);
                $customer = $spent[(int) $row->customer_id] ?? null;
                $lifetimes[$key] = $lifetimes[$key] ?? ['customers' => 0, 'lifetime' => 0, 'repeaters' => 0];
                $lifetimes[$key]['customers']++;
                $lifetimes[$key]['lifetime'] += $customer ? (int) $customer->lifetime : 0;
                $lifetimes[$key]['repeaters'] += $customer && (int) $customer->purchases >= 2 ? 1 : 0;
            }
        }

        $result = [];
        $totals = null;
        foreach ($rows as $row) {
            if ($row->loc_key === null) {
                $totals = $row;
                continue;
            }
            $key = (string) $row->loc_key;
            $value = array_merge(['key' => $key, 'unknown' => $key === self::UNKNOWN], $this->finish($row));
            if ($withLifetime) {
                $won = $lifetimes[self::match($key)] ?? ['customers' => 0, 'lifetime' => 0, 'repeaters' => 0];
                $value['average_ltv'] = $won['customers'] ? round($won['lifetime'] / $won['customers'] / 100, 2) : 0.0;
                $value['repeat_rate'] = $won['customers'] ? round($won['repeaters'] / $won['customers'] * 100, 1) : 0.0;
            }
            $result[] = $value;
        }
        $totals = $totals ? $this->finish($totals) : $this->finish((object) []);

        foreach ($result as &$row) {
            $row['net_share'] = $totals['net_sales'] ? round($row['net_sales'] / $totals['net_sales'] * 100, 2) : 0;
        }
        unset($row);
        usort($result, function ($a, $b) {
            return $a['unknown'] <=> $b['unknown'] ?: $b['net_sales'] <=> $a['net_sales'] ?: strcmp($a['key'], $b['key']);
        });

        return ['rows' => $result, 'totals' => $totals];
    }

    /**
     * Store-wide cards from the country rows. Without a store country, the international figures are null.
     */
    private function summary(array $countries, string $storeCountry): array
    {
        $located = array_values(array_filter($countries['rows'], function ($row) {
            return !$row['unknown'] && $row['orders'] > 0;
        }));
        $abroad = array_filter($located, function ($row) use ($storeCountry) {
            return $row['key'] !== $storeCountry;
        });
        $net = $countries['totals']['net_sales'];
        $abroadNet = round(array_sum(array_column($abroad, 'net_sales')), 2);
        // Rows come sorted by net sales, the unknown row last
        $top = $located[0] ?? null;

        return [
            'store_country'               => $storeCountry,
            'net_sales'                   => $net,
            'orders'                      => $countries['totals']['orders'],
            'international_net'           => $storeCountry !== '' ? $abroadNet : null,
            'international_share'         => $storeCountry !== '' ? ($net ? round($abroadNet / $net * 100, 1) : 0.0) : null,
            'international_new_customers' => $storeCountry !== '' ? (int) array_sum(array_column($abroad, 'new_customers')) : null,
            'countries'                   => count($located),
            'top_country'                 => $top ? $top['key'] : null,
            'top_share'                   => $top && $net ? round($top['net_sales'] / $net * 100, 1) : 0.0,
        ];
    }

    /**
     * The three biggest gains and drops in net sales against the previous period, a location gone quiet included
     */
    private function movers(array $rows, array $previousRows): array
    {
        // Keyed by match(): the two periods can spell one place differently; the current spelling shows
        $now = [];
        $before = [];
        $shown = [];
        foreach ($previousRows as $row) {
            $before[self::match($row['key'])] = $row['net_sales'];
            $shown[self::match($row['key'])] = $row['key'];
        }
        foreach ($rows as $row) {
            $now[self::match($row['key'])] = $row['net_sales'];
            $shown[self::match($row['key'])] = $row['key'];
        }
        $moves = [];
        foreach ($shown as $match => $key) {
            $change = round(($now[$match] ?? 0) - ($before[$match] ?? 0), 2);
            if ($key === self::UNKNOWN || !$change) {
                continue;
            }
            $moves[] = ['key' => (string) $key, 'net_sales' => (float) ($now[$match] ?? 0), 'previous_net' => (float) ($before[$match] ?? 0), 'change' => $change];
        }
        usort($moves, function ($a, $b) {
            return $b['change'] <=> $a['change'] ?: strcmp($a['key'], $b['key']);
        });
        $up = array_values(array_filter($moves, function ($move) {
            return $move['change'] > 0;
        }));
        $down = array_reverse(array_values(array_filter($moves, function ($move) {
            return $move['change'] < 0;
        })));

        return ['up' => array_slice($up, 0, 3), 'down' => array_slice($down, 0, 3)];
    }

    /**
     * Report orders with the address rows, the customer record, whether the order is a first purchase and,
     * with a Products filter, the picked variations' money share.
     */
    private function locatedOrders(array $params, bool $shipping)
    {
        $query = $this->applyFilters(ReportCurrency::ordersTable($params), $params);

        ReportHelper::joinOrderAddress($query, 'billing', 'ba');
        if ($shipping) {
            ReportHelper::joinOrderAddress($query, 'shipping', 'sa');
        }
        ReportHelper::joinOrderAddress($query, 'billing', 'pa', 'o.parent_id');
        $query->leftJoin('fct_customers as c', 'c.id', '=', 'o.customer_id');

        // The range's first purchases (ReportHelper::firstPurchaseSql), found once rather than per use of the type
        $firstPurchases = App::db()->table('fct_orders as o')
            ->select('o.id')
            ->whereBetween('o.created_at', [$params['startDate'], $params['endDate']])
            ->whereNotNull('o.customer_id')
            ->where('o.total_paid', '>', 0)
            ->whereRaw(ReportHelper::firstPurchaseSql($params));
        $query->leftJoinSub($firstPurchases, 'fpn', 'fpn.id', '=', 'o.id');

        if (!empty($params['variationIds'])) {
            $query->leftJoinSub(ProductReportService::make()->orderVariationShares($params), 'ps', 'ps.order_id', '=', 'o.id');
        } else {
            ReportHelper::joinShippingRefund($query, $params);
        }

        return $query;
    }

    /**
     * A state far above the rest of its country, which usually means a checkout default rather than real demand.
     */
    private function outsizedState(array $rows): ?string
    {
        $located = array_values(array_filter($rows, function ($row) {
            return !$row['unknown'];
        }));
        usort($located, function ($a, $b) {
            return $b['orders'] <=> $a['orders'];
        });
        $total = array_sum(array_column($located, 'orders'));
        if (!$total || count($located) < 2) {
            return null;
        }
        $top = $located[0];

        return ($top['orders'] * 100 / $total >= self::OUTSIZED_SHARE && $top['orders'] >= 3 * $located[1]['orders'])
            ? $top['key']
            : null;
    }

    /**
     * A location key for PHP lookups. MySQL groups "London" and "london" into one row, so case is ignored here
     * too; accents are not folded, as that would follow the site locale, not the database. The prefix keeps a
     * numeric name a string array key.
     */
    private static function match($key): string
    {
        $key = (string) $key;

        return 'k:' . (function_exists('mb_strtolower') ? mb_strtolower($key) : strtolower($key));
    }

    private function finish($row): array
    {
        $get = function ($key) use ($row) {
            return isset($row->{$key}) ? $row->{$key} : 0;
        };
        $orders = (int) $get('orders');

        return [
            'orders'              => $orders,
            'customers'           => (int) $get('customers'),
            'new_customers'       => (int) $get('new_customers'),
            'returning_customers' => (int) $get('returning_customers'),
            'new_orders'          => (int) $get('new_orders'),
            'returning_orders'    => (int) $get('returning_orders'),
            'renewal_orders'      => (int) $get('renewal_orders'),
            'gross_sales'         => round((float) $get('gross') / 100, 2),
            'refunds'             => round((float) $get('refunds') / 100, 2),
            'net_sales'           => round((float) $get('net') / 100, 2),
            'average_net_order'   => $orders ? round((float) $get('net') / $orders / 100, 2) : 0,
            'refund_rate'         => $orders ? round((int) $get('refunded_orders') / $orders * 100, 2) : 0,
            'via_parent'          => (int) $get('via_parent'),
            'via_customer'        => (int) $get('via_customer'),
        ];
    }
}
