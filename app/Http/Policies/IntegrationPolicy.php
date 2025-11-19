<?php

namespace FluentCart\App\Http\Policies;

use FluentCart\Framework\Http\Request\Request;

class IntegrationPolicy extends Policy
{
    public function verifyRequest(Request $request)
    {
        return $this->hasRoutePermissions();
    }

    public function index(Request $request)
    {
        return $this->userCan('integrations/view');
    }

    public function store(Request $request)
    {
        return $this->userCan('integrations/manage');
    }

    public function update(Request $request)
    {
        return $this->userCan('integrations/manage');
    }

    public function delete(Request $request)
    {
        return $this->userCan('integrations/delete');
    }
}