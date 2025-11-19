<?php

namespace FluentCart\App\Http\Requests;

use FluentCart\Framework\Foundation\RequestGuard;

class AttrTermRequest extends RequestGuard
{

    /**
     * @return array
     */
    public function rules()
    {
        $termId = $this->get('term_id');
        $tbl = 'fct_atts_terms';

        /**
         * todo - consult with heera bhai how to proceed with this composit unique validation
         *
         */
        return [
            'title' => 'required|sanitizeText|maxLength:50|unique:' . $tbl . ',title,'.$termId.',id',
            'slug' => 'required|sanitizeText|maxLength:50|unique:' . $tbl . ',slug,'.$termId.',id',
            'description' => 'nullable|sanitizeTextArea',
            'serial' => 'nullable|numeric'
        ];
    }


    /**
     * @return array
     */
    public function messages()
    {
        return [
            'title.required' => esc_html__('Title is required', 'fluent-cart'),
            'slug.required' => esc_html__('Slug is required', 'fluent-cart'),

        ];
    }


    /**
     * @return array
     */
    public function sanitize()
    {
        return [
            'serial' => 'sanitize_text_field',
            'description' => 'sanitize_text_field',
            'slug' => 'sanitize_text_field'
        ];
    }
}
