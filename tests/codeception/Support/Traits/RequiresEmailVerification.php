<?php

namespace Tests\Support\Traits;

use FluentCart\Api\StoreSettings;

/**
 * Stores require customer email verification by default. Tests pick a mode
 * explicitly and restore the default in tearDown: option writes are not rolled
 * back between tests, so a leftover value would leak into later tests.
 */
trait RequiresEmailVerification
{
    protected function requireEmailVerification(): void
    {
        $this->writeEmailVerificationSetting('yes');
    }

    protected function disableEmailVerification(): void
    {
        $this->writeEmailVerificationSetting('no');
    }

    protected function restoreEmailVerificationDefault(): void
    {
        $this->writeEmailVerificationSetting(null);
    }

    private function writeEmailVerificationSetting(?string $value): void
    {
        $settings = (array) get_option('fluent_cart_store_settings', []);
        if ($value === null) {
            unset($settings['require_customer_email_verification']);
        } else {
            $settings['require_customer_email_verification'] = $value;
        }
        update_option('fluent_cart_store_settings', $settings);
        StoreSettings::clearCache();
    }
}
