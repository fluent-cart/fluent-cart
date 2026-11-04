<?php

namespace Tests\Integration\Modules\Integrations;

use FluentCart\App\Models\Meta;
use FluentCart\App\Modules\Integrations\AddOnModule;
use lucatume\WPBrowser\TestCase\WPTestCase;

class AddOnModuleTest extends WPTestCase
{
    // Spec 77: showAddOns() returns filter result keyed by 'addOns'
    public function test_show_addons_returns_filter_result(): void
    {
        $sentinel = function ($addons) {
            $addons['__test_addon'] = ['enabled' => true, 'priority' => 1, 'title' => 'Test'];
            return $addons;
        };
        add_filter('fluent_cart/integration/order_integrations', $sentinel, 99);

        $result = AddOnModule::showAddOns();
        remove_filter('fluent_cart/integration/order_integrations', $sentinel, 99);

        $this->assertArrayHasKey('addOns', $result);
        $this->assertArrayHasKey('__test_addon', $result['addOns']);
    }

    // Spec 78: updateAddOnsStatus persists fluent_cart_global_integrations option
    public function test_update_addons_status_persists_option(): void
    {
        $mod = new AddOnModule();
        $response = $mod->updateAddOnsStatus([
            'addons' => ['fluentcrm' => 'yes', 'slack' => 'no'],
        ]);

        $this->assertSame(['fluentcrm' => 'yes', 'slack' => 'no'], fluent_cart_get_option('fluent_cart_global_integrations', null, false));
        $this->assertIsArray($response);
        $this->assertArrayHasKey('message', $response);
    }

    // Spec 79: getPremiumAddOns() empty in core
    public function test_get_premium_addons_empty_in_core(): void
    {
        $this->assertSame([], AddOnModule::getPremiumAddOns());
    }

    // Spec 80: getFluentCrm descriptor for install prompt
    public function test_get_fluentcrm_returns_install_descriptor(): void
    {
        $out = AddOnModule::getFluentCrm();
        $this->assertArrayHasKey('fluent-crm', $out);
        $entry = $out['fluent-crm'];
        foreach (['title', 'description', 'logo', 'enabled', 'purchase_url', 'category', 'btnTxt'] as $k) {
            $this->assertArrayHasKey($k, $entry);
        }
        $this->assertSame('crm', $entry['category']);
        $this->assertSame('yes', $entry['enabled']);
        $this->assertStringContainsString('fluent-crm', $entry['purchase_url']);
    }

    // Spec 81: getFluentSupport empty in core
    public function test_get_fluent_support_empty_in_core(): void
    {
        $this->assertSame([], AddOnModule::getFluentSupport());
    }
}
