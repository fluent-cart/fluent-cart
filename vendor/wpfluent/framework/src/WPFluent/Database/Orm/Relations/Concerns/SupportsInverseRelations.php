<?php

namespace FluentCart\Framework\Database\Orm\Relations\Concerns;

use FluentCart\Framework\Database\Orm\RelationNotFoundException;
use FluentCart\Framework\Database\Orm\Model;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;

trait SupportsInverseRelations
{
    /**
     * The name of the inverse relationship.
     *
     * @var string|null
     */
    protected $inverseRelationship = null;

    /**
     * Instruct Orm to link the related models back to the parent after the relationship query has run.
     *
     * Alias of "chaperone".
     *
     * @param  string|null  $relation
     * @return $this
     */
    public function inverse(?string $relation = null)
    {
        return $this->chaperone($relation);
    }

    /**
     * Instruct Orm to link the related models back to the parent after the relationship query has run.
     *
     * @param  string|null  $relation
     * @return $this
     */
    public function chaperone(?string $relation = null)
    {
        $relation = $relation ?? $this->guessInverseRelation();

        if (! $relation || ! $this->getModel()->isRelation($relation)) {
            throw RelationNotFoundException::make($this->getModel(), $relation ?: 'null');
        }

        if ($this->inverseRelationship === null && $relation) {
            $this->query->afterQuery(function ($result) {
                return $this->inverseRelationship
                    ? $this->applyInverseRelationToCollection($result, $this->getParent())
                    : $result;
            });
        }

        $this->inverseRelationship = $relation;

        return $this;
    }

    /**
     * Guess the name of the inverse relationship.
     *
     * @return string|null
     */
    protected function guessInverseRelation()
    {
        return Arr::first(
            $this->getPossibleInverseRelations(),
            fn ($relation) => $relation && $this->getModel()->isRelation($relation)
        );
    }

    /**
     * Get the possible inverse relations for the parent model.
     *
     * @return array<non-empty-string>
     */
    protected function getPossibleInverseRelations(): array
    {
        return array_filter(array_unique([
            Str::camel(Str::beforeLast($this->getForeignKeyName(), $this->getParent()->getKeyName())),
            Str::camel(Str::beforeLast($this->getParent()->getForeignKey(), $this->getParent()->getKeyName())),
            Str::camel(static::classBasename($this->getParent())),
            'owner',
            get_class($this->getParent()) === get_class($this->getModel()) ? 'parent' : null,
        ]));
    }

    /**
     * Set the inverse relation on all models in a collection.
     *
     * @param  \FluentCart\Framework\Database\Orm\Collection  $models
     * @param  \FluentCart\Framework\Database\Orm\Model|null  $parent
     * @return \FluentCart\Framework\Database\Orm\Collection
     */
    protected function applyInverseRelationToCollection($models, ?Model $parent = null)
    {
        $parent ??= $this->getParent();

        foreach ($models as $model) {
            $this->applyInverseRelationToModel($model, $parent);
        }

        return $models;
    }

    /**
     * Set the inverse relation on a model.
     *
     * @param  \FluentCart\Framework\Database\Orm\Model  $model
     * @param  \FluentCart\Framework\Database\Orm\Model|null  $parent
     * @return \FluentCart\Framework\Database\Orm\Model
     */
    protected function applyInverseRelationToModel(Model $model, ?Model $parent = null)
    {
        if ($inverse = $this->getInverseRelationship()) {
            $parent ??= $this->getParent();

            $model->setRelation($inverse, $parent);
        }

        return $model;
    }

    /**
     * Get the name of the inverse relationship.
     *
     * @return string|null
     */
    public function getInverseRelationship()
    {
        return $this->inverseRelationship;
    }

    /**
     * Remove the chaperone / inverse relationship for this query.
     *
     * Alias of "withoutChaperone".
     *
     * @return $this
     */
    public function withoutInverse()
    {
        return $this->withoutChaperone();
    }

    /**
     * Remove the chaperone / inverse relationship for this query.
     *
     * @return $this
     */
    public function withoutChaperone()
    {
        $this->inverseRelationship = null;

        return $this;
    }
}
