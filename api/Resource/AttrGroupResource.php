<?php

namespace FluentCart\Api\Resource;

use FluentCart\App\Helpers\HelperTrait;
use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Models\AttributeRelation;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class AttrGroupResource extends BaseResourceApi
{
    use HelperTrait;

    private static array $orderByCols = ['title', 'id', 'slug', 'created_at'];

    public static function getQuery(): Builder
    {
        return AttributeGroup::query();
    }

    /**
     * Retrieve attribute groups  based on the provided parameters.
     *
     * @param array $params Optional.  Params containing the necessary parameters to retrieve.
     *        [
     *            'params' => [
     *                'with'         => (array) Optional. Relationships name to be eager loaded,
     *                "search"       => (array) Optional.
     *                       [ "column name(e.g., title|slug)" => [
     *                             "column"       => "column name(e.g., title|slug)",
     *                             "operator"     => "(string)(e.g., like_all|rlike|or_rlike)",
     *                             "value"        => (string|array) ]
     *                       ],
     *                  "filters"     => (array) Optional.
     *                       [ "column name(e.g., title|slug)" => [
     *                             "column"       => "column name(e.g., title|slug)",
     *                             "operator"     => "(string)(e.g., in)",
     *                             "value"        => (string|array) ]
     *                       ],
     *                'order_by'     => (string) Optional. Column to order by,
     *                'order_type'   => (string) Optional. Order type for sorting (ASC or DESC),
     *                'per_page'     => (int) Optional. Number of items for per page,
     *                'page'         => (int) Optional. Page number for pagination
     *            ]
     *        ]
     *
     */
    public static function get(array $params = [])
    {
        $with = Arr::get($params["params"], 'with', []);

        return static::getQuery()->with($with)->withCount($with)
            ->when(Arr::get($params["params"], 'search'), function ($query) use ($params) {
                return $query->search(Arr::get($params["params"], 'search', ''));
            })
            ->when(!empty($with), function ($query) use ($params) {
                return $query->applyCustomFilters(Arr::get($params["params"], 'filters', []));
            })
            ->orderBy(
                sanitize_sql_orderby(static::getValWithinEnum(Arr::get($params["params"], 'order_by'), static::$orderByCols, 'title')),
                sanitize_sql_orderby(static::getValWithinEnum(Arr::get($params["params"], 'order_type'), static::$orderByEnum, 'ASC'))
            )
            ->paginate(Arr::get($params["params"], 'per_page', 10), ['*'], 'page', Arr::get($params["params"], 'page'));

    }

    /**
     * Find and retrieve attribute group based on the ID and given params.
     *
     * @param int $id Required. The ID of the attribute group to find and retrieve.
     * @param array $params Optional. Additional parameters for finding attribute groups.
     *        [
     *            'params' => [
     *                'with'         => (array) Optional. Relationships name to be eager loaded
     *             ]
     *         ]
     *
     */
    public static function find($id, $params = [])
    {
        $with = Arr::get($params, 'with', []);

        $query = static::getQuery();

        if (empty($with)) {
            return $query->find($id);
        }

        $with = static::getArrValWithinEnum($with, ['terms'], 'terms');
        return $query->with($with)->find($id);
    }

    /**
     * Create attribute group with the provided data.
     *
     * @param array $data Required. Array containing the necessary parameters
     *        $data    =>   (array) Required. Array of attribute group data.
     *            [
     *                    'title'          => (string) Required. The title of the attr group.
     *                    'slug'           => (string) Required. The slug of the attr group.
     *                    'description'    => (string) Optional. The description of the attr group.
     *             ]
     * @param array $params Optional. Additional parameters for attribute group creation.
     *        [
     *             // Include optional parameters, if any.
     *        ]
     *
     */
    public static function create($data, $params = [])
    {
        $groupCreated = static::getQuery()->create($data);

        if ($groupCreated) {
            return static::makeSuccessResponse(
                $groupCreated,
                __('Successfully created!', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Group creation failed.', 'fluent-cart')]
        ]);
    }

    /**
     * Update attribute group with the provided data.
     *
     * @param array $data Required. Array containing the necessary parameters
     *        $data    =>   (array) Required. Array of attribute group data.
     *            [
     *                    'title'          => (string) Required. The title of the attr group.
     *                    'description'    => (string) Optional. The description of the attr group.
     *             ]
     * @param int $groupId Required. The id of the attribute group to update.
     * @param array $params Optional. Additional parameters for attribute group creation.
     *        [
     *             // Include optional parameters, if any.
     *        ]
     *
     */
    public static function update($data, $groupId, $params = [])
    {
        $isUpdated = static::getQuery()->findOrFail($groupId)->update($data);

        if ($isUpdated) {
            return static::makeSuccessResponse(
                $isUpdated,
                __('Group updated successfully!', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Group info update failed.', 'fluent-cart')]
        ]);
    }


    /**
     * Delete attribute group by ID.
     *
     * @param int $groupId Required. The ID of the attribute group to delete.
     * @param array $params Optional. Additional parameters for attribute group deletion.
     *        [
     *              // Include optional parameters, if any.
     *        ]
     *
     */
    public static function delete($groupId, $params = [])
    {
        $isUsed = AttributeRelation::query()->where('group_id', $groupId)->first();

        if (!$isUsed) {
            $group = static::getQuery()->find($groupId);
            if ($group) {
                $group->delete();
                return static::makeSuccessResponse(
                    '',
                    __('Attribute group successfully deleted!', 'fluent-cart')
                );
            }

            return static::makeErrorResponse([
                ['code' => 404, 'message' => __('Attribute group not found in database, failed to remove.', 'fluent-cart')]
            ]);
        }

        return static::makeErrorResponse([
            ['code' => 403, 'message' => __('This group is already in use, can not be deleted.', 'fluent-cart')]
        ]);
    }
}
