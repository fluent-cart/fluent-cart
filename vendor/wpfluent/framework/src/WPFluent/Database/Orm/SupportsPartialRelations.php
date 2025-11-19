<?php

namespace FluentCart\Framework\Database\Orm;

interface SupportsPartialRelations
{
    /**
     * Indicate that the relation is a single result of a larger one-to-many relationship.
     *
     * @param  string|null  $column
     * @param  string|\Closure|null  $aggregate
     * @param  string  $relation
     * @return self
     */
    public function ofMany($column = 'id', $aggregate = 'MAX', $relation = null);

    /**
     * Determine whether the relationship is a one-of-many relationship.
     *
     * @return bool
     */
    public function isOneOfMany();

    /**
     * Get the one of many inner join subselect query builder instance.
     *
     * @return \FluentCart\Framework\Database\Orm\Builder|void
     */
    public function getOneOfManySubQuery();
}
