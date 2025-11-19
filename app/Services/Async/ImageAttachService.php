<?php

namespace FluentCart\App\Services\Async;

use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Models\DynamicModel;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\Framework\Support\Str;

class ImageAttachService
{

    public function __construct()
    {
        $this->requireFiles();
    }

    public static function attachImageToProduct(array $product, array $images)
    {

        if (empty($images)) {
            return;
        }

        $gallery = [];
        $instance = new ImageAttachService();

        foreach ($images as $image) {
            $value = $instance->addImageFromUrl($image, $product['post_title']);
            if (!empty($value)) {
                $gallery[] = $value;
            }
        }

        if (!empty($gallery)) {
            update_post_meta($product['ID'], FluentProducts::CPT_NAME . '-gallery-image', $gallery);
        }

    }

    public static function attachImageToVariant($variantId, array $images)
    {
        $variant = ProductVariation::query()->find($variantId);
        if (empty($variant)) {
            return;
        }
        
        if (empty($images)) {
            return;
        }

        $instance = new ImageAttachService();

        $metaValue = [];
        foreach ($images as $image) {
            $value = $instance->addImageFromUrl($image, $variant['variation_title']);
            if (!empty($value)) {
                $metaValue[] = $value;
            }

        }
        if (!empty($metaValue)) {
            $media = [
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key' => 'product_thumbnail',
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value' => $metaValue,
                'object_id' => $variant['id'],
                'object_type' => 'product_variant_info'

            ];
            $variant->media()->create($media);
        }

    }

    protected function addImageFromUrl($url, $title): array
    {
        $uploadUrl = wp_upload_dir()['baseurl'];
        $uploadUrl = Str::of($uploadUrl)->replace('http://', '')->replace('https://', '')->toString();
        $tmpUrl = Str::of($url)->replace('http://', '')->replace('https://', '')->toString();

        $localImageAttachment = null;
        if (Str::startsWith($tmpUrl, $uploadUrl)) {
            $localImageAttachment = $this->prepareAttachmentForJsFromUrl($url);
        }

        if (!empty($localImageAttachment)) {
            return $localImageAttachment;
        }

        $attachment_id = media_sideload_image($url, 0, $title, 'id');

        if ($attachment_id instanceof \WP_Error) {
            return [];
        }
        $media = wp_prepare_attachment_for_js($attachment_id);
        return [
            'id' => $attachment_id,
            'title' => $media['title'],
            'url' => $media['url']
        ];
    }

    public function prepareAttachmentForJsFromUrl(string $url): ?array
    {
        $url = Str::of($url)->replace('http://', '')->replace('https://', '')->toString();
        $attachment = (new DynamicModel([], 'posts'))
            ->newQuery()
            ->where('post_type', 'attachment')
            ->where('guid', 'LIKE', '%' . $url)
            ->orderByDesc('ID')
            ->first();


        if (!empty($attachment)) {
            return [
                'id' => $attachment->ID,
                'title' => $attachment->post_title,
                'url' => $attachment->guid
            ];
        }
        return null;
    }

    protected function requireFiles()
    {
        if (!function_exists('media_handle_upload')) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
        }
    }
}