<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\App\Modules\MCP\MCPInit;
use FluentCart\App\Modules\MCP\Support\PermissionGate;
use FluentCart\Framework\Http\Request\Request;

/**
 * Backend for the Settings → Features & addon → MCP card.
 *
 * The MCP feature stores its on/off state under the `mcp` key of the shared
 * `fluent_cart_modules_settings` blob (autoloaded — no extra query on init).
 * It still owns its own status/toggle/snippet endpoints (instant toggle + the
 * connection helpers) rather than riding the shared modules save; toggle()
 * read-modify-writes only the `mcp` key so the rest of the blob is untouched.
 */
class McpSettingsController extends Controller
{
    const TOOLKIT_PLUGIN_FILE = 'fluent-toolkit/fluent-toolkit.php';

    /** GitHub link shown when no Pro plugin can auto-install the toolkit. */
    const TOOLKIT_DOWNLOAD_URL = 'https://github.com/WPManageNinja/fluent-toolkit';

    /** Status payload for the MCP card: enabled state, endpoint, adapter, snippet helpers. */
    public function status()
    {
        $user = wp_get_current_user();

        return [
            'mcp_enabled'          => PermissionGate::isEnabled(),
            'adapter_available'    => MCPInit::adapterAvailable(),
            'toolkit_installed'    => $this->isToolkitInstalled(),
            'can_auto_install'     => (bool) apply_filters('fluent_toolkit/can_auto_install', false),
            'toolkit_download_url' => self::TOOLKIT_DOWNLOAD_URL,
            'endpoint_url'         => MCPInit::getEndpointUrl(),
            'tools_count'          => MCPInit::toolsCount(),
            'app_passwords_url'    => admin_url('profile.php#application-passwords-section'),
            'plugins_url'          => admin_url('plugins.php'),
            'current_user_login'   => ($user && $user->exists()) ? $user->user_login : '',
            'is_local_dev'         => $this->isLocalDev(),
        ];
    }

    /**
     * One-click FluentHub install. The free plugin can only detect + trigger;
     * the actual installer lives in a Fluent Pro plugin (FluentCart Pro, or any
     * Fluent product) that hooks the site-wide `fluent_toolkit/*` contract. With
     * no Pro present we hand back the manual download link.
     */
    public function installAdapter()
    {
        if (!current_user_can('install_plugins')) {
            return $this->sendError([
                'message' => __('Sorry, you do not have permission to install plugins.', 'fluent-cart'),
            ]);
        }

        $canAutoInstall = (bool) apply_filters('fluent_toolkit/can_auto_install', false);
        if (!$canAutoInstall) {
            return $this->sendError([
                'message'              => __('Automatic install needs FluentCart Pro (or another Fluent Pro plugin). Install FluentHub manually, then reload this page to connect FluentCart with AI agents.', 'fluent-cart'),
                'toolkit_download_url' => self::TOOLKIT_DOWNLOAD_URL,
            ]);
        }

        do_action('fluent_toolkit/do_auto_install');

        wp_clean_plugins_cache();

        $available = MCPInit::adapterAvailable();

        return $this->sendSuccess([
            'adapter_available' => $available,
            'toolkit_installed' => $this->isToolkitInstalled(),
            'message'           => $available
                ? __('FluentHub installed and activated. The MCP endpoint is ready.', 'fluent-cart')
                : __('FluentHub was installed. Please reload this page to finish connecting the MCP endpoint.', 'fluent-cart'),
        ]);
    }

    /** Is FluentHub (the toolkit plugin) present on disk (installed, active or not)? */
    private function isToolkitInstalled()
    {
        if (defined('FLUENT_TOOLKIT_VERSION')) {
            return true;
        }

        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugins = get_plugins();

        return isset($plugins[self::TOOLKIT_PLUGIN_FILE]);
    }

    /** Flip the master switch. Writes the same blob key the server boot guard reads. */
    public function toggle(Request $request)
    {
        $value   = $request->get('mcp_enabled');
        $enabled = is_string($value) ? in_array(strtolower($value), ['yes', 'true', '1', 'on'], true) : (bool) $value;

        // setEnabled() fails closed when the user can't manage_options. Re-check
        // here so we return an error instead of a misleading success response.
        if (!current_user_can('manage_options')) {
            return $this->sendError([
                'message' => __('Sorry, you do not have permission to change the MCP setting.', 'fluent-cart'),
            ]);
        }

        PermissionGate::setEnabled($enabled);

        // Report the actually-persisted state, not the requested value, so the UI
        // can never show "enabled" for a write that didn't land.
        $stored = PermissionGate::isEnabled();

        return $this->sendSuccess([
            'mcp_enabled' => $stored,
            'message'     => $stored
                ? __('MCP enabled. AI agents with a valid app password can now reach the FluentCart tools.', 'fluent-cart')
                : __('MCP disabled. The endpoint will reject requests until re-enabled.', 'fluent-cart'),
        ]);
    }

    /**
     * Connection snippets for EVERY supported client, in one response. They're
     * cheap server-side string templates, so we build them all at once rather
     * than round-tripping per tab — the UI just switches between cached entries.
     *
     * Credentials are NEVER sent here: each snippet carries placeholders the
     * browser fills in client-side, so an application password never round-trips
     * through the server. Only `local_dev` (TLS verification for Claude Desktop)
     * varies the output.
     */
    public function getConfigSnippets(Request $request)
    {
        $endpoint = MCPInit::getEndpointUrl();

        $localDevParam = $request->get('local_dev');
        $isLocalDev    = ($localDevParam === null || $localDevParam === '')
            ? $this->isLocalDev()
            : in_array(strtolower((string) $localDevParam), ['yes', 'true', '1', 'on'], true);

        $clients  = ['claude-code', 'claude-desktop', 'cursor', 'codex', 'generic'];
        $snippets = [];
        foreach ($clients as $client) {
            $snippets[$client] = $this->buildSnippet($client, $endpoint, $isLocalDev);
        }

        return [
            'snippets'          => $snippets,
            'endpoint'          => $endpoint,
            'app_passwords_url' => admin_url('profile.php#application-passwords-section'),
            'is_local_dev'      => $isLocalDev,
        ];
    }

    /**
     * Build a single client's connection snippet + instructions. Pure string
     * assembly — no DB, no credentials.
     */
    private function buildSnippet($client, $endpoint, $isLocalDev)
    {
        $basic = '<base64(your-username:application-password)>';
        $user  = '<your-username>';
        $pass  = '<your-application-password>';

        switch ($client) {
            case 'claude-desktop':
                $env = [
                    'WP_API_URL'      => $endpoint,
                    'WP_API_USERNAME' => $user,
                    'WP_API_PASSWORD' => $pass,
                    'OAUTH_ENABLED'   => 'false',
                ];
                if ($isLocalDev) {
                    $env['NODE_TLS_REJECT_UNAUTHORIZED'] = '0';
                }
                $snippet = wp_json_encode([
                    'mcpServers' => [
                        'fluent-cart' => [
                            'command' => 'npx',
                            'args'    => ['-y', '@automattic/mcp-wordpress-remote@latest'],
                            'env'     => $env,
                        ],
                    ],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $instructions = __('Add this to your Claude Desktop config (Settings → Developer → Edit Config), fill in your username + application password, then restart Claude Desktop.', 'fluent-cart');
                break;

            case 'cursor':
                $snippet = wp_json_encode([
                    'mcpServers' => [
                        'fluent-cart' => [
                            'url'     => $endpoint,
                            'type'    => 'http',
                            'headers' => ['Authorization' => 'Basic ' . $basic],
                        ],
                    ],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $instructions = __('Add to Cursor’s mcp.json, replacing the placeholder with base64 of "username:application-password".', 'fluent-cart');
                break;

            case 'codex':
                $snippet = "Settings → Connect to a custom MCP\n\n"
                    . "Name:       fluent-cart\n"
                    . "Transport:  Streamable HTTP\n"
                    . "URL:        {$endpoint}\n\n"
                    . "Header:\n  Key:    Authorization\n  Value:  Basic {$basic}";
                $instructions = __('In Codex, add a custom MCP server with Streamable HTTP transport and the Authorization header above.', 'fluent-cart');
                break;

            case 'generic':
                $snippet = "URL:   {$endpoint}\n"
                    . "Auth:  Authorization: Basic {$basic}\n\n"
                    . "# Quick test (curl base64-encodes for you):\n"
                    . "curl -s -u '{$user}:{$pass}' \\\n"
                    . "  -X POST {$endpoint} \\\n"
                    . "  -H 'Content-Type: application/json' \\\n"
                    . "  -H 'Accept: application/json, text/event-stream' \\\n"
                    . '  -d \'{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"c","version":"1.0"}}}\'';
                $instructions = __('Any MCP client that speaks Streamable HTTP can connect using this URL and a Basic auth header.', 'fluent-cart');
                break;

            case 'claude-code':
            default:
                $client  = 'claude-code';
                $snippet = "claude mcp add \\\n"
                    . "  --transport http \\\n"
                    . "  fluent-cart {$endpoint} \\\n"
                    . "  --header \"Authorization: Basic {$basic}\"";
                $instructions = __('Run this in your terminal where Claude Code is installed, with base64 of "username:application-password".', 'fluent-cart');
                break;
        }

        return [
            'client'       => $client,
            'snippet'      => $snippet,
            'instructions' => $instructions,
        ];
    }

    /**
     * Best-effort "is this a local dev site?" check, used to default the TLS
     * override in the Claude Desktop snippet. Filterable for edge cases.
     */
    private function isLocalDev()
    {
        $host = '';
        $home = home_url();
        if ($home) {
            $parsed = wp_parse_url($home, PHP_URL_HOST);
            $host   = $parsed ? strtolower($parsed) : '';
        }

        $isLocal = false;
        if ($host) {
            // Note: '.dev' is a real public HSTS-preloaded gTLD, not a local
            // suffix — excluded so a production .dev site isn't told to disable
            // TLS verification.
            foreach (['.test', '.local', '.localhost', '.lab'] as $tld) {
                if (substr($host, -strlen($tld)) === $tld) {
                    $isLocal = true;
                    break;
                }
            }
            if (!$isLocal && in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                $isLocal = true;
            }
        }

        return (bool) apply_filters('fluent_cart/mcp_is_local_dev', $isLocal, $host);
    }
}
