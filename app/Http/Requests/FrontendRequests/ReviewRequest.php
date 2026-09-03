<?php

namespace FluentCart\App\Http\Requests\FrontendRequests;

use FluentCart\Framework\Foundation\RequestGuard;

/**
 * RequestGuard forwards unknown calls to the wrapped request via __call(), and its
 * $request property is protected with no accessor. Declaring the forwarded methods
 * we actually use keeps static analysis honest about calls that resolve at runtime.
 *
 * @method string|null get_header(string $key)
 */
class ReviewRequest extends RequestGuard
{
    public function rules(): array
    {
        return [
            'rating'         => 'nullable|numeric|min:1|max:5',
            'title'          => 'nullable|sanitizeText|maxLength:255',
            'content'        => 'nullable|maxLength:5000',
            'reviewer_name'  => 'nullable|sanitizeText|maxLength:100',
            'reviewer_email' => 'nullable|sanitizeText|email',
            'per_page'       => 'nullable|numeric|min:1|max:50',
            'sort_by'        => 'nullable|sanitizeText|maxLength:50',
            'sort_order'     => 'nullable|sanitizeText|maxLength:10',
            'has_media'      => 'nullable|numeric',
            'verified_only'  => 'nullable|numeric',
        ];
    }

    public function messages(): array
    {
        return [
            'rating.numeric'    => esc_html__('Rating must be a number.', 'fluent-cart'),
            'rating.min'        => esc_html__('Rating must be at least 1.', 'fluent-cart'),
            'rating.max'        => esc_html__('Rating must not exceed 5.', 'fluent-cart'),
            'title.maxLength'   => esc_html__('Review title must not exceed 255 characters.', 'fluent-cart'),
            'content.required'  => esc_html__('Review content is required.', 'fluent-cart'),
            'content.maxLength' => esc_html__('Review content must not exceed 5000 characters.', 'fluent-cart'),
        ];
    }

    public function sanitize(): array
    {
        return [
            'rating'         => 'intval',
            'title'          => 'sanitize_text_field',
            'content'        => 'sanitize_textarea_field',
            'reviewer_name'  => 'sanitize_text_field',
            'reviewer_email' => 'sanitize_email',
            'per_page'       => 'intval',
            'sort_by'        => 'sanitize_text_field',
            'sort_order'     => 'sanitize_text_field',
            'has_media'      => 'intval',
            'verified_only'  => 'intval',
        ];
    }
}
