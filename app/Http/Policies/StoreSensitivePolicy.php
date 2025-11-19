<?php

namespace FluentCart\App\Http\Policies;

use FluentCart\Framework\Http\Request\Request;

class StoreSensitivePolicy extends Policy
{
    public function verifyRequest(Request $request)
    {
        do_action('fluent_cart/policy/store_sensitive_request', [
            'request' => $request
        ]);

        return $this->userCan('store/sensitive');
    }
}
