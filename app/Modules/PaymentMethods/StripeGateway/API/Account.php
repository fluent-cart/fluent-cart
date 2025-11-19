<?php

namespace FluentCart\App\Modules\PaymentMethods\StripeGateway\API;

class Account
{
    use RequestProcessor;
    public static function retrive($accountId, $key)
    {
        ApiRequest::set_secret_key($key);
        $account = ApiRequest::retrieve('accounts/'.$accountId);
        return self::processResponse($account);
    }
}
