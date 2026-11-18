<?php

namespace Tests\Support;

/**
 * Utility helper that captures `wp_send_json()` / `wp_die()` output so tests
 * can assert the JSON body and HTTP status that a controller or service
 * would have sent to the browser before terminating.
 *
 * WPTestCase swaps `wp_die` for a handler that throws `WPDieException` with
 * the HTTP status in the exception code. `wp_send_json()` echoes the JSON
 * body *before* calling `wp_die`, so the body is still available in the
 * output buffer when the exception is thrown.
 */
class WpDieCapture
{
    /**
     * Run $fn and capture whatever it sent to the browser through
     * `wp_send_json()` / `wp_die()` before terminating.
     *
     * @return array{status:int, body:string, json:?array, died:bool}
     */
    public static function capture(callable $fn): array
    {
        // wp_send_json() uses `die` (non-catchable) unless wp_doing_ajax() returns
        // true; force the ajax branch so wp_die() fires. WPTestCase only installs
        // a throwing wp_die_handler, not wp_die_ajax_handler, so we install an
        // ajax handler that throws too.
        // `wp_send_json()` calls status_header() BEFORE echoing, so capture the
        // status via the status_header filter — by the time wp_die runs, the
        // `response` arg passed into the handler is null.
        $forceAjax = function () { return true; };
        $capturedStatus = 0;
        $statusCapture = function ($status_header, $code) use (&$capturedStatus) {
            $capturedStatus = (int) $code;
            return $status_header;
        };
        $ajaxHandler = function () {
            return function ($message, $title = '', $args = []) {
                $code = 0;
                if (is_array($args) && isset($args['response'])) {
                    $code = (int) $args['response'];
                }
                throw new \WPDieException(is_string($message) ? $message : '', $code);
            };
        };
        add_filter('wp_doing_ajax', $forceAjax, 10000);
        add_filter('wp_die_ajax_handler', $ajaxHandler, 10000);
        add_filter('status_header', $statusCapture, 10000, 2);

        $obLevel = ob_get_level();
        ob_start();
        $died = false;
        $dieStatus = 0;
        try {
            $fn();
        } catch (\WPDieException $e) {
            $died = true;
            $dieStatus = (int) $e->getCode();
        }
        $body = self::closeBuffersTo($obLevel);
        remove_filter('wp_doing_ajax', $forceAjax, 10000);
        remove_filter('wp_die_ajax_handler', $ajaxHandler, 10000);
        remove_filter('status_header', $statusCapture, 10000);

        $json = json_decode($body, true);
        // wp_send_json($data) omits status_header() for its default HTTP 200.
        // Preserve an explicit wp_die response code when no JSON was emitted.
        $status = $capturedStatus ?: ((is_array($json) && $body !== '') ? 200 : $dieStatus);
        return [
            'status' => $status,
            'body'   => is_string($body) ? $body : '',
            'json'   => is_array($json) ? $json : null,
            'died'   => $died,
        ];
    }

    /**
     * Dispatch a REST request, capturing either the normal response or any
     * `wp_send_json()` / `wp_die()` termination that happens inside the
     * controller.
     *
     * The request runs in a Fiber and the wp_die handler suspends it for good,
     * which stops the controller the way `die` does. A thrown exception would
     * pass through Route::callback(), which treats every Throwable as an
     * application error and writes it and its trace to the error log.
     *
     * @return array{status:int, body:string, json:?array, died:bool, response:?\WP_REST_Response}
     */
    public static function dispatchRestWithDie(\WP_REST_Request $request): array
    {
        $forceAjax = function () { return true; };
        $capturedStatus = 0;
        $statusCapture = function ($statusHeader, $code) use (&$capturedStatus) {
            $capturedStatus = (int) $code;
            return $statusHeader;
        };
        $dieStatus = 0;
        $ajaxHandler = function () use (&$dieStatus) {
            return function ($message, $title = '', $args = []) use (&$dieStatus) {
                if (is_array($args) && isset($args['response'])) {
                    $dieStatus = (int) $args['response'];
                }
                \Fiber::suspend();
            };
        };
        add_filter('wp_doing_ajax', $forceAjax, 10000);
        add_filter('wp_die_ajax_handler', $ajaxHandler, 10000);
        add_filter('status_header', $statusCapture, 10000, 2);

        // A die inside a transaction rolls it back when the connection closes.
        $db          = \FluentCart\App\App::db();
        $startLevel  = $db->transactionLevel();

        $obLevel  = ob_get_level();
        ob_start();
        $status   = 0;
        $response = null;
        $fiber    = new \Fiber(function () use ($request) {
            return rest_do_request($request);
        });
        $fiber->start();
        $died = !$fiber->isTerminated();
        if (!$died) {
            $response = $fiber->getReturn();
        }
        // Destroying the suspended fiber unwinds it through `finally` blocks only.
        $fiber = null;
        if ($died && $db->transactionLevel() > $startLevel) {
            $db->rollBack($startLevel);
        }

        $body = self::closeBuffersTo($obLevel);
        remove_filter('wp_doing_ajax', $forceAjax, 10000);
        remove_filter('wp_die_ajax_handler', $ajaxHandler, 10000);
        remove_filter('status_header', $statusCapture, 10000);
        $json = json_decode($body, true);
        if ($died) {
            $status = $capturedStatus ?: $dieStatus;
        }
        if (!$died && $response instanceof \WP_REST_Response) {
            $status = (int) $response->get_status();
            $data   = $response->get_data();
            if (is_array($data) && $json === null) {
                $json = $data;
            }
        }
        // wp_send_json($data) omits status_header() for its default HTTP 200.
        if ($body !== '' && is_array($json)) {
            $status = $capturedStatus ?: 200;
        }
        return [
            'status'   => $status,
            'body'     => is_string($body) ? $body : '',
            'json'     => is_array($json) ? $json : null,
            'died'     => $died,
            'response' => $response,
        ];
    }

    /**
     * Close the capture buffer and any buffer the code under test left open when
     * it died, and return their output in the order PHP flushes it at exit.
     */
    private static function closeBuffersTo(int $level): string
    {
        $output = '';
        while (ob_get_level() > $level) {
            $output = ob_get_clean() . $output;
        }

        return $output;
    }
}
