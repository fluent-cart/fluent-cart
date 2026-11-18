<?php

namespace Tests\Integration\Modules\Integrations;

use FluentCart\App\Models\Meta;
use FluentCart\App\Modules\Integrations\IntegrationHelper;
use lucatume\WPBrowser\TestCase\WPTestCase;

class IntegrationHelperTest extends WPTestCase
{
    private string $provider;

    public function setUp(): void
    {
        parent::setUp();
        $this->provider = 'helper_test_' . wp_generate_password(6, false);
        add_filter('fluent_cart/integration/get_integration_settings_fields_' . $this->provider, function () {
            return [
                'fields' => [
                    ['key' => 'name', 'label' => 'Name', 'required' => true],
                    ['key' => 'list_ids', 'label' => 'Lists'],
                ],
            ];
        });
    }

    public function tearDown(): void
    {
        remove_all_filters('fluent_cart/integration/get_integration_settings_fields_' . $this->provider);
        remove_all_filters('fluent_cart/integration/integration_saving_data_' . $this->provider);
        remove_all_filters('fluent_cart/integration/editing_integration_' . $this->provider);
        parent::tearDown();
    }

    // Spec 163: missing provider/scope → WP_Error
    public function test_validate_returns_wp_error_when_provider_missing(): void
    {
        $result = IntegrationHelper::validateAndFormatIntegrationFeedSettings([
            'enabled' => 'yes',
            'name'    => 'Feed',
        ], ['scope' => 'global']);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('integration_validation_error', $result->get_error_code());
    }

    // Spec 164: allowlist via Arr::only over known field keys + enabled + conditional_variation_ids
    public function test_validate_strips_unknown_keys(): void
    {
        $result = IntegrationHelper::validateAndFormatIntegrationFeedSettings([
            'enabled'                   => 'yes',
            'name'                      => 'Feed A',
            'list_ids'                  => [1, 2],
            'conditional_variation_ids' => [7, 8],
            'random_garbage_field'      => 'should_strip',
        ], [
            'provider' => $this->provider,
            'scope'    => 'global',
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('list_ids', $result);
        $this->assertArrayHasKey('enabled', $result);
        $this->assertArrayHasKey('conditional_variation_ids', $result);
        $this->assertArrayNotHasKey('random_garbage_field', $result);
    }

    // Spec 165: enabled + required-missing → WP_Error with field-level messages
    public function test_validate_returns_error_when_required_missing_and_enabled(): void
    {
        $result = IntegrationHelper::validateAndFormatIntegrationFeedSettings([
            'enabled' => 'yes',
            // name missing
        ], [
            'provider' => $this->provider,
            'scope'    => 'global',
        ]);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('integration_validation_error', $result->get_error_code());
        $data = $result->get_error_data();
        $this->assertIsArray($data);
        $this->assertArrayHasKey('name', $data);
    }

    // Spec 165: disabled feed skips validation
    public function test_validate_skips_required_check_when_disabled(): void
    {
        $result = IntegrationHelper::validateAndFormatIntegrationFeedSettings([
            'enabled' => false,
        ], [
            'provider' => $this->provider,
            'scope'    => 'global',
        ]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('enabled', $result);
        $this->assertEmpty($result['enabled'], 'a disabled feed stays disabled and skips the required-field check');
    }

    public function test_validate_accepts_a_json_string_and_rejects_a_non_object(): void
    {
        $args = ['provider' => $this->provider, 'scope' => 'global'];
        $settings = ['enabled' => 'yes', 'name' => 'Feed'];

        $this->assertSame(
            IntegrationHelper::validateAndFormatIntegrationFeedSettings($settings, $args),
            IntegrationHelper::validateAndFormatIntegrationFeedSettings(wp_json_encode($settings), $args)
        );

        $result = IntegrationHelper::validateAndFormatIntegrationFeedSettings('not json', $args);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertArrayHasKey('integration', (array) $result->get_error_data());
    }

    // Spec 166: final value filtered through integration_saving_data_{provider}
    public function test_validate_runs_saving_data_filter(): void
    {
        add_filter('fluent_cart/integration/integration_saving_data_' . $this->provider, function ($data) {
            $data['filter_applied'] = '__yes';
            return $data;
        });
        $result = IntegrationHelper::validateAndFormatIntegrationFeedSettings([
            'enabled'  => 'yes',
            'name'     => 'Feed',
            'list_ids' => [1],
        ], [
            'provider' => $this->provider,
            'scope'    => 'global',
        ]);
        $this->assertIsArray($result);
        $this->assertSame('__yes', $result['filter_applied'] ?? null);
    }

    // Spec 167: formatFeedDataForEditing normalizes enabled + runs editing filter
    public function test_format_feed_data_for_editing_shape(): void
    {
        $meta = Meta::query()->create([
            'object_type' => 'order_integration',
            'meta_key'    => $this->provider,
            'meta_value'  => [
                'enabled' => true,
                'name'    => 'Editing Feed',
            ],
        ]);

        add_filter('fluent_cart/integration/editing_integration_' . $this->provider, function ($data) {
            $data['edit_filter_ran'] = true;
            return $data;
        });

        $out = IntegrationHelper::formatFeedDataForEditing($meta, ['scope' => 'global']);
        $this->assertSame($meta->id, $out['id']);
        $this->assertSame('Editing Feed', $out['name']);
        $this->assertTrue($out['enabled']);
        $this->assertSame($this->provider, $out['provider']);
        $this->assertSame('global', $out['scope']);
        $this->assertTrue($out['feed']['edit_filter_ran'] ?? false);
    }
}
