<?php

namespace FluentCartPro\App\Modules\PaymentMethods\PaddleGateway\API;

use FluentCartPro\App\Modules\PaymentMethods\PaddleGateway\PaddleSettings;
use FluentCart\Framework\Support\Arr;

class API
{
    private static $settings = null;

    public static function getSettings(): PaddleSettings
    {
        if (!self::$settings) {
            self::$settings = new PaddleSettings();
        }
        return self::$settings;
    }

    /**
     * Make API request to Paddle
     *
     * @param string $endpoint
     * @param array $data
     * @param string $method
     * @param string $mode
     * @return array|\WP_Error
     */
    public static function makeRequest($endpoint, $data = [], $method = 'POST', $mode = '')
    {
        $settings = self::getSettings();
        
        if (!$mode) {
            $mode = $settings->getMode();
        }

        $apiKey = $settings->getApiKey($mode);
        if (empty($apiKey)) {
            return new \WP_Error(
                'paddle_api_key_missing',
                sprintf(__('Paddle API key is missing for mode: %s', 'fluent-cart-pro'), $mode)
            );
        }

        $baseUrl = $settings->getApiBaseUrl($mode);
        $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');

        $headers = [
            'Authorization' => 'Bearer ' . $apiKey,
            'Paddle-Version' => 1,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        $args = [
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30,
        ];

        if (!empty($data) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $args['body'] = json_encode($data);
        } elseif (!empty($data) && $method === 'GET') {
            $url = add_query_arg($data, $url);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $responseCode = wp_remote_retrieve_response_code($response);
        $responseBody = wp_remote_retrieve_body($response);
        $decodedBody = json_decode($responseBody, true);

        if ($responseCode >= 400) {
            $errorMessage = self::parseErrorMessage($decodedBody);
            return new \WP_Error(
                'paddle_api_error',
                $errorMessage,
                ['response_code' => $responseCode, 'response_body' => $decodedBody]
            );
        }

        return $decodedBody;
    }


    /**
     * Create any Paddle object
     *
     * @param string $endpoint - e.g., 'customers', 'products', 'prices', 'transactions'
     * @param array $data - The data to create the object with
     * @param string $mode - API mode (sandbox/live)
     * @return array|\WP_Error
     *
     * Examples:
     * API::createPaddleObject('customers', ['email' => 'user@example.com'])
     * API::createPaddleObject('products', ['name' => 'My Product'])
     * API::createPaddleObject('subscriptions', $subscriptionData)
     */
    public static function createPaddleObject($endpoint, $data = [], $mode = '')
    {
        return self::makeRequest($endpoint, $data, 'POST', $mode);
    }

    /**
     * Get any Paddle object
     *
     * @param string $endpoint - e.g., 'customers/cus_123', 'products/prod_456', 'transactions/txn_789'
     * @param array $params - Query parameters for the request
     * @param string $mode - API mode (sandbox/live)
     * @return array|\WP_Error
     *
     * Examples:
     * API::getPaddleObject('customers/cus_123')
     * API::getPaddleObject('products', ['status' => 'active']) // List products
     * API::getPaddleObject('transactions/txn_456')
     */
    public static function getPaddleObject($endpoint, $params = [], $mode = '')
    {
        return self::makeRequest($endpoint, $params, 'GET', $mode);
    }

    /**
     * Update any Paddle object
     *
     * @param string $endpoint - e.g., 'customers/cus_123', 'products/prod_456', 'subscriptions/sub_789'
     * @param array $data - The data to update the object with
     * @param string $mode - API mode (sandbox/live)
     * @return array|\WP_Error
     *
     * Examples:
     * API::updatePaddleObject('customers/cus_123', ['name' => 'John Doe'])
     * API::updatePaddleObject('products/prod_456', ['name' => 'Updated Product'])
     * API::updatePaddleObject('subscriptions/sub_789', $updateData)
     */
    public static function updatePaddleObject($endpoint, $data = [], $mode = '')
    {
        return self::makeRequest($endpoint, $data, 'PATCH', $mode);
    }

    /**
     * Perform actions on Paddle objects (cancel, pause, resume, refund, etc.)
     *
     * @param string $endpoint - e.g., 'subscriptions/sub_123/cancel', 'subscriptions/sub_456/pause', 'transactions/txn_789/refund'
     * @param array $data - Data for the action
     * @param string $mode - API mode (sandbox/live)
     * @return array|\WP_Error
     *
     * Examples:
     * API::actionPaddleObject('subscriptions/sub_123/cancel', ['effective_from' => 'next_billing_period'])
     * API::actionPaddleObject('subscriptions/sub_456/pause', ['resume_at' => '2024-01-01'])
     * API::actionPaddleObject('transactions/txn_789/refund', ['amount' => 1000])
     */
    public static function actionPaddleObject($endpoint, $data = [], $mode = '')
    {
        return self::makeRequest($endpoint, $data, 'POST', $mode);
    }

    /**
     * Verify webhook signature
     */
    public static function verifyWebhookSignature($payload, $signature, $mode = '')
    {
        $settings = self::getSettings();
        $webhookSecret = $settings->getWebhookSecret($mode);

        if (empty($webhookSecret)) {
            return new \WP_Error(
                'paddle_webhook_secret_missing',
                __('Paddle webhook secret is missing', 'fluent-cart-pro')
            );
        }

        // Paddle uses HMAC-SHA256 for webhook signature verification
        $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);
        
        if (!hash_equals($expectedSignature, $signature)) {
            return new \WP_Error(
                'paddle_webhook_signature_invalid',
                __('Invalid webhook signature', 'fluent-cart-pro')
            );
        }

        return true;
    }

    /**
     * Parse error message from Paddle API response
     */
    private static function parseErrorMessage($responseBody)
    {
        if (isset($responseBody['error']['message'])) {
            return $responseBody['error']['message'];
        }

        if (isset($responseBody['error']['detail'])) {
            return $responseBody['error']['detail'];
        }

        if (isset($responseBody['errors']) && is_array($responseBody['errors'])) {
            $errors = [];
            foreach ($responseBody['errors'] as $error) {
                if (isset($error['detail'])) {
                    $errors[] = $error['detail'];
                } elseif (isset($error['message'])) {
                    $errors[] = $error['message'];
                }
            }
            return implode(', ', $errors);
        }

        return __('Unknown Paddle API error', 'fluent-cart-pro');
    }

    /**
     * Get API key
     */
    public static function getApiKey($mode = '')
    {
        return self::getSettings()->getApiKey($mode);
    }

    /**
     * Verify webhook signature
     */
    public static function verifyWebhook($payload = null, $signature = null, $mode = '')
    {
        if ($payload === null) {
            $payload = file_get_contents('php://input');
        }

        if ($signature === null) {
            $signature = $_SERVER['HTTP_PADDLE_SIGNATURE'] ?? '';
        }

        return self::verifyWebhookSignature($payload, $signature, $mode);
    }
}
