<?php

namespace Tests\Integration\Modules\Integrations;

use FluentCart\App\Modules\Integrations\FluentPlugins\FluentCRMConnect;
use lucatume\WPBrowser\TestCase\WPTestCase;

class FluentCRMConnectTest extends WPTestCase
{
    // Spec 21: constructor constants
    public function test_constructor_sets_identity_and_install_meta(): void
    {
        $mgr = new FluentCRMConnect();
        $ref = new \ReflectionObject($mgr);

        $title = $ref->getProperty('title'); $title->setAccessible(true);
        $key   = $ref->getProperty('integrationKey'); $key->setAccessible(true);
        $prio  = $ref->getProperty('priority'); $prio->setAccessible(true);

        $this->assertSame('FluentCRM', $title->getValue($mgr));
        $this->assertSame('fluentcrm', $key->getValue($mgr));
        $this->assertSame(12, $prio->getValue($mgr));
        $this->assertTrue($mgr->disableGlobalSettings);
        $this->assertSame('fluent-crm/fluent-crm.php', $mgr->installable);
        $this->assertSame(1, $mgr->integrationId);
    }

    // Spec 22: checkout filter bound only when FLUENTCRM defined
    public function test_checkout_filter_gated_by_fluentcrm_constant(): void
    {
        if (!defined('FLUENTCRM')) {
            $mgr = new FluentCRMConnect();
            // Manually re-trigger by reconstructing in absence of constant: filter must NOT be bound
            $this->assertFalse(has_filter('fluent_cart/checkout_page_name_fields_schema', [$mgr, 'maybeSetNameEmailAtCheckout']),
                'maybeSetNameEmailAtCheckout must NOT bind when FLUENTCRM undefined');
            return;
        }
        $mgr = new FluentCRMConnect();
        $this->assertNotFalse(has_filter('fluent_cart/checkout_page_name_fields_schema', [$mgr, 'maybeSetNameEmailAtCheckout']));
    }

    // Spec 23: isConfigured mirrors FLUENTCRM constant
    public function test_is_configured_returns_fluentcrm_constant_state(): void
    {
        $mgr = new FluentCRMConnect();
        $this->assertSame(defined('FLUENTCRM'), $mgr->isConfigured());
    }

    // Spec 24: getApiSettings reflects constant
    public function test_get_api_settings_returns_defined_constant_as_status(): void
    {
        $mgr = new FluentCRMConnect();
        $settings = $mgr->getApiSettings();
        $this->assertSame(['status' => defined('FLUENTCRM'), 'api_key' => ''], $settings);
    }

    // Spec 25: getIntegrationDefaults keys
    public function test_integration_defaults_keys(): void
    {
        $mgr = new FluentCRMConnect();
        $defaults = $mgr->getIntegrationDefaults([]);
        foreach (['enabled', 'list_name', 'name', 'list_ids', 'remove_list_ids', 'other_fields', 'tag_ids', 'remove_tag_ids', 'tag_routers', 'event_trigger', 'tag_ids_selection_type', 'double_opt_in', 'watch_on_access_revoke', 'note'] as $k) {
            $this->assertArrayHasKey($k, $defaults, "defaults missing key {$k}");
        }
        $this->assertSame('yes', $defaults['double_opt_in']);
        $this->assertSame('no', $defaults['watch_on_access_revoke']);
    }

    // Spec 27: maybeSetNameEmailAtCheckout returns fields unchanged when billing values preset
    public function test_maybe_set_name_email_returns_unchanged_when_billing_preset(): void
    {
        $mgr = new FluentCRMConnect();
        $fields = [
            'billing_full_name' => ['value' => 'Alice'],
            'billing_email'     => ['value' => 'a@example.com'],
        ];
        $this->assertSame($fields, $mgr->maybeSetNameEmailAtCheckout($fields));
    }

    // Spec 27 (guard 2): returns unchanged for logged-in user
    public function test_maybe_set_name_email_returns_unchanged_when_user_logged_in(): void
    {
        $userId = static::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);
        try {
            $mgr = new FluentCRMConnect();
            $fields = [
                'billing_full_name' => ['value' => ''],
                'billing_email'     => ['value' => ''],
            ];
            $this->assertSame($fields, $mgr->maybeSetNameEmailAtCheckout($fields));
        } finally {
            wp_set_current_user(0);
        }
    }


    // Pro-required gate (registration-level): order_paid_done fan-out with FLUENTCRM absent
    // must not invoke processAction because the `fluent_cart/integration/run/fluentcrm` hook
    // is only bound when isConfigured() returns true. This is the observable Pro-required
    // contract in core — no user-visible message exists.
    public function test_processaction_hook_not_registered_when_pro_plugin_absent(): void
    {
        // Integration.suite.yml loads only FluentCart, so the constant is absent unless a test leaks it.
        $this->assertFalse(defined('FLUENTCRM'), 'FluentCRM must not be loaded in the Integration suite');

        // Re-register to make sure filter state reflects current gate.
        (new FluentCRMConnect())->register();

        $this->assertFalse(
            has_action('fluent_cart/integration/run/fluentcrm'),
            'Pro-required gate: run/{key} action MUST NOT be bound without FLUENTCRM'
        );

        // dispatching the hook must be a no-op — not fatal
        $invoked = 0;
        add_action('fluent_cart/integration/run/fluentcrm', function () use (&$invoked) {
            $invoked++;
        }, 999);
        do_action('fluent_cart/integration/run/fluentcrm', ['order' => null]);
        $this->assertSame(1, $invoked, 'dispatch harness is alive');
        // The point: CRM side-effects are NOT invoked because FluentCRMConnect::processAction is not bound.
    }

    // Regression: the feed sent the state code (BD-60) to FluentCRM instead of its name.
    public function test_process_action_sends_billing_state_name_not_code(): void
    {
        // The FluentCrmApi stub below would collide with a real FluentCRM.
        $this->assertFalse(defined('FLUENTCRM'), 'FluentCRM must not be loaded in the Integration suite');

        require_once dirname(__DIR__, 3) . '/Support/Stubs/FluentCrmStubs.php';
        \FluentCrmContactsStub::reset();

        $customer = (new \Tests\Support\Factory\CustomerFactory())->create([
            'email' => 'crm-state-' . wp_generate_password(6, false) . '@example.com',
        ]);
        $order = (new \Tests\Support\Factory\OrderFactory())->create(['customer_id' => $customer->id]);
        (new \Tests\Support\Factory\OrderAddressFactory())->create($order->id, [
            'city'    => 'Sylhet',
            'state'   => 'BD-60',
            'country' => 'BD',
        ]);
        $order = \FluentCart\App\Models\Order::query()->with(['customer', 'billing_address'])->find($order->id);

        (new FluentCRMConnect())->processAction($order, ['feed' => ['double_opt_in' => 'no']]);

        $this->assertSame('Sylhet', \FluentCrmContactsStub::$lastData['state'] ?? null);
        $this->assertSame('BD', \FluentCrmContactsStub::$lastData['country'] ?? null);
    }
}
