<?php

namespace FluentCart\Api\Validator;

class FluentMetaValidator extends Validation
{
    public static function rules(): array
    {
        return [
            'object_id' => 'integer|min:1',
            'object_type' => 'sanitizeText|maxLength:50',
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_key' => 'sanitizeText|maxLength:192',
        ];
    }
}

