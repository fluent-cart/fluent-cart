<?php

namespace FluentCart\App\Http\Policies;

use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Foundation\Policy;

class PublicPolicy extends Policy
{
    /**
     * Check user permission for any method
     * @param \FluentCart\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request)
    {
        return true;
    }

    /**
     * Check user permission for any method
     * @param \FluentCart\Framework\Http\Request\Request $request
     * @return Boolean
     */
    public function create(Request $request)
    {
        return true;
    }
}
