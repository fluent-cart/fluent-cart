<?php

namespace FluentCart\App\Http\Policies;

use FluentCart\Framework\Http\Request\Request;

class StoreSettingsPolicy extends Policy
{
    public function verifyRequest(Request $request)
    {
        do_action('fluent_cart/policy/store_settings_request', [
            'request' => $request
        ]);

        return $this->hasRoutePermissions();
    }
}
