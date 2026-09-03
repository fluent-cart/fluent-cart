<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\Resource\UserResource;
use FluentCart\Api\StorageDrivers;
use FluentCart\App\Hooks\Handlers\GlobalStorageHandler;
use FluentCart\App\Http\Requests\UserRequest;
use FluentCart\App\Services\FileSystem\FileManager;
use FluentCart\App\Services\FileSystem\StoragePath;
use FluentCart\Framework\Http\Request\File;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;

class FileUploadController extends Controller
{

    public function index(Request $request)
    {
        $driver = sanitize_text_field($request->get('driver', 'local')) ?: 'local';

        if ($error = $this->invalidDriverResponse($driver)) {
            return $error;
        }

        return [
            'files' => (new FileManager($driver))->listFiles($request->all())
        ];
    }

    public function getBucketList(Request $request)
    {
        $driver = sanitize_text_field($request->get('driver', 'local')) ?: 'local';

        if ($error = $this->invalidDriverResponse($driver)) {
            return $error;
        }

        $bucketList = (new FileManager($driver))->bucketLists();
        $buckets = [];
        foreach ($bucketList as $bucket) {
            $buckets[] = array(
                "label" => $bucket,
                "value" => $bucket,
            );
        }
        $driverSettings = (new GlobalStorageHandler)->getSettings($driver);

        return [
            // "default_bucket" => Arr::get($driverSettings, 'settings.bucket'),
            "default_bucket" => Arr::get($buckets, '0.value', ''),
            "buckets"        => $buckets
        ];
    }

    private function invalidDriverResponse($driver)
    {
        $availableDrivers = array_keys((new StorageDrivers())->getActive());

        if (in_array($driver, $availableDrivers, true)) {
            return null;
        }

        if (empty($availableDrivers)) {
            $message = __('No storage drivers are enabled. Please enable a storage driver from the storage settings.', 'fluent-cart');
        } else {
            /* translators: %1$s: comma separated list of available storage drivers */
            $message = sprintf(__('Invalid driver. Available drivers: %1$s', 'fluent-cart'), implode(', ', $availableDrivers));
        }

        return $this->sendError([
            'message'           => $message,
            'available_drivers' => $availableDrivers
        ], 422);
    }

    public function upload(Request $request)
    {
        /**
         * @var $file File
         */


        $request->validate([
            'name' => 'required|sanitizeText|maxLength:160',
        ]);

        $file = Arr::get($request->files(), 'file');

        if (empty($file)) {
            return $this->sendError([
                'message'    => __('Failed To Upload File', 'fluent-cart'),
                'additional' => 'File is empty'
            ]);
        }

        $fileName = sanitize_file_name($file->getClientOriginalName());
        $driver = sanitize_text_field($request->get('driver', 'local'));

        if ($name = sanitize_file_name($request->get('name'))) {
            $fileName = $name.'.' . $file->getClientOriginalExtension();
        }


        return (new FileManager($driver))->uploadFile(
            $file->getRealPath(),
            $fileName,
            $file,
            $request->all(),
        );
    }

    public function uploadEditorFile(Request $request)
    {
        $file = Arr::get($request->files(), 'file');
        if ($file instanceof File) {
            $fileArray = $file->toArray();

            $wp_filetype = wp_check_filetype_and_ext($fileArray['tmp_name'], $fileArray['name']);

            if (wp_match_mime_types('image', $wp_filetype['type'])) {
                require_once(ABSPATH . 'wp-admin/includes/image.php');
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/media.php');
                $attachment_id = media_handle_upload('file', 0, []);
                return wp_prepare_attachment_for_js($attachment_id);
            }
            return $this->sendError(__('Error Uploading File', 'fluent-cart'));
        }

        return $this->sendError(__('No File Attached', 'fluent-cart'));
    }

    public function deleteFile(Request $request)
    {
        $request->validate([
            'file_path' => 'required|string',
            'driver' => 'required|string'
        ]);

        $filePath = sanitize_text_field($request->get('file_path'));
        $driver = sanitize_text_field($request->get('driver'));
        $bucket = sanitize_text_field($request->get('bucket'));

        // `..` survives sanitize_text_field(). The local driver contains the
        // path itself, but no driver has a use for a relative segment, so it is
        // refused here for every driver.
        if (!StoragePath::isSafe($filePath)) {
            return $this->sendError([
                'message' => __('Invalid file path', 'fluent-cart')
            ], 422);
        }

        $result = (new FileManager($driver))->deleteFile($filePath, $bucket);

        if (is_wp_error($result)) {
            return $this->sendError([
                'message' => $result->get_error_message()
            ]);
        }

        return $this->sendSuccess($result);
    }

}