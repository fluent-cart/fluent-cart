<?php

namespace FluentCart\App\Services\FileSystem\Drivers;

use FluentCart\App\Modules\StorageDrivers\BaseStorageDriver;

abstract class BaseDriver
{
    protected ?string $dirPath;
    protected ?string $dirName;

    protected ?BaseStorageDriver $storageDriver;

    public function __construct(?string $dirPath = null, ?string $dirName = null)
    {
        $this->dirName = $dirName;
        $this->dirPath = $dirPath;
    }

    public function getStorageDriver(): ?BaseStorageDriver
    {
        return $this->storageDriver;
    }

    public function saveSettings($params)
    {
        return $this->storageDriver->saveSettings($params);
    }

    protected abstract function getDefaultDirPath();

    public abstract function listFiles(array $params = []);

    public abstract function uploadFile($localFilePath, $uploadToFilePath, $file, $params = []);


    public abstract function downloadFile(string $filePath);

    public abstract function getSignedDownloadUrl(string $filePath, $bucket = null, $productDownload = null);

    public abstract function getFilePath(string $filePath);

    protected abstract function retrieveFileForDownload(string $downloadableFilePath);

    public function setDownloadHeader($fileName, $file, $fileSize = null)
    {
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        // Append extension if $fileName doesn't already have one
        if (pathinfo($fileName, PATHINFO_EXTENSION) === '') {
            $fileName .= '.' . $extension;
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream', true, 200);
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        if (!empty($fileSize)) {
            header('Content-Length: ' . $fileSize);
            //header('Content-Length: ' . filesize($file));
        }

        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
    }


}