<?php

namespace FluentCart\Framework\Support;

use FluentCart\Framework\Support\Str;

/**
 * Class File
 * 
 * A wrapper for the WordPress Filesystem API.
 */
class File
{
    /**
     * Instance of the WordPress Filesystem API.
     * 
     * @var \WP_Filesystem_Base|false
     */
    protected static $filesystem;

    /**
     * Initialize or return the WordPress Filesystem API instance.
     * 
     * @return \WP_Filesystem_Base
     */
    public static function init()
    {
        return static::fileSystem();
    }

    /**
     * Initialize or return the WordPress Filesystem API instance.
     * 
     * @return \WP_Filesystem_Base
     */
    public static function fileSystem()
    {
        global $wp_filesystem;

        if (isset($wp_filesystem)) {
            return $wp_filesystem;
        }

        if (!function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (!WP_Filesystem()) {
            throw new \Exception(
                'Could not initialize the WordPress filesystem.'
            );
        }

        if (
            $wp_filesystem === null ||
            !($wp_filesystem instanceof \WP_Filesystem_Base)
        ) {
            throw new \Exception(
                'The WordPress filesystem object is not set or is invalid.'
            );
        }

        return $wp_filesystem;
    }

    /**
     * Check if a file or directory exists.
     * 
     * @param string $path
     * @return bool
     */
    public static function exists($path)
    {
        return static::fileSystem()->exists($path);
    }

    /**
     * Read the contents of a file.
     * 
     * @param string $path
     * @return string
     */
    public static function get($path)
    {
        return static::fileSystem()->get_contents($path);
    }

    /**
     * Read the contents of a file.
     * 
     * @param string $path
     * @return string
     */
    public static function read($path)
    {
        return static::get($path);
    }

    /**
     * Read the contents of a file.
     * 
     * @param string $path
     * @return string
     */
    public static function getJson($path, $asArray = true)
    {
        $content = static::get($path);

        $data = json_decode($content, $asArray);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                'Invalid JSON: ' . json_last_error_msg()
            );
        }

        return $data;
    }

    /**
     * Read the contents of a file as lines of array.
     * 
     * @param string $path
     * @return string
     */
    public static function getArray($path)
    {
        return static::fileSystem()->get_contents_array($path);
    }

    /**
     * Read the contents of a file as lines of array.
     * 
     * @param string $path
     * @return string
     */
    public static function readAsArray($path)
    {
        return static::getArray($path);
    }

    /**
     * Write contents to a file.
     * 
     * @param string $path
     * @param string $contents
     * @param int|false $mode The file permissions as octal number.
     * @return bool
     */
    public static function put($path, $contents, $mode = false)
    {
        return static::fileSystem()->put_contents(
            $path, $contents, $mode
        );
    }

    /**
     * Write contents to a file.
     * 
     * @param string $path
     * @param string $contents
     * @param int|false $mode The file permissions as octal number.
     * @return bool
     */
    public static function write($path, $contents, $mode = false)
    {
        return static::put($path, $contents, $mode);
    }

    /**
     * Append contents to a file.
     * 
     * @param string $path
     * @param string $contents
     * @return bool
     */
    public static function append($path, $contents)
    {
        $existingContents = static::exists($path) ? static::get($path) : '';

        $newContents = $existingContents . $contents;

        return static::put($path, $newContents);
    }

    /**
     * Prepend contents to a file.
     * 
     * @param string $path
     * @param string $contents
     * @return bool
     */
    public static function prepend($path, $contents)
    {
        $existingContents = static::exists($path) ? static::get($path) : '';

        $newContents = $contents . $existingContents;

        return static::put($path, $newContents);
    }


    /**
     * Delete a file or directory.
     * 
     * @param string $path
     * @param bool $recursive
     * @return bool
     */
    public static function delete($path, $recursive = true)
    {
        return static::fileSystem()->delete($path, $recursive);
    }

    /**
     * Delete a file or directory.
     * 
     * @param string $path
     * @param bool $recursive
     * @return bool
     */
    public static function deleteDirectory($path, $recursive = true)
    {
        return static::fileSystem()->delete($path, $recursive, 'd');
    }

    /**
     * Delete a file or directory.
     * 
     * @param string $path
     * @param bool $recursive
     * @return bool
     */
    public static function rmdir($path, $recursive = true)
    {
        return static::fileSystem()->delete($path, $recursive, 'd');
    }

    /**
     * Create a directory.
     * 
     * @param string $path
     * @param int $chmod
     * @param string|int|false $chown Optional. A user name or number.
     * @param string|int|false $chgrp Optional. A group name or number.
     * @return bool
     */
    public static function mkdir(
        $path, $chmod = FS_CHMOD_DIR,  $chown = false, $chgrp = false
    )
    {
        return static::fileSystem()->mkdir($path, $chmod,  $chown, $chgrp);
    }
    
    /**
     * Create a directory.
     * 
     * @param string $path
     * @param int $chmod
     * @param string|int|false $chown Optional. A user name or number.
     * @param string|int|false $chgrp Optional. A group name or number.
     * @return bool
     */
    public static function makeDirectory(
        $path, $chmod = FS_CHMOD_DIR,  $chown = false, $chgrp = false
    )
    {
        return static::mkdir($path, $chmod,  $chown, $chgrp);
    }

    /**
     * List files and directories in a path.
     * 
     * @param string $path
     * @param bool   $withHidden
     * @param bool   $recurse
     * @return array An associative array with details.
     */
    public static function list($path, $withHidden = true, $recurse = false)
    {
        return static::fileSystem()->dirlist($path, $withHidden, $recurse);
    }

    /**
     * Get the list of files and directories as plain array.
     * 
     * @param string $path
     * @param bool   $withHidden
     * @return array
     */
    public static function getList($path, $withHidden = true)
    {
        return array_values(array_map(function ($item) {
            return $item['name'];
        }, static::list($path, $withHidden)));
    }

    /**
     * List files in a path.
     * 
     * @param string $path
     * @param bool   $withHidden
     * @param bool   $recurse
     * @return array An associative array with details.
     */
    public static function files($path, $withHidden = true, $recurse = false)
    {
        $list = static::list($path, $withHidden, $recurse);

        return array_filter($list, function ($item) {
            return $item['type'] === 'f';
        });
    }

    /**
     * Get the list of files as plain array.
     * 
     * @param string $path
     * @param bool   $withHidden
     * @return array
     */
    public static function getFiles($path, $withHidden = true)
    {
        return array_values(array_map(function($item) {
            return $item['name'];
        }, static::files($path, $withHidden)));
    }

    /**
     * List directories in a path.
     * 
     * @param string $path
     * @param bool   $withHidden
     * @param bool   $recurse
     * @return array An associative array with details.
     */
    public static function directories($path, $withHidden = true, $recurse = true)
    {
        $list = static::list($path, $withHidden, $recurse);

        return array_filter($list, function ($item) {
            return $item['type'] === 'd';
        });
    }

    /**
     * Get the list of directories as plain array.
     * 
     * @param string $path
     * @param bool   $withHidden
     * @return array
     */
    public static function getDirectories($path, $withHidden = true)
    {
        return array_values(array_map(function($item) {
            return $item['name'];
        }, static::directories($path, $withHidden)));
    }

    /**
     * Copy a file.
     * 
     * @param string $source
     * @param string $dest
     * @param bool $overwrite
     * @return bool
     */
    public static function copy($source, $dest, $overwrite = true)
    {
        return static::fileSystem()->copy($source, $dest, $overwrite);
    }

    /**
     * Move a file.
     * 
     * @param string $source
     * @param string $destination
     * @param bool $overwrite
     * @return bool
     */
    public static function move($source, $destination, $overwrite = false)
    {
        return static::copy(
            $source, $destination, $overwrite
        ) && static::delete($source);
    }

    /**
     * Get file metadata.
     * 
     * @param string $path
     * @return array|false
     */
    public static function getInfo($path)
    {
        if (!static::exists($path)) {
            return false;
        }

        $fs = static::fileSystem();

        $metadata = [
            'path' => $path,
            'size' => $fs->size($path),
            'atime' => $fs->atime($path),
            'mtime' => $fs->mtime($path),
            'mode' => $fs->getchmod($path),
            'is_dir' => $fs->is_dir($path),
            'is_file' => $fs->is_file($path)
        ];

        if ($metadata['is_file']) {
            $metadata['is_image'] = static::isImage($path);
            if ($metadata['is_image']) {
                $metadata['image_meta'] = static::getImageMetadata($path);
            }
        }

        return $metadata;
    }

    /**
     * Get file/dir metadata using stat.
     *
     * @param string $path
     * @return array|false
     */
    public static function getStats($path)
    {
        if (!static::exists($path)) {
            return false;
        }

        clearstatcache();

        $stat = stat($path);

        // Convert permissions to a readable format (e.g., "rw-r--r--")
        // Get the last 3 characters (user, group, others)
        $permissions = substr(sprintf('%o', $stat['mode']), -3);
        
        $permissionsString = '';
        $permissionsString .= ($stat['mode'] & 0x0100) ? 'r' : '-'; // Owner read
        $permissionsString .= ($stat['mode'] & 0x0080) ? 'w' : '-'; // Owner write
        $permissionsString .= ($stat['mode'] & 0x0040) ? 'x' : '-'; // Owner execute
        $permissionsString .= ($stat['mode'] & 0x0020) ? 'r' : '-'; // Group read
        $permissionsString .= ($stat['mode'] & 0x0010) ? 'w' : '-'; // Group write
        $permissionsString .= ($stat['mode'] & 0x0008) ? 'x' : '-'; // Group execute
        $permissionsString .= ($stat['mode'] & 0x0004) ? 'r' : '-'; // Others read
        $permissionsString .= ($stat['mode'] & 0x0002) ? 'w' : '-'; // Others write
        $permissionsString .= ($stat['mode'] & 0x0001) ? 'x' : '-'; // Others execute

        $permissionsNumeric = (int) substr(sprintf('%o', $stat['mode']), -3); 

        $metadata = [
            'path' => $path,
            'size' => $stat['size'],
            'size_string' => size_format($stat['size']),
            'last_modified_timestamp' => $stat['mtime'],
            'last_modified_at' => date('Y-m-d H:i:s', $stat['mtime']),
            'last_access_timestamp' => $stat['atime'],
            'last_accessed_at' => date('Y-m-d H:i:s', $stat['atime']),
            'last_change_timestamp' => $stat['ctime'],
            'last_changed_at' => date('Y-m-d H:i:s', $stat['ctime']),
            'permission' => $permissionsNumeric,
            'permission_string' => $permissionsString,
            'is_dir' => ($stat['mode'] & 0040000) === 0040000,
            'is_file' => ($stat['mode'] & 0100000) === 0100000
        ];

        if ($metadata['is_file']) {
            $metadata['is_image'] = static::isImage($path);
            if ($metadata['is_image']) {
                $metadata['image_meta'] = static::getImageMetadata($path);
            }
        }

        return $metadata;
    }


    /**
     * Searches for metadata in the first 8 KB of a file.
     * 
     * @param string $file
     * @param array $keys
     * @return array
     */
    public static function getMetaData($file, $keys = [])
    {
        $data = [];

        if (!file_exists($file)) {
            return $data;
        }

        $content = file_get_contents($file, false, null, 0, 8 * 1024);

        if ($content === false) {
            return $data;
        }

        $content = str_replace("\r", "\n", $content);

        $pattern = '/^(?:[ \t]*<\?php)?[ \t\/*#@]*(.*?):(.*)$/mi';

        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[1] as $key => $value) {
                $name = str_replace(' ', '_', strtolower(trim($value)));
                $data[$name] = trim($matches[2][$key]);
            }
        }

        $normalizedKeys = array_map(function ($key) {
            return strtolower(str_replace(' ', '_', $key));
        }, $keys);

        return $keys ? array_intersect_key(
            $data, array_flip($normalizedKeys)
        ) : $data;
    }

    /**
     * Check if a file is an image based on its MIME type.
     * 
     * @param string $path
     * @return bool
     */
    public static function isImage($path)
    {
        return file_is_valid_image($path);
    }

    /**
     * Get image metadata using EXIF.
     * 
     * @param string $path
     * @return array|false
     */
    public static function getImageMetadata($path)
    {
        if (!static::exists($path)) {
            return false;
        }

        $image_info = getimagesize($path);
        
        if ($image_info === false) {
            return false;
        }

        $metadata = wp_read_image_metadata($path);

        return array_merge([
            'width' => $image_info[0],
            'height' => $image_info[1],
            'type' => $image_info['mime'],
        ], $metadata ?: []);
    }

    /**
     * Dynamically handle calls to the filesystem API.
     *
     * @param string $method
     * @param array $args
     * @return mixed
     */
    public function __call($method, $args)
    {
        $fs = static::fileSystem();

        if (!method_exists($fs, $method)) {
            $method = strtolower(
                preg_replace([
                    '/([a-z\d])([A-Z])/', '/([^_])([A-Z][a-z])/'
                ], '$1_$2', $method)
            );
        }

        return $fs->{$method}(...$args);
    }

    /**
     * Dynamically handle static calls to the filesystem API.
     *
     * @param string $method
     * @param array $args
     * @return mixed
     */
    public static function __callStatic($method, $args)
    {
        return (new static)->{$method}(...$args);
    }
}
