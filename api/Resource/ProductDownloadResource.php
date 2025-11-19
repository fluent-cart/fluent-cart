<?php

namespace FluentCart\Api\Resource;

use FluentCart\App\App;
use FluentCart\App\Models\ProductDownload;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;

class ProductDownloadResource extends BaseResourceApi
{
    public static function getQuery(): Builder
    {
        return ProductDownload::query();
    }

    public static function get(array $params = [])
    {

    }

    /**
     * Find downloadable file by ID
     *
     * @param int $id Required. The ID of the file to find.
     * @param array $params Optional. Additional parameters for finding file.
     *
     */
    public static function find($id, $params = [])
    {
        return static::getQuery()->find($id);
    }

    /**
     * Create a new file with the given data
     *
     * @param array $data Required. Array containing the necessary parameters for file creation.
     *        [
     *              'title'      => (string) Required. The title of the file,
     *              'type'       => (string) Required. The type of the file,
     *              'driver'     => (string) Required. The driver of the file,
     *              'file_name'  => (string) Required. The file_name of the file,
     *              'file_path'  => (string) Required. The file_path of the file,
     *              'file_url'   => (string) Required. The file_url of the file,
     *              'settings'   => (string) Optional. The settings of the file,
     *              'serial'     => (int) Required. The serial of the file,
     *        ]
     * @param array $params Optional. Additional parameters for file creation.
     *        [
     *              // Include optional parameters, if any.
     *        ]
     *
     */
    public static function create($data, $params = [])
    {
        $isCreated = static::getQuery()->create($data);

        if ($isCreated) {
            return static::makeSuccessResponse(
                $isCreated,
                __('File added successfully', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Failed to add file!', 'fluent-cart')]
        ]);
    }

    /**
     * Update file with the given data
     *
     * @param array $data Required. Array containing the necessary parameters for file update.
     *        [
     *              'title'      => (string) Required. The title of the file,
     *              'type'       => (string) Required. The type of the file,
     *              'driver'     => (string) Required. The driver of the file,
     *              'file_name'  => (string) Required. The file_name of the file,
     *              'file_path'  => (string) Required. The file_path of the file,
     *              'file_url'   => (string) Required. The file_url of the file,
     *              'settings'   => (string) Optional. The settings of the file,
     *              'serial'     => (int) Required. The serial of the file,
     *        ]
     * @param int $id Required. The ID of the file.
     * @param array $params Optional. Additional parameters for file update.
     *        [
     *              // Include optional parameters, if any.
     *        ]
     *
     */
    public static function update($data, $id, $params = [])
    {
        $isUpdated = ProductDownloadResource::find($id)->update($data);

        if ($isUpdated) {
            return static::makeSuccessResponse(
                $isUpdated,
                __('File updated successfully', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Failed to update file!', 'fluent-cart')]
        ]);
    }

    /**
     * Delete a downloadable file with the given ID and parameters.
     *
     * @param int $id Required. The ID of the product downloadable file to delete single file.
     * @param array $params Optional. Additional parameters for deleting multiple files.
     *        [
     *          'type'              => (string) Optional. Possible deletion type (all|byProduct)
     *                              ('all - means delete files by product id except specific files')
     *                              ('byProduct - means delete files by product id')
     *          'post_id'      => (int) Optional. Delete by particular product.
     *          'download_file_ids' => (array) Optional. Ignore to delete those files which are
     *                                 in download file ids array.
     *        ]
     *
     */
    public static function delete($id, $params = [])
    {
        if (static::getQuery()->where('id', $id)->delete()) {
            return static::makeSuccessResponse(
                '',
                __('File has been deleted successfully', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Failed to delete!', 'fluent-cart')]
        ]);
    }

}