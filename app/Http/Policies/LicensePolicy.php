<?php

namespace FluentCart\App\Http\Policies;

use FluentCart\Framework\Http\Request\Request;

class LicensePolicy extends Policy
{
    public function verifyRequest(Request $request)
    {
        return true;
    }

    public function index(Request $request)
    {
        return $this->userCan('licenses/view');
    }

    public function store(Request $request)
    {
        return $this->userCan('licenses/manage');
    }

    public function update(Request $request)
    {
        return $this->userCan('licenses/manage');
    }

    public function delete(Request $request)
    {
        return $this->userCan('licenses/delete');
    }
}