<?php

namespace FluentCart\App\Modules\PaymentMethods\StripeGateway\Connect;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\API\Account;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\Stripe;
use FluentCart\App\Modules\PaymentMethods\StripeGateway\StripeSettingsBase;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class ConnectConfig
{
    private static $connectBase = 'https://api.fluentcart.com/connect/';

    public static function handleConnect($data)
    {
        // Permission check - only administrators can perform Stripe connect
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to perform this action.', 'fluent-cart'), __('Security Check Failed', 'fluent-cart'), ['response' => 403]);
        }

        $intent = Arr::get($data, 'intent', '');

        // Nonce verification for connect intent (initiated from admin)
        if ($intent == 'connect') {
            if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), 'fluentcart')) {
                wp_die(__('Security check failed. Please try again.', 'fluent-cart'), __('Security Check Failed', 'fluent-cart'), ['response' => 403]);
            }
        }

        $stripeSettings = new StripeSettingsBase();

        $mode = Arr::get($data, 'mode', '') === 'test' ? 'test' : 'live';

        if (!$intent) {
            wp_redirect(admin_url('admin.php?page=fluent-cart#/settings/payments/stripe'));
            exit;
        }

        if ($intent == 'connect') {
            if ($stripeSettings->getApiKey($mode)) {
                // already connected
                wp_redirect(admin_url('admin.php?page=fluent-cart#/settings/payments/stripe'));
                exit;
            }

            $connectHash = md5('stripe_connect_' . $mode . '_' . wp_generate_uuid4());
            $prevSettings = $stripeSettings->get();
            $prevSettings[$mode . '_connect_hash'] = $connectHash;
            $stripeSettings->updateSettings($prevSettings);

            $redirectUrl = add_query_arg([
                'mode'     => $mode,
                'hash'     => $connectHash,
                'url_base' => home_url('?fluent-cart=stripe_connect')
            ], self::$connectBase);
            wp_redirect($redirectUrl);
            exit;
        }
        if ($intent == 'success') {
            $apiUrl = self::$connectBase . 'stripe_tokens';

            $paypload = [
                'hash'        => $stripeSettings->get($mode . '_connect_hash'),
                'state'       => Arr::get($data, 'state', ''),
                'mode'        => Arr::get($data, 'mode', '') === 'live' ? 'live' : 'test',
                'set_webhook' => home_url('?fluent-cart=fct_payment_listener_ipn&method=stripe'),
                'code'        => Arr::get($data, 'code', '')
            ];

            $response = wp_remote_request($apiUrl, [
                'method'      => 'POST',
                'timeout'     => 45,
                'redirection' => 5,
                'sslverify'   => false,
                'blocking'    => true,
                'body'        => $paypload,
                'cookies'     => []
            ]);

            if (!is_wp_error($response)) {
                $responseBody = json_decode(wp_remote_retrieve_body($response), true);
                $tokens = Arr::get($responseBody, 'tokens', []);
                $prevSettings = $stripeSettings->get();
                if ($tokens) {
                    $updateData = [
                        'is_active'                => 'yes',
                        $mode . '_account_id'      => Arr::get($tokens, 'stripe_user_id', ''),
                        $mode . '_publishable_key' => Arr::get($tokens, 'stripe_publishable_key', ''),
                        $mode . '_secret_key'      => Helper::encryptKey(Arr::get($tokens, 'access_token', '')),
                        $mode . '_is_encrypted' => 'yes'
                    ];
                    $prevSettings = wp_parse_args($updateData, $prevSettings);
                    $stripeSettings->updateSettings($prevSettings);
                }
            }
        }

        wp_redirect(admin_url('admin.php?page=fluent-cart#/settings/payments/stripe'));
    }

    public static function getConnectConfig(): array
    {
        $settings = (new StripeSettingsBase())->get();

        // Generate nonces for secure connect URLs
        $liveNonce = wp_create_nonce('fluentcart');
        $testNonce = wp_create_nonce('fluentcart');

        return [
            'connect_config' => [
                'live_redirect' => add_query_arg([
                    'fluent-cart' => 'stripe_connect',
                    'intent'      => 'connect',
                    'mode'        => 'live',
                    '_wpnonce'    => $liveNonce
                ], home_url()),
                'test_redirect' => add_query_arg([
                    'fluent-cart' => 'stripe_connect',
                    'intent'      => 'connect',
                    'mode'        => 'test',
                    '_wpnonce'    => $testNonce
                ], home_url()),
                'image_url'     => Vite::getAssetUrl('images/payment-methods/stripe-icon.png'),
            ],
            'test_account'   => self::getAccountInfo($settings, 'test'),
            'live_account'   => self::getAccountInfo($settings, 'live'),
            'settings'       => $settings
        ];
    }

    public static function verifyAuthorizeSuccess($data)
    {
        $response = wp_remote_post(self::$connectBase . 'stripe-verify-code', [
            'method'      => 'POST',
            'timeout'     => 45,
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

        if (empty($response['stripe_user_id'])) {
            $message = Arr::get($response, 'message');
            if (!$message) {
                $message = __('Invalid Stripe Request. Please configure stripe payment gateway again', 'fluent-cart');
            }
            echo '<div class="fct_message fct_message_error">' . esc_html($message) . '</div>';
            return;
        }

        $settings = (new StripeSettingsBase())->get();

        $settings['provider'] = 'connect';

        $settings['is_active'] = 'yes';

        if (!empty($response['livemode'])) {
            $settings['payment_mode'] = 'live';
            $settings['live_account_id'] = $response['stripe_user_id'];
            $settings['live_publishable_key'] = $response['stripe_publishable_key'];
            $settings['live_secret_key'] = $response['access_token'];
        } else {
            $settings['payment_mode'] = 'test';
            $settings['test_account_id'] = $response['stripe_user_id'];
            $settings['test_publishable_key'] = $response['stripe_publishable_key'];
            $settings['test_secret_key'] = $response['access_token'];
        }

        (new Stripe())->updateSettings($settings);
    }

    private static function getAccountInfo($settings, $mode)
    {

        // if ($settings['is_active'] != 'yes') {
        //     return false;
        // }

        if ($settings['provider'] != 'connect') {
            return false;
        }

        $apiKey = Helper::decryptKey($settings[$mode . '_secret_key']);

        $accountId = Arr::get($settings, $mode . '_account_id');

        if (!$accountId) {
            return false;
        }

        $account = Account::retrive($accountId, $apiKey);

        if (is_wp_error($account)) {
            return [
                'error' => $account->get_error_message()
            ];
        }

        // Find the email.
        $email = isset($account->email)
            ? esc_html($account->email)
            : '';

        // Find a Display Name.
        $display_name = isset($account->display_name)
            ? esc_html($account->display_name)
            : '';

        if (
            empty($display_name) &&
            isset($account->settings) &&
            isset($account->settings->dashboard) &&
            isset($account->settings->dashboard->display_name)
        ) {
            $display_name = esc_html($account->settings->dashboard->display_name);
        }

        return [
            'account_id'   => $accountId,
            'display_name' => $display_name,
            'email'        => $email
        ];

    }

    public static function disconnect($mode, $sendResponse = true)
    {
        $stripeSettings = (new StripeSettingsBase())->get();

        if (empty($stripeSettings[$mode . '_account_id'])) {
            if ($sendResponse) {
                wp_send_json([
                    'message' => __('Selected Account does not exist', 'fluent-cart')
                ], 422);
            }
            return false;
        }

        $stripeSettings[$mode . '_account_id'] = '';
        $stripeSettings[$mode . '_publishable_key'] = '';
        $stripeSettings[$mode . '_secret_key'] = '';

        if ($mode == 'live') {
            $alternateMode = 'test';
        } else {
            $alternateMode = 'live';
        }

        if (empty($stripeSettings[$alternateMode . '_account_id'])) {
            $stripeSettings['is_active'] = 'no';
            $stripeSettings['payment_mode'] = 'test';
        } else {
            $stripeSettings['payment_mode'] = $alternateMode;
        }

        $stripe = GatewayManager::getInstance('stripe');
        $sendResponse = $stripe->updateSettings($stripeSettings);

        if ($sendResponse) {
            wp_send_json([
                'message'  => __('Stripe settings has been disconnected', 'fluent-cart'),
                'settings' => $stripeSettings
            ], 200);
        }

        return true;
    }
}
