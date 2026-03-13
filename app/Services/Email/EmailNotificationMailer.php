<?php

namespace FluentCart\App\Services\Email;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Model;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\BlockParser;
use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use FluentCart\Framework\Support\Arr;

class EmailNotificationMailer
{
    public function register()
    {
        // $this->registerAsyncMails();
        add_action('fluent_cart/order_placed_offline', function ($data) {
            $this->mailEmailsOfEvent(
                'order_placed_offline',
                $data
            );

        }, 999, 1);
        // To Customer
        add_action('fluent_cart/order_paid', function ($data) {
            $this->mailEmailsOfEvent(
                'order_paid',
                $data
            );

        }, 999, 1);
        // To Admin
        add_action('fluent_cart/order_paid_done', function ($data) {
            $this->mailEmailsOfEvent(
                'order_paid_done',
                $data
            );
        }, 10, 1);

        // to customer and admin
        add_action('fluent_cart/subscription_renewed', function ($data) {
            $this->mailEmailsOfEvent(
                'subscription_renewed',
                $data
            );
        }, 999, 1);

        // to customer and admin
        add_action('fluent_cart/subscription_canceled', function ($data) {
            $this->mailEmailsOfEvent(
                'subscription_canceled',
                $data
            );
        }, 999, 1);

        add_action('fluent_cart/order_refunded', function ($data) {
            $this->mailEmailsOfEvent('order_refunded', $data);
        }, 999, 1);

        add_action('fluent_cart/shipping_status_changed_to_shipped', function ($data) {
            $this->mailEmailsOfEvent('shipping_status_changed_to_shipped', $data);
        }, 999, 1);

        add_action('fluent_cart/shipping_status_changed_to_delivered', function ($data) {
            $this->mailEmailsOfEvent('shipping_status_changed_to_delivered', $data);
        }, 999, 1);

        // @todo uncomment when invoice feature is deployed
        // add_action('fluent_cart/invoice_reminder_due', function ($data) {
        //     $this->mailEmailsOfEvent('invoice_reminder_due', $data);
        // }, 999, 1);

        add_action('fluent_cart/invoice_reminder_overdue', function ($data) {
            $this->mailEmailsOfEvent('invoice_reminder_overdue', $data);
        }, 999, 1);

        add_action('fluent_cart/subscription_renewal_reminder', function ($data) {
            $this->mailEmailsOfEvent('subscription_renewal_reminder', $data);
        }, 999, 1);

        add_action('fluent_cart/subscription_trial_end_reminder', function ($data) {
            $this->mailEmailsOfEvent('subscription_trial_end_reminder', $data);
        }, 999, 1);

    }

    public function registerAsyncMails()
    {
        //For Async Actions
        add_action('fluent_cart/async_mail/order_created', function ($orderId, $mailName = '') {
            (new static())->sendAsyncOrderMail($mailName, $orderId);
        }, 10, 2);

        add_action('fluent_cart/async_mail/order_placed_offline', function ($orderId, $mailName = '') {
            (new static())->sendAsyncOrderMail($mailName, $orderId);
        }, 10, 2);

        add_action('fluent_cart/async_mail/order_paid', function ($orderId, $mailName = '') {
            (new static())->sendAsyncOrderMail($mailName, $orderId);
        }, 10, 2);

        add_action('fluent_cart/async_mail/order_updated', function ($orderId, $mailName = '') {
            (new static())->sendAsyncOrderMail($mailName, $orderId);
        }, 10, 2);

        add_action('fluent_cart/async_mail/order_refunded', function ($orderId, $mailName = '') {
            (new static())->sendAsyncOrderMail($mailName, $orderId);
        }, 10, 2);

        add_action('fluent_cart/async_mail/subscription_activated', function ($subscriptionId, $mailName = '') {
            (new static())->sendAsyncSubscriptionMail($mailName, $subscriptionId);
        }, 10, 2);

        add_action('fluent_cart/async_mail/subscription_renewed', function ($subscriptionId, $mailName = '') {
            (new static())->sendAsyncSubscriptionMail($mailName, $subscriptionId);
        }, 10, 2);

        add_action('fluent_cart/async_mail/subscription_eot', function ($subscriptionId, $mailName = '') {
            (new static())->sendAsyncSubscriptionMail($mailName, $subscriptionId);
        }, 10, 2);

        add_action('fluent_cart/async_mail/subscription_canceled', function ($subscriptionId, $mailName = '') {
            (new static())->sendAsyncSubscriptionMail($mailName, $subscriptionId);
        }, 10, 2);

        add_action('fluent_cart/async_mail/subscription_expired', function ($subscriptionId, $mailName = '') {
            (new static())->sendAsyncSubscriptionMail($mailName, $subscriptionId);
        }, 10, 2);

    }


    public function formatParsable($parsable)
    {
        foreach ($parsable as &$item) {
            if ($item instanceof Model) {
                $item = $item->toArray();
            }
        }

        if (!Arr::has($parsable, 'order.customer')) {
            $parsable['order']['customer'] = Arr::get(
                $parsable, 'customer', []
            );
        }

        return $parsable;
    }

    public function mailEmailsOfEvent($event, $data, $asyncHook = '', $asyncData = [])
    {
        $parsedData = $this->formatParsable($data);

        // Pass original Model data for template rendering (templates use $order->method())
        $notifications = EmailNotifications::getNotificationsOfEvent($event, $data);

        foreach ($notifications as $mailName => $notification) {
            $isAsync = Arr::get($notification, 'is_async', false);
            if ($isAsync && !empty($asyncHook)) {
                $asyncData['mailName'] = $mailName;
                as_enqueue_async_action($asyncHook, $asyncData);
            } else {
                list($body, $subject, $to) = $this->parseEmailContent($notification, $data);
                Mailer::make()->to($to)->subject($subject)->body($body)->send(true);
            }
        }

    }

    public function mailByEmailName($emailName, $data)
    {
        $data = $this->formatParsable($data);
        $notification = EmailNotifications::getNotification($emailName);
        $notification = EmailNotifications::formatNotification($notification, $data);
        list($body, $subject, $to) = $this->parseEmailContent($notification, $data);
        Mailer::make()->to($to)->subject($subject)->body($body)->send(true);
    }

    public function getEmailFooter(): string
    {

        $footer = "";
        $settings = EmailNotifications::getSettings();
        $emailFooter = Arr::get($settings, 'email_footer', '');
        if (!empty($emailFooter)) {
            $footer .= ShortcodeTemplateBuilder::make($emailFooter, []);
        }
        $isEmailFooter = EmailNotifications::getSettings('show_email_footer');
        if (!App::isProActive() || $isEmailFooter === 'yes') {
            $cartFooter = "<div style='padding: 15px; text-align: center; font-size: 16px; color: #2F3448;'>Powered by <a href='https://fluentcart.com' style='color: #017EF3; text-decoration: none;'>FluentCart</a></div>";
            $footer .= $cartFooter;
        }
        return $footer;

    }

    public function parseEmailContent($notification, $data, $templateData = null): array
    {
        $templateData = $templateData ?: $data;

        $rawBody = Arr::get($notification, 'body', '');
        $isCustom = (bool) Arr::get($notification, 'is_custom', false);

        // Let pro parse block content; returns empty string if no pro
        $parsedBody = apply_filters('fluent_cart/parse_email_block_content', '', $rawBody, $data);

        $body = '';

        // Custom block emails use block_editor_template (pro);
        // default templates use general_template with legacy order_header.
        if ($isCustom && $parsedBody) {
            $body = apply_filters('fluent_cart/render_block_email_template', '', [
                'emailBody'   => $parsedBody,
                'preheader'   => Arr::get($notification, 'pre_header', ''),
                'emailFooter' => $this->getEmailFooter(),
            ]);
        }

        if (empty($body)) {
            $header = App::make('view')->make('emails.parts.order_header', $data);
            $body = (string)App::make('view')->make('emails.general_template', [
                'emailBody'   => $rawBody,
                'preheader'   => Arr::get($notification, 'pre_header', ''),
                'header'      => $header,
                'emailFooter' => $this->getEmailFooter(),
            ]);
        }

        $body = ShortcodeTemplateBuilder::make($body, $data);

        $subject = ShortcodeTemplateBuilder::make(Arr::get($notification, 'subject', ''), $data);
        $to = Arr::get($notification, 'to', '');
        $to = ShortcodeTemplateBuilder::make($to, $data);

        return [
            0 => $body,
            1 => $subject,
            2 => $to
        ];
    }

    public function sendAsyncOrderMail($emailName, $orderId)
    {
        $order = Order::query()->with(['customer', 'shipping_address', 'billing_address', 'transactions'])->find($orderId);

        if ($order) {
            $transaction = [];
            if (!empty($order->transactions)) {
                $transaction = $order->transactions->first();
            }
            $this->mailByEmailName($emailName, [
                'order'       => $order,
                'customer'    => $order->customer !== null ? $order->customer : [],
                'transaction' => $transaction
            ]);
        }
    }

    public function sendAsyncSubscriptionMail($emailName, $subscriptionId)
    {
        $subscription = Subscription::query()->with([
            'customer',
            'transactions',
            'order'
        ])->find($subscriptionId);

        if ($subscription) {
            $order = $subscription->order;
            $this->mailByEmailName($emailName, [
                'subscription' => $subscription,
                'order'        => $order,
                'customer'     => $subscription->customer !== null ? $subscription->customer : [],
                'transactions' => $subscription->transactions
            ]);
        }

    }
}
