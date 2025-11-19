<?php

namespace FluentCart\App\Http\Policies;

use FluentCart\Framework\Http\Request\Request;

class DashboardPolicy extends Policy
{
    public function verifyRequest(Request $request): bool
    {
        return $this->hasRoutePermissions();
    }


}