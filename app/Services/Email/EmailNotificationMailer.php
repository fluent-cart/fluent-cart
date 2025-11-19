<?php

namespace FluentCart\App\Services\Email;

use FluentCart\App\App;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Model;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use FluentCart\Framework\Support\Arr;

class EmailNotificationMailer
{
    public function register()
    {
        // $this->registerAsyncMails();
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

        add_action('fluent_cart/order_refunded', function ($data) {
            $this->mailEmailsOfEvent('order_refunded', $data);
        }, 999, 1);

        add_action('fluent_cart/shipping_status_changed_to_shipped', function ($data) {
            $this->mailEmailsOfEvent('shipping_status_changed_to_shipped', $data);
        }, 999, 1);

        add_action('fluent_cart/shipping_status_changed_to_delivered', function ($data) {
            $this->mailEmailsOfEvent('shipping_status_changed_to_delivered', $data);
        }, 999, 1);

    }

    public function registerAsyncMails()
    {
        //For Async Actions
        add_action('fluent_cart/async_mail/order_created', function ($orderId, $mailName = '') {
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
        $notification = EmailNotifications::formatNotification($notification);
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

    public function parseEmailContent($notification, $data): array
    {

        $body = Arr::get($notification, 'body', '');

        $header = App::make('view')->make('emails.parts.order_header', $data);
        $template = 'emails.general_template';

        if (Arr::get($notification, 'is_customxxx')) {
            $body = (new FluentBlockParser($data))->parse($body);
           // $template = 'emails.block_editor_template';
        } else {

        }

        $body = (string)App::make('view')->make($template, [
            'emailBody'   => $body,
            'preheader'   => Arr::get($notification, 'pre_header', ''),
            'header'      => $header,
            'emailFooter' => $this->getEmailFooter(),
        ]);

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

