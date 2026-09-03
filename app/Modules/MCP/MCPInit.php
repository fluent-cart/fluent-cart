<?php

namespace FluentCart\App\Modules\MCP;

use FluentCart\App\Modules\MCP\Support\CrmContact;
use FluentCart\App\Modules\MCP\Support\PermissionGate;
use FluentCart\App\Modules\MCP\Tools\ContextTools;

/**
 * Bootstrap for FluentCart's Model Context Protocol (MCP) integration.
 *
 * Wires the WordPress Abilities API (core 6.9+) + the WP MCP Adapter, which is
 * provided by FluentHub (bundled) or the standalone mcp-adapter plugin —
 * whichever is present. FluentCart bundles nothing; it consumes whatever's
 * loaded. If neither is available we surface an admin notice rather than fail
 * silently.
 *
 * The whole surface is gated behind the `mcp_enabled` option (default off) — a
 * store owner turns it on in Settings → MCP and creates an application
 * password. Even when on, the endpoint stays behind WP auth + a FluentCart
 * role (transport gate) + per-ability permission checks.
 *
 * Instantiated from app/Hooks/actions.php under a `function_exists` +
 * `PermissionGate::isEnabled()` guard.
 */
class MCPInit
{
    const SERVER_ID = 'fluent-cart';

    /**
     * Bootstrap entry point, called once from app/Hooks/actions.php.
     *
     * MCP ships OFF; it's enabled in Settings → Features & addon → MCP. The
     * server itself is instantiated only when enabled, so there is zero overhead
     * by default. The Abilities-API / MCP-Adapter hooks inside init() fire only
     * when those systems are present (WP 6.9+ and FluentHub / mcp-adapter).
     *
     * Toolkit discovery and the settings card are registered UNCONDITIONALLY:
     * FluentHub needs to list FluentCart on its MCP page even while disabled
     * so the operator can toggle it on, and the card must be reachable to flip
     * the switch. Both are lightweight add_filter calls — no-ops unless applied.
     */
    public static function boot()
    {
        self::registerWithToolkit();
        self::registerModuleSettings();

        if (PermissionGate::isEnabled()) {
            (new self())->init();
        }
    }

    public function init()
    {
        // Abilities API hooks (fire only on WP 6.9+).
        add_action('wp_abilities_api_categories_init', [$this, 'registerCategory']);
        add_action('wp_abilities_api_init', [$this, 'registerAbilities']);

        // Server registration (fires only when an adapter is loaded).
        add_action('mcp_adapter_init', [$this, 'registerCustomServer']);

        // Keep get-store-context fresh: invalidate its cache when anything it
        // reports changes. One array payload per hook (coding-rule friendly).
        $invalidate = [ContextTools::class, 'invalidateCache'];
        foreach ([
            'fluent_cart/coupon_created',
            'fluent_cart/coupon_updated',
            'fluent_cart/label_created',
            'fluent_cart/label_updated',
            'fluent_cart/store_settings_saved',
            'fluent_cart/payment_settings_saved',
        ] as $hook) {
            add_action($hook, $invalidate);
        }

        // FluentCRM contact context on get-customer / get-order, mirroring the
        // contact widget FluentCRM already renders on those admin screens.
        // No-op unless FluentCRM is active.
        CrmContact::register();

        // Warn the operator if they enabled MCP but no adapter is installed.
        add_action('admin_notices', [$this, 'maybeShowAdapterNotice']);
    }

    public function registerCategory()
    {
        wp_register_ability_category('fluent-cart', [
            'label'       => __('FluentCart', 'fluent-cart'),
            'description' => __('Commerce abilities for FluentCart — orders, customers, products, subscriptions, coupons, and reports.', 'fluent-cart'),
        ]);
    }

    public function registerAbilities()
    {
        AbilitiesRegistrar::register();

        /**
         * Fires after FluentCart registers its core MCP abilities. FluentCart
         * Pro hooks this to register its own abilities (licenses, advanced
         * inventory) under the same `fluent-cart/` namespace.
         *
         * @since 1.0.0
         */
        do_action('fluent_cart/mcp_loaded');
    }

    /**
     * Register the dedicated FluentCart MCP server. Endpoint defaults to
     * /wp-json/fluent-cart/mcp (sibling to, but distinct from, the admin REST
     * namespace so it doesn't get caught by that policy stack).
     *
     * @param object $adapter The \WP\MCP\Core\McpAdapter instance.
     */
    public function registerCustomServer($adapter)
    {
        if (!$adapter || !is_object($adapter) || !method_exists($adapter, 'create_server')) {
            return;
        }

        $abilityNames = array_keys(AbilitiesRegistrar::getDefinitions());

        /**
         * Filter the ability names exposed by the FluentCart MCP server. Pro
         * and extensions push their ability names here.
         *
         * @since 1.0.0
         *
         * @param array $abilityNames Fully-qualified ability names.
         */
        $abilityNames = apply_filters('fluent_cart/mcp_ability_names', $abilityNames);
        $abilityNames = array_values(array_unique(array_filter((array) $abilityNames)));

        $namespace = apply_filters('fluent_cart/mcp_server_namespace', 'fluent-cart');
        $route     = apply_filters('fluent_cart/mcp_server_route', 'mcp');

        $adapter->create_server(
            self::SERVER_ID,
            $namespace,
            $route,
            __('FluentCart MCP Server', 'fluent-cart'),
            __('AI agent tools for FluentCart orders, customers, products, subscriptions, and reports.', 'fluent-cart'),
            defined('FLUENTCART_VERSION') ? FLUENTCART_VERSION : '1.0.0',
            ['\WP\MCP\Transport\HttpTransport'],
            '\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler',
            '\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler',
            $abilityNames,
            [],
            [],
            [PermissionGate::class, 'transport']
        );
    }

    /**
     * Announce FluentCart to FluentHub's MCP page (Settings → MCP).
     *
     * FluentHub hardcodes FluentCRM but discovers every other product
     * through the `fluent_kit/mcp_products` + `fluent_kit/mcp_toggle_handlers`
     * filters. Without these, FluentCart's server is fully functional yet never
     * appears in the Toolkit's list — which is exactly the symptom here.
     *
     * Runs UNCONDITIONALLY (even when MCP is OFF) so the operator can see the
     * card and flip it on from the Toolkit; the toggle handler maps that switch
     * onto our `mcp_enabled` option. Both filters are cheap no-ops unless the
     * Toolkit actually applies them, so there's no cost when it's absent.
     */
    public static function registerWithToolkit()
    {
        add_filter('fluent_kit/mcp_products', function ($products) {
            if (!is_array($products)) {
                $products = [];
            }

            $products[] = [
                'slug'         => self::SERVER_ID,
                'name'         => __('FluentCart', 'fluent-cart'),
                'mcp_enabled'  => PermissionGate::isEnabled(),
                'tools_count'  => self::toolsCount(),
                'endpoint_url' => self::getEndpointUrl(),
                'status'       => self::toolkitStatus(),
            ];

            return $products;
        });

        add_filter('fluent_kit/mcp_toggle_handlers', function ($handlers) {
            if (!is_array($handlers)) {
                $handlers = [];
            }

            $handlers[self::SERVER_ID] = [
                'get_enabled' => [PermissionGate::class, 'isEnabled'],
                'set_enabled' => function ($enabled) {
                    return PermissionGate::setEnabled($enabled);
                },
            ];

            return $handlers;
        });
    }

    /**
     * Register the MCP card on Settings → Features & addon. Runs UNCONDITIONALLY
     * (even when MCP is OFF) so the operator can find it and turn it on. The card
     * renders the McpSettings.vue component, which drives the settings/mcp* REST
     * endpoints (instant toggle + connection helpers) rather than the generic
     * module-settings save — though the on/off flag itself lives under the `mcp`
     * key of the shared modules blob (see PermissionGate::isEnabled/setEnabled).
     */
    public static function registerModuleSettings()
    {
        // Priority 100 so MCP is appended AFTER the other modules (which all
        // register at the default priority 10), keeping it at the end of the
        // Features & addon list rather than in the middle.
        add_filter('fluent_cart/module_setting/fields', function ($fields) {
            if (!is_array($fields)) {
                $fields = [];
            }
            $fields['mcp'] = [
                'title'       => __('MCP for AI Agents', 'fluent-cart'),
                'description' => __('Let AI assistants (Claude, Cursor, and other MCP clients) securely read your store and run operator tasks via the Model Context Protocol. Ships off; enable it and connect with an application password.', 'fluent-cart'),
                'type'        => 'component',
                'component'   => 'McpSettings',
            ];
            return $fields;
        }, 100);

        // Default the `mcp.active` flag to off so the shared modules blob always
        // carries a structured value — the McpSettings.vue model stays in sync
        // with it, so the generic "Save Settings" can't clobber the toggle.
        add_filter('fluent_cart/module_setting/default_values', function ($defaults) {
            if (!is_array($defaults)) {
                $defaults = [];
            }
            $defaults['mcp'] = ['active' => 'no'];
            return $defaults;
        });
    }

    /**
     * Count of abilities the server exposes, including any pushed by Pro via the
     * `fluent_cart/mcp_ability_names` filter. Mirrors how the Toolkit counts
     * FluentCRM's tools.
     */
    public static function toolsCount()
    {
        $names = array_keys(AbilitiesRegistrar::getDefinitions());
        $names = apply_filters('fluent_cart/mcp_ability_names', $names);

        return is_array($names) ? count(array_unique($names)) : 0;
    }

    /** Status key the Toolkit renders on the FluentCart card. */
    public static function toolkitStatus()
    {
        if (!self::adapterAvailable()) {
            return 'adapter_required';
        }

        return PermissionGate::isEnabled() ? 'ready' : 'disabled';
    }

    /** Stable endpoint URL for the Settings UI + connection-snippet generator. */
    public static function getEndpointUrl()
    {
        $namespace = apply_filters('fluent_cart/mcp_server_namespace', 'fluent-cart');
        $route     = apply_filters('fluent_cart/mcp_server_route', 'mcp');

        return get_rest_url(null, trailingslashit($namespace) . $route);
    }

    /** True when an MCP adapter + the Abilities API are both available. */
    public static function adapterAvailable()
    {
        return defined('WP_MCP_VERSION')
            && class_exists('\WP\MCP\Core\McpAdapter')
            && function_exists('wp_register_ability');
    }

    public function maybeShowAdapterNotice()
    {
        if (self::adapterAvailable() || !current_user_can('manage_options')) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('FluentCart MCP is enabled but no MCP adapter was found. Install FluentHub (recommended) or the MCP Adapter plugin, on WordPress 6.9+.', 'fluent-cart');
        echo '</p></div>';
    }
}
