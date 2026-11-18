<?php

namespace Tests\Integration\Templating;

use FluentCart\App\Vite;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * A block theme prints WordPress's import map in the head, after the head scripts. Firefox
 * ignores an import map that comes after a module script, so every Interactivity API block
 * on the page (Accordion, Navigation) stops working. Storefront module scripts, and the
 * libraries that depend on them, must therefore print in the footer.
 */
class ModuleScriptsAfterImportMapTest extends WPTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_scripts'] = new \WP_Scripts();
    }

    public function tearDown(): void
    {
        unset($GLOBALS['current_screen']);
        parent::tearDown();
    }

    public function test_a_storefront_module_script_prints_in_the_footer(): void
    {
        Vite::enqueueScript('fct-test-module', 'public/globals/FluentCartApp.js');

        ob_start();
        wp_scripts()->do_head_items();
        $head = (string) ob_get_clean();
        ob_start();
        wp_scripts()->do_footer_items();
        $footer = (string) ob_get_clean();

        $this->assertStringNotContainsString('id="fct-test-module-js"', $head, 'A module script in the head comes before the import map.');
        $this->assertStringContainsString('id="fct-test-module-js"', $footer, 'The module script prints in the footer.');
    }

    public function test_a_library_queued_before_its_module_script_does_not_pull_it_into_the_head(): void
    {
        // Toastify, Swiper and noUiSlider are queued before the module script they depend on.
        Vite::enqueueStaticScript('fct-test-library', 'public/lib/toastify/toastify-js-1.12.0.js', ['fct-test-module']);
        Vite::enqueueScript('fct-test-module', 'public/globals/FluentCartApp.js');

        ob_start();
        wp_scripts()->do_head_items();
        $head = (string) ob_get_clean();
        ob_start();
        wp_scripts()->do_footer_items();
        $footer = (string) ob_get_clean();

        $this->assertStringNotContainsString('id="fct-test-module-js"', $head, 'A head script would pull its module dependency into the head.');
        $this->assertStringNotContainsString('id="fct-test-library-js"', $head, 'The library prints in the footer with its dependency.');
        $this->assertStringContainsString('id="fct-test-module-js"', $footer, 'The module script prints in the footer.');
        $this->assertStringContainsString('id="fct-test-library-js"', $footer, 'The library prints in the footer.');
        $this->assertLessThan(
            strpos($footer, 'id="fct-test-library-js"'),
            strpos($footer, 'id="fct-test-module-js"'),
            'The module script still prints before the library that depends on it.'
        );
    }

    public function test_an_admin_script_keeps_the_position_its_caller_chose(): void
    {
        set_current_screen('dashboard');
        Vite::enqueueScript('fct-test-admin-module', 'public/globals/FluentCartApp.js');

        ob_start();
        wp_scripts()->do_head_items();
        $head = (string) ob_get_clean();

        $this->assertStringContainsString('id="fct-test-admin-module-js"', $head, 'Admin screens are out of scope and keep their head scripts.');
    }
}
