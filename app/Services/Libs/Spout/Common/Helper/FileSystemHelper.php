<?php

namespace Box\Spout\Common\Helper;

use Box\Spout\Common\Exception\IOException;

/**
 * Class FileSystemHelper
 * This class provides helper functions to help with the file system operations
 * like files/folders creation & deletion
 */
class FileSystemHelper implements FileSystemHelperInterface
{
    /** @var string Real path of the base folder where all the I/O can occur */
    protected $baseFolderRealPath;

    /**
     * @param string $baseFolderPath The path of the base folder where all the I/O can occur
     */
    public function __construct(string $baseFolderPath)
    {
        $this->baseFolderRealPath = \realpath($baseFolderPath);

        // Check if directory exists
        global $wp_filesystem;

        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
    }

    /**
     * Creates an empty folder with the given name under the given parent folder.
     *
     * @param string $parentFolderPath The parent folder path under which the folder is going to be created
     * @param string $folderName The name of the folder to create
     * @throws \Box\Spout\Common\Exception\IOException If unable to create the folder or if the folder path is not inside of the base folder
     * @return string Path of the created folder
     */
    public function createFolder($parentFolderPath, $folderName)
    {
        $this->throwIfOperationNotInBaseFolder($parentFolderPath);

        $folderPath = $parentFolderPath . '/' . $folderName;
        $wasCreationSuccessful = wp_mkdir_p($folderPath);
        if (!$wasCreationSuccessful) {
            throw new IOException("Unable to create folder: " . esc_attr($folderPath));
        }

        return $folderPath;
    }

    /**
     * Creates a file with the given name and content in the given folder.
     * The parent folder must exist.
     *
     * @param string $parentFolderPath The parent folder path where the file is going to be created
     * @param string $fileName The name of the file to create
     * @param string $fileContents The contents of the file to create
     * @throws \Box\Spout\Common\Exception\IOException If unable to create the file or if the file path is not inside of the base folder
     * @return string Path of the created file
     */
    public function createFileWithContents($parentFolderPath, $fileName, $fileContents)
    {
        $this->throwIfOperationNotInBaseFolder($parentFolderPath);

        $filePath = $parentFolderPath . '/' . $fileName;

        $wasCreationSuccessful = \file_put_contents($filePath, $fileContents);
        if ($wasCreationSuccessful === false) {
            throw new IOException("Unable to create file: " . esc_attr($filePath));
        }

        return $filePath;
    }

    /**
     * Delete the file at the given path
     *
     * @param string $filePath Path of the file to delete
     * @throws \Box\Spout\Common\Exception\IOException If the file path is not inside of the base folder
     * @return void
     */
    public function deleteFile($filePath)
    {
        $this->throwIfOperationNotInBaseFolder($filePath);

        if (\file_exists($filePath) && \is_file($filePath)) {
            wp_delete_file($filePath);
        }
    }

    /**
     * Delete the folder at the given path as well as all its contents
     *
     * @param string $folderPath Path of the folder to delete
     * @throws \Box\Spout\Common\Exception\IOException If the folder path is not inside of the base folder
     * @return void
     */
    public function deleteFolderRecursively($folderPath)
    {
        $this->throwIfOperationNotInBaseFolder($folderPath);

        $itemIterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($folderPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($itemIterator as $item) {
            if ($item->isDir()) {
                global $wp_filesystem;
                $wp_filesystem->rmdir( $item->getPathname(), true );
            } else {
                wp_delete_file($item->getPathname());
            }
        }
        global $wp_filesystem;
        if ( $wp_filesystem->is_dir( $folderPath ) ) {
            $wp_filesystem->rmdir( $folderPath );
        }
    }

    /**
     * All I/O operations must occur inside the base folder, for security reasons.
     * This function will throw an exception if the folder where the I/O operation
     * should occur is not inside the base folder.
     *
     * @param string $operationFolderPath The path of the folder where the I/O operation should occur
     * @throws \Box\Spout\Common\Exception\IOException If the folder where the I/O operation should occur
     * is not inside the base folder or the base folder does not exist
     * @return void
     */
    protected function throwIfOperationNotInBaseFolder(string $operationFolderPath)
    {
        $operationFolderRealPath = \realpath($operationFolderPath);
        if (!$this->baseFolderRealPath) {
            throw new IOException(
                sprintf(
                /* translators: %s is the base folder path */
                    esc_html__('The base folder path is invalid: %s', 'fluent-cart'),
                    esc_html($this->baseFolderRealPath)
                )
            );
        }
        $isInBaseFolder = (\strpos($operationFolderRealPath, $this->baseFolderRealPath) === 0);
        if (!$isInBaseFolder) {
            throw new IOException(
                sprintf(
                /* translators: %s is the base folder path */
                    esc_html__('Cannot perform I/O operation outside of the base folder: %s', 'fluent-cart'),
                    esc_html($this->baseFolderRealPath)
                )
            );
        }
    }
}
