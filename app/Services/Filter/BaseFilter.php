<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Model;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Filter\Concerns\HandleDateFilter;
use FluentCart\App\Services\Filter\Concerns\HandleRelationalFilter;
use FluentCart\App\Services\Permission\PermissionManager;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Database\Query\Builder as QueryBuilder;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Pagination\LengthAwarePaginator;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;
use InvalidArgumentException;

/**
 * Class BaseFilter
 *
 * Base class for filtering and querying models with simple and advanced filters.
 *
 * @package FluentCart\App\Services\Filter
 */
abstract class BaseFilter
{
    use HandleRelationalFilter, HandleDateFilter;

    /**
     * Determines if the filter type is simple or advanced.
     *
     * @var string
     */
    public string $filterType = 'simple';

    /**
     * default primary key of the table
     *
     * @var ?string
     */
    public ?string $primaryKey = null;

    /**
     * Default column used for sorting.
     *
     * @var string
     */
    public string $defaultSortBy = 'id';

    /**
     * Column used for sorting, dynamically set from arguments.
     *
     * @var string
     */
    public string $sortBy = '';

    /**
     * Default sorting order.
     *
     * @var string
     */
    public string $defaultSortType = 'desc';

    /**
     * Sorting order (asc/desc).
     *
     * @var string
     */
    public string $sortType = '';

    /**
     * Ids that must be loaded.
     *
     * @var array
     */
    public array $includeIds = [];

    /**
     * Relations to be loaded with the query.
     *
     * @var array
     */
    public array $with = [];

    /**
     * Select fields for the query.
     *
     * @var ?array
     */
    public array $select = [];


    /**
     * Model scopes.
     *
     * @var ?array
     */
    public ?array $scopes = [];

    /**
     * Search query string for simple filtering.
     *
     * @var string
     */
    public string $search = '';

    /**
     * Limit of records.
     *
     * @var ?int
     */
    public ?int $limit = null;


    /**
     * Number of records to retrieve per page.
     *
     * @var int
     */
    public int $perPage = 10;


    /**
     * Current page number
     *
     * @var ?int
     */
    public ?int $page = null;

    /**
     * The offset for paginated results.
     *
     * @var ?int
     */
    public ?int $offset = null;

    /**
     * The active view/tab to be filtered.
     *
     * @var string|null
     */
    public ?string $activeView = '';

    /**
     * HTTP request instance.
     *
     * @var Request
     */
    protected Request $request;

    /**
     * Query builder instance used for filtering and retrieving data.
     *
     * @var Builder|LengthAwarePaginator
     */
    public $query;

    /**
     * Additional filtering arguments.
     *
     * @var array
     */
    public array $args = [];

    /**
     * Parsed search groups for advanced filtering.
     *
     * @var array
     */
    public array $searchGroups = [];


    /**
     * User timezone
     *
     * @var ?string
     */
    public ?string $userTz = null;

    /**
     * The resolved SavedView model when active_view is a saved view slug.
     *
     * @var SavedView|null
     */
    protected $activeSavedView = null;


    /**
     * BaseFilter constructor.
     *
     * @param array $args Filtering arguments.
     */
    public function __construct(array $args = [])
    {
        $this->validateModel();
        $this->parseArgs($args);
        $this->query = $this->customQuery();
    }

    /**
     * Validates the model instance.
     *
     * @return void
     * @throws InvalidArgumentException
     */
    protected function validateModel()
    {
        $modelClass = $this->getModel();
        $model = new $modelClass;
        if (!$model instanceof Model) {
            throw new InvalidArgumentException('Model class must be an instance of Model');
        }

        $this->primaryKey = $model->getKeyName();
        $this->query = $model->newQuery();
    }

    /**
     * Parses filtering arguments.
     *
     * @param array $args Filtering arguments.
     * @return void
     */
    protected function parseArgs(array $args)
    {

        $this->args = $args;
        $this->select = $this->parseSelect();
        $this->filterType = Arr::get($args, $this->getParsableKey('filter_type'), $this->filterType);
        $this->search = Arr::get($args, $this->getParsableKey('search'), $this->search);
        $this->with = Arr::get($args, $this->getParsableKey('with'), $this->with);
        $this->scopes = Arr::get($args, $this->getParsableKey('scopes'), $this->scopes);
        $this->limit = Arr::get($args, $this->getParsableKey('limit'), $this->limit);
        $this->offset = Arr::get($args, $this->getParsableKey('offset'), $this->offset);
        $this->userTz = Arr::get($args, $this->getParsableKey('user_tz'), $this->userTz);
        $this->includeIds = $this->parseIncludeIds();
        $this->activeView = $this->parseAcceptedView();
        $this->sortBy = $this->parseSortBy();
        $this->sortType = $this->parseSortType();
        $this->searchGroups = $this->parseSearchGroups();
        $this->perPage = $this->parsePerPage();
        $this->page = $this->parsePageNumber();
    }

    protected function parseSelect(): array
    {
        $select = Arr::get($this->args, $this->getParsableKey('select'));

        if (!$select) {
            return [];
        }

        if (is_string($select)) {
            $select = explode(',', $select);
        }

        $parsedSelect = [];
        foreach ($select as $selectItem) {
            if (is_string($selectItem)) {
                $parsedSelect[] = sanitize_text_field($selectItem);
            }
        }

        return $parsedSelect;
    }


    protected function parseIncludeIds(): array
    {
        $includedIds = Arr::get($this->args, $this->getParsableKey('include_ids'), []);
        if (is_string($includedIds)) {
            $includedIds = explode(',', $includedIds);
        }
        return empty($includedIds) ? [] : Arr::wrap($includedIds);
    }

    protected function parsePerPage()
    {
        $perPage = Arr::get($this->args, $this->getParsableKey('per_page'));
        if (is_numeric($perPage) && $perPage > 0 && $perPage < 200) {
            return $perPage;
        }
        return $this->perPage;
    }

    protected function parsePageNumber(): ?int
    {
        $page = Arr::get($this->args, $this->getParsableKey('page'), $this->page);
        return is_numeric($page) ? (int)$page : null;
    }

    /**
     * Parses and validates the sorting column.
     *
     * @return string
     */
    protected function parseSortBy(): string
    {
        $sortBy = Arr::get($this->args, $this->getParsableKey('sort_by'));

        if (empty($sortBy)) {
            return $this->defaultSortBy;
        }

        // Declared sort options are an allow-list written in PHP (the filter
        // class + the {filterName}_table_sorts hook), so they are trusted the
        // same way $fillable is — that is what lets a filter or an add-on offer
        // a sort that is not a fillable column.
        if (array_key_exists($sortBy, static::getSortableColumns())) {
            return $sortBy;
        }

        /**
         * @var Model $modelObject
         */
        $modelClass = $this->getModel();
        $modelObject = new $modelClass;

        return in_array($sortBy, $modelObject->getFillable()) ? $sortBy : $this->defaultSortBy;
    }

    /**
     * Parses and validates the sorting order.
     *
     * @return string
     */
    protected function parseSortType(): string
    {
        $sortType = strtolower((string)Arr::get($this->args, $this->getParsableKey('sort_type'), ''));

        return in_array($sortType, ['desc', 'asc']) ? $sortType : $this->defaultSortType;
    }

    /**
     * Parses and validates the accepted view.
     * First checks static tabs; if not found, fires a filter so Pro can resolve
     * the slug to a saved view (returns an object/array with query_params).
     *
     * @return string|null
     */
    protected function parseAcceptedView(): ?string
    {
        $activeView = Arr::get($this->args, $this->getParsableKey('active_view'), $this->activeView);

        if (empty($activeView)) {
            return null;
        }

        // Static tab takes priority
        if (Arr::has($this->tabsMap(), $activeView)) {
            return $activeView;
        }

        // Ask Pro to resolve the slug for the current filter only.
        $resolvedView = apply_filters('fluent_cart/filter_resolve_saved_view', null, [
            'slug'        => $activeView,
            'filter_name' => static::getFilterName(),
            'user_id'     => get_current_user_id(),
        ]);

        if (is_array($resolvedView) || is_object($resolvedView)) {
            $this->activeSavedView = $resolvedView;
            return null;
        }

        // Backward compatibility for older Pro versions that only inject the admin table config blob.
        $tableConfig = apply_filters('fluent_cart/admin_table_saved_views', [], [
            'filterOptions' => []
        ]);
        foreach ($tableConfig as $entry) {
            $savedViews = isset($entry['saved_views']) ? $entry['saved_views'] : [];
            foreach ($savedViews as $view) {
                if (Arr::get($view, 'slug') === $activeView) {
                    $this->activeSavedView = $view;
                    break 2;
                }
            }
        }

        return null;
    }

    /**
     * Parses advanced search filters.
     *
     * @return array
     */
    protected function parseSearchGroups(?string $json = null): array
    {
        if ($json === null) {
            $json = Arr::get($this->args, $this->getParsableKey('advanced_filters'), '[]');
        }

        $filters = [];

        try {
            $filters = json_decode($json, true);
        } catch (\Exception $exception) {
            // Ignore exception, return empty filters
        }

        if (empty($filters)) {
            return [];
        }

        $groups = [];

        foreach ($filters as $filterGroup) {
            $group = [];
            foreach ($filterGroup as $filterItem) {
                if (count($filterItem['source']) != 2 || empty($filterItem['source'][0]) || empty($filterItem['source'][1]) || empty($filterItem['operator'])) {
                    continue;
                }
                $provider = $filterItem['source'][0];

                if (!isset($group[$provider])) {
                    $group[$provider] = [];
                }

                $property = $filterItem['source'][1];

                $filterData = [
                    'property'    => $property,
                    'operator'    => Arr::get($filterItem, 'operator'),
                    'value'       => Arr::get($filterItem, 'value'),
                    'filter_type' => Arr::get($filterItem, 'filter_type'),
                ];

                if (Arr::get($filterData, 'filter_type') === 'relation') {
                    $filterData['relation'] = Arr::get($filterItem, 'relation', $property);
                    $filterData['column'] = Arr::get($filterItem, 'column', 'id');
                }

                $group[$provider][] = $filterData;


            }

            if ($group) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /**
     * Sets the HTTP request instance.
     *
     * @param Request $request
     * @return $this
     */
    public function setRequest(Request $request): BaseFilter
    {
        $this->request = $request;
        return $this;
    }

    /**
     * Builds the query based on filters.
     *
     * @return Builder
     */
    public function buildQuery(): Builder
    {
        $this->buildCommonQuery();
        $this->applyCurrentFilterLayer();

        if ($this->activeSavedView) {
            $this->applySavedViewFilter();
        }

        return $this->query;
    }

    protected function applyCurrentFilterLayer(): void
    {
        if ($this->filterType == 'simple') {
            $this->applyActiveViewFilter();
            $this->applySimpleFilter();
        } else if ($this->filterType == 'advanced') {
            $this->applyAdvancedFilter();
        }
    }

    protected function applySavedViewFilter(?array $params = null): void
    {
        $params = $params ?? $this->getSavedViewParams();

        if (empty($params)) {
            return;
        }

        $this->query->where(function ($savedQuery) use ($params) {
            $originalQuery = $this->query;
            $this->query = $savedQuery;

            $savedFilterType = Arr::get($params, 'filter_type', 'simple');

            if ($savedFilterType === 'advanced') {
                $savedGroups = $this->getSavedViewSearchGroups($params);
                if (!empty($savedGroups)) {
                    $this->applyAdvancedFilter($savedGroups);
                }
            } else {
                $savedActiveView = Arr::get($params, 'active_view');
                if ($savedActiveView && Arr::has($this->tabsMap(), $savedActiveView)) {
                    $this->applyActiveViewFilter($savedActiveView);
                }

                $savedSearch = Arr::get($params, 'search', '');
                if (!empty($savedSearch)) {
                    $this->applySimpleFilter($savedSearch);
                }
            }

            $this->query = $originalQuery;
        });
    }

    protected function getSavedViewParams(): array
    {
        $savedView = $this->activeSavedView;

        return is_array($savedView)
            ? Arr::get($savedView, 'query_params', [])
            : (is_object($savedView) ? $savedView->query_params : []);
    }

    protected function getSavedViewSearchGroups(array $params): array
    {
        $json = Arr::get($params, 'advanced_filters', '[]');
        if (is_array($json)) {
            $json = wp_json_encode($json);
        }

        return $this->parseSearchGroups($json);
    }

    /**
     * Builds the common query that should be applied in every query.
     *
     * @return void
     */

    protected function buildCommonQuery()
    {
        $this->applySelect();
        $this->applyWith();
        $this->applyScopes();

        if (count($this->includeIds) > 0) {
            $this->applyMustLoadIds();
        }
        $this->applySort();
    }


    public function applySelect()
    {
        if (empty($this->select)) {
            return;
        }
        $this->query->select($this->select);
    }

    protected function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    protected function applyMustLoadIds()
    {

        if ($this->search === '') {
            $this->query = $this->query->whereIn(
                $this->getPrimaryKey(),
                $this->includeIds
            )->orWhereNotNull($this->getPrimaryKey());
        } else {
            $this->query = $this->query->orWhereIn(
                $this->getPrimaryKey(),
                $this->includeIds
            );
        }

    }

    protected function applyLimit()
    {
        $this->query = $this->query->limit($this->limit);
    }

    protected function applyOffset()
    {
        $this->query = $this->query->offset($this->offset);
    }

    protected function applySort()
    {
        $column = Arr::get(static::getSortableColumns(), $this->sortBy . '.column');

        // A declared option may hand over its own ordering — that is how a sort
        // that is not a plain column on this table (an aggregate, a joined
        // relation) gets applied.
        if (is_callable($column)) {
            $sorted = call_user_func($column, $this->query, $this->sortType);

            // Only a builder replaces the query. A callback that orders in place
            // and returns nothing — or returns something else entirely — must not
            // be able to swap the query out from under the rest of the filter.
            if ($sorted instanceof Builder || $sorted instanceof QueryBuilder) {
                $this->query = $sorted;
            }

            return;
        }

        if (!is_string($column) || $column === '') {
            $column = $this->sortBy;
        }

        $this->query = $this->query->orderBy($column, $this->sortType);
    }

    /**
     * What the `with` request parameter may load.
     *
     * DENY BY DEFAULT — the base returns an empty map, so a filter that does not
     * override this loads nothing at all. That matters because the ORM resolves
     * a relation by literally calling that method on the model
     * (`Builder::getRelation()`), catching only BadMethodCallException. A method
     * that exists but is not a relation still RUNS: before this allow-list,
     * `with[]=recountTotalPaidAndRefund` inserted a phantom refunded order row.
     *
     * ## The entry form — one form, no others
     *
     * Every entry is a LITERAL request key mapped to a CALLABLE. Nothing else is
     * an entry: a non-callable value is refused. The key is never decomposed,
     * pattern-matched or prefix-parsed, so what the client sends is either a key
     * in this map or it is dropped.
     *
     *     protected function allowedWiths(): array
     *     {
     *         return [
     *             'admin_product_list' => [$this, 'adminProductList'],
     *         ];
     *     }
     *
     *     protected function adminProductList($query)
     *     {
     *         if (!$this->userCanAny('products/view')) {
     *             return false;   // contributes nothing
     *         }
     *
     *         return $query->with(['detail' => function ($q) {
     *             $q->select(['id', 'post_id', 'featured_media']);
     *         }]);
     *     }
     *
     * ## Callback contract
     *
     * The callback receives the query as built so far — every entry already
     * adopted is on it — checks its own permission, applies its own eager load
     * (`with()`, `withCount()`, whatever it needs), and returns the query. It
     * runs EXACTLY ONCE. The base never learns a relation name; the callback owns
     * the whole path, the selects and the gate.
     *
     *   return false (or anything that is not a Builder) → contributes nothing
     *   return the Builder                               → the base adopts it
     *
     * A count is not a special case: it is a callback that calls `withCount()`.
     *
     * ## Nested paths
     *
     * Nesting is not a special case either: name the full path (`'a.b'`) inside
     * the callback. Two consequences, both of them sharp:
     *
     * 1. `with('a.b')` auto-injects the parent `a` with an EMPTY closure, so a
     *    nested callback must repeat whatever GATE the parent carries — the
     *    nested path is otherwise a way around it.
     * 2. That injected empty closure also overwrites a CONSTRAINT an earlier
     *    entry put on `a`, because each callback issues its own `with()` call and
     *    Builder::with() array_merges into $eagerLoad (addNestedWiths() only
     *    protects entries inside a single call). So a nested callback must also
     *    re-state the parent's constraint in its own `with()` call:
     *
     *        return $query->with([
     *            'a'   => function ($q) { $q->select([...]); },
     *            'a.b' => function ($q) { $q->select([...]); },
     *        ]);
     *
     * ## Why the builder you are handed is safe to mutate
     *
     * `$query->with([...])` mutates in place and returns the same instance, so a
     * callback that mutated and then refused would already have changed the
     * query. The base therefore invokes every callback against `clone
     * $this->query` and adopts the result only when a Builder comes back:
     * Orm\Builder::__clone() also clones the underlying Query\Builder, and both
     * hold their wheres, bindings, columns and eager loads in plain arrays, which
     * PHP copies by value. A refusing callback is structurally incapable of
     * touching the live query.
     *
     * ## Escape valve
     *
     * The gate lives in applyWith(), so it covers every instance including a
     * `with` assigned after construction, and it reads the CURRENT user — a cron
     * or WP-CLI caller loses permission-gated entries. The
     * `fluent_cart/{filter}_allowed_withs` filter is how an add-on or a
     * privileged background job adds its own entry.
     *
     * @return array<string, callable>
     */
    protected function allowedWiths(): array
    {
        return [];
    }

    /**
     * What the `scopes` request parameter may apply.
     *
     * DENY BY DEFAULT. The old applyScopes() invoked whatever method name the
     * request supplied directly on the query builder, before any WHERE clause
     * existed — `scopes[]=delete` emptied the table, `scopes[]=truncate` wiped it
     * and the array form mass-updated every row.
     *
     * Same single entry form as allowedWiths(): literal key => callable. This is
     * a separate map only because the request delivers it on a different
     * parameter; the resolution and the safety properties are identical.
     *
     * Scope callbacks are invoked as `($query, $args)`, where `$args` is the raw
     * remainder of an array-form request entry (`scopes[]=['x','y']` → `['y']`).
     * `$args` is UNVALIDATED client input. Declare the parameter only if you
     * intend to validate it; a callback declared `($query)` simply never sees it,
     * which is the default refusal of request-supplied arguments.
     *
     * A scope name is not reachable unless a callback routes to it, and routing
     * through `Builder::scopes()` adds a second structural gate: it resolves via
     * Model::callNamedScope(), which prefixes with "scope", so only a real
     * `scopeX()` method exists at the end of that path.
     *
     * @return array<string, callable>
     */
    protected function allowedScopes(): array
    {
        return [];
    }

    protected function applyWith()
    {
        $filterName = static::getFilterName();

        $withMap = apply_filters(
            "fluent_cart/{$filterName}_allowed_withs", $this->allowedWiths(), ['filter' => $this]
        );

        foreach (Arr::wrap($this->with) as $requestKey) {
            // Dropping non-strings also kills the array-nested with[parent][]=child
            // form, which the ORM would otherwise read as the path parent.child.
            if (!is_string($requestKey) || !array_key_exists($requestKey, $withMap)) {
                continue;
            }

            $this->adoptAllowEntry($withMap[$requestKey]);
        }
    }

    protected function applyScopes()
    {
        $filterName = static::getFilterName();

        $scopeMap = apply_filters(
            "fluent_cart/{$filterName}_allowed_scopes", $this->allowedScopes(), ['filter' => $this]
        );

        foreach (Arr::wrap($this->scopes) as $requested) {
            $args = [];

            if (is_array($requested)) {
                $requestKey = isset($requested[0]) ? $requested[0] : null;
                $args = array_slice($requested, 1);
            } else {
                $requestKey = $requested;
            }

            if (!is_string($requestKey) || !array_key_exists($requestKey, $scopeMap)) {
                continue;
            }

            $this->adoptAllowEntry($scopeMap[$requestKey], $args);
        }
    }

    /**
     * Invoke one allow-map entry and adopt what it hands back.
     *
     * The callback gets a CLONE of the live query, so a callback that mutates
     * and then refuses cannot leave its mutation behind. Only a Builder is
     * adopted; every other return value — false, null, a stray string — means the
     * entry contributes nothing.
     *
     * @param mixed $entry Anything non-callable is refused.
     * @param array $args  Unvalidated client arguments; always empty for a `with`.
     * @return void
     */
    protected function adoptAllowEntry($entry, array $args = []): void
    {
        if (!is_callable($entry)) {
            return;
        }

        $result = $entry(clone $this->query, $args);

        if ($result instanceof Builder) {
            $this->query = $result;
        }
    }

    /**
     * @param string|array $permission
     * @return bool
     */
    protected function userCan($permission): bool
    {
        return PermissionManager::hasPermission((array)$permission);
    }

    /**
     * @param string|array $permission
     * @return bool
     */
    protected function userCanAny($permission): bool
    {
        return PermissionManager::hasAnyPermission((array)$permission);
    }
    /**
     * Applies advanced filters to the query.
     *
     * @return void
     */
    protected function applyAdvancedFilter(?array $searchGroups = null): void
    {

        if (!App::isProActive()) {
            return;
        }

        $filtersGroups = $searchGroups ?? $this->searchGroups;
        if (empty($filtersGroups)) {
            return;
        }


        $filterName = static::getFilterName();
        $allFilterOptions = apply_filters("fluent_cart/{$filterName}_filter_options", static::advanceFilterOptions());
        foreach ($filtersGroups as $groupIndex => $group) {


            $method = $groupIndex == 0 ? 'where' : 'orWhere';

            $this->query->{$method}(function ($query) use ($group, $filterName, $allFilterOptions) {
                foreach ($group as $providerName => $items) {
                    $items = $this->mergeRelationFilters($items);
                    foreach ($items as $item) {
                        if ($item['filter_type'] === 'custom') {
                            $filters = $allFilterOptions;
                            $filter = Arr::get($filters, $providerName . '.children', null);
                            $property = Arr::get($item, 'property');
                            $isCallbackFound = false;
                            if (is_array($filter)) {
                                foreach ($filter as $filterItem) {
                                    if ($filterItem['value'] === $item['property']) {
                                        $callback = Arr::get($filterItem, 'callback', null);
                                        if ($callback) {
                                            $callback($query, $item);
                                            $isCallbackFound = true;
                                        }
                                        break;
                                    }
                                }
                            }

                            if ($isCallbackFound) {
                                continue;
                            }
                            do_action_ref_array("fluent_cart/{$filterName}_filter/{$providerName}/{$item['property']}", [&$query, $item]);
                        } else {
                            $this->handleAdvanceFilter($query, $item);
                        }

                    }
                }
            });
        }
    }

    /**
     * Merge relation filter items that target the same relation field with compatible operators.
     * This prevents multiple whereHas() subqueries ANDed together for the same relation column,
     * which would return no results (e.g., license status IN ('active') AND license status IN ('expired')).
     */
    private function mergeRelationFilters(array $items): array
    {
        $merged = [];
        $relationGroups = [];

        foreach ($items as $item) {
            if (Arr::get($item, 'filter_type') !== 'relation') {
                $merged[] = $item;
                continue;
            }

            $value = Arr::get($item, 'value');
            $operator = $item['operator'] ?? '';
            $mergeableOperators = ['in', 'contains', 'not_in', 'not_contains'];

            if (!in_array($operator, $mergeableOperators)) {
                $merged[] = $item;
                continue;
            }

            // Normalize string value to array for merging.
            //
            // Only for the membership operators. `contains`/`not_contains` on a
            // text field are substring searches ("Includes gmail"), and wrapping
            // one into an array sends it down the whereIn path below — an exact
            // match, so the search silently returns nothing. Left as a string it
            // is passed through unmerged and handleRelation answers it with a
            // LIKE. Array values for those operators (ids from a
            // remote_tree_select field such as Order Items) still merge, which is
            // what this merge was written for.
            if (is_string($value) && $value !== '' && in_array($operator, ['in', 'not_in'], true)) {
                $value = [$value];
                $item['value'] = $value;
            }

            if (!is_array($value) || empty($value)) {
                $merged[] = $item;
                continue;
            }

            $key = $item['property'] . ':' . $item['relation'] . ':' . $item['column'] . ':' . $operator;
            if (!isset($relationGroups[$key])) {
                $relationGroups[$key] = $item;
            } else {
                $relationGroups[$key]['value'] = array_values(
                    array_unique(array_merge($relationGroups[$key]['value'], $value))
                );
            }
        }

        return array_merge($merged, array_values($relationGroups));
    }

    private function handleAdvanceFilter($query, $filterItem)
    {
        if (Arr::get($filterItem, 'filter_type') === 'relation') {
            $this->handleRelation($query, $filterItem);
        } else if (Arr::get($filterItem, 'filter_type') === 'date') {
            $this->handleDate($query, $filterItem);
        } else {
            $this->handleOperator($query, $filterItem);
        }


        //
    }

    private function handleOperator(Builder &$query, array $filterItem)
    {

        $searchTerm = $filterItem['value'];

        if (is_array($searchTerm)) {
            $this->searchFromArray($query, $filterItem);
        } else {
            $this->searchFromString($query, $filterItem);
        }
    }

    private function searchFromArray(Builder &$query, array $filterItem)
    {
        $property = $filterItem['property'];
        $operator = $this->resolveOperator($filterItem['operator']);
        $searchTerm = $filterItem['value'];
        $methodName = 'modify' . Str::studly($property . '_value');

        if (in_array($property, $this->centColumns())) {
            $searchTerm = array_map(function ($value) {
                return Helper::toCent($value);
            }, $searchTerm);
        }
        if (method_exists($this, $methodName)) {
            $searchTerm = $this->{$methodName}($searchTerm, $filterItem, $query);
            if ($searchTerm === null) {
                return;
            }
        }

        if (in_array($operator, ['in', 'contains'])) {
            $query = $query->whereIn($property, $searchTerm);
        } else if (in_array($operator, ['not_in', 'not_contains'])) {
            $query = $query->whereNotIn($property, $searchTerm);
        } elseif (in_array($operator, ['in_all', 'not_in_all'])) {
            $condition = $operator === 'in_all' ? '=' : '!=';
            foreach ($searchTerm as $term) {
                $query = $query->where($property, $condition, $term);
            }
        } else if (in_array($operator, $this->getSimpleOperators(['::']))) {
            $query = $query->where($property, $operator, $searchTerm);
        }
    }

    private function searchFromString(Builder &$query, array $filterItem)
    {
        $property = $filterItem['property'];
        $operator = $this->resolveOperator($filterItem['operator']);
        $searchTerm = $filterItem['value'];

        $methodName = 'modify' . Str::studly($property . '_value');

        if (in_array($property, $this->centColumns())) {
            $searchTerm = Helper::toCent($searchTerm);
        }
        if (method_exists($this, $methodName)) {
            $searchTerm = $this->{$methodName}($searchTerm, $filterItem, $query);

            if ($searchTerm === null) {
                return;
            }
        }


        if (in_array($operator, ['contains', 'in'])) {
            $query = $query->where($property, 'LIKE', '%' . $searchTerm . '%');
        } else if (in_array($operator, ['not_contains', 'not_in'])) {
            $query = $query->where($property, 'NOT LIKE', '%' . $searchTerm . '%');
        } else if ($operator === 'is_null') {
            $query = $query->where(function (Builder $q) use ($property) {
                return $q->whereNull($property)
                    ->orWhere($property, '=', '');
            });
        } else if ($operator === 'not_null') {
            $query = $query->where(function (Builder $q) use ($property) {
                return $q->whereNotNull($property)
                    ->orWhere($property, '!=', '');
            });
        } else if (in_array($operator, $this->getSimpleOperators(['::']))) {
            $query = $query->where($property, $operator, $searchTerm);
        } else {
            $query = $query->where($property, $operator, $searchTerm);
        }
    }

    /**
     * Apply the simple Filters.
     *
     * @return void
     */

    public abstract function applySimpleFilter(?string $search = null): void;

    public abstract function applyActiveViewFilter(?string $activeView = null): void;

    /**
     * Return the maps of [table-column, tabs-name]
     *
     * @return array
     */
    public abstract function tabsMap(): array;

    /**
     * Return Model name
     *
     * @return string
     */
    public abstract function getModel(): string;


    private function getDbColumns(): array
    {
        $modelClass = $this->getModel();
        $model = new $modelClass;
        // Get fillable columns and add primary key
        return array_merge($model->getFillable(), [$this->primaryKey]);
    }


    /**
     * Return the columns that are searchable
     *
     * @return array
     */
    public static function getSearchableFields(): array
    {
        $self = (new static);
        $columns = $self->getDbColumns();
        // Create case-insensitive lookup array
        $searchableColumns = [];
        foreach ($columns as $column) {
            $searchableColumns[strtolower($column)] = $column;
            $searchableColumns[$column] = $column;
        }

        return $searchableColumns;
    }

    /**
     * Return the operators that are supported for simple filters
     *
     * @return array
     */
    public function getSimpleOperators($except = []): array
    {
        return Arr::except(
            ['=', '!=', '>', '<', '>=', '<=', '::'],
            $except
        );
    }

    /**
     * The operator vocabulary this engine resolves: the operator a request may
     * name => the operator actually applied to the query.
     *
     * Every entry core ships is an identity mapping, so translating through this
     * map changes nothing on its own. The indirection exists so that filter
     * options contributed from outside this class — an add-on's, or one of the
     * named operators the built-in options already advertise — can be taught to
     * the engine without editing it.
     *
     * Why it matters that an unknown operator never reaches the query builder:
     * Query\Builder::invalidOperator() silently rewrites one it does not
     * recognise into `=` and compares the column against the operator STRING, so
     * the filter does not error — it returns wrong rows.
     *
     * @return array<string, string>
     */
    public static function supportedOperators(): array
    {
        $operators = [
            '='            => '=',
            '!='           => '!=',
            '>'            => '>',
            '<'            => '<',
            '>='           => '>=',
            '<='           => '<=',
            '::'           => '::',
            'in'           => 'in',
            'not_in'       => 'not_in',
            'in_all'       => 'in_all',
            'not_in_all'   => 'not_in_all',
            'contains'     => 'contains',
            'not_contains' => 'not_contains',
            'is_null'      => 'is_null',
            'not_null'     => 'not_null',
            'before'       => 'before',
            'after'        => 'after',
            'date_equal'   => 'date_equal',
            'between'      => 'between',
        ];

        // Listeners must declare add_filter(..., 10, 2) to receive the context.
        return apply_filters(
            'fluent_cart/filter/supported_operators',
            $operators,
            ['filter_name' => static::getFilterName()]
        );
    }

    /**
     * Translate a requested operator into the one the engine applies.
     *
     * An operator with no entry is returned untouched — declining to resolve it
     * here keeps this behaviour-neutral for anything already in use.
     *
     * @param mixed $operator
     * @return mixed
     */
    protected function resolveOperator($operator)
    {
        if (!is_string($operator)) {
            return $operator;
        }

        $resolved = Arr::get(static::supportedOperators(), $operator);

        return is_string($resolved) && $resolved !== '' ? $resolved : $operator;
    }

    public function applySimpleOperatorFilter(?string $search = null): bool
    {
        $operators = $this->getSimpleOperators();

        // check if search has an operator with regexp
        $operatorPattern = '/\s*(' . implode('|', $operators) . ')\s*/';

        $search = trim($search ?? $this->search);
        if (preg_match($operatorPattern, $search, $matches)) {
            $operator = $matches[1];
            $searchParts = explode($operator, $search);

            if (count($searchParts) >= 2) {
                $column = trim($searchParts[0]);
                $value = trim($searchParts[1]);

                // Check if the column is valid
                $validColumns = static::getSearchableFields();
                $column = strtolower($column);
                if ($columnSchema = Arr::get($validColumns, $column, null)) {

                    $type = Arr::get($columnSchema, 'type', 'string');

                    if ($type === 'custom') {
                        $callback = $columnSchema['callback'];
                        $callback($this->query, $value, $operator, $this);
                        return true;
                    }
                    if (is_array($columnSchema)) {
                        $column = $columnSchema['column'];
                    } else {
                        $column = $columnSchema;
                    }

                    if ($operator == '::') {
                        $values = explode('-', $value);
                        if (count($values) == 2) {
                            if (in_array($column, $this->centColumns())) {
                                $values[0] = Helper::toCent($values[0]);
                                $values[1] = Helper::toCent($values[1]);
                            } else if (in_array($column, $this->dateColumns())) {
                                $values[0] = DateTime::anyTimeToGmt($values[0], $this->userTz)->format('Y-m-d H:i:s');
                                $values[1] = DateTime::anyTimeToGmt($values[1], $this->userTz)->format('Y-m-d H:i:s');
                            }
                            $this->query->whereBetween($column, $values);
                            return true;
                        }
                    }

                    if (in_array($column, $this->centColumns())) {
                        $value = Helper::toCent($value);
                    } else if (in_array($column, $this->dateColumns())) {
                        $value = DateTime::anyTimeToGmt($value, $this->userTz)->format('Y-m-d H:i:s');
                    }

                    if ($this->shouldApplyMatchFilter($operator)) {
                        $this->applyMatchFilter($this->query, $column, $value, $operator);
                    } else {
                        $this->query->where($column, $operator, $value);
                    }


                    return true;
                }
            }
        }

        return false;
    }

    public function shouldApplyMatchFilter(string $operator): bool
    {
        $operator = trim($operator);
        return $operator === '=' || $operator==='!=';
    }

    public function applyMatchFilter(Builder $query, string $column, $value, string $operator = '='): Builder
    {
        $value = sanitize_text_field($value);

        $hasStartWildcard = Str::startsWith($value, '*');
        $hasEndWildcard   = Str::endsWith($value, '*');

        // No wildcards → strict equality / inequality
        if (!$hasStartWildcard && !$hasEndWildcard) {
            return $query->where($column, $operator === '!=' ? '!=' : '=', $value);
        }

        // Remove * wildcards
        $likeValue = $value;

        if ($hasStartWildcard) {
            $likeValue = ltrim($likeValue, '*');
        }

        if ($hasEndWildcard) {
            $likeValue = rtrim($likeValue, '*');
        }

        // Convert to SQL LIKE pattern
        if ($hasStartWildcard && $hasEndWildcard) {
            $likeValue = '%' . $likeValue . '%';   // *value*
        } elseif ($hasStartWildcard) {
            $likeValue = '%' . $likeValue;         // *value
        } else {
            $likeValue = $likeValue . '%';         // value*
        }

        $sqlOperator = $operator === '!=' ? 'NOT LIKE' : 'LIKE';

        return $query->where($column, $sqlOperator, $likeValue);
    }




    public function centColumns(): array
    {
        return [];
    }

    public function dateColumns(): array
    {
        return ['updated_at', 'created_at'];
    }

    /**
     * Return the name of filter
     *
     * @return string
     */

    public static abstract function getFilterName(): string;


    /**
     * Return the maps of [key, key-name]
     * It's used for parse the data
     *
     * @return array
     */
    public static function parseableKeyMap(): array
    {
        return [
            'filter_type'      => 'filter_type',
            'with'             => 'with',
            'search'           => 'search',
            'limit'            => 'limit',
            'offset'           => 'offset',
            'active_view'      => 'active_view',
            'sort_by'          => 'sort_by',
            'sort_type'        => 'sort_type',
            'advanced_filters' => 'advanced_filters',
            'per_page'         => 'per_page',
            'include_ids'      => 'include_ids',
            'scopes'           => 'scopes',
            'user_tz'          => 'user_tz',
            'select'           => 'select',
            'page'             => 'page'
        ];
    }

    /**
     * Return the names of allowed keys preserved in data
     *
     * @return array
     */

    public static function parseableKeys(): array
    {
        return static::parseableKeyMap();
    }

    /**
     * Return the names of the kye, which should be used to parse the value
     *
     * @param string $key // Name of the Key
     * @return string
     */
    private function getParsableKey(string $key): string
    {
        return Arr::has(static::parseableKeyMap(), $key) ?
            static::parseableKeyMap()[$key] : $key;

    }

    public function query()
    {
        return $this->query;
    }

    public function setQuery(Builder $query): BaseFilter
    {
        $this->query = $query;
        return $this;
    }

    public function customQuery()
    {
        return $this->query;
    }

    public function get()
    {
        //Apply limit and offset only when using get
        //While using pagination, limit and offset are auto calculated

        $this->buildQuery();

        if (!empty($this->limit)) {
            $this->applyLimit();
        }

        if (!empty($this->offset)) {
            $this->applyOffset();
        }
        $filter = $this->getFilterName();
        $this->query = apply_filters("fluent_cart/{$filter}_list_filter_query", $this->query, $this->toArray());
        return $this->query->get();
    }

    public function paginate($perPage = null): LengthAwarePaginator
    {
        $this->buildQuery();
        $perPage = empty($perPage) ? $this->perPage : $perPage;
        $filter = $this->getFilterName();
        $this->query = apply_filters("fluent_cart/{$filter}_list_filter_query", $this->query, $this->toArray());
        return $this->query->paginate(
            $perPage,
            ['*'],
            'page',
            $this->page
        );
    }

    public static function fromRequest(Request $request): BaseFilter
    {
        return new static($request->only(
            static::parseableKeys()
        ));
    }

    public static function make(array $args): BaseFilter
    {
        return new static($args);
    }

    public static function getAdvanceFilterOptions(): ?array
    {
        $filterName = static::getFilterName();
        $options = apply_filters("fluent_cart/{$filterName}_filter_options", static::advanceFilterOptions());
        return is_array($options) ? array_values($options) : null;
    }

    private static function advanceFilterOptions(): ?array
    {
        return null;
    }

    public static function getCustomColumns()
    {
//        $data = [
//            [
//                'title' => 'Product One',
//                'meta' => [
//                    'max_price' => '100',
//                    'min_price' => '80',
//                ]
//            ],
//            [
//                'title' => 'Product Two',
//                'meta' => [
//                    'max_price' => '150',
//                    'min_price' => '90',
//                ]
//            ]
//        ];
//        $example_columns = [
//            'title' => [
//                'label'    => 'Title',
//                'accessor' => 'title',
//                'as_link' => false,
//            ],
//            'max_price' => [
//                'label'    => 'Max Price',
//                'accessor' => 'meta.max_price'
//            ],
//            'min_price' => [
//                'label'    => 'Min Price',
//                'accessor' => 'meta.min_price'
//            ]
//        ];
        $filterName = static::getFilterName();
        return apply_filters("fluent_cart/{$filterName}_table_columns", []);
    }

    /**
     * Sort options this filter offers, keyed by the value the table sends as
     * `sort_by`:
     *
     *     'id'          => ['label' => 'Order ID', 'column' => 'id'],
     *     'best_seller' => ['label' => 'Best Selling', 'column' => function ($query, $direction) {
     *         return $query->orderBy('order_items_count', $direction);
     *     }],
     *
     * `column` is the real DB column to ORDER BY, or a callable that receives
     * ($query, $direction) and applies its own ordering — which is how an
     * add-on sorts by something that is not a plain column on this table.
     *
     * Filter classes override this with the core defaults; add-ons append
     * through the hook in getSortableColumns().
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function sortableColumns(): array
    {
        return [];
    }

    /**
     * The declared sort options plus whatever add-ons registered.
     *
     * This map is the allow-list parseSortBy() validates `sort_by` against and
     * the handler table applySort() resolves against, so a registered option
     * actually reaches ORDER BY instead of being dropped for not being fillable.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getSortableColumns(): array
    {
        $filterName = static::getFilterName();
        $columns = apply_filters("fluent_cart/{$filterName}_table_sorts", static::sortableColumns());

        return is_array($columns) ? $columns : [];
    }

    /**
     * What the admin table receives: a `sort_by value => label` map. The column
     * (or the callable behind it) stays server side — it is not serializable,
     * and resolving it is the backend's job.
     *
     * @return array<string, string>
     */
    public static function getSortOptions(): array
    {
        $options = [];

        foreach (static::getSortableColumns() as $value => $option) {
            $options[$value] = (string)Arr::get($option, 'label', $value);
        }

        return $options;
    }

    public static function getTableFilterOptions(): array
    {
        return [
            'advance' => static::getAdvanceFilterOptions(),
            'guide'   => static::getSearchableFields(),
            'columns' => static::getCustomColumns(),
            'sorts'   => static::getSortOptions(),
        ];
    }

    public function toArray(): array
    {
        return [
            'select'       => $this->select,
            'filterType'   => $this->filterType,
            'search'       => $this->search,
            'with'         => $this->with,
            'scopes'       => $this->scopes,
            'limit'        => $this->limit,
            'offset'       => $this->offset,
            'userTz'       => $this->userTz,
            'includeIds'   => $this->includeIds,
            'activeView'   => $this->activeView,
            'sortBy'       => $this->sortBy,
            'sortType'     => $this->sortType,
            'searchGroups' => $this->searchGroups,
            'perPage'      => $this->perPage,
        ];
    }

}
