<?php

namespace Tests\Integration\Services;

use FluentCart\App\Services\Email\ConditionPresets;
use lucatume\WPBrowser\TestCase\WPTestCase;

class ConditionPresetsTest extends WPTestCase
{
    // =========================================================================
    // Spec 115: all() returns 12 expected preset ids
    // =========================================================================

    public function test_all_returns_expected_preset_ids(): void
    {
        $expected = [
            'is_digital_order',
            'is_physical_order',
            'is_order_paid',
            'is_order_pending',
            'is_order_failed',
            'has_discount',
            'has_shipping',
            'has_tax',
            'has_refund',
            'has_shipping_tax',
            'has_order_note',
            'has_downloads',
        ];
        $ids = array_column(ConditionPresets::all(), 'id');
        foreach ($expected as $id) {
            $this->assertContains($id, $ids, "Missing preset id: $id");
        }
        $this->assertCount(12, $ids);
    }

    // =========================================================================
    // Spec 116: each preset has required keys
    // =========================================================================

    public function test_each_preset_has_required_keys(): void
    {
        $required = ['id', 'label', 'hint', 'shortcode', 'condition', 'compareValue'];
        foreach (ConditionPresets::all() as $preset) {
            foreach ($required as $key) {
                $this->assertArrayHasKey($key, $preset, "Preset {$preset['id']} missing key $key");
            }
        }
    }

    // =========================================================================
    // Spec 117: is_digital_order shape
    // =========================================================================

    public function test_is_digital_order_shape(): void
    {
        $preset = ConditionPresets::find('is_digital_order');
        $this->assertSame('{{order.is_digital}}', $preset['shortcode']);
        $this->assertSame('equal', $preset['condition']);
        $this->assertSame('yes', $preset['compareValue']);
    }

    // =========================================================================
    // Spec 118: has_discount shape
    // =========================================================================

    public function test_has_discount_shape(): void
    {
        $preset = ConditionPresets::find('has_discount');
        $this->assertSame('{{order.discount_total}}', $preset['shortcode']);
        $this->assertSame('greater_than', $preset['condition']);
        $this->assertSame('0', $preset['compareValue']);
    }

    // =========================================================================
    // Spec 119: filter fluent_cart/condition_presets mutates result
    // =========================================================================

    public function test_filter_can_inject_preset(): void
    {
        add_filter('fluent_cart/condition_presets', static function ($p) {
            $p[] = ['id' => 'custom_added', 'label' => 'X', 'hint' => '', 'shortcode' => '{{x}}', 'condition' => 'equal', 'compareValue' => 'y'];
            return $p;
        });
        try {
            $ids = array_column(ConditionPresets::all(), 'id');
            $this->assertContains('custom_added', $ids);
        } finally {
            remove_all_filters('fluent_cart/condition_presets');
        }
    }

    // =========================================================================
    // Spec 120: find()
    // =========================================================================

    public function test_find_returns_match_or_null(): void
    {
        $this->assertNotNull(ConditionPresets::find('is_order_paid'));
        $this->assertNull(ConditionPresets::find('does_not_exist_xyz'));
    }

    // =========================================================================
    // Spec 121: forJs strips shortcode/condition/compareValue
    // =========================================================================

    public function test_for_js_returns_id_label_hint_only(): void
    {
        $forJs = ConditionPresets::forJs();
        $this->assertNotEmpty($forJs);
        foreach ($forJs as $entry) {
            $this->assertSame(['id', 'label', 'hint'], array_keys($entry));
        }
    }

    // =========================================================================
    // Spec 122: resolve() returns shortcode/condition/compareValue
    // =========================================================================

    public function test_resolve_returns_shortcode_path(): void
    {
        $resolved = ConditionPresets::resolve('is_order_paid');
        $this->assertArrayHasKey('shortcode', $resolved);
        $this->assertArrayHasKey('condition', $resolved);
        $this->assertArrayHasKey('compareValue', $resolved);
    }

    // =========================================================================
    // Spec 123: resolve() returns ['callback' => callable] when preset has callback
    // =========================================================================

    public function test_resolve_returns_callback_when_preset_has_callback(): void
    {
        add_filter('fluent_cart/condition_presets', static function ($p) {
            $p[] = ['id' => 'cb_preset', 'label' => 'X', 'hint' => '', 'callback' => static fn() => true];
            return $p;
        });
        try {
            $resolved = ConditionPresets::resolve('cb_preset');
            $this->assertArrayHasKey('callback', $resolved);
            $this->assertIsCallable($resolved['callback']);
        } finally {
            remove_all_filters('fluent_cart/condition_presets');
        }
    }

    // =========================================================================
    // Spec 124: resolve('unknown') returns null
    // =========================================================================

    public function test_resolve_unknown_returns_null(): void
    {
        $this->assertNull(ConditionPresets::resolve('unknown_preset_xyz'));
    }
}
