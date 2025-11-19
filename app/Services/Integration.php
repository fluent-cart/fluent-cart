<?php

namespace FluentCart\App\Services;

use FluentCart\App\Modules\Integrations\FluentPlugins\FluentCommunityConnect;
use FluentCart\App\Modules\Integrations\FluentPlugins\FluentCRMConnect;
use FluentCart\App\Modules\Integrations\FluentPlugins\FluentCRMDeepIntegration;
use FluentCart\App\Modules\Integrations\FluentPlugins\FluentSupportWidget;

class Integration
{
    public function register(): void
    {
        add_action('init', function () {
            $this->init();
            (new \FluentCart\App\Listeners\IntegrationEventListener())->registerHooks();
        }, 2);
    }

    private function init()
    {
        (new FluentCRMConnect)->register();
        (new FluentCommunityConnect())->register();

        if (defined('FLUENTCRM')) {
            (new FluentCRMDeepIntegration())->init();
        }

        if(defined('FLUENT_SUPPORT_VERSION')) {
            (new FluentSupportWidget())->register();
        }
    }

}
