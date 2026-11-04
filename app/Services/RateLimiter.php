<?php

namespace FluentCart\App\Services;

use FluentCart\App\Helpers\AddressHelper;

class RateLimiter
{

    public static function isActive()
    {
        return wp_using_ext_object_cache();
    }

    /**
     * Checks if the action identified by $identifier is being spammed. This will only work if an external object cache is enabled.
     * Usage: FluentCart\App\Services\RateLimiter::isSpamming('checkout_attempt', 5, 60); // allows 5 attempts per 60 seconds
     *
     * @param $identifier string A unique identifier for the action being rate limited. For example: checkout_attempt
     * @param $limit Number of allowed attempts within the time window
     * @param $seconds integer Time window in seconds
     * @param $sendJson boolean Whether to send a JSON response when rate limit is exceeded
     * @return bool
     */
    public static function isSpamming($identifier, $limit = 10, $seconds = 30, $sendJson = false)
    {
        global $wpdb;

        $prefix = AddressHelper::getIpAddress();
        $userId = get_current_user_id();
        if ($userId) {
            $prefix .= "_user_{$userId}";
        }

        $prefix = md5($prefix);
        $identifier = "{$prefix}_{$identifier}";

        // Parallel hits from one visitor update the window one at a time; a hit
        // that cannot get its turn counts as over the limit.
        $lockName = 'fct_rate_' . md5($identifier);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 2)) !== '1') {
            $isSpamming = true;
        } else {
            // Remove hits older than the time window, then add this one
            $currentTime = time();
            $hits = array_filter(static::getCurrentHits($identifier), function ($hitTime) use ($currentTime, $seconds) {
                return ($currentTime - $hitTime) <= $seconds;
            });
            $hits[] = $currentTime;
            self::setHits($identifier, array_values($hits), $seconds);
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));

            $isSpamming = count($hits) > $limit;
        }

        if ($isSpamming && $sendJson) {
            wp_send_json([
                'message' => __('Too many requests. Please try again after some time.', 'fluent-cart')
            ], 429);
        }

        return $isSpamming;
    }

    // Without an external object cache wp_cache_* lives only for the request,
    // so the window is kept in a transient instead.
    private static function getCurrentHits($identifier)
    {
        $hits = self::isActive()
            ? Cache::get("rate_limit:{$identifier}")
            : get_transient("fct_rate_limit_{$identifier}");

        return is_array($hits) ? $hits : [];
    }

    private static function setHits($identifier, $hits, $seconds)
    {
        if (self::isActive()) {
            return Cache::set("rate_limit:{$identifier}", $hits, $seconds);
        }

        return set_transient("fct_rate_limit_{$identifier}", $hits, $seconds);
    }
}
