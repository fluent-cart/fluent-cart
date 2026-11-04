<?php

namespace Tests\Integration\Modules\Integrations;

use FluentCart\App\Models\Meta;
use FluentCart\App\Modules\Integrations\BaseIntegrationManager;
use FluentCart\App\Models\Order;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

class BaseIntegrationManagerTest extends WPTestCase
{
    private string $key = 'base_mgr_test_provider';

    public function setUp(): void
    {
        parent::setUp();
        // Defeat function-static cache by rewriting option with null if any previous test left residue
        Meta::query()->where('object_type', 'option')
            ->where('meta_key', '_integration_api_' . $this->key)
            ->delete();
    }

    private function manager(bool $configured, int $priority = 10): BaseIntegrationManager
    {
        $instance = new class ('Test Provider', $this->key, $priority) extends BaseIntegrationManager {
            public bool $configuredOverride = false;
            public $returnProcessedOrder = null;
            public $processedEventData = null;
            public $logoOverride = '';

            public function isConfigured()
            {
                return $this->configuredOverride;
            }

            public function processAction($order, $eventData)
            {
                $this->returnProcessedOrder = $order;
                $this->processedEventData = $eventData;
            }

            public function getIntegrationDefaults($settings)
            {
                return ['defaults_called' => true];
            }

            public function getSettingsFields($settings, $args = [])
            {
                return ['fields_called' => true];
            }
        };
        $instance->configuredOverride = $configured;
        $instance->logo = '/path/logo.svg';
        $instance->category = 'crm';
        $instance->disableGlobalSettings = false;
        $instance->installable = 'acme/acme.php';
        $instance->scopes = ['global', 'product'];
        return $instance;
    }

    private function removeAll(string $hook): void
    {
        remove_all_filters($hook);
        remove_all_actions($hook);
    }

    // Spec 6: always adds order_integrations filter entry keyed by integrationKey
    public function test_register_always_adds_order_integrations_filter_entry(): void
    {
        $mgr = $this->manager(false, 7);
        $mgr->register();

        $addons = apply_filters('fluent_cart/integration/order_integrations', []);
        $this->assertArrayHasKey($this->key, $addons);
        $this->assertSame(7, $addons[$this->key]['priority']);
    }

    // Spec 7: when NOT configured, run/saving hooks not bound
    public function test_register_does_not_bind_run_hooks_when_unconfigured(): void
    {
        $mgr = $this->manager(false);
        $mgr->register();

        $this->assertFalse(has_filter('fluent_cart/integration/get_integration_defaults_' . $this->key));
        $this->assertFalse(has_filter('fluent_cart/integration/get_integration_settings_fields_' . $this->key));
        $this->assertFalse(has_filter('fluent_cart/integration/integration_saving_data_' . $this->key));
        $this->assertFalse(has_action('fluent_cart/integration/run/' . $this->key));
    }

    // Spec 8: configured → get_integration_defaults filter bound
    public function test_register_binds_defaults_filter_when_configured(): void
    {
        $mgr = $this->manager(true);
        $mgr->register();

        $defaults = apply_filters('fluent_cart/integration/get_integration_defaults_' . $this->key, []);
        $this->assertSame(['defaults_called' => true], $defaults);
    }

    // Spec 9: configured → get_integration_settings_fields filter bound
    public function test_register_binds_settings_fields_filter_when_configured(): void
    {
        $mgr = $this->manager(true);
        $mgr->register();

        $fields = apply_filters('fluent_cart/integration/get_integration_settings_fields_' . $this->key, []);
        $this->assertSame(['fields_called' => true], $fields);
    }

    // Spec 10: configured → integration_saving_data filter bound (default validateFeedData returns data unchanged)
    public function test_register_binds_saving_data_filter_when_configured(): void
    {
        $mgr = $this->manager(true);
        $mgr->register();

        $payload = ['k' => 'v'];
        $out = apply_filters('fluent_cart/integration/integration_saving_data_' . $this->key, $payload, []);
        $this->assertSame($payload, $out);
    }

    // Spec 11: configured → run/{key} action dispatches processAction with order when present
    public function test_run_action_dispatches_process_action_with_order(): void
    {
        $customer = (new CustomerFactory())->create();
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);

        $mgr = $this->manager(true);
        $mgr->register();

        do_action('fluent_cart/integration/run/' . $this->key, [
            'order' => $order,
            'feed'  => ['name' => 'test'],
        ]);

        $this->assertNotNull($mgr->returnProcessedOrder);
        $this->assertSame($order->id, $mgr->returnProcessedOrder->id);
        $this->assertIsArray($mgr->processedEventData);
    }

    // Spec 11 (no-op branch): run/{key} with missing order → processAction NOT invoked
    public function test_run_action_noop_when_order_missing(): void
    {
        $mgr = $this->manager(true);
        $mgr->register();

        do_action('fluent_cart/integration/run/' . $this->key, ['feed' => []]);
        $this->assertNull($mgr->returnProcessedOrder);
    }

    // Spec 12: getInfo() keys
    public function test_get_info_returns_expected_keys(): void
    {
        $mgr = $this->manager(true);
        $info = $mgr->getInfo();
        $expected = [
            'priority', 'title', 'description', 'category', 'disable_global_settings',
            'config_url', 'logo', 'enabled', 'scopes', 'installable',
            'delay_on_product_action', 'delay_on_global_action',
        ];
        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, $info, "getInfo() must expose '{$key}'");
        }
    }

    // Spec 13: config_url empty when disableGlobalSettings true
    public function test_config_url_empty_when_global_settings_disabled(): void
    {
        $mgr = $this->manager(true);
        $mgr->disableGlobalSettings = true;
        $info = $mgr->getInfo();
        $this->assertSame('', $info['config_url']);
    }

    public function test_config_url_points_to_admin_integrations_when_enabled(): void
    {
        $mgr = $this->manager(true);
        $mgr->disableGlobalSettings = false;
        $info = $mgr->getInfo();
        $this->assertStringContainsString('admin.php?page=fluent-cart#/integrations/' . $this->key, $info['config_url']);
    }

    // Spec 14: getInfo().enabled reflects isConfigured() at call time
    public function test_get_info_enabled_reflects_is_configured(): void
    {
        $mgr = $this->manager(false);
        $this->assertFalse($mgr->getInfo()['enabled']);
        $mgr->configuredOverride = true;
        $this->assertTrue($mgr->getInfo()['enabled']);
    }

    // Spec 15: default isConfigured() requires stored option with status=true
    public function test_default_is_configured_requires_option_status_true(): void
    {
        $realKey = 'real_provider_' . wp_generate_password(6, false);
        $real = new class ('Real', $realKey, 10) extends BaseIntegrationManager {
            public function processAction($order, $eventData) {}
            public function getIntegrationDefaults($settings) { return []; }
            public function getSettingsFields($settings, $args = []) { return []; }
        };

        // No option → unconfigured
        $this->assertFalse($real->isConfigured());

        fluent_cart_update_option('_integration_api_' . $realKey, ['status' => true, 'apiKey' => 'abc']);
        fluent_cart_get_option('_integration_api_' . $realKey, null, false); // reset cache
        $this->assertTrue($real->isConfigured());
    }

    // Spec 16: getApiSettings default falls back to empty status=false when option missing
    public function test_default_get_api_settings_falls_back_to_empty(): void
    {
        $realKey = 'no_option_' . wp_generate_password(6, false);
        $real = new class ('Real', $realKey, 10) extends BaseIntegrationManager {
            public function processAction($order, $eventData) {}
            public function getIntegrationDefaults($settings) { return []; }
            public function getSettingsFields($settings, $args = []) { return []; }
        };
        $settings = $real->getApiSettings();
        $this->assertSame(['apiKey' => '', 'status' => false], $settings);
    }

    public function test_default_get_api_settings_decodes_json_string_option(): void
    {
        $realKey = 'json_string_' . wp_generate_password(6, false);
        fluent_cart_update_option('_integration_api_' . $realKey, json_encode(['status' => true, 'apiKey' => 'x']));
        fluent_cart_get_option('_integration_api_' . $realKey, null, false);

        $real = new class ('Real', $realKey, 10) extends BaseIntegrationManager {
            public function processAction($order, $eventData) {}
            public function getIntegrationDefaults($settings) { return []; }
            public function getSettingsFields($settings, $args = []) { return []; }
        };
        $settings = $real->getApiSettings();
        $this->assertTrue((bool) $settings['status']);
    }

    // Spec 17: getSelectedTagIds with simple routing returns flat array
    public function test_get_selected_tag_ids_simple_returns_flat_array(): void
    {
        $mgr = $this->manager(true);
        // Reflection to call protected method
        $ref = new \ReflectionClass($mgr);
        $method = $ref->getMethod('getSelectedTagIds');
        $method->setAccessible(true);

        $data = ['tag_ids_selection_type' => 'simple', 'tag_ids' => [10, 20, 30]];
        $result = $method->invoke($mgr, $data, []);
        $this->assertSame([10, 20, 30], $result);
    }

    // Spec 18: router routing → evaluateRoutings result
    public function test_get_selected_tag_ids_routing_uses_routers(): void
    {
        $mgr = $this->manager(true);
        $ref = new \ReflectionClass($mgr);
        $method = $ref->getMethod('getSelectedTagIds');
        $method->setAccessible(true);

        $data = [
            'tag_ids_selection_type' => 'conditional',
            'tag_routers'            => [
                [
                    'field'       => 'country',
                    'operator'    => '=',
                    'value'       => 'US',
                    'input_value' => [42],
                ],
            ],
        ];
        $input = ['country' => 'US'];
        $result = $method->invoke($mgr, $data, $input);
        $this->assertContains([42], $result);
    }

    // Spec 19: evaluateRoutings skips rows with empty input_value
    public function test_evaluate_routings_skips_rows_with_empty_input_value(): void
    {
        $mgr = $this->manager(true);
        $ref = new \ReflectionClass($mgr);
        $method = $ref->getMethod('evaluateRoutings');
        $method->setAccessible(true);

        $routings = [
            [
                'field'       => 'country',
                'operator'    => '=',
                'value'       => 'US',
                'input_value' => '', // skipped
            ],
        ];
        $result = $method->invoke($mgr, $routings, ['country' => 'US']);
        $this->assertSame([], $result);
    }

    // Spec 20: parseSmartCode runs ShortcodeTemplateBuilder with order + customer
    public function test_parse_smart_code_returns_string_via_shortcode_template_builder(): void
    {
        $customer = (new CustomerFactory())->create(['first_name' => 'Alice']);
        $order = (new OrderFactory())->create(['customer_id' => $customer->id]);
        $order = Order::query()->with('customer')->find($order->id);

        $mgr = $this->manager(true);
        // Basic smoke test: pass literal text, assert string result
        $out = $mgr->parseSmartCode('Hello world', $order);
        $this->assertIsString($out);
        $this->assertStringContainsString('Hello world', $out);
    }
}
