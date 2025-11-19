<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\User;
use FluentCart\App\Services\RoleManager;
use FluentCart\Framework\Http\Controller as BaseController;

abstract class Controller extends BaseController
{
    /**
     *
     * @param string $permission
     * @return false|string|void
     */
    public function redirectOnUnauthorized(string $permission)
    {
        return true;
    }

    public function getUser()
    {
        return Helper::getCurrentUser();
    }

    public function entityNotFoundError($message, $buttonText = null, $route = '/'): \WP_REST_Response
    {
        return $this->sendError([
            'data' => [
                'message'    => $message,
                'buttonText' => $buttonText ?? __('Back to Dashboard', 'fluent-cart'),
                'route'      => $route,
            ],
            'code' => 'fluent_cart_entity_not_found',
        ], 404);
    }

}
