<?php

namespace FluentCart\Framework\Database\Orm\Relations;

use FluentCart\Framework\Database\Orm\Model;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Database\Orm\Collection;
use FluentCart\Framework\Database\Query\JoinClause;
use FluentCart\Framework\Database\Orm\SupportsPartialRelations;
use FluentCart\Framework\Database\Orm\Relations\Concerns\CanBeOneOfMany;
use FluentCart\Framework\Database\Orm\Relations\Concerns\ComparesRelatedModels;
use FluentCart\Framework\Database\Orm\Relations\Concerns\SupportsDefaultModels;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 */
class MorphOne extends MorphOneOrMany implements SupportsPartialRelations
{
    use CanBeOneOfMany, ComparesRelatedModels, SupportsDefaultModels;

    /** @inheritDoc */
    public function getResults()
    {
        if (is_null($this->getParentKey())) {
            return $this->getDefaultFor($this->parent);
        }

        return $this->query->first() ?: $this->getDefaultFor($this->parent);
    }

    /** @inheritDoc */
    public function initRelation(array $models, $relation)
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->getDefaultFor($model));
        }

        return $models;
    }

    /** @inheritDoc */
    public function match(array $models, Collection $results, $relation)
    {
        return $this->matchOne($models, $results, $relation);
    }

    /** @inheritDoc */
    public function getRelationExistenceQuery(
        Builder $query, Builder $parentQuery, $columns = ['*']
    ) {
        if ($this->isOneOfMany()) {
            $this->mergeOneOfManyJoinsTo($query);
        }

        return parent::getRelationExistenceQuery($query, $parentQuery, $columns);
    }

    /**
     * Add constraints for inner join subselect for one of many relationships.
     *
     * @param  \FluentCart\Framework\Database\Orm\Builder<TRelatedModel>  $query
     * @param  string|null  $column
     * @param  string|null  $aggregate
     * @return void
     */
    public function addOneOfManySubQueryConstraints(
        Builder $query, $column = null, $aggregate = null
    ) {
        $query->addSelect($this->foreignKey, $this->morphType);
    }

    /**
     * Get the columns that should be selected by the one of many subquery.
     *
     * @return array|string
     */
    public function getOneOfManySubQuerySelectColumns()
    {
        return [$this->foreignKey, $this->morphType];
    }

    /**
     * Add join query constraints for one of many relationships.
     *
     * @param  \FluentCart\Framework\Database\Query\JoinClause  $join
     * @return void
     */
    public function addOneOfManyJoinSubQueryConstraints(JoinClause $join)
    {
        $join
            ->on(
                $this->qualifySubSelectColumn($this->morphType),
                '=',
                $this->qualifyRelatedColumn($this->morphType))
            ->on(
                $this->qualifySubSelectColumn($this->foreignKey),
                '=',
                $this->qualifyRelatedColumn($this->foreignKey)
            );
    }

    /**
     * Make a new related instance for the given model.
     *
     * @param  TDeclaringModel  $parent
     * @return TRelatedModel
     */
    public function newRelatedInstanceFor(Model $parent)
    {
        return $this->related->newInstance()
            ->setAttribute(
                $this->getForeignKeyName(), $parent->{$this->localKey}
            )->setAttribute($this->getMorphType(), $this->morphClass);
    }

    /**
     * Get the value of the model's foreign key.
     *
     * @param  TRelatedModel  $model
     * @return int|string
     */
    protected function getRelatedKeyFrom(Model $model)
    {
        return $model->getAttribute($this->getForeignKeyName());
    }
}
