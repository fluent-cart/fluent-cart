<?php

namespace FluentCart\App\Services\Filter\Concerns;

use FluentCart\Framework\Database\Orm\Builder;

trait HandleRelationalFilter
{

    public array $directRelationalOperator = [
        'has'
    ];

    private function isDirectRelationalOperator($property): bool
    {
        return in_array($property, $this->directRelationalOperator);
    }

    private function handleRelation(&$query, $filter)
    {

        $relation = $filter['relation'];
        $relationKey = $filter['column'];

        $operator = $filter['operator'];
        $searchTerm = $filter['value'];

        $property = $filter['property'];

        //if the search value is not string or array, return
        if (!(is_string($searchTerm) || is_array($searchTerm))) {
            return;
        }
        // If the search term is empty, return
        if (empty($searchTerm) && $searchTerm !== '0') {
            return;
        }

        if ($this->isDirectRelationalOperator($property)) {
            if ($property === 'has') {
                $query = $query->has($relation, $operator, $searchTerm);
            }
            return;
        }

        if (is_string($searchTerm)) {
            $query = $query->whereHas($relation, function (Builder $q) use ($filter, $relationKey) {
                $filter['property'] = $relationKey;
                $this->handleOperator($q, $filter);
            });
            return;
        }

        //searchTerm is array
        if ($operator === 'not_contains' || $operator === 'not_in') {
            $query = $query->whereDoesntHave($relation, function (Builder $q) use ($searchTerm, $relationKey) {
                $q->whereIn($relationKey, $searchTerm);
            });
        } else if ($operator === 'not_in_all') {
            $query = $query->whereDoesntHave($relation, function (Builder $q) use ($searchTerm, $relationKey) {
                foreach ($searchTerm as $term) {
                    $q->where($relationKey, $term);
                }
            });
        } else if ($operator === 'in_all') {
            foreach ($searchTerm as $term) {
                $query = $query->whereHas($relation, function (Builder $q) use ($term, $relationKey) {
                    $q->where($relationKey, $term);
                });
            }
        } else {
            $query = $query->whereHas($relation, function (Builder $q) use ($searchTerm, $relationKey) {
                $q->whereIn($relationKey, $searchTerm);
            });
        }
    }
}