<?php

namespace FluentCartPro\App\Http\Policies;
use FluentCart\App\Http\Policies\Policy;
use FluentCart\Framework\Http\Request\Request;


class OrderBumpPolicy extends Policy
{
    /**
     * Check user permission for any method
     * @param Request $request
     * @return Boolean
     */
    public function verifyRequest(Request $request): bool
    {
        return $this->hasRoutePermissions();
    }
}
