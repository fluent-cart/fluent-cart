<?php

namespace Tests\Support\Traits;

/**
 * Row lookup inside MCP tool envelopes (['data']['subscriptions'|...]).
 */
trait ReadsMcpRows
{
    /**
     * Find the row for one subscription id in a list-subscriptions envelope,
     * or null when the filter excluded it.
     */
    protected function subscriptionRowFor($envelope, int $subId): ?array
    {
        foreach ($envelope['data']['subscriptions'] as $row) {
            if ($row['subscription_id'] === $subId) {
                return $row;
            }
        }
        return null;
    }
}
