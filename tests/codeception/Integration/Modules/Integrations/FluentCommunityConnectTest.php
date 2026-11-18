<?php

namespace Tests\Integration\Modules\Integrations;

use FluentCart\App\Modules\Integrations\FluentPlugins\FluentCommunityConnect;
use lucatume\WPBrowser\TestCase\WPTestCase;

class FluentCommunityConnectTest extends WPTestCase
{
    // Spec 41: constructor identity and meta
    public function test_constructor_sets_identity_and_install_meta(): void
    {
        $mgr = new FluentCommunityConnect();
        $ref = new \ReflectionObject($mgr);

        $title = $ref->getProperty('title'); $title->setAccessible(true);
        $key   = $ref->getProperty('integrationKey'); $key->setAccessible(true);
        $prio  = $ref->getProperty('priority'); $prio->setAccessible(true);

        $this->assertSame('FluentCommunity', $title->getValue($mgr));
        $this->assertSame('fluent_community', $key->getValue($mgr));
        $this->assertSame(12, $prio->getValue($mgr));
        $this->assertSame('lms', $mgr->category);
        $this->assertTrue($mgr->disableGlobalSettings);
        $this->assertSame('fluent-community/fluent-community.php', $mgr->installable);
    }

    // Spec 42: isConfigured mirrors FLUENT_COMMUNITY_PLUGIN_VERSION
    public function test_is_configured_returns_constant_state(): void
    {
        $mgr = new FluentCommunityConnect();
        $this->assertSame(defined('FLUENT_COMMUNITY_PLUGIN_VERSION'), $mgr->isConfigured());
    }

    // Spec 43: defaults keys
    public function test_integration_defaults_keys(): void
    {
        $mgr = new FluentCommunityConnect();
        $defaults = $mgr->getIntegrationDefaults([]);
        foreach (['enabled', 'name', 'space_ids', 'remove_space_ids', 'course_ids', 'remove_course_ids', 'event_trigger', 'tag_ids_selection_type', 'mark_as_verified', 'watch_on_access_revoke'] as $k) {
            $this->assertArrayHasKey($k, $defaults, "defaults missing key {$k}");
        }
        $this->assertSame('yes', $defaults['enabled']);
        $this->assertSame('no', $defaults['mark_as_verified']);
        $this->assertSame('yes', $defaults['watch_on_access_revoke']);
    }


    // Pro-required gate: without FLUENT_COMMUNITY_PLUGIN_VERSION the run hook must not be bound.
    public function test_processaction_hook_not_registered_when_pro_plugin_absent(): void
    {
        // Integration.suite.yml loads only FluentCart, so the constant is absent unless a test leaks it.
        $this->assertFalse(defined('FLUENT_COMMUNITY_PLUGIN_VERSION'), 'FluentCommunity must not be loaded in the Integration suite');

        (new FluentCommunityConnect())->register();
        $this->assertFalse(
            has_action('fluent_cart/integration/run/fluent_community'),
            'Pro-required gate: run/{key} action MUST NOT be bound without FluentCommunity'
        );

        // dispatch is safe no-op (other listeners may still exist; we only assert this provider is not invoked)
        do_action('fluent_cart/integration/run/fluent_community', ['order' => null]);
    }
}
