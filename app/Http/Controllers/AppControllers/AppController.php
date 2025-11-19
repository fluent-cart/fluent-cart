<?php

namespace FluentCart\App\Http\Controllers\AppControllers;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Http\Request\File;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;

class AppController extends Controller
{
    public function init(Request $request): \WP_REST_Response
    {
        return $this->sendSuccess([
            'rest' => Helper::getRestInfo(),
            'asset_url' => Vite::getAssetUrl(),
            'trans' => TransStrings::getStrings(),
            'shop' => Helper::shopConfig(),
        ]);
    }

    public function attachments(): \WP_REST_Response
    {
        $query_images_args = array(
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'post_status' => 'inherit',
            'posts_per_page' => -1,
        );

        $query_images = new \WP_Query($query_images_args);

        $image_list = [];
        foreach ($query_images->posts as $image) {
            $image_title = get_the_title($image->ID);
            $image_url = wp_get_attachment_url($image->ID);
            $image_list[] = array(
                'id' => $image->ID,
                'title' => $image_title,
                'url' => $image_url,
            );
        }

        if (count($image_list)) {
            return $this->sendSuccess(
                [
                    'attachments' => $image_list
                ]
            );
        }
        return $this->sendError(
            [
                'message' => __('No Images Found', 'fluent-cart')
            ]
        );

    }

    public function uploadAttachments(Request $request)
    {


        foreach ($request->files() as $file) {
            if ($file instanceof File) {
                $fileArray = $file->toArray();
                $wp_filetype = wp_check_filetype_and_ext($fileArray['tmp_name'], $fileArray['name']);

                if (wp_match_mime_types('image', $wp_filetype['type'])) {

                    if (!function_exists('media_handle_upload')) {
                        require_once(ABSPATH . 'wp-admin/includes/image.php');
                        require_once(ABSPATH . 'wp-admin/includes/file.php');
                        require_once(ABSPATH . 'wp-admin/includes/media.php');
                    }

                    $attachment_id = media_handle_upload('file', 0, []);

                    if ($attachment_id instanceof \WP_Error) {
                        return $this->sendError([
                            'error' => $attachment_id->get_error_messages()
                        ]);
                    }
                    return Arr::only(
                        wp_prepare_attachment_for_js($attachment_id),
                        ['id', 'title', 'url']
                    );
                }
                return $this->sendError([
                    'error' => __('Error Uploading File', 'fluent-cart')
                ]);


            }
        }

        return $this->sendError(__('No File Attached', 'fluent-cart'));
    }
}