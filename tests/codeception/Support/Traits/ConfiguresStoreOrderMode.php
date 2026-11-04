<?php

namespace Tests\Support\Traits;

use FluentCart\Api\StoreSettings;

/**
 * Pins the store's order_mode for a test. Renewal paths only act on
 * subscriptions whose parent-order mode matches the store mode, and
 * OrderFactory defaults to live — so renewal tests must declare the store
 * live (a fresh test install defaults to 'test'). Option writes persist
 * across tests, so setStoreOrderMode() also re-pins subscription_mode_guard;
 * the StoreSettings static cache is reset explicitly (same pattern as
 * ConfiguresStripeTestMode).
 */
trait ConfiguresStoreOrderMode
{
    protected function setStoreOrderMode(string $mode): void
    {
        // Option writes are NOT rolled back between tests here, so also re-pin
        // the mode guard a previous test may have disabled.
        $this->setStoreSetting('subscription_mode_guard', 'yes');
        $this->setStoreSetting('order_mode', $mode);
    }

    protected function setStoreSetting(string $key, string $value): void
    {
        $settings = get_option('fluent_cart_store_settings', []);
        $settings[$key] = $value;
        update_option('fluent_cart_store_settings', $settings);

        wp_cache_flush();
        $ref = new \ReflectionClass(StoreSettings::class);
        if ($ref->hasProperty('cachedStoreSettings')) {
            $prop = $ref->getProperty('cachedStoreSettings');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        }
    }
}
