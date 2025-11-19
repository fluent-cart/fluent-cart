<?php
namespace FluentCart\App\Http\Policies;

use FluentCart\Framework\Http\Request\Request;

class CustomerFrontendPolicy extends Policy
{
    public function verifyRequest(Request $request): bool
    {
        // check user logged in
        return is_user_logged_in();
    }
}
