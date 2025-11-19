<?php

namespace FluentCart\Framework\Foundation;

use FluentCart\Framework\Http\Request\Request;

abstract class Policy
{
    /**
     * Fallback method even if verifyRequest is not implemented.
     * @return bool true
     */
    public function verifyRequest(Request $request)
    {
        return true;
    }
}
