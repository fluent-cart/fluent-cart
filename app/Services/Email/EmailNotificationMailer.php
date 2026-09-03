<?php

namespace FluentCart\App\Services\Email;

use FluentCart\App\App;
use FluentCart\App\Models\Model;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\OrderService;
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
        // 999 like the rest of this file: custom smartcodes in a customised admin
        // body resolve against the payload as it stands when the mail is built, so
        // the mail has to run after everything else bound here writes its data —
        // integration feeds (11), FluentCRM (20), affiliate referral status (99).
        // Cost of running last: this hook forces every feed to run realtime, so the
        // mail waits on their outbound HTTP, and IntegrationEventListener catches
        // only \Exception — a \Error in a feed loses the mail.
        add_action('fluent_cart/order_paid_done', function ($data) {
            $this->mailEmailsOfEvent(
                'order_paid_done',
                $data
            );
        }, 999, 1);

        // to customer and admin
        add_action('fluent_cart/subscription_renewed', function ($data) {
            $this->mailEmailsOfEvent(
                'subscription_renewed',
                $data
            );
        }, 999, 1);

        // to customer and admin
        add_action('fluent_cart/subscription_renewal_failed', function ($data) {
            $this->mailEmailsOfEvent(
                'subscription_renewal_failed',
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

        add_action('fluent_cart/renewal_created', function ($data) {
            $this->mailEmailsOfEvent('renewal_created', $data);
        }, 999, 1);

        add_action('fluent_cart/renewal_payment_reminder', function ($data) {
            $this->mailEmailsOfEvent('renewal_payment_reminder', $data);
        }, 999, 1);

        // Fired by RenewalService when a system renewal order is created ahead of
        // its automatic charge — the customer's advance notice of amount and date.
        add_action('fluent_cart/subscriptions/system_renewal_scheduled', function ($data) {
            $shouldSend = apply_filters('fluent_cart/subscriptions/upcoming_charge_notification', true, $data);

            if ($shouldSend) {
                $this->mailEmailsOfEvent('system_upcoming_charge', $data);
            }
        }, 999, 1);

        // Fired by SystemChargeService when an automatic (system) renewal charge
        // fails and the customer should be notified (first failure by default).
        add_action('fluent_cart/subscriptions/system_charge_failed_notification', function ($data) {
            $this->mailEmailsOfEvent('system_charge_failed', $data);
        }, 999, 1);

        add_action('fluent_cart/subscription_period_skipped', function ($data) {
            $this->mailEmailsOfEvent('subscription_period_skipped', $data);
        }, 999, 1);

        add_action('fluent_cart/subscription_past_due', function ($data) {
            $this->mailEmailsOfEvent('subscription_past_due', $data);
        }, 999, 1);

        add_action('fluent_cart/renewal_reminder_due', function ($data) {
            $this->mailEmailsOfEvent('renewal_reminder_due', $data);
        }, 999, 1);

        add_action('fluent_cart/renewal_reminder_overdue', function ($data) {
            // Set only by the deprecated bridge in RenewalReminderService::send():
            // the staged email (renewal_overdue_first/followup/final) already went
            // out for this payload, so mailing here would duplicate it. Direct
            // dispatches of this hook never carry the flag and still deliver.
            if (!empty(Arr::get($data, 'staged_email_dispatched'))) {
                return;
            }
            $this->mailEmailsOfEvent('renewal_reminder_overdue', $data);
        }, 999, 1);

        add_action('fluent_cart/renewal_overdue_first', function ($data) {
            $this->mailEmailsOfEvent('renewal_overdue_first', $data);
        }, 999, 1);

        add_action('fluent_cart/renewal_overdue_followup', function ($data) {
            $this->mailEmailsOfEvent('renewal_overdue_followup', $data);
        }, 999, 1);

        add_action('fluent_cart/renewal_overdue_final', function ($data) {
            $this->mailEmailsOfEvent('renewal_overdue_final', $data);
        }, 999, 1);

        add_action('fluent_cart/subscription_renewal_reminder', function ($data) {
            $this->mailEmailsOfEvent('subscription_renewal_reminder', $data);
        }, 999, 1);

        add_action('fluent_cart/subscription_trial_end_reminder', function ($data) {
            $this->mailEmailsOfEvent('subscription_trial_end_reminder', $data);
        }, 999, 1);

        add_action('fluent_cart/review_created', function ($data) {
            $review = Arr::get($data, 'review');
            if ($review && empty($review->parent_id)) {
                $this->mailEmailsOfEvent('review_created', $data);
            }
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

        add_action('fluent_cart/async_mail/subscription_reactivated', function ($subscriptionId, $mailName = '') {
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

        $order = Arr::get($data, 'order');

        // Pass original Model data for template rendering (templates use $order->method())
        $notifications = EmailNotifications::getNotificationsOfEvent($event, $data);

        foreach ($notifications as $mailName => $notification) {
            if ($order instanceof Order && !apply_filters('fluent_cart/should_send_email_notification', true, [
                'event'     => $event,
                'mail_name' => $mailName,
                'order'     => $order,
            ])) {
                continue;
            }

            $isAsync = Arr::get($notification, 'is_async', false);
            if ($isAsync && !empty($asyncHook)) {
                $asyncData['mailName'] = $mailName;
                as_enqueue_async_action($asyncHook, $asyncData);
            } else {
                list($body, $subject, $to) = $this->parseEmailContent($notification, $data);

                $mailer = Mailer::make()->to($to)->subject($subject)->body($body);

                $pdfPath = null;
                $templateId = Arr::get($notification, 'settings.attach_pdf_template', '');
                // Backward compat: old attach_pdf='yes' maps to order_receipt
                if (empty($templateId) && Arr::get($notification, 'settings.attach_pdf', 'no') === 'yes') {
                    $templateId = 'order_receipt';
                }
                if (!empty($templateId) && defined('FLUENT_PDF')) {
                    $pdfPath = $this->generateOrderPdf($data, $templateId);
                    if ($pdfPath) {
                        $mailer->addAttachment($pdfPath);
                    }
                }

                $mailer = $this->applyMailerFilter($mailer, [
                    'event'        => $event,
                    'mail_name'    => $mailName,
                    'recipient'    => Arr::get($notification, 'recipient'),
                    'notification' => $notification,
                    'data'         => $data,
                ]);

                $mailer->send(true);

                // Clean up temp PDF file after sending
                if ($pdfPath && file_exists($pdfPath)) {
                    @unlink($pdfPath);
                }
            }
        }

    }

    public function mailByEmailName($emailName, $data)
    {
        // $data keeps its Models: parseEmailContent renders emails.parts.order_header,
        // which reads $order->invoice_no / $order->orderTaxRates — an array here
        // warns and then fatals inside the view (regression of 201a14885 via 44a39aa1d)
        $orderModel = Arr::get($data, 'order');

        $notification = EmailNotifications::getNotification($emailName);
        $notification = EmailNotifications::formatNotification($notification, $data);
        list($body, $subject, $to) = $this->parseEmailContent($notification, $data);

        $mailer = Mailer::make()->to($to)->subject($subject)->body($body);

        $pdfPath = null;
        $templateId = Arr::get($notification, 'settings.attach_pdf_template', '');
        if (empty($templateId) && Arr::get($notification, 'settings.attach_pdf', 'no') === 'yes') {
            $templateId = 'order_receipt';
        }
        if (!empty($templateId) && defined('FLUENT_PDF')) {
            $pdfPath = $this->generateOrderPdf(['order' => $orderModel], $templateId);
            if ($pdfPath) {
                $mailer->addAttachment($pdfPath);
            }
        }

        $mailer = $this->applyMailerFilter($mailer, [
            'event'        => Arr::get($notification, 'event', ''),
            'mail_name'    => $emailName,
            'recipient'    => Arr::get($notification, 'recipient'),
            'notification' => $notification,
            'data'         => $data,
        ]);

        $mailer->send(true);

        if ($pdfPath && file_exists($pdfPath)) {
            @unlink($pdfPath);
        }
    }

    /**
     * Let third-party code adjust the fully prepared Mailer (recipients,
     * subject, body, attachments already set) immediately before it sends.
     *
     * @param Mailer $mailer
     * @param array $context event, mail_name, recipient, notification, data
     * @return Mailer
     */
    private function applyMailerFilter(Mailer $mailer, array $context): Mailer
    {
        $filtered = apply_filters('fluent_cart/email_notification/mailer', $mailer, $context);

        return $filtered instanceof Mailer ? $filtered : $mailer;
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
            // order_header reads $order->invoice_no and $order->orderTaxRates, so
            // anything that is not an Order model fatals inside the view — an
            // array warns and then dies on ->first(). Render it only for a real
            // model: notifications that legitimately have no order still get a
            // body, they just get no order header.
            $orderModel = Arr::get($data, 'order');
            $header = ($orderModel instanceof Order)
                ? (string)App::make('view')->make('emails.parts.order_header', $data)
                : '';

            $body = (string)App::make('view')->make('emails.general_template', [
                'emailBody'   => $rawBody,
                'preheader'   => Arr::get($notification, 'pre_header', ''),
                'header'      => $header,
                'emailFooter' => $this->getEmailFooter(),
            ]);
        }

        $body = ShortcodeTemplateBuilder::make($body, $data);

        $subject = ShortcodeTemplateBuilder::make(Arr::get($notification, 'subject', ''), $data);
        // A notification may declare an array of recipients — wp_mail() accepts
        // one — so expand element by element rather than flattening to a string.
        $to = Arr::get($notification, 'to', '');
        if (is_array($to)) {
            foreach ($to as $index => $address) {
                $to[$index] = ShortcodeTemplateBuilder::make((string)$address, $data);
            }
        } else {
            $to = ShortcodeTemplateBuilder::make((string)$to, $data);
        }

        // Admin-bound notifications only — stores route these to a helpdesk or
        // accounting inbox. Customer-bound mail must keep going to the customer,
        // so it is deliberately not filterable here. Applied after shortcode
        // resolution, so listeners see the address the notification resolved to.
        if (Arr::get($notification, 'recipient') === 'admin') {
            $to = apply_filters('fluent_cart/admin_email/notification_recipient', $to, [
                'event'        => Arr::get($notification, 'event', ''),
                'mail_name'    => Arr::get($notification, 'name', ''),
                'notification' => $notification,
                'data'         => $data,
            ]);
        }

        return [
            0 => $body,
            1 => $subject,
            2 => $to
        ];
    }

    public function sendAsyncOrderMail($emailName, $orderId)
    {
        $order = Order::query()->with(['customer', 'shipping_address', 'billing_address', 'transactions', 'orderTaxRates'])->find($orderId);

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
            if (!$order) {
                return;
            }
            $this->mailByEmailName($emailName, [
                'subscription' => $subscription,
                'order'        => $order,
                'customer'     => $subscription->customer !== null ? $subscription->customer : [],
                'transactions' => $subscription->transactions
            ]);
        }

    }

    /**
     * Generate a PDF receipt for the order in the given data array.
     *
     * @param array $data Event data containing 'order' key
     * @return string|null Path to generated PDF or null
     */
    private function generateOrderPdf(array $data, string $templateId = 'order_receipt'): ?string
    {
        if (!OrderService::canGenerateReceiptPdf()) {
            return null;
        }

        $order = Arr::get($data, 'order');
        if (!$order || !($order instanceof Order)) {
            return null;
        }

        try {
            return apply_filters('fluent_cart/pdf/generate_receipt', null, [
                'order'       => $order,
                'template_id' => $templateId,
            ]);
        } catch (\Throwable $e) {
            fluent_cart_add_log(
                'PDF Generation Failed',
                $e->getMessage(),
                'error'
            );
            return null;
        }
    }

}
