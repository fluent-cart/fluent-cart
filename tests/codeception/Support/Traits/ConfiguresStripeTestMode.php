<?php

namespace Tests\Support\Traits;

use FluentCart\Api\StoreSettings;

/**
 * Puts the store into Stripe test mode with a fake secret key so code paths
 * that resolve a Stripe API key (StripeSettingsBase::getApiKey) proceed to the
 * HTTP layer, where tests mock api.stripe.com via pre_http_request.
 *
 * The FCT_STRIPE_TEST_SECRET_KEY constant only supplies a key while the store
 * order_mode is 'test' (option write, rolled back per test), so defining it
 * process-wide cannot leak live behavior into sibling tests (same pattern as
 * SpiesOnEmailMailer::forceFluentPdfConstant).
 */
trait ConfiguresStripeTestMode
{
    protected function enableStripeTestMode(): void
    {
        if (!defined('FCT_STRIPE_TEST_SECRET_KEY')) {
            define('FCT_STRIPE_TEST_SECRET_KEY', 'sk_test_fct_suite_fake_key');
        }

        $settings = get_option('fluent_cart_store_settings', []);
        $settings['order_mode'] = 'test';
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
