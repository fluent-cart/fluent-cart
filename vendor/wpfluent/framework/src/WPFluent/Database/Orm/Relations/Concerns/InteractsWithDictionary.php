<?php

namespace FluentCart\Framework\Database\Orm\Relations\Concerns;

use FluentCart\Framework\Support\InvalidArgumentException;

trait InteractsWithDictionary
{
    /**
     * Get a dictionary key attribute - casting it to a string if necessary.
     *
     * @param  mixed  $attribute
     * @return mixed
     *
     * @throws \FluentCart\Framework\Support\InvalidArgumentException
     */
    protected function getDictionaryKey($attribute)
    {
        if (is_object($attribute)) {
            if (method_exists($attribute, '__toString')) {
                return $attribute->__toString();
            }

            if (function_exists('enum_exists')) {
                if ($attribute instanceof \BackedEnum) {
                    return $attribute->value;
                }
            }

            throw new InvalidArgumentException(
                'Model attribute value is an object but does not have a __toString method.'
            );
        }

        return $attribute;
    }
}
