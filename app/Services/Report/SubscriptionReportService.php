<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\Report\Concerns\Subscription\FutureRenewals;

class SubscriptionReportService extends ReportService
{
    use FutureRenewals;

    public function getRetentionChart($params = [])
    {
        $baseQuery = App::db()->query()
            ->from('fct_subscriptions as s')
            ->whereBetween('s.created_at', [$params['startDate'], $params['endDate']])
            ->when($params['variationIds'], fn ($q) => $q->whereIn('s.variation_id', $params['variationIds']));

        if ($params['customDays']) {
            $query = $baseQuery->selectRaw("COUNT(*) AS day_{$params['customDays']}")
                ->whereRaw('
                    DATEDIFF(
                        COALESCE(s.canceled_at, NOW()),
                        s.created_at
                    ) <= ?
                ', $params['customDays']);
        } else {
            $baseQuery->selectRaw('DATEDIFF(COALESCE(s.canceled_at, NOW()), s.created_at) AS lifespan');

            $query = App::db()->query()->selectRaw('
                SUM(CASE WHEN lifespan <= 7 THEN 1 ELSE 0 END) AS day_7,
                SUM(CASE WHEN lifespan BETWEEN 8 AND 15 THEN 1 ELSE 0 END) AS day_15,
                SUM(CASE WHEN lifespan BETWEEN 16 AND 30 THEN 1 ELSE 0 END) AS day_30,
                SUM(CASE WHEN lifespan BETWEEN 31 AND 90 THEN 1 ELSE 0 END) AS day_90,
                SUM(CASE WHEN lifespan BETWEEN 91 AND 180 THEN 1 ELSE 0 END) AS day_180,
                SUM(CASE WHEN lifespan BETWEEN 181 AND 365 THEN 1 ELSE 0 END) AS day_365,
                SUM(CASE WHEN lifespan > 365 THEN 1 ELSE 0 END) AS more_than_year
            ')->fromSub($baseQuery, 'retention_data');
        }

        return $query->first();
    }

    public function getDailySignups($params = [])
    {
        return App::db()->query()
            ->selectRaw('
                DATE(s.created_at) AS trend_date, 
                COUNT(s.id) AS value
            ')
            ->from('fct_subscriptions as s')
            ->whereBetween('s.created_at', [$params['startDate'], $params['endDate']])
            ->when($params['variationIds'], fn ($q) => $q->whereIn('s.variation_id', $params['variationIds']))
            ->groupBy('trend_date')
            ->orderBy('trend_date')
            ->get();
    }

    public function getChartData(array $params)
    {
        $startDate = $params['startDate'];
        $endDate = $params['endDate'];
        $subscriptionType = $params['subscriptionType'];

        $group = ReportHelper::processGroup($startDate, $endDate, $params['groupKey']);

        $query = App::db()->query();

        if (in_array($subscriptionType, [Status::ORDER_TYPE_SUBSCRIPTION, Status::ORDER_TYPE_RENEWAL])) {
            $query->from('fct_orders as o')
                ->where('o.type', $subscriptionType)
                ->whereIn('o.status', Status::getOrderSuccessStatuses());

            $query = $this->applyFilters($query, $params);
        } else {
            $dateColumn = 'o.expire_at';

            if ($subscriptionType === Status::SUBSCRIPTION_CANCELED) {
                $dateColumn = 'o.canceled_at';
            }

            $query->from('fct_subscriptions as o')->where('o.status', $subscriptionType)
                ->whereBetween($dateColumn, [
                    $startDate->format('Y-m-d H:i:s'),
                    $endDate->format('Y-m-d H:i:s'),
                ])
                ->when($params['variationIds'], fn ($q) => $q->whereIn('o.variation_id', $params['variationIds']));
        }

        $query->selectRaw("{$group['field']}, COUNT(o.id) as count")->groupByRaw($group['by']);

        $results = $query->get();

        $keys = ['count'];
        $grouped = $this->getPeriodRange($startDate, $endDate, $group['key'], $keys);
        $totalSubscriptions = 0;

        foreach ($results as $row) {
            $grouped[$row->group] = [
                'year'  => (int) $row->year,
                'group' => $row->group,
                'count' => (int) $row->count,
            ];

            $totalSubscriptions += (int) $row->count;
        }

        return [
            'grouped'            => array_values($grouped),
            'totalSubscriptions' => $totalSubscriptions,
        ];
    }
}
