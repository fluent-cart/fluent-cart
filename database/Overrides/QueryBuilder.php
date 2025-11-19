<?php

namespace FluentCart\Database\Overrides;

use DateTimeInterface;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Database\Query\Builder;
use FluentCart\Framework\Database\Query\Expression;


class QueryBuilder extends Builder
{

    public function whereDate($column, $operator, $value = null, $boolean = 'and')
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        $value = $this->flattenValue($value);

        if ($value instanceof DateTimeInterface) {
            $value = DateTime::anyTimeToGmt($value)->format('Y-m-d');
        }

        return $this->addDateBasedWhere('Date', $column, $operator, $value, $boolean);
    }

    /**
     * Add an "or where date" statement to the query.
     *
     * @param string $column
     * @param string $operator
     * @param \DateTimeInterface|string|null $value
     * @return $this
     */
    public function orWhereDate($column, $operator, $value = null)
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        return $this->whereDate($column, $operator, $value, 'or');
    }

    /**
     * Add a "where time" statement to the query.
     *
     * @param string $column
     * @param string $operator
     * @param \DateTimeInterface|string|null $value
     * @param string $boolean
     * @return $this
     */
    public function whereTime($column, $operator, $value = null, $boolean = 'and')
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        $value = $this->flattenValue($value);

        if ($value instanceof DateTimeInterface) {
            $value = DateTime::anyTimeToGmt($value)->format('H:i:s');
        }

        return $this->addDateBasedWhere('Time', $column, $operator, $value, $boolean);
    }

    /**
     * Add an "or where time" statement to the query.
     *
     * @param string $column
     * @param string $operator
     * @param \DateTimeInterface|string|null $value
     * @return $this
     */
    public function orWhereTime($column, $operator, $value = null)
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        return $this->whereTime($column, $operator, $value, 'or');
    }

    /**
     * Add a "where day" statement to the query.
     *
     * @param string $column
     * @param string $operator
     * @param \DateTimeInterface|string|null $value
     * @param string $boolean
     * @return $this
     */
    public function whereDay($column, $operator, $value = null, $boolean = 'and')
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        $value = $this->flattenValue($value);

        if ($value instanceof DateTimeInterface) {
            $value = DateTime::anyTimeToGmt($value)->format('d');
        }

        if (!$value instanceof Expression) {
            $value = sprintf('%02d', $value);
        }

        return $this->addDateBasedWhere('Day', $column, $operator, $value, $boolean);
    }

    /**
     * Add an "or where day" statement to the query.
     *
     * @param string $column
     * @param string $operator
     * @param \DateTimeInterface|string|null $value
     * @return $this
     */
    public function orWhereDay($column, $operator, $value = null)
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        return $this->whereDay($column, $operator, $value, 'or');
    }

    /**
     * Add a "where month" statement to the query.
     *
     * @param string $column
     * @param string $operator
     * @param \DateTimeInterface|string|null $value
     * @param string $boolean
     * @return $this
     */
    public function whereMonth($column, $operator, $value = null, $boolean = 'and')
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        $value = $this->flattenValue($value);

        if ($value instanceof DateTimeInterface) {
            $value = DateTime::anyTimeToGmt($value)->format('m');
        }

        if (!$value instanceof Expression) {
            $value = sprintf('%02d', $value);
        }

        return $this->addDateBasedWhere('Month', $column, $operator, $value, $boolean);
    }

    /**
     * Add an "or where month" statement to the query.
     *
     * @param string $column
     * @param string $operator
     * @param \DateTimeInterface|string|null $value
     * @return $this
     */
    public function orWhereMonth($column, $operator, $value = null)
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        return $this->whereMonth($column, $operator, $value, 'or');
    }

    /**
     * Add a "where year" statement to the query.
     *
     * @param string $column
     * @param string $operator
     * @param \DateTimeInterface|string|int|null $value
     * @param string $boolean
     * @return $this
     */
    public function whereYear($column, $operator, $value = null, $boolean = 'and')
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        $value = $this->flattenValue($value);

        if ($value instanceof DateTimeInterface) {
            $value = DateTime::anyTimeToGmt($value)->format('Y');
        }

        return $this->addDateBasedWhere('Year', $column, $operator, $value, $boolean);
    }

    /**
     * Add an "or where year" statement to the query.
     *
     * @param string $column
     * @param string $operator
     * @param \DateTimeInterface|string|int|null $value
     * @return $this
     */
    public function orWhereYear($column, $operator, $value = null)
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        return $this->whereYear($column, $operator, $value, 'or');
    }


}