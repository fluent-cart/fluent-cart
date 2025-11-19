<?php

namespace FluentCart\Framework\Support;

class MediaUploader
{
    /**
     * @var bool Whether to disable EXIF reading during upload
     */
    protected static bool $withoutExif = false;

    /**
     * @var bool Whether to disable generating
     * intermediate image sizes during upload
     */
    protected static bool $withoutSizes = false;

    /**
     * Disable EXIF reading during upload.
     * 
     * @return static
     */
    public static function withoutExif()
    {
        static::$withoutExif = true;
        return new static;
    }

    /**
     * Disable generating intermediate image sizes during upload.
     * 
     * @return static
     */
    public static function withoutSizes()
    {
        static::$withoutSizes = true;
        return new static;
    }

    /**
     * Disable both EXIF reading and image sizes during upload.
     * 
     * @return static
     */
    public static function withoutExifAndSizes()
    {
        static::$withoutExif = true;
        static::$withoutSizes = true;
        return new static;
    }

    /**
     * Upload a local file array or remote URL, optionally disabling EXIF or image sizes.
     *
     * @param array|string $resource File array from $_FILES or remote URL
     * @param int $postId Optional post ID to attach media to
     * @param string|null $filename Optional filename for remote URLs
     *
     * @return array
     */
    public static function upload($resource, $postId = 0, $filename = null)
    {
        $media = new Media();

        if (static::$withoutExif) {
            $media->withoutExif();
        }

        if (static::$withoutSizes) {
            $media->withoutSizes();
        }

        // Reset flags so they don't persist between calls
        static::$withoutExif = false;
        static::$withoutSizes = false;

        return $media->upload($resource, $postId, $filename);
    }
}
