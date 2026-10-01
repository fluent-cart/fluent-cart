<?php

namespace FluentCart\App\Services;

class ReviewSubmissionLimiter
{
    /** Persist attempts across requests, including sites without Redis. */
    public static function increment(string $identity): int
    {
        $key = 'fct_review_rate_' . $identity;
        if (wp_using_ext_object_cache()) {
            wp_cache_add($key, 0, 'fluent_cart_reviews', HOUR_IN_SECONDS);
            $count = wp_cache_incr($key, 1, 'fluent_cart_reviews');

            return $count === false ? PHP_INT_MAX : (int) $count;
        }

        global $wpdb;
        $lock = 'fct_review_rate_' . md5(constant('DB_NAME') . ':' . $wpdb->options . ':' . $key);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 1)', $lock)) !== '1') {
            return PHP_INT_MAX;
        }

        try {
            wp_cache_delete('_transient_' . $key, 'options');
            wp_cache_delete('_transient_timeout_' . $key, 'options');
            wp_cache_delete('notoptions', 'options');
            $state = get_transient($key);
            $now = time();
            if (!is_array($state) || ($state['expires'] ?? 0) <= $now) {
                $state = ['count' => 0, 'expires' => $now + HOUR_IN_SECONDS];
            }
            $state['count']++;

            return set_transient($key, $state, max(1, $state['expires'] - $now))
                ? (int) $state['count'] : PHP_INT_MAX;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }
}
