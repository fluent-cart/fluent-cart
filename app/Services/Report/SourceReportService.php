<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\App;

class SourceReportService extends ReportService
{
    public function getSourceReportData($params = [])
    {
        $query = App::db()->table('fct_orders as o')
            ->selectRaw("oo.utm_campaign,
                oo.utm_source,
                oo.utm_medium,
                oo.utm_term,
                oo.utm_content,
                oo.utm_id,
                CONCAT(COALESCE(oo.utm_campaign, ''), '|', COALESCE(oo.utm_source, ''), '|', COALESCE(oo.utm_medium, '')) as utm_key,
                COUNT(o.id) as orders,
                SUM(o.total_paid) / 100 as gross_sales,
                SUM(o.total_paid - o.total_refund - o.tax_total - o.shipping_tax) / 100 as net_sales,
                AVG(o.total_paid) / 100 as average_order,
                AVG(o.total_paid - o.total_refund - o.tax_total - o.shipping_tax) / 100 as average_net_order")
            ->leftJoin('fct_order_operations as oo', 'o.id', '=', 'oo.order_id')
            ->whereNotNull('oo.utm_source')
            ->where('oo.utm_source', '!=', '')
            ->groupBy([
                'oo.utm_campaign',
                'oo.utm_source',
                'oo.utm_medium',
            ])
            ->orderByRaw('gross_sales DESC');

        $query = $this->applyFilters($query, $params);

        return $query->get();
    }

    public function calculateFluctuations($currentData, $comparisonData)
    {
        // Create lookup array for comparison data
        $comparisonLookup = [];
        foreach ($comparisonData as $item) {
            $comparisonLookup[$item->utm_key] = $item;
        }

        // Create fluctuations lookup array
        $fluctuations = [];

        foreach ($currentData as $currentItem) {
            $key = $currentItem->utm_key;
            $comparisonItem = $comparisonLookup[$key] ?? null;

            if ($comparisonItem) {
                $fluctuations[$key] = [
                    'previous_orders'            => (float) $comparisonItem->orders,
                    'previous_gross_sales'       => (float) $comparisonItem->gross_sales,
                    'previous_net_sales'         => (float) $comparisonItem->net_sales,
                    'previous_average_order'     => (float) $comparisonItem->average_order,
                    'previous_average_net_order' => (float) $comparisonItem->average_net_order,
                    'orders_fluctuation'         => $this->calculatePercentageChange(
                        $comparisonItem->orders,
                        $currentItem->orders
                    ),
                    'gross_sales_fluctuation' => $this->calculatePercentageChange(
                        $comparisonItem->gross_sales,
                        $currentItem->gross_sales
                    ),
                    'net_sales_fluctuation' => $this->calculatePercentageChange(
                        $comparisonItem->net_sales,
                        $currentItem->net_sales
                    ),
                    'average_order_fluctuation' => $this->calculatePercentageChange(
                        $comparisonItem->average_order,
                        $currentItem->average_order
                    ),
                    'average_net_order_fluctuation' => $this->calculatePercentageChange(
                        $comparisonItem->average_net_order,
                        $currentItem->average_net_order
                    ),
                ];
            } else {
                // New UTM combination - no comparison data
                $fluctuations[$key] = [
                    'previous_orders'               => 0,
                    'previous_gross_sales'          => 0,
                    'previous_net_sales'            => 0,
                    'previous_average_order'        => 0,
                    'previous_average_net_order'    => 0,
                    'orders_fluctuation'            => 100,
                    'gross_sales_fluctuation'       => 100,
                    'net_sales_fluctuation'         => 100,
                    'average_order_fluctuation'     => 100,
                    'average_net_order_fluctuation' => 100,
                ];
            }
        }

        return $fluctuations;
    }

    protected function calculatePercentageChange($oldValue, $newValue)
    {
        if ($oldValue == 0) {
            return $newValue > 0 ? 100 : 0;
        }

        return round((($newValue - $oldValue) / $oldValue) * 100, 2);
    }
}
