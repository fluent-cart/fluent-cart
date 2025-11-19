<?php

namespace FluentCart\App\Models\Concerns;

use FluentCart\Framework\Database\ORM\Builder;
use FluentCart\Framework\Support\Arr;

/**
 * @method static Builder search(array $query = [])
 * @see  dev-docs/search-trait.md
 */
trait CanSearch
{
    /**
     * WHERE $column LIKE %$value% query.
     *
     * @param Builder $query
     * @param $column
     * @param $value
     * @param string $boolean
     *
     * @return Builder
     */
    public function scopeWhereLike(Builder $query, $column, $value, string $boolean = 'and'): Builder
    {
        return $query->where($column, 'LIKE', "%$value%", $boolean);
    }

    /**
     * WHERE $column LIKE $value% query.
     *
     * @param Builder $query
     * @param $column
     * @param $value
     * @param string $boolean
     *
     * @return Builder
     */
    public function scopeWhereBeginsWith(Builder $query, $column, $value, string $boolean = 'and'): Builder
    {
        return $query->where($column, 'LIKE', "$value%", $boolean);
    }

    /**
     * WHERE $column LIKE %$value query.
     *
     * @param Builder $query
     * @param $column
     * @param $value
     * @param string $boolean
     *
     * @return Builder
     */
    public function scopeWhereEndsWith(Builder $query, $column, $value, string $boolean = 'and'): Builder
    {
        return $query->where($column, 'LIKE', "%$value", $boolean);
    }

    /**
     * @param array $params
     * @param Builder $query
     *
     * @return Builder
     */
    public function scopeSearch(Builder $query, array $params): Builder
    {
        $searchable = $this->getSearchable();

        $new_params = [];

        foreach ($params as $key => $_param) {
            $param = [];
            if (is_array($_param)) {
                $param['column'] = Arr::get($_param, 'column', $key);
                $param['operator'] = Arr::get($_param, 'operator', '=');
                $param['value'] = Arr::get($_param, 'value', null);

            } else {
                if ($_param == null) {
                    continue;
                }
                $param['column'] = $key;
                $param['operator'] = '=';
                $param['value'] = $_param;
            }

            if ($param['value'] == null) {
                continue;
            }

            $new_params[$param['column']] = $param;
        }

        $params = [];
        if ($searchable) {
            foreach ($searchable as $search) {
                if (isset($new_params[$search])) {
                    $params[] = $new_params[$search];
                }
            }
        } else {
            $params = $new_params;
            unset($new_params);
        }

        if (is_array($params)) {
            foreach ($params as $key => $param) {

                switch (strtolower($param['operator'])) {

                    case 'between':
                        $query->whereBetween($param['column'], $param['value']);
                        break;
                    case 'or_between':
                        $query->orWhereBetween($param['column'], $param['value']);
                        break;

                    case 'not_between':
                        $query->whereNotBetween($param['column'], $param['value']);
                        break;
                    case 'or_not_between':
                        $query->orWhereNotBetween($param['column'], $param['value']);
                        break;

                    case 'is_null':
                        $query->whereNull($param['column']);
                        break;
                    case 'or_is_null':
                        $query->orWhereNull($param['column']);
                        break;

                    case 'is_not_null':
                        $query->whereNotNull($param['column']);
                        break;
                    case 'or_is_not_null':
                        $query->orWhereNotNull($param['column']);
                        break;

                    case 'like_all':
                        $query->where($param['column'], 'like', '%' . $param['value'] . '%');
                        break;

                    case 'or_like_all':
                        $query->orWhere($param['column'], 'like', '%' . $param['value'] . '%');
                        break;

                    case 'llike':
                        $query->where($param['column'], 'like', '%' . $param['value']);
                        break;
                    case 'or_llike':
                        $query->orWhere($param['column'], 'like', '%' . $param['value']);
                        break;

                    case 'rlike':
                        $query->where($param['column'], 'like', $param['value'] . '%');
                        break;
                    case 'or_rlike':
                        $query->orWhere($param['column'], 'like', $param['value'] . '%');
                        break;

                    case 'not_like':
                        $query->where($param['column'], 'not like', '%' . $param['value'] . '%');
                        break;

                    case 'in':
                        $query->whereIn($param['column'], $param['value']);
                        break;

                    case 'not_in':
                        $query->whereNotIn($param['column'], $param['value']);
                        break;

                    case 'or_in':
                        $query->orWhereIn($param['column'], $param['value']);
                        break;

                    case 'or_where':
                        $query->orWhere($param['column'], $param['value']);
                        break;
                    case 'where':
                        $query->where($param['column'], $param['value']);
                        break;

                    default:
                        $query->where($param['column'], $param['operator'], $param['value']);
                }
            }
        }

        return $query;
    }

    /**
     * @param Builder $query
     * @param array $groups
     *
     * @return Builder
     */
    public function scopeGroupSearch(Builder $query, array $groups): Builder
    {
        $class = get_class($this);
        $className = basename(str_replace('\\', '/', $class));

        $new_groups = [];
        foreach ($groups as $key => $group) {
            $arr = explode('.', $key);
            $new_groups[$arr[0]] = $group;
        }

        unset($groups);

        foreach ($new_groups as $key => $params) {
            if ($key == $this->getTable() || strtolower($key) == strtolower($className)) {
                $query->search($params);
            } else {
                if (!$this->checkValueIsNull($params)) {
                    $query->whereHas($key, function ($query) use ($params) {
                        $query->search($params);
                    });
                }
            }
        }

        return $query;
    }

    /**
     * @param array $params
     * @return bool
     */
    private function checkValueIsNull(array $params): bool
    {
        foreach ($params as $key => $param) {
            if ($param['value'] != '') {
                return false;
            }
        }

        return true;
    }

    public function getSearchable(): array
    {
        return [];
    }


}