<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;

class AttrGroupRequest extends RequestGuard
{

    /**
     * title can not be empty
     *
     * todo - AR_Docs
     *
     * Usage of unique - unique:tbl,col ; unique:tbl,col,pk_val; unique:tbl,col,pk_col,pk_val; does not support more than 4 parameter.
     *
     * @return string[]
     */
    public function rules()
    {
        $groupId = $this->get('group_id');
        $tbl = 'fct_atts_groups';

        return [
            'title' => 'required|sanitizeText|maxLength:50|unique:' . $tbl . ',title,' . $groupId.',id',
            'slug'  => 'required|sanitizeText|maxLength:50|unique:' . $tbl . ',slug,' . $groupId.',id',
            'description' => 'nullable|sanitizeTextArea',

        ];
    }

    /**
     *
     * @return array
     */
    public function messages()
    {
        return [
            'title' => esc_html__('Group title can not be empty and must be unique.', 'fluent-cart'),
            'slug' => esc_html__('Group slug can not be empty and must be unique.', 'fluent-cart'),
            'description' => esc_html__('Group description should be long text.', 'fluent-cart'),
            'settings' => esc_html__('Group settings should be long text.', 'fluent-cart'),
        ];
    }


    /**
     *
     * @return array
     */
    public function sanitize()
    {
        return [
            'title' => 'sanitize_text_field',
            'description' => 'sanitize_text_field',
            'slug' => 'sanitize_text_field'
        ];
    }
}
