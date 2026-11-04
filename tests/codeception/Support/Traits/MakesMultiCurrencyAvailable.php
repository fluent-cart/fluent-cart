<?php

namespace Tests\Support\Traits;

/**
 * Multi-currency runs on the storefront only with Pro. Suites that do not
 * load Pro call this to test multi-currency as a Pro store has it; hooks are
 * restored after each test.
 */
trait MakesMultiCurrencyAvailable
{
    protected function makeMultiCurrencyAvailable(): void
    {
        add_filter('fluent_cart/currency/multi_currency_available', '__return_true');
    }
}
