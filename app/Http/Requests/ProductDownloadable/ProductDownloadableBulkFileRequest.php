<?php

namespace FluentCart\App\Http\Requests\ProductDownloadable;

use FluentCart\Framework\Foundation\RequestGuard;

class ProductDownloadableBulkFileRequest extends RequestGuard
{

    /**
     * @return array
     */
    public function rules(): array
    {

        return [
            'downloadable_files'                            => 'nullable|array',
            'downloadable_files.*.title'                    => 'required|sanitizeText|maxLength:160',
            'downloadable_files.*.type'                     => 'required|sanitizeText|maxLength:100',
            'downloadable_files.*.driver'                   => 'required|sanitizeText|maxLength:60',
            'downloadable_files.*.file_name'                => 'required|sanitizeText|maxLength:185',
            'downloadable_files.*.file_path'                => 'required|sanitizeText|maxLength:185',
            'downloadable_files.*.file_url'                 => 'required|sanitizeText|maxLength:200',
            'downloadable_files.*.settings.download_limit'  => 'nullable|numeric',
            'downloadable_files.*.settings.download_expiry' => 'nullable|numeric',
        ];
    }


    /**
     * @return array
     */
    public function messages(): array
    {
        return [
            'downloadable_files.*.title.required'     => esc_html__('Title is required.', 'fluent-cart'),
            'downloadable_files.*.type.required'      => esc_html__('Type is required.', 'fluent-cart'),
            'downloadable_files.*.driver.required'    => esc_html__('Driver is required.', 'fluent-cart'),
            'downloadable_files.*.file_name.required' => esc_html__('File Name is required.', 'fluent-cart'),
            'downloadable_files.*.file_path.required' => esc_html__('File Path is required.', 'fluent-cart'),
            'downloadable_files.*.file_url.required'  => esc_html__('File URL is required.', 'fluent-cart'),
        ];
    }


    /**
     * @return array
     */
    public function sanitize()
    {

        return [
            'downloadable_files.*.id'                   => 'intval',
            //'downloadable_files.*.post_id' => 'intval',
            'downloadable_files.*.product_variation_id' => 'sanitize_text_field',
            //'downloadable_files.*.download_identifier' => 'sanitize_text_field',
            'downloadable_files.*.title'                => 'sanitize_text_field',
            'downloadable_files.*.type'                 => 'sanitize_text_field',
            'downloadable_files.*.driver'               => 'sanitize_text_field',
            'downloadable_files.*.bucket'               => 'sanitize_text_field',
            'downloadable_files.*.file_name'            => 'sanitize_text_field',
            'downloadable_files.*.file_path'            => 'sanitize_text_field',
            'downloadable_files.*.file_url'             => 'sanitize_text_field',
            'downloadable_files.*.file_size'            => 'sanitize_text_field',
            'downloadable_files.*.settings'             => 'wp_kses_post',
            'downloadable_files.*.serial'               => 'intval',
            //'downloadable_files.*.created_at' => 'sanitize_text_field',
            //'downloadable_files.*.updated_at' => 'sanitize_text_field',
        ];

    }
}
