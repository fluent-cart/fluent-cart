<?php

namespace FluentCartPro\App\Modules\PaymentMethods\MollieGateway\API;

use FluentCartPro\App\Modules\PaymentMethods\MollieGateway\MollieSettingsBase;
use FluentCart\Framework\Support\Arr;

class MollieAPI
{
    private $apiUrl = 'https://api.mollie.com/v2/';

    public function makeRequest($path, $data = [], $apiKey = null, $method = 'GET')
    {
        if (!$apiKey) {
            $apiKey = $this->getApiKey();
        }

        return $this->remoteRequest($path, $data, $apiKey, $method);
    }

    public function getMollieObject($path, $data = [], $mode = 'current')
    {
        $apiKey = (new MollieSettingsBase())->getApiKey($mode);

        return $this->remoteRequest($path, $data, $apiKey, 'GET');
    }

    public function createMollieObject($path, $data = [], $mode = 'current')
    {
        $apiKey = (new MollieSettingsBase())->getApiKey($mode);
        return $this->remoteRequest($path, $data, $apiKey, 'POST');
    }

    public function deleteMollieObject($path, $data = [], $mode = 'current')
    {
        $apiKey = (new MollieSettingsBase())->getApiKey($mode);
        return $this->remoteRequest($path, $data, $apiKey, 'DELETE');
    }

    public function remoteRequest($path, $data, $apiKey, $method)
    {
        $headers = array(
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type'  => 'application/json'
        );

        $url = $this->apiUrl . $path;

        if ($method === 'GET') {
            if (!empty($data) && is_array($data)) {
                $url .= '?' . http_build_query($data);
            }
       
            $requestData = array(
                'headers' => $headers,
                'method'  => $method,
                'timeout' => 30
            );
        } else if ($method === 'DELETE') {
            $requestData = array(
                'headers' => $headers,
                'method'  => $method,
                'timeout' => 30
            );
        }else {
            $requestData = array(
                'headers' => $headers,
                'body'    => is_array($data) ? json_encode( $data) : $data,
                'method'  => $method,
                'timeout' => 30
            );
        }

        $response = wp_remote_request($url, $requestData);

        if (is_wp_error($response)) {
            return $response;
        }

        $responseBody = wp_remote_retrieve_body($response);
        $responseArray = json_decode($responseBody, true);

        $statusCode = wp_remote_retrieve_response_code($response);

        if ($statusCode >= 300) {
            $message = Arr::get($responseArray, 'detail');
            if (!$message) {
                $message = Arr::get($responseArray, 'title');
            }
            if (!$message) {
                $message = __('Unknown Mollie API request error', 'fluent-cart');
            }

            return new \WP_Error('api_error', $message, $responseArray);
        }

        return $responseArray;
    }

    public function verifyIPN()
    {
        $post_data = '';
        if (ini_get('allow_url_fopen')) {
            $post_data = file_get_contents('php://input');
        } else {
            ini_set('post_max_size', '12M');
        }

        $data = json_decode($post_data);

        if ($data && isset($data->id)) {
            return $data;
        }

        parse_str($post_data, $parsed);
        if (isset($parsed['id']) && $parsed['id']) {
            // Return as object for consistency
            return (object)[
                'id' => $parsed['id']
            ];
        }

        return new \WP_Error('invalid_data', __('Invalid data received from Mollie', 'fluent-cart'));
    }

    public function getApiKey($mode = 'current')
    {
        return (new MollieSettingsBase())->getApiKey($mode);
    }

    // Note: Mollie doesn't have native subscriptions API
    // Subscriptions are handled through regular payments with metadata

    public static function testConnection($apiKey)
    {
        $response = wp_remote_get('https://api.mollie.com/v2/methods', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey
            ],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        
        if ($statusCode !== 200) {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            $error = Arr::get($data, 'detail', __('Invalid API credentials', 'fluent-cart'));
            throw new \Exception($error);
        }

        return true;
    }

    public function getActivatedPaymentMethodsConfigs($mode = 'live')
    {
        $apiKey = (new MollieSettingsBase())->getApiKey($mode);
        if (!$apiKey) {
            return [];
        }

        $response = $this->makeRequest('methods', [], $apiKey);
        if (is_wp_error($response)) {
            return [];
        }

       $methods = isset($response['_embedded']['methods']) && is_array($response['_embedded']['methods'])
            ? $response['_embedded']['methods']
            : [];

        if (empty($methods)) {
            return [];
        }

        $activatedMethods = array_map(function ($method) {
            return [
                'description' => Arr::get($method, 'description'),
                'image'       => Arr::get($method, 'image.svg')
            ];
        }, $methods);


        $activatedMethods = array_filter($methods, function ($method) {
            return Arr::get($method, 'status') == 'activated';
        });
        $activatedMethods = array_map(function ($method) {
            return [
                'name' => Arr::get($method, 'description'),
                'image' => Arr::get($method, 'image.svg')
            ];
        }, $activatedMethods);

        return [
            'activated_methods' => $activatedMethods,
            'configure_url'     => 'https://www.mollie.com/dashboard/settings/payment-methods'
        ];
    }
}
