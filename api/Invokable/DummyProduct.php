<?php

namespace FluentCart\Api\Invokable;

use FluentCart\Framework\Support\Arr;

class DummyProduct
{
    public function __invoke($app, $params)
    {
        \FluentCart\App\Services\Async\DummyProductService::createAll(Arr::get($params, 'category'));
    }
}