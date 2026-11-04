<?php

namespace Tests\Support\Isolation;

/**
 * TC-048 — per-test restoration of the in-memory WP object cache.
 *
 * A bare `wp_cache_flush()` after each DB restore is CORRECT but slow: every
 * test then rebuilds options/users/terms caches through dozens of cold
 * `get_option()` / `get_user_by()` misses — measured at ~250ms per test,
 * the dominant share of the suite going 3:04 → 6:14.
 *
 * Instead, capture the cache contents ONCE, warm and consistent with the DB
 * snapshot taken at the same moment, and reassign a deep copy before each
 * test. serialize/unserialize is the deep copy — a plain array assignment
 * would share the stdClass/WP_User instances inside the cache by reference,
 * letting one test's in-place mutation of a cached object leak into every
 * later test through the "restored" cache.
 *
 * WP_Object_Cache's $cache property is private in current WordPress but the
 * class ships __get/__set back-compat magic accessors, which is the supported
 * seam used here.
 */
class ObjectCacheSnapshot
{
    /** @var string|null serialized baseline cache contents */
    private $blob;

    public function isCaptured(): bool
    {
        return $this->blob !== null;
    }

    /**
     * Call once, immediately after the DB snapshot is captured: flush the
     * possibly-stale cache, re-warm the hottest path (autoloaded options)
     * from the now-canonical baseline DB, and keep a deep copy.
     */
    public function capture(): void
    {
        wp_cache_flush();
        wp_load_alloptions();

        $this->blob = serialize($GLOBALS['wp_object_cache']->cache);
    }

    /**
     * Reassign the baseline cache. Call after every DB restore so cache and
     * tables describe the same state.
     */
    public function restore(): void
    {
        if ($this->blob === null) {
            throw new \RuntimeException('ObjectCacheSnapshot::restore() called before capture().');
        }

        $GLOBALS['wp_object_cache']->cache = unserialize($this->blob);
    }
}
