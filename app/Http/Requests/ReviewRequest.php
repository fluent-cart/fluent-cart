<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;

class ReviewRequest extends RequestGuard
{
    public function rules(): array
    {
        return [
            'rating'      => 'nullable|numeric|min:0|max:5',
            'title'       => 'nullable|sanitizeText|maxLength:255',
            'content'     => 'nullable|maxLength:5000',
            'status'      => 'nullable|sanitizeText|maxLength:50',
            'post_id'     => 'nullable|numeric',
            'search'      => 'nullable|sanitizeText|maxLength:200',
            'sort_by'     => 'nullable|sanitizeText|maxLength:50',
            'sort_order'  => 'nullable|sanitizeText|maxLength:10',
            'sort_type'   => 'nullable|sanitizeText|maxLength:10',
            'active_view'      => 'nullable|sanitizeText|maxLength:50',
            'filter_type'      => 'nullable|sanitizeText|maxLength:20',
            'advanced_filters' => 'nullable|maxLength:5000',
            'user_tz'          => 'nullable|sanitizeText|maxLength:100',
            'per_page'         => 'nullable|numeric|min:1|max:100',
            'page'        => 'nullable|numeric|min:1',
            'action_type' => 'nullable|sanitizeText|maxLength:50',
            'review_ids'  => 'nullable|array',
            'review_ids.*'=> 'numeric',
        ];
    }

    public function messages(): array
    {
        return [
            'rating.numeric'   => esc_html__('Rating must be a number.', 'fluent-cart'),
            'rating.min'       => esc_html__('Rating must be at least 0.', 'fluent-cart'),
            'rating.max'       => esc_html__('Rating must not exceed 5.', 'fluent-cart'),
            'title.maxLength'  => esc_html__('Review title must not exceed 255 characters.', 'fluent-cart'),
            'content.maxLength'=> esc_html__('Review content must not exceed 5000 characters.', 'fluent-cart'),
        ];
    }

    public function sanitize(): array
    {
        return [
            'rating'         => 'intval',
            'title'          => 'sanitize_text_field',
            'content'        => 'sanitize_textarea_field',
            'status'         => 'sanitize_text_field',
            'reviewer_name'  => 'sanitize_text_field',
            'reviewer_email' => 'sanitize_email',
            'post_id'        => 'intval',
            'search'         => 'sanitize_text_field',
            'sort_by'        => 'sanitize_text_field',
            'sort_order'     => 'sanitize_text_field',
            'sort_type'      => 'sanitize_text_field',
            'active_view'    => 'sanitize_text_field',
            'filter_type'      => 'sanitize_text_field',
            'advanced_filters' => static function ($value) {
                if (is_array($value)) {
                    return map_deep($value, 'sanitize_text_field');
                }
                return is_string($value) ? sanitize_text_field($value) : $value;
            },
            'user_tz'        => 'sanitize_text_field',
            'per_page'       => 'intval',
            'page'           => 'intval',
            'action_type'    => 'sanitize_text_field',
            'review_ids.*'   => 'intval',
        ];
    }
}
