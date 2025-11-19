<?php

namespace FluentCart\App\Modules\PaymentMethods\PayPalGateway;

use FluentCart\App\App;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\API;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\PayPalPartner;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\Webhook;
use FluentCart\App\Vite;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;

class ConnectConfig
{
    public static function getConnectConfig()
    {
        $PaypalSettings = new PayPalSettingsBase();
        $settings = $PaypalSettings->get();

        $testAccountInfo = self::getAccountInfo($settings, 'test');
        $liveAccountInfo = self::getAccountInfo($settings, 'live');

        $testConnectRedirect = admin_url('?fluent-cart=fluent_cart_payment_authenticate&payment_method=paypal&type=connect&mode=test');
        $liveConnectRedirect = admin_url('?fluent-cart=fluent_cart_payment_authenticate&payment_method=paypal&type=connect&mode=live');

        return [
            'connect_config' => [
                'test_redirect'   => $testConnectRedirect,
                'live_redirect'   => $liveConnectRedirect,
                'image_url'       => Vite::getAssetUrl('images/payment-methods/paypal-icon.png'),
                'disconnect_note' => __('Disconnecting your PayPal account will prevent you from offering PayPal services and products on your website. Do you wish to continue?', 'fluent-cart')
            ],
            'test_account'   => $testAccountInfo,
            'live_account'   => $liveAccountInfo,
            'settings'       => $settings
        ];
    }

    public static function parseConnectInfos($vendorData)
    {
        if (!$vendorData || !Arr::get($vendorData, 'permissionsGranted')) {
            echo '<div class="fct_message fct_message_error">' . esc_html(__('Invalid PayPal Request. Please try configuring paypal payment gateway again!', 'fluent-cart')) . '</div>';
            die();
        }

        $settingsInstance = App::gateway('paypal')->settings;

        $mode = Arr::get($vendorData, 'mode');

        /*
        * @todo will verify later
        * we need to verify merchant manually after they create account
        *
        // start verifications if merchant able to receive payments
        $sellerAccessToken = fluent_cart_get_option('_paypal_partner_connect_access_token_' . Arr::get($vendorData, 'mode'));

        $accountData = (new PayPalPartner($mode))->verifyMerchant(Arr::get($sellerAccessToken, 'access_token'), Arr::get($vendorData, 'merchantIdInPayPal'));

        if (is_wp_error($accountData)) {
            echo '<div class="fct_message fct_message_error">' . esc_html($accountData->get_error_message()) . '</div>';
        }

        if (!Arr::get($accountData, 'payments_receivable')) {
            echo '<div class="fct_message fct_message_error">
                    <p style="color: #b94a48; background: #f2dede; padding: 12px 16px; border-radius: 4px; border: 1px solid #ebccd1; margin: 0; max-width: 460px; margin:0 auto; margin-top: 10%;">
                    Attention: You currently cannot receive payments due to restriction on your PayPal account. Please reach out to PayPal Customer Support or connect to <a href="https://www.paypal.com" style="color: #31708f; text-decoration: underline;">https://www.paypal.com</a> for more information.
                    </p>
                </div>';
            die();
        }

        if (!Arr::get($accountData, 'primary_email_confirmed')) {
            echo '<div class="fct_message fct_message_error">
                    <p style="color: #b94a48; background: #f2dede; padding: 12px 16px; border-radius: 4px; border: 1px solid #ebccd1; margin: 0; max-width: 460px; margin:0 auto; margin-top: 10%;line-height: 1.7rem;">
                    Attention: Please confirm your email address on <a href="https://www.paypal.com/businessprofile/settings" style="color: #31708f; text-decoration: underline;">https://www.paypal.com/businessprofile/settings</a> in order to receive payments! You currently cannot receive payments.
                    </p>
                </div>';
            die();
        }

        *
        */

        // update all data that verified
        $data = [
            $mode . '_email_address'  => sanitize_text_field(Arr::get($vendorData, 'merchantId')),
            $mode . '_account_status' => sanitize_text_field(Arr::get($vendorData, 'accountStatus')),
        ];
        $settingsInstance->updateNonSensitiveData($data);

        wp_redirect(admin_url('admin.php?page=fluent-cart#/settings/payments/paypal'));
    }

    public function getSellerAuthToken(Request $request)
    {

        $authCode = $request->getSafe('authCode', 'sanitize_text_field');
        $clientId = $request->getSafe('sharedId', 'sanitize_text_field');
        $mode = $request->getSafe('mode', 'sanitize_text_field');
        $paypalConnect = new PayPalPartner($mode);

        try {

            $response = $paypalConnect->exchangeAuthCode($clientId, $authCode);

            if (isset($response['access_token'])) {
                $endpoint = "/v1/customer/partners/" . $paypalConnect->getPartnerId() . "/merchant-integrations/credentials/";

                // retrieve the merchant client ID and client secret
                $credentials = $paypalConnect->makeMerchantRequest($endpoint, $response['access_token']);

                if (is_wp_error($credentials)) {
                    wp_send_json([
                        'message' => $credentials->get_error_message()
                    ], 422);
                }
                if (is_array($credentials)) {
                    static::prepareSettingToSave($credentials, $mode);
                }
                //update the existing access token for after redirect verification
                fluent_cart_update_option('_paypal_partner_connect_access_token_' . $mode, $response);
            }

            if (isset($response['error'])) {
                throw new \Exception($response['error_description']);
            }
        } catch (\Exception $exception) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new \Exception($exception->getMessage());
        }
    }


    public static function prepareSettingToSave($credentials, $mode = 'live')
    {
        $clientId = Arr::get($credentials, 'client_id');
        $clientSecret = Arr::get($credentials, 'client_secret');

        $data = [
            $mode . '_client_id'     => $clientId,
            $mode . '_client_secret' => $clientSecret,
            $mode . '_account_id'    => Arr::get($credentials, 'payer_id'),
            'provider'               => 'connect',
            'is_active'              => 'yes',
            'payment_mode'           => $mode
        ];

        $payPalInstance = App::gateway('paypal');
        
        $oldSettings = $payPalInstance->settings->get();
        $settingsData = wp_parse_args($data, $oldSettings);
        $payPalInstance->updateSettings($settingsData);

        //create webhook and setup endpoints
        (new Webhook())->registerWebhook($mode);
    }

    public static function verifyAuthorizeSuccess($data)
    {
        $response = wp_remote_post(self::$connectBase . 'paypal-verify-code', [
            'method'      => 'POST',
            'timeout'     => 60,
            'redirection' => 5,
            'httpversion' => '1.0',
            'sslverify'   => false,
            'blocking'    => true,
            'headers'     => array(),
            'body'        => $data,
            'cookies'     => array()
        ]);

        if (is_wp_error($response)) {
            $message = $response->get_error_message();
            echo '<div class="fct_message fct_message_error">' . esc_html($message) . '</div>';
            return;
        }

        $response = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($response['paypal_user_id'])) {
            $message = Arr::get($response, 'message');
            if (!$message) {
                $message = __('Invalid PayPal Request. Please configure paypal payment gateway again', 'fluent-cart');
            }
            echo '<div class="fct_message fct_message_error">' . esc_html($message) . '</div>';
            return;
        }

        $payPalInstance = App::gateway('paypal');
        $settings = $payPalInstance->settings->get();

        $settings['provider'] = 'connect';

        $settings['is_active'] = 'yes';

        if (!empty($response['livemode'])) {
            $settings['payment_mode'] = 'live';
            $settings['live_account_id'] = $response['paypal_user_id'];
            $settings['live_publishable_key'] = $response['paypal_publishable_key'];
            $settings['live_secret_key'] = $response['access_token'];
        } else {
            $settings['payment_mode'] = 'test';
            $settings['test_account_id'] = $response['paypal_user_id'];
            $settings['test_publishable_key'] = $response['paypal_publishable_key'];
            $settings['test_secret_key'] = $response['access_token'];
        }

        $payPalInstance->updateSettings($settings);
    }

    private static function getAccountInfo($settings, $mode)
    {
        if (Arr::get($settings, 'provider') !== 'connect') {
            return false;
        }
        return API::retrieveAccount($settings, $mode);
    }

    public static function disconnect($mode, $sendResponse = true)
    {
        $payPalInstance = App::gateway('paypal');
        $paypalSettings = $payPalInstance->settings->get();

        if (empty($paypalSettings[$mode . '_account_id'])) {
            if ($sendResponse) {
                wp_send_json([
                    'message' => __('Selected Account does not exist', 'fluent-cart')
                ], 422);
            }
            return false;
        }

        $paypalSettings[$mode . '_account_id'] = '';
        $paypalSettings[$mode . '_publishable_key'] = '';
        $paypalSettings[$mode . '_secret_key'] = '';
        $paypalSettings[$mode . '_webhook_events'] = [];
        $paypalSettings[$mode . '_webhook_id'] = '';
        $paypalSettings[$mode . '_client_id'] = '';
        $paypalSettings[$mode . '_client_secret'] = '';

        if ($mode == 'live') {
            $alternateMode = 'test';
        } else {
            $alternateMode = 'live';
        }

        if (empty($paypalSettings[$alternateMode . '_account_id'])) {
            $paypalSettings['is_active'] = 'no';
            $paypalSettings['payment_mode'] = 'test';
        } else {
            $paypalSettings['payment_mode'] = $alternateMode;
        }

        $sendResponse = $payPalInstance->updateSettings($paypalSettings);

        if ($sendResponse) {
            wp_send_json([
                'message'  => __('PayPal settings has been disconnected', 'fluent-cart'),
                'settings' => $paypalSettings
            ], 200);
        }

        return true;
    }
}
