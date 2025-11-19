<?php

namespace FluentCart\App\Http\Policies;

use FluentCart\Framework\Http\Request\Request;

class OrderPolicy extends Policy
{
    public function verifyRequest(Request $request)
    {
        return $this->hasRoutePermissions();
    }
}
