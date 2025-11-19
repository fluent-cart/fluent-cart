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
            $keyConfig = Arr::get($config, $key, []);
            if (!$keyConfig) {
                continue;
            }

            $setting['settings'] = wp_parse_args($keyConfig, $setting['settings']);
        }

        return $settings;
    }

    public static function getDefaultNotifications(): array
    {

        /**
         * Order Placed -> fluent_cart/order_paid (customer + admin)
         * Order Renewal -> fluent_cart/subscription_renewed (customer + admin)
         * Order Refunded -> fluent_cart/order_fully_refunded (customer + admin)
         * Order Shipping Status Changed => fluent_cart/shipping_status_changed (customer + admin)
         */

        return [
            'order_paid_admin'              => [
                'event'            => 'order_paid_done',
                'title'            => __('Send mail to admin after New Order Paid', 'fluent-cart'),
                'description'      => __('This email will be sent to the admin after an order is placed.', 'fluent-cart'),
                'recipient'        => 'admin',
                'smartcode_groups' => [],
                'template_path'    => 'order.paid.admin',
                'is_async'         => false,
                'pre_header'       => 'You got a new order on your shop. Congratulations! Checkout all the details in this email. You can also go to FluentCart Dashboard to view the order details and manage it. Thank you for using FluentCart.',
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('New Sales On {{settings.store_name}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
            'order_paid_customer'           => [
                'event'            => 'order_paid',
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
                ]
            ],
            'subscription_renewal_customer' => [
                'event'            => 'subscription_renewed',
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
                ]
            ],
            'subscription_renewal_admin'    => [
                'event'            => 'subscription_renewed',
                'title'            => __('Send mail to admin after a subscription renewed', 'fluent-cart'),
                'description'      => __('This email will be sent to the admin after a renewal payment made', 'fluent-cart'),
                'recipient'        => 'admin',
                'smartcode_groups' => [],
                'template_path'    => 'subscription.renewal.admin',
                'pre_header'       => 'You got a new Renewal on your shop. Congratulations! Checkout all the details in this email. You can also go to FluentCart Dashboard to view the order details and manage it. Thank you for using FluentCart.',
                'is_async'         => false,
                'settings'         => [
                    'active'          => 'yes',
                    'subject'         => __('New Renewal On {{settings.store_name}}', 'fluent-cart'),
                    'is_default_body' => 'yes',
                    'email_body'      => '',
                ]
            ],
            'order_refunded_admin'          => [
                'event'            => 'order_refunded',
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
                ]
            ],
            'order_refunded_customer'       => [
                'event'            => 'order_refunded',
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
                ]
            ],
            'order_shipped_customer'        => [
                'event'            => 'shipping_status_changed_to_shipped',
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
                ]
            ],
            'order_delivered_customer'      => [
                'event'            => 'shipping_status_changed_to_delivered',
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

            if (Arr::get($settings, 'active') !== 'yes') {
                continue;
            }
            $recipient = Arr::get($notification, 'recipient');

            if (!in_array($recipient, ['admin', 'customer', 'user', 'subscriber'])) {
                continue;
            }

            $recipient = Arr::get($notification, 'recipient');
            if ($recipient === 'admin') {
                $toEmail = Arr::get($mailingSettings, 'admin_email', '');
            } else {
                $toEmail = self::EMAIL_RECIPIENT_MAP[$recipient];
            }

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
        if ($recipient === 'admin') {
            $toEmail = Arr::get($mailingSettings, 'admin_email', '');
        } else {
            $toEmail = self::EMAIL_RECIPIENT_MAP[$recipient];
        }


        $isDefaultEmailBody = Arr::get($settings, 'is_default_body', 'yes') === 'yes' || empty(Arr::get($settings, 'email_body'));

        $emailBody = $isDefaultEmailBody ?
            TemplateService::getTemplateByPathName(Arr::get($notification, 'template_path'), $viewData) :
            Arr::get($settings, 'email_body');

        return [
            'to'       => $toEmail,
            'body'     => $emailBody,
            'pre_header' => Arr::get($notification, 'pre_header', ''),
            'is_async' => $notification['is_async'],
            'subject'  => Arr::get($settings, 'subject'),
            'is_custom' => !$isDefaultEmailBody
        ];
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
            'is_default_body'
        ];
        $allConfig = static::getSettings();
        $config = static::getNotificationConfig($name);

        foreach ($updateableKeys as $key) {
            $defaultValue = Arr::get($config, $key, '');
            $config[$key] = Arr::get($data, $key, $defaultValue);
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
            'admin_email'       => '{{wp.admin_email}}'
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
