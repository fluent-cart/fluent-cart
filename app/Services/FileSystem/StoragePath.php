<?php

namespace FluentCart\App\Services\FileSystem;

/**
 * Resolves a user supplied file path against a storage directory.
 *
 * `file_path` reaches the storage drivers from request input on several
 * surfaces, and the drivers compose it onto their directory by plain string
 * concatenation. Neither sanitize_text_field() nor wp_normalize_path() resolves
 * a `..` segment, so the resolution has to happen here — once, so the path a
 * write boundary stores and the path a read boundary consumes are reduced the
 * same way.
 *
 * A leading separator is deliberately treated as relative rather than refused:
 * `"{$dir}/" . "/etc/passwd"` always resolved inside the storage directory, so
 * rejecting it would break stored paths that were never an escape.
 */
class StoragePath
{
    /**
     * Reduce a path to the segments that stay inside a storage directory.
     *
     * @param mixed $filePath
     * @return string The contained relative path, or '' when it escapes.
     */
    public static function relative($filePath): string
    {
        if (!is_string($filePath) && !is_numeric($filePath)) {
            return '';
        }

        $segments = [];

        foreach (explode('/', str_replace('\\', '/', (string)$filePath)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if (!$segments) {
                    // Walks above the storage directory.
                    return '';
                }

                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        if (!$segments) {
            return '';
        }

        return implode('/', $segments);
    }

    /**
     * Whether a path can be stored and later resolved inside a storage directory.
     *
     * @param mixed $filePath
     * @return bool
     */
    public static function isSafe($filePath): bool
    {
        return static::relative($filePath) !== '';
    }

    /**
     * Absolute path for $filePath inside $basePath.
     *
     * The file does not have to exist, so the result can address a destination
     * that is about to be written. When it does exist its real path is checked
     * too, because a symlink inside the directory can still point out of it.
     *
     * @param mixed $basePath
     * @param mixed $filePath
     * @return string The absolute path, or '' when it escapes $basePath.
     */
    public static function contain($basePath, $filePath): string
    {
        if (!is_string($basePath) || $basePath === '') {
            return '';
        }

        $relativePath = static::relative($filePath);

        if ($relativePath === '') {
            return '';
        }

        $realBasePath = realpath($basePath);
        $basePath = rtrim(str_replace('\\', '/', $realBasePath ? $realBasePath : $basePath), '/');

        if ($basePath === '') {
            return '';
        }

        $fullPath = $basePath . '/' . $relativePath;
        $realFullPath = realpath($fullPath);

        if ($realFullPath && strpos(str_replace('\\', '/', $realFullPath), $basePath . '/') !== 0) {
            // Resolved through a symlink that leaves the storage directory.
            return '';
        }

        return $fullPath;
    }
}
