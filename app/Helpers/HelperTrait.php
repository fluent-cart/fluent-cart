<?php

namespace FluentCart\App\Helpers;


/**
 * todo - need to consult with heera bhai regarding the approach of these common functions
 *
 */
trait HelperTrait
{
    private static array $orderByEnum = ['ASC', 'DESC'];


    /**
     *
     * @param $val
     * @param $stack
     * @param $def
     * @return mixed|string
     */
    private static function getValWithinEnum($val, $stack, $def = '')
    {
        return in_array($val, $stack) ? $val : $def;
    }


    /**
     *
     *
     * @param array $val
     * @param array $stack
     * @param array|string $def
     * @return array|string[]
     */
    private static function getArrValWithinEnum(array $val, array $stack, $def = ''): array
    {
        $ret = [];

        if (empty($val)) {

            return [];
        }

        foreach ($val as $item) {
            if (in_array($item, $stack)) {
                $ret[] = $item;
            }
        }

        if (empty($ret)) {

            if (is_array($def)) {

                return $def;
            }

            return [$def];
        }

        return $ret;
    }
}
