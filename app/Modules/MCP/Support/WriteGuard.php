<?php

namespace FluentCart\App\Modules\MCP\Support;

/**
 * Safety rails for mutating MCP tools. Annotations are UX hints, not safety —
 * this is where real protection lives for the sensitive writes (refund, cancel).
 *
 * Two mechanisms:
 *
 *  1. Dry-run + confirmation token. A destructive tool called with dry_run:true
 *     computes the effect, binds it to the entity's CURRENT state (a fingerprint),
 *     stashes a short-lived token, and returns a preview. To actually execute,
 *     the caller passes that confirm_token back. If the entity changed in the
 *     meantime, the fingerprint no longer matches and we force a fresh preview —
 *     so an agent can never act on stale numbers (e.g. refund a balance that was
 *     already refunded by someone else).
 *
 *  2. Idempotency keys. The caller passes an idempotency_key; the first execution
 *     for that key is cached, and a retry with the same key returns the cached
 *     result instead of charging/cancelling twice. This is the guard against an
 *     agent re-issuing a refund after a timeout.
 *
 * CONTRACT (enforced by convention, not the framework): every ability whose
 * annotations include `destructive => true` MUST route its mutation through
 * confirm() (dry-run + confirm_token) and, for gateway/real-money actions, also
 * liveGatewayAllowed(), before mutating — and MUST expose `dry_run` and
 * `confirm_token` in its input_schema. Reversible CRUD writes (coupon, customer,
 * label, order-status) are intentionally NOT marked destructive and rely on
 * their permission_callback alone. When adding a new destructive ability (here
 * or in Pro, which registers under the same namespace via fluent_cart/mcp_loaded),
 * follow this contract — refund-order and change-subscription-status are the
 * reference implementations.
 */
class WriteGuard
{
    const CONFIRM_TTL = 300;   // 5 minutes to confirm a previewed action.

    const IDEM_TTL = 86400;    // remember an idempotency key for a day.

    /**
     * Build a dry-run preview response with a confirmation token bound to the
     * entity's current state.
     *
     * @param string $tool         Ability name (namespacing the token).
     * @param string $entityKey    Stable id of the target, e.g. "order:42".
     * @param string $fingerprint  A string capturing the mutable state we care
     *                             about (e.g. "paid:8000|refund:0"). If this
     *                             differs at execute time, the token is rejected.
     * @param array  $preview      The human/agent-facing preview payload.
     */
    public static function preview($tool, $entityKey, $fingerprint, array $preview)
    {
        $token = substr(wp_hash($tool . '|' . $entityKey . '|' . $fingerprint . '|' . wp_generate_uuid4()), 0, 32);

        set_transient(self::confirmKey($tool, $entityKey), [
            'token'       => $token,
            'fingerprint' => $fingerprint,
        ], self::CONFIRM_TTL);

        return [
            'dry_run'            => true,
            'preview'            => $preview,
            'confirm_token'      => $token,
            'expires_in_seconds' => self::CONFIRM_TTL,
            'next_step'          => 'Call this tool again with the same parameters plus confirm_token (and an idempotency_key) to execute.',
        ];
    }

    /**
     * Validate a confirm_token against the entity's current fingerprint.
     * Returns true, or a WP_Error the agent can act on.
     *
     * @return true|\WP_Error
     */
    public static function confirm($tool, $entityKey, $currentFingerprint, $token)
    {
        if (empty($token)) {
            return MCPHelper::error(
                'confirmation_required',
                __('This action changes data. Call again with dry_run:true to preview, then pass the returned confirm_token to execute.', 'fluent-cart'),
                ['next_step' => 'set dry_run:true']
            );
        }

        $stored = get_transient(self::confirmKey($tool, $entityKey));

        if (!is_array($stored) || empty($stored['token'])) {
            return MCPHelper::error(
                'confirmation_expired',
                __('Your confirmation has expired. Run a fresh dry_run to preview and get a new confirm_token.', 'fluent-cart'),
                ['next_step' => 'set dry_run:true']
            );
        }

        if (!hash_equals((string) $stored['token'], (string) $token)) {
            return MCPHelper::error(
                'confirmation_invalid',
                __('The confirm_token does not match. Run a fresh dry_run.', 'fluent-cart'),
                ['next_step' => 'set dry_run:true']
            );
        }

        if ((string) $stored['fingerprint'] !== (string) $currentFingerprint) {
            delete_transient(self::confirmKey($tool, $entityKey));
            return MCPHelper::error(
                'state_changed',
                __('The record changed since you previewed it. Run a fresh dry_run to see the current state before executing.', 'fluent-cart'),
                ['next_step' => 'set dry_run:true']
            );
        }

        // One-shot: consume the token so it can't be replayed.
        delete_transient(self::confirmKey($tool, $entityKey));

        return true;
    }

    /**
     * Run $fn at most once per idempotency key (per user + tool + entity). A
     * repeat call with the same key on the SAME entity returns the cached result.
     * If no key is supplied, $fn runs normally (no dedupe) — keys are recommended
     * but not forced.
     *
     * The key is entity-scoped so reusing one idempotency_key across different
     * records (e.g. "refund-1" for two orders) can't replay the first entity's
     * result and silently skip the second mutation.
     */
    public static function idempotent($tool, $entityKey, $key, callable $fn)
    {
        if (empty($key)) {
            return $fn();
        }

        $cacheKey = self::idemKey($tool, $entityKey, $key);
        $cached   = get_transient($cacheKey);
        if ($cached !== false) {
            return is_array($cached) ? array_merge($cached, ['idempotent_replay' => true]) : $cached;
        }

        $result = $fn();

        // Only cache successful, serializable results.
        if (!is_wp_error($result)) {
            set_transient($cacheKey, $result, self::IDEM_TTL);
        }

        return $result;
    }

    /**
     * True when a gateway action would hit live (real-money) mode. An unknown or
     * empty mode is treated as live — we fail safe rather than assume sandbox.
     */
    public static function isLiveMode($paymentMode)
    {
        $mode = strtolower((string) $paymentMode);
        return $mode !== 'test' && $mode !== 'sandbox';
    }

    /**
     * Gate real-money gateway mutations (refund, cancel). Test/sandbox records are
     * always allowed; LIVE requires explicit opt-in via the `mcp_allow_live_gateway`
     * option ('yes') or the `fluent_cart/mcp_allow_live_gateway` filter — so an
     * agent cannot fire a live refund/cancellation by default, even holding a
     * valid confirm_token. The dry_run preview still works in either mode.
     *
     * @return true|\WP_Error
     */
    public static function liveGatewayAllowed($paymentMode)
    {
        if (!self::isLiveMode($paymentMode)) {
            return true;
        }

        $allowed = fluent_cart_get_option('mcp_allow_live_gateway', 'no') === 'yes';

        /**
         * Allow live (real-money) MCP gateway mutations. Default false; flip via
         * this filter or the mcp_allow_live_gateway option.
         *
         * @since 1.0.0
         *
         * @param bool $allowed Whether live refunds/cancellations are permitted.
         */
        $allowed = (bool) apply_filters('fluent_cart/mcp_allow_live_gateway', $allowed);

        if ($allowed) {
            return true;
        }

        return MCPHelper::error(
            'live_gateway_blocked',
            __('This is a live (real-money) gateway action and live mutations are disabled. Enable the mcp_allow_live_gateway option or the fluent_cart/mcp_allow_live_gateway filter to permit live refunds and cancellations.', 'fluent-cart'),
            [
                'payment_mode' => 'live',
                'hint'         => 'Test-mode records can be refunded or cancelled without this flag.',
            ]
        );
    }

    private static function confirmKey($tool, $entityKey)
    {
        // User-scoped: a token minted by one operator/session can't be consumed
        // by another, even for the same entity.
        return 'fct_mcp_confirm_' . get_current_user_id() . '_' . md5($tool . '|' . $entityKey);
    }

    private static function idemKey($tool, $entityKey, $key)
    {
        return 'fct_mcp_idem_' . get_current_user_id() . '_' . md5($tool . '|' . $entityKey . '|' . $key);
    }
}
