<?php

namespace FluentCart\App\Services\FileSystem\Drivers\Local;

use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\StorageDrivers\BaseStorageDriver;
use FluentCart\App\Modules\StorageDrivers\Local\Local as LocalStorageDriver;
use FluentCart\App\Services\FileSystem\Drivers\BaseDriver;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;

class LocalDriver extends BaseDriver
{
    public function getDirName(): string
    {
        return $this->dirName;
    }


    public function __construct(?string $dirPath = null, ?string $dirName = null)
    {
        parent::__construct($dirPath, $dirName);
        $this->dirName = $dirName ?? 'fluent-cart';
        $this->dirPath = $dirPath ?? $this->getDefaultDirPath();
        $this->storageDriver = new LocalStorageDriver();

        global $wp_filesystem;

        // Initialize the WP_Filesystem API
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $this->ensureDirectoryExist();

    }

    protected function getDefaultDirPath(): string
    {
        return wp_get_upload_dir()['basedir'] . DIRECTORY_SEPARATOR . $this->getDirName();
    }

    private function ensureDirectoryExist() {


        $uploadDirectory = $this->getDefaultDirPath();
        global $wp_filesystem;
        // Check if directory exists
        if (! $wp_filesystem->is_dir($uploadDirectory)) {
            $wp_filesystem->mkdir($uploadDirectory, FS_CHMOD_DIR);
        }
    }


    public function listFiles(array $params = []): array
    {
        $filesArray = [];
        $searchTerm = $params['search'] ?? '';

        $files = scandir($this->getDefaultDirPath());

        $maxFile = App::request()->get('per_page', 10);

        $fileCount = 1;

        foreach ($files as $file) {
            // Skip hidden files (starting with dot)
            if (str_starts_with($file, '.')) {
                continue;
            }

            if (str_ends_with($file, '.php')) {
                continue;
            }

            // Apply search filter if provided
            if (!empty($searchTerm) && stripos($file, $searchTerm) === false) {
                continue;
            }

            $filePath = $this->getDefaultDirPath() . '/' . $file;
            if (is_file($filePath)) {
                $filesArray[] = [
                    'name' => $file,
                    'size' => filesize($filePath),
                    'driver' => 'local',
                    'bucket' => ''
                ];
            }

            $fileCount++;
            if($fileCount > $maxFile) {
                break;
            }
        }

        return $filesArray;
    }

    public function uploadFile($localFilePath, $uploadToFilePath, $file, $params = [])
    {
        global $wp_filesystem;

        $uploadToFilePath = sanitize_file_name($uploadToFilePath);


        $fileInfo = $file->toArray();
        $fileSize =Arr::get($fileInfo, 'size_in_bytes', 0);

        $originalName  = $file->getClientOriginalName();
        $sanitizedName = sanitize_file_name($originalName);
        $checked  = wp_check_filetype_and_ext(
            $localFilePath,
            $sanitizedName
        );


        if (empty($checked['ext']) || empty($checked['type'])) {
            return new \WP_Error(
                'invalid_file_type',
                __('Invalid or unsupported file type.', 'fluent-cart')
            );
        }

        $blockedExts = ['php','phtml','html','htm','svg','exe','sh','bat','cmd','dll'];

        $blockedExts = apply_filters('fluent_cart/local_file_blocked_extensions',$blockedExts,[
            'localFilePath' => $localFilePath,
            'uploadToFilePath' => $uploadToFilePath,
            'fileInfo' => $fileInfo,
            'fileSize' => $fileSize,
            'originalName' => $originalName,
            'file' => $file,
            'checked_data' => $checked,
        ]);
        if (in_array($checked['ext'], $blockedExts, true)) {
            return new \WP_Error(
                'forbidden_type',
                __('This file type is not allowed.', 'fluent-cart')
            );
        }

        // Generate unique filename
        $uploadToFilePath = $uploadToFilePath . '__fluent-cart__.' . time() . '.' . $file->getClientOriginalExtension();

        // Define destination
        $destination = trailingslashit( $this->getDefaultDirPath() ) . $uploadToFilePath;

        // Read file contents from temp path
        $contents = @file_get_contents( $localFilePath );

        if ( $contents === false ) {
            return new \WP_Error( 'failed_to_read', __( 'Failed to read uploaded file', 'fluent-cart' ) );
        }

        // Write file using WP_Filesystem
        $isUploaded = $wp_filesystem->put_contents( $destination, $contents, FS_CHMOD_FILE );

        // Optionally delete temp file using WP_Filesystem for compliance
        if ( $wp_filesystem->exists( $localFilePath ) ) {
            $wp_filesystem->delete( $localFilePath );
        }

        if ( ! $isUploaded ) {
            return new \WP_Error( 'failed_to_upload', __( 'Failed to upload file', 'fluent-cart' ) );
        }

        return [
            'message' => __( 'File Uploaded Successfully', 'fluent-cart' ),
            'path'    => $uploadToFilePath,
            'file'    => [
                'driver' => 'local',
                'size'   => $fileSize,
                'name'   => $uploadToFilePath,
                'bucket' => '',
            ],
        ];
    }


    public function getSignedDownloadUrl(string $filePath, $bucket = null, $productDownload = null): string
    {
        return Helper::generateDownloadFileLink($productDownload);
    }

    public function downloadFile(string $filePath, $fileName = null)
    {
        $filePath = wp_normalize_path( $filePath );
        $fileName = sanitize_file_name($fileName);

        $file = "{$this->dirPath}/{$filePath}";
        if (ob_get_level()) {
            ob_end_clean();
        }

        if(!file_exists($file)) {
            return new \WP_Error('file_not_found', __('File not found', 'fluent-cart'));
        }
        $fileSize = filesize($file);
        $fileName = $fileName ?? basename($filePath);
        $fileName = explode('_____fluent-cart_____', $fileName)[0];
        $fileName = explode('__fluent-cart__', $fileName)[0];
        $this->setDownloadHeader($fileName ?? basename($filePath), $file, $fileSize);
        global $wp_filesystem;
        $content = $wp_filesystem->get_contents( $file );
        if ( $content !== false ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $content;
        }
        exit;
    }

    public function getFilePath(string $filePath, $fileName = null): string
    {
        return "{$this->dirPath}/{$filePath}";
    }

    protected function retrieveFileForDownload(string $downloadableFilePath)
    {
        // TODO: Implement retrieveFileForDownload() method.
    }

    public function deleteFile(string $filePath)
    {

        if ( !current_user_can('manage_options') ) {
            return new \WP_Error('permission_error', __('You are not allowed to delete file', 'fluent-cart'));
        }

        $filePath = wp_normalize_path($filePath);
        $fullPath = $this->getFilePath($filePath);
        
        if (!file_exists($fullPath)) {
            return new \WP_Error('file_not_found', __('File not found', 'fluent-cart'));
        }
        
        if (!is_file($fullPath)) {
            return new \WP_Error('not_a_file', __('Path is not a file', 'fluent-cart'));
        }
        
        if (wp_delete_file($fullPath)) {
            return [
                'message' => __('File Deleted Successfully', 'fluent-cart'),
                'driver' => 'local',
                'path' => $filePath
            ];
        }
        
        return new \WP_Error('failed_to_delete', __('Failed to delete file', 'fluent-cart'));
    }
}
