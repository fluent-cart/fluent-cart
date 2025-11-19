<?php

namespace FluentCart\App\Models\Query;


use FluentCart\App\Models\Concerns\CanSearch;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class QueryParser
{
    use CanSearch;


    static function make(): QueryParser
    {
        return new static();
    }

    public function parse(Builder $query, array $condition)
    {
        $conditionType = $this->resolveCondition($condition);
        $this->parseQuery($query, $condition, $conditionType, true, $conditionType);
    }

    private function resolveCondition($condition): string
    {
        return strtolower(Arr::get($condition, 'type', 'and')) === 'or' ? 'or' : 'and';
    }


    public function parseQuery(Builder $query, array $condition, $conditionType = 'and')
    {
        if (isset($condition['isCondition'])) {
            $this->handleCondition($query, $condition, $conditionType);
        } else if (isset($condition['isRelation'])) {
            $this->handleRelation($query, $condition, $conditionType);
        } else if (isset($condition['isOperator'])) {
            $this->handleOperator($query, $condition, $conditionType);
        }
    }


    public function handleCondition($query, $condition, $conditionTypeOriginal)
    {
        $conditions = Arr::get($condition, 'conditions', []);
        if (!is_array($conditions) || empty($conditions)) {
            return;
        }

        $conditionType = $this->resolveCondition($condition);


        $groupUsing = $conditionTypeOriginal === 'or' ? 'orWhere' : 'where';

        $query->{$groupUsing}(function ($q) use ($conditions, $conditionType, $condition, $groupUsing) {
            foreach ($conditions as $index => $childCondition) {
                $this->parseQuery($q, $childCondition, $conditionType);
            }
        });
    }

    public function handleOperator($query, $condition, $conditionType = 'and')
    {
        $columns = Arr::get($condition, 'column');
        if (empty($columns)) {
            return;
        }


        $columns = Arr::wrap($columns);
        $value = sanitize_text_field(Arr::get($condition, 'value'));

        foreach ($columns as $index => $column) {

            $operator = Arr::get($condition, 'operator');
            if ($conditionType === 'or') {
                $operator = 'or_' . $operator;
            }

            $query->search(
                [
                    $column => [
                        "column"   => $column,
                        "operator" => $operator,
                        "value"    => $value
                    ]
                ]
            );

        }
    }

    public function handleRelation($query, $condition, $conditionType = 'and')
    {

        $relationMethod = $conditionType === 'and' ? 'whereHas' : 'orWhereHas';
        $relationName = $condition['name'];

        $innerConditions = Arr::get($condition, 'conditions', []);

        if (empty($innerConditions)) {
            $query->{$relationMethod}($relationName);
        } else {
            $query->{$relationMethod}($relationName, function ($query) use ($innerConditions, $conditionType) {
                foreach ($innerConditions as $condition) {
                    $this->parseQuery($query, $condition, $conditionType);
                }
            });
        }
    }


}
