<?php

namespace Tests\Integration\Http;

use FluentCart\App\Http\Policies\StoreSensitivePolicy;
use FluentCart\App\Models\Meta;
use FluentCart\App\Services\Cache;
use FluentCart\App\Services\Email\EmailNotifications;
use Tests\Support\Factory\EmailNotificationConfigFactory as ConfigFactory;
use Tests\Support\RestTestCase;

class EmailNotificationControllerTest extends RestTestCase
{
    protected bool $rebuildRestServerPerDispatch = true;

    private ConfigFactory $configFactory;

    public function setUp(): void
    {
        parent::setUp();
        $this->configFactory = new ConfigFactory();
        $this->configFactory->reset();
    }

    public function tearDown(): void
    {
        $this->configFactory->reset();
        parent::tearDown();
    }

    public function test_store_sensitive_policy_fires_action(): void
    {
        $fired = false;
        add_action('fluent_cart/policy/store_sensitive_request', function () use (&$fired) {
            $fired = true;
        });
        try {
            $this->dispatchRequest(new \WP_REST_Request('GET', '/fluent-cart/v2/email-notification'));
            $this->assertTrue($fired, 'fluent_cart/policy/store_sensitive_request must fire on policy verify');
        } finally {
            remove_all_actions('fluent_cart/policy/store_sensitive_request');
        }
    }

    // =========================================================================
    // Spec 223: GET /email-notification
    // =========================================================================

    public function test_get_index_returns_notifications(): void
    {
        $response = $this->dispatchRequest(new \WP_REST_Request('GET', '/fluent-cart/v2/email-notification'));
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('order_paid_customer', $data['data']);
    }

    // =========================================================================
    // Spec 224: GET /email-notification/get-short-codes
    // =========================================================================

    public function test_get_short_codes_returns_templates_shortcodes_buttons(): void
    {
        $response = $this->dispatchRequest(new \WP_REST_Request('GET', '/fluent-cart/v2/email-notification/get-short-codes'));
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('email_templates', $data['data']);
        $this->assertArrayHasKey('shortcodes', $data['data']);
        $this->assertArrayHasKey('buttons', $data['data']);
    }

    // =========================================================================
    // Spec 225: GET /email-notification/get-settings
    // =========================================================================

    public function test_get_settings_returns_settings_and_shortcodes(): void
    {
        $response = $this->dispatchRequest(new \WP_REST_Request('GET', '/fluent-cart/v2/email-notification/get-settings'));
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('shortcodes', $data);
    }

    // =========================================================================
    // Spec 226: POST /save-settings validates EmailSettingsRequest
    // =========================================================================

    public function test_save_settings_validates_required_fields(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/email-notification/save-settings');
        // missing required fields
        $response = $this->dispatchRequest($request);
        $this->assertSame(422, $response->get_status(), 'missing required fields must fail validation with 422');
    }

    public function test_save_settings_persists_valid_payload(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/email-notification/save-settings');
        $request->set_body_params([
            'from_name'    => 'Sender',
            'from_email'   => 'sender@x.test',
            'admin_email'  => 'admin@x.test',
        ]);
        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status());
        $this->assertSame('Sender', EmailNotifications::getSettings('from_name'));
    }

    // =========================================================================
    // Spec 227: show_email_footer forced to yes when not Pro
    // =========================================================================

    public function test_save_settings_forces_show_email_footer_when_not_pro(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/email-notification/save-settings');
        $request->set_body_params([
            'from_name'         => 'X',
            'from_email'        => 'x@y.test',
            'admin_email'       => 'admin@y.test',
            'show_email_footer' => 'no',
        ]);
        $this->dispatchRequest($request);
        $this->assertSame('yes', EmailNotifications::getSettings('show_email_footer'));
    }

    // =========================================================================
    // Spec 228-229: GET/POST /reminders
    // =========================================================================

    public function test_get_reminders_returns_settings_and_fields(): void
    {
        $response = $this->dispatchRequest(new \WP_REST_Request('GET', '/fluent-cart/v2/email-notification/reminders'));
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('settings', $data);
        $this->assertArrayHasKey('fields', $data);
        $this->assertArrayHasKey('reminders', $data['fields']);
    }

    public function test_post_reminders_persists_settings(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/email-notification/reminders');
        $request->set_body_params([
            'yearly_renewal_reminders_enabled' => 'yes',
            'yearly_renewal_reminder_days'     => 14,
        ]);
        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status());

        // Verify the persisted store-settings actually carry the values
        $store = (new \FluentCart\Api\StoreSettings())->get();
        $this->assertSame('yes', $store['yearly_renewal_reminders_enabled'] ?? null);
        $this->assertSame(14, (int) ($store['yearly_renewal_reminder_days'] ?? 0));
    }

    // =========================================================================
    // Spec 230: POST /enable-notification/{name}
    // =========================================================================

    public function test_post_enable_notification_updates_active_flag(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/email-notification/enable-notification/order_paid_customer');
        $request->set_body_params(['active' => 'no']);
        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status());
        $row = Meta::query()
            ->where('object_type', 'email_notification')
            ->where('meta_key', EmailNotifications::META_KEY)
            ->first();
        $this->assertSame('no', $row->meta_value['notification_config']['order_paid_customer']['active']);
    }

    // =========================================================================
    // Spec 231-232: POST /send-manual-reminder
    // =========================================================================

    public function test_send_manual_reminder_requires_event_and_entity_id(): void
    {
        $response = $this->dispatchRequest(new \WP_REST_Request('POST', '/fluent-cart/v2/email-notification/send-manual-reminder'));
        $this->assertSame(422, $response->get_status(), 'missing event/entity_id must be rejected with 422');
        $this->assertSame('Event type and entity ID are required', $response->get_data()['message'] ?? null, 'rejection must carry the required-fields message');
    }

    public function test_send_manual_reminder_for_unknown_order_returns_not_found_error(): void
    {
        // ReminderService::sendManualReminder short-circuits on the store-level
        // reminders_enabled switch (default 'no') BEFORE the order lookup
        // (app/Services/Reminders/ReminderService.php:62-64,138-143). Leaving it off
        // would assert the disabled-store message and never reach the lookup this
        // test exists to prove.
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
            $request = new \WP_REST_Request('POST', '/fluent-cart/v2/email-notification/send-manual-reminder');
            $request->set_body_params([
                'event'     => 'renewal_reminder_overdue',
                'entity_id' => 9999999,
            ]);
            $response = $this->dispatchRequest($request);

            // Input passed the controller's required-fields guard (that path returns
            // 'Event type and entity ID are required'), reached ReminderService, and
            // failed on the order lookup — proving the service dispatch, not just routing.
            $this->assertSame(422, $response->get_status(), 'unknown order must produce an error response');
            $this->assertSame('Order or customer not found', $response->get_data()['message'] ?? null, 'service lookup failure message must surface');
        } finally {
            update_option('fluent_cart_store_settings', $previousStoreSettings);
            \FluentCart\Api\StoreSettings::clearCache();
        }
    }

    // =========================================================================
    // Spec 233: POST /preview-default-template
    // =========================================================================

    public function test_preview_default_template_renders_html(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/email-notification/preview-default-template');
        $request->set_body_params(['template' => 'order.paid.customer']);
        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('content', $data['data']);
        $this->assertStringContainsString('pointer-events: none', $data['data']['content']);
    }

    public function test_preview_renders_every_registered_template(): void
    {
        // The preview dummy order/subscription must satisfy every property and
        // method the email views dereference — a template added without extending
        // EmailPreviewService fatals here instead of 500ing in wp-admin.
        $templates = array_filter(array_column(EmailNotifications::getNotifications(), 'template_path'));
        $this->assertNotEmpty($templates, 'notification registry must expose template paths');

        foreach ($templates as $template) {
            $request = new \WP_REST_Request('POST', '/fluent-cart/v2/email-notification/preview-default-template');
            $request->set_body_params(['template' => $template]);
            $response = $this->dispatchRequest($request);
            $this->assertSame(
                200,
                $response->get_status(),
                "preview must render template {$template}; got: " . wp_json_encode($response->get_data())
            );
        }
    }

    // =========================================================================
    // Spec 234: GET /email-notification/{notification}
    // =========================================================================

    public function test_get_single_notification_returns_payload(): void
    {
        $response = $this->dispatchRequest(new \WP_REST_Request('GET', '/fluent-cart/v2/email-notification/order_paid_customer'));
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('shortcodes', $data);
        $this->assertArrayHasKey('has_fluent_pdf', $data);
        $this->assertArrayHasKey('fonts_ready', $data);
        $this->assertArrayHasKey('setup_url', $data);
        $this->assertArrayHasKey('pdf_templates', $data);
    }

    // =========================================================================
    // Spec 235: GET unknown notification returns error
    // =========================================================================

    public function test_get_unknown_notification_returns_error(): void
    {
        // Two dispatches with different {notification} values in one process:
        // rebuildRestServerPerDispatch guards against WPFluent's stale route-param cache.
        $known = $this->dispatchRequest(new \WP_REST_Request('GET', '/fluent-cart/v2/email-notification/order_paid_customer'));
        $unknown = $this->dispatchRequest(new \WP_REST_Request('GET', '/fluent-cart/v2/email-notification/does_not_exist_xyz'));

        $this->assertSame(200, $known->get_status(), 'known notification must resolve');
        $this->assertArrayHasKey('shortcodes', $known->get_data(), 'known notification payload carries shortcodes');

        $this->assertSame(422, $unknown->get_status(), 'unknown notification must return the sendError default 422');
        $unknownData = $unknown->get_data();
        $this->assertArrayNotHasKey('shortcodes', $unknownData, 'unknown notification must not leak the editor payload');
        $this->assertSame('Notification Details not found', $unknownData['message'] ?? null, 'unknown notification returns the not-found message');
    }

    // =========================================================================
    // Spec 236: has_fluent_pdf=true only when FLUENT_PDF defined
    // =========================================================================

    public function test_has_fluent_pdf_reflects_constant_state(): void
    {
        $response = $this->dispatchRequest(new \WP_REST_Request('GET', '/fluent-cart/v2/email-notification/order_paid_customer'));
        $data = $response->get_data();
        $this->assertSame(defined('FLUENT_PDF'), (bool) $data['has_fluent_pdf']);
    }

    // =========================================================================
    // Spec 237: PUT /email-notification/{notification} validates EmailNotificationRequest
    // =========================================================================

    public function test_put_validates_subject_required(): void
    {
        $request = new \WP_REST_Request('PUT', '/fluent-cart/v2/email-notification/order_paid_customer');
        $request->set_body_params(['settings' => ['email_body' => '<p>x</p>']]);
        $response = $this->dispatchRequest($request);
        $this->assertSame(422, $response->get_status(), 'missing subject must fail validation with 422');
    }

    public function test_put_persists_with_valid_payload(): void
    {
        $marker = 'put_subject_marker_' . uniqid();
        $request = new \WP_REST_Request('PUT', '/fluent-cart/v2/email-notification/order_paid_customer');
        $request->set_body_params(['settings' => ['subject' => $marker]]);
        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status());

        // Read back the persisted notification to prove the subject actually saved
        $row = Meta::query()
            ->where('object_type', 'email_notification')
            ->where('meta_key', EmailNotifications::META_KEY)
            ->first();
        $this->assertNotNull($row, 'PUT must persist a fct_meta row');
        $stored = $row->meta_value['notification_config']['order_paid_customer']['subject'] ?? null;
        $this->assertSame($marker, $stored, 'PUT must persist the supplied subject');
    }

    // =========================================================================
    // Spec 238: prepare_email_template_data filter applied (free strips email_body)
    // =========================================================================

    public function test_prepare_email_template_data_filter_invoked(): void
    {
        $invoked = 0;
        add_filter('fluent_cart/prepare_email_template_data', function ($without, $with) use (&$invoked) {
            $invoked++;
            return $without;
        }, 10, 2);
        try {
            $request = new \WP_REST_Request('PUT', '/fluent-cart/v2/email-notification/order_paid_customer');
            $request->set_body_params(['settings' => ['subject' => 'Filter Test']]);
            $this->dispatchRequest($request);
            $this->assertGreaterThanOrEqual(1, $invoked);
        } finally {
            remove_all_filters('fluent_cart/prepare_email_template_data');
        }
    }

    // =========================================================================
    // Spec 239: PUT returns error when updateNotification falsy
    // =========================================================================

    public function test_put_unknown_notification_handles_falsy_update(): void
    {
        $request = new \WP_REST_Request('PUT', '/fluent-cart/v2/email-notification/missing_notification_xyz');
        $request->set_body_params(['settings' => ['subject' => 'X', 'is_default_body' => 'yes']]);
        $response = $this->dispatchRequest($request);

        // EmailNotifications::updateNotification persists the config blob first, then
        // getNotification('missing_notification_xyz') returns null (unknown name), so the
        // controller takes the falsy branch.
        $this->assertSame(422, $response->get_status(), 'unknown notification PUT must return the sendError default 422');
        $this->assertSame('Failed to update notification', $response->get_data()['message'] ?? null, 'falsy update must surface the failure message');

        // Current (quirky but real) behavior: the config write happens before the null
        // lookup, so the unknown key IS persisted. Pin it so a silent contract change shows.
        $row = Meta::query()
            ->where('object_type', 'email_notification')
            ->where('meta_key', EmailNotifications::META_KEY)
            ->first();
        $this->assertNotNull($row, 'config blob must exist after the PUT');
        $this->assertArrayHasKey('missing_notification_xyz', $row->meta_value['notification_config'] ?? [], 'updateNotification persists the unknown key before the falsy lookup');
    }

    // =========================================================================
    // Spec 240-241: request sanitization
    // =========================================================================

    public function test_put_email_body_payload_is_never_persisted_in_free(): void
    {
        // Free core strips email_body in the controller (pro's
        // fluent_cart/prepare_email_template_data filter restores it), and
        // is_default_body is forced to 'yes' which clears any stored body — so a
        // submitted XSS payload must never reach the config blob at all.
        $request = new \WP_REST_Request('PUT', '/fluent-cart/v2/email-notification/order_paid_customer');
        $request->set_body_params([
            'settings' => [
                'subject'         => 'X',
                'email_body'      => '<script>alert(1)</script><p>safe</p>',
                'is_default_body' => 'no',
            ],
        ]);
        $response = $this->dispatchRequest($request);
        $this->assertSame(200, $response->get_status(), 'PUT with subject must succeed');

        $row = Meta::query()
            ->where('object_type', 'email_notification')
            ->where('meta_key', EmailNotifications::META_KEY)
            ->first();
        $config = $row->meta_value['notification_config']['order_paid_customer'] ?? [];
        $this->assertSame('', $config['email_body'] ?? null, 'free core must persist an empty email_body regardless of the payload');
        $this->assertSame('yes', $config['is_default_body'] ?? null, 'free core must force is_default_body back to yes');
    }

    public function test_email_settings_request_rejects_malformed_from_email(): void
    {
        // The email rule rejects this before any sanitizer runs — nothing may persist.
        $dirty = "  evil@<script>example\x00.test  ";
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/email-notification/save-settings');
        $request->set_body_params([
            'from_name'   => 'Test',
            'from_email'  => $dirty,
            'admin_email' => 'admin@x.test',
        ]);
        $response = $this->dispatchRequest($request);

        $this->assertSame(422, $response->get_status(), 'malformed from_email must fail the email validation rule');
        $this->assertNotSame('Test', EmailNotifications::getSettings('from_name'), 'rejected save-settings must not persist any field');
    }

    // =========================================================================
    // Spec 242-244: Permissions
    // =========================================================================

    public function test_unauthenticated_request_is_forbidden(): void
    {
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/email-notification');
        $response = $this->dispatchRequestAsGuest($request);

        $this->assertContains($response->get_status(), [401, 403], 'Guest must be denied (got ' . $response->get_status() . ')');
        $body = $response->get_data();
        // Denial body may carry a 'data' key with error details but must NOT contain real notification config
        $payload = is_array($body) ? json_encode($body) : (string) $body;
        $this->assertStringNotContainsString('order_paid_customer', $payload, 'Denied request must not leak notification payload');
    }

    public function test_subscriber_without_capability_is_forbidden(): void
    {
        $subId = static::factory()->user->create(['role' => 'subscriber']);
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/email-notification');
        $response = $this->dispatchRequestAs($subId, $request);

        $this->assertContains($response->get_status(), [401, 403], 'Subscriber must be denied (got ' . $response->get_status() . ')');
        $body = $response->get_data();
        $payload = is_array($body) ? json_encode($body) : (string) $body;
        $this->assertStringNotContainsString('order_paid_customer', $payload, 'Denied request must not leak notification payload');
    }

    public function test_unauthenticated_post_to_save_settings_is_forbidden(): void
    {
        $request = new \WP_REST_Request('POST', '/fluent-cart/v2/email-notification/save-settings');
        $request->set_param('from_name', 'X');
        $request->set_param('from_email', 'x@x.test');
        $request->set_param('admin_email', 'admin@x.test');

        $response = $this->dispatchRequestAsGuest($request);
        $this->assertContains($response->get_status(), [401, 403], 'Mutating endpoint must reject guest (got ' . $response->get_status() . ')');

        // And confirm side effect did NOT occur
        $stored = EmailNotifications::getSettings('from_name');
        $this->assertNotSame('X', $stored, 'save-settings must not persist when guest is denied');
    }

    public function test_administrator_with_manage_options_allowed(): void
    {
        // Persistent admin (id=1) is set up in RestTestCase::setUpBeforeClass
        $response = $this->dispatchRequest(new \WP_REST_Request('GET', '/fluent-cart/v2/email-notification'));
        $this->assertSame(200, $response->get_status());
    }

    public function test_second_administrator_user_is_allowed(): void
    {
        // PermissionManager grants all permissions to any user with manage_options (without Pro).
        // Verify a freshly created admin (separate from persistent admin) is also accepted by the policy.
        $userId = static::factory()->user->create(['role' => 'administrator']);

        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/email-notification');
        $response = $this->dispatchRequestAs($userId, $request);

        $this->assertSame(200, $response->get_status(), 'Second administrator must be allowed');
        $body = $response->get_data();
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('order_paid_customer', $body['data']);
    }
}
