<?php

namespace Tests\Support;

use FluentCart\App\App;
use FluentCart\Framework\Http\Request\Request as FluentRequest;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Base test case for REST API integration tests.
 *
 * Provides helpers for dispatching WP_REST_Request with proper nonce handling
 * and FluentCart request binding. Test files extend this instead of WPTestCase
 * when testing HTTP endpoints.
 */
abstract class RestTestCase extends WPTestCase
{
    protected static int $persistentAdminId = 0;

    /**
     * Opt-in: rebuild the REST server before every dispatch so WPFluent
     * registers fresh Route objects. A reused Route memoizes the first
     * dispatch's URL params forever (Route::getParameter — TESTABILITY-DEBT
     * #3), poisoning any later dispatch to the same parameterized pattern.
     * Default off: flipping it changes dispatch behavior, and red-baselined
     * suites must keep their exact failure fingerprints until their own
     * repair ticket flips it deliberately.
     */
    protected bool $rebuildRestServerPerDispatch = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::ensurePersistentAdmin();
    }

    /**
     * Resolve the persistent admin and guarantee it can manage the store.
     *
     * Lives in its own method (not just setUpBeforeClass) because Codeception's
     * single-test filter (`Suite:testMethod`) skips setUpBeforeClass when it
     * selects one method out of a multi-method class. setUp() calls this too so
     * an isolated test still authenticates instead of running as guest (401).
     */
    protected static function ensurePersistentAdmin(): void
    {
        self::$persistentAdminId = 1;
        wp_set_current_user(self::$persistentAdminId);

        $user = get_user_by('ID', self::$persistentAdminId);
        if ($user && !user_can($user, 'manage_options')) {
            $user->set_role('administrator');
        }
    }

    public function setUp(): void
    {
        parent::setUp();
        self::ensurePersistentAdmin();
        wp_set_current_user(self::$persistentAdminId);
        $this->refreshFluentCartCurrentUserCache();
        $this->resetFluentRequest();
        rest_get_server();
    }

    protected function resetFluentRequest(): void
    {
        $app = App::getInstance();
        $request = new FluentRequest($app, [], []);
        $app->instance(FluentRequest::class, $request);
        $app->instance('request', $request);
        if ($app->bound('wprestrequest')) {
            $app->forgetInstance('wprestrequest');
        }
    }

    protected function dispatchRequest(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->dispatchRequestInternal($request, false);
    }

    protected function dispatchRequestSilently(\WP_REST_Request $request): \WP_REST_Response
    {
        return $this->dispatchRequestInternal($request, true);
    }

    /**
     * Dispatch as the supplied user id without re-authenticating to the persistent admin.
     * Use this for permission tests where you must exercise a non-admin auth context.
     */
    protected function dispatchRequestAs(int $userId, \WP_REST_Request $request): \WP_REST_Response
    {
        wp_set_current_user($userId);
        $this->refreshFluentCartCurrentUserCache();
        return $this->dispatchRequestInternal($request, false, false);
    }

    /**
     * Dispatch with no authenticated user. Use for 401 / unauthenticated permission tests.
     */
    protected function dispatchRequestAsGuest(\WP_REST_Request $request): \WP_REST_Response
    {
        wp_set_current_user(0);
        $this->refreshFluentCartCurrentUserCache();
        return $this->dispatchRequestInternal($request, false, false);
    }

    /**
     * FluentCart's Helper::getCurrentUser() caches the resolved user in a function-static.
     * Permission tests must invalidate that cache to flip auth context within the same process.
     * Calls Helper::getCurrentUser(true) which is the test seam exposed for this purpose.
     */
    protected function refreshFluentCartCurrentUserCache(): void
    {
        if (class_exists(\FluentCart\App\Helpers\Helper::class)) {
            \FluentCart\App\Helpers\Helper::getCurrentUser(true);
        }
    }

    private function dispatchRequestInternal(\WP_REST_Request $request, bool $suppressErrors, bool $forceAdmin = true): \WP_REST_Response
    {
        if ($forceAdmin && self::$persistentAdminId && get_current_user_id() !== self::$persistentAdminId) {
            wp_set_current_user(self::$persistentAdminId);
            $this->refreshFluentCartCurrentUserCache();
        }

        global $wp_rest_auth_cookie;
        $wp_rest_auth_cookie = false;

        $this->resetFluentRequest();

        if ($this->rebuildRestServerPerDispatch) {
            $GLOBALS['wp_rest_server'] = null;
            rest_get_server();
        }

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

    /**
     * Extract a flat array of product/item arrays from a REST response payload.
     *
     * FluentCart endpoints return data in various shapes:
     *   - ['products' => Paginator|array]
     *   - ['data' => [...]]
     *   - direct array
     *
     * This helper normalises all of them to a plain array of items.
     */
    protected function extractProducts(array $data): array
    {
        // Try 'products' key first (most list endpoints)
        $items = $data['products'] ?? $data['data'] ?? $data;

        // If it's a Paginator, convert to array of items
        if (is_object($items) && method_exists($items, 'items')) {
            $items = $items->items();
        }

        if (is_object($items) && method_exists($items, 'toArray')) {
            $items = $items->toArray();
        }

        // Handle paginated array structure ['data' => [...]]
        if (is_array($items) && isset($items['data']) && is_array($items['data'])) {
            $items = $items['data'];
        }

        // Convert model objects to arrays
        return array_map(function ($item) {
            return is_object($item) && method_exists($item, 'toArray')
                ? $item->toArray()
                : (array)$item;
        }, $items);
    }
}
