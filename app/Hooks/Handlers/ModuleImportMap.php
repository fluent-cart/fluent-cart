<?php

namespace FluentCart\App\Hooks\Handlers;

use FluentCart\App\Vite;

class ModuleImportMap
{
    /**
     * Lets add-on admin bundles import core's Vue and UI kit instead of
     * shipping their own copies (one Vue instance, one app). Element Plus
     * needs no specifier: core registers it globally on the app.
     */
    public static function imports(): array
    {
        $imports = [
            '@fluent-cart/vue'     => static::sharedUrl('module-vue.js'),
            '@fluent-cart/dayjs'   => static::sharedUrl('module-dayjs.js'),
            '@fluent-cart/echarts' => static::sharedUrl('module-echarts.js'),
            '@fluent-cart/ui'      => static::sharedUrl('module-ui.js'),
        ];

        $imports = apply_filters('fluent_cart/admin/module_import_map', $imports);

        return array_filter((array)$imports);
    }

    /**
     * Runs on admin_enqueue_scripts priority 1: a browser drops an import map
     * that comes after any module script, and the head ones print later.
     */
    public static function print(): void
    {
        $imports = static::imports();
        if (!$imports) {
            return;
        }

        $json = wp_json_encode(['imports' => $imports], JSON_HEX_TAG | JSON_UNESCAPED_SLASHES);

        echo '<script type="importmap">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX_TAG keeps </script> out
    }

    /**
     * Pro <= 2.0.0 still enqueues core's licensing bundle, which core no
     * longer builds; drop the request (the licensing routes then show the
     * "update Pro" screen). Remove together with legacyLicensingRoutes.js.
     */
    public static function dropLegacyBundles(): void
    {
        wp_dequeue_script('fluent_cart_licensing');
        wp_deregister_script('fluent_cart_licensing');
    }

    private static function sharedUrl(string $file): string
    {
        $url = Vite::getEnqueuePath('admin/shared/' . $file);
        if (!$url) {
            return '';
        }

        // Same ?ver= stamp the build puts on chunk imports, so an entry and
        // its chunks always come from one cache generation.
        return add_query_arg('ver', FLUENTCART_VERSION, $url);
    }
}
