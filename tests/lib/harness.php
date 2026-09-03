<?php
/**
 * Local WPFluent plugin test harness.
 *
 * Shared runtime for every suite. Loaded by each runner via `require_once`.
 * Everything runs through WP-CLI (`wp eval-file`) against a real WordPress +
 * MySQL install — no Docker, no GitHub Actions, no PHPUnit bootstrap.
 *
 * Design notes (read tests/AGENT.md before changing these):
 *
 *  - REST calls are dispatched IN-PROCESS via rest_do_request(), not over HTTP.
 *    Fast (no network, no second PHP boot), deterministic, and it keeps the
 *    per-query wpdb error ledger observable in the same process.
 *  - A DB error is detected even when a later successful query clears
 *    $wpdb->last_error: each case snapshots WordPress's append-only
 *    $EZSQL_ERROR ledger, then also checks final last_error, response status,
 *    rendered database diagnostics, and the plugin exception envelope.
 *  - PHP notices/warnings/deprecations are promoted to failures. "Silently
 *    broken" is the exact condition this whole effort exists to eliminate.
 *
 * @see tests/AGENT.md
 */

$fcTestConfig = require dirname(__DIR__) . '/suite.config.php';
if (!is_array($fcTestConfig)) {
    exit("tests/suite.config.php must return an array.\n");
}

if (!defined('WP_CLI') || !WP_CLI) {
    exit("These tests must run via WP-CLI.\n");
}

$fcTestSentinel = isset($fcTestConfig['sentinel_class'])
    ? (string) $fcTestConfig['sentinel_class']
    : '';
if ($fcTestSentinel === '' || !class_exists($fcTestSentinel)) {
    $fcTestSlug = isset($fcTestConfig['plugin_slug'])
        ? (string) $fcTestConfig['plugin_slug']
        : 'Configured plugin';
    WP_CLI::error($fcTestSlug . ' is not active on this site.');
}
unset($fcTestConfig, $fcTestSentinel, $fcTestSlug);

require_once __DIR__ . '/protected-tables.php';

class FcTestWpDieException extends \RuntimeException
{
}

class FcTest
{
    /** @var array<int,array{name:string,detail:string}> */
    public static $failures = [];

    /** @var int */
    public static $passed = 0;

    /** @var int */
    public static $skipped = 0;

    /** @var array<int,string> PHP diagnostics captured during the current case. */
    private static $diagnostics = [];

    /** @var string|null Name of the case currently running. */
    private static $currentCase = null;

    /** @var float */
    private static $startedAt = 0.0;

    /** @var array<string,int> Protected counts captured at runner start. */
    private static $protectedBaseline = [];

    /** @var array<int,array<string,mixed>> Messages intercepted before transport. */
    private static $sentMails = [];

    /** @var \Closure|null Exact callback retained for idempotent registration. */
    private static $mailInterceptor = null;

    /** @var array<int,array{method:string,url:string}> Outbound HTTP calls blocked in the current case. */
    private static $externalCalls = [];

    /** @var \Closure|null Exact callback retained for idempotent registration. */
    private static $httpInterceptor = null;

    /** @var callable|null Explicit fixture-backed provider transport for the current case. */
    private static $providerHttpTransport = null;

    /** @var array<int,array{operation:string,hook:string}> Cron writes blocked in the current case. */
    private static $cronAttempts = [];

    /** @var array<string,\Closure> Exact callbacks retained for idempotent registration. */
    private static $cronInterceptors = [];

    /** @var array<int,array{operation:string,hook:string}> Action Scheduler writes blocked in the current case. */
    private static $actionSchedulerAttempts = [];

    /** @var array<string,\Closure> Exact callbacks retained for idempotent registration. */
    private static $actionSchedulerInterceptors = [];

    /** @var bool Return synthetic IDs for an explicitly expected scheduler enqueue. */
    private static $expectedActionSchedulerCapture = false;

    /** @var array<int,array{url:string,body:array}> Loopback intents captured before transport. */
    private static $firedLoopbacks = [];

    /** @var \Closure|null Exact callback retained for idempotent registration. */
    private static $loopbackInterceptor = null;

    // -----------------------------------------------------------------
    // Lifecycle
    // -----------------------------------------------------------------

    /**
     * Install the error handler that turns PHP diagnostics into failures and
     * log in as an administrator so permission-gated routes are reachable.
     */
    public static function boot()
    {
        self::$startedAt = microtime(true);
        self::configureTimezoneEnvironment();
        self::configureSqlEnvironment();
        try {
            self::$protectedBaseline = self::protectedCounts();
        } catch (\RuntimeException $e) {
            WP_CLI::error($e->getMessage());
        }

        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            // Respect @-suppression and error_reporting().
            if (!(error_reporting() & $errno)) {
                return false;
            }

            // Ignore diagnostics raised inside WP core / other plugins: we are
            // testing one plugin, and a noisy neighbour must not fail our suite.
            if (strpos($errfile, self::config('plugin_dir_hint')) === false) {
                return false;
            }

            self::$diagnostics[] = self::errorLabel($errno) . ': ' . $errstr
                . ' (' . self::relPath($errfile) . ':' . $errline . ')';

            return true; // handled — do not print
        });

        $admin = get_users(['role' => 'administrator', 'number' => 1, 'orderby' => 'ID']);
        if (!$admin) {
            WP_CLI::error('No administrator user found on this site.');
        }
        wp_set_current_user($admin[0]->ID);

        // Phase 1 is read-only and in-process. Install fail-closed transport
        // guards before any manifest case is dispatched.
        self::interceptMail();
        self::interceptOutboundHttp();
    }

    /**
     * Enable production-default strict SQL modes for this connection when the
     * opt-in environment axis is active.
     *
     * WP-CLI suites and isolated public CGI workers use separate MySQL
     * sessions, so every process must call this after WordPress has connected.
     * The change is session-only and disappears when that process exits.
     */
    public static function configureSqlEnvironment()
    {
        if (getenv('WP_PLUGIN_TEST_STRICT_SQL') !== '1') {
            return;
        }

        global $wpdb;
        $current = (string) $wpdb->get_var('SELECT @@SESSION.sql_mode');
        $modes = array_values(array_filter(array_map('trim', explode(',', $current))));

        foreach (['ONLY_FULL_GROUP_BY', 'STRICT_TRANS_TABLES'] as $requiredMode) {
            if (!in_array($requiredMode, $modes, true)) {
                $modes[] = $requiredMode;
            }
        }

        $target = implode(',', $modes);
        $updated = $wpdb->query($wpdb->prepare('SET SESSION sql_mode = %s', $target));
        if ($updated === false) {
            WP_CLI::error('Could not enable the strict SQL environment axis: ' . $wpdb->last_error);
        }

        $verified = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) $wpdb->get_var('SELECT @@SESSION.sql_mode'))
        )));
        foreach (['ONLY_FULL_GROUP_BY', 'STRICT_TRANS_TABLES'] as $requiredMode) {
            if (!in_array($requiredMode, $verified, true)) {
                WP_CLI::error('Strict SQL environment axis did not enable ' . $requiredMode . '.');
            }
        }

        WP_CLI::log('Environment axis strict-sql: session sql_mode=' . implode(',', $verified));
    }

    /**
     * Override WordPress's timezone options inside this WP-CLI process only.
     *
     * pre_option filters make every production option read see the hostile
     * value without persisting a site option or leaving restoration work after
     * a fatal test process.
     */
    public static function configureTimezoneEnvironment()
    {
        $configured = getenv('WP_PLUGIN_TEST_GMT_OFFSET');
        if ($configured === false || $configured === '') {
            return;
        }
        if (!is_numeric($configured)) {
            WP_CLI::error('Non-UTC environment axis requires a numeric GMT offset.');
        }

        $offset = (float) $configured;
        if ($offset == 0.0 || $offset < -14.0 || $offset > 14.0) {
            WP_CLI::error('Non-UTC environment axis requires an offset from -14 to 14, excluding zero.');
        }

        add_filter('pre_option_timezone_string', function () {
            return '';
        }, PHP_INT_MAX);
        add_filter('pre_option_gmt_offset', function () use ($offset) {
            return $offset;
        }, PHP_INT_MAX);

        $actual = (float) get_option('gmt_offset');
        $delta = (int) current_time('timestamp') - time();
        $expectedDelta = (int) round($offset * HOUR_IN_SECONDS);
        if (abs($actual - $offset) > 0.0001 || abs($delta - $expectedDelta) > 1) {
            WP_CLI::error(
                'Non-UTC environment axis did not apply its process-local offset.'
            );
        }

        WP_CLI::log(sprintf(
            'Environment axis non-utc: offset=%s local=%s utc=%s delta_seconds=%d',
            (string) $offset,
            current_time('mysql'),
            gmdate('Y-m-d H:i:s'),
            $delta
        ));
    }

    /**
     * Print the summary and exit with a non-zero code if anything failed.
     * Every runner MUST end with this — the exit code is what makes the suite
     * usable from a script or a git hook.
     */
    public static function finish($suiteName)
    {
        try {
            self::assertProtectedCountsUnchanged($suiteName . ' runner');
        } catch (\RuntimeException $e) {
            restore_error_handler();
            WP_CLI::log('HARD FAILURE — ' . $e->getMessage());
            WP_CLI::halt(97);
        }

        restore_error_handler();

        $elapsed = round(microtime(true) - self::$startedAt, 1);
        $total   = self::$passed + count(self::$failures);

        WP_CLI::log('');
        WP_CLI::log(str_repeat('=', 72));
        WP_CLI::log(sprintf(
            '%s: %d/%d passed, %d failed, %d skipped  (%ss)',
            $suiteName, self::$passed, $total, count(self::$failures), self::$skipped, $elapsed
        ));

        if (self::$failures) {
            WP_CLI::log('');
            foreach (self::$failures as $i => $f) {
                WP_CLI::log(sprintf('%d) %s', $i + 1, $f['name']));
                foreach (explode("\n", rtrim($f['detail'])) as $line) {
                    WP_CLI::log('   ' . $line);
                }
                WP_CLI::log('');
            }
            WP_CLI::log(str_repeat('=', 72));
            WP_CLI::halt(1);
        }

        WP_CLI::log(str_repeat('=', 72));
        WP_CLI::halt(0);
    }

    // -----------------------------------------------------------------
    // Assertions
    // -----------------------------------------------------------------

    /**
     * Run one test case. $fn receives no arguments and should call fail()/pass()
     * indirectly through the assert helpers below.
     */
    public static function case($name, callable $fn)
    {
        self::$currentCase = $name;
        self::$diagnostics = [];
        self::$externalCalls = [];
        self::$cronAttempts = [];
        self::$actionSchedulerAttempts = [];
        self::$expectedActionSchedulerCapture = false;
        self::$providerHttpTransport = null;
        self::interceptMail();

        $failedBefore = count(self::$failures);

        try {
            $fn();
        } catch (\Throwable $e) {
            self::fail('threw ' . get_class($e) . ': ' . $e->getMessage()
                . ' (' . self::relPath($e->getFile()) . ':' . $e->getLine() . ')');
        }

        // Any PHP diagnostic raised inside the configured plugin is a failure.
        if (self::$diagnostics) {
            self::fail("PHP diagnostics raised:\n  - " . implode("\n  - ", self::$diagnostics));
        }

        if (self::$externalCalls) {
            $attempts = array_map(function ($call) {
                return $call['method'] . ' ' . $call['url'];
            }, self::$externalCalls);
            self::fail("OUTBOUND HTTP ATTEMPT BLOCKED:\n  - " . implode("\n  - ", $attempts));
        }

        if (self::$sentMails) {
            self::fail(
                'MAIL ATTEMPT BLOCKED: ' . count(self::$sentMails)
                . ' wp_mail() call(s) were intercepted'
            );
        }

        if (self::$cronAttempts) {
            $attempts = array_map(function ($attempt) {
                return $attempt['operation'] . ' ' . $attempt['hook'];
            }, self::$cronAttempts);
            self::fail("CRON MUTATION ATTEMPT BLOCKED:\n  - " . implode("\n  - ", $attempts));
        }

        if (self::$actionSchedulerAttempts) {
            $attempts = array_map(function ($attempt) {
                return $attempt['operation'] . ' ' . $attempt['hook'];
            }, self::$actionSchedulerAttempts);
            self::fail(
                "ACTION SCHEDULER MUTATION ATTEMPT BLOCKED:\n  - "
                . implode("\n  - ", $attempts)
            );
        }

        self::$expectedActionSchedulerCapture = false;
        self::$providerHttpTransport = null;

        if (count(self::$failures) === $failedBefore) {
            self::$passed++;
        }

        self::$currentCase = null;
    }

    public static function fail($detail)
    {
        self::$failures[] = [
            'name'   => self::$currentCase ?: '(no case)',
            'detail' => $detail,
        ];
    }

    public static function skip($reason)
    {
        self::$skipped++;
        WP_CLI::log('  SKIP ' . (self::$currentCase ?: '') . ' — ' . $reason);
    }

    public static function assert($condition, $detail)
    {
        if (!$condition) {
            self::fail($detail);
        }
    }

    public static function assertSame($expected, $actual, $label)
    {
        if ($expected !== $actual) {
            self::fail($label . "\n  expected: " . var_export($expected, true)
                . "\n  actual:   " . var_export($actual, true));
        }
    }

    /**
     * Claim diagnostics that are being surfaced as an explicit KNOWN-FAILURE.
     *
     * Use this only immediately before skip(): matching production warnings are
     * removed from the automatic failure list so a known defect can remain
     * executable, while every unmatched diagnostic still fails the case.
     *
     * @param string $pattern PCRE matched against each formatted diagnostic.
     * @return array<int,string> Claimed diagnostics for inclusion in skip output.
     */
    public static function claimKnownDiagnostics($pattern)
    {
        $claimed = [];
        $remaining = [];

        foreach (self::$diagnostics as $diagnostic) {
            if (preg_match($pattern, $diagnostic)) {
                $claimed[] = $diagnostic;
            } else {
                $remaining[] = $diagnostic;
            }
        }

        self::$diagnostics = $remaining;

        return $claimed;
    }

    // -----------------------------------------------------------------
    // REST
    // -----------------------------------------------------------------

    /**
     * Dispatch a configured REST route in-process and return a rich result array.
     *
     * @param string $method  GET|POST|PUT|DELETE
     * @param string $route   Route WITHOUT the namespace.
     * @param array  $params  Query params (GET) or body params (everything else)
     * @return array{status:int,data:mixed,db_error:string,is_exception:bool,message:string}
     */
    public static function rest($method, $route, array $params = [])
    {
        global $wpdb, $EZSQL_ERROR;

        $wpdb->last_error = '';

        /*
         * WPFluent's Request::mergeInputsFromRestRequest() merges into the
         * container's request singleton. That is correct for one HTTP request
         * per PHP process, but an in-process suite dispatches hundreds of REST
         * requests in one process. Rebind an empty request so query parameters
         * from one case cannot leak into the next (for example `with[]=stats`
         * being mistaken for a relationship on a later campaign request).
         */
        $appBootstrap = self::config('app_bootstrap');
        $app = $appBootstrap();
        $requestClass = self::config('request_class');
        $app->instance($requestClass, new $requestClass($app, [], []));

        $customerResource = '\\FluentCart\\Api\\Resource\\CustomerResource';
        if (
            class_exists($customerResource)
            && method_exists($customerResource, 'resetCurrentCustomerRuntimeCache')
        ) {
            $customerResource::resetCurrentCustomerRuntimeCache();
        }
        $cartResource = '\\FluentCart\\Api\\Resource\\FrontendResource\\CartResource';
        if (class_exists($cartResource) && method_exists($cartResource, 'resetCartCache')) {
            $cartResource::resetCartCache();
        }

        $path = '/' . trim(self::config('rest_namespace'), '/')
            . '/' . ltrim($route, '/');
        $path = rtrim($path, '/');

        $request = new WP_REST_Request(strtoupper($method), $path);

        if (strtoupper($method) === 'GET') {
            $request->set_query_params($params);
        } else {
            $request->set_body_params($params);
        }

        /*
         * A few legacy REST controllers still call wp_send_json(). WordPress
         * echoes JSON and terminates the process in that path, which would
         * falsely truncate this long-running WP-CLI suite. Convert only that
         * in-process termination seam back into a REST response.
         */
        $dieHandlerFilter = function () {
            return function ($message = '', $title = '', $args = []) {
                throw new FcTestWpDieException((string) $message);
            };
        };
        foreach (['wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler'] as $filter) {
            add_filter($filter, $dieHandlerFilter, PHP_INT_MAX);
        }

        $response = null;
        $dieException = null;
        $databaseErrorOffset = is_array($EZSQL_ERROR) ? count($EZSQL_ERROR) : 0;
        ob_start();
        try {
            $response = rest_do_request($request);
        } catch (FcTestWpDieException $e) {
            $dieException = $e;
        } finally {
            $capturedOutput = (string) ob_get_clean();
            foreach (['wp_die_handler', 'wp_die_ajax_handler', 'wp_die_json_handler'] as $filter) {
                remove_filter($filter, $dieHandlerFilter, PHP_INT_MAX);
            }
        }

        if ($dieException !== null) {
            $decoded = json_decode(trim($capturedOutput), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw $dieException;
            }
            $response = new \WP_REST_Response($decoded, 200);
        }

        $data     = $response->get_data();

        $queryErrors = is_array($EZSQL_ERROR)
            ? array_slice($EZSQL_ERROR, $databaseErrorOffset)
            : [];
        $dbErrors = [];
        foreach ($queryErrors as $queryError) {
            if (!is_array($queryError) || empty($queryError['error_str'])) {
                continue;
            }

            $detail = (string) $queryError['error_str'];
            if (!empty($queryError['query'])) {
                $detail .= "\n  query: " . trim((string) $queryError['query']);
            }
            $dbErrors[] = $detail;
        }

        // EZSQL_ERROR is append-only inside wpdb::print_error(), so it records
        // every failed query even when a later successful query clears
        // $wpdb->last_error. Retain last_error and rendered-output detection as
        // fallbacks for failures that occur before print_error() can append.
        $dbError = $dbErrors
            ? implode("\n", $dbErrors)
            : (string) $wpdb->last_error;
        if (
            !$dbErrors
            && $capturedOutput !== ''
            && preg_match('/(?:class=[\'"]wpdberror[\'"]|WordPress database error)/i', $capturedOutput)
        ) {
            $plainOutput = html_entity_decode(
                wp_strip_all_tags($capturedOutput),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            );
            if (preg_match('/WordPress database error:\\s*\\[([^]]+)\\]/i', $plainOutput, $matches)) {
                $dbError = trim($matches[1]);
            } elseif ($dbError === '') {
                $dbError = trim($plainOutput);
            }
        }

        // The plugin wraps runtime errors in its own envelope, which can carry a
        // 2xx status in some paths — check the payload shape, not just the code.
        $isException = is_array($data)
            && isset($data['code'])
            && in_array($data['code'], ['plugin_exception', 'internal_server_error'], true);

        $message = '';
        if (is_array($data) && isset($data['message']) && is_string($data['message'])) {
            $message = $data['message'];
        }

        return [
            'status'       => $response->get_status(),
            'data'         => $data,
            'db_error'     => $dbError,
            'is_exception' => $isException,
            'message'      => $message,
        ];
    }

    /**
     * The core smoke assertion: a route responded without any form of breakage.
     *
     * This is the assertion that would have caught both 2026-07-20 regressions.
     * Keep all three checks — the DB error alone was invisible in the HTTP status
     * on some routes, and the status alone was invisible on others.
     */
    public static function assertHealthy(array $result, $label, array $okStatuses = [200, 201, 204])
    {
        if ($result['db_error'] !== '') {
            self::fail($label . "\n  DATABASE ERROR: " . $result['db_error']);
            return;
        }

        if ($result['is_exception']) {
            self::fail($label . "\n  PLUGIN EXCEPTION: " . $result['message']);
            return;
        }

        if (!in_array($result['status'], $okStatuses, true)) {
            self::fail($label . "\n  unexpected status " . $result['status']
                . ' (allowed: ' . implode(',', $okStatuses) . ')'
                . ($result['message'] !== '' ? "\n  message: " . $result['message'] : ''));
        }
    }

    /**
     * Drop the configured plugin's own caches so a suite tests live code paths.
     *
     * THIS IS NOT OPTIONAL AND IT IS NOT PARANOIA. When the 2026-07-20
     * a raw-SQL regression was reproduced, the broken query was hidden behind a
     * transient. With a warm cache an endpoint can return 200 while the code
     * underneath is broken. A cached endpoint is exactly where a regression
     * hides longest, so every suite clears configured caches before it runs.
     *
     * Deliberately targeted by configured transient prefixes and cache groups
     * rather than wp_cache_flush(), which could evict unrelated site data.
     */
    public static function clearCaches()
    {
        global $wpdb;

        $patterns = [];
        foreach ((array) self::config('transient_prefixes', []) as $prefix) {
            $patterns[] = $wpdb->esc_like('_transient_' . $prefix) . '%';
            $patterns[] = $wpdb->esc_like('_transient_timeout_' . $prefix) . '%';
        }

        $rows = [];
        if ($patterns) {
            $clauses = implode(' OR ', array_fill(0, count($patterns), 'option_name LIKE %s'));
            $query = $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE {$clauses}",
                $patterns
            );
            $rows = $wpdb->get_col($query);
        }

        foreach ($rows as $name) {
            $key = preg_replace('/^_transient_(timeout_)?/', '', $name);
            delete_transient($key);
        }

        // Flush only configured cache groups.
        if (function_exists('wp_cache_flush_group')) {
            foreach ((array) self::config('cache_groups', []) as $group) {
                wp_cache_flush_group($group);
            }
        }

        return count($rows);
    }

    // -----------------------------------------------------------------
    // Mail safety
    // -----------------------------------------------------------------

    /**
     * Fail closed around wp_mail() and start a fresh capture buffer.
     *
     * The callback runs at the last possible priority and always returns true.
     * WordPress therefore exits before PHPMailer even when another plugin added
     * an earlier pre_wp_mail filter. Repeated calls reset captures without
     * registering duplicate callbacks.
     */
    public static function interceptMail()
    {
        self::$sentMails = [];

        if (self::$mailInterceptor === null) {
            self::$mailInterceptor = function ($preempt, $attributes) {
                self::$sentMails[] = is_array($attributes) ? $attributes : [];
                return true;
            };
        }

        if (has_filter('pre_wp_mail', self::$mailInterceptor) === false) {
            add_filter('pre_wp_mail', self::$mailInterceptor, PHP_INT_MAX, 2);
        }
    }

    /**
     * Return all messages captured since the last interceptMail() call.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function sentMails()
    {
        return self::$sentMails;
    }

    /**
     * Prove the fail-closed mail and local-loopback guards are actually wired.
     *
     * Phase 14 calls this inside every case before trusting an empty transport
     * capture. Both probes go through the real WordPress APIs, but the
     * interceptors preempt them before PHPMailer or the HTTP transport can run.
     * Captures are cleared after the proof so later entries belong only to the
     * engine under test.
     *
     * @return true
     */
    public static function assertMailAndLoopbackInterceptorsActive()
    {
        self::interceptMail();
        $mailResult = wp_mail(
            'phase14-guard@example.invalid',
            'Phase 14 mail interception proof',
            'This message must be preempted by the local test harness.'
        );
        if ($mailResult !== true || count(self::$sentMails) !== 1) {
            throw new RuntimeException(
                'Mail interception proof failed; refusing to trust an empty mail capture.'
            );
        }
        self::interceptMail();

        self::$externalCalls = [];
        self::interceptOutboundHttp();
        $loopbackResult = wp_remote_post(
            home_url('/phase14-loopback-interception-proof'),
            [
                'blocking' => false,
                'body'     => ['phase' => 14],
            ]
        );
        if (
            !is_wp_error($loopbackResult)
            || $loopbackResult->get_error_code() !== 'fc_test_outbound_http_blocked'
            || count(self::$externalCalls) !== 1
        ) {
            throw new RuntimeException(
                'Loopback interception proof failed; refusing to run a background-engine case.'
            );
        }
        self::$externalCalls = [];

        return true;
    }

    // -----------------------------------------------------------------
    // Outbound HTTP safety
    // -----------------------------------------------------------------

    /**
     * Block every WordPress HTTP API request and retain an auditable attempt.
     *
     * The smoke suite never permits loopback or remote transport. The URL is
     * recorded without credentials or query parameters, and the current case
     * fails after the route returns. Returning WP_Error at pre_http_request
     * guarantees no socket is opened.
     */
    public static function interceptOutboundHttp()
    {
        if (self::$httpInterceptor === null) {
            self::$httpInterceptor = function ($preempt, $args, $url) {
                if (self::$providerHttpTransport !== null) {
                    $response = call_user_func(
                        self::$providerHttpTransport,
                        is_array($args) ? $args : [],
                        (string) $url
                    );
                    if ($response === false || $response === null) {
                        throw new RuntimeException(
                            'Provider transport returned no response; refusing HTTP pass-through.'
                        );
                    }

                    return $response;
                }

                $parts = wp_parse_url((string) $url);
                $safeUrl = '';
                if (is_array($parts)) {
                    $safeUrl = (isset($parts['scheme']) ? $parts['scheme'] . '://' : '')
                        . (isset($parts['host']) ? $parts['host'] : '')
                        . (isset($parts['port']) ? ':' . $parts['port'] : '')
                        . (isset($parts['path']) ? $parts['path'] : '');
                }

                self::$externalCalls[] = [
                    'method' => isset($args['method']) ? strtoupper((string) $args['method']) : 'GET',
                    'url'    => $safeUrl !== '' ? $safeUrl : '[unparseable URL]',
                ];

                return new \WP_Error(
                    'fc_test_outbound_http_blocked',
                    'Outbound HTTP is blocked by the FluentCart smoke harness.'
                );
            };
        }

        if (has_filter('pre_http_request', self::$httpInterceptor) === false) {
            add_filter('pre_http_request', self::$httpInterceptor, PHP_INT_MAX, 3);
        }
    }

    /**
     * Route WordPress HTTP through an explicit fixture-backed transport.
     *
     * The ordinary terminal blocker remains registered. It delegates only
     * while this per-case callback is active, and rejects false/null so an
     * incomplete provider fake can never fall through to a real transport.
     *
     * @param callable $transport Receives (array $args, string $url).
     */
    public static function useProviderHttpTransport(callable $transport)
    {
        if (self::$currentCase === null) {
            throw new LogicException(
                'Provider HTTP transport may only be installed inside a test case.'
            );
        }

        self::$providerHttpTransport = $transport;
        self::interceptOutboundHttp();
    }

    /**
     * Restore the ordinary block-all HTTP behavior for the current case.
     */
    public static function clearProviderHttpTransport()
    {
        self::$providerHttpTransport = null;
    }

    /**
     * Return outbound calls blocked since the current case began.
     *
     * @return array<int,array{method:string,url:string}>
     */
    public static function externalCalls()
    {
        return self::$externalCalls;
    }

    // -----------------------------------------------------------------
    // Cron safety
    // -----------------------------------------------------------------

    /**
     * Block WordPress cron mutations and retain auditable attempts.
     *
     * Permission smoke may run policy callbacks only; it must never schedule,
     * reschedule, unschedule, or clear a cron event. Controllers are separately
     * fused off, while these core preemption seams catch extension callbacks.
     */
    public static function interceptCronMutations()
    {
        $filters = [
            'pre_schedule_event'       => 'schedule',
            'pre_reschedule_event'     => 'reschedule',
            'pre_unschedule_event'     => 'unschedule',
            'pre_clear_scheduled_hook' => 'clear',
        ];

        foreach ($filters as $filter => $operation) {
            if (!isset(self::$cronInterceptors[$filter])) {
                self::$cronInterceptors[$filter] = function ($preempt, $eventOrHook) use ($operation) {
                    $hook = '[unknown hook]';
                    if (is_object($eventOrHook) && isset($eventOrHook->hook)) {
                        $hook = (string) $eventOrHook->hook;
                    } elseif (is_string($eventOrHook) && $eventOrHook !== '') {
                        $hook = $eventOrHook;
                    }

                    self::$cronAttempts[] = [
                        'operation' => $operation,
                        'hook'      => $hook,
                    ];

                    return new \WP_Error(
                        'fc_test_cron_mutation_blocked',
                        'Cron mutations are blocked by the FluentCart test harness.'
                    );
                };
            }

            if (has_filter($filter, self::$cronInterceptors[$filter]) === false) {
                add_filter($filter, self::$cronInterceptors[$filter], PHP_INT_MAX, 2);
            }
        }
    }

    /**
     * @return array<int,array{operation:string,hook:string}>
     */
    public static function cronAttempts()
    {
        return self::$cronAttempts;
    }

    /**
     * Block Action Scheduler creation before its data store is reached.
     *
     * Integration tests exercise synchronous domain behavior only. These
     * official preemption filters retain the attempted hook, return integer
     * zero as required by Action Scheduler, and fail the owning case.
     */
    public static function interceptActionScheduler()
    {
        $filters = [
            'pre_as_enqueue_async_action'    => ['operation' => 'enqueue_async', 'hook_index' => 0],
            'pre_as_schedule_single_action'  => ['operation' => 'schedule_single', 'hook_index' => 1],
            'pre_as_schedule_recurring_action' => ['operation' => 'schedule_recurring', 'hook_index' => 2],
            'pre_as_schedule_cron_action'    => ['operation' => 'schedule_cron', 'hook_index' => 2],
        ];

        foreach ($filters as $filter => $details) {
            if (!isset(self::$actionSchedulerInterceptors[$filter])) {
                self::$actionSchedulerInterceptors[$filter] = function (
                    $preempt,
                    ...$arguments
                ) use ($details) {
                    $hookIndex = (int) $details['hook_index'];
                    $hook = isset($arguments[$hookIndex])
                        ? (string) $arguments[$hookIndex]
                        : '[unknown hook]';

                    self::$actionSchedulerAttempts[] = [
                        'operation' => (string) $details['operation'],
                        'hook'      => $hook !== '' ? $hook : '[empty hook]',
                    ];

                    if (self::$expectedActionSchedulerCapture) {
                        return 900000 + count(self::$actionSchedulerAttempts);
                    }

                    return 0;
                };
            }

            if (has_filter($filter, self::$actionSchedulerInterceptors[$filter]) === false) {
                add_filter(
                    $filter,
                    self::$actionSchedulerInterceptors[$filter],
                    PHP_INT_MAX,
                    8
                );
            }
        }
    }

    /**
     * @return array<int,array{operation:string,hook:string}>
     */
    public static function actionSchedulerAttempts()
    {
        return self::$actionSchedulerAttempts;
    }

    /**
     * Capture an expected enqueue without writing to or running Action Scheduler.
     *
     * The preemption filters remain the terminal callbacks. During this narrow
     * mode they return a positive synthetic action ID so production queueing
     * code can persist its normal "queued" bookkeeping. The caller must consume
     * the capture before the case ends; otherwise the standard fail-closed case
     * check reports the scheduler attempt as a failure.
     */
    public static function beginExpectedActionSchedulerCapture()
    {
        self::$actionSchedulerAttempts = [];
        self::$expectedActionSchedulerCapture = true;
        self::interceptActionScheduler();
    }

    /**
     * Return and clear explicitly expected scheduler attempts.
     *
     * @return array<int,array{operation:string,hook:string}>
     */
    public static function consumeExpectedActionSchedulerAttempts()
    {
        $attempts = self::$actionSchedulerAttempts;
        self::$actionSchedulerAttempts = [];
        self::$expectedActionSchedulerCapture = false;

        return $attempts;
    }

    /**
     * Abort send-path suites unless FluentSMTP's site-wide simulation net is active.
     *
     * Browser tests run in a separate PHP process where this harness's pre_wp_mail
     * interceptor cannot apply. Phases that exercise sending therefore verify the
     * persistent FluentSMTP backstop before and after their work; a setting change
     * is a hard failure because continuing could deliver real email.
     *
     * @return true
     * @throws RuntimeException When simulation is missing or disabled.
     */
    public static function assertMailSimulationActive()
    {
        $settings = get_option('fluentmail-settings', []);
        $simulate = is_array($settings)
            && isset($settings['misc'])
            && is_array($settings['misc'])
            ? (isset($settings['misc']['simulate_emails']) ? $settings['misc']['simulate_emails'] : null)
            : null;

        if ($simulate !== 'yes') {
            throw new RuntimeException(
                'FluentSMTP simulate_emails must remain "yes" while send-path tests run.'
            );
        }

        return true;
    }

    // -----------------------------------------------------------------
    // Loopback safety
    // -----------------------------------------------------------------

    /**
     * Fail closed at the configured non-blocking request choke point.
     *
     * The production filter is inert unless a caller returns true. Tests use
     * this interceptor to assert continuation intent and lock state without
     * allowing cURL or WordPress HTTP to leave the process.
     */
    public static function interceptLoopbacks()
    {
        self::$firedLoopbacks = [];
        $filter = self::config('loopback_filter', '');

        if ($filter === '') {
            throw new RuntimeException(
                'No loopback interception filter is configured; refusing to run a loopback-path test.'
            );
        }

        if (self::$loopbackInterceptor === null) {
            self::$loopbackInterceptor = function ($intercept, $url, $body) {
                self::$firedLoopbacks[] = [
                    'url'  => (string) $url,
                    'body' => is_array($body) ? $body : [],
                ];
                return true;
            };
        }

        if (has_filter($filter, self::$loopbackInterceptor) === false) {
            add_filter($filter, self::$loopbackInterceptor, PHP_INT_MAX, 3);
        }
    }

    /**
     * Return continuation requests captured since interceptLoopbacks().
     *
     * @return array<int,array{url:string,body:array}>
     */
    public static function firedLoopbacks()
    {
        return self::$firedLoopbacks;
    }

    /**
     * Remove the fail-closed loopback interceptor between independently-owned
     * suites in the same WP-CLI process.
     *
     * Most callers keep interception active for the rest of the process. A
     * suite that must hand control to another transport probe can explicitly
     * release this callback so it does not mask that later probe.
     */
    public static function releaseLoopbackInterceptor()
    {
        self::$firedLoopbacks = [];
        $filter = self::config('loopback_filter', '');

        if ($filter !== '' && self::$loopbackInterceptor !== null) {
            remove_filter(
                $filter,
                self::$loopbackInterceptor,
                PHP_INT_MAX
            );
        }
    }

    // -----------------------------------------------------------------
    // Isolated public requests
    // -----------------------------------------------------------------

    /**
     * Run one public handler in a real isolated PHP-CGI request.
     *
     * Public handlers frequently terminate through wp_send_json(), redirects,
     * or a tracking-pixel exit, and SES bounce handling reads php://input.
     * A CGI child preserves those request semantics without making loopback
     * HTTP calls or terminating the shared integration process. The child
     * installs the same fail-closed mail and loopback guards and reports their
     * captures on stderr during shutdown.
     *
     * @param string              $action   Worker action from public-handler-request.php.
     * @param array<string,mixed> $query    Query-string values.
     * @param array<string,mixed> $form     URL-encoded POST values.
     * @param string|null         $rawBody  Raw request body, normally JSON.
     * @param array<string,mixed> $options  Worker flags and request cookies.
     * @return array{status:int,headers:string,body:string,data:mixed,stderr:string,guards:array<string,mixed>}
     */
    public static function publicHandlerRequest(
        $action,
        array $query = [],
        array $form = [],
        $rawBody = null,
        array $options = []
    ) {
        $results = self::publicHandlerRequests([
            [
                'action'   => $action,
                'query'    => $query,
                'form'     => $form,
                'raw_body' => $rawBody,
                'options'  => $options,
            ],
        ]);

        return $results[0];
    }

    /**
     * Run independent public-handler requests concurrently.
     *
     * Public-surface matrices need wrong, missing, and cross-owner requests for
     * the same behavior. Each request must retain real CGI exit/redirect/input
     * semantics, but serially booting WordPress for every independent variant
     * dominated the entire integration tier. Bounded parallel CGI workers keep
     * those semantics and safety reports while avoiding unnecessary wall time.
     *
     * Request rows accept the same arguments as publicHandlerRequest() under
     * action, query, form, raw_body, and options keys. Results preserve order.
     *
     * @param array<int,array<string,mixed>> $requests
     * @return array<int,array{status:int,headers:string,body:string,data:mixed,stderr:string,guards:array<string,mixed>}>
     */
    public static function publicHandlerRequests(array $requests)
    {
        static $coverageChildSequence = 0;

        if (!$requests) {
            return [];
        }

        $cgiBinary = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php-cgi';
        if (!is_executable($cgiBinary)) {
            throw new RuntimeException('php-cgi is required for isolated public-handler tests.');
        }

        $worker = dirname(__DIR__) . '/bin/public-handler-request.php';
        if (!is_file($worker)) {
            throw new RuntimeException('Public-handler CGI worker is missing: ' . $worker);
        }

        $concurrency = (int) getenv('WP_PLUGIN_TEST_PUBLIC_REQUEST_CONCURRENCY');
        if ($concurrency < 1) {
            $concurrency = 8;
        }
        $concurrency = min(8, $concurrency);

        $results = [];
        foreach (array_chunk($requests, $concurrency, true) as $requestChunk) {
            $running = [];

            foreach ($requestChunk as $index => $request) {
                $action = isset($request['action']) ? (string) $request['action'] : '';
                $query = isset($request['query']) && is_array($request['query'])
                    ? $request['query']
                    : [];
                $form = isset($request['form']) && is_array($request['form'])
                    ? $request['form']
                    : [];
                $rawBody = array_key_exists('raw_body', $request)
                    ? $request['raw_body']
                    : null;
                $options = isset($request['options']) && is_array($request['options'])
                    ? $request['options']
                    : [];
                if ($action === '') {
                    throw new InvalidArgumentException('Public-handler request action is required.');
                }

                $method = isset($options['method'])
                    ? strtoupper((string) $options['method'])
                    : (($form || $rawBody !== null) ? 'POST' : 'GET');
                $body = $rawBody !== null ? (string) $rawBody : http_build_query($form);
                $contentType = $rawBody !== null
                    ? 'application/json'
                    : 'application/x-www-form-urlencoded';

                $environment = array_merge(getenv(), [
                    'REDIRECT_STATUS'                  => '1',
                    'REQUEST_METHOD'                  => $method,
                    /*
                     * Keep the real CGI query empty while WordPress boots.
                     * The plugin routes public requests on wp_loaded, before the
                     * worker can install guards. The worker injects this query
                     * after bootstrap and dispatches the handler explicitly.
                     */
                    'QUERY_STRING'                    => '',
                    'CONTENT_TYPE'                    => $method === 'POST' ? $contentType : '',
                    'CONTENT_LENGTH'                  => $method === 'POST' ? (string) strlen($body) : '0',
                    'SCRIPT_FILENAME'                 => $worker,
                    'SCRIPT_NAME'                     => '/tests/bin/public-handler-request.php',
                    'SERVER_PROTOCOL'                 => 'HTTP/1.1',
                    'SERVER_NAME'                     => 'localhost',
                    'HTTP_HOST'                       => 'localhost',
                    'REMOTE_ADDR'                     => '127.0.0.1',
                    'WP_PLUGIN_TEST_PUBLIC_HANDLER_TEST'         => '1',
                    'WP_PLUGIN_TEST_PUBLIC_HANDLER_ACTION'       => $action,
                    'WP_PLUGIN_TEST_PUBLIC_HANDLER_QUERY'        => base64_encode(wp_json_encode($query)),
                    'WP_PLUGIN_TEST_PUBLIC_HANDLER_WP_ROOT'      => rtrim(ABSPATH, '/'),
                    'WP_PLUGIN_TEST_PUBLIC_HANDLER_LATE_LOADED'  => !empty($options['run_late_wp_loaded']) ? '1' : '0',
                    'WP_PLUGIN_TEST_PUBLIC_HANDLER_REQUIRE_TOKEN' => !empty($options['require_benchmark_token']) ? '1' : '0',
                    'WP_PLUGIN_TEST_PUBLIC_HANDLER_CALLBACK_FILE' => isset($options['callback_file'])
                        ? (string) $options['callback_file']
                        : '',
                ]);

                if (!empty($options['cookies']) && is_array($options['cookies'])) {
                    $cookies = [];
                    foreach ($options['cookies'] as $name => $value) {
                        $cookies[] = rawurlencode((string) $name) . '=' . rawurlencode((string) $value);
                    }
                    $environment['HTTP_COOKIE'] = implode('; ', $cookies);
                }

                $command = [$cgiBinary];
                $coverageChildDir = (string) getenv('WP_PLUGIN_TEST_PCOV_CHILD_DIR');
                if ($coverageChildDir !== '' && is_dir($coverageChildDir)) {
                    $coverageBootstrap = dirname(__DIR__) . '/bin/coverage-bootstrap.php';
                    $coverageSource = dirname(__DIR__, 2);
                    if (!is_file($coverageBootstrap)) {
                        throw new RuntimeException(
                            'Coverage bootstrap is missing for public-handler CGI workers.'
                        );
                    }

                    $coverageChildSequence++;
                    $environment['WP_PLUGIN_TEST_PCOV_OUTPUT'] = rtrim($coverageChildDir, '/')
                        . '/public-' . getmypid() . '-'
                        . str_pad((string) $coverageChildSequence, 5, '0', STR_PAD_LEFT)
                        . '.pcov';
                    $environment['WP_PLUGIN_TEST_PCOV_SUITE'] = 'integration-public-handler';
                    $environment['WP_PLUGIN_TEST_PCOV_CHILD_DIR'] = '';
                    $command = array_merge($command, [
                        '-d',
                        'pcov.enabled=1',
                        '-d',
                        'pcov.directory=' . $coverageSource,
                        '-d',
                        'auto_prepend_file=' . $coverageBootstrap,
                    ]);
                }
                $command[] = $worker;

                $pipes = [];
                $process = proc_open(
                    $command,
                    [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ],
                    $pipes,
                    dirname($worker),
                    $environment
                );

                if (!is_resource($process)) {
                    throw new RuntimeException('Could not start the public-handler CGI worker.');
                }

                fwrite($pipes[0], $body);
                fclose($pipes[0]);
                $running[$index] = [
                    'process' => $process,
                    'pipes'   => $pipes,
                ];
            }

            foreach ($running as $index => $workerProcess) {
                $pipes = $workerProcess['pipes'];
                $stdout = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                $stderr = stream_get_contents($pipes[2]);
                fclose($pipes[2]);
                $exitCode = proc_close($workerProcess['process']);

                $results[$index] = self::parsePublicHandlerResult(
                    $exitCode,
                    $stdout,
                    $stderr
                );
            }
        }

        ksort($results);
        return array_values($results);
    }

    /**
     * Validate one CGI worker result and decode its response and guard report.
     *
     * @return array{status:int,headers:string,body:string,data:mixed,stderr:string,guards:array<string,mixed>}
     */
    private static function parsePublicHandlerResult($exitCode, $stdout, $stderr)
    {
        if ($exitCode !== 0) {
            $workerOutput = trim(strip_tags((string) $stdout . "\n" . (string) $stderr));
            if (strlen($workerOutput) > 4000) {
                $workerOutput = substr($workerOutput, -4000);
            }
            throw new RuntimeException(
                'Public-handler CGI worker exited ' . $exitCode . ': ' . $workerOutput
            );
        }

        $parts = preg_split("/\r?\n\r?\n/", (string) $stdout, 2);
        $headers = count($parts) === 2 ? $parts[0] : '';
        $responseBody = count($parts) === 2 ? $parts[1] : (string) $stdout;
        $status = 200;
        if (preg_match('/^Status:\s*(\d{3})/mi', $headers, $match)) {
            $status = (int) $match[1];
        }

        $guards = null;
        if (preg_match('/^WP_PLUGIN_TEST_PUBLIC_GUARD:([A-Za-z0-9+\/=]+)$/m', (string) $stderr, $match)) {
            $decoded = base64_decode($match[1], true);
            $guards = $decoded === false ? null : json_decode($decoded, true);
        }
        if (!is_array($guards) || empty($guards['ok'])) {
            throw new RuntimeException(
                'Public-handler child did not prove mail/loopback safety: ' . trim((string) $stderr)
            );
        }

        $data = json_decode(trim($responseBody), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $data = null;
        }

        return [
            'status'  => $status,
            'headers' => $headers,
            'body'    => $responseBody,
            'data'    => $data,
            'stderr'  => (string) $stderr,
            'guards'  => $guards,
        ];
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** Count queries run by $fn — for locking in N+1 fixes. */
    public static function countQueries(callable $fn)
    {
        global $wpdb;
        $before = $wpdb->num_queries;
        $fn();
        return $wpdb->num_queries - $before;
    }

    /**
     * Profile one database call boundary without retaining or asserting SQL text.
     *
     * Elapsed time is returned for informational output only. Throughput guards
     * assert the query counter and returned batch values, never wall-clock time.
     *
     * @return array{result:mixed,query_count:int,elapsed_ms:float}
     */
    public static function profileQueries(callable $fn)
    {
        global $wpdb;

        $before = (int) $wpdb->num_queries;
        $startedAt = microtime(true);
        $result = $fn();

        return [
            'result'      => $result,
            'query_count' => (int) $wpdb->num_queries - $before,
            'elapsed_ms'  => round((microtime(true) - $startedAt) * 1000, 3),
        ];
    }

    /** Unique suffix so fixtures from different runs never collide. */
    public static function uniq($prefix = 'fctest')
    {
        return $prefix . '-' . strtolower(wp_generate_password(8, false, false));
    }

    /**
     * @return array<string,int>
     */
    public static function protectedCounts()
    {
        return FcProtectedTables::capture(
            array_values((array) self::config('protected_tables', []))
        );
    }

    /**
     * @return array<string,int>
     */
    public static function protectedBaseline()
    {
        return self::$protectedBaseline;
    }

    /**
     * @return array<string,int>
     */
    public static function assertProtectedCountsUnchanged($context)
    {
        return FcProtectedTables::assertUnchanged(
            self::$protectedBaseline,
            array_values((array) self::config('protected_tables', [])),
            $context
        );
    }

    private static function relPath($file)
    {
        $pathFragment = self::config('plugin_slug') . '/';
        $pos = strpos($file, $pathFragment);
        return $pos === false ? basename($file) : substr($file, $pos);
    }

    /**
     * Read one value from the central per-plugin configuration.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    private static function config($key, $default = null)
    {
        static $config;

        if ($config === null) {
            $config = require dirname(__DIR__) . '/suite.config.php';
        }

        return array_key_exists($key, $config) ? $config[$key] : $default;
    }

    private static function errorLabel($errno)
    {
        $map = [
            E_WARNING           => 'Warning',
            E_NOTICE            => 'Notice',
            E_USER_WARNING      => 'User Warning',
            E_USER_NOTICE       => 'User Notice',
            E_DEPRECATED        => 'Deprecated',
            E_USER_DEPRECATED   => 'User Deprecated',
            E_RECOVERABLE_ERROR => 'Recoverable Error',
        ];
        return isset($map[$errno]) ? $map[$errno] : ('Error(' . $errno . ')');
    }
}
