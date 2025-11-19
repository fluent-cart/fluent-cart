<?php

namespace FluentCart\App\Listeners\FirstTimePluginActivation;

use FluentCart\App\Events\FirstTimePluginActivation;

class SetupStore
{
    public static function handle(FirstTimePluginActivation $event)
    {
        $event->storeSettings->set('store_name', '');
        $event->storeSettings->set('store_logo', '');
    }
}
