<?php

namespace FluentCart\Api\Sanitizer;

class Sanitizer
{


    public const SANITIZE_EMAIL = 'sanitize_email';
    public const SANITIZE_FILE_NAME = 'sanitize_file_name';
    public const SANITIZE_HEX_COLOR = 'sanitize_hex_color';
    public const SANITIZE_HEX_COLOR_NO_HASH = 'sanitize_hex_color_no_hash';
    public const SANITIZE_HTML_CLASS = 'sanitize_html_class';
    public const SANITIZE_KEY = 'sanitize_key';
    public const SANITIZE_META = 'sanitize_meta';
    public const SANITIZE_MIME_TYPE = 'sanitize_mime_type';
    public const SANITIZE_OPTION = 'sanitize_option';
    public const SANITIZE_SQL_ORDER_BY = 'sanitize_sql_orderby';
    public const SANITIZE_TERM = 'sanitize_term';
    public const SANITIZE_TERM_FIELD = 'sanitize_term_field';
    public const SANITIZE_TEXT_FIELD = 'sanitize_text_field';
    public const SANITIZE_TEXTAREA_FIELD = 'sanitize_textarea_field';
    public const SANITIZE_TITLE = 'sanitize_title';
    public const SANITIZE_TITLE_FOR_QUERY = 'sanitize_title_for_query';
    public const SANITIZE_TITLE_WITH_DASHES = 'sanitize_title_with_dashes';
    public const SANITIZE_USER = 'sanitize_user';
    public const SANITIZE_URL = 'sanitize_url';
    public const WP_KSES = 'wp_kses';
    public const WP_KSES_POST = 'wp_kses_post';

    public const FLOATVAL = 'floatval';


    public static function getDefaultSanitizerMap(): array
    {
        return [
            'email'     => Sanitizer::SANITIZE_EMAIL,
            'address'   => Sanitizer::SANITIZE_TEXTAREA_FIELD,
            'file'      => Sanitizer::SANITIZE_URL,
            'url'       => Sanitizer::SANITIZE_URL,
            'number'    => Sanitizer::FLOATVAL,
            'textarea'  => Sanitizer::SANITIZE_TEXTAREA_FIELD,
            'text'      => SANITIZER::SANITIZE_TEXT_FIELD,
            'html_attr' => Sanitizer::WP_KSES_POST,
            'provider'  => SANITIZER::SANITIZE_TEXT_FIELD,
            'validate'  => SANITIZER::SANITIZE_TEXT_FIELD,
        ];
    }


    public static function sanitize(array $data, array $sanitizeMap = null): array
    {
        $sanitizeMap = $sanitizeMap ?? Sanitizer::getDefaultSanitizerMap();
        $sanitizedData = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitizedData[$key] = self::sanitize($value, $sanitizeMap);
                continue;
            }
            
            $sanitizeFunction = $sanitizeMap[$key] ?? Sanitizer::SANITIZE_TEXT_FIELD;
            
            if (is_callable($sanitizeFunction) || (is_string($sanitizeFunction) && function_exists($sanitizeFunction))) {
                $sanitizedData[$key] = $sanitizeFunction($value);
            } else {
                $sanitizedData[$key] = sanitize_text_field($value);
            }
        }

        return $sanitizedData;
    }


}