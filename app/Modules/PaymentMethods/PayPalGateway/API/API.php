<?php

namespace FluentCart\App\Modules\PaymentMethods\PayPalGateway\API;

use FluentCart\App\Modules\PaymentMethods\PayPalGateway\PayPalSettingsBase;
use FluentCart\Framework\Support\Arr;

class API
{

    private static $settings = null;
    private const TEST_API_URL = 'https://api-m.sandbox.paypal.com';
    private const LIVE_API_URL = 'https://api.paypal.com';

    private const TEST_VERIFYING_URL = 'https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature';
    private const LIVE_VERIFYING_URL = 'https://api-m.paypal.com/v1/notifications/verify-webhook-signature';

    private static function getPayPalSettings()
    {
        if (!self::$settings) {
            self::$settings = new PayPalSettingsBase();
        }

        return self::$settings;
    }

    public static function getAPIUrl($mode = 'test'): string
    {
        if ($mode === 'test') {
            return self::TEST_API_URL;
        }
        return self::LIVE_API_URL;
    }

    protected static function getAuthAPI($mode = 'test')
    {
        if ($mode === 'live') {
            return self::LIVE_API_URL . '/v1/oauth2/token';
        }

        return self::TEST_API_URL . '/v1/oauth2/token';
    }

    public static function validateCredentials($clientId, $clientSecret, $mode = 'test')
    {
        $result = self::getAccessToken($mode, [
            'public_key' => $clientId,
            'api_key'    => $clientSecret
        ]);

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    /**
     * @param string $path API path ex: checkout/orders (Required)
     * @param string $version API version ex: v1, v2 (Optional)
     * @param string $method HTTP method ex: GET, POST, DELETE (Optional)
     * @param array $args API request arguments (Optional)
     * @return mixed $response API response
     * @throws \Exception if error occurs
     */
    public static function makeRequest($path, $version = 'v1', $method = 'POST', $args = [], $mode = '')
    {
        if (empty($path)) {
            return new \WP_Error('invalid_path', esc_html__('API path is required', 'fluent-cart'));
        }

        $settings = self::getPayPalSettings();

        if (!$mode) {
            $mode = $settings->getMode();
        }

        $paypal_api_url = self::getAPIUrl($mode) . '/' . $version . '/' . $path;

        $accessToken = self::getAccessToken($mode);

        if (is_wp_error($accessToken)) {
            return $accessToken;
        }


        //unset auth asertion headers, if platform app not connected
        if ($settings->getProviderType() === 'api_keys') {
            $headers = array(
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            );
        } else {
            $authAssertion = static::generatePayPalAuthAssertion(
                $settings->getPublicKey($mode),
                static::getAccountId($settings, $mode)
            );

            $headers = array(
                'Authorization'         => 'Bearer ' . $accessToken,
                'PayPal-Partner-Attribution-ID: FLUENTCART_SP_PPCP',
                'Content-Type'          => 'application/json',
                'Accept'                => 'application/json',
                'PayPal-Auth-Assertion' => $authAssertion
            );
        }


        if ('GET' === $method) {
            // if args is not empty then append it to the url
            if (!empty($args)) {
                $paypal_api_url .= '?' . http_build_query($args);
            }

            return self::getRequest($paypal_api_url, $accessToken, $mode);
        }

        if ('POST' === $method) {
            $headers['Prefer'] = 'return=representation';
        }

        $response = wp_remote_post($paypal_api_url, [
            'headers' => $headers,
            'method'  => $method,
            'body'    => json_encode($args)
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error('general_error', $response->get_error_message(), $response);
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($http_code > 299) {
            $code = 'general_error';
            $message = 'PayPal General Error';
            if (isset($body['error'])) {
                $code = $body['error'];
            }

            if ($code === 'invalid_token') {
                fluent_cart_update_option('_paypal_access_token_' . $mode, []);
            }

            if (!empty($body['message'])) {
                $message = $body['message'];
                if (isset($body['details'])) {
                    $message = Arr::get($body, 'details.0.issue', $message);
                }
            }

            return new \WP_Error($code, $message, $body);
        }

        // it's success response with no content
        if ($http_code == 204) {
            return [
                'status' => 'success',
                'body'   => 'No Content',
                'code'   => 204
            ];
        }

        return $body;
    }

    public static function getResource($path, $data = [], $mode = '')
    {
        return self::makeRequest($path, 'v1', 'GET', $data, $mode);
    }

    public static function createResource($path, $data = [], $mode = '')
    {
        return self::makeRequest($path, 'v1', 'POST', $data, $mode);
    }

    public static function retrieveAccount($settings, $mode = '')
    {
        $paypalSettings = self::getPayPalSettings();
        if (empty($mode)) {
            $mode = $paypalSettings->getMode();
        }

        $settings = $paypalSettings->settings;

        $clientId = Arr::get($settings, $mode . '_client_id');
        $secretId = Arr::get($settings, $mode . '_client_secret');
        $merchantId = Arr::get($settings, $mode . '_account_id');
        $email = Arr::get($settings, $mode . '_email_address');
        $accountType = Arr::get($settings, $mode . '_account_status');

        if (!$clientId || !$secretId || !$merchantId) {
            return false;
        }

        return [
            'account_id'   => $merchantId,
            'display_name' => 'Merchant ID: ' . $merchantId,
            'email'        => $email,
            'account_type' => $accountType
        ];
    }

    public static function getRequest($url, $accessToken = null, $mode = '')
    {
        if (!$accessToken) {
            $accessToken = self::getAccessToken($mode);

            if (is_wp_error($accessToken)) {
                return $accessToken;
            }
        }

        $headers = array(
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'PayPal-Partner-Attribution-ID: FLUENTCART_SP_PPCP'
        );

        $response = wp_safe_remote_get($url, [
            'headers' => $headers
        ]);

        if (is_wp_error($response)) {
            return new \WP_Error('general_error', $response->get_error_message(), $response);
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($http_code == 200) {
            return $body;
        }

        // it's success response with no content
        if ($http_code == 204) {
            return [
                'status' => 'success',
                'body'   => 'No Content',
                'code'   => 204
            ];
        }

        if ($http_code > 299) {
            $code = 'general_error';
            if (isset($body['error'])) {
                $code = $body['error'];
            }


            if ($code === 'invalid_token' && $mode) {
                fluent_cart_update_option('_paypal_access_token_' . $mode, []);
            }

            if (!empty($body['message'])) {
                $message = $body['message'];
                if (isset($body['details'])) {
                    $message = Arr::get($body, 'details.0.description');
                }
            }
            return new \WP_Error($code, $message, $body);
        }

        $message = $body['message'] ?? 'PayPal General Error';

        if (isset($body['details'])) {
            $message = $body['details'][0]['issue'];
        }

        return new \WP_Error($http_code, $message, $body);
    }

    public static function verifyPayment($paymentId)
    {
        return self::makeRequest('checkout/orders/' . $paymentId, 'v2', 'GET');
    }

    public function verifySubscription($subscriptionId, $mode = '')
    {
        return self::makeRequest('billing/subscriptions/' . $subscriptionId, 'v1', 'GET', [], $mode);
    }

    /**
     * Retrieves PayPal access token using WP_HTTP.
     *
     * @param string $mode The PayPal mode (live/sandbox).
     * @param array $args Additional arguments including public_key and api_key.
     * @return string|\WP_Error Access token on success, WP_Error on failure.
     */
    private static function getAccessToken($mode = '', $args = [])
    {
        if (!$mode) {
            $mode = (new PayPalSettingsBase())->getMode();
        }

        static $accessToken;

        // Check for cached token
        if (!$args) {
            if ($accessToken) {
                return $accessToken;
            }

            $existingToken = fluent_cart_get_option('_paypal_access_token_' . $mode);
            if ($existingToken && isset($existingToken['expires_at']) && $existingToken['expires_at'] > time()) {
                $accessToken = $existingToken['access_token'];
                return $accessToken;
            }
        }

        $apiUrl = self::getAuthAPI($mode);

        // Prepare headers
        $headers = [
            'Accept'                        => 'application/json',
            'Accept-Language'               => 'en_US',
            'PayPal-Partner-Attribution-ID' => 'FLUENTCART_SP_PPCP'
        ];

        // Prepare body
        $body = [
            'grant_type' => 'client_credentials'
        ];

        // Get credentials
        $publicKey = !empty($args['public_key']) ? $args['public_key'] : self::getPayPalSettings()->getPublicKey($mode);
        $apiKey = !empty($args['api_key']) ? $args['api_key'] : self::getPayPalSettings()->getApiKey($mode);

        // Add Basic Auth header
        $headers['Authorization'] = 'Basic ' . base64_encode($publicKey . ':' . $apiKey);

        // Make HTTP request
        $response = wp_remote_post($apiUrl, [
            'headers' => $headers,
            'body'    => $body,
            'timeout' => 30
        ]);

        // Check for WP_Error
        if (is_wp_error($response)) {
            return $response;
        }

        // Get response code and body
        $http_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($http_code === 200) {
            $response_data = json_decode($response_body, true);
            $accessToken = $response_data['access_token'];

            $data = [
                'access_token' => $accessToken,
                'expires_at'   => time() + (int)$response_data['expires_in'] - 120 // Subtract 2 minutes
            ];

            fluent_cart_update_option('_paypal_access_token_' . $mode, $data);

            return $accessToken;
        }

        $error = json_decode($response_body, true);
        $errorMessage = $error['error_description'] ?? $error['error'] ?? esc_html__('Failed to retrieve access token from PayPal.', 'fluent-cart');

        return new \WP_Error(
            'access_token_error',
            $errorMessage,
            $error
        );
    }

    /**
     * Generate a PayPal-Auth-Assertion JWT header (unsigned, alg=none).
     *
     * @param string $clientId Your platform's REST API client ID
     * @param string $sellerPayerId Seller's PayPal payer_id (preferred) or email
     * @return string               The PayPal‑Auth‑Assertion header value
     */
    private static function generatePayPalAuthAssertion($clientId, $sellerPayerId)
    {
        $header = ['alg' => 'none'];
        $encodedHeader = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
        $payload = [
            'iss'      => $clientId,
            'payer_id' => $sellerPayerId
        ];
        $encodedPayload = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        return "{$encodedHeader}.{$encodedPayload}.";
    }

    private static function getAccountId($settings, $mode)
    {
        return Arr::get($settings->settings, $mode . '_account_id');
    }

    public static function verifyWebhookSignature($body)
    {
        $verify_url = ((new PayPalSettingsBase())->getMode() === 'live')
            ? self::LIVE_VERIFYING_URL
            : self::TEST_VERIFYING_URL;

        $accessToken = self::getAccessToken();

        $args = array(
            'headers'     => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $accessToken,
            ),
            'body'        => json_encode($body),
            'timeout'     => 30,
            'data_format' => 'body'
        );

        $response = wp_remote_post($verify_url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        return $response;
    }
}
