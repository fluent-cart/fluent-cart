<?php

namespace FluentCart\Framework\Database\Orm;

use LogicException;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;
use FluentCart\Framework\Support\ArrayableInterface;
use FluentCart\Framework\Support\Collection as BaseCollection;
use FluentCart\Framework\Database\Orm\Relations\Concerns\InteractsWithDictionary;

class Collection extends BaseCollection
{
    use InteractsWithDictionary;

    /**
     * Find a model in the collection by key.
     *
     * @param  mixed  $key
     * @param  mixed  $default
     * @return \FluentCart\Framework\Database\Orm\Model|static|null
     */
    public function find($key, $default = null)
    {
        if ($key instanceof Model) {
            $key = $key->getKey();
        }

        if ($key instanceof ArrayableInterface) {
            $key = $key->toArray();
        }

        if (is_array($key)) {
            if ($this->isEmpty()) {
                return new static;
            }

            return $this->whereIn($this->first()->getKeyName(), $key);
        }

        return Arr::first($this->items, fn ($model) => $model->getKey() == $key, $default);
    }

    /**
     * Load a set of relationships onto the collection.
     *
     * @param  array|string  $relations
     * @return $this
     */
    public function load($relations)
    {
        if ($this->isNotEmpty()) {
            if (is_string($relations)) {
                $relations = func_get_args();
            }

            $query = $this->first()->newQueryWithoutRelationships()->with($relations);

            $this->items = $query->eagerLoadRelations($this->items);
        }

        return $this;
    }

    /**
     * Load a set of aggregations over relationship's column onto the collection.
     *
     * @param  array|string  $relations
     * @param  string  $column
     * @param  string  $function
     * @return $this
     */
    public function loadAggregate($relations, $column, $function = null)
    {
        if ($this->isEmpty()) {
            return $this;
        }

        $models = $this->first()->newModelQuery()
            ->whereKey($this->modelKeys())
            ->select($this->first()->getKeyName())
            ->withAggregate($relations, $column, $function)
            ->get()
            ->keyBy($this->first()->getKeyName());

        $attributes = Arr::except(
            array_keys($models->first()->getAttributes()),
            $models->first()->getKeyName()
        );

        $this->each(function ($model) use ($models, $attributes) {
            $extraAttributes = Arr::only($models->get($model->getKey())->getAttributes(), $attributes);

            $model->forceFill($extraAttributes)
                ->syncOriginalAttributes($attributes)
                ->mergeCasts($models->get($model->getKey())->getCasts());
        });

        return $this;
    }

    /**
     * Load a set of relationship counts onto the collection.
     *
     * @param  array|string  $relations
     * @return $this
     */
    public function loadCount($relations)
    {
        return $this->loadAggregate($relations, '*', 'count');
    }

    /**
     * Load a set of relationship's max column values onto the collection.
     *
     * @param  array|string  $relations
     * @param  string  $column
     * @return $this
     */
    public function loadMax($relations, $column)
    {
        return $this->loadAggregate($relations, $column, 'max');
    }

    /**
     * Load a set of relationship's min column values onto the collection.
     *
     * @param  array|string  $relations
     * @param  string  $column
     * @return $this
     */
    public function loadMin($relations, $column)
    {
        return $this->loadAggregate($relations, $column, 'min');
    }

    /**
     * Load a set of relationship's column summations onto the collection.
     *
     * @param  array|string  $relations
     * @param  string  $column
     * @return $this
     */
    public function loadSum($relations, $column)
    {
        return $this->loadAggregate($relations, $column, 'sum');
    }

    /**
     * Load a set of relationship's average column values onto the collection.
     *
     * @param  array|string  $relations
     * @param  string  $column
     * @return $this
     */
    public function loadAvg($relations, $column)
    {
        return $this->loadAggregate($relations, $column, 'avg');
    }

    /**
     * Load a set of related existences onto the collection.
     *
     * @param  array|string  $relations
     * @return $this
     */
    public function loadExists($relations)
    {
        return $this->loadAggregate($relations, '*', 'exists');
    }

    /**
     * Load a set of relationships onto the collection if they are not already eager loaded.
     *
     * @param  array|string  $relations
     * @return $this
     */
    public function loadMissing($relations)
    {
        if (is_string($relations)) {
            $relations = func_get_args();
        }

        foreach ($relations as $key => $value) {
            if (is_numeric($key)) {
                $key = $value;
            }

            $segments = explode('.', explode(':', $key)[0]);

            if (Str::contains($key, ':')) {
                $segments[count($segments) - 1] .= ':'.explode(':', $key)[1];
            }

            $path = [];

            foreach ($segments as $segment) {
                $path[] = [$segment => $segment];
            }

            if (is_callable($value)) {
                $path[count($segments) - 1][end($segments)] = $value;
            }

            $this->loadMissingRelation($this, $path);
        }

        return $this;
    }

    /**
     * Load a relationship path if it is not already eager loaded.
     *
     * @param  \FluentCart\Framework\Database\Orm\Collection  $models
     * @param  array  $path
     * @return void
     */
    protected function loadMissingRelation(self $models, array $path)
    {
        $relation = array_shift($path);

        $name = explode(':', key($relation))[0];

        if (is_string(reset($relation))) {
            $relation = reset($relation);
        }

        $models->filter(function ($model) use ($name) {
            return ! is_null($model) && ! $model->relationLoaded($name);
        })->load($relation);

        if (empty($path)) {
            return;
        }

        $models = $models->pluck($name)->whereNotNull();

        if ($models->first() instanceof BaseCollection) {
            $models = $models->collapse();
        }

        $this->loadMissingRelation(new static($models), $path);
    }

    /**
     * Load a set of relationships onto the mixed relationship collection.
     *
     * @param  string  $relation
     * @param  array  $relations
     * @return $this
     */
    public function loadMorph($relation, $relations)
    {
        $this->pluck($relation)
            ->filter()
            ->groupBy(function ($model) {
                return get_class($model);
            })
            ->each(function ($models, $className) use ($relations) {
                static::make($models)->load($relations[$className] ?? []);
            });

        return $this;
    }

    /**
     * Load a set of relationship counts onto the mixed relationship collection.
     *
     * @param  string  $relation
     * @param  array  $relations
     * @return $this
     */
    public function loadMorphCount($relation, $relations)
    {
        $this->pluck($relation)
            ->filter()
            ->groupBy(function ($model) {
                return get_class($model);
            })
            ->each(function ($models, $className) use ($relations) {
                static::make($models)->loadCount($relations[$className] ?? []);
            });

        return $this;
    }

    /**
     * Determine if a key exists in the collection.
     *
     * @param  mixed  $key
     * @param  mixed  $operator
     * @param  mixed  $value
     * @return bool
     */
    public function contains($key, $operator = null, $value = null)
    {
        if (func_num_args() > 1 || $this->useAsCallable($key)) {
            return parent::contains(...func_get_args());
        }

        if ($key instanceof Model) {
            return parent::contains(function ($model) use ($key) {
                return $model->is($key);
            });
        }

        return parent::contains(function ($model) use ($key) {
            return $model->getKey() == $key;
        });
    }

    /**
     * Get the array of primary keys.
     *
     * @return array
     */
    public function modelKeys()
    {
        return array_map(function ($model) {
            return $model->getKey();
        }, $this->items);
    }

    /**
     * Merge the collection with the given items.
     *
     * @param  \ArrayAccess|array  $items
     * @return static
     */
    public function merge($items)
    {
        $dictionary = $this->getDictionary();

        foreach ($items as $item) {
            $dictionary[$this->getDictionaryKey($item->getKey())] = $item;
        }

        return new static(array_values($dictionary));
    }

    /**
     * Run a map over each of the items.
     *
     * @param  callable  $callback
     * @return \FluentCart\Framework\Support\Collection|static
     */
    public function map(callable $callback)
    {
        $result = parent::map($callback);

        return $result->contains(function ($item) {
            return ! $item instanceof Model;
        }) ? $result->toBase() : $result;
    }

    /**
     * Run an associative map over each of the items.
     *
     * The callback should return an associative array with a single key / value pair.
     *
     * @param  callable  $callback
     * @return \FluentCart\Framework\Support\Collection|static
     */
    public function mapWithKeys(callable $callback)
    {
        $result = parent::mapWithKeys($callback);

        return $result->contains(function ($item) {
            return ! $item instanceof Model;
        }) ? $result->toBase() : $result;
    }

    /**
     * Reload a fresh model instance from the database for all the entities.
     *
     * @param  array|string  $with
     * @return static
     */
    public function fresh($with = [])
    {
        if ($this->isEmpty()) {
            return new static;
        }

        $model = $this->first();

        $freshModels = $model->newQueryWithoutScopes()
            ->with(is_string($with) ? func_get_args() : $with)
            ->whereIn($model->getKeyName(), $this->modelKeys())
            ->get()
            ->getDictionary();

        return $this->filter(function ($model) use ($freshModels) {
            return $model->exists && isset($freshModels[$model->getKey()]);
        })
        ->map(function ($model) use ($freshModels) {
            return $freshModels[$model->getKey()];
        });
    }

    /**
     * Diff the collection with the given items.
     *
     * @param  \ArrayAccess|array  $items
     * @return static
     */
    public function diff($items)
    {
        $diff = new static;

        $dictionary = $this->getDictionary($items);

        foreach ($this->items as $item) {
            if (! isset($dictionary[$this->getDictionaryKey($item->getKey())])) {
                $diff->add($item);
            }
        }

        return $diff;
    }

    /**
     * Intersect the collection with the given items.
     *
     * @param  \ArrayAccess|array  $items
     * @return static
     */
    public function intersect($items)
    {
        $intersect = new static;

        if (empty($items)) {
            return $intersect;
        }

        $dictionary = $this->getDictionary($items);

        foreach ($this->items as $item) {
            if (isset($dictionary[$this->getDictionaryKey($item->getKey())])) {
                $intersect->add($item);
            }
        }

        return $intersect;
    }

    /**
     * Return only unique items from the collection.
     *
     * @param  string|callable|null  $key
     * @param  bool  $strict
     * @return static
     */
    public function unique($key = null, $strict = false)
    {
        if (! is_null($key)) {
            return parent::unique($key, $strict);
        }

        return new static(array_values($this->getDictionary()));
    }

    /**
     * Returns only the models from the collection with the specified keys.
     *
     * @param  mixed  $keys
     * @return static
     */
    public function only($keys)
    {
        if (is_null($keys)) {
            return new static($this->items);
        }

        $dictionary = Arr::only($this->getDictionary(), array_map(
            fn($value) => $this->getDictionaryKey($value),
            (array) $keys)
        );

        return new static(array_values($dictionary));
    }

    /**
     * Returns all models in the collection except the models with specified keys.
     *
     * @param  mixed  $keys
     * @return static
     */
    public function except($keys)
    {
        if (is_null($keys)) {
            return new static($this->items);
        }

        $dictionary = Arr::except($this->getDictionary(), array_map(
            fn($value) => $this->getDictionaryKey($value),
            (array) $keys)
        );

        return new static(array_values($dictionary));
    }

    /**
     * Make the given, typically visible, attributes hidden across the entire collection.
     *
     * @param  array|string  $attributes
     * @return $this
     */
    public function makeHidden($attributes)
    {
        return $this->each->makeHidden($attributes);
    }

    /**
     * Make the given, typically hidden, attributes visible across the entire collection.
     *
     * @param  array|string  $attributes
     * @return $this
     */
    public function makeVisible($attributes)
    {
        return $this->each->makeVisible($attributes);
    }

    /**
     * Set the visible attributes across the entire collection.
     *
     * @param  array<int, string>  $visible
     * @return $this
     */
    public function setVisible($visible)
    {
        return $this->each->setVisible($visible);
    }

    /**
     * Set the hidden attributes across the entire collection.
     *
     * @param  array<int, string>  $hidden
     * @return $this
     */
    public function setHidden($hidden)
    {
        return $this->each->setHidden($hidden);
    }

    /**
     * Append an attribute across the entire collection.
     *
     * @param  array|string  $attributes
     * @return $this
     */
    public function append($attributes)
    {
        return $this->each->append($attributes);
    }

    /**
     * Get a dictionary keyed by primary keys.
     *
     * @param  \ArrayAccess|array|null  $items
     * @return array
     */
    public function getDictionary($items = null)
    {
        $items = is_null($items) ? $this->items : $items;

        $dictionary = [];

        foreach ($items as $value) {
            $dictionary[$this->getDictionaryKey($value->getKey())] = $value;
        }

        return $dictionary;
    }

    /**
     * Count the number of items in the collection by a field or using a callback.
     *
     * @param  (callable(mixed, mixed): array-key)|string|null  $countBy
     * @return \FluentCart\Framework\Support\Collection
     */
    public function countBy($countBy = null)
    {
        return $this->toBase()->countBy($countBy);
    }

    /**
     * The following methods are intercepted to always return base collections.
     */

    /**
     * Get an array with the values of a given key.
     *
     * @param  string|array  $value
     * @param  string|null  $key
     * @return \FluentCart\Framework\Support\Collection
     */
    public function pluck($value, $key = null)
    {
        return $this->toBase()->pluck($value, $key);
    }

    /**
     * Get the comparison function to detect duplicates.
     *
     * @param  bool  $strict
     * @return callable(mixed, mixed): bool
     */
    protected function duplicateComparator($strict)
    {
        return fn ($a, $b) => $a->is($b);
    }

    /**
     * Get the keys of the collection items.
     *
     * @return \FluentCart\Framework\Support\Collection
     */
    public function keys()
    {
        return $this->toBase()->keys();
    }

    /**
     * Zip the collection together with one or more arrays.
     *
     * @param  mixed  ...$items
     * @return \FluentCart\Framework\Support\Collection
     */
    public function zip($items)
    {
        return $this->toBase()->zip(...func_get_args());
    }

    /**
     * Collapse the collection of items into a single array.
     *
     * @return \FluentCart\Framework\Support\Collection
     */
    public function collapse()
    {
        return $this->toBase()->collapse();
    }

    /**
     * Get a flattened array of the items in the collection.
     *
     * @param  int|float  $depth
     * @return \FluentCart\Framework\Support\Collection
     */
    public function flatten($depth = INF)
    {
        return $this->toBase()->flatten($depth);
    }

    /**
     * Flip the items in the collection.
     *
     * @return \FluentCart\Framework\Support\Collection
     */
    public function flip()
    {
        return $this->toBase()->flip();
    }

    /**
     * Pad collection to the specified length with a value.
     *
     * @param  int  $size
     * @param  mixed  $value
     * @return \FluentCart\Framework\Support\Collection
     */
    public function pad($size, $value)
    {
        return $this->toBase()->pad($size, $value);
    }

    /**
     * Get the Orm query builder from the collection.
     *
     * @return \FluentCart\Framework\Database\Orm\Builder
     *
     * @throws \LogicException
     */
    public function toQuery()
    {
        $model = $this->first();

        if (! $model) {
            throw new LogicException('Unable to create query for empty collection.');
        }

        $class = get_class($model);

        if ($this->filter(function ($model) use ($class) {
            return ! $model instanceof $class;
        })->isNotEmpty()) {
            throw new LogicException('Unable to create query for collection with mixed types.');
        }

        return $model->newModelQuery()->whereKey($this->modelKeys());
    }
}
