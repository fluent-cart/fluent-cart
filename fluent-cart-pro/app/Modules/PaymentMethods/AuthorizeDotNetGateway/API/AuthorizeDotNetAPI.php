<?php

namespace FluentCartPro\App\Modules\PaymentMethods\AuthorizeDotNetGateway\API;

use FluentCartPro\App\Modules\PaymentMethods\AuthorizeDotNetGateway\AuthorizeDotNetSettings;
use FluentCart\Framework\Support\Arr;

class AuthorizeDotNetAPI
{
    private static $apiUrlLive = 'https://api.authorize.net/xml/v1/request.api';
    private static $apiUrlTest = 'https://apitest.authorize.net/xml/v1/request.api';

    private static $mode = 'current';
    private $settings = null;

    public function __construct()
    {
        $this->settings = new AuthorizeDotNetSettings();
    }


    public static function getAuthorizeNetObject($path, $data = [], $mode = 'current')
    {
        return self::remoteRequest($path, $data, 'GET', $mode);
    }

    public static function createAuthorizeNetObject($path, $data = [], $mode = 'current')
    {
        return self::remoteRequest($path, $data, 'POST', $mode);
    }

    public static function deleteAuthorizeNetObject($path, $data = [], $mode = 'current')
    {
        return self::remoteRequest($path, $data, 'DELETE', $mode);
    }

    public static function remoteRequest($path, $data, $headers = [], $method = 'POST', $mode = 'current')
    {
        $mode = self::resolveMode($mode);
  
        $url = $mode == 'test' ? self::$apiUrlTest : self::$apiUrlLive;

        // Wrap data with path as the request type
        $payload = [
            $path => $data
        ];

        $headers = array(
            'Content-Type' => 'application/json'
        );

        $requestData = array(
            'headers' => $headers,
            'body'    => is_array($payload) ? json_encode($payload) : $payload,
            'method'  => 'POST',
            'timeout' => 45
        );


        $response = wp_remote_request($url, $requestData);


        if (is_wp_error($response)) {
            return $response;
        }

        $responseBody = wp_remote_retrieve_body($response);
        
        // Remove BOM (Byte Order Mark) if present - Authorize.Net sometimes includes this
        $responseBody = trim($responseBody, "\xEF\xBB\xBF");
        
        $responseArray = json_decode($responseBody, true);

        $statusCode = wp_remote_retrieve_response_code($response);

        if ($statusCode >= 300) {
            $message = self::extractErrorMessage($responseArray);
            if (!$message) {
                $message = __('Unknown Authorize.Net API request error', 'fluent-cart-pro');
            }

            return new \WP_Error('api_error', $message, $responseArray);
        }

        if (!self::isSuccessResponse($responseArray)) {
            $message = self::extractErrorMessage($responseArray);
            return new \WP_Error('authnet_error', $message, $responseArray);
        }

        return $responseArray;
    }

    public function validateWebhookSignature($payload, $signature)
    {
        if (!$signature) {
            return false;
        }

        $merchantSignatureKey = (new AuthorizeDotNetSettings())->getWebhookSignatureKey();
        if (!$merchantSignatureKey) {
            return false;
        }

        $generated = hash_hmac('sha512', $payload, $merchantSignatureKey);

        return hash_equals(strtolower($generated), strtolower($signature));
    }


    public function getMerchantAuthentication($mode = 'current')
    {
        return [  
            'name'           => (new AuthorizeDotNetSettings())->getApiLoginId($mode),
            'transactionKey' => (new AuthorizeDotNetSettings())->getTransactionKey($mode),
        ];
    }

    protected static function resolveMode($mode)
    {
        if ($mode === 'current') {
            return (new AuthorizeDotNetSettings())->getMode();
        }
        return $mode;
    }

    public static function isSuccessResponse($response)
    {
        if (is_wp_error($response)) {
            return false;
        }

        $resultCode = Arr::get($response, 'messages.resultCode');
        return strtoupper((string) $resultCode) === 'OK';
    }

    public static function extractErrorMessage($response, $fallback = '')
    {
        if (is_wp_error($response)) {
            return $response->get_error_message();
        }

        $transactionErrors = Arr::get($response, 'transactionResponse.errors', []);
        if ($transactionErrors) {
            $first = Arr::first($transactionErrors);
            return (string) Arr::get($first, 'errorText', $fallback);
        }

        $messages = Arr::get($response, 'messages.message', []);
        if ($messages) {
            $first = Arr::first($messages);
            return (string) Arr::get($first, 'text', $fallback);
        }

        return $fallback ?: __('Payment failed with an unknown error.', 'fluent-cart-pro');
    }
}
