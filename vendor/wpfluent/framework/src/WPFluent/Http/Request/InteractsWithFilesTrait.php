<?php

namespace FluentCart\Framework\Http\Request;

use FluentCart\Framework\Http\Request\File;
use FluentCart\Framework\Support\Helper;
use FluentCart\Framework\Support\Collection;

trait InteractsWithFilesTrait
{
    /**
     * Processed $_FILES array
     * @var array
     */
    protected $files = [];

    /**
     * Prepares HTTP files for Request
     *
     * @param array $files
     *
     * @return array
     */
    public function prepareFiles($files = [])
    {
        foreach ($files as $key => &$file) {
            $file = $this->convertFileInformation($file);
        }

        return $files;
    }

    /**
     * @taken from \Symfony\Component\HttpFoundation\FileBag
     *
     * Converts uploaded files to UploadedFile instances.
     *
     * @param array|File $file A (multi-dimensional) array of uploaded file information
     *
     * @return File[]|File|null A (multi-dimensional) array of File instances
     */
    protected function convertFileInformation($file)
    {
        $fileKeys = array('error', 'name', 'size', 'tmp_name', 'type');

        if ($file instanceof File) {
            return $file;
        }

        $file = $this->fixPhpFilesArray($file);

        if (is_array($file)) {
            $keys = array_keys($file);
            sort($keys);

            if ($keys == $fileKeys) {
                if (UPLOAD_ERR_NO_FILE == $file['error']) {
                    $file = null;
                } else {
                    $file = new File(
                        $file['tmp_name'],
                        $file['name'],
                        $file['type'],
                        $file['size'],
                        $file['error']
                    );
                }
            } else {
                $file = array_map(array($this, 'convertFileInformation'), $file);
                if (array_keys($keys) === $keys) {
                    $file = array_filter($file);
                }
            }
        }

        return $file;
    }

    /**
     * @taken from \Symfony\Component\HttpFoundation\FileBag
     *
     * Fixes a malformed PHP $_FILES array.
     *
     * PHP has a bug that the format of the $_FILES array differs, depending on
     * whether the uploaded file fields had normal field names or array-like
     * field names ("normal" vs. "parent[child]").
     *
     * This method fixes the array to look like the "normal" $_FILES array.
     *
     * It's safe to pass an already converted array, in which case this method
     * just returns the original array unmodified.
     *
     * @return array
     */
    protected function fixPhpFilesArray($data)
    {
        $fileKeys = array('error', 'name', 'size', 'tmp_name', 'type');

        if (!is_array($data)) {
            return $data;
        }

        // Remove extra key added by PHP 8.1.
        unset($data['full_path']);
        $keys = array_keys($data);
        sort($keys);

        if (
            $fileKeys != $keys ||
            !isset($data['name']) ||
            !is_array($data['name'])
        ) {
            return $data;
        }

        $files = $data;
        foreach ($fileKeys as $k) {
            unset($files[$k]);
        }

        foreach ($data['name'] as $key => $name) {
            $files[$key] = $this->fixPhpFilesArray(array(
                'error'    => $data['error'][$key],
                'name'     => $name,
                'type'     => $data['type'][$key],
                'tmp_name' => $data['tmp_name'][$key],
                'size'     => $data['size'][$key],
            ));
        }

        return $files;
    }

    /**
     * Retrieve a file from the request.
     *
     * @param  string|null  $key
     * @param  mixed  $default
     * @return \FluentCart\Framework\Http\Request\File|array|null
     */
    public function file($key = null, $default = null)
    {
        return Helper::dataGet($this->files(), $key, $default);
    }

    /**
     * Get the files array from the request.
     *
     * @return array
     */
    public function files($key = null)
    {
        if (empty($this->files)) {
            $this->files = $this->prepareFiles($_FILES);
        }

        return Helper::dataGet($this->files, $key, $this->files);
    }

    /**
     * Get the files as collection from the request.
     *
     * @return array
     */
    public function fileCollection($key = null)
    {   
        $collection = Helper::collect(
            Helper::dataGet($this->files, $key, $this->files)
        );

        if (!method_exists($collection, 'save')) {
            $this->addSaveMethod($collection);
        }

        return $collection;
    }

    /**
     * Add a save method on the runtime.
     * 
     * @param Collection &$files
     * @return void
     */
    protected function addSaveMethod(Collection &$files)
    {
        Collection::macro('save', function ($path = null) use ($files) {
            return $files->map(function ($file) use ($path) {
                // When developer will use the method without key name:
                // i.e: $request->fileCollection(), an array of arrays
                // ['images' => [0 => File, 1 => File]] will be
                // returned, so $file will contain an array
                // of File objects, [0 => File, 1 => File].
                if (is_array($file)) {
                    $savedFiles = [];
                    foreach ($file as $fileObject) {
                        $savedFiles[] = $fileObject->save($path);
                    }
                    return $savedFiles;
                }

                // For $request->fileCollection('images')
                // [0 => File, 1 => File] will be returned.
                return $file->save($path);
            });
        });
    }

    /**
     * Determine if the request contains a valid uploaded file for the given key.
     *
     * @param  string  $key
     * @return bool
     */
    public function hasFile($key)
    {
        $file = $this->file($key);

        if (is_array($file)) {
            foreach ($file as $f) {
                if ($f instanceof File && $f->isValid()) {
                    return true;
                }
            }
            return false;
        }

        return $file instanceof File && $file->isValid();
    }

    /**
     * Alias for hasFile().
     *
     * @param  string  $key
     * @return bool
     */
    public function isValidFile($key)
    {
        return $this->hasFile($key);
    }
}
