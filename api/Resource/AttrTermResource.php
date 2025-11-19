<?php

namespace FluentCart\Api\Resource;

use FluentCart\App\Helpers\HelperTrait;
use FluentCart\App\Models\AttributeRelation;
use FluentCart\App\Models\AttributeTerm;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class AttrTermResource extends BaseResourceApi
{
    use HelperTrait;

    private static array $orderByCols = ['id', 'title', 'slug', 'serial', 'created_at'];

    private static array $moveTo = ['up', 'down'];

    public static function getQuery(): Builder
    {
        return AttributeTerm::query();
    }

    /**
     * Retrieve attribute terms  based on the provided parameters.
     *
     * @param array $params Optional.  Params containing the necessary parameters to retrieve.
     *        [
     *            'params' => [
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
        $orderBy = static::getValWithinEnum(Arr::get($params["params"], 'order_by', 'serial'), static::$orderByCols, 'serial');
        $orderDir = static::getValWithinEnum(Arr::get($params["params"], 'order_type', 'ASC'), static::$orderByEnum, 'ASC');

        return static::getQuery()->where('group_id', Arr::get($params, 'group_id'))
            ->when(Arr::get($params["params"], 'search'), function ($query) use ($params) {
                return $query->search(Arr::get($params["params"], 'search', ''));
            })
            ->applyCustomFilters(Arr::get($params["params"], 'filters', []))
            ->orderBy(
                sanitize_sql_orderby($orderBy), 
                sanitize_sql_orderby($orderDir)
            )
            ->paginate(Arr::get($params["params"], 'per_page', 15), ['*'], 'page', Arr::get($params["params"], 'page'));
    }

    /**
     * Find and retrieve attribute term based on the ID and given params.
     *
     * @param int   $id     Required. The ID of the attribute term to find and retrieve.
     * @param array $params Optional. Additional parameters for finding attribute terms.
     *        [
     *             // Include optional parameters, if any.  
     *        ]
     *
     */
    public static function find($id, $params = [])
    {
        return static::getQuery()->find($id);
    }

    /**
     * Create attribute term with the provided data.
     *
     * @param array $data   Required. Array containing the necessary parameters
     *        $data    =>   (array) Required. Array of attribute term data.
     *            [
     *                    'title'          => (string) Required. The title of the attr term.
     *                    'slug'           => (string) Required. The slug of the attr term.
     *                    'description'    => (string) Optional. The description of the attr term.
     *                    'serial'         => (int)    Optional. The serial of the attr term.
     *             ]
     * @param array $params Required. Additional parameters for attribute term creation.
     *        [
     *            'params' => [
     *                'group_id'=> (int) Required. The id of the attr group.
     *             ]
     *         ]
     *
     */
    public static function create($data, $params = [])
    {
        $groupId = Arr::get($params, 'group_id');

        $group = static::getQuery()->find($groupId);

        if (!$group) {
            return static::makeErrorResponse([
                [ 'code' => 404, 'message' => __('Information mismatch.', 'fluent-cart') ]
            ]);
        }

        $data['serial'] = empty($data['serial']) ? 10 : $data['serial'];
        $data['group_id'] = $group->id;

        $term = AttributeTerm::create($data);

        if ($term) {
            return static::makeSuccessResponse(
                $term,
                __('Successfully created!', 'fluent-cart')
            );
        }
        
        return static::makeErrorResponse([
            [ 'code' => 400, 'message' => __('Term creation failed.', 'fluent-cart') ]
        ]);
    }

    /**
     * Update attribute term with the provided data.
     *
     * @param array $data   Required. Array containing the necessary parameters
     *        $data    =>   (array) Required. Array of attribute term data.
     *            [
     *                    'title'          => (string) Required. The title of the attr term.
     *                    'slug'           => (string) Required. The slug of the attr term.
     *                    'description'    => (string) Optional. The description of the attr term.
     *                    'serial'         => (int)    Optional. The serial of the attr term.
     *             ]
     * @param int $termId     Required. The id of the attribute term to update.
     * @param array $params   Required. Additional parameters for attribute term creation.
     *        [
     *            'params' => [
     *                'group_id'=> (int) Required. The id of the attr group.
     *             ]
     *         ]
     *
     */
    public static function update($data, $termId, $params = [])
    {
        $groupId = Arr::get($params, 'group_id');

        $term = static::getQuery()->where('id', $termId)->where('group_id', $groupId)->first();

        if (!$term) {
            return static::makeErrorResponse([
                [ 'code' => 404, 'message' => __('Information mismatch.', 'fluent-cart') ]
            ]);
        }

        $isUpdated = $term->update($data);

        if ($isUpdated) {
            return static::makeSuccessResponse(
                $isUpdated,
                __('Successfully updated!', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            [ 'code' => 400, 'message' => __('Term info update failed.', 'fluent-cart') ]
        ]);  
    }

    /**
     * Delete attribute term by term ID and given params.
     *
     * @param int   $termId     Required. The ID of the attribute term to delete.
     * @param array $params     Required. Additional parameters for attribute term deletion.
     *        [
     *            'params' => [
     *                'group_id'=> (int) Required. The id of the attr group.
     *             ]
     *         ]
     * 
     */
    public static function delete($termId, $params = [])
    {
        $groupId = Arr::get($params, 'group_id');

        $isUsed = AttributeRelation::query()->where('group_id', $groupId)->where('term_id', $termId)->first();

        if (!$isUsed) {

            $term = static::getQuery()->find($termId);

            if ($term->group_id == $groupId) {
                
                $term->delete();
                
                return static::makeSuccessResponse(
                    '',
                    __('Attribute term successfully deleted!', 'fluent-cart')
                );
            }
            
            return static::makeErrorResponse([
                [ 'code' => 404, 'message' => __('Term not found in database, failed to remove.', 'fluent-cart') ]
            ]);
        }
        
        return static::makeErrorResponse([
            [ 'code' => 403, 'message' => __('This term is already in use, can not be deleted.', 'fluent-cart') ]
        ]);
    }

    /**
     * Update attribute term serial index with the provided params.
     *
     * @param array $params   Required. Array containing the necessary parameters
     *        $params    =>   (array) Required. Array of attribute term data.
     *            [
     *                    'term_id'   => (int) Required. The term id of the attr term.
     *                    'group_id'  => (int) Required. The group id of the attr term.
     *                    'move'      => (int) Required. The move of the attr term.
     *             ]
     *
     */
    public static function updateSerial($params = [])
    {
        $termId = Arr::get($params, 'term_id');
        $groupId = Arr::get($params, 'group_id');
        $move = Arr::get($params, 'move');

        $term = static::getQuery()->find($termId);

        if ($term->group_id == $groupId) {

            $move = static::getValWithinEnum($move, static::$moveTo, 'down');

            if ($move == 'up') {
                $term->serial = $term->serial > 0 ? ($term->serial - 1) : 0;
            } else {
                $term->serial++;
            }

            $term->save();
            
            return static::makeSuccessResponse(
                $term->serial,
                __('Serial updated.', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            [ 'code' => 404, 'message' => __('Info mismatch.', 'fluent-cart') ]
        ]);
    }
}