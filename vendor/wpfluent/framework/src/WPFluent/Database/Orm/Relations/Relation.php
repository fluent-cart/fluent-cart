<?php

namespace FluentCart\Framework\Database\Orm\Relations;

use Closure;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Helper;
use FluentCart\Framework\Support\ForwardsCalls;
use FluentCart\Framework\Support\MacroableTrait;
use FluentCart\Framework\Support\HelperFunctionsTrait;
use FluentCart\Framework\Database\Orm\Model;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Database\Orm\Collection;
use FluentCart\Framework\Database\Query\Expression;
use FluentCart\Framework\Database\Orm\ModelNotFoundException;
use FluentCart\Framework\Database\MultipleRecordsFoundException;

/**
 * @mixin \FluentCart\Framework\Database\Orm\Builder
 */
abstract class Relation
{
    use HelperFunctionsTrait;
    use ForwardsCalls, MacroableTrait {
        __call as macroCall;
    }

    /**
     * The Orm query builder instance.
     *
     * @var \FluentCart\Framework\Database\Orm\Builder
     */
    protected $query;

    /**
     * The parent model instance.
     *
     * @var \FluentCart\Framework\Database\Orm\Model
     */
    protected $parent;

    /**
     * The related model instance.
     *
     * @var \FluentCart\Framework\Database\Orm\Model
     */
    protected $related;

    /**
     * Indicates whether the eagerly loaded relation
     * should implicitly return an empty collection.
     *
     * @var bool
     */
    protected $eagerKeysWereEmpty = false;

    /**
     * Indicates if the relation is adding constraints.
     *
     * @var bool
     */
    protected static $constraints = true;

    /**
     * An array to map class names to their morph names in the database.
     *
     * @var array
     */
    public static $morphMap = [];

    /**
     * Prevents morph relationships without a morph map.
     *
     * @var bool
     */
    protected static $requireMorphMap = false;

    /**
     * The count of self joins.
     *
     * @var int
     */
    protected static $selfJoinCount = 0;

    /**
     * Create a new relation instance.
     *
     * @param  \FluentCart\Framework\Database\Orm\Builder  $query
     * @param  \FluentCart\Framework\Database\Orm\Model  $parent
     * @return void
     */
    public function __construct(Builder $query, Model $parent)
    {
        $this->query = $query;
        $this->parent = $parent;
        $this->related = $query->getModel();

        $this->addConstraints();
    }

    /**
     * Run a callback with constraints disabled on the relation.
     *
     * @param  \Closure  $callback
     * @return mixed
     */
    public static function noConstraints(Closure $callback)
    {
        $previous = static::$constraints;

        static::$constraints = false;

        // When resetting the relation where clause, we want to shift the first element
        // off of the bindings, leaving only the constraints that the developers put
        // as "extra" on the relationships, and not original relation constraints.
        try {
            return $callback();
        } finally {
            static::$constraints = $previous;
        }
    }

    /**
     * Set the base constraints on the relation query.
     *
     * @return void
     */
    abstract public function addConstraints();

    /**
     * Set the constraints for an eager load of the relation.
     *
     * @param  array  $models
     * @return void
     */
    abstract public function addEagerConstraints(array $models);

    /**
     * Initialize the relation on a set of models.
     *
     * @param  array  $models
     * @param  string  $relation
     * @return array
     */
    abstract public function initRelation(array $models, $relation);

    /**
     * Match the eagerly loaded results to their parents.
     *
     * @param  array  $models
     * @param  \FluentCart\Framework\Database\Orm\Collection  $results
     * @param  string  $relation
     * @return array
     */
    abstract public function match(array $models, Collection $results, $relation);

    /**
     * Get the results of the relationship.
     *
     * @return mixed
     */
    abstract public function getResults();

    /**
     * Get the relationship for eager loading.
     *
     * @return \FluentCart\Framework\Database\Orm\Collection
     */
    public function getEager()
    {
        return $this->eagerKeysWereEmpty
                    ? $this->query->getModel()->newCollection()
                    : $this->get();
    }

    /**
     * Execute the query and get the first result if it's the sole matching record.
     *
     * @param  array|string  $columns
     * @return \FluentCart\Framework\Database\Orm\Model
     *
     * @throws \FluentCart\Framework\Database\Orm\ModelNotFoundException
     * @throws \FluentCart\Framework\Database\MultipleRecordsFoundException
     */
    public function sole($columns = ['*'])
    {
        $result = $this->take(2)->get($columns);

        $count = $result->count();

        if ($count === 0) {
            throw (new ModelNotFoundException)->setModel(get_class($this->related));
        }

        if ($count > 1) {
            throw new MultipleRecordsFoundException($count);
        }

        return $result->first();
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param  array  $columns
     * @return \FluentCart\Framework\Database\Orm\Collection
     */
    public function get($columns = ['*'])
    {
        return $this->query->get($columns);
    }

    /**
     * Touch all of the related models for the relationship.
     *
     * @return void
     */
    public function touch()
    {
        $model = $this->getRelated();

        if (! $model::isIgnoringTouch()) {
            $this->rawUpdate([
                $model->getUpdatedAtColumn() => $model->freshTimestampString(),
            ]);
        }
    }

    /**
     * Run a raw update against the base query.
     *
     * @param  array  $attributes
     * @return int
     */
    public function rawUpdate(array $attributes = [])
    {
        return $this->query->withoutGlobalScopes()->update($attributes);
    }

    /**
     * Add the constraints for a relationship count query.
     *
     * @param  \FluentCart\Framework\Database\Orm\Builder  $query
     * @param  \FluentCart\Framework\Database\Orm\Builder  $parentQuery
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    public function getRelationExistenceCountQuery(Builder $query, Builder $parentQuery)
    {
        return $this->getRelationExistenceQuery(
            $query, $parentQuery, new Expression('count(*)')
        )->setBindings([], 'select');
    }

    /**
     * Add the constraints for an internal relationship existence query.
     *
     * Essentially, these queries compare on column names like whereColumn.
     *
     * @param  \FluentCart\Framework\Database\Orm\Builder  $query
     * @param  \FluentCart\Framework\Database\Orm\Builder  $parentQuery
     * @param  array|mixed  $columns
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    public function getRelationExistenceQuery(
        Builder $query,
        Builder $parentQuery,
        $columns = ['*']
    ) {
        return $query->select($columns)->whereColumn(
            $this->getQualifiedParentKeyName(), '=', $this->getExistenceCompareKey()
        );
    }

    /**
     * Get a relationship join table hash.
     *
     * @param  bool  $incrementJoinCount
     * @return string
     */
    public function getRelationCountHash($incrementJoinCount = true)
    {
        return 'laravel_reserved_'.(
            $incrementJoinCount ? static::$selfJoinCount++ : static::$selfJoinCount
        );
    }

    /**
     * Get all of the primary keys for an array of models.
     *
     * @param  array  $models
     * @param  string|null  $key
     * @return array
     */
    protected function getKeys(array $models, $key = null)
    {
        return Collection::make($models)->map(function ($value) use ($key) {
            return $key ? $value->getAttribute($key) : $value->getKey();
        })->values()->unique(null, true)->sort()->all();
    }

    /**
     * Get the query builder that will contain the relationship constraints.
     *
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    protected function getRelationQuery()
    {
        return $this->query;
    }

    /**
     * Get the underlying query for the relation.
     *
     * @return \FluentCart\Framework\Database\Orm\Builder
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * Get the base query builder driving the Orm builder.
     *
     * @return \FluentCart\Framework\Database\Query\Builder
     */
    public function getBaseQuery()
    {
        return $this->query->getQuery();
    }

    /**
     * Get a base query builder instance.
     *
     * @return \FluentCart\Framework\Database\Query\Builder
     */
    public function toBase()
    {
        return $this->query->toBase();
    }

    /**
     * Get the parent model of the relation.
     *
     * @return \FluentCart\Framework\Database\Orm\Model
     */
    public function getParent()
    {
        return $this->parent;
    }

    /**
     * Get the fully qualified parent key name.
     *
     * @return string
     */
    public function getQualifiedParentKeyName()
    {
        return $this->parent->getQualifiedKeyName();
    }

    /**
     * Get the related model of the relation.
     *
     * @return \FluentCart\Framework\Database\Orm\Model
     */
    public function getRelated()
    {
        return $this->related;
    }

    /**
     * Get the name of the "created at" column.
     *
     * @return string
     */
    public function createdAt()
    {
        return $this->parent->getCreatedAtColumn();
    }

    /**
     * Get the name of the "updated at" column.
     *
     * @return string
     */
    public function updatedAt()
    {
        return $this->parent->getUpdatedAtColumn();
    }

    /**
     * Get the name of the related model's "updated at" column.
     *
     * @return string
     */
    public function relatedUpdatedAt()
    {
        return $this->related->getUpdatedAtColumn();
    }

    /**
     * Add a whereIn eager constraint for the given set of model keys to be loaded.
     *
     * @param  string  $whereIn
     * @param  string  $key
     * @param  array  $modelKeys
     * @param  \FluentCart\Framework\Database\Orm\Builder|null  $query
     * @return void
     */
    protected function whereInEager(
        string $whereIn,
        string $key, array $modelKeys,
        ?Builder $query = null
    ) {
        ($query ?? $this->query)->{$whereIn}($key, $modelKeys);

        if ($modelKeys === []) {
            $this->eagerKeysWereEmpty = true;
        }
    }

    /**
     * Get the name of the "where in" method for eager loading.
     *
     * @param  \FluentCart\Framework\Database\Orm\Model  $model
     * @param  string  $key
     * @return string
     */
    protected function whereInMethod(Model $model, $key)
    {
        return $model->getKeyName() === Helper::last(explode('.', $key))
                    && in_array($model->getKeyType(), ['int', 'integer'])
                        ? 'whereIntegerInRaw'
                        : 'whereIn';
    }

    /**
     * Prevent polymorphic relationships from being used without model mappings.
     *
     * @param  bool  $requireMorphMap
     * @return void
     */
    public static function requireMorphMap($requireMorphMap = true)
    {
        static::$requireMorphMap = $requireMorphMap;
    }

    /**
     * Determine if polymorphic relationships require explicit model mapping.
     *
     * @return bool
     */
    public static function requiresMorphMap()
    {
        return static::$requireMorphMap;
    }

    /**
     * Define the morph map for polymorphic relations and require all morphed models to be explicitly mapped.
     *
     * @param  array  $map
     * @param  bool  $merge
     * @return array
     */
    public static function enforceMorphMap(array $map, $merge = true)
    {
        static::requireMorphMap();

        return static::morphMap($map, $merge);
    }

    /**
     * Set or get the morph map for polymorphic relations.
     *
     * @param  array|null  $map
     * @param  bool  $merge
     * @return array
     */
    public static function morphMap(?array $map = null, $merge = true)
    {
        $map = static::buildMorphMapFromModels($map);

        if (is_array($map)) {
            static::$morphMap = $merge && static::$morphMap
                            ? $map + static::$morphMap : $map;
        }

        return static::$morphMap;
    }

    /**
     * Builds a table-keyed array from model class names.
     *
     * @param  string[]|null  $models
     * @return array|null
     */
    protected static function buildMorphMapFromModels(?array $models = null)
    {
        if (is_null($models) || Arr::isAssoc($models)) {
            return $models;
        }

        return array_combine(array_map(function ($model) {
            return (new $model)->getTable();
        }, $models), $models);
    }

    /**
     * Get the model associated with a custom polymorphic type.
     *
     * @param  string  $alias
     * @return string|null
     */
    public static function getMorphedModel($alias)
    {
        return static::$morphMap[$alias] ?? null;
    }

    /**
     * Get the alias associated with a custom polymorphic class.
     *
     * @param  string  $className
     * @return int|string
     */
    public static function getMorphAlias(string $className)
    {
        return array_search($className, static::$morphMap, true) ?: $className;
    }

    /**
     * Handle dynamic method calls to the relationship.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        return $this->forwardDecoratedCallTo($this->query, $method, $parameters);
    }

    /**
     * Force a clone of the underlying query builder when cloning.
     *
     * @return void
     */
    public function __clone()
    {
        $this->query = clone $this->query;
    }
}
