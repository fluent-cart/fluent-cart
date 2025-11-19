<?php

namespace FluentCart\App\Listeners\FirstTimePluginActivation;

use FluentCart\App\CPT\Pages;
use FluentCart\App\Events\FirstTimePluginActivation;
use FluentCart\Framework\Support\Str;

class CreatePages
{

    public static function handle(FirstTimePluginActivation $event)
    {
        static::createPages();
    }


    public static function createPages()
    {
        (new Pages())->createPages();
    }


}