<?php

namespace Tests\Support\Traits;

use FluentCart\App\App;
use FluentCart\Framework\Http\Request\Request as FluentRequest;

/**
 * Code that reads App::request() outside a REST dispatch sees whatever request
 * the container holds, so a test binds the params it wants that code to read.
 */
trait BindsFluentRequest
{
    protected function bindFluentRequest(array $params): void
    {
        $app = App::getInstance();
        $request = new FluentRequest($app, $params, []);
        $app->instance(FluentRequest::class, $request);
        $app->instance('request', $request);
    }
}
