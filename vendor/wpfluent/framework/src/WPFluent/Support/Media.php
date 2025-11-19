<?php

namespace FluentCart\Framework\Support;

use RuntimeException;
use InvalidArgumentException;

class Media
{
    /**
     * Turn off reading exif.
     * 
     * @return $this
     */
    public function withoutExif()
    {
        add_filter('wp_read_image_metadata', '__return_empty_array');
        return $this;
    }

    /**
     * Turn off creating image sizes.
     * 
     * @return $this
     */
    public function withoutSizes()
    {
        add_filter('intermediate_image_sizes_advanced', '__return_empty_array');
        return $this;
    }

    /**
     * Turn off reading exif and creating image sizes.
     * 
     * @return $this
     */
    public function withoutExifAndSizes()
    {
        $this->withoutExif();
        $this->withoutSizes();
        return $this;
    }

    /**
     * Sideload a local file array (e.g. from $_FILES) or a remote file URL.
     *
     * @param array|string $resource  File array from $_FILES or remote file URL
     * @param int|null $postId        Optional post ID to attach the media to
     * @param string|null $filename   Optional file name for remote URL
     *
     * @return array
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function upload($resource, $postId = 0, $filename = null)
    {
        [$data, $tmp] = $this->prepareMedia($resource, $filename);

        $attachmentId = media_handle_sideload($data, $postId);

        $this->cleanup($tmp, $attachmentId);

        return [
            'success' => true,
            'id'      => $attachmentId,
            'url'     => wp_get_attachment_url($attachmentId),
        ];
    }

    /**
     * Prepare the file array to use with media_handle_sideload.
     * 
     * @param array|string $resource  File array from $_FILES or remote file URL
     * @param string|null $filename   Optional file name for remote URL
     *
     * @return array
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    protected function prepareMedia($resource, $filename = null)
    {
        $this->includeMediaFunctions();

        if (is_array($resource)) {
            if (empty($resource['tmp_name'])) {
                throw new InvalidArgumentException(
                    'Invalid file array: tmp_name is required.'
                );
            }

            return [[
                'name'     => $resource['name'] ?? 'file',
                'tmp_name' => $resource['tmp_name'],
                'type'     => $resource['type'] ?? null,
                'error'    => $resource['error'] ?? 0,
                'size'     => $resource['size_in_bytes'] ?? $resource['size'] ?? null,
            ], null];
        }

        if ($this->isBase64($resource)) {
            $resource = $this->base64ToUrl($resource);
        }

        if (is_string($resource) && filter_var($resource, FILTER_VALIDATE_URL)) {
            $tmp = download_url($resource);

            if (is_wp_error($tmp)) {
                throw new RuntimeException(
                    'Failed to download remote file: ' . $tmp->get_error_message()
                );
            }

            return [[
                'tmp_name' => $tmp,
                'name'     => $filename ?: basename(
                    parse_url($resource, PHP_URL_PATH)
                ),
            ], $tmp];
        }

        throw new InvalidArgumentException('Invalid file array or URL provided.');
    }

    /**
     * Ensure required WordPress media functions are loaded.
     * 
     * @return void
     */
    protected function includeMediaFunctions()
    {
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
    }

    /**
     * Checks if a string is base64 encoded.
     * 
     * @param  string $string
     * @return boolean
     */
    protected function isBase64($string)
    {
        return is_string($string) && str_starts_with($string, 'data:image');
    }

    /**
     * Saves a base64 encoded image to a file and returns the URL.
     *
     * @param string $imageData The base64 encoded image string.
     * @param string $extension Optional. The image file extension.
     * @return string The URL of the saved image on success.
     * @throws RuntimeException If the image could not be saved.
     */
    public function base64ToUrl($imageData, $extension = 'png')
    {
        if (strpos($imageData, 'base64,') !== false) {
            $imageData = explode('base64,', $imageData)[1];
        }

        $decoded = base64_decode($imageData);
        if (!$decoded) {
            throw new RuntimeException('Invalid base64 image data.');
        }

        $upload_dir = wp_upload_dir();

        if (!wp_mkdir_p($upload_dir['path'])) {
            throw new RuntimeException(
                'Upload directory does not exist and could not be created.'
            );
        }

        $filename = uniqid('wpfluent-') . '.' . $extension;
        $file_path = trailingslashit($upload_dir['path']) . $filename;
        $filUrl  = trailingslashit($upload_dir['url']) . $filename;

        if (file_put_contents($file_path, $decoded) === false) {
            throw new RuntimeException('Failed to save image file.');
        }

        return $filUrl;
    }

    /**
     * Delete the temporary file and throw an exception on failure.
     * 
     * @param  string $tmp
     * @param  int|\WP_Error $attachmentId
     * @return void
     */
    protected function cleanup($tmp, $attachmentId)
    {
        if ($tmp && file_exists($tmp)) {
            @unlink($tmp);
        }

        if (is_wp_error($attachmentId)) {
            throw new RuntimeException(
                'Failed to upload media: ' . $attachmentId->get_error_message()
            );
        }
    }

    /**
     * Generate and save attachment metadata (image sizes, dimensions, etc.)
     * 
     * @param  int $attachmentId
     * @return array
     */
    public function generateMetadata($attachmentId)
    {
        $this->includeMediaFunctions();

        $metadata = wp_generate_attachment_metadata(
            $attachmentId, get_attached_file($attachmentId)
        );

        wp_update_attachment_metadata(
            $attachmentId, $metadata
        );

        return $metadata;
    }

    /**
     * Update attachment title, caption, description, alt text, etc.
     * 
     * @param  int $attachmentId
     * @param  array  $fields      
     * @return void
     */
    public function updateAttachmentFields($attachmentId, array $fields = [])
    {
        $defaults = [
            'post_title'     => '',
            'post_content'   => '',
            'post_excerpt'   => '',
            'post_mime_type' => '',
        ];

        wp_update_post(array_merge([
            'ID' => $attachmentId], array_intersect_key($fields, $defaults)
        ));

        if (!empty($fields['alt'])) {
            update_post_meta(
                $attachmentId, '_wp_attachment_image_alt',
                sanitize_text_field($fields['alt'])
            );
        }
    }

    /**
     * Get detailed info about an attachment.
     * 
     * @param  int $attachmentId
     * @return array
     */
    public function getAttachmentArray($attachmentId)
    {
        return [
            'id'   => $attachmentId,
            'url'  => wp_get_attachment_url($attachmentId),
            'path' => get_attached_file($attachmentId),
            'type' => get_post_mime_type($attachmentId),
        ];
    }

    /**
     * Delete an attachment.
     * 
     * @param  int  $attachmentId
     * @param  boolean $forceDelete
     * @return \WP_Post|false|null Post data on success, false/null on failure.
     */
    public function delete($attachmentId, $forceDelete = false)
    {
        $this->includeMediaFunctions();

        return wp_delete_attachment($attachmentId, $forceDelete);
    }

    /**
     * Set an image as featured for a post.
     * 
     * @param int $attachmentId
     * @param int $postId
     * @return int|bool
     */
    public function setAsFeaturedImage($attachmentId, $postId)
    {
        $this->includeMediaFunctions();
        
        return set_post_thumbnail($postId, $attachmentId);
    }

    /**
     * Find an attachment by its file name.
     *
     * @param string $name  Filename to search for (can be partial).
     * @return array|null   Attachment data array or null if not found.
     */
    public function findByFilename($name)
    {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
             WHERE post_type = 'attachment' 
             AND post_mime_type LIKE 'image/%' 
             AND guid LIKE %s 
             ORDER BY post_date DESC LIMIT 1",
            '%' . $wpdb->esc_like($name) . '%'
        ));

        if (empty($results)) {
            return null;
        }

        return $this->getAttachmentArray($results[0]->ID);
    }

    /**
     * Get all media attachments associated with a specific post.
     *
     * @param int $postId
     * @return array  List of attachment data arrays.
     */
    public function findByPostId($postId)
    {
        $attachments = get_children([
            'post_parent'    => $postId,
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
        ]);

        $results = [];

        foreach ($attachments as $attachment) {
            $results[] = $this->getAttachmentArray($attachment->ID);
        }

        return $results;
    }

    /**
     * Find attachments by MIME type.
     *
     * @param string $mimeType e.g. 'image/jpeg', 'application/pdf', etc.
     * @return array List of attachment data arrays.
     */
    public function findByMimeType($mimeType)
    {
        $attachments = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'post_mime_type' => $mimeType,
        ]);

        return array_map(
            fn($attachment) => $this->getAttachmentArray($attachment->ID),
            $attachments
        );
    }

    /**
     * Find attachments uploaded within a date range.
     *
     * @param string $startDate Format: 'YYYY-MM-DD'
     * @param string $endDate   Format: 'YYYY-MM-DD'
     * @return array List of attachment data arrays.
     */
    public function findByDateRange($startDate, $endDate)
    {
        $attachments = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => -1,
            'date_query'     => [
                [
                    'after'     => $startDate,
                    'before'    => $endDate,
                    'inclusive' => true,
                ]
            ],
        ]);

        return array_map(
            fn($attachment) => $this->getAttachmentArray($attachment->ID),
            $attachments
        );
    }
}
