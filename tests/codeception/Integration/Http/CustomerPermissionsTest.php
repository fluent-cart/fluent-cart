<?php

namespace Tests\Integration\Http;

use FluentCart\App\App;
use FluentCart\App\Services\Permission\PermissionManager;
use FluentCart\Framework\Http\Request\Request as FluentRequest;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * End-to-end endpoint permission tests for customer routes.
 *
 * @runTestsInSeparateProcesses
 *
 * Run each test in a fresh process because Helper::getCurrentUser() caches the
 * resolved user in a function static variable in the current codebase.
 */
class CustomerPermissionsTest extends WPTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        $this->resetFluentRequest();
        // Never fire rest_api_init bare: on an already-built server WordPress
        // re-appends every handler set, leaving duplicate registrations for the
        // rest of the process (RouteRegistrationTest's invariant). Rebuilding
        // the server fires rest_api_init exactly once on a fresh instance.
        $GLOBALS['wp_rest_server'] = null;
        rest_get_server();
    }

    public function tearDown(): void
    {
        wp_cache_flush();
        gc_collect_cycles();
        parent::tearDown();
    }

    /**
     * Rebuild the Fluent request singleton so each REST dispatch starts clean.
     */
    private function resetFluentRequest(): void
    {
        $app = App::getInstance();
        $request = new FluentRequest($app, [], []);

        $app->instance(FluentRequest::class, $request);
        $app->instance('request', $request);
        if ($app->bound('wprestrequest')) {
            $app->forgetInstance('wprestrequest');
        }
    }

    private function dispatchRequest(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->dispatchRequestInternal($request, false);
    }

    /**
     * Suppress framework error_log noise for expected >=400 REST responses.
     */
    private function dispatchRequestSilently(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->dispatchRequestInternal($request, true);
    }

    private function dispatchRequestInternal(\WP_REST_Request $request, bool $suppressErrors): \WP_REST_Response
    {
        global $wp_rest_auth_cookie;
        $wp_rest_auth_cookie = false;

        $this->resetFluentRequest();

        $currentUserId = get_current_user_id();
        if ($currentUserId) {
            wp_set_current_user($currentUserId);
            wp_set_auth_cookie($currentUserId, false, is_ssl());
        } else {
            wp_clear_auth_cookie();
        }

        $method = strtoupper($request->get_method());
        $shouldAttachRestNonce = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && (bool)$currentUserId;

        $hadRequestNonce = array_key_exists('_wpnonce', $_REQUEST);
        $originalRequestNonce = $hadRequestNonce ? $_REQUEST['_wpnonce'] : null;
        $hadServerNonce = array_key_exists('HTTP_X_WP_NONCE', $_SERVER);
        $originalServerNonce = $hadServerNonce ? $_SERVER['HTTP_X_WP_NONCE'] : null;

        if ($shouldAttachRestNonce) {
            $nonce = wp_create_nonce('wp_rest');
            $_REQUEST['_wpnonce'] = $nonce;
            $_SERVER['HTTP_X_WP_NONCE'] = $nonce;
            $request->set_header('X-WP-Nonce', $nonce);
        } else {
            unset($_REQUEST['_wpnonce'], $_SERVER['HTTP_X_WP_NONCE']);
        }

        $originalErrorLog = ini_get('error_log');
        $temporaryErrorLog = $suppressErrors ? tempnam(sys_get_temp_dir(), 'fc-rest-errors-') : false;

        if ($temporaryErrorLog) {
            ini_set('error_log', $temporaryErrorLog);
        }

        try {
            return rest_do_request($request);
        } finally {
            if ($temporaryErrorLog) {
                ini_set('error_log', $originalErrorLog === false ? '' : (string)$originalErrorLog);
                if (file_exists($temporaryErrorLog)) {
                    @unlink($temporaryErrorLog);
                }
            }

            if ($hadRequestNonce) {
                $_REQUEST['_wpnonce'] = $originalRequestNonce;
            } else {
                unset($_REQUEST['_wpnonce']);
            }

            if ($hadServerNonce) {
                $_SERVER['HTTP_X_WP_NONCE'] = $originalServerNonce;
            } else {
                unset($_SERVER['HTTP_X_WP_NONCE']);
            }
        }
    }

    // =========================================================================
    // Route-policy wiring: all 3 permission keys are registered
    // =========================================================================

    public function test_customer_permission_keys_are_registered(): void
    {
        $allPermissions = PermissionManager::getAllPermissions();

        $this->assertArrayHasKey('customers/view', $allPermissions);
        $this->assertArrayHasKey('customers/manage', $allPermissions);
        $this->assertArrayHasKey('customers/delete', $allPermissions);
    }
}
