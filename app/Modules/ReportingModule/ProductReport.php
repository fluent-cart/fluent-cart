<?php

namespace FluentCart\App\Modules\ReportingModule;

use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;

class ProductReport
{
    public static function getStatByProductIds($productIds = [], $ranges = ['-30 days', 'all_time'])
    {
        if (!$productIds) {
            return [];
        }

        $db = App::getInstance('db');

        $stats = [];

        foreach ($ranges as $range) {
            $statsQuery = $db->table('fct_order_items')
                ->select([
                    $db->raw('SUM(line_total) as total_amount'),
                    $db->raw('SUM(quantity) as total_quantity')
                ])
                ->join('fct_orders', 'fct_orders.id', '=', 'fct_order_items.order_id')
                ->whereIn('fct_order_items.object_id', $productIds)
                ->whereIn('fct_orders.status', Status::getTransactionSuccessStatuses());

            if ($range != 'all_time') {
                $statsQuery = $statsQuery->where('fct_orders.created_at', '>', gmdate('Y-m-d 00:00:00', strtotime($range)));
            }

            $stats[$range] = $statsQuery->first();
        }

        return $stats;
    }
}
