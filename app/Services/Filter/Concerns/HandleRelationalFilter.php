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

        // `contains`/`not_contains` carry two different kinds of value. On a text
        // field they are the UI's "Includes"/"Doesn't includes" — substring
        // searches. On a remote_tree_select field (Order Items) they carry an
        // array of ids and mean membership. Only the string form is handled here;
        // arrays fall through to the whereIn branches below, unchanged.
        //
        // Normalising a string into a single-element array (as the array-based
        // operators below need) sent it to whereIn instead, so "Includes gmail"
        // matched nothing while the full address matched — a wrong result set
        // rather than an error.
        if (is_string($searchTerm) && in_array($operator, ['contains', 'not_contains'], true)) {
            $likeTerm = '%' . $searchTerm . '%';

            if ($operator === 'contains') {
                $query = $query->whereHas($relation, function (Builder $q) use ($relationKey, $likeTerm) {
                    $q->where($relationKey, 'LIKE', $likeTerm);
                });

                return;
            }

            // "Doesn't include" has to exclude the row when ANY related record
            // matches. Requiring one related record that does not match is a
            // different question, and gives the wrong answer on a to-many
            // relation: an order with two transactions would satisfy it while
            // still holding a matching one.
            $query = $query->whereDoesntHave($relation, function (Builder $q) use ($relationKey, $likeTerm) {
                $q->where($relationKey, 'LIKE', $likeTerm);
            });

            return;
        }

        // Normalize string values to array for array-based operators
        if (is_string($searchTerm) && in_array($operator, ['in', 'not_in', 'in_all', 'not_in_all'])) {
            $searchTerm = [$searchTerm];
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