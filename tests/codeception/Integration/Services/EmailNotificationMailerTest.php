<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Order;
use FluentCart\App\Services\Email\EmailNotificationMailer;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\EmailNotificationConfigFactory;
use Tests\Support\Factory\OrderTransactionFactory;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Factory\SubscriptionFactory;
use Tests\Support\Traits\BuildsCustomerOrders;
use Tests\Support\Traits\SpiesOnEmailMailer;

class EmailNotificationMailerTest extends WPTestCase
{
    use BuildsCustomerOrders;
    use SpiesOnEmailMailer;

    private EmailNotificationConfigFactory $configFactory;
    private CustomerFactory $customerFactory;
    private OrderTransactionFactory $txnFactory;
    private SubscriptionFactory $subscriptionFactory;
    private ProductFactory $productFactory;
    private array $sentMail = [];
    private \Closure $mailCapture;

    public function setUp(): void
    {
        parent::setUp();
        $this->configFactory = new EmailNotificationConfigFactory();
        $this->customerFactory = new CustomerFactory();
        $this->txnFactory = new OrderTransactionFactory();
        $this->subscriptionFactory = new SubscriptionFactory();
        $this->productFactory = new ProductFactory();
        $this->configFactory->reset();
        $this->sentMail = [];

        $this->mailCapture = function ($null, array $atts) {
            $this->sentMail[] = $atts;
            return true;
        };
        add_filter('pre_wp_mail', $this->mailCapture, 10, 2);
    }

    public function tearDown(): void
    {
        remove_filter('pre_wp_mail', $this->mailCapture, 10);
        $this->configFactory->reset();
        parent::tearDown();
    }

    // =========================================================================
    // Spec 64-72: register() wires production hooks to the right event names
    // =========================================================================
    // The previous implementation only checked priorities, which would let a regression
    // such as `add_action('fluent_cart/order_paid', fn() => mailEmailsOfEvent('subscription_renewed', ...))`
    // pass undetected. Each test below fires the action and asserts the event name
    // routed to mailEmailsOfEvent matches the action hook.

    public function test_action_order_placed_offline_routes_event_name(): void
    {
        $spy = $this->spyMailerCapturingDispatchedEvent();
        $payload = ['order' => $this->makeOrderWithCustomer(), 'customer' => null, 'transaction' => null];
        do_action('fluent_cart/order_placed_offline', $payload);
        $this->assertContains('order_placed_offline', $spy['captured']['events']);
        $this->assertSame(999, $this->getActionPriority('fluent_cart/order_placed_offline', $spy['mailer']));
    }

    public function test_action_order_paid_routes_event_name(): void
    {
        $spy = $this->spyMailerCapturingDispatchedEvent();
        do_action('fluent_cart/order_paid', ['order' => $this->makeOrderWithCustomer()]);
        $this->assertContains('order_paid', $spy['captured']['events']);
        $this->assertSame(999, $this->getActionPriority('fluent_cart/order_paid', $spy['mailer']));
    }

    public function test_action_order_paid_done_routes_event_name(): void
    {
        $spy = $this->spyMailerCapturingDispatchedEvent();
        do_action('fluent_cart/order_paid_done', ['order' => $this->makeOrderWithCustomer()]);
        $this->assertContains('order_paid_done', $spy['captured']['events']);
        $this->assertSame(999, $this->getActionPriority('fluent_cart/order_paid_done', $spy['mailer']));
    }

    public function test_action_subscription_renewed_routes_event_name(): void
    {
        $spy = $this->spyMailerCapturingDispatchedEvent();
        do_action('fluent_cart/subscription_renewed', ['order' => $this->makeOrderWithCustomer()]);
        $this->assertContains('subscription_renewed', $spy['captured']['events']);
        $this->assertSame(999, $this->getActionPriority('fluent_cart/subscription_renewed', $spy['mailer']));
    }

    public function test_action_subscription_canceled_routes_event_name(): void
    {
        $spy = $this->spyMailerCapturingDispatchedEvent();
        do_action('fluent_cart/subscription_canceled', ['order' => $this->makeOrderWithCustomer()]);
        $this->assertContains('subscription_canceled', $spy['captured']['events']);
        $this->assertSame(999, $this->getActionPriority('fluent_cart/subscription_canceled', $spy['mailer']));
    }

    public function test_action_order_refunded_routes_event_name(): void
    {
        $spy = $this->spyMailerCapturingDispatchedEvent();
        do_action('fluent_cart/order_refunded', ['order' => $this->makeOrderWithCustomer()]);
        $this->assertContains('order_refunded', $spy['captured']['events']);
        $this->assertSame(999, $this->getActionPriority('fluent_cart/order_refunded', $spy['mailer']));
    }

    public function test_action_shipping_status_actions_route_event_names(): void
    {
        $spy = $this->spyMailerCapturingDispatchedEvent();
        do_action('fluent_cart/shipping_status_changed_to_shipped', ['order' => $this->makeOrderWithCustomer()]);
        do_action('fluent_cart/shipping_status_changed_to_delivered', ['order' => $this->makeOrderWithCustomer()]);
        $this->assertContains('shipping_status_changed_to_shipped', $spy['captured']['events']);
        $this->assertContains('shipping_status_changed_to_delivered', $spy['captured']['events']);
    }

    public function test_action_renewal_reminder_overdue_routes_event_name(): void
    {
        // Drive the PRODUCTION producer, not a hand-rolled do_action: the event
        // must be fired by ReminderService::sendManualReminder's renewal path,
        // or a producer regression (hook renamed/dropped) stays invisible.
        $spy = $this->spyMailerCapturingDispatchedEvent();

        $order = $this->makeOrderWithCustomer([
            'payment_status' => 'pending',
            'total_amount'   => 2999,
            'total_paid'     => 0,
        ]);

        // sendManualReminder() returns success=false on the store-level
        // reminders_enabled switch (default 'no') before it ever reaches the
        // renewal path (app/Services/Reminders/ReminderService.php:62-64,138-143).
        // renewal_reminder_overdue has no per-event toggle, so this is the only
        // switch standing between the call and the producer under test.
        // StoreSettings memoizes the option in a process-wide static, so a direct
        // update_option is invisible to an already-warmed cache — clear it explicitly
        // (api/StoreSettings.php:40).
        $previousStoreSettings = get_option('fluent_cart_store_settings', []);
        update_option('fluent_cart_store_settings', array_merge(
            (array) $previousStoreSettings,
            ['reminders_enabled' => 'yes']
        ));
        \FluentCart\Api\StoreSettings::clearCache();

        try {
            $result = (new \FluentCart\App\Services\Reminders\ReminderService())
                ->sendManualReminder('renewal_reminder_overdue', $order->id);

            $this->assertTrue(
                (bool) ($result['success'] ?? false),
                'production reminder send must succeed for a pending order with an outstanding amount: '
                    . json_encode($result)
            );
            $this->assertSame(
                ['renewal_reminder_overdue'],
                $spy['captured']['events'],
                'the producer must dispatch the event exactly once — duplicates double-send, zero silently drops the reminder'
            );
            $this->assertSame(
                (int) $order->id,
                (int) ($spy['captured']['data'][0]['reminder']['order_id'] ?? 0),
                'the dispatched payload must carry the reminder order id'
            );
            $this->assertSame(
                2999,
                (int) ($spy['captured']['data'][0]['reminder']['due_amount'] ?? 0),
                'the dispatched payload must carry the outstanding amount in cents'
            );
        } finally {
            update_option('fluent_cart_store_settings', $previousStoreSettings);
            \FluentCart\Api\StoreSettings::clearCache();
        }
    }

    public function test_action_renewal_overdue_stage_hooks_route_event_names(): void
    {
        $spy = $this->spyMailerCapturingDispatchedEvent();
        do_action('fluent_cart/renewal_overdue_first', ['order' => $this->makeOrderWithCustomer()]);
        do_action('fluent_cart/renewal_overdue_followup', ['order' => $this->makeOrderWithCustomer()]);
        do_action('fluent_cart/renewal_overdue_final', ['order' => $this->makeOrderWithCustomer()]);
        $this->assertContains('renewal_overdue_first', $spy['captured']['events']);
        $this->assertContains('renewal_overdue_followup', $spy['captured']['events']);
        $this->assertContains('renewal_overdue_final', $spy['captured']['events']);
    }

    public function test_action_subscription_reminders_route_event_names(): void
    {
        $spy = $this->spyMailerCapturingDispatchedEvent();
        do_action('fluent_cart/subscription_renewal_reminder', ['order' => $this->makeOrderWithCustomer()]);
        do_action('fluent_cart/subscription_trial_end_reminder', ['order' => $this->makeOrderWithCustomer()]);
        $this->assertContains('subscription_renewal_reminder', $spy['captured']['events']);
        $this->assertContains('subscription_trial_end_reminder', $spy['captured']['events']);
    }

    // =========================================================================
    // Spec 73-74: registerAsyncMails() is opt-in; production wires only register()
    // =========================================================================
    // app/Hooks/actions.php:20 calls (new EmailNotificationMailer)->register() and
    // EmailNotificationMailer::register() leaves $this->registerAsyncMails() commented out.
    // Tests for async-mail hooks must call registerAsyncMails() explicitly and document
    // that production does NOT wire these hooks.

    public function test_register_async_mails_wires_order_actions_when_called_explicitly(): void
    {
        $mailer = new EmailNotificationMailer();
        $mailer->registerAsyncMails();
        foreach ([
            'fluent_cart/async_mail/order_created',
            'fluent_cart/async_mail/order_placed_offline',
            'fluent_cart/async_mail/order_paid',
            'fluent_cart/async_mail/order_updated',
            'fluent_cart/async_mail/order_refunded',
        ] as $hook) {
            $this->assertSame(10, $this->getActionPriority($hook, $mailer), "registerAsyncMails should wire $hook");
        }
    }

    public function test_register_async_mails_wires_subscription_actions_when_called_explicitly(): void
    {
        $mailer = new EmailNotificationMailer();
        $mailer->registerAsyncMails();
        foreach ([
            'fluent_cart/async_mail/subscription_activated',
            'fluent_cart/async_mail/subscription_renewed',
            'fluent_cart/async_mail/subscription_eot',
            'fluent_cart/async_mail/subscription_canceled',
            'fluent_cart/async_mail/subscription_expired',
        ] as $hook) {
            $this->assertSame(10, $this->getActionPriority($hook, $mailer), "registerAsyncMails should wire $hook");
        }
    }

    public function test_production_register_does_not_wire_async_hooks(): void
    {
        // Document the prod gap: actions.php only calls register(), which has registerAsyncMails() commented out.
        // Verifies no async_mail/* hook gets wired by the production boot path.
        global $wp_filter;
        // Snapshot existing hooks before fresh register()
        $existingAsyncHooks = array_filter(array_keys($wp_filter ?? []), fn($h) => strpos($h, 'fluent_cart/async_mail/') === 0);
        $beforeCount = count($existingAsyncHooks);

        $mailer = new EmailNotificationMailer();
        $mailer->register();

        // After register(), no NEW async_mail hooks should be added by THIS mailer
        $afterAsyncHooks = array_filter(array_keys($wp_filter ?? []), fn($h) => strpos($h, 'fluent_cart/async_mail/') === 0);
        $newHooks = array_diff($afterAsyncHooks, $existingAsyncHooks);
        $this->assertEmpty($newHooks, 'register() should NOT wire async_mail/* hooks (registerAsyncMails is opt-in)');
    }

    // =========================================================================
    // Spec 75-76: formatParsable
    // =========================================================================

    public function test_format_parsable_converts_models_to_array(): void
    {
        $order = $this->makeOrderWithCustomer();
        $parsed = (new EmailNotificationMailer())->formatParsable(['order' => $order, 'customer' => $order->customer]);
        $this->assertIsArray($parsed['order']);
        $this->assertIsArray($parsed['customer']);
        $this->assertSame((int) $order->id, (int) $parsed['order']['id'], 'the order row survives the conversion');
        $this->assertSame((int) $order->customer->id, (int) $parsed['customer']['id'], 'the customer row survives the conversion');
    }

    public function test_format_parsable_injects_order_customer_when_missing(): void
    {
        $customerData = ['id' => 99, 'email' => 'x@y.test'];
        $order = $this->makeOrderWithCustomer();
        $orderArray = $order->toArray();
        unset($orderArray['customer']);
        $parsed = (new EmailNotificationMailer())->formatParsable(['order' => $orderArray, 'customer' => $customerData]);
        $this->assertSame($customerData, $parsed['order']['customer']);
    }

    // =========================================================================
    // Spec 77: mailEmailsOfEvent dispatches with correct subject AND recipient
    // =========================================================================

    public function test_mail_emails_of_event_sends_with_correct_subject_and_recipient(): void
    {
        $order = $this->makeOrderWithCustomer();
        $this->configFactory->withNotificationConfig('order_paid_customer', [
            'subject'         => 'Test Subject',
            'is_default_body' => 'no',
            'email_body'      => '<p>Body marker xyz</p>',
            'active'          => 'yes',
        ]);

        (new EmailNotificationMailer())->mailEmailsOfEvent('order_paid', ['order' => $order, 'customer' => $order->customer]);

        $this->assertCount(1, $this->sentMail);
        $this->assertSame('Test Subject', $this->sentMail[0]['subject']);
        $this->assertSame($order->customer->email, $this->sentMail[0]['to']);
        $this->assertStringContainsString('Body marker xyz', $this->sentMail[0]['message']);
    }

    public function test_mail_body_escapes_buyer_values_inside_attributes_but_not_the_subject(): void
    {
        $order = $this->makeOrderWithCustomer();
        $order->customer->first_name = 'Ann" data-injected="1';
        $order->customer->save();
        $this->configFactory->withNotificationConfig('order_paid_customer', [
            'subject'         => 'Hi {{order.customer.first_name}}',
            'is_default_body' => 'no',
            'email_body'      => '<p><a href="https://crm.example/?q={{order.customer.first_name}}">Find</a></p>',
            'active'          => 'yes',
        ]);

        (new EmailNotificationMailer())->mailEmailsOfEvent('order_paid', ['order' => $order, 'customer' => $order->customer]);

        $this->assertCount(1, $this->sentMail);
        $this->assertSame('Hi Ann" data-injected="1', $this->sentMail[0]['subject']);
        $link = new \WP_HTML_Tag_Processor($this->sentMail[0]['message']);
        $this->assertTrue($link->next_tag(['tag_name' => 'a']));
        $this->assertSame(['href'], $link->get_attribute_names_with_prefix(''), 'A buyer value must stay inside its attribute.');
        $this->assertStringStartsWith('https://crm.example/?q=Ann', $link->get_attribute('href'));
    }

    // =========================================================================
    // Spec 78-79: should_send_email_notification filter
    // =========================================================================

    public function test_should_send_email_notification_filter_skips_dispatch(): void
    {
        $order = $this->makeOrderWithCustomer();
        $this->configFactory->withNotificationConfig('order_paid_customer', [
            'subject' => 'X', 'is_default_body' => 'no', 'email_body' => 'X', 'active' => 'yes',
        ]);
        $captured = [];
        add_filter('fluent_cart/should_send_email_notification', function ($shouldSend, $args) use (&$captured) {
            $captured = $args;
            return false;
        }, 10, 2);
        try {
            (new EmailNotificationMailer())->mailEmailsOfEvent('order_paid', ['order' => $order, 'customer' => $order->customer]);
            $this->assertEmpty($this->sentMail);
            $this->assertSame('order_paid', $captured['event']);
            $this->assertSame('order_paid_customer', $captured['mail_name']);
            $this->assertInstanceOf(Order::class, $captured['order']);
            $this->assertSame($order->id, $captured['order']->id);
        } finally {
            remove_all_filters('fluent_cart/should_send_email_notification');
        }
    }

    public function test_should_send_email_notification_filter_receives_event_mail_name_order(): void
    {
        $order = $this->makeOrderWithCustomer();
        $this->configFactory->withNotificationConfig('order_paid_customer', [
            'subject' => 'X', 'is_default_body' => 'no', 'email_body' => 'X', 'active' => 'yes',
        ]);
        $args = null;
        add_filter('fluent_cart/should_send_email_notification', function ($s, $a) use (&$args) { $args = $a; return $s; }, 10, 2);
        try {
            (new EmailNotificationMailer())->mailEmailsOfEvent('order_paid', ['order' => $order, 'customer' => $order->customer]);
            $this->assertSame(['event', 'mail_name', 'order'], array_keys($args));
        } finally {
            remove_all_filters('fluent_cart/should_send_email_notification');
        }
    }

    // =========================================================================
    // Spec 80: is_async + asyncHook enqueues Action Scheduler
    // =========================================================================

    public function test_is_async_enqueues_async_action_when_hook_provided(): void
    {
        // Action Scheduler ships with the plugin (boot/app.php loads it) — no env gate.
        $order = $this->makeOrderWithCustomer();
        $this->configFactory->withNotificationConfig('order_paid_customer', ['active' => 'no']);
        add_filter('fluent_cart/email_notifications', function ($n) {
            $n['async_test'] = [
                'event'         => 'custom_async_event',
                'recipient'     => 'customer',
                'template_path' => 'order.paid.customer',
                'is_async'      => true,
                'settings'      => ['active' => 'yes', 'subject' => 'X', 'is_default_body' => 'no', 'email_body' => 'X'],
            ];
            return $n;
        });

        try {
            (new EmailNotificationMailer())->mailEmailsOfEvent(
                'custom_async_event',
                ['order' => $order, 'customer' => $order->customer],
                'fluent_cart/async_mail/order_paid',
                ['orderId' => $order->id]
            );
            $next = as_next_scheduled_action('fluent_cart/async_mail/order_paid');
            $this->assertNotFalse($next, 'Async action should be scheduled');
            $this->assertEmpty($this->sentMail, 'Sync mail should not fire when async path taken');
        } finally {
            remove_all_filters('fluent_cart/email_notifications');
            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions('fluent_cart/async_mail/order_paid');
            }
        }
    }

    // =========================================================================
    // Spec 81-83: PDF attachment paths — exercised through the production
    // fluent_cart/pdf/generate_receipt filter (the Pro integration point since
    // OrderReceiptPdfService moved to Pro) plus the fluent_cart/pdf/can_generate_receipt
    // testability seam (Pro is not active in the Integration suite).
    // =========================================================================

    public function test_attaches_pdf_path_when_template_set_and_pdf_enabled(): void
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'fc-pdf-test-') . '.pdf';
        file_put_contents($tmpPath, '%PDF-1.4 stub');

        $this->forceFluentPdfConstant();
        add_filter('fluent_cart/pdf/can_generate_receipt', '__return_true');
        add_filter('fluent_cart/pdf/generate_receipt', function ($path, $context) use ($tmpPath) {
            return $tmpPath;
        }, 10, 2);

        try {
            $order = $this->makeOrderWithCustomer();
            $this->configFactory->withNotificationConfig('order_paid_customer', [
                'subject'             => 'PDF Subject',
                'is_default_body'     => 'no',
                'email_body'          => 'X',
                'active'              => 'yes',
                'attach_pdf_template' => 'order_receipt',
            ]);

            (new EmailNotificationMailer())->mailEmailsOfEvent('order_paid', ['order' => $order, 'customer' => $order->customer]);

            $this->assertNotEmpty($this->sentMail);
            $this->assertContains($tmpPath, $this->sentMail[0]['attachments'], 'wp_mail must receive the exact PDF path');
        } finally {
            remove_all_filters('fluent_cart/pdf/can_generate_receipt');
            remove_all_filters('fluent_cart/pdf/generate_receipt');
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }

    public function test_attach_pdf_legacy_yes_maps_to_order_receipt_template_id(): void
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'fc-pdf-legacy-') . '.pdf';
        file_put_contents($tmpPath, '%PDF-1.4 stub');

        $capturedTemplateId = null;
        $this->forceFluentPdfConstant();
        add_filter('fluent_cart/pdf/can_generate_receipt', '__return_true');
        add_filter('fluent_cart/pdf/generate_receipt', function ($path, $context) use ($tmpPath, &$capturedTemplateId) {
            $capturedTemplateId = $context['template_id'];
            return $tmpPath;
        }, 10, 2);

        // Force the legacy attach_pdf branch by injecting a notification with attach_pdf=yes
        // and no attach_pdf_template (mailEmailsOfEvent maps it to order_receipt).
        $order = $this->makeOrderWithCustomer();
        add_filter('fluent_cart/email_notifications', function ($n) {
            $n['legacy_test'] = [
                'event'         => 'order_paid',
                'recipient'     => 'customer',
                'template_path' => 'order.paid.customer',
                'is_async'      => false,
                'settings'      => [
                    'active' => 'yes', 'subject' => 'Legacy', 'is_default_body' => 'no',
                    'email_body' => 'X', 'attach_pdf' => 'yes',
                ],
            ];
            return $n;
        });

        try {
            (new EmailNotificationMailer())->mailEmailsOfEvent('order_paid', ['order' => $order, 'customer' => $order->customer]);
            $this->assertSame('order_receipt', $capturedTemplateId, 'Legacy attach_pdf=yes must map to order_receipt template');
        } finally {
            remove_all_filters('fluent_cart/email_notifications');
            remove_all_filters('fluent_cart/pdf/can_generate_receipt');
            remove_all_filters('fluent_cart/pdf/generate_receipt');
            if (file_exists($tmpPath)) @unlink($tmpPath);
        }
    }

    public function test_skips_pdf_attachment_when_template_id_empty(): void
    {
        // No attach_pdf_template + no legacy attach_pdf => no PDF generation, no attachment —
        // even with the PDF gate forced open, so the only reason the filter stays silent
        // is the empty template id.
        $order = $this->makeOrderWithCustomer();
        $this->configFactory->withNotificationConfig('order_paid_customer', [
            'subject' => 'No-PDF', 'is_default_body' => 'no', 'email_body' => 'X', 'active' => 'yes',
        ]);

        $generateCalls = 0;
        $this->forceFluentPdfConstant();
        add_filter('fluent_cart/pdf/can_generate_receipt', '__return_true');
        add_filter('fluent_cart/pdf/generate_receipt', function ($path, $context) use (&$generateCalls) {
            $generateCalls++;
            return '/tmp/should-not-be-called.pdf';
        }, 10, 2);

        try {
            (new EmailNotificationMailer())->mailEmailsOfEvent('order_paid', ['order' => $order, 'customer' => $order->customer]);
            $this->assertSame(0, $generateCalls, 'PDF generation must not run when no template id');
            $this->assertSame([], $this->sentMail[0]['attachments']);
        } finally {
            remove_all_filters('fluent_cart/pdf/can_generate_receipt');
            remove_all_filters('fluent_cart/pdf/generate_receipt');
        }
    }

    // =========================================================================
    // Spec 84: generateOrderPdf returns null + logs activity row when generation throws
    // =========================================================================

    public function test_generate_order_pdf_returns_null_and_logs_when_service_throws(): void
    {
        $order = $this->makeOrderWithCustomer();
        add_filter('fluent_cart/pdf/can_generate_receipt', '__return_true');
        add_filter('fluent_cart/pdf/generate_receipt', function ($path, $context) {
            throw new \RuntimeException('PDF intentionally exploded for test');
        }, 10, 2);

        try {
            $mailer = new EmailNotificationMailer();
            $ref = new \ReflectionMethod($mailer, 'generateOrderPdf');
            $ref->setAccessible(true);

            // Snapshot pre-call activity log count so we don't depend on prior tests
            global $wpdb;
            $logTable = $wpdb->prefix . 'fct_activity';
            $beforeCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$logTable`");

            $result = $ref->invoke($mailer, ['order' => $order], 'order_receipt');
            $this->assertNull($result, 'generateOrderPdf must return null on throw');

            $afterCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$logTable`");
            $this->assertGreaterThan($beforeCount, $afterCount, 'activity log row must be added on PDF failure');

            $latestLog = $wpdb->get_row("SELECT * FROM `$logTable` ORDER BY id DESC LIMIT 1", ARRAY_A);
            $this->assertSame('PDF Generation Failed', $latestLog['title']);
            $this->assertStringContainsString('PDF intentionally exploded', (string) $latestLog['content']);
        } finally {
            remove_all_filters('fluent_cart/pdf/can_generate_receipt');
            remove_all_filters('fluent_cart/pdf/generate_receipt');
        }
    }

    public function test_generate_order_pdf_returns_null_when_order_missing(): void
    {
        $generateCalls = 0;
        add_filter('fluent_cart/pdf/can_generate_receipt', '__return_true');
        add_filter('fluent_cart/pdf/generate_receipt', function ($path, $context) use (&$generateCalls) {
            $generateCalls++;
            return '/tmp/should-not-be-called.pdf';
        }, 10, 2);

        try {
            $mailer = new EmailNotificationMailer();
            $ref = new \ReflectionMethod($mailer, 'generateOrderPdf');
            $ref->setAccessible(true);
            $this->assertNull($ref->invoke($mailer, ['order' => null], 'order_receipt'));
            $this->assertSame(0, $generateCalls, 'generation filter must not run without an Order model');
        } finally {
            remove_all_filters('fluent_cart/pdf/can_generate_receipt');
            remove_all_filters('fluent_cart/pdf/generate_receipt');
        }
    }

    // =========================================================================
    // Spec 85: temp PDF unlinked after send (success path) AND when wp_mail returns false
    // =========================================================================

    public function test_temp_pdf_unlinked_after_successful_send(): void
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'fc-pdf-unlink-') . '.pdf';
        file_put_contents($tmpPath, 'pdf data');
        $this->assertFileExists($tmpPath);

        $this->forceFluentPdfConstant();
        add_filter('fluent_cart/pdf/can_generate_receipt', '__return_true');
        add_filter('fluent_cart/pdf/generate_receipt', function ($path, $context) use ($tmpPath) {
            return $tmpPath;
        }, 10, 2);

        try {
            $order = $this->makeOrderWithCustomer();
            $this->configFactory->withNotificationConfig('order_paid_customer', [
                'subject' => 'X', 'is_default_body' => 'no', 'email_body' => 'X',
                'active' => 'yes', 'attach_pdf_template' => 'order_receipt',
            ]);
            (new EmailNotificationMailer())->mailEmailsOfEvent('order_paid', ['order' => $order, 'customer' => $order->customer]);

            $this->assertFileDoesNotExist($tmpPath, 'temp PDF must be unlinked after wp_mail returns');
        } finally {
            remove_all_filters('fluent_cart/pdf/can_generate_receipt');
            remove_all_filters('fluent_cart/pdf/generate_receipt');
            if (file_exists($tmpPath)) @unlink($tmpPath);
        }
    }

    public function test_temp_pdf_unlinked_when_wp_mail_returns_false(): void
    {
        // Replace pre_wp_mail filter to return false (mail "failed") instead of true
        remove_filter('pre_wp_mail', $this->mailCapture, 10);
        add_filter('pre_wp_mail', static function ($null, array $atts) {
            return false; // wp_mail returns false; cleanup must still run
        }, 10, 2);

        $tmpPath = tempnam(sys_get_temp_dir(), 'fc-pdf-fail-') . '.pdf';
        file_put_contents($tmpPath, 'pdf data');
        $this->assertFileExists($tmpPath);

        $this->forceFluentPdfConstant();
        add_filter('fluent_cart/pdf/can_generate_receipt', '__return_true');
        add_filter('fluent_cart/pdf/generate_receipt', function ($path, $context) use ($tmpPath) {
            return $tmpPath;
        }, 10, 2);

        try {
            $order = $this->makeOrderWithCustomer();
            $this->configFactory->withNotificationConfig('order_paid_customer', [
                'subject' => 'X', 'is_default_body' => 'no', 'email_body' => 'X',
                'active' => 'yes', 'attach_pdf_template' => 'order_receipt',
            ]);
            (new EmailNotificationMailer())->mailEmailsOfEvent('order_paid', ['order' => $order, 'customer' => $order->customer]);

            $this->assertFileDoesNotExist($tmpPath, 'temp PDF must be unlinked even when wp_mail returns false');
        } finally {
            remove_all_filters('fluent_cart/pdf/can_generate_receipt');
            remove_all_filters('fluent_cart/pdf/generate_receipt');
            remove_all_filters('pre_wp_mail');
            add_filter('pre_wp_mail', $this->mailCapture, 10, 2);
            if (file_exists($tmpPath)) @unlink($tmpPath);
        }
    }

    // =========================================================================
    // Spec 86: parseEmailContent returns indexed [body, subject, to]
    // =========================================================================

    public function test_parse_email_content_returns_indexed_triple(): void
    {
        $order = $this->makeOrderWithCustomer();
        $notification = [
            'body' => '<p>Body content</p>', 'subject' => 'Subject XYZ', 'to' => 'recipient@x.test',
            'pre_header' => '', 'is_async' => false, 'is_custom' => false,
        ];

        $result = (new EmailNotificationMailer())->parseEmailContent($notification, ['order' => $order, 'customer' => $order->customer]);
        $this->assertCount(3, $result);
        $this->assertIsString($result[0]);
        $this->assertSame('Subject XYZ', $result[1]);
        $this->assertSame('recipient@x.test', $result[2]);
    }

    // =========================================================================
    // Spec 87: custom block emails apply parse_email_block_content + render_block_email_template
    // =========================================================================

    public function test_custom_block_emails_use_block_filters(): void
    {
        $blockParseCalls = 0;
        $blockRenderCalls = 0;
        add_filter('fluent_cart/parse_email_block_content', function () use (&$blockParseCalls) {
            $blockParseCalls++;
            return '<p>Parsed block content</p>';
        }, 10, 3);
        add_filter('fluent_cart/render_block_email_template', function ($body, $vars) use (&$blockRenderCalls) {
            $blockRenderCalls++;
            return '<html>Block rendered: ' . $vars['emailBody'] . '</html>';
        }, 10, 2);

        try {
            $order = $this->makeOrderWithCustomer();
            $notification = [
                'body' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
                'subject' => 'S', 'to' => 'r@x.test', 'pre_header' => '', 'is_async' => false, 'is_custom' => true,
            ];
            $result = (new EmailNotificationMailer())->parseEmailContent($notification, ['order' => $order, 'customer' => $order->customer]);
            $this->assertGreaterThanOrEqual(1, $blockParseCalls);
            $this->assertGreaterThanOrEqual(1, $blockRenderCalls);
            $this->assertStringContainsString('Block rendered', $result[0]);
        } finally {
            remove_all_filters('fluent_cart/parse_email_block_content');
            remove_all_filters('fluent_cart/render_block_email_template');
        }
    }

    // =========================================================================
    // Spec 88: default emails render order_header + general_template
    // =========================================================================

    public function test_default_emails_render_order_header_and_general_template(): void
    {
        $order = $this->makeOrderWithCustomer(['invoice_no' => 'INV-HEADER-9000']);
        $notification = [
            'body' => '<p>Default body</p>', 'subject' => 'S', 'to' => 'r@x.test',
            'pre_header' => 'Pre-header text', 'is_async' => false, 'is_custom' => false,
        ];
        $result = (new EmailNotificationMailer())->parseEmailContent($notification, ['order' => $order, 'customer' => $order->customer]);
        $this->assertStringContainsString('Default body', $result[0]);
        // order_header partial renders {{order.created_at}} & the order uuid — proves the header view actually rendered
        $this->assertStringContainsString((string) $order->uuid, $result[0]);
    }

    // =========================================================================
    // Spec 89: shortcodes resolved in subject + to via ShortcodeTemplateBuilder
    // =========================================================================

    public function test_shortcodes_resolved_in_subject_and_to(): void
    {
        $this->configFactory->create(['from_name' => 'X', 'from_email' => 'x@y.test']);
        update_option('blogname', 'Wonder Shop');

        $order = $this->makeOrderWithCustomer();
        $notification = [
            'body' => '<p>Body</p>',
            'subject' => 'Order from {{wp.site_name}}',
            'to' => '{{order.customer.email}}',
            'pre_header' => '', 'is_async' => false, 'is_custom' => false,
        ];
        $result = (new EmailNotificationMailer())->parseEmailContent($notification, ['order' => $order, 'customer' => $order->customer]);
        $this->assertSame('Order from Wonder Shop', $result[1]);
        $this->assertSame($order->customer->email, $result[2]);
    }

    // =========================================================================
    // Spec 90-91: getEmailFooter
    // =========================================================================

    public function test_get_email_footer_includes_custom_footer_when_set(): void
    {
        $this->configFactory->create(['email_footer' => 'My custom footer XYZ']);
        $footer = (new EmailNotificationMailer())->getEmailFooter();
        $this->assertStringContainsString('My custom footer XYZ', $footer);
    }

    public function test_get_email_footer_appends_branding_when_not_pro(): void
    {
        // Integration suite loads only the free plugin (Integration.suite.yml) — Pro is never active.
        $this->assertFalse(\FluentCart\App\App::isProActive(), 'suite must run without Pro for this oracle');
        $footer = (new EmailNotificationMailer())->getEmailFooter();
        $this->assertStringContainsString('fluentcart.com', $footer);
    }

    // =========================================================================
    // Spec 92-94: sendAsyncOrderMail (now testable after mailByEmailName fix)
    // =========================================================================

    public function test_send_async_order_mail_loads_order_with_relations_and_dispatches(): void
    {
        $order = $this->makeOrderWithCustomer();
        $this->txnFactory->create($order->id);
        $this->configFactory->withNotificationConfig('order_paid_customer', [
            'subject' => 'Async Subject', 'is_default_body' => 'no',
            'email_body' => 'X', 'active' => 'yes',
        ]);

        (new EmailNotificationMailer())->sendAsyncOrderMail('order_paid_customer', $order->id);

        $this->assertCount(1, $this->sentMail);
        $this->assertSame('Async Subject', $this->sentMail[0]['subject']);
        $this->assertSame($order->customer->email, $this->sentMail[0]['to']);
    }

    public function test_send_async_order_mail_passes_first_transaction_to_smartcodes(): void
    {
        $order = $this->makeOrderWithCustomer();
        $this->txnFactory->create($order->id, ['vendor_charge_id' => 'ch_marker_unique']);
        // Add a SECOND transaction to confirm async path passes the FIRST one (not the latest)
        $this->txnFactory->create($order->id, ['vendor_charge_id' => 'ch_second_should_not_appear']);

        $this->configFactory->withNotificationConfig('order_paid_customer', [
            'subject' => 'Trx {{transaction.vendor_charge_id}}',
            'is_default_body' => 'no', 'email_body' => 'X', 'active' => 'yes',
        ]);

        (new EmailNotificationMailer())->sendAsyncOrderMail('order_paid_customer', $order->id);

        $this->assertSame('Trx ch_marker_unique', $this->sentMail[0]['subject']);
        $this->assertStringNotContainsString('ch_second_should_not_appear', $this->sentMail[0]['subject']);
    }

    public function test_send_async_order_mail_no_op_when_order_missing(): void
    {
        (new EmailNotificationMailer())->sendAsyncOrderMail('order_paid_customer', 9999999);
        $this->assertEmpty($this->sentMail);
    }

    // =========================================================================
    // Spec 95-96: sendAsyncSubscriptionMail
    // =========================================================================

    public function test_send_async_subscription_mail_loads_subscription_with_relations(): void
    {
        $order = $this->makeOrderWithCustomer();
        $product = $this->productFactory->create();
        $variant = $product->variants->first();
        $sub = $this->subscriptionFactory->create([
            'customer_id' => $order->customer_id, 'parent_order_id' => $order->id,
            'product_id' => $product->ID, 'variation_id' => $variant->id,
            'item_name' => 'Async Sub Plan',
        ]);
        $this->configFactory->withNotificationConfig('subscription_renewal_customer', [
            'subject' => 'Sub Test {{subscription.item_name}}',
            'is_default_body' => 'no', 'email_body' => 'X', 'active' => 'yes',
        ]);

        (new EmailNotificationMailer())->sendAsyncSubscriptionMail('subscription_renewal_customer', $sub->id);

        $this->assertCount(1, $this->sentMail);
        $this->assertSame('Sub Test Async Sub Plan', $this->sentMail[0]['subject']);
        $this->assertSame($order->customer->email, $this->sentMail[0]['to']);
    }

    public function test_send_async_subscription_mail_returns_when_no_parent_order(): void
    {
        $customer = $this->customerFactory->create();
        $product = $this->productFactory->create();
        $variant = $product->variants->first();
        $sub = $this->subscriptionFactory->create([
            'customer_id' => $customer->id, 'parent_order_id' => 9999999,
            'product_id' => $product->ID, 'variation_id' => $variant->id,
        ]);

        (new EmailNotificationMailer())->sendAsyncSubscriptionMail('subscription_renewal_customer', $sub->id);
        $this->assertEmpty($this->sentMail);
    }

    // =========================================================================
    // Spec 97-103: fluent_cart/email_notification/mailer — send-time mailer filter
    // =========================================================================

    public function test_mailer_filter_receives_correct_context_for_order_paid_customer(): void
    {
        $order = $this->makeOrderWithCustomer();
        $this->configFactory->withNotificationConfig('order_paid_customer', [
            'subject' => 'X', 'is_default_body' => 'no', 'email_body' => 'X', 'active' => 'yes',
        ]);

        $captured = null;
        $listener = function ($mailer, $context) use (&$captured) {
            $captured = $context;
            return $mailer;
        };
        add_filter('fluent_cart/email_notification/mailer', $listener, 10, 2);
        try {
            (new EmailNotificationMailer())->mailEmailsOfEvent('order_paid', ['order' => $order, 'customer' => $order->customer]);
        } finally {
            remove_filter('fluent_cart/email_notification/mailer', $listener, 10);
        }

        $this->assertNotNull($captured, 'Filter should have fired');
        $this->assertSame(
            ['event', 'mail_name', 'recipient', 'notification', 'data'],
            array_keys($captured),
            'Context array must expose exactly these 5 keys, in this order'
        );
        $this->assertSame('order_paid', $captured['event'], 'event must be the triggering event name');
        $this->assertSame('order_paid_customer', $captured['mail_name'], 'mail_name must be the specific notification identifier');
        $this->assertSame('customer', $captured['recipient'], 'recipient must reflect the notification config');
        $this->assertIsArray($captured['notification'], 'notification must be the resolved notification config array');
        $this->assertSame('order_paid_customer', $captured['notification']['name'], 'notification[name] must match the notification being sent');
        $this->assertInstanceOf(Order::class, $captured['data']['order'], 'data must carry the original Order model, not a flattened array');
        $this->assertSame($order->id, $captured['data']['order']->id, 'data[order] must be the same order that triggered the send');
    }

    public function test_mailer_filter_targets_only_order_paid_customer(): void
    {
        $order = $this->makeOrderWithCustomer();
        $this->configFactory->withNotificationConfig('order_paid_customer', [
            'subject' => 'X', 'is_default_body' => 'no', 'email_body' => 'X', 'active' => 'yes',
        ]);
        $this->configFactory->withNotificationConfig('order_refunded_customer', [
            'subject' => 'X', 'is_default_body' => 'no', 'email_body' => 'X', 'active' => 'yes',
        ]);

        $listener = function ($mailer, $context) {
            if ($context['event'] === 'order_paid' && $context['mail_name'] === 'order_paid_customer') {
                $mailer->addBCC('trustpilot-address@example.com');
            }
            return $mailer;
        };
        add_filter('fluent_cart/email_notification/mailer', $listener, 10, 2);
        try {
            // Different event/mail_name — must NOT receive the BCC.
            (new EmailNotificationMailer())->mailEmailsOfEvent('order_refunded', ['order' => $order, 'customer' => $order->customer]);
            $this->assertNotEmpty($this->sentMail, 'order_refunded_customer must still send');
            foreach ($this->sentMail as $mail) {
                $this->assertNoBccHeader($mail);
            }
            $this->sentMail = [];

            // order_paid_customer — must receive the BCC.
            (new EmailNotificationMailer())->mailEmailsOfEvent('order_paid', ['order' => $order, 'customer' => $order->customer]);
            $this->assertNotEmpty($this->sentMail, 'order_paid_customer must send');
            $this->assertStringContainsString(
                'trustpilot-address@example.com',
                $this->findHeader($this->sentMail[0], 'Bcc'),
                'order_paid_customer must carry the BCC the listener added'
            );
        } finally {
            remove_filter('fluent_cart/email_notification/mailer', $listener, 10);
        }
    }

    public function test_mailer_filter_cc_and_bcc_reach_wp_mail_headers(): void
    {
        $order = $this->makeOrderWithCustomer();
        $this->configFactory->withNotificationConfig('order_paid_customer', [
            'subject' => 'X', 'is_default_body' => 'no', 'email_body' => 'X', 'active' => 'yes',
        ]);

        $listener = function ($mailer, $context) {
            $mailer->addBCC('trustpilot-address@example.com');
            $mailer->addCC('another-address@example.com');
            return $mailer;
        };
        add_filter('fluent_cart/email_notification/mailer', $listener, 10, 2);
        try {
            (new EmailNotificationMailer())->mailEmailsOfEvent('order_paid', ['order' => $order, 'customer' => $order->customer]);
        } finally {
            remove_filter('fluent_cart/email_notification/mailer', $listener, 10);
        }

        $this->assertCount(1, $this->sentMail, 'exactly one order_paid_customer send is expected');
        $this->assertStringContainsString(
            'trustpilot-address@example.com',
            $this->findHeader($this->sentMail[0], 'Bcc'),
            'BCC added via Mailer::addBCC() must reach the real wp_mail() Bcc header'
        );
        $this->assertStringContainsString(
            'another-address@example.com',
            $this->findHeader($this->sentMail[0], 'Cc'),
            'CC added via Mailer::addCC() must reach the real wp_mail() Cc header'
        );
    }

    public function test_mailer_filter_fires_for_direct_by_name_delivery(): void
    {
        $order = $this->makeOrderWithCustomer();
        $this->configFactory->withNotificationConfig('order_paid_customer', [
            'subject' => 'X', 'is_default_body' => 'no', 'email_body' => 'X', 'active' => 'yes',
        ]);

        $captured = null;
        $listener = function ($mailer, $context) use (&$captured) {
            $captured = $context;
            return $mailer;
        };
        add_filter('fluent_cart/email_notification/mailer', $listener, 10, 2);
        try {
            (new EmailNotificationMailer())->mailByEmailName('order_paid_customer', ['order' => $order, 'customer' => $order->customer]);
        } finally {
            remove_filter('fluent_cart/email_notification/mailer', $listener, 10);
        }

        $this->assertNotNull($captured, 'Filter must fire on the direct mailByEmailName() path');
        $this->assertSame('order_paid', $captured['event'], 'event must come from the notification config, not the caller');
        $this->assertSame('order_paid_customer', $captured['mail_name'], 'mail_name must be the name passed to mailByEmailName()');
    }

    public function test_mailer_filter_fires_for_async_delivery(): void
    {
        $order = $this->makeOrderWithCustomer();
        $this->txnFactory->create($order->id);
        $this->configFactory->withNotificationConfig('order_paid_customer', [
            'subject' => 'X', 'is_default_body' => 'no', 'email_body' => 'X', 'active' => 'yes',
        ]);

        $captured = null;
        $listener = function ($mailer, $context) use (&$captured) {
            $captured = $context;
            $mailer->addBCC('trustpilot-address@example.com');
            return $mailer;
        };
        add_filter('fluent_cart/email_notification/mailer', $listener, 10, 2);
        try {
            (new EmailNotificationMailer())->sendAsyncOrderMail('order_paid_customer', $order->id);
        } finally {
            remove_filter('fluent_cart/email_notification/mailer', $listener, 10);
        }

        $this->assertNotNull($captured, 'Filter should fire on the sendAsyncOrderMail -> mailByEmailName path');
        $this->assertSame('order_paid_customer', $captured['mail_name'], 'mail_name must be the name passed to sendAsyncOrderMail()');
        $this->assertCount(1, $this->sentMail, 'the async order mail must actually send');
        $this->assertStringContainsString(
            'trustpilot-address@example.com',
            $this->findHeader($this->sentMail[0], 'Bcc'),
            'BCC added on the async path must reach the real wp_mail() Bcc header'
        );
    }

    public function test_mailer_filter_invalid_return_falls_back_to_prepared_mailer(): void
    {
        $order = $this->makeOrderWithCustomer();
        $this->configFactory->withNotificationConfig('order_paid_customer', [
            'subject' => 'Invalid Return Subject', 'is_default_body' => 'no', 'email_body' => 'Body marker abc', 'active' => 'yes',
        ]);

        foreach ([null, 'not-a-mailer', [], 42, new \stdClass()] as $invalidReturn) {
            $this->sentMail = [];
            $listener = function () use ($invalidReturn) {
                return $invalidReturn;
            };
            add_filter('fluent_cart/email_notification/mailer', $listener, 10, 2);
            try {
                (new EmailNotificationMailer())->mailEmailsOfEvent('order_paid', ['order' => $order, 'customer' => $order->customer]);
            } finally {
                remove_filter('fluent_cart/email_notification/mailer', $listener, 10);
            }

            $failureContext = 'when the filter returns ' . var_export($invalidReturn, true);
            $this->assertCount(1, $this->sentMail, 'Original prepared mailer must still send ' . $failureContext);
            $this->assertSame('Invalid Return Subject', $this->sentMail[0]['subject'], 'subject must be untouched ' . $failureContext);
            $this->assertSame($order->customer->email, $this->sentMail[0]['to'], 'to must be untouched ' . $failureContext);
            $this->assertStringContainsString('Body marker abc', $this->sentMail[0]['message'], 'body must be untouched ' . $failureContext);
        }
    }

    public function test_mailer_filter_no_behavior_change_without_listener(): void
    {
        $order = $this->makeOrderWithCustomer();
        $this->configFactory->withNotificationConfig('order_paid_customer', [
            'subject' => 'No Listener Subject', 'is_default_body' => 'no', 'email_body' => 'X', 'active' => 'yes',
        ]);

        // Assert the precondition instead of forcing it with remove_all_filters():
        // silently wiping every listener would mask a leaked filter from an
        // earlier test (or a future bootstrap listener) instead of failing loudly.
        $this->assertFalse(
            has_filter('fluent_cart/email_notification/mailer'),
            'A listener is already registered on fluent_cart/email_notification/mailer — this test cannot prove "no behavior change without a listener" while one is present.'
        );

        (new EmailNotificationMailer())->mailEmailsOfEvent('order_paid', ['order' => $order, 'customer' => $order->customer]);

        $this->assertCount(1, $this->sentMail, 'send must be unaffected by the presence of the filter hook itself');
        $this->assertSame('No Listener Subject', $this->sentMail[0]['subject'], 'subject must be byte-identical to the pre-filter behavior');
        $this->assertSame($order->customer->email, $this->sentMail[0]['to'], 'to must be byte-identical to the pre-filter behavior');
        $this->assertNoCcHeader($this->sentMail[0]);
        $this->assertNoBccHeader($this->sentMail[0]);
    }
}
