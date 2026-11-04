<?php

namespace Tests\Integration\Modules\Shipping;

use FluentCart\App\Modules\Shipping\Carriers\Core\CarrierManager;
use FluentCart\App\Modules\Shipping\Services\CarrierSettingsService;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Shipping\StubCarrierSettings;
use Tests\Support\Shipping\StubShippingCarrier;

/**
 * A 'mode' field nests each option's fields. The save and mask paths must
 * treat those nested fields exactly like top-level ones.
 */
class CarrierModeFieldTest extends DatabaseTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        StubShippingCarrier::install();
        StubShippingCarrier::$fields = [
            ['key' => 'is_active', 'type' => 'toggle', 'label' => 'Enabled'],
            [
                'key'     => 'sandbox_mode',
                'type'    => 'mode',
                'label'   => 'Connection Mode',
                'options' => [
                    ['value' => 'no', 'label' => 'Live', 'fields' => [
                        ['key' => 'live_token', 'type' => 'password', 'label' => 'Live token'],
                        ['key' => 'live_account', 'type' => 'text', 'label' => 'Live account'],
                    ]],
                    ['value' => 'yes', 'label' => 'Test', 'fields' => [
                        ['key' => 'test_token', 'type' => 'text', 'label' => 'Test token', 'is_secret' => true],
                        ['key' => 'test_labels', 'type' => 'toggle', 'label' => 'Test labels'],
                    ]],
                ],
            ],
            ['key' => 'origin', 'type' => 'text', 'label' => 'Origin'],
        ];
    }

    public function tearDown(): void
    {
        StubShippingCarrier::uninstall();
        parent::tearDown();
    }

    private function carrier()
    {
        return CarrierManager::carrier(StubShippingCarrier::SLUG);
    }

    public function test_flatten_fields_lists_the_mode_field_and_every_option_field(): void
    {
        $keys = array_column(CarrierManager::flattenFields(StubShippingCarrier::$fields), 'key');

        $this->assertSame(
            ['is_active', 'sandbox_mode', 'live_token', 'live_account', 'test_token', 'test_labels', 'origin'],
            $keys,
            'flattenFields must keep order and include nested option fields'
        );
    }

    public function test_nested_text_field_is_saved_and_sanitized(): void
    {
        $result = CarrierSettingsService::save($this->carrier(), [
            'sandbox_mode' => 'yes',
            'live_account' => ' <b>acct_1</b> ',
            'origin'       => 'Dhaka',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('acct_1', StubCarrierSettings::$saved['live_account'] ?? null, 'a nested text field must be saved through sanitize');
        $this->assertSame('yes', StubCarrierSettings::$saved['sandbox_mode'] ?? null, 'the mode value must be saved');
        $this->assertSame('Dhaka', StubCarrierSettings::$saved['origin'] ?? null, 'a top-level field must still be saved');
    }

    public function test_nested_secrets_are_kept_verbatim_and_empty_means_unchanged(): void
    {
        CarrierSettingsService::save($this->carrier(), [
            'live_token' => 'tok_a%2Fb<c>',
            'test_token' => '',
        ]);

        $this->assertSame('tok_a%2Fb<c>', StubCarrierSettings::$saved['live_token'] ?? null, 'a nested password must not pass through sanitize_text_field');
        $this->assertArrayNotHasKey('test_token', StubCarrierSettings::$saved, 'an empty nested secret must be dropped so the stored one is kept');

        CarrierSettingsService::save($this->carrier(), ['test_token' => ['x']]);
        $this->assertArrayNotHasKey('test_token', StubCarrierSettings::$saved, 'a non-scalar nested secret must be dropped');
    }

    public function test_whitespace_only_secret_means_unchanged(): void
    {
        StubCarrierSettings::$saved = ['live_token' => 'shippo_live_0123456789abcdef2a9f'];

        CarrierSettingsService::save($this->carrier(), ['live_token' => '   ', 'origin' => 'Dhaka']);

        $this->assertArrayNotHasKey('live_token', StubCarrierSettings::$saved, 'a whitespace-only secret must be dropped so the stored one is kept');

        // A blank value stored before this fix must not be shown as saved.
        $saved = CarrierManager::savedSecrets(StubShippingCarrier::$fields, ['live_token' => "  \t"]);
        $this->assertSame([], $saved, 'a whitespace-only stored secret is not a saved secret');
    }

    public function test_nested_secrets_are_masked_in_responses(): void
    {
        StubCarrierSettings::$saved = [
            'sandbox_mode' => 'no',
            'live_token'   => 'tok_live',
            'test_token'   => 'tok_test',
            'live_account' => 'acct_1',
            'oauth_secret' => 'internal',
        ];

        $described = CarrierManager::getInstance()->describeCarrier(StubShippingCarrier::SLUG);
        $settings  = $described['settings'];

        $this->assertSame('', $settings['live_token'], 'a nested password must be masked');
        $this->assertSame('', $settings['test_token'], 'a nested is_secret field must be masked');
        $this->assertSame('acct_1', $settings['live_account'], 'a nested plain field must be returned');
        $this->assertSame('no', $settings['sandbox_mode'], 'the mode value must be returned');
        $this->assertArrayNotHasKey('oauth_secret', $settings, 'an undeclared stored key must never be returned');
    }

    public function test_saved_secrets_are_flagged_without_their_value(): void
    {
        StubCarrierSettings::$saved = [
            'sandbox_mode' => 'no',
            'live_token'   => 'shippo_live_0123456789abcdef2a9f',
            'test_token'   => '',
            'oauth_secret' => 'internal',
        ];

        $described = CarrierManager::getInstance()->describeCarrier(StubShippingCarrier::SLUG);

        $this->assertSame(['live_token' => ['ends_with' => '2a9f']], $described['saved_secrets'], 'only a stored declared secret is flagged, with its last four characters');
        $this->assertSame('', $described['settings']['live_token'], 'the secret itself stays masked');
        $this->assertStringNotContainsString('0123456789abcdef', wp_json_encode($described), 'the secret never reaches the payload');

        StubCarrierSettings::$saved = ['test_token' => 'short'];
        $described = CarrierManager::getInstance()->describeCarrier(StubShippingCarrier::SLUG);
        $this->assertSame(['test_token' => ['ends_with' => '']], $described['saved_secrets'], 'a short secret is flagged without revealing any of it');
    }

    public function test_undeclared_key_is_still_dropped(): void
    {
        CarrierSettingsService::save($this->carrier(), [
            'origin'   => 'Dhaka',
            'options'  => 'x',
            'injected' => 'x',
        ]);

        $this->assertArrayNotHasKey('injected', StubCarrierSettings::$saved, 'an undeclared key must be dropped');
        $this->assertArrayNotHasKey('options', StubCarrierSettings::$saved, 'a mode field attribute name is not a settings key');
    }

    public function test_mode_value_outside_its_options_is_dropped(): void
    {
        StubCarrierSettings::$saved = ['sandbox_mode' => 'no'];

        CarrierSettingsService::save($this->carrier(), ['sandbox_mode' => 'maybe', 'origin' => 'Dhaka']);
        $this->assertArrayNotHasKey('sandbox_mode', StubCarrierSettings::$saved, 'an unknown mode value must not be saved');

        CarrierSettingsService::save($this->carrier(), ['sandbox_mode' => ['yes']]);
        $this->assertArrayNotHasKey('sandbox_mode', StubCarrierSettings::$saved, 'a non-scalar mode value must not be saved');
    }

    public function test_nested_toggle_is_normalized(): void
    {
        CarrierSettingsService::save($this->carrier(), ['test_labels' => true, 'is_active' => false]);

        $this->assertSame('yes', StubCarrierSettings::$saved['test_labels'] ?? null, 'a nested toggle must be normalized to yes/no');
        $this->assertSame('no', StubCarrierSettings::$saved['is_active'] ?? null, 'a top-level toggle must still be normalized');
    }

    public function test_describe_carrier_keeps_the_nested_field_shape(): void
    {
        $described = CarrierManager::getInstance()->describeCarrier(StubShippingCarrier::SLUG);

        $this->assertSame(StubShippingCarrier::$fields, $described['fields'], 'Vue needs the nested fields as declared');
    }

    public function test_describe_carrier_flags_a_field_key_declared_twice(): void
    {
        $this->setExpectedIncorrectUsage(CarrierManager::class . '::describeCarrier');

        // A nested field reusing a top-level key would share one stored setting.
        StubShippingCarrier::$fields[1]['options'][1]['fields'][] = ['key' => 'origin', 'type' => 'text', 'label' => 'Test origin'];

        $described = CarrierManager::getInstance()->describeCarrier(StubShippingCarrier::SLUG);

        $this->assertIsArray($described, 'the carrier must still be described');
    }
}
