<?php

namespace Tests\Unit\Modules\Shipping;

use Codeception\Test\Unit;
use FluentCart\App\Modules\Shipping\Carriers\Core\CarrierManager;

/**
 * A 'section' entry is a heading in the settings form, and a 'webhook_url' entry
 * places the carrier's webhook URL. Neither holds a value, so both stay out of
 * flattenFields(), which every save, mask and secret path reads, and a carrier
 * reads FIELD_TYPES to know the form draws them.
 */
class CarrierSectionFieldTest extends Unit
{
    public function test_flatten_fields_leaves_out_sections(): void
    {
        $fields = [
            ['key' => 'connection', 'type' => 'section', 'label' => 'Connection'],
            ['key' => 'is_active', 'type' => 'toggle', 'label' => 'Enabled'],
            ['key' => 'sandbox_mode', 'type' => 'mode', 'label' => 'Mode', 'options' => [
                ['value' => 'no', 'label' => 'Live', 'fields' => [['key' => 'live_token', 'type' => 'password', 'label' => 'Live token']]],
            ]],
            ['key' => 'labels', 'type' => 'section', 'label' => 'Labels'],
            ['key' => 'origin', 'type' => 'text', 'label' => 'Origin'],
            ['key' => 'webhook', 'type' => 'webhook_url'],
        ];

        $this->assertSame(
            ['is_active', 'sandbox_mode', 'live_token', 'origin'],
            array_column(CarrierManager::flattenFields($fields), 'key'),
            'a section or webhook URL holds no value, so no save or mask path may see it'
        );
    }

    public function test_flatten_fields_leaves_out_markers_inside_a_mode_option(): void
    {
        $fields = [
            ['key' => 'sandbox_mode', 'type' => 'mode', 'label' => 'Mode', 'options' => [
                ['value' => 'yes', 'label' => 'Test', 'fields' => [
                    ['key' => 'test_heading', 'type' => 'section', 'label' => 'Test account'],
                    ['key' => 'test_token', 'type' => 'password', 'label' => 'Test token'],
                    ['key' => 'test_webhook', 'type' => 'webhook_url'],
                ]],
            ]],
        ];

        $this->assertSame(
            ['sandbox_mode', 'test_token'],
            array_column(CarrierManager::flattenFields($fields), 'key'),
            'a marker inside a mode option holds no value either'
        );
    }

    public function test_field_types_lists_every_drawn_type(): void
    {
        foreach (['text', 'password', 'toggle', 'select', 'mode', 'section', 'webhook_url'] as $type) {
            $this->assertContains($type, CarrierManager::FIELD_TYPES);
        }
    }
}
