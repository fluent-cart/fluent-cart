<?php

namespace FluentCart\Api\Resource;

use FluentCart\App\App;
use FluentCart\App\Models\Meta;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class FluentMetaResource extends BaseResourceApi
{

    public static function getQuery(): Builder
    {
        return Meta::query();
    }

    /**
     * Get meta based on the provided parameters.
     *
     * @param array $params Array containing the necessary parameters.
     *        [
     *              'meta_key' => (string) Required. The meta key to retrieve data.
     *              'default'  => (mixed) Optional. Default value to return if data is not found. 
     *        ]
     *
     */
    public static function get(array $params = [])
    {
        $metaKey = Arr::get($params, 'meta_key');
        $default = Arr::get($params, 'default');

        $metaData = static::getQuery()->where('meta_key', $metaKey)->first();

        if (!empty($metaData)) {
            $metaData = $metaData->toArray();
        }

        if (isset($metaData['meta_value'])) {
            return Arr::get($metaData, 'meta_value');
        }

        return $default;
    }

    public static function find($id, $params = [])
    {

    }

    public static function create($data, $params = [])
    {

    }

    /**
     * Update metadata with the given data
     *
     * @param array $data   Required. Array containing the necessary parameters.
     *        [
     *              'meta_key'   => (string) Required. The key of the metadata.
     *              'meta_value' => (mixed) Required. The value of the metadata.
     *              'type'       => (string) Optional. The type of the object.
     *        ]
     * @param int   $id     Required. The ID of the product for which metadata is updated.
     * @param array $params Optional. Additional parameters for updating metadata.
     *        [
     *              // Include optional parameters, if any.
     *        ]
     *
     */
    public static function update($data, $id, $params = [])
    {

        $metaKey = Arr::get($data, 'meta_key');
        $metaValue = Arr::get($data, 'meta_value');
        $type = Arr::get($data, 'type', '');

        $existingMeta = static::getQuery()->where('meta_key', $metaKey)->first();

        if ($existingMeta) {
            $existingMeta->meta_value = $metaValue;
            return $existingMeta->update();
        }

        return static::getQuery()->create([
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_key' => $metaKey,
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'meta_value' => $metaValue,
            'object_id' => intval($id),
            'object_type' => $type,
        ]);
    }

    public static function delete($id, $params = [])
    {

    }
}