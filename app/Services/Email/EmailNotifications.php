<?php

namespace FluentCart\App\Services\Email;

use FluentCart\App\Models\Meta;
use FluentCart\App\Services\Cache;
use FluentCart\App\Services\TemplateService;
use FluentCart\Framework\Support\Arr;

class EmailNotifications
{

    const EMAIL_RECIPIENT_MAP = [
        'customer'   => '{{order.customer.email}}',
        'user'       => '{{user.user_email}}',
        'subscriber' => '{{order.customer.email}}'
    ];

    const META_KEY = 'email_notifications_config';

    public static function getNotifications(): array
    {
        $settings = static::getDefaultNotifications();
        $settings = apply_filters('fluent_cart/email_notifications', $settings);
        $config = Arr::get(static::cachedSettings(), 'notification_config', []);

        foreach ($settings as $key => &$setting) {
            $setting['name'] = $key;

            if (empty($setting['group_label'])) {
                $setting['group_label'] = __('Other Actions', 'fluent-cart');
            }

            $keyConfig = Arr::get($config, $key, []);
            if (!$keyConfig) {
                continue;
            }

            $setting['settings'] = wp_parse_args($keyConfig, $setting['settings']);

            // Backward compat: migrate old attach_pdf to attach_pdf_template
            if (empty($setting['settings']['attach_pdf_template']) && !empty($setting['settings']['attach_pdf']) && $setting['settings']['attach_pdf'] === 'yes') {
                $setting['settings']['attach_pdf_template'] = 'order_receipt';
            }
            unset($setting['settings']['attach_pdf']);
        }

        return $settings;
    }

    public static function getDefaultNotifications(): array
    {

        /**
         * Order Placed (Offline Payment) -> fluent_cart/order_placed_offline (customer + admin)
         * Order Paid -> fluent_cart/order_paid (customer + admin)
         * Order Renewal -> fluent_cart/subscription_renewed (customer + admin)
         * Order Refunded -> fluent_cart/order_fully_refunded (customer + admin)
         * Order Shipping Status Changed => fluent_cart/shipping_status_changed (customer + admin)
         * Reminder Events:
         * - fluent_cart/renewal_reminder_due
         * - fluent_cart/renewal_reminder_overdue (manual/on-demand payment reminder)
         * - fluent_cart/renewal_overdue_first (scheduled, first overdue stage)
         * - fluent_cart/renewal_overdue_followup (scheduled, intermediate overdue stages)
         * - fluent_cart/renewal_overdue_final (scheduled, last overdue stage)
         * - fluent_cart/subscription_renewal_reminder
         * - fluent_cart/subscription_trial_end_reminder
         */

        return [
            'order_paid_admin'              => [
                'event'            => 'order_paid_done',
                'group'            => 'order',
                'group_label'      => __('Order Actions', 'fluent-cart'),
                'title'            => __('Send mail to admin after New Order Paid', 'fluent-cart'),
                'description'      => __('This email will be sent to the admin after an order is placed.', 'fluent-cart'),
                'recipient'        => 'admin',
                'smartcode_groups' => [],
                'template_path'    => 'order.paid.admin',
                'is_async'         => false,
                'pre_header'       => __('You got a new order on your shop. Congratulations! Checkout all the details in this email. You can also go to FluentCart Dashboard to view the order details and manage it. Thank you for using FluentCart.', 'fluent-cart'),
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('New Sales On {{settings.store_name}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                    'attach_pdf_template' => '',
                ]
            ],
            'order_paid_customer'           => [
                'event'            => 'order_paid',
                'group'            => 'order',
                'group_label'      => __('Order Actions', 'fluent-cart'),
                'title'            => __('Purchase receipt to customer', 'fluent-cart'),
                'description'      => __('This email will be sent to the customer after an order is placed.', 'fluent-cart'),
                'recipient'        => 'customer',
                'smartcode_groups' => [],
                'template_path'    => 'order.paid.customer',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('Purchase Receipt #{{order.invoice_no}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                    'attach_pdf_template' => '',
                ]
            ],
            'subscription_renewal_customer' => [
                'event'            => 'subscription_renewed',
                'group'            => 'subscription',
                'group_label'      => __('Subscription Actions', 'fluent-cart'),
                'title'            => __('Send mail to customer after a subscription renewed', 'fluent-cart'),
                'description'      => __('This email will be sent to the customer after a renewal payment made', 'fluent-cart'),
                'recipient'        => 'customer',
                'smartcode_groups' => [],
                'template_path'    => 'subscription.renewal.customer',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('Renewal Confirmation on {{settings.store_name}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                    'attach_pdf_template' => '',
                ]
            ],
            'subscription_renewal_admin'    => [
                'event'            => 'subscription_renewed',
                'group'            => 'subscription',
                'group_label'      => __('Subscription Actions', 'fluent-cart'),
                'title'            => __('Send mail to admin after a subscription renewed', 'fluent-cart'),
                'description'      => __('This email will be sent to the admin after a renewal payment made', 'fluent-cart'),
                'recipient'        => 'admin',
                'smartcode_groups' => [],
                'template_path'    => 'subscription.renewal.admin',
                'pre_header'       => __('You got a new Renewal on your shop. Congratulations! Checkout all the details in this email. You can also go to FluentCart Dashboard to view the order details and manage it. Thank you for using FluentCart.', 'fluent-cart'),
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('New Renewal On {{settings.store_name}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                    'attach_pdf_template' => '',
                ]
            ],
            'subscription_renewal_failed_customer' => [
                'event'            => 'subscription_renewal_failed',
                'group'            => 'subscription',
                'group_label'      => __('Subscription Actions', 'fluent-cart'),
                'title'            => __('Notify customer when an automatic renewal charge fails', 'fluent-cart'),
                'description'      => __('This email will be sent to the customer when their saved payment method could not be charged for a subscription renewal.', 'fluent-cart'),
                'recipient'        => 'customer',
                'smartcode_groups' => [],
                'template_path'    => 'subscription.renewal_failed.customer',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('Automatic Payment Failed for Renewal #{{order.invoice_no}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
            'subscription_renewal_failed_admin'    => [
                'event'            => 'subscription_renewal_failed',
                'group'            => 'subscription',
                'group_label'      => __('Subscription Actions', 'fluent-cart'),
                'title'            => __('Notify admin when an automatic renewal charge fails', 'fluent-cart'),
                'description'      => __('This email will be sent to the admin when a saved payment method could not be charged for a subscription renewal.', 'fluent-cart'),
                'recipient'        => 'admin',
                'smartcode_groups' => [],
                'template_path'    => 'subscription.renewal_failed.admin',
                'pre_header'       => __('An automatic subscription renewal charge failed. Review the order in the FluentCart Dashboard.', 'fluent-cart'),
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'no',
                    'subject'         => __('Automatic Charge Failed for Renewal #{{order.invoice_no}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
            'subscription_canceled_customer' => [
                'event'            => 'subscription_canceled',
                'group'            => 'subscription',
                'group_label'      => __('Subscription Actions', 'fluent-cart'),
                'title'            => __('Send mail to customer when a subscription is canceled', 'fluent-cart'),
                'description'      => __('This email will be sent to the customer when their subscription is canceled.', 'fluent-cart'),
                'recipient'        => 'customer',
                'smartcode_groups' => [],
                'template_path'    => 'subscription.canceled.customer',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('Subscription Canceled on {{settings.store_name}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
            'subscription_canceled_admin'    => [
                'event'            => 'subscription_canceled',
                'group'            => 'subscription',
                'group_label'      => __('Subscription Actions', 'fluent-cart'),
                'title'            => __('Send mail to admin when a subscription is canceled', 'fluent-cart'),
                'description'      => __('This email will be sent to the admin when a subscription is canceled.', 'fluent-cart'),
                'recipient'        => 'admin',
                'smartcode_groups' => [],
                'template_path'    => 'subscription.canceled.admin',
                'pre_header'       => __('A subscription has been canceled. Review the details in this email or visit FluentCart Dashboard to manage the subscription.', 'fluent-cart'),
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('Subscription Canceled - {{order.customer.full_name}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
            'subscription_period_skipped_customer' => [
                'event'            => 'subscription_period_skipped',
                'group'            => 'subscription',
                'group_label'      => __('Subscription Actions', 'fluent-cart'),
                'title'            => __('Notify customer when a billing period is skipped', 'fluent-cart'),
                'description'      => __('This email will be sent to the customer when an admin skips their next billing period. The internal reason and actor are never included. Off by default.', 'fluent-cart'),
                'recipient'        => 'customer',
                'smartcode_groups' => [],
                'template_path'    => 'subscription.period_skipped.customer',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'no',
                    'subject'         => __('Your next payment has been skipped on {{settings.store_name}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
            'order_refunded_admin'          => [
                'event'            => 'order_refunded',
                'group'            => 'order',
                'group_label'      => __('Order Actions', 'fluent-cart'),
                'title'            => __('Send mail to admin after a refund.', 'fluent-cart'),
                'description'      => __('This email will be sent to the admin after an order is refunded (partial / full).', 'fluent-cart'),
                'recipient'        => 'admin',
                'smartcode_groups' => [],
                'template_path'    => 'order.refunded.admin',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'no',
                    'subject'         => __('Refund sent to {{order.customer.full_name}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                    'attach_pdf_template' => '',
                ]
            ],
            'order_refunded_customer'       => [
                'event'            => 'order_refunded',
                'group'            => 'order',
                'group_label'      => __('Order Actions', 'fluent-cart'),
                'title'            => __('Send mail to customer after a refund.', 'fluent-cart'),
                'description'      => __('This email will be sent to the customer after an order is refunded (partial / full).', 'fluent-cart'),
                'recipient'        => 'customer',
                'smartcode_groups' => [],
                'template_path'    => 'order.refunded.customer',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('Refund Confirmation from {{settings.store_name}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                    'attach_pdf_template' => '',
                ]
            ],
            'order_shipped_customer'        => [
                'event'            => 'shipping_status_changed_to_shipped',
                'group'            => 'order',
                'group_label'      => __('Order Actions', 'fluent-cart'),
                'title'            => __('Send mail to customer when shipping status changed to shipped.', 'fluent-cart'),
                'description'      => __('This email will be sent to the customer after an order is marked as shipped', 'fluent-cart'),
                'recipient'        => 'customer',
                'smartcode_groups' => [],
                'template_path'    => 'order.shipped.customer',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('Order has been shipped #{{order.invoice_no}} 📦', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                    'attach_pdf_template' => '',
                ]
            ],
            'order_delivered_customer'      => [
                'event'            => 'shipping_status_changed_to_delivered',
                'group'            => 'order',
                'group_label'      => __('Order Actions', 'fluent-cart'),
                'title'            => __('Send mail to customer when shipping status changed to delivered.', 'fluent-cart'),
                'description'      => __('This email will be sent to the customer after an order is marked as delivered', 'fluent-cart'),
                'recipient'        => 'customer',
                'smartcode_groups' => [],
                'template_path'    => 'order.delivered.customer',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('Order has been delivered #{{order.invoice_no}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                    'attach_pdf_template' => '',
                ]
            ],
            'order_placed_admin'            => [
                'event'            => 'order_placed_offline',
                'group'            => 'order',
                'group_label'      => __('Order Actions', 'fluent-cart'),
                'title'            => __('Send mail to admin after New Order Placed (Offline Payment)', 'fluent-cart'),
                'description'      => __('This email will be sent to the admin when an order is placed using offline payment method.', 'fluent-cart'),
                'recipient'        => 'admin',
                'smartcode_groups' => [],
                'template_path'    => 'order.placed.admin',
                'is_async'         => false,
                'manage_toggle'    => 'no',
                'toggle_label'     => __('Auto-enabled for offline payments', 'fluent-cart'),
                'pre_header'       => __('You have a new order on your shop placed with offline payment. Please review the order details in this email. You can also go to FluentCart Dashboard to view the order details and manage it. Thank you for using FluentCart.', 'fluent-cart'),
                'settings'         => [
                    'subject'         => __('New Order on {{settings.store_name}} (Offline Payment)', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                    'attach_pdf_template' => '',
                ]
            ],
            'order_placed_customer'         => [
                'event'            => 'order_placed_offline',
                'group'            => 'order',
                'group_label'      => __('Order Actions', 'fluent-cart'),
                'title'            => __('Order confirmation to customer (Offline Payment)', 'fluent-cart'),
                'description'      => __('This email will be sent to the customer when an order is placed using offline payment method.', 'fluent-cart'),
                'recipient'        => 'customer',
                'smartcode_groups' => [],
                'template_path'    => 'order.placed.customer',
                'is_async'         => false,
                'manage_toggle'    => 'no',
                'toggle_label'     => __('Auto-enabled for offline payments', 'fluent-cart'),
                'settings'         => [
                    'subject'         => __('Order Confirmation #{{order.invoice_no}} (Offline Payment)', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                    'attach_pdf_template' => '',
                ]
            ],
            'renewal_created_admin'         => [
                'event'            => 'renewal_created',
                'group'            => 'manual_subscription',
                'group_label'      => __('Store-Managed Renewals', 'fluent-cart'),
                'title'            => __('Notify admin when a renewal order is created', 'fluent-cart'),
                'description'      => __('This email will be sent to the admin when a renewal order is generated for a store-managed subscription.', 'fluent-cart'),
                'recipient'        => 'admin',
                'smartcode_groups' => [],
                'template_path'    => 'renewal.created.admin',
                'pre_header'       => 'A new renewal order has been created for a store-managed subscription. Review it in the FluentCart Dashboard.',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('New Renewal Order Created on {{settings.store_name}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
            'renewal_created_customer'      => [
                'event'            => 'renewal_created',
                'group'            => 'manual_subscription',
                'group_label'      => __('Store-Managed Renewals', 'fluent-cart'),
                'title'            => __('Send the renewal order to the customer when a renewal order is created', 'fluent-cart'),
                'description'      => __('This email will be sent to the customer when a renewal order is generated for a store-managed subscription.', 'fluent-cart'),
                'recipient'        => 'customer',
                'smartcode_groups' => [],
                'template_path'    => 'renewal.created.customer',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('Your Subscription Renewal #{{order.invoice_no}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
            'system_upcoming_charge_customer' => [
                'event'            => 'system_upcoming_charge',
                'group'            => 'system_subscription',
                'group_label'      => __('Store-Managed Renewals · Auto-Charge', 'fluent-cart'),
                'title'            => __('Notify customer before an automatic renewal charge', 'fluent-cart'),
                'description'      => __('This email tells the customer the amount and date of the upcoming automatic charge to their saved payment method, when the renewal order is created ahead of the due date.', 'fluent-cart'),
                'recipient'        => 'customer',
                'smartcode_groups' => [],
                'template_path'    => 'subscription.upcoming_charge.customer',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('Upcoming automatic payment for your subscription', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
            'system_charge_failed_customer' => [
                'event'            => 'system_charge_failed',
                'group'            => 'system_subscription',
                'group_label'      => __('Store-Managed Renewals · Auto-Charge', 'fluent-cart'),
                'title'            => __('Notify customer when an automatic renewal charge fails', 'fluent-cart'),
                'description'      => __('This email will be sent to the customer when the saved payment method could not be charged for a renewal order. It includes the failure reason and a Pay Now link.', 'fluent-cart'),
                'recipient'        => 'customer',
                'smartcode_groups' => [],
                'template_path'    => 'subscription.charge_failed.customer',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('Automatic Payment Failed for Renewal #{{order.invoice_no}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
            'system_charge_failed_admin'    => [
                'event'            => 'system_charge_failed',
                'group'            => 'system_subscription',
                'group_label'      => __('Store-Managed Renewals · Auto-Charge', 'fluent-cart'),
                'title'            => __('Notify admin when an automatic renewal charge fails', 'fluent-cart'),
                'description'      => __('This email will be sent to the admin when a saved payment method could not be charged for a renewal order.', 'fluent-cart'),
                'recipient'        => 'admin',
                'smartcode_groups' => [],
                'template_path'    => 'subscription.charge_failed.admin',
                'pre_header'       => 'An automatic subscription renewal charge failed. Review the renewal order in the FluentCart Dashboard.',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'no',
                    'subject'         => __('Automatic Charge Failed for Renewal #{{order.invoice_no}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
                    'renewal_reminder_due_admin'    => [
                'event'            => 'renewal_reminder_due',
                'group'            => 'manual_subscription',
                'group_label'      => __('Store-Managed Renewals', 'fluent-cart'),
                'title'            => __('Renewal due reminder copy to admin', 'fluent-cart'),
                'description'      => __('This email will be sent to admin when a due reminder is sent to customer.', 'fluent-cart'),
                'recipient'        => 'admin',
                'smartcode_groups' => [],
                'template_path'    => 'order.reminder.due.admin',
                'pre_header'       => 'A renewal due reminder was sent to a customer. Review the order details in this email or visit FluentCart Dashboard.',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'no',
                    'subject'         => __('Renewal Reminder Sent #{{order.invoice_no}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
            'renewal_reminder_due_customer' => [
                'event'            => 'renewal_reminder_due',
                'group'            => 'manual_subscription',
                'group_label'      => __('Store-Managed Renewals', 'fluent-cart'),
                'title'            => __('Renewal due reminder to customer', 'fluent-cart'),
                'description'      => __('This email will be sent before/at renewal due date when payment is pending.', 'fluent-cart'),
                'recipient'        => 'customer',
                'smartcode_groups' => [],
                'template_path'    => 'order.reminder.due.customer',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('Payment Reminder #{{order.invoice_no}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
            'renewal_reminder_overdue_customer' => [
                'event'            => 'renewal_reminder_overdue',
                'group'            => 'scheduler',
                'group_label'      => __('Scheduler / Reminder Actions', 'fluent-cart'),
                'title'            => __('Payment reminder to customer', 'fluent-cart'),
                'description'      => __('This email will be sent to remind customer about pending payment.', 'fluent-cart'),
                'recipient'        => 'customer',
                'smartcode_groups' => [],
                'template_path'    => 'order.reminder.overdue.customer',
                'is_async'         => false,
                'manage_toggle'    => 'no',
                'toggle_label'     => __('On demand', 'fluent-cart'),
                'settings'         => [
                    'subject'         => __('Payment Reminder - Order #{{order.id}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
            'subscription_past_due_admin' => [
                'event'            => 'subscription_past_due',
                'group'            => 'subscription',
                'group_label'      => __('Subscription Actions', 'fluent-cart'),
                'title'            => __('Notify admin when a subscription is past due', 'fluent-cart'),
                'description'      => __('This email will be sent to the admin when a subscription is marked as past due.', 'fluent-cart'),
                'recipient'        => 'admin',
                'smartcode_groups' => [],
                'template_path'    => 'subscription.past_due.admin',
                'pre_header'       => 'A subscription payment is past due. Review the subscription details in this email or visit FluentCart Dashboard.',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'no',
                    'subject'         => __('Subscription Past Due #{{order.invoice_no}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
            'subscription_past_due_customer' => [
                'event'            => 'subscription_past_due',
                'group'            => 'subscription',
                'group_label'      => __('Subscription Actions', 'fluent-cart'),
                'title'            => __('Notify customer when subscription is past due', 'fluent-cart'),
                'description'      => __('This email will be sent to the customer when their subscription is marked as past due.', 'fluent-cart'),
                'recipient'        => 'customer',
                'smartcode_groups' => [],
                'template_path'    => 'subscription.past_due.customer',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('Your Subscription is Past Due on {{settings.store_name}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
            'subscription_renewal_reminder_admin' => [
                'event'            => 'subscription_renewal_reminder',
                'group'            => 'scheduler',
                'group_label'      => __('Scheduler / Reminder Actions', 'fluent-cart'),
                'title'            => __('Upcoming renewal reminder copy to admin', 'fluent-cart'),
                'description'      => __('This email will be sent to admin when an upcoming renewal reminder is sent.', 'fluent-cart'),
                'recipient'        => 'admin',
                'smartcode_groups' => [],
                'template_path'    => 'subscription.reminder.admin',
                'pre_header'       => __('A subscription renewal reminder was sent to a customer. Review subscription details from FluentCart Dashboard.', 'fluent-cart'),
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'no',
                    'subject'         => __('Renewal Reminder Sent for {{order.customer.full_name}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
            'subscription_renewal_reminder_customer' => [
                'event'            => 'subscription_renewal_reminder',
                'group'            => 'scheduler',
                'group_label'      => __('Scheduler / Reminder Actions', 'fluent-cart'),
                'title'            => __('Upcoming renewal reminder to customer', 'fluent-cart'),
                'description'      => __('This email will be sent before subscription auto-renewal date.', 'fluent-cart'),
                'recipient'        => 'customer',
                'smartcode_groups' => [],
                'template_path'    => 'subscription.reminder.customer',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('Upcoming Renewal Reminder from {{settings.store_name}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
            'renewal_overdue_first_customer' => [
                'event'            => 'renewal_overdue_first',
                'group'            => 'scheduler',
                'group_label'      => __('Scheduler / Reminder Actions', 'fluent-cart'),
                'title'            => __('First overdue renewal reminder to customer', 'fluent-cart'),
                'description'      => __('This email will be sent at the first overdue reminder day after a renewal due date while the payment is still pending.', 'fluent-cart'),
                'recipient'        => 'customer',
                'smartcode_groups' => [],
                'template_path'    => 'order.reminder.renewal_overdue.first.customer',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('Payment Reminder for Renewal #{{order.order_ref}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
            'renewal_overdue_followup_customer' => [
                'event'            => 'renewal_overdue_followup',
                'group'            => 'scheduler',
                'group_label'      => __('Scheduler / Reminder Actions', 'fluent-cart'),
                'title'            => __('Follow-up overdue renewal reminder to customer', 'fluent-cart'),
                'description'      => __('This email will be sent at the intermediate overdue reminder days while the renewal payment remains pending.', 'fluent-cart'),
                'recipient'        => 'customer',
                'smartcode_groups' => [],
                'template_path'    => 'order.reminder.renewal_overdue.followup.customer',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('Payment Overdue for Renewal #{{order.order_ref}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
            'renewal_overdue_final_customer' => [
                'event'            => 'renewal_overdue_final',
                'group'            => 'scheduler',
                'group_label'      => __('Scheduler / Reminder Actions', 'fluent-cart'),
                'title'            => __('Final overdue renewal notice to customer', 'fluent-cart'),
                'description'      => __('This email will be sent at the last configured overdue reminder day while the renewal payment is still pending.', 'fluent-cart'),
                'recipient'        => 'customer',
                'smartcode_groups' => [],
                'template_path'    => 'order.reminder.renewal_overdue.final.customer',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('Final Notice: Payment Overdue for Renewal #{{order.order_ref}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
             'subscription_trial_end_reminder_admin' => [
                'event'            => 'subscription_trial_end_reminder',
                'group'            => 'scheduler',
                'group_label'      => __('Scheduler / Reminder Actions', 'fluent-cart'),
                'title'            => __('Trial ending soon reminder copy to admin', 'fluent-cart'),
                'description'      => __('This email will be sent to admin when a trial ending reminder is sent to a customer.', 'fluent-cart'),
                'recipient'        => 'admin',
                'smartcode_groups' => [],
                'template_path'    => 'subscription.trial_end.admin',
                'pre_header'       => __('A trial ending soon reminder was sent to a customer. Review subscription details from FluentCart Dashboard.', 'fluent-cart'),
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'no',
                    'subject'         => __('Trial Ending Soon - {{order.customer.full_name}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
            'subscription_trial_end_reminder_customer' => [
                'event'            => 'subscription_trial_end_reminder',
                'group'            => 'scheduler',
                'group_label'      => __('Scheduler / Reminder Actions', 'fluent-cart'),
                'title'            => __('Trial ending soon reminder to customer', 'fluent-cart'),
                'description'      => __('This email will be sent before a trial period ends and converts to a paid subscription.', 'fluent-cart'),
                'recipient'        => 'customer',
                'smartcode_groups' => [],
                'template_path'    => 'subscription.trial_end.customer',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('Your Trial is Ending Soon - {{settings.store_name}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
            'review_submitted_admin' => [
                'event'            => 'review_created',
                'group'            => 'review',
                'group_label'      => __('Review Actions', 'fluent-cart'),
                'title'            => __('Send mail to admin when a new review is submitted', 'fluent-cart'),
                'description'      => __('Email admin when a customer submits a new review.', 'fluent-cart'),
                'recipient'        => 'admin',
                'smartcode_groups' => [],
                'template_path'    => 'review.submitted.admin',
                'is_async'         => false,
                'pre_header'       => __('A new product review has been submitted.', 'fluent-cart'),
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('New Review Submitted', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
        ];

    }

    public static function getNotificationsOfEvent($event, $viewData): array
    {
        $notifications = static::getNotifications();
        $notifications = array_filter($notifications, function ($notification) use ($event) {
            return $notification['event'] === $event;
        });

        if (empty($notifications)) {
            return [];
        }

        $emails = [];
        $mailingSettings = static::getSettings();

        foreach ($notifications as $key => $notification) {

            $settings = Arr::get($notification, 'settings');

            // Skip active check for on-demand notifications (offline payments, payment reminders)
            if (in_array($notification['event'], ['order_placed_offline', 'renewal_reminder_overdue']) || Arr::get($settings, 'active') === 'yes') {
                // Continue with normal flow
            } else {
                continue;
            }
            $recipient = Arr::get($notification, 'recipient');

            // A notification that declares its own `to` carries its recipient
            // with it, so the whitelist — which only describes the recipients
            // core knows how to resolve — does not apply to it.
            if (!Arr::get($notification, 'to') && !in_array($recipient, ['admin', 'customer', 'user', 'subscriber'])) {
                continue;
            }

            $toEmail = static::resolveRecipientTemplate($notification, $mailingSettings);

            if (empty($toEmail)) {
                continue;
            }

            $emails[$key] = static::formatNotification($notification, $viewData);
        }

        return $emails;
    }

    public static function formatNotification($notification, $viewData): array
    {
        $settings = Arr::get($notification, 'settings');
        $mailingSettings = static::getSettings();

        $recipient = Arr::get($notification, 'recipient');
        $toEmail = static::resolveRecipientTemplate($notification, $mailingSettings);


        $isDefaultEmailBody = Arr::get($settings, 'is_default_body', 'yes') === 'yes' || empty(Arr::get($settings, 'email_body'));

        $emailBody = $isDefaultEmailBody ?
            TemplateService::getTemplateByPathName(Arr::get($notification, 'template_path'), $viewData) :
            Arr::get($settings, 'email_body');

        return [
            'to'         => $toEmail,
            'body'       => $emailBody,
            'pre_header' => Arr::get($notification, 'pre_header', ''),
            'is_async'   => $notification['is_async'],
            'subject'    => Arr::get($settings, 'subject'),
            'is_custom'  => !$isDefaultEmailBody,
            'settings'   => $settings,
            // Carried through so send-time consumers can tell one notification
            // from another, and admin-bound mail from customer-bound — see the
            // admin recipient filter in parseEmailContent().
            'name'       => Arr::get($notification, 'name', ''),
            'event'      => Arr::get($notification, 'event', ''),
            'recipient'  => $recipient,
        ];
    }

    /**
     * Resolve the recipient template for a notification.
     *
     * A notification may declare its own `to` — a smartcode template, or a
     * literal address — which wins outright. Add-ons need this: they register
     * notifications that are not order-bound, and the built-in customer
     * recipient resolves to {{order.customer.email}}, which has nothing to read
     * from without an order.
     *
     * Falling back to the recipient map keeps every core notification, and any
     * add-on that does not declare `to`, on exactly the path it used before.
     *
     * The returned value is a template — parseEmailContent() runs it through
     * the shortcode parser before use. A declared `to` is returned as given:
     * wp_mail() accepts an array of recipients as well as a comma-separated
     * string, so an array is passed through rather than flattened.
     *
     * @param array $notification
     * @param array|null $mailingSettings resolved settings, passed in to avoid a repeat lookup in loops
     * @return string|array
     */
    public static function resolveRecipientTemplate($notification, $mailingSettings = null)
    {
        $declaredTo = Arr::get($notification, 'to', '');
        if (!empty($declaredTo)) {
            return $declaredTo;
        }

        if ($mailingSettings === null) {
            $mailingSettings = static::getSettings();
        }

        $recipient = Arr::get($notification, 'recipient');

        if ($recipient === 'admin') {
            return (string)Arr::get($mailingSettings, 'admin_email', '');
        }

        return (string)Arr::get(self::EMAIL_RECIPIENT_MAP, $recipient, '');
    }

    public static function getNotification($name)
    {
        $notifications = static::getNotifications();
        return Arr::get($notifications, $name);
    }

    //returns only the notification settings
    public static function getNotificationConfig($notificationName = null)
    {
        $configs = self::getNotifications();
        if ($notificationName) {
            return Arr::get($configs, $notificationName . '.settings', []);
        }
        return Arr::pluck($configs, 'settings', 'name');
    }

    public static function updateNotification($name, $data)
    {
        $updateableKeys = [
            'active',
            'subject',
            'email_body',
            'is_default_body',
            'attach_pdf_template',
        ];
        $allConfig = static::getSettings();
        $config = static::getNotificationConfig($name);

        foreach ($updateableKeys as $key) {
            $defaultValue = Arr::get($config, $key, '');
            $config[$key] = Arr::get($data, $key, $defaultValue);
        }

        // When switching back to default body, clear the custom email body unless draft mode is enabled
        if (Arr::get($config, 'is_default_body') === 'yes' && !apply_filters('fluent_cart/keep_email_body_draft', false, ['notification_name' => $name])) {
            $config['email_body'] = '';
        }

        Arr::set($allConfig, 'notification_config.' . $name, $config);

        $isUpdate = Meta::query()->updateOrCreate(
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            ['meta_key' => static::META_KEY, 'object_type' => 'email_notification'],
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            ['meta_value' => $allConfig]
        );

        if ($isUpdate) {
            static::updateCache();
        }

        return static::getNotification($name);
    }

    public static function defaultSettings(): array
    {
        return [
            'from_name'         => '',
            'from_email'        => '',
            'reply_to_name'     => '',
            'reply_to_email'    => '',
            'email_footer'      => '',
            'show_email_footer' => 'yes',
            'admin_email'       => '{{wp.admin_email}}',
        ];
    }

    //returns all the settings
    public static function getSettings($key = null)
    {

        $defaultSettings = static::defaultSettings();
        $cachedSettings = static::cachedSettings();
        $settings = wp_parse_args($cachedSettings, $defaultSettings);

        $settings['notification_config'] = wp_parse_args(
            Arr::get($cachedSettings, 'notification_config', []),
            Arr::get($defaultSettings, 'notification_config', [])
        );

        if (!empty($key)) {
            return Arr::get($settings, $key);
        }

        return $settings;
    }

    public static function cachedSettings()
    {
        return Cache::get(static::META_KEY, function () {
            $config = Meta::query()->where('object_type', 'email_notification')
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                ->where('meta_key', static::META_KEY)->first();
            return $config ? $config->meta_value : [];
        });
    }

    public static function updateSettings($data)
    {
        $allConfig = static::getSettings();

        foreach ($data as $key => $value) {
            Arr::set($allConfig, $key, $value);
        }

        $config = Meta::query()->updateOrCreate(
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            ['meta_key' => static::META_KEY, 'object_type' => 'email_notification'],
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            ['meta_value' => $allConfig]
        );

        static::updateCache();
        return $config;
    }

    private static function updateCache(): void
    {
        Cache::forget(static::META_KEY);
    }
}
