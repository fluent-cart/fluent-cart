<?php

namespace Tests\Integration\Services;

use FluentCart\App\Events\PlanChanged;
use FluentCart\App\Events\Subscription\SubscriptionPaymentMethodChanged;
use FluentCart\App\Models\Meta;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\Cache;
use FluentCart\App\Services\Email\EmailNotifications;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\EmailNotificationConfigFactory;
use Tests\Support\Factory\OrderFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;

class EmailNotificationsTest extends WPTestCase
{
    private EmailNotificationConfigFactory $configFactory;
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->configFactory = new EmailNotificationConfigFactory();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->configFactory->reset();
    }

    public function tearDown(): void
    {
        $this->configFactory->reset();
        parent::tearDown();
    }

    private function makeOrderFixture(): array
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        // Eager-load customer so views accessing $order->customer don't blow up
        $order = Order::query()->with('customer')->find($order->id);
        return ['order' => $order, 'customer' => $customer];
    }

    // =========================================================================
    // Spec 1: META_KEY constant
    // =========================================================================

    public function test_meta_key_constant_value(): void
    {
        $this->assertSame('email_notifications_config', EmailNotifications::META_KEY);
    }

    // =========================================================================
    // Spec 2: EMAIL_RECIPIENT_MAP placeholders
    // =========================================================================

    public function test_email_recipient_map_placeholders(): void
    {
        $this->assertSame('{{order.customer.email}}', EmailNotifications::EMAIL_RECIPIENT_MAP['customer']);
        $this->assertSame('{{user.user_email}}', EmailNotifications::EMAIL_RECIPIENT_MAP['user']);
        $this->assertSame('{{order.customer.email}}', EmailNotifications::EMAIL_RECIPIENT_MAP['subscriber']);
    }

    // =========================================================================
    // Spec 3: getDefaultNotifications returns expected keys
    // =========================================================================

    public function test_default_notifications_returns_all_expected_keys(): void
    {
        $defaults = EmailNotifications::getDefaultNotifications();

        // Independently specified, EXACT registry roster — never derived from the
        // registry itself. Exact equality (not a subset walk) makes both a
        // silently DELETED notification (e.g. dropping system_charge_failed_customer
        // would stop customer emails after failed automatic renewals) and an
        // undocumented addition fail this test.
        $expected = [
            'order_delivered_customer',
            'order_paid_admin',
            'order_paid_customer',
            'order_placed_admin',
            'order_placed_customer',
            'order_refunded_admin',
            'order_refunded_customer',
            'order_shipment_created_customer',
            'order_shipped_customer',
            'renewal_created_admin',
            'renewal_created_customer',
            'renewal_overdue_final_customer',
            'renewal_overdue_first_customer',
            'renewal_overdue_followup_customer',
            'renewal_reminder_due_admin',
            'renewal_reminder_due_customer',
            'renewal_reminder_overdue_customer',
            'review_approved_customer',
            'review_replied_customer',
            'review_submitted_admin',
            'subscription_canceled_admin',
            'subscription_canceled_customer',
            'subscription_past_due_admin',
            'subscription_past_due_customer',
            'subscription_payment_method_changed_admin',
            'subscription_payment_method_changed_customer',
            'subscription_period_skipped_customer',
            'subscription_plan_changed_admin',
            'subscription_plan_changed_customer',
            'subscription_renewal_admin',
            'subscription_renewal_customer',
            'subscription_renewal_failed_admin',
            'subscription_renewal_failed_customer',
            'subscription_renewal_reminder_admin',
            'subscription_renewal_reminder_customer',
            'subscription_trial_end_reminder_admin',
            'subscription_trial_end_reminder_customer',
            'system_charge_failed_admin',
            'system_charge_failed_customer',
            'system_upcoming_charge_customer',
        ];

        $actual = array_keys($defaults);
        sort($actual);
        $this->assertSame(
            $expected,
            $actual,
            'default notification registry must exactly match the independently specified roster — a missing key silently drops that email; an extra key is undocumented'
        );
    }

    public function test_renewal_engine_notifications_route_the_correct_event_and_recipient(): void
    {
        $defaults = EmailNotifications::getDefaultNotifications();

        $contracts = [
            'renewal_created_admin'         => ['renewal_created', 'admin'],
            'renewal_created_customer'      => ['renewal_created', 'customer'],
            'system_charge_failed_admin'    => ['system_charge_failed', 'admin'],
            'system_charge_failed_customer' => ['system_charge_failed', 'customer'],
        ];

        foreach ($contracts as $key => [$event, $recipient]) {
            $this->assertSame($event, $defaults[$key]['event'] ?? null, "$key must route event $event");
            $this->assertSame($recipient, $defaults[$key]['recipient'] ?? null, "$key must target the $recipient recipient");
        }
    }

    // =========================================================================
    // Spec 4: each notification has required keys
    // =========================================================================

    public function test_default_notifications_each_have_required_keys(): void
    {
        $defaults = EmailNotifications::getDefaultNotifications();
        $required = ['event', 'group', 'group_label', 'title', 'description', 'recipient', 'smartcode_groups', 'template_path', 'is_async', 'settings'];

        foreach ($defaults as $name => $notification) {
            foreach ($required as $key) {
                $this->assertArrayHasKey($key, $notification, "Notification $name missing key $key");
            }
        }
    }

    // =========================================================================
    // Spec 5: order_paid_admin shape
    // =========================================================================

    public function test_order_paid_admin_shape(): void
    {
        $defaults = EmailNotifications::getDefaultNotifications();
        $this->assertSame('order_paid_done', $defaults['order_paid_admin']['event']);
        $this->assertSame('admin', $defaults['order_paid_admin']['recipient']);
        $this->assertSame('order.paid.admin', $defaults['order_paid_admin']['template_path']);
    }

    // =========================================================================
    // Spec 6: order_paid_customer shape
    // =========================================================================

    public function test_order_paid_customer_shape(): void
    {
        $defaults = EmailNotifications::getDefaultNotifications();
        $this->assertSame('order_paid', $defaults['order_paid_customer']['event']);
        $this->assertSame('customer', $defaults['order_paid_customer']['recipient']);
        $this->assertSame('order.paid.customer', $defaults['order_paid_customer']['template_path']);
    }

    // =========================================================================
    // Spec 7: subscription_renewal_customer shape
    // =========================================================================

    public function test_subscription_renewal_customer_shape(): void
    {
        $defaults = EmailNotifications::getDefaultNotifications();
        $this->assertSame('subscription_renewed', $defaults['subscription_renewal_customer']['event']);
        $this->assertSame('customer', $defaults['subscription_renewal_customer']['recipient']);
    }

    // =========================================================================
    // subscription_period_skipped_customer shape — customer-only, off by default
    // =========================================================================

    public function test_subscription_period_skipped_customer_shape(): void
    {
        $defaults = EmailNotifications::getDefaultNotifications();
        $notification = $defaults['subscription_period_skipped_customer'];

        $this->assertSame('subscription_period_skipped', $notification['event']);
        $this->assertSame('customer', $notification['recipient']);
        $this->assertSame('subscription.period_skipped.customer', $notification['template_path']);
        $this->assertSame('no', $notification['settings']['active']);
    }

    // =========================================================================
    // subscription_period_skipped template resolves and leaks no audit data
    // =========================================================================

    public function test_subscription_period_skipped_email_has_no_internal_audit_data(): void
    {
        ['order' => $order] = $this->makeOrderFixture();

        $subscription = (new SubscriptionFactory())->create([
            'customer_id'       => $order->customer_id,
            'parent_order_id'   => $order->id,
            'product_id'        => 1,
            'variation_id'      => 1,
            'item_name'         => 'Skipped Plan Fixture',
            'collection_method' => 'manual',
            'next_billing_date' => gmdate('Y-m-d H:i:s', time() + (30 * DAY_IN_SECONDS)),
        ]);

        $reasonMarker = 'INTERNAL-AUDIT-REASON-9f3';
        $actorMarker  = 'Skipper Admin 9f3';
        $subscription->updateMeta('skipped_periods', [[
            'date'       => gmdate('Y-m-d H:i:s'),
            'resumes_at' => $subscription->next_billing_date,
            'skipped_at' => gmdate('Y-m-d H:i:s'),
            'reason'     => $reasonMarker,
            'actor_id'   => 1,
            'actor_name' => $actorMarker,
        ]]);

        $notifications = EmailNotifications::getNotifications();
        $formatted = EmailNotifications::formatNotification(
            $notifications['subscription_period_skipped_customer'],
            ['subscription' => $subscription, 'order' => $order]
        );

        $this->assertIsString($formatted['body']);
        $this->assertNotEmpty($formatted['body']);
        $this->assertStringContainsString('Skipped Plan Fixture', $formatted['body']);
        $this->assertStringNotContainsString($reasonMarker, $formatted['body']);
        $this->assertStringNotContainsString($actorMarker, $formatted['body']);
    }

    // =========================================================================
    // Payment method changed / plan changed: customer on, admin off
    // =========================================================================

    public function test_subscription_change_notifications_shape(): void
    {
        $defaults = EmailNotifications::getDefaultNotifications();

        foreach (['payment_method_changed', 'plan_changed'] as $name) {
            foreach (['customer' => 'yes', 'admin' => 'no'] as $recipient => $active) {
                $notification = $defaults['subscription_' . $name . '_' . $recipient];
                $this->assertSame('subscription_' . $name, $notification['event']);
                $this->assertSame($recipient, $notification['recipient']);
                $this->assertSame('subscription.' . $name . '.' . $recipient, $notification['template_path']);
                $this->assertSame($active, $notification['settings']['active']);
            }
        }
    }

    public function test_payment_method_changed_email_shows_masked_old_and_new_methods_only(): void
    {
        ['order' => $order] = $this->makeOrderFixture();
        $subscription = (new SubscriptionFactory())->create([
            'customer_id'     => $order->customer_id,
            'parent_order_id' => $order->id,
            'product_id'      => 1,
            'variation_id'    => 1,
            'item_name'       => 'Card Plan Fixture',
        ]);

        $notifications = EmailNotifications::getNotifications();
        foreach (['customer', 'admin'] as $recipient) {
            $formatted = EmailNotifications::formatNotification(
                $notifications['subscription_payment_method_changed_' . $recipient],
                [
                    'subscription'       => $subscription,
                    'order'              => $order,
                    'old_payment_method' => ['method' => 'paypal', 'details' => ['email' => 'buyer@example.com', 'payer_id' => 'PAYER9F3']],
                    'new_payment_method' => ['method' => 'stripe', 'details' => ['brand' => 'visa', 'last_4' => '4242', 'fingerprint' => 'FP9F3', 'payment_method_id' => 'pm_9f3']],
                ]
            );

            $body = html_entity_decode($formatted['body']);
            $this->assertStringContainsString('Card Plan Fixture', $body);
            $this->assertStringContainsString('Visa •••• 4242', $body);
            $this->assertStringContainsString('(buyer@example.com)', $body);
            foreach (['FP9F3', 'pm_9f3', 'PAYER9F3'] as $secret) {
                $this->assertStringNotContainsString($secret, $body);
            }
        }
    }

    public function test_payment_method_changed_event_decodes_stored_meta_and_names_the_change(): void
    {
        ['order' => $order] = $this->makeOrderFixture();
        $subscription = (new SubscriptionFactory())->create([
            'customer_id'     => $order->customer_id,
            'parent_order_id' => $order->id,
            'product_id'      => 1,
            'variation_id'    => 1,
        ]);

        $payloads = [];
        $capture = function ($data) use (&$payloads) {
            $payloads[] = $data;
        };
        add_action('fluent_cart/subscription_payment_method_changed', $capture, 1);
        add_filter('pre_wp_mail', '__return_true');

        try {
            $new = ['method' => 'stripe', 'details' => ['brand' => 'visa', 'last_4' => '4242']];
            SubscriptionPaymentMethodChanged::fire($subscription, json_encode(['method' => 'stripe', 'details' => ['last_4' => '1111']]), $new, 'stripe');
            SubscriptionPaymentMethodChanged::fire($subscription, '', $new, 'paypal');
        } finally {
            remove_action('fluent_cart/subscription_payment_method_changed', $capture, 1);
            remove_filter('pre_wp_mail', '__return_true');
        }

        $this->assertCount(2, $payloads);
        $this->assertSame('update', $payloads[0]['change']);
        $this->assertSame('1111', $payloads[0]['old_payment_method']['details']['last_4']);
        $this->assertSame('switch', $payloads[1]['change']);
        $this->assertSame(['method' => 'paypal'], $payloads[1]['old_payment_method']);
    }

    public function test_plan_changed_event_sends_one_customer_email_with_previous_and_new_plan(): void
    {
        ['order' => $order] = $this->makeOrderFixture();
        $product = (new ProductFactory())->createWithVariants([
            ['variation_title' => 'Monthly Tier 9f3'],
            ['variation_title' => 'Yearly Tier 9f3'],
        ], ['post_title' => 'Plan Product 9f3']);
        [$oldVariation, $newVariation] = ProductVariation::query()->where('post_id', $product->ID)->orderBy('serial_index')->get()->all();

        $oldSubscription = (new SubscriptionFactory())->create([
            'customer_id'      => $order->customer_id,
            'parent_order_id'  => $order->id,
            'product_id'       => $product->ID,
            'variation_id'     => $oldVariation->id,
            'billing_interval' => 'monthly',
            'recurring_total'  => 900,
        ]);
        $newSubscription = (new SubscriptionFactory())->create([
            'customer_id'      => $order->customer_id,
            'parent_order_id'  => $order->id,
            'product_id'       => $product->ID,
            'variation_id'     => $newVariation->id,
            'billing_interval' => 'yearly',
            'recurring_total'  => 9900,
        ]);

        $sent = [];
        $capture = function ($null, array $atts) use (&$sent) {
            $sent[] = $atts;
            return true;
        };
        add_filter('pre_wp_mail', $capture, 10, 2);

        try {
            (new PlanChanged($order, $newVariation, $oldVariation, [
                'from_order_id'        => $order->id,
                'from_subscription_id' => $oldSubscription->id,
                'subscription_id'      => $newSubscription->id,
            ]))->dispatch();
        } finally {
            remove_filter('pre_wp_mail', $capture, 10);
        }

        $planMails = array_values(array_filter($sent, function ($mail) {
            return strpos(html_entity_decode($mail['message']), 'Yearly Tier 9f3') !== false;
        }));
        $this->assertCount(1, $planMails, 'admin copy is off by default, so only the customer gets the plan email');
        $this->assertStringContainsString($order->customer->email, implode(',', (array) $planMails[0]['to']));

        $body = html_entity_decode($planMails[0]['message']);
        $this->assertStringContainsString('Plan Product 9f3 - Monthly Tier 9f3', $body);
        $this->assertStringContainsString('Plan Product 9f3 - Yearly Tier 9f3', $body);
        $this->assertStringContainsString(html_entity_decode(wp_kses_post($oldSubscription->payment_info)), $body);
        $this->assertStringContainsString(html_entity_decode(wp_kses_post($newSubscription->payment_info)), $body);
    }

    public function test_plan_changed_summary_uses_billing_schedule_text_and_order_timezone(): void
    {
        $originalDateFormat = get_option('date_format');
        $originalSettings = get_option('fluent_cart_store_settings', []);

        try {
            update_option('date_format', 'Y-m-d');
            $settings = $originalSettings;
            $settings['timezone_source'] = 'fluent_cart';
            update_option('fluent_cart_store_settings', $settings);
            \FluentCart\Api\StoreSettings::clearCache();
            \FluentCart\App\Services\Translations\TransStrings::dateTimeStrings(true);

            ['order' => $order] = $this->makeOrderFixture();
            $order->config = ['user_tz' => 'America/Los_Angeles'];

            $newSubscription = (new SubscriptionFactory())->create([
                'customer_id'       => $order->customer_id,
                'parent_order_id'   => $order->id,
                'product_id'        => 1,
                'variation_id'      => 1,
                'billing_interval'  => 'yearly',
                'bill_times'        => 3,
                'recurring_total'   => 10000,
                'next_billing_date' => '2027-01-01 03:00:00',
                'config'            => ['billing_schedule' => ['period' => 'year', 'interval' => 3]],
            ]);

            $formatted = EmailNotifications::formatNotification(
                EmailNotifications::getNotifications()['subscription_plan_changed_customer'],
                [
                    'order'            => $order,
                    'subscription'     => $newSubscription,
                    'old_subscription' => null,
                    'old_variation'    => null,
                    'new_variation'    => null,
                ]
            );
            $body = html_entity_decode($formatted['body']);

            $this->assertStringContainsString(html_entity_decode(wp_kses_post($newSubscription->payment_info)), $body);
            $this->assertStringNotContainsString('(yearly)', $body);
            $this->assertStringContainsString('2026-12-31', $body, 'next payment renders in the order timezone');
            $this->assertStringNotContainsString('2027-01-01', $body);
        } finally {
            update_option('date_format', $originalDateFormat);
            update_option('fluent_cart_store_settings', $originalSettings);
            \FluentCart\Api\StoreSettings::clearCache();
            \FluentCart\App\Services\Translations\TransStrings::dateTimeStrings(true);
        }
    }

    // =========================================================================
    // Spec 8: order_refunded_customer default active
    // =========================================================================

    public function test_order_refunded_customer_active_yes(): void
    {
        $defaults = EmailNotifications::getDefaultNotifications();
        $this->assertSame('order_refunded', $defaults['order_refunded_customer']['event']);
        $this->assertSame('yes', $defaults['order_refunded_customer']['settings']['active']);
    }

    // =========================================================================
    // Spec 9: order_refunded_admin default active=no
    // =========================================================================

    public function test_order_refunded_admin_active_no(): void
    {
        $defaults = EmailNotifications::getDefaultNotifications();
        $this->assertSame('no', $defaults['order_refunded_admin']['settings']['active']);
    }

    // =========================================================================
    // Spec 10: order_placed manage_toggle=no
    // =========================================================================

    public function test_order_placed_notifications_have_manage_toggle_no(): void
    {
        $defaults = EmailNotifications::getDefaultNotifications();
        $this->assertSame('no', $defaults['order_placed_admin']['manage_toggle']);
        $this->assertSame('no', $defaults['order_placed_customer']['manage_toggle']);
    }

    // =========================================================================
    // Spec 11: invoice reminder manage_toggle=no
    // =========================================================================

    public function test_renewal_reminder_overdue_manage_toggle_no(): void
    {
        $defaults = EmailNotifications::getDefaultNotifications();
        $this->assertSame('no', $defaults['renewal_reminder_overdue_customer']['manage_toggle']);
    }

    // =========================================================================
    // Spec 12: getNotifications applies filter fluent_cart/email_notifications
    // =========================================================================

    public function test_get_notifications_applies_filter(): void
    {
        $marker = 'filter_marker_' . uniqid();
        add_filter('fluent_cart/email_notifications', function ($settings) use ($marker) {
            $settings[$marker] = [
                'event'         => 'custom_event',
                'recipient'     => 'admin',
                'template_path' => 'custom.path',
                'is_async'      => false,
                'settings'      => ['active' => 'no', 'subject' => 'x', 'is_default_body' => 'yes', 'email_body' => ''],
            ];
            return $settings;
        });

        try {
            $notifications = EmailNotifications::getNotifications();
            $this->assertArrayHasKey($marker, $notifications);
        } finally {
            remove_all_filters('fluent_cart/email_notifications');
        }
    }

    // =========================================================================
    // Spec 13: name key matches array key
    // =========================================================================

    public function test_get_notifications_injects_name_key(): void
    {
        $notifications = EmailNotifications::getNotifications();
        foreach ($notifications as $key => $notification) {
            $this->assertSame($key, $notification['name']);
        }
    }

    // =========================================================================
    // Spec 14: empty group_label defaults to "Other Actions"
    // =========================================================================

    public function test_empty_group_label_defaults_to_other_actions(): void
    {
        add_filter('fluent_cart/email_notifications', function ($settings) {
            $settings['custom_no_label'] = [
                'event'         => 'x',
                'recipient'     => 'admin',
                'template_path' => 'x',
                'group_label'   => '',
                'is_async'      => false,
                'settings'      => ['active' => 'yes', 'subject' => 'x', 'is_default_body' => 'yes', 'email_body' => ''],
            ];
            return $settings;
        });

        try {
            $notifications = EmailNotifications::getNotifications();
            $this->assertSame('Other Actions', $notifications['custom_no_label']['group_label']);
        } finally {
            remove_all_filters('fluent_cart/email_notifications');
        }
    }

    // =========================================================================
    // Spec 15: persisted notification_config merges into settings
    // =========================================================================

    public function test_persisted_notification_config_merges(): void
    {
        $this->configFactory->withNotificationConfig('order_paid_customer', [
            'subject' => 'Custom Subject Override',
            'active'  => 'no',
        ]);

        $notifications = EmailNotifications::getNotifications();
        $this->assertSame('Custom Subject Override', $notifications['order_paid_customer']['settings']['subject']);
        $this->assertSame('no', $notifications['order_paid_customer']['settings']['active']);
    }

    // =========================================================================
    // Spec 16: backward compat attach_pdf=yes migrates to attach_pdf_template=order_receipt
    // =========================================================================

    public function test_attach_pdf_yes_migrates_to_attach_pdf_template(): void
    {
        $this->configFactory->withNotificationConfig('order_paid_customer', [
            'attach_pdf' => 'yes',
        ]);

        $notifications = EmailNotifications::getNotifications();
        $this->assertSame('order_receipt', $notifications['order_paid_customer']['settings']['attach_pdf_template']);
    }

    // =========================================================================
    // Spec 17: attach_pdf key removed from settings after migration
    // =========================================================================

    public function test_attach_pdf_key_removed_after_migration(): void
    {
        $this->configFactory->withNotificationConfig('order_paid_customer', [
            'attach_pdf' => 'yes',
        ]);

        $notifications = EmailNotifications::getNotifications();
        $this->assertArrayNotHasKey('attach_pdf', $notifications['order_paid_customer']['settings']);
    }

    // =========================================================================
    // Spec 18: getNotificationsOfEvent filters by event
    // =========================================================================

    public function test_get_notifications_of_event_filters_by_event(): void
    {
        $emails = EmailNotifications::getNotificationsOfEvent('order_paid', $this->makeOrderFixture());
        $this->assertArrayHasKey('order_paid_customer', $emails);
        $this->assertArrayNotHasKey('order_paid_admin', $emails); // event=order_paid_done
        $this->assertArrayNotHasKey('subscription_renewal_customer', $emails);
    }

    // =========================================================================
    // Spec 19: empty result when no event matches
    // =========================================================================

    public function test_get_notifications_of_event_empty_result(): void
    {
        $emails = EmailNotifications::getNotificationsOfEvent('non_existent_event_xyz', $this->makeOrderFixture());
        $this->assertSame([], $emails);
    }

    // =========================================================================
    // Spec 20: skips notification when active!='yes' and not in toggle-bypass list
    // =========================================================================

    public function test_skips_inactive_notification_outside_bypass_list(): void
    {
        $this->configFactory->withNotificationConfig('order_paid_customer', [
            'active' => 'no',
        ]);

        $emails = EmailNotifications::getNotificationsOfEvent('order_paid', $this->makeOrderFixture());
        $this->assertArrayNotHasKey('order_paid_customer', $emails);
    }

    // =========================================================================
    // Spec 21: includes order_placed_offline regardless of active
    // =========================================================================

    public function test_includes_order_placed_offline_regardless_of_active(): void
    {
        // order_placed_customer event=order_placed_offline; default has no active flag (manage_toggle=no)
        $emails = EmailNotifications::getNotificationsOfEvent('order_placed_offline', $this->makeOrderFixture());
        $this->assertArrayHasKey('order_placed_customer', $emails);
        $this->assertArrayHasKey('order_placed_admin', $emails);
    }

    // =========================================================================
    // Spec 22: includes renewal_reminder_overdue regardless of active
    // =========================================================================

    public function test_includes_renewal_reminder_overdue_regardless_of_active(): void
    {
        $emails = EmailNotifications::getNotificationsOfEvent('renewal_reminder_overdue', $this->makeOrderFixture());
        $this->assertArrayHasKey('renewal_reminder_overdue_customer', $emails);
    }

    // =========================================================================
    // Overdue stage entries: default bodies escalate and respect their toggles
    // =========================================================================

    private function makeOverdueReminderFixture(string $stage): array
    {
        $data = $this->makeOrderFixture();
        $data['reminder'] = [
            'stage'        => $stage,
            'due_at'       => gmdate('Y-m-d H:i:s'),
            'due_amount'   => 2999,
            'payment_link' => 'https://example.com/pay-now',
            'order_ref'    => '#OVR-1',
        ];

        return $data;
    }

    public function test_overdue_first_entry_renders_gentle_default_body(): void
    {
        $emails = EmailNotifications::getNotificationsOfEvent('renewal_overdue_first', $this->makeOverdueReminderFixture('overdue_1'));

        $this->assertArrayHasKey('renewal_overdue_first_customer', $emails);
        $body = $emails['renewal_overdue_first_customer']['body'];
        $this->assertStringContainsString('Just a nudge', $body);
        $this->assertStringContainsString('https://example.com/pay-now', $body);
        $this->assertStringNotContainsString('suspended', $body);
    }

    public function test_overdue_followup_entry_renders_day_count_default_body(): void
    {
        $emails = EmailNotifications::getNotificationsOfEvent('renewal_overdue_followup', $this->makeOverdueReminderFixture('overdue_3'));

        $this->assertArrayHasKey('renewal_overdue_followup_customer', $emails);
        $body = $emails['renewal_overdue_followup_customer']['body'];
        $this->assertStringContainsString('3 days overdue', $body);
        $this->assertStringContainsString('https://example.com/pay-now', $body);
        $this->assertStringNotContainsString('suspended', $body);
    }

    public function test_overdue_final_entry_renders_suspension_default_body(): void
    {
        $emails = EmailNotifications::getNotificationsOfEvent('renewal_overdue_final', $this->makeOverdueReminderFixture('overdue_7'));

        $this->assertArrayHasKey('renewal_overdue_final_customer', $emails);
        $body = $emails['renewal_overdue_final_customer']['body'];
        $this->assertStringContainsString('7 days overdue', $body);
        $this->assertStringContainsString('will be suspended', $body);
        $this->assertStringContainsString('https://example.com/pay-now', $body);
    }

    public function test_overdue_stage_entry_respects_active_toggle(): void
    {
        $this->configFactory->withNotificationConfig('renewal_overdue_final_customer', ['active' => 'no']);

        $emails = EmailNotifications::getNotificationsOfEvent('renewal_overdue_final', $this->makeOverdueReminderFixture('overdue_7'));

        $this->assertArrayNotHasKey('renewal_overdue_final_customer', $emails);
    }

    // =========================================================================
    // Spec 23: skips when recipient not in valid list
    // =========================================================================

    public function test_skips_when_recipient_invalid(): void
    {
        add_filter('fluent_cart/email_notifications', function ($settings) {
            $settings['bad_recipient'] = [
                'event'         => 'custom_x',
                'recipient'     => 'unknown_role',
                'template_path' => 'x',
                'is_async'      => false,
                'settings'      => ['active' => 'yes', 'subject' => 'x', 'is_default_body' => 'no', 'email_body' => 'X'],
            ];
            return $settings;
        });

        try {
            $emails = EmailNotifications::getNotificationsOfEvent('custom_x', $this->makeOrderFixture());
            $this->assertArrayNotHasKey('bad_recipient', $emails);
        } finally {
            remove_all_filters('fluent_cart/email_notifications');
        }
    }

    // =========================================================================
    // Spec 24: admin recipient resolves to admin_email setting
    // =========================================================================

    public function test_admin_recipient_resolves_to_admin_email_setting(): void
    {
        $this->configFactory->create([
            'admin_email' => 'admin@example.test',
        ]);

        $emails = EmailNotifications::getNotificationsOfEvent('order_paid_done', $this->makeOrderFixture());
        $this->assertSame('admin@example.test', $emails['order_paid_admin']['to']);
    }

    // =========================================================================
    // Spec 25: customer recipient resolves to placeholder
    // =========================================================================

    public function test_customer_recipient_resolves_to_placeholder(): void
    {
        $emails = EmailNotifications::getNotificationsOfEvent('order_paid', $this->makeOrderFixture());
        $this->assertSame('{{order.customer.email}}', $emails['order_paid_customer']['to']);
    }

    // =========================================================================
    // Spec 26: skips when admin_email empty
    // =========================================================================

    public function test_skips_admin_recipient_when_admin_email_empty(): void
    {
        $this->configFactory->create([
            'admin_email' => '',
        ]);

        $emails = EmailNotifications::getNotificationsOfEvent('order_paid_done', $this->makeOrderFixture());
        $this->assertArrayNotHasKey('order_paid_admin', $emails);
    }

    // =========================================================================
    // Spec 27: formatNotification returns expected keys
    // =========================================================================

    public function test_format_notification_returns_expected_keys(): void
    {
        $notifications = EmailNotifications::getNotifications();
        $formatted = EmailNotifications::formatNotification($notifications['order_paid_customer'], $this->makeOrderFixture());

        $expected = ['to', 'body', 'pre_header', 'is_async', 'subject', 'is_custom', 'settings'];
        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, $formatted);
        }
    }

    // =========================================================================
    // Spec 28: is_custom=false when is_default_body=yes OR email_body empty
    // =========================================================================

    public function test_is_custom_false_when_default_body_or_empty(): void
    {
        $notification = [
            'recipient'     => 'customer',
            'template_path' => 'order.paid.customer',
            'pre_header'    => '',
            'is_async'      => false,
            'settings'      => [
                'is_default_body' => 'yes',
                'email_body'      => '',
                'subject'         => 'X',
            ],
        ];

        $formatted = EmailNotifications::formatNotification($notification, $this->makeOrderFixture());
        $this->assertFalse($formatted['is_custom']);
    }

    // =========================================================================
    // Spec 29: is_custom=true when is_default_body!=yes and email_body non-empty
    // =========================================================================

    public function test_is_custom_true_when_custom_body(): void
    {
        $notification = [
            'recipient'     => 'customer',
            'template_path' => 'order.paid.customer',
            'pre_header'    => '',
            'is_async'      => false,
            'settings'      => [
                'is_default_body' => 'no',
                'email_body'      => '<p>Custom HTML body</p>',
                'subject'         => 'X',
            ],
        ];

        // is_default_body=no + non-empty email_body => template render path skipped; no order data needed.
        $formatted = EmailNotifications::formatNotification($notification, []);
        $this->assertTrue($formatted['is_custom']);
    }

    // =========================================================================
    // Spec 30: default body resolves via TemplateService::getTemplateByPathName
    // =========================================================================

    public function test_default_body_resolves_via_template_service(): void
    {
        $notification = [
            'recipient'     => 'customer',
            'template_path' => 'order.paid.customer',
            'pre_header'    => '',
            'is_async'      => false,
            'settings'      => [
                'is_default_body' => 'yes',
                'email_body'      => '',
                'subject'         => 'X',
            ],
        ];

        $formatted = EmailNotifications::formatNotification($notification, $this->makeOrderFixture());
        $this->assertIsString($formatted['body']);
        $this->assertNotEmpty($formatted['body']);
    }

    // =========================================================================
    // Spec 31: custom body uses email_body verbatim
    // =========================================================================

    public function test_custom_body_uses_email_body_verbatim(): void
    {
        $custom = '<p>This is my custom marker xyz123</p>';
        $notification = [
            'recipient'     => 'customer',
            'template_path' => 'order.paid.customer',
            'pre_header'    => '',
            'is_async'      => false,
            'settings'      => [
                'is_default_body' => 'no',
                'email_body'      => $custom,
                'subject'         => 'X',
            ],
        ];

        $formatted = EmailNotifications::formatNotification($notification, ['order' => null]);
        $this->assertSame($custom, $formatted['body']);
    }

    // =========================================================================
    // Spec 32: updateNotification persists only whitelisted keys
    // =========================================================================

    public function test_update_notification_persists_only_whitelisted_keys(): void
    {
        $updated = EmailNotifications::updateNotification('order_paid_customer', [
            'subject'             => 'New Subject',
            'email_body'          => '<p>Body</p>',
            'is_default_body'     => 'no',
            'active'              => 'yes',
            'attach_pdf_template' => 'order_receipt',
            'unsupported_key'     => 'should not persist',
        ]);

        $row = Meta::query()
            ->where('object_type', 'email_notification')
            ->where('meta_key', EmailNotifications::META_KEY)
            ->first();

        $stored = $row->meta_value['notification_config']['order_paid_customer'];
        $this->assertSame('New Subject', $stored['subject']);
        $this->assertSame('<p>Body</p>', $stored['email_body']);
        $this->assertSame('no', $stored['is_default_body']);
        $this->assertSame('yes', $stored['active']);
        $this->assertSame('order_receipt', $stored['attach_pdf_template']);
        $this->assertArrayNotHasKey('unsupported_key', $stored);
    }

    // =========================================================================
    // Spec 33: missing keys preserve existing config values
    // =========================================================================

    public function test_update_notification_preserves_existing_when_keys_missing(): void
    {
        EmailNotifications::updateNotification('order_paid_customer', [
            'subject' => 'First Update',
            'active'  => 'yes',
        ]);

        EmailNotifications::updateNotification('order_paid_customer', [
            'active' => 'no',
        ]);

        $row = Meta::query()
            ->where('object_type', 'email_notification')
            ->where('meta_key', EmailNotifications::META_KEY)
            ->first();

        $stored = $row->meta_value['notification_config']['order_paid_customer'];
        $this->assertSame('First Update', $stored['subject']);
        $this->assertSame('no', $stored['active']);
    }

    // =========================================================================
    // Spec 34: is_default_body=yes clears email_body when no draft filter
    // =========================================================================

    public function test_default_body_yes_clears_email_body(): void
    {
        EmailNotifications::updateNotification('order_paid_customer', [
            'subject'         => 'X',
            'email_body'      => '<p>Old body</p>',
            'is_default_body' => 'no',
        ]);

        EmailNotifications::updateNotification('order_paid_customer', [
            'subject'         => 'X',
            'is_default_body' => 'yes',
        ]);

        $row = Meta::query()
            ->where('object_type', 'email_notification')
            ->where('meta_key', EmailNotifications::META_KEY)
            ->first();

        $stored = $row->meta_value['notification_config']['order_paid_customer'];
        $this->assertSame('', $stored['email_body']);
    }

    // =========================================================================
    // Spec 35: keep_email_body_draft filter preserves email_body
    // =========================================================================

    public function test_keep_email_body_draft_filter_preserves_email_body(): void
    {
        EmailNotifications::updateNotification('order_paid_customer', [
            'subject'         => 'X',
            'email_body'      => '<p>Draft preserved</p>',
            'is_default_body' => 'no',
        ]);

        add_filter('fluent_cart/keep_email_body_draft', '__return_true');

        try {
            EmailNotifications::updateNotification('order_paid_customer', [
                'subject'         => 'X',
                'is_default_body' => 'yes',
            ]);

            $row = Meta::query()
                ->where('object_type', 'email_notification')
                ->where('meta_key', EmailNotifications::META_KEY)
                ->first();

            $stored = $row->meta_value['notification_config']['order_paid_customer'];
            $this->assertSame('<p>Draft preserved</p>', $stored['email_body']);
        } finally {
            remove_filter('fluent_cart/keep_email_body_draft', '__return_true');
        }
    }

    // =========================================================================
    // Spec 36: persists into fct_meta with object_type='email_notification'
    // =========================================================================

    public function test_persists_into_fct_meta_with_correct_keys(): void
    {
        EmailNotifications::updateNotification('order_paid_customer', [
            'subject' => 'Persist Test',
        ]);

        $count = Meta::query()
            ->where('object_type', 'email_notification')
            ->where('meta_key', EmailNotifications::META_KEY)
            ->count();

        $this->assertSame(1, $count);
    }

    // =========================================================================
    // Spec 37: updateNotification forgets cache after persist
    // =========================================================================

    public function test_update_notification_forgets_cache(): void
    {
        // Prime cache
        EmailNotifications::cachedSettings();

        EmailNotifications::updateNotification('order_paid_customer', [
            'subject' => 'Cache Bust',
        ]);

        // After forget, the next cachedSettings call should re-read from DB
        $cached = EmailNotifications::cachedSettings();
        $this->assertSame('Cache Bust', $cached['notification_config']['order_paid_customer']['subject']);
    }

    // =========================================================================
    // Spec 38: returns updated notification array
    // =========================================================================

    public function test_update_notification_returns_updated_notification(): void
    {
        $result = EmailNotifications::updateNotification('order_paid_customer', [
            'subject' => 'Returned Subject',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('Returned Subject', $result['settings']['subject']);
    }

    // =========================================================================
    // Spec 39: defaultSettings keys + values
    // =========================================================================

    public function test_default_settings_shape(): void
    {
        $defaults = EmailNotifications::defaultSettings();
        $this->assertSame('', $defaults['from_name']);
        $this->assertSame('', $defaults['from_email']);
        $this->assertSame('', $defaults['reply_to_name']);
        $this->assertSame('', $defaults['reply_to_email']);
        $this->assertSame('', $defaults['email_footer']);
        $this->assertSame('yes', $defaults['show_email_footer']);
        $this->assertSame('{{wp.admin_email}}', $defaults['admin_email']);
    }

    // =========================================================================
    // Spec 40: getSettings merges cached over defaults
    // =========================================================================

    public function test_get_settings_merges_cached_over_defaults(): void
    {
        $this->configFactory->create([
            'from_name'  => 'Custom Sender',
            'from_email' => 'custom@example.test',
        ]);

        $settings = EmailNotifications::getSettings();
        $this->assertSame('Custom Sender', $settings['from_name']);
        $this->assertSame('custom@example.test', $settings['from_email']);
        // Defaults still present
        $this->assertSame('yes', $settings['show_email_footer']);
    }

    // =========================================================================
    // Spec 41: getSettings($key) returns single value
    // =========================================================================

    public function test_get_settings_with_key_returns_single_value(): void
    {
        $this->configFactory->create([
            'from_email' => 'single@example.test',
        ]);

        $this->assertSame('single@example.test', EmailNotifications::getSettings('from_email'));
    }

    // =========================================================================
    // Spec 42: notification_config merged into settings
    // =========================================================================

    public function test_get_settings_includes_notification_config(): void
    {
        $this->configFactory->withNotificationConfig('order_paid_customer', [
            'subject' => 'Merged Config',
        ]);

        $settings = EmailNotifications::getSettings();
        $this->assertArrayHasKey('notification_config', $settings);
        $this->assertSame('Merged Config', $settings['notification_config']['order_paid_customer']['subject']);
    }

    // =========================================================================
    // Spec 43: updateSettings persists + forgets cache
    // =========================================================================

    public function test_update_settings_persists_and_forgets_cache(): void
    {
        EmailNotifications::updateSettings(['from_name' => 'Persist Update']);

        $row = Meta::query()
            ->where('object_type', 'email_notification')
            ->where('meta_key', EmailNotifications::META_KEY)
            ->first();

        $this->assertSame('Persist Update', $row->meta_value['from_name']);

        // Cache should reflect immediately
        $this->assertSame('Persist Update', EmailNotifications::getSettings('from_name'));
    }

    // =========================================================================
    // Spec 44: cachedSettings reads through Cache::get
    // =========================================================================

    public function test_cached_settings_reads_through_cache(): void
    {
        $this->configFactory->create(['from_name' => 'Initial DB Value']);

        $first = EmailNotifications::cachedSettings();
        $this->assertSame('Initial DB Value', $first['from_name']);

        // Mutate row directly + forget cache, next read reflects new DB value
        Meta::query()
            ->where('object_type', 'email_notification')
            ->where('meta_key', EmailNotifications::META_KEY)
            ->update(['meta_value' => json_encode(['from_name' => 'Reloaded Value'])]);
        Cache::forget(EmailNotifications::META_KEY);

        $reloaded = EmailNotifications::cachedSettings();
        $this->assertSame('Reloaded Value', $reloaded['from_name']);
    }

    // =========================================================================
    // Spec 45: cachedSettings returns [] when no Meta row
    // =========================================================================

    public function test_cached_settings_empty_when_no_meta_row(): void
    {
        $this->configFactory->reset();
        $cached = EmailNotifications::cachedSettings();
        $this->assertSame([], $cached);
    }
}
