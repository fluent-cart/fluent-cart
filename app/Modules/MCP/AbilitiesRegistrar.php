<?php

namespace FluentCart\App\Modules\MCP;

use FluentCart\App\Modules\MCP\Tools\ContextTools;
use FluentCart\App\Modules\MCP\Tools\SearchTools;
use FluentCart\App\Modules\MCP\Tools\OrderTools;
use FluentCart\App\Modules\MCP\Tools\CustomerTools;
use FluentCart\App\Modules\MCP\Tools\ProductTools;
use FluentCart\App\Modules\MCP\Tools\SubscriptionTools;
use FluentCart\App\Modules\MCP\Tools\CouponTools;
use FluentCart\App\Modules\MCP\Tools\LabelTools;
use FluentCart\App\Modules\MCP\Tools\ReportTools;
use FluentCart\App\Modules\MCP\Tools\ProductFinancialsTools;
use FluentCart\App\Modules\MCP\Tools\PaymentScheduleTools;
use FluentCart\App\Modules\MCP\Tools\TransactionTools;

/**
 * Single source of truth for every FluentCart MCP ability.
 *
 * Each tool class owns its own `definitions()` slice (schema next to code);
 * this class merges them, wraps every execute_callback — rejecting undeclared
 * input params up front and converting unhandled exceptions into structured
 * WP_Errors the agent can read (instead of the adapter's generic "Tool
 * execution failed") — and registers each as a WP ability.
 *
 * Pro tools are NOT listed here — FluentCart Pro pushes its abilities via the
 * `fluent_cart/mcp_loaded` action + `fluent_cart/mcp_ability_names` filter.
 */
class AbilitiesRegistrar
{
    /** Tool classes that expose a static definitions() method. */
    private static function toolClasses()
    {
        return [
            ContextTools::class,
            SearchTools::class,
            OrderTools::class,
            CustomerTools::class,
            ProductTools::class,
            SubscriptionTools::class,
            CouponTools::class,
            LabelTools::class,
            ReportTools::class,
            ProductFinancialsTools::class,
            PaymentScheduleTools::class,
            TransactionTools::class,
        ];
    }

    public static function getDefinitions()
    {
        $defs = [];

        foreach (self::toolClasses() as $class) {
            if (class_exists($class) && method_exists($class, 'definitions')) {
                $defs = array_merge($defs, (array) $class::definitions());
            }
        }

        return $defs;
    }

    public static function register()
    {
        foreach (self::getDefinitions() as $name => $definition) {
            try {
                self::registerAbility($name, $definition);
            } catch (\Throwable $e) {
                // Registration runs on wp_abilities_api_init, which the adapter
                // fires lazily from INSIDE our own create_server() call — so an
                // uncaught throw here doesn't just drop this one ability: it
                // aborts every later callback on the action (other plugins'
                // abilities included) and kills the FluentCart MCP server
                // itself, 404ing the endpoint. One malformed definition must
                // never take the whole surface down: skip it, log it, move on.
                fluent_cart_error_log(
                    'MCP ability registration failed: ' . $name,
                    get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine()
                );

                /**
                 * Fires when a single MCP ability fails to register. The
                 * remaining abilities still register; this lets sites alert on
                 * the gap.
                 *
                 * @since 1.0.0
                 *
                 * @param array $context { exception: \Throwable, ability: string }
                 */
                do_action('fluent_cart/mcp_ability_registration_failed', [
                    'exception' => $e,
                    'ability'   => $name,
                ]);
            }
        }
    }

    /**
     * Register one ability definition with the Abilities API. Kept separate
     * from register() so its try/catch stays a thin skip-and-continue shell.
     */
    private static function registerAbility($name, $definition)
    {
        // Cast before array_keys: no-arg tools declare properties as
        // stdClass (so the schema serializes as {} not []), which
        // array_keys() rejects on PHP 8 with a TypeError.
        $declaredParams = isset($definition['input_schema']['properties'])
            ? array_keys((array) $definition['input_schema']['properties'])
            : [];

        $args = [
            'label'               => $definition['label'],
            'description'         => $definition['description'],
            'category'            => 'fluent-cart',
            'execute_callback'    => self::wrapExecuteCallback($name, $definition['execute_callback'], $declaredParams),
            'permission_callback' => $definition['permission_callback'],
            'meta'                => [
                'show_in_rest' => true,
                'mcp'          => ['public' => true],
            ],
        ];

        if (!empty($definition['input_schema'])) {
            $args['input_schema'] = $definition['input_schema'];
        }

        if (!empty($definition['output_schema'])) {
            $args['output_schema'] = $definition['output_schema'];
        }

        if (!empty($definition['annotations'])) {
            $mapped = self::mapAnnotations($definition['annotations']);
            if (!empty($mapped)) {
                $args['meta']['annotations'] = $mapped;
            }
        }

        wp_register_ability($name, $args);
    }

    /**
     * Translate a tool's readable snake_case behavior hints into the MCP tool
     * annotation keys clients actually read.
     *
     * Tool classes declare intent as readonly / destructive / idempotent /
     * open_world / title. The MCP spec names them readOnlyHint / destructiveHint
     * / idempotentHint / openWorldHint, and the WP MCP adapter forwards
     * meta.annotations VERBATIM (it does not translate), so an unmapped
     * 'readonly' key would never reach a client as a real hint. Unknown keys
     * (e.g. a stray 'bulk') are dropped rather than emitted as noise a client
     * cannot act on.
     *
     * @param array $annotations snake_case behavior hints from the tool definition
     * @return array MCP-standard annotation keys
     */
    private static function mapAnnotations($annotations)
    {
        $map = [
            'readonly'    => 'readOnlyHint',
            'destructive' => 'destructiveHint',
            'idempotent'  => 'idempotentHint',
            'open_world'  => 'openWorldHint',
        ];

        $out = [];
        foreach ((array) $annotations as $key => $value) {
            if ($key === 'title') {
                $out['title'] = (string) $value;
            } elseif (isset($map[$key])) {
                $out[$map[$key]] = (bool) $value;
            }
        }

        // A read-only tool cannot be destructive. destructiveHint defaults to
        // true when absent (MCP spec), so state it explicitly for read tools —
        // otherwise a client gating on destructiveHint would treat every report
        // as dangerous.
        if (!empty($out['readOnlyHint']) && !isset($out['destructiveHint'])) {
            $out['destructiveHint'] = false;
        }

        return $out;
    }

    /**
     * Reject any input param this tool does not declare, instead of executing
     * with it silently dropped. input_schema sets no additionalProperties, so an
     * unknown key would otherwise pass validation and the agent would get a
     * full, plausible-looking result that is NOT filtered the way it asked —
     * the worst failure mode for an agent (a wrong number reads as a right one;
     * an error is recoverable). Sibling tools also name overlapping concepts
     * differently (list-orders: created_after/type; query-orders: start_date/
     * order_type dimension), so carried-over names are a common, realistic slip,
     * not a rare typo. The error lists the accepted params so the agent can
     * self-correct in one step — richer than the schema validator's message,
     * which is why this is enforced here rather than via additionalProperties.
     *
     * @param string $toolName
     * @param mixed  $params         the raw input params
     * @param array  $declaredParams input_schema property names this tool declares
     * @return \WP_Error|null null when all params are declared
     */
    private static function rejectUnknownParams($toolName, $params, $declaredParams)
    {
        if (!is_array($params)) {
            return null;
        }

        $unknown = [];
        foreach (array_keys($params) as $key) {
            if (!in_array((string) $key, $declaredParams, true)) {
                $unknown[] = (string) $key;
            }
        }

        if (empty($unknown)) {
            return null;
        }

        return \FluentCart\App\Modules\MCP\Support\MCPHelper::error(
            'unknown_param',
            sprintf(
                /* translators: 1: rejected parameter names, 2: tool name, 3: accepted parameter names */
                __('Unknown parameter(s) [%1$s] — %2$s does not support them, and running without them would return a result that is NOT filtered the way you asked. Accepted parameters: [%3$s]. Rename or remove the unknown parameter(s) and retry.', 'fluent-cart'),
                implode(', ', $unknown),
                $toolName,
                $declaredParams ? implode(', ', $declaredParams) : __('none — this tool takes no parameters', 'fluent-cart')
            ),
            ['fields' => $unknown, 'accepted' => $declaredParams, 'tool' => $toolName]
        );
    }

    /**
     * Append a meta.warnings entry when a requested per_page exceeded the tool's
     * ceiling and was clamped (the descriptions state each cap, but a stated cap
     * still deserves a runtime signal — the agent asked for 500 rows and must
     * know it got a 100-row page, not the full set). Detected generically by
     * comparing the request against meta.page.per_page, so every list and
     * query tool is covered without per-tool changes. Untouched when the result
     * isn't a paged success envelope.
     *
     * @param mixed $result the tool's return value
     * @param mixed $params the raw input params
     * @return mixed
     */
    private static function annotateClampedPerPage($result, $params)
    {
        if (
            !is_array($params) || !isset($params['per_page'])
            || !is_array($result) || !isset($result['meta']['page']['per_page'])
        ) {
            return $result;
        }

        $requested = (int) $params['per_page'];
        $actual    = (int) $result['meta']['page']['per_page'];
        if ($actual < 1 || $requested <= $actual) {
            return $result;
        }

        $warnings = (isset($result['meta']['warnings']) && is_array($result['meta']['warnings']))
            ? $result['meta']['warnings']
            : [];

        $warnings[] = sprintf(
            /* translators: 1: requested per_page, 2: the maximum this tool returned */
            __('per_page %1$d exceeds this tool\'s maximum; %2$d rows per page were returned. Use meta.page (total/pages/has_more) to page through the rest.', 'fluent-cart'),
            $requested,
            $actual
        );

        $result['meta']['warnings'] = $warnings;

        return $result;
    }

    /**
     * Convert any unhandled \Throwable from a tool into a structured WP_Error
     * carrying the real message (and, under WP_DEBUG, the file + a short trace).
     * Without this the agent only sees the adapter's generic failure surface and
     * retries blindly against tools that may have partially succeeded.
     */
    private static function wrapExecuteCallback($toolName, $callback, $declaredParams = [])
    {
        return function ($params) use ($toolName, $callback, $declaredParams) {
            try {
                // Before executing: an undeclared param means the caller asked for
                // a filter this tool can't apply — error out rather than return a
                // confidently wrong (unfiltered) result. Runs before the callback
                // so write tools never partially execute on a malformed call.
                $unknownError = self::rejectUnknownParams($toolName, $params, $declaredParams);
                if ($unknownError !== null) {
                    return $unknownError;
                }

                $result = call_user_func($callback, $params);
                return self::annotateClampedPerPage($result, $params);
            } catch (\Throwable $e) {
                /**
                 * Fires when an MCP tool throws. Lets sites log/alert before the
                 * structured error reaches the agent.
                 *
                 * @since 1.0.0
                 *
                 * @param array $context { exception: \Throwable, tool: string, params: mixed }
                 */
                do_action('fluent_cart/mcp_tool_exception', [
                    'exception' => $e,
                    'tool'      => $toolName,
                    'params'    => $params,
                ]);

                // Unexpected exceptions are treated as transient (retryable):
                // the agent may legitimately retry once.
                $details = ['tool' => $toolName, 'exception' => get_class($e), 'retryable' => true];

                // File/line/trace help an operator debug, but this payload is
                // forwarded to the remote agent/LLM — raw paths would leak the
                // server's filesystem layout. So it's off by default and opt-in
                // only; full detail is always available server-side via the
                // action above. When enabled, the file is reduced to a basename.
                $exposeDetails = apply_filters('fluent_cart/mcp_expose_error_details', false);
                if ($exposeDetails) {
                    $details['file']  = basename($e->getFile()) . ':' . $e->getLine();
                    $details['trace'] = array_slice(explode("\n", $e->getTraceAsString()), 0, 5);
                }

                return \FluentCart\App\Modules\MCP\Support\MCPHelper::error('tool_failed', $e->getMessage(), $details);
            }
        };
    }
}
