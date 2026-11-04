<?php

namespace Tests\Integration\Services;

use FluentCart\App\Services\Integration;
use lucatume\WPBrowser\TestCase\WPTestCase;

class IntegrationBootstrapTest extends WPTestCase
{
    // Spec 1: Integration::register schedules init() on `init` priority 2
    public function test_register_schedules_init_on_init_priority_2(): void
    {
        // init has already fired during bootstrap; re-register and assert `init` has a closure at priority 2
        (new Integration())->register();

        $priority = has_action('init', function () {});
        $this->assertTrue(has_action('init') !== false, 'init must have at least one action bound');

        global $wp_filter;
        $this->assertArrayHasKey('init', $wp_filter);
        $callbacks = $wp_filter['init']->callbacks;
        $this->assertArrayHasKey(2, $callbacks, 'Integration::register must bind at priority 2');
    }

    // Spec 2: FluentCRMConnect and FluentCommunityConnect always registered post-boot (regardless of constants)
    public function test_fluentcrm_and_fluentcommunity_always_appear_in_order_integrations_filter(): void
    {
        $addons = apply_filters('fluent_cart/integration/order_integrations', []);
        $this->assertArrayHasKey('fluentcrm', $addons, 'FluentCRMConnect must register its entry');
        $this->assertArrayHasKey('fluent_community', $addons, 'FluentCommunityConnect must register its entry');
    }

    // Spec 3: FluentCRMDeepIntegration::init() only runs when FLUENTCRM defined
    public function test_fluentcrm_deep_integration_gated_by_fluentcrm_constant(): void
    {
        if (!defined('FLUENTCRM')) {
            // Deep filters must not be registered
            $this->assertFalse(has_filter('fluentcrm_contacts_filter_fluent_cart'), 'deep integration must NOT register when FLUENTCRM undefined');
            return;
        }
        // When FLUENTCRM defined, deep filters must be bound
        $this->assertNotFalse(has_filter('fluentcrm_contacts_filter_fluent_cart'));
        $this->assertNotFalse(has_filter('fluentcrm_advanced_filter_options'));
    }

    // Spec 4: FluentSupportWidget registered only when FLUENT_SUPPORT_VERSION defined
    public function test_fluent_support_widget_gated_by_constant(): void
    {
        if (!defined('FLUENT_SUPPORT_VERSION')) {
            $this->assertFalse(has_filter('fluent_support/customer_extra_widgets'), 'widget must NOT register when FLUENT_SUPPORT_VERSION undefined');
            return;
        }
        $this->assertNotFalse(has_filter('fluent_support/customer_extra_widgets'));
    }

    // Spec 5: integration boot wired from app/Hooks/actions.php (state must be visible post-boot)
    public function test_integration_event_listener_hooks_bound_after_boot(): void
    {
        // IntegrationEventListener::registerHooks is called from the same init callback that fires the providers
        $this->assertNotFalse(has_action('fluent_cart/order_paid_done'), 'listener must bind order_paid_done on boot');
        $this->assertNotFalse(has_action('fluent_cart/init_order_async_runner'));
        $this->assertNotFalse(has_action('wp_ajax_fluent_cart_run_order_actions'));
    }
}
