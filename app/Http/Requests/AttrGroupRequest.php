<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;

class AttrGroupRequest extends RequestGuard
{
    public function rules()
    {
        // Only trust group_id from the URL on PUT requests (updateGroup). On
        // POST createGroup there is no URL group_id, and we MUST NOT honour a
        // body-supplied value — a malicious client could POST {"group_id": N,
        // "slug": "color"} to force the unique-slug validator to exclude row
        // N from its check and slip past validation. The DB-level UNIQUE on
        // slug backstops the insert either way, but ignoring body-supplied
        // identifiers here keeps the validator's contract honest.
        $groupId = strtoupper((string) $this->method()) === 'PUT'
            ? $this->get('group_id')
            : null;
        $tbl = 'fct_atts_groups';

        // Build the slug rule conditionally. On PUT the client sends the
        // existing slug; it may be absent on POST because the UI omits the
        // slug field and the backend auto-generates it from the title instead.
        // When present on PUT, enforce uniqueness while excluding the current row.
        $slugRule = $groupId
            ? 'nullable|sanitizeText|maxLength:50|unique:' . $tbl . ',slug,' . (int) $groupId . ',id'
            : 'nullable|sanitizeText|maxLength:50|unique:' . $tbl . ',slug';

        return [
            'title'       => 'required|sanitizeText|maxLength:50',
            'slug'        => $slugRule,
            'description' => 'nullable|sanitizeTextArea',
            'settings'    => 'nullable',
        ];
    }

    public function messages()
    {
        return [
            'title'       => esc_html__('Group title can not be empty.', 'fluent-cart'),
            'slug'        => esc_html__('Group slug can not be empty and must be unique.', 'fluent-cart'),
            'description' => esc_html__('Group description should be long text.', 'fluent-cart'),
        ];
    }

    public function sanitize()
    {
        return [
            'title'       => 'sanitize_text_field',
            // sanitize_textarea_field (not sanitize_text_field) so newlines survive.
            // rules() declares the field as sanitizeTextArea — using the single-line
            // sanitizer would silently flatten multi-line descriptions to one line.
            'description' => 'sanitize_textarea_field',
            // sanitize_title (not sanitize_text_field) so user-typed slugs end up
            // URL-safe ("My Color" → "my-color"). Slugs are POSTed as both title
            // AND slug from the product editor; without this, slugs end up with
            // raw spaces and break anything that round-trips them through URLs.
            'slug'        => 'sanitize_title',
            'settings'    => function ($value) {
                if (!is_array($value)) {
                    return [];
                }
                return array_map('sanitize_text_field', $value);
            },
        ];
    }
}
