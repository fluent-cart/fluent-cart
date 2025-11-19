<?php

namespace FluentCart\Framework\Validator;

use Closure;
use FluentCart\Framework\Support\Str;
use FluentCart\Framework\Foundation\App;
use FluentCart\Framework\Database\Orm\Model;
use FluentCart\Framework\Validator\Rules\Exists;
use FluentCart\Framework\Validator\Rules\Unique;

trait ValidateDatabaseRulesTrait
{
	/**
     * Validate the existence of an attribute value in a database table.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  array  $parameters
     * @return bool
     */
    public function validateExists($attribute, $value, $parameters)
    {
        $this->requireParameterCount(1, $parameters, 'exists');

        [$connection, $table] = $this->parseTable($parameters[0]);
        
        $column = isset($parameters[1]) ? $parameters[1] : $attribute;

        $expected = is_array($value) ? count(array_unique($value)) : 1;

        return $this->getExistCount(
            $connection, $table, $column, $value, $parameters
        ) >= $expected;
    }

    /**
     * Parse the connection / table for the unique / exists rules.
     *
     * @param  string  $table
     * @return array
     */
    public function parseTable($table)
    {
        [$connection, $table] = Str::contains($table, '.')
        ? explode('.', $table, 2)
        : [null, $table];

        if (Str::contains($table, '\\') && class_exists($table) && is_a($table, Model::class, true)) {
            $model = new $table;

            $table = $model->getTable();
            $connection = $connection ?: $model->getConnectionName();

            if (Str::contains($table, '.') && Str::startsWith($table, $connection)) {
                $connection = null;
            }

            $idColumn = $model->getKeyName();
        }

        return [$connection, $table, isset($idColumn) ? $idColumn : null];
    }

    /**
     * Get the number of records that exist in storage.
     *
     * @param  mixed  $connection
     * @param  string  $table
     * @param  string  $column
     * @param  mixed  $value
     * @param  array  $parameters
     * @return int
     */
    protected function getExistCount($connection, $table, $column, $value, $parameters)
    {
        $extra = $this->getExtraConditions(
            array_values(array_slice($parameters, 2))
        );

        if ($this->currentRule instanceof Exists) {
            $extra = array_merge($extra, $this->currentRule->queryCallbacks());
        }

        return is_array($value)
                ? $this->getMultiCount($table, $column, $value, $extra)
                : $this->getCount($table, $column, $value, null, null, $extra);
    }

    /**
     * Get the extra conditions for a unique / exists rule.
     *
     * @param  array  $segments
     * @return array
     */
    protected function getExtraConditions(array $segments)
    {
        $extra = [];

        $count = count($segments);

        for ($i = 0; $i < $count; $i += 2) {
            $extra[$segments[$i]] = $segments[$i + 1];
        }

        return $extra;
    }

    /**
     * Count the number of objects in a collection having the given value.
     *
     * @param  string  $collection
     * @param  string  $column
     * @param  string  $value
     * @param  int|null  $excludeId
     * @param  string|null  $idColumn
     * @param  array  $extra
     * @return int
     */
    public function getCount($collection, $column, $value, $excludeId = null, $idColumn = null, array $extra = [])
    {
        $query = $this->table($collection)->where($column, '=', $value);

        if (! is_null($excludeId) && $excludeId !== 'NULL') {
            $query->where($idColumn ?: 'id', '<>', $excludeId);
        }

        return $this->addConditions($query, $extra)->count();
    }

    /**
     * Count the number of objects in a collection with the given values.
     *
     * @param  string  $collection
     * @param  string  $column
     * @param  array  $values
     * @param  array  $extra
     * @return int
     */
    public function getMultiCount($collection, $column, array $values, array $extra = [])
    {
        $query = $this->table($collection)->whereIn($column, $values);

        return $this->addConditions($query, $extra)->distinct()->count($column);
    }

    /**
     * Add the given conditions to the query.
     *
     * @param  \FluentCart\Framework\Database\Query\Builder  $query
     * @param  array  $conditions
     * @return \FluentCart\Framework\Database\Query\Builder
     */
    protected function addConditions($query, $conditions)
    {
        foreach ($conditions as $key => $value) {
            if ($value instanceof Closure) {
                $query->where(function ($query) use ($value) {
                    $value($query);
                });
            } else {
                $this->addWhere($query, $key, $value);
            }
        }

        return $query;
    }

    /**
     * Add a "where" clause to the given query.
     *
     * @param  \FluentCart\Framework\Database\Query\Builder  $query
     * @param  string  $key
     * @param  string  $extraValue
     * @return void
     */
    protected function addWhere($query, $key, $extraValue)
    {
        if ($extraValue === 'NULL') {
            $query->whereNull($key);
        } elseif ($extraValue === 'NOT_NULL') {
            $query->whereNotNull($key);
        } elseif (Str::startsWith($extraValue, '!')) {
            $query->where($key, '!=', mb_substr($extraValue, 1));
        } else {
            $query->where($key, $extraValue);
        }
    }

    /**
     * Get a query builder for the given table.
     *
     * @param  string  $table
     * @return \FluentCart\Framework\Database\Query\Builder
     */
    protected function table($table)
    {
        return App::make('db')->table($table);
    }

    /**
     * Validate the uniqueness of an attribute value on a given database table.
     *
     * If a database column is not specified, the attribute will be used.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @param  array  $parameters
     * @return bool
     */
    public function validateUnique($attribute, $value, $parameters)
    {
        $this->requireParameterCount(1, $parameters, 'unique');

        [$connection, $table, $idColumn] = $this->parseTable($parameters[0]);

        $column = isset($parameters[1]) ? $parameters[1] : $attribute;

        $id = null;

        if (isset($parameters[2])) {
            [$idColumn, $id] = $this->getUniqueIds($idColumn, $parameters);

            if (! is_null($id)) {
                $id = stripslashes($id);
            }
        }

        $extra = $this->getUniqueExtra($parameters);

        if ($this->currentRule instanceof Unique) {
            $extra = array_merge($extra, $this->currentRule->queryCallbacks());
        }

        return $this->getCount(
            $table, $column, $value, $id, $idColumn, $extra
        ) == 0;
    }

    /**
     * Get the excluded ID column and value for the unique rule.
     *
     * @param  string|null  $idColumn
     * @param  array  $parameters
     * @return array
     */
    protected function getUniqueIds($idColumn, $parameters)
    {
        $idColumn = $idColumn ?: ($parameters[3] ?: 'id');

        return [$idColumn, $this->prepareUniqueId($parameters[2])];
    }

    /**
     * Prepare the given ID for querying.
     *
     * @param  mixed  $id
     * @return int
     */
    protected function prepareUniqueId($id)
    {
        if (preg_match('/\[(.*)\]/', $id, $matches)) {
            $id = $this->getValue($matches[1]);
        }

        if (strtolower($id) === 'null') {
            $id = null;
        }

        if (filter_var($id, FILTER_VALIDATE_INT) !== false) {
            $id = (int) $id;
        }

        return $id;
    }

    /**
     * Get the extra conditions for a unique rule.
     *
     * @param  array  $parameters
     * @return array
     */
    protected function getUniqueExtra($parameters)
    {
        if (isset($parameters[4])) {
            return $this->getExtraConditions(array_slice($parameters, 4));
        }

        return [];
    }
}
