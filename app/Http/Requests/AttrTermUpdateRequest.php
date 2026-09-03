<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\App\Models\AttributeGroup;
use FluentCart\Framework\Foundation\RequestGuard;
use FluentCart\Framework\Support\Arr;

class AttrTermUpdateRequest extends RequestGuard
{
    public function rules()
    {
        $rules = [
            'title'    => 'required|sanitizeText|maxLength:50',
            'settings' => 'nullable',
        ];

        // Same type-aware guards as create — keep the term's settings
        // consistent with the parent group's type.
        $groupType = $this->getGroupType();
        if ($groupType === 'color') {
            // Same `sanitize_hex_color` + `required` pairing as the bulk
            // endpoint — invalid hex collapses to empty, which the required
            // rule then catches with the standard user-facing message.
            $rules['settings.color'] = ['required'];
        } elseif ($groupType === 'image') {
            $rules['settings.image'] = ['required', 'url'];
        }

        return $rules;
    }

    public function messages()
    {
        return [
            'title.required'           => esc_html__('Title is required.', 'fluent-cart'),
            'settings.color.required'  => esc_html__('A color is required for color-type terms.', 'fluent-cart'),
            'settings.image.required'  => esc_html__('An image is required for image-type terms.', 'fluent-cart'),
            'settings.image.url'       => esc_html__('Image must be a valid URL.', 'fluent-cart'),
        ];
    }

    public function sanitize()
    {
        return [
            'title'          => 'sanitize_text_field',
            // Hex-only — sanitize_hex_color() returns null on bad input,
            // which trips the required rule for the user-facing error.
            'settings.color' => 'sanitize_hex_color',
            'settings.image' => 'esc_url_raw',
        ];
    }

    /**
     * Look up the parent attribute group's type ('color' | 'image' | 'options')
     * from the route param {group_id} so settings can be required to match.
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
