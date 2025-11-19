<?php

namespace FluentCart\App\Services\Filter;

use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Model;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\App\Services\Filter\Concerns\HandleDateFilter;
use FluentCart\App\Services\Filter\Concerns\HandleRelationalFilter;
use FluentCart\Framework\Database\Orm\Builder;
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
     *
     * @return string|null
     */
    protected function parseAcceptedView(): ?string
    {
        $activeView = Arr::get($this->args, $this->getParsableKey('active_view'), $this->activeView);
        return Arr::has($this->tabsMap(), $activeView) ? $activeView : null;
    }

    /**
     * Parses advanced search filters.
     *
     * @return array
     */
    protected function parseSearchGroups(): array
    {
        $filters = [];


        try {
            $filters = json_decode(
                Arr::get($this->args, $this->getParsableKey('advanced_filters'), '[]'),
                true
            );
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

        if ($this->filterType == 'simple') {
            $this->applyActiveViewFilter();
            $this->applySimpleFilter();
        } else if ($this->filterType == 'advanced') {
            $this->applyAdvancedFilter();
        }

        return $this->query;
        //$this->filterType == 'simple' ? $this->applySimpleFilter() : $this->applyAdvancedFilter();
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
        $this->query = $this->query->orderBy($this->sortBy, $this->sortType);
    }

    protected function applyWith()
    {
        $withs = Arr::wrap($this->with);

        foreach ($withs as $with) {
            if (Str::of($with)->lower()->endsWith('count')) {
                //Get the relation name from count
                //e.g.: variantsCount converts to variants
                $relationName = Str::of($with)->substr(0, -5)->toString();
                $this->query = $this->query->withCount($relationName);
            } else {
                $this->query = $this->query->with($with);
            }
        }

    }

    protected function applyScopes()
    {
        $scopes = Arr::wrap($this->scopes);
        foreach ($scopes as $scope) {
            if (is_array($scope)) {
                $this->query = $this->query->{$scope[0]}($scope[1]);
                continue;
            }
            $this->query = $this->query->{$scope}();
        }
    }

    /**
     * Applies advanced filters to the query.
     *
     * @return void
     */
    protected function applyAdvancedFilter()
    {

        if (!App::isProActive()) {
            return;
        }

        $filtersGroups = $this->searchGroups;
        if (empty($filtersGroups)) {
            return;
        }


        $filterName = static::getFilterName();
        foreach ($filtersGroups as $groupIndex => $group) {


            $method = $groupIndex == 0 ? 'where' : 'orWhere';

            $this->query->{$method}(function ($query) use ($group, $filterName) {
                foreach ($group as $providerName => $items) {
                    foreach ($items as $item) {
                        if ($item['filter_type'] === 'custom') {
                            $filters = static::advanceFilterOptions();
                            $filter = Arr::get($filters, $providerName . '.children', null);
                            $property = Arr::get($item, 'property');
                            $isCallbackFound = false;
                            if (is_array($filter)) {
                                foreach ($filter as $filterItem) {
                                    if ($filterItem['value'] === $item['property']) {
                                        $callback = Arr::get($filterItem, 'callback', null);
                                        if ($callback) {
                                            $callback($this->query, $item);
                                            $isCallbackFound = true;
                                        }
                                        break;
                                    }
                                }
                            }

                            if ($isCallbackFound) {
                                return;
                            }
                            do_action_ref_array("fluent_cart/{$filterName}_filter/{$providerName}/{$item['property']}", [&$this->query, $item]);
                        } else {
                            $this->handleAdvanceFilter($query, $item);
                        }

                    }
                }
            });
        }
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
        $operator = $filterItem['operator'];
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
        $operator = $filterItem['operator'];
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

    public abstract function applySimpleFilter();

    public abstract function applyActiveViewFilter();

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

    public function applySimpleOperatorFilter(): bool
    {
        $operators = $this->getSimpleOperators();

        // check if search has an operator with regexp
        $operatorPattern = '/\s*(' . implode('|', $operators) . ')\s*/';

        $search = trim($this->search);
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
                        $callback($this->query, $value);
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

                    $this->query->where($column, $operator, $value);
                    return true;
                }
            }
        }

        return false;
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
        return $this->query->paginate($perPage);
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

    public static function getTableFilterOptions(): array
    {
        return [
            'advance' => static::getAdvanceFilterOptions(),
            'guide'   => static::getSearchableFields(),
            'columns' => static::getCustomColumns(),
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
