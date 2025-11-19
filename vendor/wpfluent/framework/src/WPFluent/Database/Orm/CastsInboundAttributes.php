<?php

namespace FluentCart\Framework\Database\Orm;

interface CastsInboundAttributes
{
    /**
     * Transform the attribute to its underlying model values.
     *
     * @param  \FluentCart\Framework\Database\Orm\Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array  $attributes
     * @return mixed
     */
    public function set($model, string $key, $value, array $attributes);
}
