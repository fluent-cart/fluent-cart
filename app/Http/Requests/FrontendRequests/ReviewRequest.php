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
            // Star chips, comma separated ("5,4"). Five stars and four commas at most.
            'ratings'        => 'nullable|sanitizeText|maxLength:9',
            'title'          => 'nullable|sanitizeText|maxLength:192',
            'content'        => 'nullable|maxLength:5000',
            'reviewer_name'  => 'nullable|sanitizeText|maxLength:100',
            'reviewer_email' => 'nullable|sanitizeText|email|maxLength:192',
            // The order-review page's proof of purchase: an order uuid. Bounded
            // here so a junk value is rejected before it reaches a query.
            'order_hash'     => 'nullable|sanitizeText|maxLength:64',
            // The variation the review is about. Only honoured alongside an
            // order grant that covers that item; the controller forces 0
            // otherwise.
            'item_id'        => 'nullable|numeric',
            'per_page'       => 'nullable|numeric|min:1|max:50',
            'sort_by'        => 'nullable|sanitizeText|maxLength:50',
            'sort_order'     => 'nullable|sanitizeText|maxLength:10',
            'has_media'      => 'nullable|numeric',
            'verified_only'  => 'nullable|numeric',
            // Display flags are sent again when Reviews.js refreshes a page,
            // so the endpoint renders the same preset after sorting or
            // pagination. Keep them constrained to the wire's 0/1 shape.
            'show_avatar'    => 'nullable|numeric|min:0|max:1',
            'show_title'     => 'nullable|numeric|min:0|max:1',
            'show_content'   => 'nullable|numeric|min:0|max:1',
            'show_photos'    => 'nullable|numeric|min:0|max:1',
            'show_footer'    => 'nullable|numeric|min:0|max:1',
            'show_variation' => 'nullable|numeric|min:0|max:1',
            'page'           => 'nullable|numeric|min:1',
            'client_id'      => 'nullable|sanitizeText|maxLength:64',
            'rating_token'   => 'nullable|sanitizeText|maxLength:64',
            'max_words'      => 'nullable|numeric|min:0|max:500',
            'pagination_type'=> 'nullable|sanitizeText|maxLength:20',
            'media_visible'  => 'nullable|numeric|min:0|max:100',
            'media_width'    => 'nullable|numeric|min:0|max:2000',
            'media_height'   => 'nullable|numeric|min:0|max:2000',
            'media_full_width'=> 'nullable|numeric|min:0|max:1',
            'media_flush'     => 'nullable|numeric|min:0|max:1',
            'media_backdrop'  => 'nullable|numeric|min:0|max:1',
            'photos_first'    => 'nullable|numeric|min:0|max:1',
            'rating_first'    => 'nullable|numeric|min:0|max:1',
            'badge_last'      => 'nullable|numeric|min:0|max:1',
            'show_meta'       => 'nullable|numeric|min:0|max:1',
            'item_class'      => 'nullable|string',
            'media_more'     => 'nullable|sanitizeText|maxLength:20',
        ];
    }

    public function messages(): array
    {
        return [
            'rating.numeric'    => esc_html__('Rating must be a number.', 'fluent-cart'),
            'rating.min'        => esc_html__('Rating must be at least 1.', 'fluent-cart'),
            'rating.max'        => esc_html__('Rating must not exceed 5.', 'fluent-cart'),
            'title.maxLength'   => esc_html__('Review title must not exceed 192 characters.', 'fluent-cart'),
            'content.required'  => esc_html__('Review content is required.', 'fluent-cart'),
            'content.maxLength' => esc_html__('Review content must not exceed 5000 characters.', 'fluent-cart'),
        ];
    }

    public function sanitize(): array
    {
        return [
            'rating'         => 'intval',
            'ratings'        => 'sanitize_text_field',
            'title'          => 'sanitize_text_field',
            'content'        => 'sanitize_textarea_field',
            'reviewer_name'  => 'sanitize_text_field',
            'reviewer_email' => 'sanitize_email',
            'order_hash'     => 'sanitize_text_field',
            'item_id'        => 'intval',
            'per_page'       => 'intval',
            'sort_by'        => 'sanitize_text_field',
            'sort_order'     => 'sanitize_text_field',
            'has_media'      => 'intval',
            'verified_only'  => 'intval',
            'show_avatar'    => 'intval',
            'show_title'     => 'intval',
            'show_content'   => 'intval',
            'show_photos'    => 'intval',
            'show_footer'    => 'intval',
            'show_variation' => 'intval',
            'page'           => 'intval',
            'client_id'      => 'sanitize_text_field',
            'rating_token'   => 'sanitize_text_field',
            'max_words'      => 'intval',
            'pagination_type'=> 'sanitize_text_field',
            'media_visible'  => 'intval',
            'media_width'    => 'intval',
            'media_height'   => 'intval',
            'media_full_width'=> 'intval',
            'media_flush'     => 'intval',
            'media_backdrop'  => 'intval',
            'photos_first'    => 'intval',
            'rating_first'    => 'intval',
            'badge_last'      => 'intval',
            'show_meta'       => 'intval',
            'item_class'      => 'sanitize_html_class',
            'media_more'     => 'sanitize_text_field',
        ];
    }
}
