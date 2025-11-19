<?php

namespace FluentCart\App\Services\Widgets;

use FluentCart\Framework\Support\Arr;

abstract class BaseWidget
{
    abstract public function widgetName(): string;

    abstract public function widgetData(): array;

    public static function widgets(): array
    {
        $instance = new static();
        $stats = apply_filters('fluent_cart/' . $instance->widgetName(), $instance->widgetData());
        return Arr::wrap($stats);
    }
}