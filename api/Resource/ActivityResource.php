<?php

namespace FluentCart\Api\Resource;

use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\App\Models\Activity;
use FluentCart\Framework\Support\Arr;

class ActivityResource extends BaseResourceApi
{
    public static function get(array $params = [])
    {
        $logsQuery =  Activity::query()
            ->orderBy(
                sanitize_sql_orderby(Arr::get($params, 'order_by', 'id')),
                sanitize_sql_orderby(Arr::get($params, 'order_type', 'DESC'))
            );
        $status = Arr::get($params, 'status');

        if (!empty($status) && $status !== 'all') {
            if ($status === 'api') {
                $logsQuery->where('log_type', 'api');
            } else {
                $logsQuery->where('status', $status);
            }
        }

        return $logsQuery->paginate(
            Arr::get($params, 'per_page', 15),
            ['*'],
            'page',
            Arr::get($params, 'page', 1)
        );
    }

    public static function find($id, $params = [])
    {
        return Activity::query()->find($id);
    }

    public static function create($data, $params = [])
    {
        return Activity::query()->create($data);
    }

    public static function update($data, $id, $params = [])
    {
        return Activity::query()->where('id', $id)->update($data);
    }

    public static function delete($id, $params = [])
    {
        return Activity::query()->where('id', $id)->delete();
    }

    static function getQuery(): Builder
    {
        return Activity::query();
    }
}