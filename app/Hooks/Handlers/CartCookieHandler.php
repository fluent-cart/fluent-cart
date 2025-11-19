<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\Api\Hasher\Hash;
use FluentCart\App\App;

class CartCookieHandler
{
    public static function handle()
    {
        $app = App::getInstance();
        //Set up the cookie before any header info is sent to a client
        $app->addAction('init', function () use ($app) {

            if (App::doingRestRequest()) {
                return;
            }

            $cartCookie = \FluentCart\Api\Cookie\Cookie::getCartHash();
            if (!$cartCookie) {
                \FluentCart\Api\Cookie\Cookie::setCartHash(
                    md5(time() . wp_generate_uuid4())
                );
            }
        });


        add_filter('rest_request_before_callbacks', function ($response, $handler, $request) {
            // Only set cookie if not already present
            $cartCookie = \FluentCart\Api\Cookie\Cookie::getCartHash();
            if (!$cartCookie) {
                \FluentCart\Api\Cookie\Cookie::setCartHash(
                    md5(time() . wp_generate_uuid4())
                );
            }
            // Return null to let the normal REST flow continue
            return null;
        }, 10, 3);
    }
}