<?php

namespace FluentCart\App\Http\Policies;

use FluentCart\Framework\Http\Request\Request;

class LabelPolicy extends Policy
{
    public function verifyRequest(Request $request): bool
    {
        return $this->hasRoutePermissions();
    }


}