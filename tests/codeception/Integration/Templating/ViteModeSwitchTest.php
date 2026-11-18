<?php

namespace Tests\Integration\Templating;

use FluentCart\App\App;
use FluentCart\App\Vite;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Vite keeps one instance for the whole process. An instance first built in dev mode
 * must still find the build manifest when a later request runs in production mode.
 */
class ViteModeSwitchTest extends WPTestCase
{
    private $previousEnv;

    public function setUp(): void
    {
        parent::setUp();
        $this->previousEnv = App::config()->get('app.env');
        $GLOBALS['wp_scripts'] = new \WP_Scripts();
        // Start with no instance, so this test decides the mode of the first build.
        (new \ReflectionProperty(Vite::class, 'instance'))->setValue(null, null);
    }

    public function tearDown(): void
    {
        App::config()->set('app.env', $this->previousEnv);
        parent::tearDown();
    }

    public function test_a_production_enqueue_resolves_its_built_file_after_a_dev_mode_instance(): void
    {
        App::config()->set('app.env', 'dev');
        $this->assertTrue(Vite::underDevelopment(), 'The instance is first built in dev mode.');

        App::config()->set('app.env', 'production');
        Vite::enqueueScript('fct-test-mode-switch', 'public/globals/FluentCartApp.js');

        $this->assertArrayHasKey('fct-test-mode-switch', wp_scripts()->registered, 'The production enqueue registers the script.');
        $manifest = App::config()->get('vite_config');
        $this->assertSame(
            App::getInstance()['url.assets'] . $manifest['resources/public/globals/FluentCartApp.js']['file'],
            wp_scripts()->registered['fct-test-mode-switch']->src,
            'The source is the built file that the manifest names for this entry.'
        );
    }
}
