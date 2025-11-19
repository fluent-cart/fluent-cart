<?php

namespace FluentCart\App\Services\FileSystem;

use FluentCart\Api\StorageDrivers;
use FluentCart\App\Services\FileSystem\Drivers\BaseDriver;
use FluentCart\App\Services\FileSystem\Drivers\Local\LocalDriver;
use FluentCart\App\Services\FileSystem\Drivers\S3\S3Driver;
use FluentCart\Framework\Support\Arr;

class FileManager
{
    /**
     * @var $driver BaseDriver|S3Driver|LocalDriver
     **/
    private $driver;
    protected ?string $dirPath;
    protected ?string $dirName;


    public function getDriver()
    {
        return $this->driver;
    }

    public function __construct(
        string $driver = 'local',
        ?string $dirPath = null,
        ?string $dirName = null,
        $inActiveMode = false
    )
    {
        $this->dirName = $dirName;
        $this->dirPath = $dirPath;
        $this->driver = $this->resolveDriver($driver, $inActiveMode);
    }

    public function listFiles(array $params = [])
    {
        return $this->driver->listFiles($params);
    }

    public function bucketLists()
    {
        return $this->driver->buckets();
    }


    public function uploadFile(string $localFilePath, string $uploadToFilePath, $file, array $params = [])
    {
        return $this->driver->uploadFile($localFilePath, $uploadToFilePath, $file, $params);
    }

    public function downloadFile(string $filePath, string $fileName, $bucket = null)
    {
        return $this->driver->downloadFile($filePath, $fileName, $bucket);
    }

    public function getSignedDownloadUrl(string $filePath, $bucket = null, $productDownload = null): ?string
    {
        return $this->driver->getSignedDownloadUrl($filePath, $bucket, $productDownload);
    }

    public function getFilePath(string $filePath, $bucket = null)
    {
        return $this->driver->getFilePath($filePath, null, $bucket);
    }

    public function deleteFile(string $filePath, $bucket = null)
    {
        return $this->driver->deleteFile($filePath, $bucket);
    }


    private function resolveDriver(string $driver, $inActiveMode)
    {

        $storageDrivers = new StorageDrivers();

        if($inActiveMode){
            $storages = $storageDrivers->getAll();
        }else{
            $storages = $storageDrivers->getActive(true);
        }

        $storage = Arr::get($storages, $driver.'.instance');

        if(empty($storage)){
            throw new \Exception(esc_html__('Invalid driver', 'fluent-cart'));
        }

        $driverClass = $storage->getDriverClass();

        return new $driverClass($this->dirPath, $this->dirName);
    }


    public function saveSettings($params)
    {
        return $this->driver->saveSettings($params);
    }

}
