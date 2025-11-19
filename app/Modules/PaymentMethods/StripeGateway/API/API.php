<?php

namespace FluentCart\App\Modules\PaymentMethods\StripeGateway\API;

use FluentCart\App\Modules\PaymentMethods\StripeGateway\StripeSettingsBase;
use FluentCart\Framework\Support\Arr;

class API
{
    private $createSessionUrl;

    private $apiUrl = 'https://api.stripe.com/v1/';

    public function makeRequest($path, $data = [], $apiKey = null, $method = 'GET')
    {
        if (!$apiKey) {
            $apiKey = $this->getApiKey();
        }

        return $this->remoteRequest($path, $data, $apiKey, $method);
    }

    public function getStripeObject($path, $data = [], $mode = 'current')
    {
        $apiKey = (new StripeSettingsBase())->getApiKey($mode);

        return $this->remoteRequest($path, $data, $apiKey, 'GET');
    }

    public function createStripeObject($path, $data = [], $mode = 'current')
    {
        $apiKey = (new StripeSettingsBase())->getApiKey($mode);
        return $this->remoteRequest($path, $data, $apiKey, 'POST');
    }

    public function deleteStripeObject($path, $data = [], $mode = 'current')
    {
        $apiKey = (new StripeSettingsBase())->getApiKey($mode);
        return $this->remoteRequest($path, $data, $apiKey, 'DELETE');
    }

    public function remoteRequest($path, $data, $apiKey, $method)
    {
        $stripeApiKey = $apiKey;
        $apiVersion = '2025-02-24.acacia';
        $sessionHeaders = array(
            'Authorization'  => 'Bearer ' . $stripeApiKey,
            'Content-Type'   => 'application/x-www-form-urlencoded',
            'Stripe-Version' => $apiVersion
        );

        $url = $this->apiUrl . $path;

        if ($method === 'GET' && is_array($data) && !empty($data)) {
            $url .= '?' . http_build_query($data);
            $requestData = array(
                'headers' => $sessionHeaders,
                'method'  => $method,
            );
        } else {
            $requestData = array(
                'headers' => $sessionHeaders,
                'body'    => is_array($data) ? http_build_query($data) : $data,
                'method'  => $method,
            );
        }

        $sessionResponse = wp_remote_request($url, $requestData);

        if (is_wp_error($sessionResponse)) {
            return $sessionResponse;
        }

        $sessionResponseData = wp_remote_retrieve_body($sessionResponse);
        $responseBodyArray = json_decode($sessionResponseData, true);

        $statusCode = wp_remote_retrieve_response_code($sessionResponse);

        if ($statusCode >= 300) {

            $message = Arr::get($responseBodyArray, 'detail');
            if (!$message) {
                $message = Arr::get($responseBodyArray, 'error.message');
            }
            if (!$message) {
                $message = __('Unknown Stripe API request error', 'fluent-cart');
            }

            return new \WP_Error('api_error', $message, $responseBodyArray);
        }

        return $responseBodyArray;
    }

    public function verifyIPN()
    {
        $post_data = '';
        if (ini_get('allow_url_fopen')) {
            $post_data = file_get_contents('php://input');
        } else {
            // If allow_url_fopen is not enabled, then make sure that post_max_size is large enough
            // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Required for legacy file format support
            ini_set('post_max_size', '12M');
        }

        $data = json_decode($post_data);

        if ($data && $data->id) {
            return $data;
        }

        return new \WP_Error('invalid_data', __('Invalid data received from Stripe', 'fluent-cart'));
    }

    public function getEvent($eventId)
    {
        $api = $this->getApi();
        return $api::request([], 'events/' . $eventId, 'GET');
    }

    public function getApi()
    {
        $api = new ApiRequest();
        $api::set_secret_key((new StripeSettingsBase())->getApiKey());
        return $api;
    }

    public function getApiKey($mode = 'current')
    {
        return (new StripeSettingsBase())->getApiKey($mode);
    }

    public function addWebhookEndpoint()
    {
        // get all the webhook endpoints first

    }

    public function getWebhookEndpoints()
    {
        return $this->getStripeObject('webhooks');
    }

    public function getActivatedPaymentMethodsConfigs($mode = 'live')
    {
        $apiKey = (new StripeSettingsBase())->getApiKey($mode);

        if (!$apiKey) {
            return [];
        }

        $clientId = 'ca_TDs9okG0Jy8gY5GWbwmsDWHmIpOlyIoc';
        if ($mode == 'test') {
            $clientId = 'ca_TDs9NGCHtEcklwK4EFKHe72TAxC2kQap';
        }

        $stripeGateways = (new \FluentCart\App\Modules\PaymentMethods\StripeGateway\API\API)->makeRequest('payment_method_configurations', [
            'application' => $clientId,
        ], $apiKey);

        if (is_wp_error($stripeGateways) || empty($stripeGateways['data'])) {
            return [];
        }

        $gateway = Arr::first($stripeGateways['data']);

        $stripeGateways = [
            'acss_debit'        => 'ACSS Debit',
            'affirm'            => 'Affirm',
            'afterpay_clearpay' => 'Afterpay / Clearpay',
            'alipay'            => 'Alipay',
            'amazon_pay'        => 'Amazon Pay',
            'apple_pay'         => 'Apple Pay',
            'bacs_debit'        => 'BACS Debit',
            'bancontact'        => 'Bancontact',
            'blik'              => 'BLIK',
            'card'              => 'Credit/Debit Card',
            'cartes_bancaires'  => 'Cartes Bancaires',
            'cashapp'           => 'Cash App',
            'crypto'            => 'Cryptocurrency',
            'eps'               => 'EPS',
            'giropay'           => 'Giropay',
            'google_pay'        => 'Google Pay',
            'ideal'             => 'iDEAL',
            'kakao_pay'         => 'Kakao Pay',
            'klarna'            => 'Klarna',
            'kr_card'           => 'Korea Card',
            'link'              => 'Link',
            'mb_way'            => 'MB Way',
            'multibanco'        => 'Multibanco',
            'naver_pay'         => 'Naver Pay',
            'p24'               => 'Przelewy24',
            'payco'             => 'Payco',
            'pix'               => 'Pix',
            'samsung_pay'       => 'Samsung Pay',
            'sepa_debit'        => 'SEPA Debit',
            'sofort'            => 'Sofort',
            'us_bank_account'   => 'US Bank Account',
            'wechat_pay'        => 'WeChat Pay',
            'zip'               => 'Zip'
        ];

        $allMethods = Arr::only($gateway, array_keys($stripeGateways));

        $activatedMethods = array_filter($allMethods, function ($method) {
            return !!Arr::get($method, 'available');
        });

        $stripeGateways = Arr::only($stripeGateways, array_keys($activatedMethods));
        $settings = (new StripeSettingsBase())->settings;
        $accountId = Arr::get($settings, $mode . '_account_id');

        $liveMode = !!Arr::get($gateway, 'livemode');

        return [
            'id'                => Arr::get($gateway, 'id'),
            'activated_methods' => $stripeGateways,
            'configure_url'     => 'https://dashboard.stripe.com/' . $accountId . (!$liveMode ? '/test/' : '/') . 'settings/payment_methods/' . Arr::get($gateway, 'id'),
        ];
    }

}
