<?php

namespace FluentCart\Api\Validator;

class FluentActivityValidator extends Validation
{
    public static function rules(): array
    {
        return [
            'status' => 'sanitizeText|maxLength:20',
            'log_type' => 'sanitizeText|maxLength:20',
            'user_id' => 'integer',
            'module_id' => 'integer',
            'title' => 'sanitizeText|maxLength:100',
            'content' => 'sanitizeTextArea',
            'module_type' => 'sanitizeText|maxLength:192',
            'module_name' => 'sanitizeText|maxLength:192',
        ];
    }

}
