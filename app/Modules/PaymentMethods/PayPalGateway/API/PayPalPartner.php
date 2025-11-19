<?php

namespace FluentCart\App\Modules\PaymentMethods\PayPalGateway\API;

use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\Framework\Support\Arr;
use WP_Error;

class PayPalPartner
{
    private $mode;
    private $apiBase;
    private $accessToken;

    private static $fluentCartAPI = '';
    protected static $testClientId = 'AUaPp5Il9xr2gUhAUtHDcx5qVHIgyyY21Q22XHn_gdKCR-E_S4-r3D4IqZxahb_zpqL46_pT_z2jnHXt';
    protected static $liveClientId = 'AV2vFALQ_Okaw1HbNAWFbEstDofcsTGdetUNjIMniFTB5xRwnc2Kw2PLADEoRn-gZ5jLPnzwEZD6pQM3';

    protected static $testPartnerMerchantId = 'TVZ979WK7888J';

    protected static $partnerMerchantId = '8VQ7BA4DWP6TA'; // LIVE ACCOUNT ID

    public function __construct($mode = 'live')
    {
        static::$fluentCartAPI = App::config()->get('paypal.connect_api');
        $this->mode = $mode;
        $this->apiBase = $mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    public function getPartnerId($mode = ''): string
    {
        if (!$mode) {
            $mode = $this->mode;
        }

        if ($mode === 'test') {
            return static::$testPartnerMerchantId;
        }

        return static::$partnerMerchantId;
    }

    public function getClientId(): string
    {
        if ($this->mode == 'test') {
            return static::$testClientId;
        }
        return static::$liveClientId;
    }

    public function withToken($token)
    {
        $this->accessToken = $token;
        return $this;
    }

    public function get($endpoint, $params = [])
    {
        $url = $this->apiBase . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        return $this->sendRequest('GET', $url);
    }

    public function exchangeAuthCode($sharedId, $authCode)
    {
        $endpoint = '/v1/oauth2/token';
        $url = $this->apiBase . $endpoint;

        $payload = http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $authCode,
            'code_verifier' => $this->nonce()
        ]);
        return $this->sendTokenRequest($url, $payload, $sharedId);
    }

    public function verifyMerchant($sellerAccessToken, $merchantIdInPayPal)
    {
        $endpoint = "/v1/customer/partners/" . $this->getPartnerId() . "/merchant-integrations/" . $merchantIdInPayPal;
        return $this->makeMerchantRequest($endpoint, $sellerAccessToken);
    }

    public function makeMerchantRequest($endpoint, $sellerAccessToken, $method = 'GET')
    {
        $url = $this->apiBase . $endpoint;

        $args = [
            'headers'   => [
                'Content-Type'                  => 'application/json',
                'Authorization'                 => 'Bearer ' . $sellerAccessToken,
                'PayPal-Partner-Attribution-ID' => 'FLUENTCART_SP_PPCP'
            ],
            'method'    => $method
        ];

        $response = wp_remote_get($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = wp_remote_retrieve_body($response);
        $bodyFormatted = json_decode($body, true);

        $code = Arr::get($response, 'response.code');

        if ($code > 299) {
            return new \WP_Error($code, Arr::get($bodyFormatted, 'message'));
        }

        return $bodyFormatted;
    }


    private function sendTokenRequest($url, $payload, $sharedId)
    {
        $args = [
            'method'    => 'POST',
            'body'      => $payload,
            'headers'   => [
                'Content-Type'                  => 'application/x-www-form-urlencoded',
                'PayPal-Partner-Attribution-ID' => 'FLUENTCART_SP_PPCP',
                'Authorization'                 => 'Basic ' . base64_encode($sharedId . ':')
            ]
        ];

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }

    /**
     * @throws \Exception
     */
    public function sellerOnboarding()
    {
        $return_url = admin_url('admin.php?fct_app_authenticator&method=paypal&mode=' . $this->mode);
        $capabilities = [];
        $products = [
            'EXPRESS_CHECKOUT'
        ];

        $store = (new StoreSettings())->get();
        $currency = Arr::get($store, 'currency');
        $country = Arr::get($store, 'store_country');

        if (empty($country) || empty($currency)) {
            return new Wp_Error('store_country_currency_not_set', 'Please set store country and currency first!');
        }

        $inAcdcCountry = (new DccApplies($country, $currency))->forCountryCurrency();


        if ($inAcdcCountry) {
            $products = array('PPCP', 'ADVANCED_VAULTING');
            $capabilities[] = 'PAYPAL_WALLET_VAULTING_ADVANCED';
        }

        $payload = [
            // we will use it to generate the tracking ID
            'tracking_id'             => 'fluent_cart_seller_' . md5(site_url()),
            "operations"              => [
                [
                    "operation"                  => "API_INTEGRATION",
                    "api_integration_preference" => [
                        "rest_api_integration" => [
                            "integration_method"  => "PAYPAL",
                            "integration_type"    => "FIRST_PARTY",
                            "first_party_details" => [
                                'features'     => [
                                    'PAYMENT',
                                    'REFUND',
                                    'ADVANCED_TRANSACTIONS_SEARCH',
                                    'TRACKING_SHIPMENT_READWRITE',
                                    'BILLING_AGREEMENT',
//                                    'FUTURE_PAYMENT',
//                                    'VAULT'
                                ],
                                "seller_nonce" => $this->nonce()
                            ]
                        ]
                    ]
                ]
            ],
            'partner_config_override' => [
                'return_url'             => $return_url,
                'return_url_description' => 'Return to FluentCart',
                'show_add_credit_card'   => true
            ],
            "products"                => $products,
            "legal_consents"          => [
                [
                    "type"    => "SHARE_DATA_CONSENT",
                    "granted" => true
                ]
            ]
        ];

        if (!empty($capabilities)) {
            $payload['capabilities'] = $capabilities;
        }

        //todo: will decide later
        //$response = $this->post('/v2/customer/partner-referrals', $payload);
        $response = $this->getRemoteAuthenticator($payload, $this->mode);

        $openModeParams = '&displayMode=minibrowser';
        if (isset($response['links'])) {
            foreach ($response['links'] as $link) {
                if ('action_url' === $link['rel']) {
                    return (string)$link['href'] . $openModeParams;
                }
            }
        }

        return '';
    }

    public function getRemoteAuthenticator($payload, $mode)
    {
        $url = $this->apiBase;
        $response = wp_remote_post(
            static::$fluentCartAPI . 'client_id=' . $this->getClientId() . '&endpoint=' . $url . '&environment=' . $mode,
            [
                'method'    => 'POST',
                'body'      => json_encode($payload),
                'headers'   => [
                    'Content-Type' => 'application/json' 
                ],
            ]
        );

        if (is_wp_error($response)) {
            throw new \Exception(esc_html($response->get_error_message()), 400);
        }

        $body = wp_remote_retrieve_body($response);
        $response = json_decode($body, true);
        if (isset($response['data'])) {
            return $response['data'];
        }
        return $response;
    }

    public function nonce(): string
    {
        return 'Zmx1ZW50Y2FydHBheXBhbG5vbmNlX2RvbnRkZWNvZGV0aGlz';
    }

    public function getBNCode()
    {
        return 'FLUENTCART_SP_PPCP';
    }

    private function sendRequest($method, $url, $payload = null)
    {
        $headers = [
            'Content-Type'                  => 'application/json',
            'Authorization'                 => 'Bearer ' . $this->accessToken,
            'PayPal-Partner-Attribution-ID' => 'FLUENTCART_SP_PPCP'
        ];

        $args = [
            'method'    => $method,
            'headers'   => $headers
        ];

        if ($payload) {
            $args['body'] = $payload;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
}
