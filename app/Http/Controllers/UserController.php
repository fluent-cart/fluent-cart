<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\Api\Resource\UserResource;
use FluentCart\App\Http\Requests\UserRequest;
use FluentCart\Framework\Http\Request\Request;

class UserController
{
    public function register(UserRequest $request)
    {
        $data = $request->getSafe($request->sanitize());
        return UserResource::create($data);
    }

    public function login(Request $request)
    {
        if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) {
            wp_send_json([
                'message' => __('Invalid security token. Please refresh the page and try again.', 'fluent-cart'),
                'code' => 'invalid_nonce'
            ], 403);
        }

        $data = $request->only(['user_login', 'password', 'remember_me']);

        return UserResource::login($data);
    }

}
