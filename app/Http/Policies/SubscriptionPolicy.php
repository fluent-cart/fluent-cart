<?php

namespace FluentCart\App\Http\Policies;

use FluentCart\Framework\Http\Request\Request;

class SubscriptionPolicy extends Policy
{
    public function verifyRequest(Request $request)
    {
        return true;
    }

    public function index(Request $request)
    {
        return $this->userCan('subscriptions/view');
    }

    public function store(Request $request)
    {
        return $this->userCan('subscriptions/manage');
    }

    public function update(Request $request)
    {
        return $this->userCan('subscriptions/manage');
    }

    public function delete(Request $request)
    {
        return $this->userCan('subscriptions/delete');
    }
}