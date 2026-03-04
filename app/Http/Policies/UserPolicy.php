<?php

namespace FluentCart\App\Http\Policies;

use FluentCart\Framework\Http\Request\Request;

class UserPolicy extends Policy
{
    public function verifyRequest(Request $request): bool
    {
        return is_user_logged_in();
    }
}