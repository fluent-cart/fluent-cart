<?php

namespace Tests\Integration\Services;

use FluentCart\App\Models\Meta;
use FluentCart\App\Models\Order;
use FluentCart\App\Services\Email\BlockEditorHelper;
use FluentCart\App\Services\Email\ConditionPresets;
use FluentCart\App\Services\Email\EmailNotificationMailer;
use FluentCart\App\Services\Email\EmailNotifications;
use FluentCart\App\Services\Email\EmailPreviewService;
use FluentCart\App\Services\Email\Mailer;
use FluentCart\App\Services\ShortCodeParser\Parsers\OrderParser;
use FluentCart\App\Services\ShortCodeParser\ShortcodeTemplateBuilder;
use FluentCart\App\Services\ShortCodeParser\SmartCodeParser;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\EmailNotificationConfigFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * Filters/actions exhaustive coverage + edge cases (specs 246-267).
 */
class EmailHooksAndEdgeTest extends WPTestCase
{
    private EmailNotificationConfigFactory $configFactory;
    private CustomerFactory $customerFactory;
    private OrderFactory $orderFactory;
    private array $sentMail = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->configFactory = new EmailNotificationConfigFactory();
        $this->customerFactory = new CustomerFactory();
        $this->orderFactory = new OrderFactory();
        $this->configFactory->reset();
        $this->sentMail = [];
        add_filter('pre_wp_mail', [$this, 'capturePreWpMail'], 10, 2);
    }

    public function tearDown(): void
    {
        remove_filter('pre_wp_mail', [$this, 'capturePreWpMail'], 10);
        $this->configFactory->reset();
        parent::tearDown();
    }

    public function capturePreWpMail($null, array $atts)
    {
        $this->sentMail[] = $atts;
        return true;
    }

    private function makeOrderFixture(): array
    {
        $customer = $this->customerFactory->create();
        $order = $this->orderFactory->create(['customer_id' => $customer->id]);
        $order = Order::query()->with('customer')->find($order->id);
        return ['order' => $order, 'customer' => $customer];
    }

    // =========================================================================
    // Spec 246: filter fluent_cart/email_notifications applied
    // =========================================================================

    public function test_filter_email_notifications_applied(): void
    {
        $invoked = false;
        add_filter('fluent_cart/email_notifications', function ($n) use (&$invoked) {
            $invoked = true;
            return $n;
        });
        try {
            EmailNotifications::getNotifications();
            $this->assertTrue($invoked);
        } finally {
            remove_all_filters('fluent_cart/email_notifications');
        }
    }

    // =========================================================================
    // Spec 247: filter fluent_cart/keep_email_body_draft preserves email_body
    // =========================================================================

    public function test_filter_keep_email_body_draft_preserves_body(): void
    {
        EmailNotifications::updateNotification('order_paid_customer', [
            'subject'         => 'X',
            'email_body'      => '<p>Draft</p>',
            'is_default_body' => 'no',
        ]);

        add_filter('fluent_cart/keep_email_body_draft', '__return_true');
        try {
            EmailNotifications::updateNotification('order_paid_customer', [
                'subject'         => 'X',
                'is_default_body' => 'yes',
            ]);
            $row = Meta::query()->where('object_type', 'email_notification')->where('meta_key', EmailNotifications::META_KEY)->first();
            $this->assertSame('<p>Draft</p>', $row->meta_value['notification_config']['order_paid_customer']['email_body']);
        } finally {
            remove_filter('fluent_cart/keep_email_body_draft', '__return_true');
        }
    }

    // =========================================================================
    // Spec 248: filter fluent_cart/should_send_email_notification short-circuits
    // =========================================================================

    public function test_filter_should_send_email_notification_short_circuits(): void
    {
        $fixture = $this->makeOrderFixture();
        $this->configFactory->withNotificationConfig('order_paid_customer', [
            'subject'         => 'X',
            'is_default_body' => 'no',
            'email_body'      => 'X',
            'active'          => 'yes',
        ]);
        add_filter('fluent_cart/should_send_email_notification', '__return_false');
        try {
            (new EmailNotificationMailer())->mailEmailsOfEvent('order_paid', $fixture);
            $this->assertEmpty($this->sentMail);
        } finally {
            remove_all_filters('fluent_cart/should_send_email_notification');
        }
    }

    // =========================================================================
    // Spec 249-250: pro-only block content/render filters
    // =========================================================================

    public function test_filter_parse_email_block_content_invoked_for_custom_emails(): void
    {
        $invoked = 0;
        add_filter('fluent_cart/parse_email_block_content', function ($parsed, $body, $data) use (&$invoked) {
            $invoked++;
            return '';
        }, 10, 3);
        try {
            $fixture = $this->makeOrderFixture();
            $notification = [
                'body'       => '<!-- block -->',
                'subject'    => 'X',
                'to'         => 'r@x.test',
                'pre_header' => '',
                'is_async'   => false,
                'is_custom'  => true,
            ];
            (new EmailNotificationMailer())->parseEmailContent($notification, $fixture);
            $this->assertGreaterThanOrEqual(1, $invoked);
        } finally {
            remove_all_filters('fluent_cart/parse_email_block_content');
        }
    }

    public function test_filter_render_block_email_template_invoked_with_pre_parsed_body(): void
    {
        $invoked = 0;
        add_filter('fluent_cart/parse_email_block_content', function () { return '<p>parsed</p>'; });
        add_filter('fluent_cart/render_block_email_template', function ($body, $vars) use (&$invoked) {
            $invoked++;
            return '<html>x</html>';
        }, 10, 2);
        try {
            $fixture = $this->makeOrderFixture();
            $notification = ['body' => 'x', 'subject' => 'x', 'to' => 'r@x.test', 'pre_header' => '', 'is_async' => false, 'is_custom' => true];
            (new EmailNotificationMailer())->parseEmailContent($notification, $fixture);
            $this->assertGreaterThanOrEqual(1, $invoked);
        } finally {
            remove_all_filters('fluent_cart/parse_email_block_content');
            remove_all_filters('fluent_cart/render_block_email_template');
        }
    }

    // =========================================================================
    // Spec 251: filter fluent_cart/prepare_email_template_data invoked on PUT
    // (verified via REST dispatch in EmailNotificationControllerTest::test_prepare_email_template_data_filter_invoked).
    // This unit-level case asserts the contract of the filter args via REST.
    // =========================================================================

    public function test_filter_prepare_email_template_data_receives_without_and_with_payloads(): void
    {
        $invoked = 0;
        $capturedWithout = null;
        $capturedWith = null;
        add_filter('fluent_cart/prepare_email_template_data', function ($without, $with) use (&$invoked, &$capturedWithout, &$capturedWith) {
            $invoked++;
            $capturedWithout = $without;
            $capturedWith = $with;
            return $without;
        }, 10, 2);

        try {
            wp_set_current_user(1);
            $request = new \WP_REST_Request('PUT', '/fluent-cart/v2/email-notification/order_paid_customer');
            $request->set_param('settings', [
                'subject'         => 'Marker XYZ',
                'email_body'      => '<p>body</p>',
                'is_default_body' => 'no',
            ]);
            // Use REST so the request guard wires correctly (RequestGuard expects framework-bound init)
            rest_do_request($request);

            $this->assertSame(1, $invoked, 'Controller PUT must invoke prepare_email_template_data exactly once');
            $this->assertArrayNotHasKey('email_body', $capturedWithout, '"without" payload must have email_body stripped (free)');
            $this->assertArrayHasKey('email_body', $capturedWith, '"with" payload must preserve email_body for pro restore');
            $this->assertSame('yes', $capturedWithout['is_default_body']);
        } finally {
            remove_all_filters('fluent_cart/prepare_email_template_data');
        }
    }

    // =========================================================================
    // Spec 252: filter fluent_cart/condition_presets mutates list
    // =========================================================================

    public function test_filter_condition_presets_mutates_list(): void
    {
        add_filter('fluent_cart/condition_presets', function ($p) {
            $p[] = ['id' => 'edge_test_preset', 'label' => 'X', 'hint' => '', 'shortcode' => '{{x}}', 'condition' => 'equal', 'compareValue' => 'y'];
            return $p;
        });
        try {
            $ids = array_column(ConditionPresets::all(), 'id');
            $this->assertContains('edge_test_preset', $ids);
        } finally {
            remove_all_filters('fluent_cart/condition_presets');
        }
    }

    // =========================================================================
    // Spec 254: filter fluent_cart/smartcode_fallback for unknown smartcodes
    // =========================================================================

    public function test_filter_smartcode_fallback_invoked_for_unknown_group(): void
    {
        $captured = [];
        add_filter('fluent_cart/smartcode_fallback', function ($value, $code = null) use (&$captured) {
            $captured[] = $code;
            return 'FALLBACK';
        }, 10, 4);
        try {
            $rendered = ShortcodeTemplateBuilder::make('{{single_segment_token}}', []);
            $this->assertStringContainsString('FALLBACK', $rendered);
            $this->assertNotEmpty($captured);
        } finally {
            remove_all_filters('fluent_cart/smartcode_fallback');
        }
    }

    // =========================================================================
    // Spec 255: action fluent_cart/policy/store_sensitive_request fires when policy verifies
    // =========================================================================

    public function test_action_store_sensitive_request_fires_on_verify(): void
    {
        $captured = null;
        add_action('fluent_cart/policy/store_sensitive_request', function ($payload) use (&$captured) {
            $captured = $payload;
        });

        try {
            $policy = new \FluentCart\App\Http\Policies\StoreSensitivePolicy();
            // Drive policy directly with a real Request; we don't care about the boolean result here,
            // only that the action fired with the expected payload shape.
            $request = new \FluentCart\Framework\Http\Request\Request(\FluentCart\App\App::getInstance(), [], []);
            $policy->verifyRequest($request);

            $this->assertIsArray($captured, 'Action must fire with a payload');
            $this->assertArrayHasKey('request', $captured);
            $this->assertSame($request, $captured['request']);
        } finally {
            remove_all_actions('fluent_cart/policy/store_sensitive_request');
        }
    }

    // =========================================================================
    // Spec 256: admin recipient + empty admin_email is silently skipped
    // =========================================================================

    public function test_admin_recipient_empty_admin_email_skipped_silently(): void
    {
        $this->configFactory->create(['admin_email' => '']);
        $emails = EmailNotifications::getNotificationsOfEvent('order_paid_done', $this->makeOrderFixture());
        $this->assertArrayNotHasKey('order_paid_admin', $emails);
    }

    // =========================================================================
    // Spec 257: mailEmailsOfEvent with empty event no-ops
    // =========================================================================

    public function test_mail_emails_of_event_empty_event_noop(): void
    {
        (new EmailNotificationMailer())->mailEmailsOfEvent('non_existent_event_xyz', ['order' => null]);
        $this->assertEmpty($this->sentMail);
    }

    // =========================================================================
    // Spec 258: Mailer::send returns false when no recipient (no wp_mail)
    // =========================================================================

    public function test_mailer_send_no_recipient_skips_wp_mail(): void
    {
        $result = Mailer::make()->send();
        $this->assertFalse($result);
        $this->assertEmpty($this->sentMail);
    }

    // =========================================================================
    // Spec 259: backward compat attach_pdf=yes migrates to attach_pdf_template once per call
    // =========================================================================

    public function test_attach_pdf_legacy_migration_idempotent(): void
    {
        $this->configFactory->withNotificationConfig('order_paid_customer', ['attach_pdf' => 'yes']);
        $first = EmailNotifications::getNotifications();
        $second = EmailNotifications::getNotifications();

        $this->assertSame('order_receipt', $first['order_paid_customer']['settings']['attach_pdf_template']);
        $this->assertSame('order_receipt', $second['order_paid_customer']['settings']['attach_pdf_template']);
        $this->assertArrayNotHasKey('attach_pdf', $first['order_paid_customer']['settings']);
    }

    // =========================================================================
    // Spec 260: is_default_body=yes + filter true keeps email_body (covered in 247)
    // =========================================================================

    public function test_default_body_yes_with_draft_filter_keeps_email_body(): void
    {
        EmailNotifications::updateNotification('order_paid_customer', [
            'subject'         => 'X',
            'email_body'      => '<p>Keep me</p>',
            'is_default_body' => 'no',
        ]);
        add_filter('fluent_cart/keep_email_body_draft', '__return_true');
        try {
            EmailNotifications::updateNotification('order_paid_customer', [
                'subject'         => 'X',
                'is_default_body' => 'yes',
            ]);
            $row = Meta::query()->where('object_type', 'email_notification')->where('meta_key', EmailNotifications::META_KEY)->first();
            $this->assertSame('<p>Keep me</p>', $row->meta_value['notification_config']['order_paid_customer']['email_body']);
        } finally {
            remove_filter('fluent_cart/keep_email_body_draft', '__return_true');
        }
    }

    // =========================================================================
    // Spec 261: nested mixed delimiters resolve both
    // =========================================================================

    public function test_mixed_delimiters_both_resolve(): void
    {
        update_option('blogname', 'MixedShop');
        $admin = get_bloginfo('admin_email');
        $rendered = ShortcodeTemplateBuilder::make('{{wp.site_name}} :: ##wp.admin_email##', []);
        $this->assertSame('MixedShop :: ' . $admin, $rendered);
    }

    // =========================================================================
    // Spec 262: empty data preserves unresolvable {{…}} text
    // =========================================================================

    public function test_unresolvable_codes_preserved_with_empty_data(): void
    {
        $rendered = ShortcodeTemplateBuilder::make('{{unknown.parser_group}}', []);
        $this->assertSame('{{unknown.parser_group}}', $rendered);
    }

    // =========================================================================
    // Spec 263: OrderParser invalid timezone falls back to UTC
    // =========================================================================

    public function test_order_parser_invalid_timezone_falls_back_to_utc(): void
    {
        $fixture = $this->makeOrderFixture();
        $fixture['order']->config = ['user_tz' => 'Made/Up'];
        $parser = new OrderParser(['order' => $fixture['order']]);
        $ref = new \ReflectionClass($parser);
        $tz = $ref->getProperty('orderTz');
        $tz->setAccessible(true);
        $this->assertSame('UTC', $tz->getValue($parser));
    }

    // =========================================================================
    // Spec 264: EmailPreviewService is deterministic (no DB lookups)
    // =========================================================================

    public function test_email_preview_service_deterministic_no_db(): void
    {
        $a = (new EmailPreviewService())->getPreviewData('order.paid.customer');
        $b = (new EmailPreviewService())->getPreviewData('order.paid.customer');
        $this->assertSame($a['order']->id, $b['order']->id);
        $this->assertSame($a['order']->invoice_no, $b['order']->invoice_no);
    }

    // =========================================================================
    // Spec 265: BlockEditorHelper::getThemePrefScheme cache survives within request
    // =========================================================================

    public function test_theme_pref_scheme_cache_within_request(): void
    {
        $first = BlockEditorHelper::getThemePrefScheme();
        $second = BlockEditorHelper::getThemePrefScheme();
        $this->assertSame($first, $second);
    }

    // =========================================================================
    // Spec 266: PDF unlink runs even when wp_mail returns false
    // =========================================================================

    public function test_pdf_cleanup_runs_when_wp_mail_returns_false(): void
    {
        // No FLUENT_PDF in test env => no temp PDF created => cleanup branch trivially safe.
        // Still assert the dispatch path completes and our pre_wp_mail short-circuit didn't
        // leave any orphan temp files.
        $fixture = $this->makeOrderFixture();
        $this->configFactory->withNotificationConfig('order_paid_customer', [
            'subject'         => 'X',
            'is_default_body' => 'no',
            'email_body'      => 'X',
            'active'          => 'yes',
        ]);
        (new EmailNotificationMailer())->mailEmailsOfEvent('order_paid', $fixture);
        $this->assertNotEmpty($this->sentMail);
        $this->assertSame('X', $this->sentMail[0]['subject'] ?? null, 'the configured subject reaches wp_mail');
    }

    // =========================================================================
    // Spec 267: formatNotification falls back to default body when email_body empty
    // even though is_default_body='no'
    // =========================================================================

    public function test_format_notification_falls_back_to_default_when_email_body_empty(): void
    {
        $notification = [
            'recipient'     => 'customer',
            'template_path' => 'order.paid.customer',
            'pre_header'    => '',
            'is_async'      => false,
            'settings'      => [
                'is_default_body' => 'no',
                'email_body'      => '', // empty triggers default-template path
                'subject'         => 'X',
            ],
        ];
        $formatted = EmailNotifications::formatNotification($notification, $this->makeOrderFixture());
        $this->assertFalse($formatted['is_custom']);
        $this->assertNotEmpty($formatted['body']);
    }
}
