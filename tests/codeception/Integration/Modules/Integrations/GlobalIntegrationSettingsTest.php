<?php

namespace Tests\Integration\Modules\Integrations;

use FluentCart\App\Models\Meta;
use FluentCart\App\Modules\Integrations\GlobalIntegrationSettings;
use Tests\Support\IntegrationsTestCase;

class GlobalIntegrationSettingsTest extends IntegrationsTestCase
{
    private string $provider;

    public function setUp(): void
    {
        parent::setUp();
        $this->provider = 'gis_' . wp_generate_password(6, false);
        add_filter('fluent_cart/integration/get_integration_settings_fields_' . $this->provider, function () {
            return [
                'fields'              => [
                    ['key' => 'name', 'label' => 'Name', 'required' => true],
                    ['key' => 'list_ids', 'label' => 'Lists'],
                ],
                'button_require_list' => false,
            ];
        });
        add_filter('fluent_cart/integration/get_integration_defaults_' . $this->provider, function () {
            return ['enabled' => 'yes', 'name' => '', 'list_ids' => []];
        });
    }

    public function tearDown(): void
    {
        remove_all_filters('fluent_cart/integration/get_integration_settings_fields_' . $this->provider);
        remove_all_filters('fluent_cart/integration/get_integration_defaults_' . $this->provider);
        remove_all_filters('fluent_cart/integration/global_integration_settings_' . $this->provider);
        remove_all_filters('fluent_cart/integration/global_integration_fields_' . $this->provider);
        remove_all_filters('fluent_cart/integration/global_notification_types');
        remove_all_filters('fluent_cart/integration/global_notification_feed_' . $this->provider);
        parent::tearDown();
    }

    // Spec 121: unknown key → 422 JSON
    public function test_get_global_settings_data_unknown_key_returns_422(): void
    {
        $svc = new GlobalIntegrationSettings();
        $captured = $this->captureAjaxJson(function () use ($svc) {
            $svc->getGlobalSettingsData(['settings_key' => 'unknown_prov_x']);
        });
        $this->assertSame(422, $captured['status'], 'unknown integration must respond with HTTP 422');
        $this->assertIsArray($captured['payload']);
        $this->assertArrayHasKey('message', $captured['payload']);
        $this->assertStringContainsString('unknown_prov_x', (string) $captured['payload']['message']);
    }

    // Spec 122: defaults for button text / messages when filter omits them
    public function test_get_global_settings_data_applies_text_defaults(): void
    {
        add_filter('fluent_cart/integration/global_integration_fields_' . $this->provider, function () {
            return ['key' => 'anything']; // minimal non-empty
        });

        $svc = new GlobalIntegrationSettings();
        $captured = $this->captureAjaxJson(function () use ($svc) {
            $svc->getGlobalSettingsData(['settings_key' => $this->provider]);
        });
        $this->assertSame(200, $captured['status'], 'known integration must return 200 OK');
        $payload = $captured['payload'];
        $this->assertIsArray($payload);
        $this->assertSame('Save Settings', $payload['settings']['save_button_text']);
        $this->assertSame('Your API Key is valid', $payload['settings']['valid_message']);
        $this->assertSame('Your API Key is not valid', $payload['settings']['invalid_message']);
    }

    // Spec 123: authenticate fires provider-specific action
    public function test_authenticate_credentials_fires_provider_action(): void
    {
        $fired = 0;
        $captured = null;
        add_action('fluent_cart/integration/authenticate_global_credentials_' . $this->provider, function ($payload) use (&$fired, &$captured) {
            $fired++;
            $captured = $payload;
        });

        (new GlobalIntegrationSettings())->authenticateCredentials([
            'settings_key' => $this->provider,
            'integration'  => ['apiKey' => 'abc'],
        ]);

        $this->assertSame(1, $fired);
        $this->assertSame($this->provider, $captured['settings_key']);
        $this->assertSame(['apiKey' => 'abc'], $captured['integration']);
    }

    // Spec 124: fall-through 422 when no save hook short-circuits
    public function test_save_global_settings_data_falls_through_to_422(): void
    {
        $svc = new GlobalIntegrationSettings();
        $captured = $this->captureAjaxJson(function () use ($svc) {
            $svc->saveGlobalSettingsData([
                'settings_key' => $this->provider,
                'integration'  => ['apiKey' => 'x'],
            ]);
        });
        $this->assertSame(422, $captured['status'], 'unhandled save must respond with 422');
        $this->assertIsArray($captured['payload']);
        $this->assertArrayHasKey('message', $captured['payload']);
        $this->assertStringContainsString('no Integration found', (string) $captured['payload']['message']);
    }

    // Spec 125: getFeeds filters to providers exposing `'global'` scope AND `enabled` truthy.
    // Positive + negative coverage to lock the filter and the returned row shape.
    public function test_get_feeds_returns_only_global_enabled(): void
    {
        $productOnly = 'productonly_' . wp_generate_password(4, false);
        $disabledGlobal = 'disabledglobal_' . wp_generate_password(4, false);

        $a1 = $this->registerAddonInFilter($this->provider, [
            'scopes'  => ['global', 'product'],
            'enabled' => true,
        ]);
        $a2 = $this->registerAddonInFilter($productOnly, [
            'scopes'  => ['product'],
            'enabled' => true,
        ]);
        $a3 = $this->registerAddonInFilter($disabledGlobal, [
            'scopes'  => ['global'],
            'enabled' => false,
        ]);

        // Seed one feed per provider so getFeeds has rows to return for the allowlisted providers.
        $this->addGlobalFeed($this->provider, [
            'enabled' => 'yes',
            'name'    => 'Allowed Feed',
        ]);
        $this->addGlobalFeed($productOnly, [
            'enabled' => 'yes',
            'name'    => 'Product-only Feed',
        ]);
        $this->addGlobalFeed($disabledGlobal, [
            'enabled' => 'yes',
            'name'    => 'Disabled-provider Feed',
        ]);

        $result = (new GlobalIntegrationSettings())->getFeeds();

        remove_filter('fluent_cart/integration/order_integrations', $a1);
        remove_filter('fluent_cart/integration/order_integrations', $a2);
        remove_filter('fluent_cart/integration/order_integrations', $a3);

        $this->assertArrayHasKey('feeds', $result);
        $providers = array_column($result['feeds'], 'provider');
        $this->assertContains($this->provider, $providers, 'global + enabled provider must appear');
        $this->assertNotContains($productOnly, $providers, 'product-only provider must NOT appear in getFeeds()');
        $this->assertNotContains($disabledGlobal, $providers, 'disabled provider must NOT appear in getFeeds()');

        foreach ($result['feeds'] as $row) {
            if ($row['provider'] === $this->provider) {
                $this->assertArrayHasKey('id', $row);
                $this->assertArrayHasKey('name', $row);
                $this->assertArrayHasKey('enabled', $row);
                $this->assertArrayHasKey('feed', $row);
                $this->assertArrayHasKey('scope', $row);
            }
        }
    }

    // Spec 126: getNotificationFeeds uses global_notification_types filter as allowlist
    public function test_get_notification_feeds_uses_global_notification_types_filter(): void
    {
        $this->addGlobalFeed($this->provider, ['enabled' => 'yes', 'name' => 'N1']);

        add_filter('fluent_cart/integration/global_notification_types', function () {
            return [$this->provider];
        });

        $feeds = (new GlobalIntegrationSettings())->getNotificationFeeds();
        $this->assertIsArray($feeds);
        $providers = array_column($feeds, 'provider');
        $this->assertContains($this->provider, $providers);
    }

    public function test_get_notification_feeds_empty_when_no_types_registered(): void
    {
        $this->addGlobalFeed($this->provider, ['enabled' => 'yes', 'name' => 'N2']);
        // No filter registered → fc returns []
        $feeds = (new GlobalIntegrationSettings())->getNotificationFeeds();
        $providers = array_column($feeds, 'provider');
        $this->assertNotContains($this->provider, $providers);
    }

    // Spec 127: global_notification_feed_{provider} filter applied
    public function test_format_feed_data_runs_notification_feed_filter(): void
    {
        add_filter('fluent_cart/integration/global_notification_feed_' . $this->provider, function ($data) {
            $data['filter_tag'] = '__applied';
            return $data;
        });
        $feed = $this->addGlobalFeed($this->provider, ['enabled' => 'yes', 'name' => 'X']);

        $out = (new GlobalIntegrationSettings())->formatFeedData($feed);
        $this->assertSame('__applied', $out['filter_tag'] ?? null);
    }

    // Spec 128: unknown feed → WP_Error
    public function test_get_integration_settings_unknown_feed_returns_error(): void
    {
        $res = (new GlobalIntegrationSettings())->getIntegrationSettings([
            'integration_name' => $this->provider,
            'integration_id'   => 99999999,
        ], 'global');
        $this->assertInstanceOf(\WP_Error::class, $res);
        $this->assertSame('integration_not_found', $res->get_error_code());
    }

    // Spec 129/130: resolves Meta vs ProductMeta; merges defaults; returns fields + shortcodes
    public function test_get_integration_settings_returns_merged_settings(): void
    {
        $feed = $this->addGlobalFeed($this->provider, [
            'enabled' => 'yes',
            'name'    => 'Merged',
            'list_ids'=> [9],
        ]);
        $res = (new GlobalIntegrationSettings())->getIntegrationSettings([
            'integration_name' => $this->provider,
            'integration_id'   => $feed->id,
        ], 'global');

        $this->assertIsArray($res);
        $this->assertArrayHasKey('settings', $res);
        $this->assertArrayHasKey('settings_fields', $res);
        $this->assertArrayHasKey('shortcodes', $res);
        $this->assertArrayHasKey('inputs', $res);
        $this->assertSame('Merged', $res['settings']['name']);
    }

    // Spec 131: missing name → 422 validation
    public function test_prepare_feed_data_missing_name_returns_422(): void
    {
        $svc = new GlobalIntegrationSettings();
        $captured = $this->captureAjaxJson(function () use ($svc) {
            $svc->prepareFeedData([
                'integration' => json_encode(['enabled' => 'yes']),
            ], 0, '', 'order_integration');
        });
        $this->assertSame(422, $captured['status'], 'missing integration_name must respond with 422');
        $this->assertIsArray($captured['payload']);
        $this->assertArrayHasKey('message', $captured['payload']);
    }

    // Spec 132: validation failure with required missing → WP_Error
    public function test_prepare_feed_data_validation_error_returns_wp_error(): void
    {
        $res = (new GlobalIntegrationSettings())->prepareFeedData([
            'integration' => json_encode(['enabled' => 'yes']), // missing name
        ], 0, $this->provider, 'order_integration');
        $this->assertInstanceOf(\WP_Error::class, $res);
        $this->assertSame('validation_error', $res->get_error_code());
    }

    // Spec 133: data_type=stringify → json_decode; else wp_unslash
    public function test_prepare_feed_data_decodes_stringified_integration(): void
    {
        $payload = ['enabled' => 'yes', 'name' => 'Feed'];
        $res = (new GlobalIntegrationSettings())->prepareFeedData([
            'integration' => json_encode($payload),
            'data_type'   => 'stringify',
        ], 0, $this->provider, 'order_integration');

        $this->assertIsArray($res);
        $this->assertSame($this->provider, $res['meta_key']);
        $this->assertSame('order_integration', $res['object_type']);
        $this->assertArrayHasKey('meta_value', $res);
        $this->assertSame('Feed', $res['meta_value']['name'] ?? null);
    }

    // Spec 134: save_integration_values_{name} filter applied to final integration payload
    public function test_prepare_feed_data_runs_save_integration_values_filter(): void
    {
        add_filter('fluent_cart/integration/save_integration_values_' . $this->provider, function ($int) {
            $int['__saved'] = true;
            return $int;
        });
        $res = (new GlobalIntegrationSettings())->prepareFeedData([
            'integration' => json_encode(['enabled' => 'yes', 'name' => 'Feed']),
            'data_type'   => 'stringify',
        ], 0, $this->provider, 'order_integration');
        remove_all_filters('fluent_cart/integration/save_integration_values_' . $this->provider);

        $this->assertIsArray($res);
        $this->assertTrue($res['meta_value']['__saved'] ?? false);
    }

    // Spec 135: validateFields
    public function test_validate_fields_flags_required_missing(): void
    {
        $svc = new GlobalIntegrationSettings();
        $errors = $svc->validateFields(
            [
                ['key' => 'a', 'label' => 'A', 'required' => true],
                ['key' => 'b', 'label' => 'B'], // non-required
            ],
            ['b' => 'ok']
        );
        $this->assertArrayHasKey('a', $errors);
        $this->assertArrayNotHasKey('b', $errors);
    }

    public function test_validate_fields_flags_empty_arrays_and_whitespace(): void
    {
        $svc = new GlobalIntegrationSettings();
        $errors = $svc->validateFields(
            [
                ['key' => 'arr', 'label' => 'Arr', 'required' => true],
                ['key' => 'str', 'label' => 'Str', 'required' => true],
            ],
            ['arr' => [], 'str' => '   ']
        );
        $this->assertArrayHasKey('arr', $errors);
        $this->assertArrayHasKey('str', $errors);
    }

    // Spec 136: unknown integration_id → WP_Error
    public function test_save_integration_settings_unknown_id_returns_error(): void
    {
        $res = (new GlobalIntegrationSettings())->saveIntegrationSettings([
            'integration_id'   => 99999999,
            'integration_name' => $this->provider,
            'integration'      => json_encode(['enabled' => 'yes', 'name' => 'F']),
        ]);
        $this->assertInstanceOf(\WP_Error::class, $res);
        $this->assertSame('integration_not_found', $res->get_error_code());
    }

    // Spec 137: IntegrationHelper error short-circuits saveIntegrationSettings
    public function test_save_integration_settings_validation_short_circuits(): void
    {
        $res = (new GlobalIntegrationSettings())->saveIntegrationSettings([
            'integration_name' => $this->provider,
            'integration'      => json_encode(['enabled' => 'yes']), // missing required name
        ]);
        $this->assertInstanceOf(\WP_Error::class, $res);
    }

    // Spec 138: creates new Meta row with wasRecentlyCreated flag
    public function test_save_integration_settings_creates_new_meta(): void
    {
        $res = (new GlobalIntegrationSettings())->saveIntegrationSettings([
            'integration_name' => $this->provider,
            'integration'      => json_encode(['enabled' => 'yes', 'name' => 'Created']),
        ]);
        $this->assertIsArray($res);
        $this->assertTrue((bool) $res['created']);
        $this->assertSame($this->provider, $res['integration_name']);

        $meta = Meta::query()->where('object_type', 'order_integration')
            ->where('meta_key', $this->provider)
            ->orderBy('id', 'DESC')
            ->first();
        $this->assertNotNull($meta);
        $this->assertSame('Created', $meta->meta_value['name'] ?? null);
    }

    public function test_save_integration_settings_updates_existing_meta(): void
    {
        $existing = $this->addGlobalFeed($this->provider, ['enabled' => 'yes', 'name' => 'Old']);

        $res = (new GlobalIntegrationSettings())->saveIntegrationSettings([
            'integration_id'   => $existing->id,
            'integration_name' => $this->provider,
            'integration'      => json_encode(['enabled' => 'yes', 'name' => 'Updated']),
        ]);
        $this->assertIsArray($res);
        $this->assertFalse((bool) $res['created']);
        $fresh = Meta::query()->find($existing->id);
        $this->assertSame('Updated', $fresh->meta_value['name']);
    }

    // Spec 139: deleteIntegrationFeed removes meta
    public function test_delete_integration_feed_removes_meta(): void
    {
        $feed = $this->addGlobalFeed($this->provider, ['enabled' => 'yes', 'name' => 'ToDelete']);
        (new GlobalIntegrationSettings())->deleteIntegrationFeed(['integration_id' => $feed->id]);
        $this->assertNull(Meta::query()->find($feed->id));
    }

    // Spec 140: getIntegrationList returns merge_fields via filter
    public function test_get_integration_list_returns_merge_fields(): void
    {
        add_filter('fluent_cart/integration/get_integration_merge_fields_' . $this->provider, function () {
            return ['first_name' => 'First Name'];
        });
        $res = (new GlobalIntegrationSettings())->getIntegrationList([
            'integration_name' => $this->provider,
            'list_id'          => 'abc',
        ]);
        remove_all_filters('fluent_cart/integration/get_integration_merge_fields_' . $this->provider);
        $this->assertSame(['first_name' => 'First Name'], $res['merge_fields']);
    }

    // Spec 141: chainedData fires route-specific action
    public function test_chained_data_fires_route_action(): void
    {
        $fired = null;
        add_action('fluent_cart/integration/chained_myroute', function ($payload) use (&$fired) {
            $fired = $payload;
        });
        (new GlobalIntegrationSettings())->chainedData(['route' => 'myroute', 'x' => 1]);
        $this->assertIsArray($fired);
        $this->assertArrayHasKey('data', $fired);
        $this->assertSame('myroute', $fired['data']['route']);
    }

    // Spec 142: permission_denied when user lacks install_plugins
    public function test_install_plugin_permission_denied_without_cap(): void
    {
        $userId = static::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($userId);

        $res = (new GlobalIntegrationSettings())->installPlugin('fluent-crm');
        wp_set_current_user(0);

        $this->assertInstanceOf(\WP_Error::class, $res);
        $this->assertSame('permission_denied', $res->get_error_code());
    }

    // Spec 143: aliases 'fluentcrm' → 'fluent-crm'
    public function test_install_plugin_aliases_fluentcrm(): void
    {
        $userId = static::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);

        // Capture slug passed to BackgroundInstaller via allowlist filter
        $observed = null;
        add_filter('fluent_cart/installable_repo_plugins', function ($plugins) use (&$observed) {
            // Filter runs with the (already-aliased) slug held in $pluginSlug closure; can't read it here.
            return $plugins;
        });

        $res = (new GlobalIntegrationSettings())->installPlugin('fluentcrm');
        wp_set_current_user(0);

        // Expected: installPlugin proceeds past allowlist since 'fluent-crm' is in the default list.
        // Real install may fail without network; assert we did NOT get an invalid_plugin error (proving aliasing applied).
        if ($res instanceof \WP_Error) {
            $this->assertNotSame('invalid_plugin', $res->get_error_code(), 'aliasing must convert fluentcrm → fluent-crm BEFORE allowlist check');
        } else {
            $this->assertIsArray($res);
        }
    }

    // Spec 144: invalid slug returns invalid_plugin error
    public function test_install_plugin_rejects_non_allowlisted_slug(): void
    {
        $userId = static::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);

        $res = (new GlobalIntegrationSettings())->installPlugin('evil-plugin');
        wp_set_current_user(0);

        $this->assertInstanceOf(\WP_Error::class, $res);
        $this->assertSame('invalid_plugin', $res->get_error_code());
    }

    // Spec 145: installable_repo_plugins filter extensible
    public function test_install_plugin_allowlist_is_filterable(): void
    {
        $userId = static::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);

        $filter = function ($plugins) {
            $plugins[] = 'my-custom-plugin';
            return $plugins;
        };
        add_filter('fluent_cart/installable_repo_plugins', $filter);

        $res = (new GlobalIntegrationSettings())->installPlugin('my-custom-plugin');
        remove_filter('fluent_cart/installable_repo_plugins', $filter);
        wp_set_current_user(0);

        if ($res instanceof \WP_Error) {
            $this->assertNotSame('invalid_plugin', $res->get_error_code());
        } else {
            $this->assertIsArray($res);
        }
    }

    // Spec 148: changeStatus updates meta enabled flag
    public function test_change_status_toggles_enabled_flag(): void
    {
        $feed = $this->addGlobalFeed($this->provider, ['enabled' => 'no', 'name' => 'Toggle']);
        (new GlobalIntegrationSettings())->changeStatus([
            'notification_id' => $feed->id,
            'status'          => 'yes',
        ]);
        $fresh = Meta::query()->find($feed->id);
        $this->assertSame('yes', $fresh->meta_value['enabled']);

        (new GlobalIntegrationSettings())->changeStatus([
            'notification_id' => $feed->id,
            'status'          => 'no',
        ]);
        $fresh2 = Meta::query()->find($feed->id);
        $this->assertSame('no', $fresh2->meta_value['enabled']);
    }
}
