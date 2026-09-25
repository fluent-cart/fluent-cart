<?php
namespace FluentCart\App\Http\Policies;

use FluentCart\App\Services\CustomerIdentity\EmailVerificationService;
use FluentCart\Framework\Http\Request\Request;

class CustomerFrontendPolicy extends Policy
{
    public function verifyRequest(Request $request): bool
    {
        // check user logged in
        return is_user_logged_in() && !EmailVerificationService::isRequired(get_current_user_id());
    }
}
