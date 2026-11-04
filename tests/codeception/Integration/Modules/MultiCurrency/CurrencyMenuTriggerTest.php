<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use Tests\Support\DatabaseTestCase;
use Tests\Support\RateFixture;
use Tests\Support\Traits\MakesMultiCurrencyAvailable;

/**
 * A nav menu item classed .fct-currency-switcher-toggle opens Pro's currency
 * dropdown. With nothing to switch to it would be a dead "#" link, so the
 * menu drops it. Pro is not loaded in this suite. Base USD.
 */
class CurrencyMenuTriggerTest extends DatabaseTestCase
{
    use MakesMultiCurrencyAvailable;

    /** @var int */
    private $menuId;

    public function setUp(): void
    {
        parent::setUp();
        $this->reset();

        $this->menuId = (int) wp_create_nav_menu('Currency trigger ' . wp_generate_password(6, false));
        wp_update_nav_menu_item($this->menuId, 0, [
            'menu-item-title'  => 'Shop link',
            'menu-item-url'    => 'https://example.test/shop',
            'menu-item-status' => 'publish',
        ]);
        $parent = wp_update_nav_menu_item($this->menuId, 0, [
            'menu-item-title'   => 'Currency',
            'menu-item-url'     => '#',
            'menu-item-classes' => 'fct-currency-switcher-toggle',
            'menu-item-status'  => 'publish',
        ]);
        wp_update_nav_menu_item($this->menuId, 0, [
            'menu-item-title'     => 'Under currency',
            'menu-item-url'       => 'https://example.test/under',
            'menu-item-parent-id' => $parent,
            'menu-item-status'    => 'publish',
        ]);
    }

    public function tearDown(): void
    {
        unset($_COOKIE[CurrencyContext::COOKIE]);
        wp_delete_nav_menu($this->menuId);
        $this->reset();
        parent::tearDown();
    }

    public function test_the_trigger_is_dropped_without_pro(): void
    {
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP']]]);
        RateFixture::set('GBP', '0.79');
        $this->reset();

        $html = $this->renderMenu();

        $this->assertStringContainsString('Shop link', $html);
        $this->assertStringNotContainsString('fct-currency-switcher-toggle', $html);
        $this->assertStringNotContainsString('Under currency', $html, 'its submenu goes with it, not orphaned to the top level');
    }

    public function test_the_trigger_is_dropped_when_only_the_base_can_be_offered(): void
    {
        $this->makeMultiCurrencyAvailable();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP']]]);
        $this->reset();

        $html = $this->renderMenu();

        $this->assertStringContainsString('Shop link', $html);
        $this->assertStringNotContainsString('fct-currency-switcher-toggle', $html, 'GBP has no rate');
    }

    public function test_the_trigger_stays_when_a_currency_can_be_chosen(): void
    {
        $this->makeMultiCurrencyAvailable();
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP']]]);
        RateFixture::set('GBP', '0.79');
        $this->reset();

        $html = $this->renderMenu();

        $this->assertStringContainsString('fct-currency-switcher-toggle', $html);
        $this->assertStringContainsString('Under currency', $html);
        $this->assertStringContainsString('Shop link', $html);
    }

    private function renderMenu(): string
    {
        return (string) wp_nav_menu([
            'menu'        => $this->menuId,
            'echo'        => false,
            'fallback_cb' => '__return_empty_string',
        ]);
    }

    private function reset(): void
    {
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        CurrencyContext::reset();
        CartCurrency::reset();
    }
}
