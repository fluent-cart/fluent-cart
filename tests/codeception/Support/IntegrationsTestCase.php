<?php

namespace Tests\Support;

use FluentCart\App\App;
use FluentCart\App\Models\Meta;
use FluentCart\App\Models\ProductMeta;
use FluentCart\Framework\Http\Request\Request;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Base class for Integrations domain tests. Provides:
 *  - AJAX JSON capture (wp_send_json dies via wp_die — trap + parse)
 *  - FluentCart request rebinding after $_GET/$_REQUEST mutation
 *  - Global / product-scoped feed seeding helpers
 */
abstract class IntegrationsTestCase extends WPTestCase
{
    protected function rebindFluentRequest(): void
    {
        $app = App::getInstance();
        $request = new Request($app, $_GET, $_POST);
        $app->instance(Request::class, $request);
        $app->instance('request', $request);
    }

    protected function captureAjaxJson(callable $action): array
    {
        // Default 200 OK: `wp_send_json($data)` without explicit status code still echoes body without calling status_header.
        $captured = ['payload' => null, 'status' => 200];

        $statusFilter = function ($statusHeader, $code) use (&$captured) {
            $captured['status'] = (int) $code;
            return $statusHeader;
        };
        $dieHandler = function ($message = '') {
            throw new \RuntimeException('__fc_test_ajax_die__:' . (string) $message);
        };
        $forceAjax = function () { return true; };
        add_filter('wp_doing_ajax', $forceAjax);
        add_filter('wp_die_ajax_handler', function () use ($dieHandler) { return $dieHandler; });
        add_filter('wp_die_handler', function () use ($dieHandler) { return $dieHandler; });
        add_filter('status_header', $statusFilter, 10, 2);

        ob_start();
        try {
            $action();
        } catch (\RuntimeException $e) {
            if (strpos($e->getMessage(), '__fc_test_ajax_die__:') !== 0) {
                ob_end_clean();
                remove_filter('wp_doing_ajax', $forceAjax);
                remove_filter('status_header', $statusFilter, 10);
                remove_all_filters('wp_die_ajax_handler');
                remove_all_filters('wp_die_handler');
                throw $e;
            }
        }
        $body = ob_get_clean();
        if ($body !== '' && $body !== false) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $captured['payload'] = $decoded;
            }
        }
        remove_filter('wp_doing_ajax', $forceAjax);
        remove_filter('status_header', $statusFilter, 10);
        remove_all_filters('wp_die_ajax_handler');
        remove_all_filters('wp_die_handler');
        return $captured;
    }

    protected function addGlobalFeed(string $provider, array $integration): Meta
    {
        return Meta::query()->create([
            'object_type' => 'order_integration',
            'meta_key'    => $provider,
            'meta_value'  => $integration,
        ]);
    }

    protected function addProductFeed(int $productId, string $provider, array $integration): ProductMeta
    {
        return ProductMeta::query()->create([
            'object_id'   => $productId,
            'object_type' => 'product_integration',
            'meta_key'    => $provider,
            'meta_value'  => $integration,
        ]);
    }

    protected function registerAddonInFilter(string $provider, array $opts = []): callable
    {
        $filter = function ($addons) use ($provider, $opts) {
            $addons[$provider] = array_merge([
                'enabled'                 => true,
                'priority'                => 5,
                'scopes'                  => ['global', 'product'],
                'delay_on_product_action' => false,
                'delay_on_global_action'  => false,
            ], $opts);
            return $addons;
        };
        add_filter('fluent_cart/integration/order_integrations', $filter);
        return $filter;
    }
}
