<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\App;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;

class DefaultReportService extends ReportService
{
    public function fetchTopSoldProducts(array $params)
    {
        $query = App::db()->table('fct_orders as o')
            ->selectRaw('
                oi.post_id AS product_id,
                SUM(oi.quantity) AS quantity_sold,
                SUM(oi.line_total) / 100 AS total_amount
            ')
            ->join('fct_order_items as oi', 'oi.order_id', '=', 'o.id')
            ->where('oi.post_id', '>', 0)
            ->groupBy('oi.post_id')
            ->orderByDesc('quantity_sold');

        unset($params['variationIds']);

        $query = $this->applyFilters($query, $params);

        $topSoldProducts = $query->limit(20)->get();

        $productIds = $topSoldProducts->pluck('product_id')->all();

        $productNames = $this->getProductNames($productIds);
        $productImages = $this->getProductImages($productIds);

        $topSoldProducts = $topSoldProducts->map(fn ($item) => [
            'product_id'    => (int) $item->product_id,
            'product_name'  => $productNames[(int) $item->product_id] ?? __('Unknown Product', 'fluent-cart'),
            'quantity_sold' => (int) $item->quantity_sold,
            'total_amount'  => round((float) $item->total_amount, 2),
            'media'         => $productImages[(int) $item->product_id] ?? null,
        ]);

        return [
            'topSoldProducts' => $topSoldProducts,
        ];
    }

    public function fetchTopSoldVariants(array $params): array
    {
        $query = App::db()->table('fct_orders as o')
            ->selectRaw('
                oi.object_id AS variation_id,
                SUM(oi.quantity) AS quantity_sold,
                SUM(oi.line_total) / 100 AS total_amount
            ')
            ->join('fct_order_items as oi', 'oi.order_id', '=', 'o.id')
            ->where('oi.object_id', '>', 0)
            ->groupBy('oi.object_id')
            ->orderByDesc('quantity_sold');

        unset($params['variationIds']);

        $query = $this->applyFilters($query, $params);

        $topSoldVariants = $query->limit(10)->get();

        $variationIds = $topSoldVariants->pluck('variation_id')->all();
        $variantMeta = $this->getVariantMeta($variationIds);

        $productIds = array_values(array_unique(array_column($variantMeta, 'post_id')));
        $productNames = $this->getProductNames($productIds);

        $variationImages = $this->getVariationImages($variationIds);
        $productImages = $this->getProductImages($productIds);

        $topSoldVariants = $topSoldVariants->map(fn ($item) => [
            'product_id'     => (int) ($variantMeta[(int) $item->variation_id]['post_id'] ?? 0),
            'product_name'   => $productNames[(int) ($variantMeta[(int) $item->variation_id]['post_id'] ?? 0)] ?? __('Unknown Product', 'fluent-cart'),
            'variation_id'   => (int) $item->variation_id,
            'variation_name' => $variantMeta[(int) $item->variation_id]['title'] ?? __('Unknown Variant', 'fluent-cart'),
            'quantity'       => (int) $item->quantity_sold,
            'total_amount'   => round((float) $item->total_amount, 2),
            'media_url'      => $variationImages[(int) $item->variation_id]
                ?? $productImages[(int) ($variantMeta[(int) $item->variation_id]['post_id'] ?? 0)]
                ?? null,
        ]);

        return [
            'topSoldVariants' => $topSoldVariants,
        ];
    }

    public function calculateFluctuations($currentMetrics, $previousMetrics)
    {
        // Calculate fluctuations for each metric
        $metrics = [
            'gross_sale'  => $this->calculateFluctuation($currentMetrics['gross_sale'], $previousMetrics['gross_sale']),
            'net_revenue' => $this->calculateFluctuation($currentMetrics['net_revenue'], $previousMetrics['net_revenue']),
            // 'subscription_revenue' => $this->calculateFluctuation($currentMetrics['subscription_revenue'], $previousMetrics['subscription_revenue']),
            'order_count' => $this->calculateFluctuation($currentMetrics['order_count'], $previousMetrics['order_count']),
            // 'new_customers' => $this->calculateFluctuation($currentMetrics['new_customers'], $previousMetrics['new_customers']),
            'total_item_count'        => $this->calculateFluctuation($currentMetrics['total_item_count'], $previousMetrics['total_item_count']),
            'total_refunded'          => $this->calculateFluctuation($currentMetrics['total_refunded'], $previousMetrics['total_refunded']),
            'total_refunded_amount'   => $this->calculateFluctuation($currentMetrics['total_refunded_amount'], $previousMetrics['total_refunded_amount']),
            'average_order_net'       => $this->calculateFluctuation($currentMetrics['average_order_net'], $previousMetrics['average_order_net']),
            'average_order_items'     => $this->calculateFluctuation($currentMetrics['average_order_items'], $previousMetrics['average_order_items']),
            'average_customer_orders' => $this->calculateFluctuation($currentMetrics['average_customer_orders'], $previousMetrics['average_customer_orders']),
            'average_customer_ltv'    => $this->calculateFluctuation($currentMetrics['average_customer_ltv'], $previousMetrics['average_customer_ltv']),
        ];

        return $metrics;
    }

    private function calculateFluctuation($currentValue, $previousValue)
    {
        if ($previousValue > 0) {
            return round((($currentValue - $previousValue) / $previousValue) * 100, 2);
        }

        return $currentValue > 0 ? 100 : 0;
    }

    private function getProductNames(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        if (!$productIds) {
            return [];
        }

        $latestIds = App::db()->table('fct_order_items')
            ->selectRaw('MAX(id) AS id')
            ->whereIn('post_id', $productIds)
            ->groupBy('post_id');

        return App::db()->table('fct_order_items')
            ->select(['post_id', 'post_title'])
            ->whereIn('id', $latestIds)
            ->get()
            ->reduce(function ($names, $item) {
                $names[(int) $item->post_id] = $item->post_title;
                return $names;
            }, []);
    }

    private function getProductImages(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        if (!$productIds) {
            return [];
        }

        return Product::query()
            ->whereIn('ID', $productIds)
            ->with(['detail.galleryImage'])
            ->get()
            ->reduce(function ($images, Product $product) {
                $images[(int) $product->ID] = $product->thumbnail ?: null;

                return $images;
            }, []);
    }

    private function getVariantMeta(array $variationIds): array
    {
        $variationIds = array_values(array_unique(array_filter(array_map('intval', $variationIds))));

        if (!$variationIds) {
            return [];
        }

        $latestIds = App::db()->table('fct_order_items')
            ->selectRaw('MAX(id) AS id')
            ->whereIn('object_id', $variationIds)
            ->groupBy('object_id');

        return App::db()->table('fct_order_items')
            ->select(['object_id', 'title', 'post_id'])
            ->whereIn('id', $latestIds)
            ->get()
            ->reduce(function ($meta, $item) {
                $meta[(int) $item->object_id] = [
                    'title'   => $item->title,
                    'post_id' => (int) $item->post_id,
                ];
                return $meta;
            }, []);
    }

    private function getVariationImages(array $variationIds): array
    {
        $variationIds = array_values(array_unique(array_filter(array_map('intval', $variationIds))));

        if (!$variationIds) {
            return [];
        }

        return ProductVariation::query()
            ->whereIn('id', $variationIds)
            ->with(['media'])
            ->get()
            ->reduce(function ($images, ProductVariation $variation) {
                $images[(int) $variation->id] = $variation->thumbnail ?: null;

                return $images;
            }, []);
    }

    public function getAllGraphMetricsSeparate($params = [])
    {
        $group = ReportHelper::processGroup(
            $params['startDate'], $params['endDate'], $params['groupKey']
        );

        $variationIds = array_map('intval', $params['variationIds'] ?? []);

        $itemsSub = App::db()->table('fct_order_items')
            ->selectRaw('order_id, SUM(quantity) AS items_sold')
            ->groupBy('order_id')
            ->whereBetween('created_at', [$params['startDate'], $params['endDate']])
            ->when($variationIds, fn ($q) => $q->whereIn('object_id', $variationIds));

        $orderMetricsQuery = App::db()->table('fct_orders as o')
            ->joinSub($itemsSub, 'oi_sum', fn ($join) => $join->on('oi_sum.order_id', '=', 'o.id'));

        $orderMetricsQuery = $orderMetricsQuery->selectRaw("{$group['field']},

            SUM(o.total_paid) / 100 AS gross_sale,

            SUM(
                o.total_paid
                - o.total_refund
                - o.tax_total
                - o.shipping_tax
            ) / 100 as net_revenue,

            SUM(o.total_refund) / 100 AS refund_amount,

            COUNT(CASE WHEN o.total_refund > 0 THEN 1 END) AS refund_count,

            COUNT(o.id) as order_count,

            COUNT(CASE WHEN o.parent_id > 0 THEN 1 END) as subscription_renewals,

            COUNT(CASE WHEN o.type = 'payment' THEN 1 END) AS onetime_count,
            COUNT(CASE WHEN o.type = 'renewal' THEN 1 END) AS renewal_count,
            COUNT(CASE WHEN o.type = 'subscription' THEN 1 END) AS subscription_count,

            SUM(CASE WHEN o.type = 'payment' THEN o.total_paid ELSE 0 END) / 100 AS onetime_gross,
            SUM(CASE WHEN o.type = 'renewal' THEN o.total_paid ELSE 0 END) / 100 AS renewal_gross,
            SUM(CASE WHEN o.type = 'subscription' THEN o.total_paid ELSE 0 END) / 100 AS subscription_gross,

            SUM(
                CASE WHEN o.type = 'payment' 
                THEN (o.total_paid - o.total_refund - o.tax_total - o.shipping_tax) 
                ELSE 0 END
            ) / 100 AS onetime_net,
            SUM(
                CASE WHEN o.type = 'renewal' 
                THEN (o.total_paid - o.total_refund - o.tax_total - o.shipping_tax) 
                ELSE 0 END
            ) / 100 AS renewal_net,
            SUM(
                CASE WHEN o.type = 'subscription' 
                THEN (o.total_paid - o.total_refund - o.tax_total - o.shipping_tax) 
                ELSE 0 END
            ) / 100 AS subscription_net,

            SUM(COALESCE(oi_sum.items_sold, 0)) AS items_sold,

            SUM(
                CASE
                    WHEN o.parent_id > 0
                    THEN o.total_paid - o.total_refund - o.tax_total - o.shipping_tax
                    ELSE 0
                END
            ) / 100 as subscription_revenue")
            ->groupByRaw($group['by'])
            ->orderByRaw($group['by']);

        $orderMetricsQuery = $this->applyFilters($orderMetricsQuery, $params);

        $orderMetrics = $orderMetricsQuery->get();

        return $this->combineMetricsResults($orderMetrics);
    }

    private function combineMetricsResults($orderMetrics): array
    {
        $metrics = [
            'orderGraph'               => [],
            'grossSaleGraph'           => [],
            'refundsGraph'             => [],
            'refundCountGraph'         => [],
            'netRevenueGraph'          => [],
            'itemsSoldGraph'           => [],
            'subscriptionRenewalGraph' => [],
            'subscriptionRevenueGraph' => [],
        ];

        $summary = [
            'gross_sale'                 => 0,
            'net_revenue'                => 0,
            'order_count'                => 0,
            'subscription_renewal_count' => 0,
            'total_item_count'           => 0,
            'total_refunded_amount'      => 0,
            'total_refunded'             => 0,
            'average_order_net'          => 0,
            'average_order_items'        => 0,
            'average_customer_orders'    => 0,
            'average_customer_ltv'       => 0,
            'onetime_count'              => 0,
            'renewal_count'              => 0,
            'subscription_count'         => 0,
            'onetime_gross'              => 0,
            'renewal_gross'              => 0,
            'subscription_gross'         => 0,
            'onetime_net'                => 0,
            'renewal_net'                => 0,
            'subscription_net'           => 0,
        ];

        // Process order metrics
        foreach ($orderMetrics as $row) {
            $period = $row->group;
            $metrics['grossSaleGraph'][$period] = (float) $row->gross_sale;
            $metrics['netRevenueGraph'][$period] = (float) $row->net_revenue;
            $metrics['orderGraph'][$period] = (int) $row->order_count;
            $metrics['subscriptionRenewalGraph'][$period] = (int) $row->subscription_renewals;
            $metrics['subscriptionRevenueGraph'][$period] = (float) $row->subscription_revenue;
            $metrics['refundsGraph'][$period] = (float) $row->refund_amount;
            $metrics['refundCountGraph'][$period] = (int) $row->refund_count;
            $metrics['itemsSoldGraph'][$row->group] = (int) $row->items_sold;

            $summary['gross_sale'] += (float) $row->gross_sale;
            $summary['net_revenue'] += (float) $row->net_revenue;
            $summary['order_count'] += (int) $row->order_count;
            $summary['total_refunded_amount'] += (float) $row->refund_amount;
            $summary['total_refunded'] += (int) $row->refund_count;
            $summary['total_item_count'] += (int) $row->items_sold;

            $summary['onetime_count'] += (int) $row->onetime_count;
            $summary['renewal_count'] += (int) $row->renewal_count;
            $summary['subscription_count'] += (int) $row->subscription_count;

            $summary['onetime_gross'] += (float) $row->onetime_gross;
            $summary['renewal_gross'] += (float) $row->renewal_gross;
            $summary['subscription_gross'] += (float) $row->subscription_gross;

            $summary['onetime_net'] += (float) $row->onetime_net;
            $summary['renewal_net'] += (float) $row->renewal_net;
            $summary['subscription_net'] += (float) $row->subscription_net;
        }

        return [
            'metrics' => $metrics,
            'summary' => $summary,
        ];
    }
}
