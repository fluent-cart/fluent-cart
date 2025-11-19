<?php

namespace FluentCart\App\Modules\Subscriptions\Http\Policies;

use FluentCart\Framework\Foundation\Policy;
use FluentCart\Framework\Http\Request\Request;

class SubscriptionsPolicy extends Policy
{
    /**
     * Check user permission for any method
     * @param \FluentCart\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        return true;
        return current_user_can('manage_options');
    }

    /**
     * Check user permission for any method
     * @param \FluentCart\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function create(Request $request)
    {
        return current_user_can('manage_options');
    }
}
