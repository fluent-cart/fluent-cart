<?php

namespace FluentCart\App\Modules\PaymentMethods\PayPalGateway\API;

use FluentCart\App\Modules\PaymentMethods\PayPalGateway\PayPalSettingsBase;
use FluentCart\Framework\Support\Arr;

class Webhook
{
    const EVENTS = [
        ['name' => 'PAYMENT.SALE.COMPLETED'],
        ['name' => 'PAYMENT.SALE.REFUNDED'], // recurring payment refund
        ['name' => 'PAYMENT.CAPTURE.REFUNDED'], // one time payment refund
        ['name' => 'PAYMENT.CAPTURE.COMPLETED'],
        ['name' => 'BILLING.SUBSCRIPTION.CREATED'],
        ['name' => 'BILLING.SUBSCRIPTION.ACTIVATED'],
        ['name' => 'BILLING.SUBSCRIPTION.SUSPENDED'],
        ['name' => 'BILLING.SUBSCRIPTION.CANCELLED'],
        ['name' => 'BILLING.SUBSCRIPTION.EXPIRED'],
        ['name' => 'CUSTOMER.DISPUTE.CREATED'],
        ['name' => 'CUSTOMER.DISPUTE.UPDATED'],
        ['name' => 'CUSTOMER.DISPUTE.RESOLVED'],

        // Extra for testing
        ['name' => 'CHECKOUT.CHECKOUT.BUYER-APPROVED'],
        ['name' => 'INVOICING.INVOICE.PAID'],
        ['name' => 'CHECKOUT.ORDER.COMPLETED'],
        ['name' => 'CHECKOUT.ORDER.PROCESSED'],
        ['name' => 'CHECKOUT.ORDER.APPROVED'],
        ['name' => 'PAYMENT.ORDER.CREATED']
    ];

    const WEBHOOK_ENDPOINT = '?fluent-cart=fct_payment_listener_ipn&method=paypal';

    public static function getWebhookURL(): string
    {
        // return 'https://webhook.site/9f647f0d-3514-47b7-acca-95a2c168b917';
        return site_url() . self::WEBHOOK_ENDPOINT;
    }

    public static function webhookInstruction(): string
    {
        $webhook_url = static::getWebhookURL();
        
        $events = sprintf(
            '<b>%1$s</b>
            %2$s
            <b>%3$s</b>
            %4$s',
            __('Payments and Payout:', 'fluent-cart'),
            __('- Payment capture refunded | Payment sale completed | Payment sale refunded', 'fluent-cart'),
            __('Billing Subscriptions:', 'fluent-cart'),
            __('- Billing subscription activated | Billing subscription cancelled | Billing subscription created | Billing subscription expired | Billing subscription suspended', 'fluent-cart')
        );

        return sprintf(
            '<div>
                <br/>
                <h4>%1$s</h4>
                <p>%2$s</p>
                <br/>
                <p>%3$s</p>
                <p>%4$s <a href="https://developer.paypal.com/dashboard/applications/production" target="_blank">%5$s</a> > %6$s</p>
                <p>%7$s <code class="copyable-content">%8$s</code></p>
                <b>%9$s</b>
                %10$s
                <br/>
            </div>
            <br/>',
            __('How to configure webhook?', 'fluent-cart'),                    // %1$s
            __('You should configure your PayPal webhooks manually if you connected manually. Follow the instruction to Create webhook and paste the webhook ID below.', 'fluent-cart'), // %2$s
            __('In your PayPal account:', 'fluent-cart'),                     // %3$s
            __('Go to', 'fluent-cart'),                                       // %4$s
            __('PayPal developers', 'fluent-cart'),                           // %5$s
            __('App & Credentials > Your App > Webhooks section > Add webhook', 'fluent-cart'), // %6$s
            __('Enter The Webhook URL:', 'fluent-cart'),                      // %7$s
            $webhook_url,                                                     // %8$s
            __('Select these events', 'fluent-cart'),                         // %9$s
            $events                                                           // %10$s
        );
    }

    public function registerWebhook($mode, $url = '')
    {
        $webhookData = API::makeRequest('notifications/webhooks', 'v1', 'POST',
            [
                'url'         => $url ? $url : static::getWebhookURL(),
                'event_types' => static::EVENTS
            ], $mode);

        if (is_wp_error($webhookData)) {
            // if ERROR: WEBHOOK_URL_ALREADY_EXISTS, then manage
            return $this->maybeWebhookExists($webhookData, $mode);
        }

        static::parseAndUpdateSettings($webhookData, $mode);
        return $webhookData;
    }

    public static function parseAndUpdateSettings($webhookData, $mode)
    {
        $data = [
            $mode . '_webhook_id'     => Arr::get($webhookData, 'id'),
            $mode . '_webhook_events' => Arr::get($webhookData, 'event_types')
        ];
        (new PayPalSettingsBase())->updateNonSensitiveData($data);
    }

    public function maybeWebhookExists($webhookData, $mode)
    {
        $webhookURL = static::getWebhookURL();
        if (Arr::get($webhookData->get_error_data(), 'name') === 'WEBHOOK_URL_ALREADY_EXISTS') {
            $webhookData = API::makeRequest('notifications/webhooks', 'v1', 'GET', []);
            if (is_wp_error($webhookData)) {
                return $webhookData;
            }

            $webhooks = Arr::get($webhookData, 'webhooks');
            $matched = array_filter($webhooks, function ($webhook) use ($webhookURL) {
                return $webhook['url'] === $webhookURL;
            });

            if (isset($matched[0])) {
                static::parseAndUpdateSettings($matched[0], $mode);
            }
        }
        return $webhookData;
    }

    public function maybeSetWebhook($mode)
    {
        $payPalSettings = new PayPalSettingsBase();
        if (!$payPalSettings->getApiKey($mode)) {
            return [
                'status'  => 'false',
                'message' => __('No API key found for webhook setup. Please connect your PayPal account first.', 'fluent-cart')
            ];
        }

        $webhookId = $payPalSettings->get($mode . '_webhook_id');

        if ($webhookId) {
            $webhookData = API::makeRequest('notifications/webhooks/' . $webhookId, 'v1', 'GET', []);
            if (!is_wp_error($webhookData) && Arr::get($webhookData, 'id') === $webhookId) {
                static::parseAndUpdateSettings($webhookData, $mode); // update webhook events
                return $webhookData;
            }
        }

        // If there is no Webhook ID found or webhook isn't found, register a new one
        return (new Webhook())->registerWebhook($mode);
    }
}
