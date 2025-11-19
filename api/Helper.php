<?php

namespace FluentCart\Api;

use FluentCart\Framework\Support\Arr;


/**
 * todo - need to consult with heera bhai regarding this approach
 *
 */
class Helper
{

    public static function getPermittedRoles()
    {
        if (!current_user_can('manage_options')) {
            return array(
                'capability' => array(),
                'roles'      => array()
            );
        }

        if (!function_exists('get_editable_roles')) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
        }
        $roles = \get_editable_roles();

        $formatted = array();
        foreach ($roles as $key => $role) {
            if ($key == 'administrator') {
                continue;
            }
            if ($key != 'subscriber') {
                $formatted[] = array(
                    'name' => $role['name'],
                    'key'  => $key
                );
            }
        }

        $capability = fluent_cart_get_option('_fluent_cart_admin_permissions');

        if (is_string($capability)) {
            $capability = [];
        }

        return array(
            'capability' => $capability,
            'roles'      => $formatted
        );
    }


    public static function savePermissions($capability)
    {
        if (current_user_can('manage_options')) {
            fluent_cart_update_option('_fluent_cart_admin_permissions', $capability);
        } else {
            throw new \Exception(esc_html__('Sorry, You can not update permissions. Only administrators can update permissions', 'fluent-cart'));
        }

        return array(
            'message' => esc_html__('Successfully updated the role(s).', 'fluent-cart')
        );
    }

    /**
     *
     * @param $val
     * @param $stack
     * @param $def
     * @return mixed|string
     */
    public static function getValWithinEnum($val, $stack, $def = '')
    {

        return in_array($val, $stack) ? $val : $def;
    }

    /**
     * sanitize by fields
     * where $data should be an array like [key => value]
     * $fields will be like [key=>array( 'type'=> '', 'value'=> '')]
     */
    public static function sanitize($data, $fields)
    {
        $sanitizers = [
            'email'     => function ($value) {
                if(empty($value)) {
                    return '';
                }

                return sanitize_email($value);
            },
            'url'       => 'esc_url_raw',
            'number'    => 'floatval',
            'textarea'  => 'sanitize_textarea_field',
            'text'      => 'sanitize_text_field',
            'html_attr' => 'wp_kses_post',
            'provider'  => 'sanitize_text_field',
            'validate'  => 'sanitize_text_field',
        ];

        foreach ($fields as $key => $field) {
            if (!isset($data[$key])) {
                continue;
            }
            $type = $field['type'] ?? 'text';
            // Skip non-user input types
            if (in_array($type, ['notice', 'tab'])) {
                continue;
            }
            if ($type === 'password') {
                continue;
            }
            $value = $data[$key];
            if (is_array($value)) {
                $data[$key] = self::deepSanitize($value);
            } elseif (isset($sanitizers[$type]) && is_callable($sanitizers[$type])) {
                $data[$key] = call_user_func($sanitizers[$type], $value);
            } else {
                // Fallback to text sanitizer
                $data[$key] = sanitize_text_field($value);
            }
        }

        return $data;
    }

    protected static function deepSanitize($value)
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::deepSanitize($v);
            }
            return $value;
        }

        return sanitize_text_field($value);
    }


    public static function sanitizeTextAll($data = [])
    {
        $sanitizedData = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitizedData[$key] = static::sanitizeTextAll($value);
            } else {
                $sanitizedData[$key] = sanitize_text_field($value);
            }
        }
        return $sanitizedData;
    }


    /**
     * to get dynamic select search by any query params for settings page
     */
    public static function getSearchOptions($data)
    {
        $key = Arr::get($data, 'search_for', '');
        $searchBy = Arr::get($data, 'search_by', '');
        return apply_filters('fluent_cart/get_dynamic_search_' . $key, [], [
            'searchBy' => $searchBy
        ]);
    }

    /**
     *
     *
     * @param array $val
     * @param array $stack
     * @param array|string $def
     * @return array|string[]
     */
//    public static function getArrValWithinEnum(array $val, array $stack, $def = ''): array
//    {
//        $ret = [];
//
//        if (empty($val)) {
//
//            return [];
//        }
//
//        foreach ($val as $item) {
//            if (in_array($item, $stack)) {
//                $ret[] = $item;
//            }
//        }
//
//        if (empty($ret)) {
//
//            if (is_array($def)) {
//
//                return $def;
//            }
//
//            return [$def];
//        }
//
//        return $ret;
//    }


}
