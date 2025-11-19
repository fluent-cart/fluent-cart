<?php

namespace FluentCart\App\Http\Policies;

use FluentCart\Framework\Http\Request\Request;
class StorePolicy extends Policy
{
    public function verifyRequest(Request $request)
    {
        return true;
    }

    public function settings(Request $request)
    {
        return $this->userCan('store/settings');
    }

    public function sensitive(Request $request)
    {
        return $this->userCan('store/sensitive');
    }
}