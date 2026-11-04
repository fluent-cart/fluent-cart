<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\Framework\Support\Arr;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\CustomerFactory;
use Tests\Support\Factory\OrderFactory;

/**
 * The Checkout Currency field mirrors the save guard: disabled, with a
 * note linking to Multi Currency, exactly when a change would be refused.
 */
class StoreCurrencyFieldLockTest extends DatabaseTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP', 'rounding' => 'none']], 'rate_lock_minutes' => 30]);
        MultiCurrencySettings::clearCache();
        // The lock asks "does any order exist", and earlier classes whose code
        // commits its own transaction leave orders behind. Cleared inside this
        // test's transaction, so the delete is rolled back with the test.
        App::db()->table('fct_orders')->delete();
    }

    public function tearDown(): void
    {
        MultiCurrencySettings::clearCache();
        parent::tearDown();
    }

    public function test_the_field_is_editable_before_any_order_exists(): void
    {
        $field = $this->currencyField();

        $this->assertFalse(MultiCurrencySettings::isBaseCurrencyLocked());
        $this->assertFalse($field['disabled']);
        $this->assertSame('', $field['note']);
    }

    public function test_the_field_is_disabled_with_a_multi_currency_link_once_orders_exist(): void
    {
        (new OrderFactory())->create(['customer_id' => (new CustomerFactory())->create()->id]);

        $field = $this->currencyField();

        $this->assertTrue($field['disabled']);
        $this->assertStringContainsString('#/settings/multi-currency', $field['note']);
        $this->assertInstanceOf(\WP_Error::class, MultiCurrencySettings::guardBaseCurrencyChange('USD', 'EUR'), 'field and save guard agree');
    }

    public function test_the_field_unlocks_when_multi_currency_is_switched_off(): void
    {
        (new OrderFactory())->create(['customer_id' => (new CustomerFactory())->create()->id]);
        MultiCurrencySettings::save(['enabled' => 'no', 'currencies' => [], 'rate_lock_minutes' => 30]);
        MultiCurrencySettings::clearCache();

        $this->assertFalse($this->currencyField()['disabled']);
    }

    private function currencyField(): array
    {
        $fields = (new StoreSettings())->fields();
        $found = null;
        $this->findCurrency($fields, $found);
        $this->assertIsArray($found, 'currency_grid field exists');

        return $found;
    }

    private function findCurrency(array $node, &$found): void
    {
        if (isset($node['currency_grid'])) {
            $found = Arr::get($node, 'currency_grid.schema.currency');
            return;
        }
        foreach ($node as $child) {
            if (is_array($child) && $found === null) {
                $this->findCurrency($child, $found);
            }
        }
    }
}
