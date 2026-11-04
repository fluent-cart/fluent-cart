<?php

namespace Tests\Integration\Hooks;

use FluentCart\App\Hooks\Handlers\ModuleImportMap;
use FluentCart\App\Vite;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Pro's licensing screens are built against core's runtime: they import
 * `@fluent-cart/vue`, `@fluent-cart/ui`, ... and the admin page resolves those
 * through this import map. Every specifier must point at the built shared
 * entry with the same ?ver= stamp the build puts on chunk imports, or the
 * browser loads a second Vue and every <el-*> in Pro's screens breaks.
 */
class ModuleImportMapTest extends WPTestCase
{
    private const SPECIFIERS = [
        '@fluent-cart/vue'     => 'module-vue.js',
        '@fluent-cart/dayjs'   => 'module-dayjs.js',
        '@fluent-cart/echarts' => 'module-echarts.js',
        '@fluent-cart/ui'      => 'module-ui.js',
    ];

    protected function tearDown(): void
    {
        remove_all_filters('fluent_cart/admin/module_import_map');
        parent::tearDown();
    }

    public function test_the_module_api_version_is_published(): void
    {
        $this->assertTrue(defined('FLUENTCART_MODULE_API'));
        $this->assertGreaterThanOrEqual(1, FLUENTCART_MODULE_API);
    }

    public function test_every_specifier_points_at_its_versioned_shared_entry(): void
    {
        $imports = ModuleImportMap::imports();

        $this->assertSame(array_keys(self::SPECIFIERS), array_keys($imports));

        foreach (self::SPECIFIERS as $specifier => $file) {
            $this->assertSame(
                $this->expectedUrl($file),
                $imports[$specifier],
                $specifier . ' must resolve to the built shared entry'
            );
        }
    }

    public function test_add_ons_can_extend_the_map_and_empty_entries_are_dropped(): void
    {
        add_filter('fluent_cart/admin/module_import_map', function ($imports) {
            $imports['@my-addon/ui'] = 'https://example.test/ui.js';
            $imports['@fluent-cart/echarts'] = '';
            return $imports;
        });

        $imports = ModuleImportMap::imports();

        $this->assertSame('https://example.test/ui.js', $imports['@my-addon/ui']);
        $this->assertArrayNotHasKey('@fluent-cart/echarts', $imports);
    }

    public function test_print_emits_one_import_map_that_cannot_close_its_script_tag(): void
    {
        add_filter('fluent_cart/admin/module_import_map', function ($imports) {
            $imports['@evil/x'] = 'https://example.test/</script><script>alert(1)</script>.js';
            return $imports;
        });

        ob_start();
        ModuleImportMap::print();
        $html = ob_get_clean();

        $this->assertStringStartsWith('<script type="importmap">', $html);
        $this->assertSame(1, substr_count($html, '</script>'));

        $json = json_decode(substr(trim($html), strlen('<script type="importmap">'), -strlen('</script>')), true);
        $this->assertSame($this->expectedUrl('module-ui.js'), $json['imports']['@fluent-cart/ui']);
    }

    public function test_print_emits_nothing_when_the_map_is_emptied(): void
    {
        add_filter('fluent_cart/admin/module_import_map', '__return_empty_array');

        ob_start();
        ModuleImportMap::print();

        $this->assertSame('', ob_get_clean());
    }

    public function test_an_old_pro_cannot_load_the_licensing_bundle_core_removed(): void
    {
        // Pro <= 2.0.0 still asks core's Vite for licensing/license.js on this hook.
        wp_enqueue_script('fluent_cart_licensing', 'https://example.test/licensing/license.js');

        ModuleImportMap::dropLegacyBundles();

        $this->assertFalse(wp_script_is('fluent_cart_licensing', 'enqueued'));
        $this->assertFalse(wp_script_is('fluent_cart_licensing', 'registered'));
    }

    /**
     * Built entry in production; the source file on the Vite dev server in
     * dev mode, so add-ons resolve to the same Vue the dev app runs.
     */
    private function expectedUrl(string $file): string
    {
        if (!Vite::underDevelopment()) {
            return FLUENTCART_URL . 'assets/' . $file . '?ver=' . FLUENTCART_VERSION;
        }

        return Vite::getEnqueuePath('admin/shared/' . $file) . '?ver=' . FLUENTCART_VERSION;
    }
}
