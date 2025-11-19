<?php

namespace FluentCart\App\Http\Policies;

use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Foundation\Policy;

class FilePolicy extends Policy
{

    public function verifyRequest(Request $request)
    {
        return true;
        return current_user_can('manage_options');
    }


}
