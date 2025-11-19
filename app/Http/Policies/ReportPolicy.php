<?php

namespace FluentCart\App\Http\Policies;

use FluentCart\Framework\Http\Request\Request;
class ReportPolicy extends Policy
{
    public function verifyRequest(Request $request): bool
    {
        return $this->userCan('reports/view');
    }
}