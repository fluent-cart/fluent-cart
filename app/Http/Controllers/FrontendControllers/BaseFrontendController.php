<?php

namespace FluentCart\App\Http\Controllers\FrontendControllers;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\App\Http\Controllers\Controller;

class BaseFrontendController extends Controller
{
    /**
     * Check if the user is logged in.
     *
     * @return \WP_REST_Response|null Returns an error response if the user is not logged in; otherwise, returns null.
     */
    protected function checkUserLoggedIn(): ?\WP_REST_Response
    {
        $customer = CustomerResource::getCurrentCustomer();
        // Check if the user is logged in, return an error if not
        if (empty($customer)) {
            return $this->sendError([
                'message' => __('You are not logged in', 'fluent-cart')
            ]);
        }

        // User is logged in return null
        return null;
    }
}