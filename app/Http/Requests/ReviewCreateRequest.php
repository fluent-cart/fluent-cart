<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\ProductReviewService;
use FluentCart\Framework\Foundation\RequestGuard;

/**
 * An admin-authored review. The shared ReviewRequest serves ten list and
 * moderation actions and can require nothing; creating a review has
 * required fields, so they are declared here, the way product create has
 * its own request class. A failure answers 422 with {field: {rule: message}},
 * which the Add Review form prints under each field.
 */
class ReviewCreateRequest extends RequestGuard
{
    /**
     * The required text fields are checked as they will be stored. The rules
     * run on the raw request, and sanitize_textarea_field() can strip a
     * markup-only body to nothing afterwards — "required" would pass and an
     * empty review would be saved. Cleaning them first closes that gap.
     *
     * @return array
     */
    public function beforeValidation()
    {
        $data = $this->all();

        // Text fields only: an array or object here is not text, and casting
        // it would yield the literal "Array", which "required" would accept.
        $clean = static function ($value, callable $sanitizer) {
            return is_scalar($value) ? trim($sanitizer((string) $value)) : '';
        };

        if (array_key_exists('content', $data)) {
            $data['content'] = $clean($data['content'], 'sanitize_textarea_field');
        }
        if (array_key_exists('reviewer_name', $data)) {
            $data['reviewer_name'] = $clean($data['reviewer_name'], 'sanitize_text_field');
        }
        if (array_key_exists('title', $data)) {
            $data['title'] = $clean($data['title'], 'sanitize_text_field');
        }

        return $data;
    }

    public function rules(): array
    {
        // Optional, unlike the guest storefront path: a moderator transcribing
        // a review from a phone call may have no address to enter. When one is
        // given it still has to be real, because the customer link and the
        // avatar are resolved from it.
        $rules = [
            'post_id'        => 'required|numeric',
            // The variation being reviewed, when the product has variations
            // worth telling apart. The controller checks it belongs to the
            // product — a rule cannot see post_id's value.
            'item_id'        => 'nullable|numeric',
            'content'        => 'required|maxLength:5000',
            'reviewer_name'  => 'required|sanitizeText|maxLength:100',
            'reviewer_email' => 'nullable|sanitizeText|email|maxLength:192',
            'title'          => 'nullable|sanitizeText|maxLength:192',
            'rating'         => 'nullable|numeric|min:0|max:5',
            'status'         => 'nullable|sanitizeText|in:' . implode(',', Status::getReviewStatuses()),
            'is_verified'    => 'nullable|numeric|min:0|max:1',
        ];

        // A store that collects ratings and requires them refuses a review
        // without one; with ratings optional or off, 0 means "none".
        if (ProductReviewService::isStarRatingRequired()) {
            $rules['rating'] = 'required|numeric|min:1|max:5';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'post_id.required'         => esc_html__('Please select a product for this review.', 'fluent-cart'),
            'post_id.numeric'          => esc_html__('Please select a product for this review.', 'fluent-cart'),
            'item_id.numeric'          => esc_html__('Please select a valid variation for this review.', 'fluent-cart'),
            'content.required'         => esc_html__('Review content is required.', 'fluent-cart'),
            'content.maxLength'        => esc_html__('Review content must not exceed 5000 characters.', 'fluent-cart'),
            'reviewer_name.required'   => esc_html__('Reviewer name is required.', 'fluent-cart'),
            'reviewer_name.maxLength'  => esc_html__('Reviewer name must not exceed 100 characters.', 'fluent-cart'),
            'reviewer_email.email'     => esc_html__('A valid email address is required', 'fluent-cart'),
            'reviewer_email.maxLength' => esc_html__('Email address must not exceed 192 characters.', 'fluent-cart'),
            'title.maxLength'          => esc_html__('Review title must not exceed 192 characters.', 'fluent-cart'),
            'rating.required'          => esc_html__('Star rating is required', 'fluent-cart'),
            'rating.numeric'           => esc_html__('Rating must be a number.', 'fluent-cart'),
            'rating.min'               => esc_html__('Star rating is required', 'fluent-cart'),
            'rating.max'               => esc_html__('Rating must not exceed 5.', 'fluent-cart'),
            'status.in'                => esc_html__('Invalid status', 'fluent-cart'),
        ];
    }

    public function sanitize(): array
    {
        return [
            'post_id'        => 'intval',
            'item_id'        => 'intval',
            'content'        => 'sanitize_textarea_field',
            'reviewer_name'  => 'sanitize_text_field',
            'reviewer_email' => 'sanitize_email',
            'title'          => 'sanitize_text_field',
            'rating'         => 'intval',
            'status'         => 'sanitize_text_field',
            'is_verified'    => 'intval',
        ];
    }
}
