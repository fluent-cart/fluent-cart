<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Reminders\InvoiceReminderService;
use FluentCart\App\Services\Reminders\ReminderService;
use FluentCart\App\Services\Reminders\SubscriptionReminderService;
use FluentCart\Framework\Support\Arr;

class ReminderHandler
{
    public function register(): void
    {
        add_action('fluent_cart/scheduler/hourly_tasks', [$this, 'scan'], 40);

        add_action(SubscriptionReminderService::RENEWAL_ASYNC_HOOK, [$this, 'sendSubscriptionRenewalReminder'], 10, 3);
        add_action(SubscriptionReminderService::TRIAL_ASYNC_HOOK, [$this, 'sendSubscriptionTrialReminder'], 10, 3);

        add_filter('fluent_cart/store_settings/values', [$this, 'addDefaultStoreSettings'], 10, 2);
        add_filter('fluent_cart/store_settings/fields', [$this, 'addStoreSettingFields'], 10, 2);
        add_filter('fluent_cart/store_settings/sanitizer', [$this, 'addStoreSettingSanitizers']);

        add_action('fluent_cart/order_paid', [$this, 'clearOrderReminderState'], 10, 1);
        add_action('fluent_cart/order_refunded', [$this, 'clearOrderReminderState'], 10, 1);

        add_action('fluent_cart/payments/subscription_cancelled', [$this, 'clearSubscriptionReminderState'], 10, 1);
        add_action('fluent_cart/payments/subscription_expired', [$this, 'clearSubscriptionReminderState'], 10, 1);
    }

    public function scan(): void
    {
        (new ReminderService())->runHourlyScan();
    }

    public function sendSubscriptionRenewalReminder($subscriptionId, $stage, $cycleKey): void
    {
        (new SubscriptionReminderService())->sendRenewal($subscriptionId, $stage, $cycleKey);
    }

    public function sendSubscriptionTrialReminder($subscriptionId, $stage, $cycleKey): void
    {
        (new SubscriptionReminderService())->sendTrial($subscriptionId, $stage, $cycleKey);
    }

    public function addDefaultStoreSettings($defaults): array
    {
        if (!is_array($defaults)) {
            $defaults = [];
        }

        return wp_parse_args($defaults, [
            'reminders_enabled'                       => 'no',
            // Yearly renewal reminders (enabled by default - compliance)
            'yearly_renewal_reminders_enabled'        => 'yes',
            'yearly_renewal_reminder_days'            => '30',
            // Trial end reminders (enabled by default - conversion)
            'trial_end_reminders_enabled'             => 'yes',
            'trial_end_reminder_days'                 => '3',
            // Optional additional billing cycles
            'monthly_renewal_reminders_enabled'       => 'no',
            'monthly_renewal_reminder_days'           => '7',
            'quarterly_renewal_reminders_enabled'     => 'no',
            'quarterly_renewal_reminder_days'         => '14',
            'half_yearly_renewal_reminders_enabled'   => 'no',
            'half_yearly_renewal_reminder_days'       => '21',
        ]);
    }

    public function addStoreSettingFields($fields): array
    {
        $tabsPath = 'setting_tabs.schema';
        $tabs = Arr::get($fields, $tabsPath, []);

        if (!is_array($tabs)) {
            return $fields;
        }

        $enabledCondition = [
            [
                'key'      => 'reminders_enabled',
                'operator' => '==',
                'value'    => 'yes',
            ],
        ];

        $tabs['reminders'] = [
            'title'           => __('Reminder Emails', 'fluent-cart'),
            'show_title'      => false,
            'type'            => 'section',
            'columns'         => [
                'default' => 1,
                'md'      => 1
            ],
            'disable_nesting' => true,
            'schema'          => [
                // Master switch row
                'intro_grid' => [
                    'type'            => 'grid',
                    'columns'         => [
                        'default' => 1,
                        'md'      => 3
                    ],
                    'disable_nesting' => true,
                    'schema'          => [
                        'label'             => [
                            'type'  => 'html',
                            'value' => '<span class="setting-label">' . __('Reminder Emails', 'fluent-cart') . '</span>
                                <div class="form-note">' . __('Send automated reminders for Subscription trial ending and upcoming subscription renewals.', 'fluent-cart') . '</div>'
                        ],
                        'reminders_enabled' => [
                            'wrapperClass' => 'col-span-2 flex items-start justify-end',
                            'label'        => false,
                            'type'         => 'switch',
                            'value'        => 'no',
                            'attributes'   => [
                                'active-value'   => 'yes',
                                'inactive-value' => 'no',
                            ],
                        ],
                    ],
                ],

                'email_notice' => [
                    'conditions' => $enabledCondition,
                    'type'       => 'html',
                    'value'      => '<div class="fc_reminder_email_notice" style="background: #f0f6ff; border: 1px solid #d0e2ff; border-radius: 6px; padding: 10px 14px; font-size: 13px; color: #1e3a5f;">'
                        . sprintf(
                            /* translators: %s is the opening and closing anchor tag */
                            __('Reminder emails must also be enabled in %1$sEmail Notification Settings%2$s under "Scheduler / Reminder Actions" to be delivered.', 'fluent-cart'),
                            '<a href="#/settings/email_notifications" style="color: #017EF3; text-decoration: underline;">',
                            '</a>'
                        )
                        . '</div>'
                ],

                // ── Subscription Reminders Group ──
                'subscription_hr' => [
                    'conditions' => $enabledCondition,
                    'type'       => 'html',
                    'value'      => '<hr class="settings-divider">'
                ],
                'subscription_group_label' => [
                    'conditions'   => $enabledCondition,
                    'type'         => 'html',
                    'wrapperClass' => 'mb-4',
                    'value'        => '<span class="setting-label" style="font-size: 15px;">' . __('Subscription Reminders', 'fluent-cart') . '</span>
                        <div class="form-note">' . __('Send reminders before subscription renewals and trial expirations.', 'fluent-cart') . '</div>'
                ],
                'trial_inner_hr' => [
                    'conditions' => $enabledCondition,
                    'type'       => 'html',
                    'value'      => '<hr class="settings-divider">'
                ],
                'trial_grid' => [
                    'conditions'      => $enabledCondition,
                    'type'            => 'grid',
                    'columns'         => [
                        'default' => 1,
                        'md'      => 3
                    ],
                    'disable_nesting' => true,
                    'schema'          => [
                        'label'            => [
                            'type'  => 'html',
                            'value' => '<span class="setting-label">' . __('Trial Ending', 'fluent-cart') . '</span>
                                <div class="form-note">' . __('Notify customers before their trial ends and billing begins.', 'fluent-cart') . '</div>'
                        ],
                        'trial_input_grid' => [
                            'type'            => 'grid',
                            'columns'         => ['default' => 1, 'md' => 1],
                            'disable_nesting' => true,
                            'class'           => 'col-span-2',
                            'schema'          => [
                                'trial_end_reminders_enabled' => [
                                    'label' => __('Enable', 'fluent-cart'),
                                    'type'  => 'checkbox',
                                    'value' => 'yes',
                                ],
                                'trial_end_reminder_days'     => [
                                    'label'      => false,
                                    'type'       => 'input',
                                    'value'      => '3',
                                    'note'       => __('Days before trial ends. Min: 1, Max: 14. Default: 3', 'fluent-cart'),
                                    'conditions' => [['key' => 'trial_end_reminders_enabled', 'operator' => '==', 'value' => 'yes']],
                                ],
                            ],
                        ],
                    ],
                ],
                'renewal_inner_hr' => [
                    'conditions' => $enabledCondition,
                    'type'       => 'html',
                    'value'      => '<hr class="settings-divider">'
                ],
                'renewal_grid' => [
                    'conditions'      => $enabledCondition,
                    'type'            => 'grid',
                    'columns'         => [
                        'default' => 1,
                        'md'      => 3
                    ],
                    'disable_nesting' => true,
                    'schema'          => [
                        'label'              => [
                            'type'  => 'html',
                            'value' => '<span class="setting-label">' . __('Renewal Reminders', 'fluent-cart') . '</span>
                                <div class="form-note">' . __('Notify customers before upcoming subscription renewals.', 'fluent-cart') . '</div>'
                        ],
                        'renewal_input_grid' => [
                            'type'            => 'grid',
                            'columns'         => ['default' => 1, 'md' => 1],
                            'disable_nesting' => true,
                            'class'           => 'col-span-2',
                            'schema'          => [
                                'yearly_renewal_reminders_enabled'      => [
                                    'label' => __('Yearly (Recommended)', 'fluent-cart'),
                                    'type'  => 'checkbox',
                                    'value' => 'yes',
                                ],
                                'yearly_renewal_reminder_days'          => [
                                    'label'        => false,
                                    'type'         => 'input',
                                    'value'        => '30',
                                    'wrapperClass' => 'mb-6',
                                    'note'         => __('Days before billing date. Min: 7, Max: 90. Default: 30', 'fluent-cart'),
                                    'conditions'   => [['key' => 'yearly_renewal_reminders_enabled', 'operator' => '==', 'value' => 'yes']],
                                ],
                                'half_yearly_renewal_reminders_enabled' => [
                                    'label' => __('Half Yearly', 'fluent-cart'),
                                    'type'  => 'checkbox',
                                    'value' => 'no',
                                ],
                                'half_yearly_renewal_reminder_days'     => [
                                    'label'        => false,
                                    'type'         => 'input',
                                    'value'        => '21',
                                    'wrapperClass' => 'mb-6',
                                    'note'         => __('Days before half yearly renewal. Min: 7, Max: 60. Default: 21', 'fluent-cart'),
                                    'conditions'   => [['key' => 'half_yearly_renewal_reminders_enabled', 'operator' => '==', 'value' => 'yes']],
                                ],
                                'quarterly_renewal_reminders_enabled'   => [
                                    'label' => __('Quarterly', 'fluent-cart'),
                                    'type'  => 'checkbox',
                                    'value' => 'no',
                                ],
                                'quarterly_renewal_reminder_days'       => [
                                    'label'        => false,
                                    'type'         => 'input',
                                    'value'        => '14',
                                    'wrapperClass' => 'mb-6',
                                    'note'         => __('Days before quarterly renewal. Min: 7, Max: 60. Default: 14', 'fluent-cart'),
                                    'conditions'   => [['key' => 'quarterly_renewal_reminders_enabled', 'operator' => '==', 'value' => 'yes']],
                                ],
                                'monthly_renewal_reminders_enabled'     => [
                                    'label' => __('Monthly', 'fluent-cart'),
                                    'type'  => 'checkbox',
                                    'value' => 'no',
                                ],
                                'monthly_renewal_reminder_days'         => [
                                    'label'        => false,
                                    'type'         => 'input',
                                    'value'        => '7',
                                    'wrapperClass' => 'mb-6',
                                    'note'         => __('Days before monthly renewal. Min: 3, Max: 28. Default: 7', 'fluent-cart'),
                                    'conditions'   => [['key' => 'monthly_renewal_reminders_enabled', 'operator' => '==', 'value' => 'yes']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Arr::set($fields, $tabsPath, $tabs);
        return $fields;
    }

    public function addStoreSettingSanitizers($sanitizers): array
    {
        if (!is_array($sanitizers)) {
            $sanitizers = [];
        }

        $sanitizers['reminders_enabled'] = 'sanitize_text_field';

        // Yearly renewal reminder sanitizers (default)
        $sanitizers['yearly_renewal_reminders_enabled'] = 'sanitize_text_field';
        $sanitizers['yearly_renewal_reminder_days'] = 'intval';

        // Trial end reminder sanitizers (default)
        $sanitizers['trial_end_reminders_enabled'] = 'sanitize_text_field';
        $sanitizers['trial_end_reminder_days'] = 'intval';

        // Optional billing cycle reminder sanitizers
        $sanitizers['monthly_renewal_reminders_enabled'] = 'sanitize_text_field';
        $sanitizers['monthly_renewal_reminder_days'] = 'intval';
        $sanitizers['quarterly_renewal_reminders_enabled'] = 'sanitize_text_field';
        $sanitizers['quarterly_renewal_reminder_days'] = 'intval';
        $sanitizers['half_yearly_renewal_reminders_enabled'] = 'sanitize_text_field';
        $sanitizers['half_yearly_renewal_reminder_days'] = 'intval';

        return $sanitizers;
    }

    public function clearOrderReminderState($data): void
    {
        try {
            $order = Arr::get($data, 'order');
            if (!$order instanceof Order) {
                return;
            }

            (new InvoiceReminderService())->clearState($order);
        } catch (\Throwable $e) {
            fluent_cart_error_log('Reminder state clear error', $e->getMessage());
        }
    }

    public function clearSubscriptionReminderState($data): void
    {
        try {
            $subscription = Arr::get($data, 'subscription');
            if (!$subscription instanceof Subscription) {
                return;
            }

            (new SubscriptionReminderService())->clearState($subscription);
        } catch (\Throwable $e) {
            fluent_cart_error_log('Subscription reminder state clear error', $e->getMessage());
        }
    }
}
