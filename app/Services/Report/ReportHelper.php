<?php

namespace FluentCart\App\Services\Report;

use FluentCart\App\App;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Status;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Sanitizer;
use FluentCart\App\Services\DateTime\DateTime;

class ReportHelper
{

    /**
     * Define the group key based on the data density between the start and end dates.
     *
     * @param \DateTime $startDate The start date as a DateTime object.
     * @param \DateTime $endDate The end date as a DateTime object.
     * @return string The group key, which can be 'daily', 'monthly', or 'yearly'.
     */

    public static function defineGroupKey($startDate, $endDate)
    {
        $diff = $endDate->diff($startDate);
        $days = $diff->days;

        if ($days <= 91) {
            return 'daily';
        } elseif ($days <= 365) {
            return 'monthly';
        } else {
            return 'yearly';
        }
    }

    public static function processGroup($startDate, $endDate, $groupKey = null)
    {
        if (!$groupKey || $groupKey === 'default') {
            $groupKey = static::defineGroupKey($startDate, $endDate);
        }

        switch ($groupKey) {
            case 'yearly':
                $format = '%Y';
                break;
            case 'monthly':
                $format = '%Y-%m';
                break;
            default:
                $format = '%Y-%m-%d';
                break;
        }

        $groupBy = "DATE_FORMAT(o.created_at, '{$format}')";
        $selection = "{$groupBy} AS `group`, YEAR(o.created_at) AS year";

        return [
            'key'   => $groupKey,
            'by'    => $groupBy,
            'field' => $selection,
        ];
    }

    public static function processFilters($params = [])
    {
        $storeMode = (new StoreSettings())->get('order_mode') ?: 'live';
        $mode = $params['filterMode'] ?? $storeMode;

        $filters = [
            'payment_status' => $params['paymentStatus'] ?? null,
            'status' => $params['orderStatus'] ?? null,
            'currency' => $params['currency'] ?? null,
            'mode' => $mode,
        ];

        return array_filter($filters);
    }

    public static function processRequest($params = []): array
    {
        $storeMode = (new StoreSettings())->get('order_mode') ?: 'live';
        $mode = $params['filterMode'] ?? $storeMode;

        $filters = [
            'payment_status' => Arr::get($params, 'paymentStatus'),
            'status' => Arr::get($params, 'orderStatus'),
            'currency' => Arr::get($params, 'currency'),
            'mode' => $mode,
            'type' => array_filter(Arr::get($params, 'orderTypes', [])),
        ];

        $groupKey = Arr::get($params, 'groupKey', 'daily');

        return [
            'filters' => array_filter($filters),
            'startDate' => Arr::get($params, 'startDate'),
            'endDate' => Arr::get($params, 'endDate'),
            'groupKey' => $groupKey,
        ];
    }

    public static function processParams($params = [], $additional = []): array
    {
        $params = static::sanitizeParams($params);
        
        if (!App::isProActive()) {
            $startDate = (new DateTime())->subMonth()->startOfDay();
            $endDate = (new DateTime())->endOfDay();
        } else {
            $startDate = (new DateTime(Arr::get($params, 'startDate')))->startOfDay();
            $endDate = (new DateTime(Arr::get($params, 'endDate')))->endOfDay();
        }
        
        $compareType = Arr::get($params, 'compareType');
        $compareDate = Arr::get($params, 'compareDate');
        $comparePeriod = null;

        if ($compareType && $compareDate) {
            $comparePeriod = static::getCompareRange(
                $compareType,
                ['startDate' => $startDate, 'endDate' => $endDate],
                $compareDate
            );
        }

        $attributes = [
            'startDate'     => $startDate,
            'endDate'       => $endDate,
            'groupKey'      => Arr::get($params, 'groupKey'),
            'currency'      => Arr::get($params, 'currency'),
            'paymentMode'   => Arr::get($params, 'filterMode'),
            'variationIds'  => Arr::get($params, 'variation_ids', []),
            'comparePeriod' => $comparePeriod
        ];

        foreach ($additional as $key) {
            $value = Arr::get($params, $key);

            if ($key === 'orderStatus') {
                if (!$value || in_array('all', $value)) {
                    $value = [Status::ORDER_ON_HOLD, Status::ORDER_FAILED];
                }
            } elseif ($key === 'paymentStatus') {
                $value = Status::getReportStatuses();
            } elseif ($key === 'subscriptionType') {
                $value = $value ?: Status::ORDER_TYPE_SUBSCRIPTION;
            }

            $attributes[$key] = $value;
        }

        return $attributes;
    }

    protected static function sanitizeParams($params)
    {        
        $rules = [
            'startDate'        => 'sanitize_text_field',
            'endDate'          => 'sanitize_text_field',
            'compareType'      => 'sanitize_text_field',
            'compareDate'      => 'sanitize_text_field',
            'groupKey'         => 'sanitize_text_field',
            'currency'         => 'sanitize_text_field',
            'filterMode'       => 'sanitize_text_field',
            'storeMode'        => 'sanitize_text_field',
            'variation_ids.*'  => 'intval',
            'subscriptionType' => 'sanitize_text_field',
            'orderStatus.*'    => 'sanitize_text_field',
            'orderTypes.*'     => 'sanitize_text_field',
        ];
        
        return Sanitizer::sanitize($params, $rules);
    }

    /**
     * @param string $type
     * @param array $compareRange
     * @param array $currentRange
     * @return array|\DateTime[]|false
     * @throws \Exception
     */
    public static function getCompareRange($type, $currentRange, $compareDate = null)
    {
        $currentStartDate = $currentRange['startDate'];
        $currentendDate = $currentRange['endDate'];

        $diffDays = $currentendDate->diff($currentStartDate)->days;

        if ($type == 'previous_period') {
            return [
                $currentStartDate->copy()->subDays($diffDays + 1),
                $currentStartDate->copy()->subDays(1)->endOfDay()
            ];
        } else if ($type == 'previous_month') {
            $fromDate = $currentStartDate->copy()->subMonths(1);
            
            return [
                $fromDate->copy(),
                $fromDate->addDays($diffDays)->endOfDay()
            ];
        } else if ($type == 'previous_quarter') {
            $fromDate = $currentStartDate->copy()->subMonths(3);
            return [
                $fromDate->copy()->startOfMonth(),
                $fromDate->addDays($diffDays)->endOfDay()
            ];
        } else if ($type == 'previous_year') {
            $fromDate = $currentStartDate->copy()->subYears(1);
            return [
                $fromDate->copy(),
                $fromDate->addDays($diffDays)->endOfDay()
            ];
        } else if ($type == 'custom' && $compareDate) {
            $compareDate = (new DateTime($compareDate))->startOfDay();
            return [
                $compareDate->copy(),
                $compareDate->addDays($diffDays)->endOfDay(),
            ];
        } else {
            return false;
        }
    }
}
