<?php

namespace FluentCart\App\Services\Filter\Concerns;


use FluentCart\App\Services\DateTime\DateTime;

trait HandleDateFilter
{
    /**
     * Handles date-based filtering for the query.
     *
     * @param array $filterItem The filter item containing property, value, and operator.
     * @return void|null Returns null if the filter is invalid.
     */
    private function handleDate(&$query,array $filterItem)
    {
        $searchTerm = $filterItem['value'];
        $property = $filterItem['property'];

        if (empty($searchTerm) && $searchTerm.'' !== '0') {
            return;
        }

        $filterItem = $this->parseFilterForDate($filterItem);

        if ($filterItem === null) {
            return;
        }

        $newOperator = $filterItem['operator'];
        $searchTerm = $filterItem['value'];
        if ($newOperator === 'between') {
            $query = $query->whereBetween($property, $searchTerm);
        } else {
            $query = $query->where($property, $newOperator, $searchTerm);
        }
    }

    /**
     * Parses the filter item to transform date-based operators into valid query conditions.
     *
     * @param array $filter The filter item containing operator and value.
     * @return array|null The modified filter item or null if invalid.
     */
    private function parseFilterForDate(array $filter): ?array
    {
        $operator = $filter['operator'];
        $date = DateTime::gmtNow();

        // Handle days-based filters
        $days = $filter['value'];
        if ($operator === 'days_before') {

            if (!is_numeric($days)) {
                return null;
            }
            $days = intval($days);
            $filter['operator'] = '<';
            $filter['value'] = $date
                ->endOfDay()
                ->subDays($days)
                ->format('Y-m-d H:i:s');
            return $filter;
        } else if ($operator === 'days_within') {

            if (!is_numeric($days)) {
                return null;
            }
            $days = intval($days);

            $filter['operator'] = 'between';
            $filter['value'] = [
                $date->copy()->startOfDay()->subDays($days)->format('Y-m-d H:i:s'),
                $date->endOfDay()->format('Y-m-d H:i:s')
            ];
            return $filter;
        }

        try {
            $date = DateTime::parse($filter['value']);
        } catch (\Exception $e) {
            return null; // Return null if the date cannot be parsed
        }

        // Convert date operators to appropriate SQL conditions
        switch ($filter['operator']) {
            case 'before':
                $filter['operator'] = '<';
                $filter['value'] = $date->format('Y-m-d H:i:s');
                break;

            case 'after':
                $filter['operator'] = '>';
                $filter['value'] = $date->format('Y-m-d H:i:s');
                break;

            case 'date_equal':
                $filter['operator'] = 'between';
                // The value is an array because we need a range from the start to the end of the selected date
                $filter['value'] = [
                    $date->copy()->startOfDay()->format('Y-m-d H:i:s'),
                    $date->endOfDay()->format('Y-m-d H:i:s')
                ];
                break;

            default:
                $filter = null;
        }

        return $filter;
    }
}
