<?php

namespace FluentCart\App\Listeners\FirstTimePluginActivation;

use FluentCart\App\Events\FirstTimePluginActivation;

class ManagePayments
{
    public static function handle(FirstTimePluginActivation $event)
    {
        $event->storeSettings->set('currency', 'USD');
    }
}