<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\App\Models\AttributeGroup;
use FluentCart\Framework\Foundation\RequestGuard;
use FluentCart\Framework\Support\Arr;

class AttrTermRequest extends RequestGuard
{
    public function rules()
    {
        // Term slugs are uniquely scoped per group at the DB layer (composite UNIQUE
        // index on (group_id, slug) in AttributeTermsMigrator), so two groups can
        // share a term slug ("red" in Color and "red" in Theme). The Resource layer
        // handles per-group dedup + auto-suffixing; let the DB enforce final uniqueness
        // instead of duplicating a global validator rule that would over-reject.
        $rules = [
            'terms'            => ['required', function ($_, $value) {
                if (\is_array($value) && \count($value) > 10) {
                    return esc_html__('Cannot create more than 10 terms at once.', 'fluent-cart');
                }
            }],
            'terms.*.title'    => 'required|sanitizeText|maxLength:50',
            'terms.*.settings' => 'nullable',
        ];

        // Type-aware settings validation. The group's `settings.type` decides
        // whether each term row needs a color (hex string) or image (URL) —
        // an `image` group with no thumbnail would render an empty swatch in
        // the picker chips, and a `color` group with no hex breaks the dot.
        $groupType = $this->getGroupType();
        if ($groupType === 'color') {
            // The sanitize() map runs `sanitize_hex_color` on this field,
            // which returns an empty string for anything that isn't a valid
            // `#rgb` / `#rrggbb` — so `required` here doubles as the hex
            // validator without a separate regex rule.
            $rules['terms.*.settings.color'] = ['required'];
        } elseif ($groupType === 'image') {
            $rules['terms.*.settings.image'] = ['required', 'url'];
        }

        return $rules;
    }

    public function messages()
    {
        return [
            'terms.required'                 => esc_html__('At least one term is required.', 'fluent-cart'),
            'terms.*.title.required'         => esc_html__('Each term must have a title.', 'fluent-cart'),
            'terms.*.title.maxLength'        => esc_html__('Each title must be 50 characters or fewer.', 'fluent-cart'),
            'terms.*.settings.color.required' => esc_html__('A color is required for each term in a color group.', 'fluent-cart'),
            'terms.*.settings.image.required' => esc_html__('An image is required for each term in an image group.', 'fluent-cart'),
            'terms.*.settings.image.url'      => esc_html__('Image must be a valid URL.', 'fluent-cart'),
        ];
    }

    public function sanitize()
    {
        return [
            'terms.*.title'          => 'sanitize_text_field',
            // sanitize_hex_color() returns null for anything that isn't a
            // valid `#rgb` / `#rrggbb` — the rules() `required` check then
            // surfaces the user-facing "color is required" error.
            'terms.*.settings.color' => 'sanitize_hex_color',
            'terms.*.settings.image' => 'esc_url_raw',
        ];
    }

    /**
     * Look up the parent attribute group's type ('color' | 'image' | 'options')
     * so the rules() can require the matching settings field. The {group_id}
     * URL param is merged into the request inputs via WP_REST_Request::get_params(),
     * so $this->get() resolves it without touching the controller signature.
     *
     * @return string|null
     */
    protected function getGroupType()
    {
        $groupId = (int) $this->get('group_id', 0);
        if ($groupId <= 0) {
            return null;
        }
        $group = AttributeGroup::query()->find($groupId);
        if (!$group) {
            return null;
        }
        return Arr::get($group->settings ?: [], 'type');
    }
}
