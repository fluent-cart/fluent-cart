<?php

namespace FluentCart\App\Modules\PaymentMethods\PayPalGateway;

use FluentCart\App\App;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\API;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\PayPalPartner;
use FluentCart\App\Modules\PaymentMethods\PayPalGateway\API\PayPalPartnerRenderer;
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

        $testConnectRedirect = add_query_arg([
            'fluent-cart' => 'paypal_connect',
            'intent'      => 'connect',
            'mode'        => 'test',
            '_wpnonce'    => wp_create_nonce('fluent_cart_paypal_connect_test'),
        ], home_url());
        $liveConnectRedirect = add_query_arg([
            'fluent-cart' => 'paypal_connect',
            'intent'      => 'connect',
            'mode'        => 'live',
            '_wpnonce'    => wp_create_nonce('fluent_cart_paypal_connect_live'),
        ], home_url());

        return [
            'connect_config' => [
                'test_redirect'   => $testConnectRedirect,
                'live_redirect'   => $liveConnectRedirect,
                'image_url'       => Vite::getAssetUrl('images/payment-methods/paypal-icon.svg'),
                'disconnect_note' => __('Disconnecting your PayPal account will prevent you from offering PayPal services and products on your website. Do you wish to continue?', 'fluent-cart')
            ],
            'test_account'   => $testAccountInfo,
            'live_account'   => $liveAccountInfo,
            'settings'       => $settings
        ];
    }

    public static function handleConnect($data): void
    {
        $intent = Arr::get($data, 'intent');
        if ($intent === 'return') {
            self::parseConnectInfos($data);
            return;
        }

        if ($intent !== 'connect') {
            wp_die(esc_html__('Invalid PayPal connection request.', 'fluent-cart'), '', ['response' => 400]);
        }

        $mode = self::validateConnectRequest($data, 'connect');
        // WebRoutes terminates the request after dispatching this action.
        (new PayPalPartnerRenderer($mode))->template($data);
    }

    public static function parseConnectInfos($vendorData)
    {
        $mode = self::validateConnectRequest($vendorData, 'return');

        if (!$vendorData || !Arr::get($vendorData, 'permissionsGranted')) {
            echo '<div class="fct_message fct_message_error">' . esc_html(__('Invalid PayPal Request. Please try configuring paypal payment gateway again!', 'fluent-cart')) . '</div>';
            die();
        }

        $settingsInstance = App::gateway('paypal')->settings;

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

        // Store metadata only after authorizing the connection return.
        $data = [
            $mode . '_email_address'  => sanitize_text_field(Arr::get($vendorData, 'merchantId')),
            $mode . '_account_status' => sanitize_text_field(Arr::get($vendorData, 'accountStatus')),
        ];
        $settingsInstance->updateNonSensitiveData($data);

        wp_redirect(admin_url('admin.php?page=fluent-cart#/settings/payments/paypal'));
    }

    public static function validateConnectRequest($data, string $intent): string
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to configure PayPal.', 'fluent-cart'), '', ['response' => 403]);
        }

        $mode = Arr::get($data, 'mode');
        if (!in_array($mode, ['live', 'test'], true)) {
            wp_die(esc_html__('Invalid PayPal payment mode.', 'fluent-cart'), '', ['response' => 400]);
        }

        $nonce = Arr::get($data, '_wpnonce');
        $action = $intent === 'return' ? 'fluent_cart_paypal_connect_return_' : 'fluent_cart_paypal_connect_';
        if (!is_string($nonce) || !wp_verify_nonce(sanitize_text_field($nonce), $action . $mode)) {
            wp_die(esc_html__('Security check failed. Please try connecting PayPal again.', 'fluent-cart'), '', ['response' => 403]);
        }

        return $mode;
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
