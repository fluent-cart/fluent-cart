<?php

namespace FluentCartPro\App\Modules\Integrations;

use FluentCartPro\App\Modules\Integrations\LMS\LearnDashLMSConnect;
use FluentCartPro\App\Modules\Integrations\LMS\LifterLMSConnect;

class IntegrationsInit
{
    public function register()
    {
        add_action('fluent_cart/init', function () {
            (new WebhookConnect())->register();
            (new LifterLMSConnect())->register();
            (new LearnDashLMSConnect())->register();
        });
    }


}
