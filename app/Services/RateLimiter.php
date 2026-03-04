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
        if (!self::isActive()) {
            return false; // Currently only support rate limiting when external object cache is enabled
        }

        $prefix = AddressHelper::getIpAddress();
        $userId = get_current_user_id();
        if ($userId) {
            $prefix .= "_user_{$userId}";
        }

        $prefix = md5($prefix);
        $identifier = "{$prefix}_{$identifier}";
        $hits = static::getCurrentHits($identifier);

        if (!$hits) {
            $hits = [
                time()
            ];
            // Save the hit for the first time
            self::setHits($identifier, $hits, $seconds);
            return false; // all good here
        }

        // Remove hits older than the time window
        $currentTime = time();
        $hits = array_filter($hits, function ($hitTime) use ($currentTime, $seconds) {
            return ($currentTime - $hitTime) <= $seconds;
        });

        // Add the current hit
        $hits[] = $currentTime;
        self::setHits($identifier, $hits, $seconds);

        $isSpamming = count($hits) > $limit;

        if ($isSpamming && $sendJson) {
            wp_send_json([
                'message' => __('Too many requests. Please try again after some time.', 'fluent-cart')
            ], 429);
        }

        return $isSpamming;
    }

    private static function getCurrentHits($identifier)
    {
        $hits = Cache::get("rate_limit:{$identifier}");
        return $hits ?? [];
    }

    private static function setHits($identifier, $hits, $seconds)
    {
        return Cache::set("rate_limit:{$identifier}", $hits, $seconds);
    }
}
