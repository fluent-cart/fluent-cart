<?php

namespace Tests\Integration\Modules\MultiCurrency;

use FluentCart\App\Hooks\Handlers\BlockEditors\CurrencySwitcherBlockEditor;
use FluentCart\App\Modules\MultiCurrency\Services\CartCurrency;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\ExchangeRateService;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Traits\MakesMultiCurrencyAvailable;
use Tests\Support\RateFixture;

/**
 * The currency switcher — shortcode and block render the same form, and
 * nothing at all when only one currency can be offered
 * (dev-docs/multi-currency/plan.md §8.2). Base USD.
 */
class CurrencySwitcherTest extends DatabaseTestCase
{
    use MakesMultiCurrencyAvailable;

    public function setUp(): void
    {
        parent::setUp();
        $this->makeMultiCurrencyAvailable();
        $this->reset();
    }

    public function tearDown(): void
    {
        unset($_COOKIE[CurrencyContext::COOKIE]);
        $this->reset();
        parent::tearDown();
    }

    public function test_nothing_renders_with_a_single_currency(): void
    {
        MultiCurrencySettings::save(['enabled' => 'no', 'currencies' => []]);
        $this->reset();

        $this->assertSame('', do_shortcode('[fluent_cart_currency_switcher]'));
        // Blocks may print the shared block-supports CSS once per request;
        // the switcher itself must not render
        $this->assertStringNotContainsString('fct-currency-switcher', do_blocks('<!-- wp:fluent-cart/currency-switcher /-->'));
    }

    public function test_the_shortcode_and_the_block_list_offerable_currencies_with_the_current_one_selected(): void
    {
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP'], ['code' => 'EUR']]]);
        RateFixture::set('GBP', '0.79');
        $_COOKIE[CurrencyContext::COOKIE] = 'GBP';
        $this->reset();

        $this->assertTrue(\WP_Block_Type_Registry::get_instance()->is_registered('fluent-cart/currency-switcher'));

        foreach ([do_shortcode('[fluent_cart_currency_switcher]'), do_blocks('<!-- wp:fluent-cart/currency-switcher /-->')] as $html) {
            $this->assertStringContainsString('name="currency"', $html);
            $this->assertStringContainsString('value="USD"', $html);
            $this->assertMatchesRegularExpression('/value="GBP"\s+selected/', $html);
            $this->assertStringNotContainsString('value="EUR"', $html, 'no rate, not offered');
        }
    }

    public function test_the_block_keeps_the_pages_query_string_and_each_block_gets_its_own_id(): void
    {
        $this->offerGbp();
        $get = $_GET;
        $_GET = ['fct_cart_hash' => md5('buy-now'), 'page_id' => '42', 'currency' => 'GBP'];

        try {
            $html = do_blocks('<!-- wp:fluent-cart/currency-switcher /--><!-- wp:fluent-cart/currency-switcher /-->');
        } finally {
            $_GET = $get;
        }

        $this->assertMatchesRegularExpression('/<input type="hidden" name="fct_cart_hash" value="' . md5('buy-now') . '"/', $html, 'a buy-now or re-pay cart survives a switch from the block');
        $this->assertMatchesRegularExpression('/<input type="hidden" name="page_id" value="42"/', $html);
        $this->assertDoesNotMatchRegularExpression('/type="hidden" name="currency"/', $html);

        preg_match_all('/<select id="([^"]+)"/', $html, $ids);
        $this->assertCount(2, $ids[1]);
        $this->assertNotSame($ids[1][0], $ids[1][1], 'two blocks on one page, two labels that point at their own select');
    }

    public function test_the_block_keeps_the_class_the_editor_gave_it(): void
    {
        $this->offerGbp();

        $html = do_blocks('<!-- wp:fluent-cart/currency-switcher {"className":"header-switcher"} /-->');

        $this->assertMatchesRegularExpression('/<div[^>]*class="([^"]*)"[^>]*>\s*<form class="fct-currency-switcher"/', $html, 'the frontend carries the same wrapper the editor shows');
        preg_match('/<div[^>]*class="([^"]*)"/', $html, $wrapper);
        $classes = preg_split('/\s+/', $wrapper[1]);
        $this->assertContains('wp-block-fluent-cart-currency-switcher', $classes);
        $this->assertContains('header-switcher', $classes);
    }

    public function test_the_block_leaves_no_empty_wrapper_with_a_single_currency(): void
    {
        MultiCurrencySettings::save(['enabled' => 'no', 'currencies' => []]);
        $this->reset();

        $html = do_blocks('<!-- wp:fluent-cart/currency-switcher {"className":"header-switcher"} /-->');

        $this->assertStringNotContainsString('wp-block-fluent-cart-currency-switcher', $html);
        $this->assertStringNotContainsString('header-switcher', $html);
    }

    public function test_a_filter_can_replace_the_select_in_the_shortcode_and_the_block(): void
    {
        $this->offerGbp();
        $seen = [];
        add_filter('fluent_cart/currency_switcher/render', function ($html, $context) use (&$seen) {
            $seen[] = $context;

            return '<button class="custom-switcher">' . esc_html($context['current']) . '</button>';
        }, 10, 2);

        $shortcode = do_shortcode('[fluent_cart_currency_switcher display="code"]');
        $block = do_blocks('<!-- wp:fluent-cart/currency-switcher {"className":"header-switcher"} /-->');
        remove_all_filters('fluent_cart/currency_switcher/render');

        $this->assertSame('<button class="custom-switcher">GBP</button>', $shortcode);
        $this->assertMatchesRegularExpression('/<div[^>]*class="[^"]*header-switcher[^"]*"[^>]*><button class="custom-switcher">GBP<\/button><\/div>/', $block, 'the block keeps its wrapper around the replacement');
        $this->assertStringNotContainsString('<select', $shortcode . $block);
        $this->assertSame(['USD', 'GBP'], $seen[0]['currencies']);
        $this->assertSame(['display' => 'code'], $seen[0]['atts']);
    }

    public function test_a_filter_that_returns_nothing_keeps_the_select(): void
    {
        $this->offerGbp();
        add_filter('fluent_cart/currency_switcher/render', '__return_null');

        $html = do_shortcode('[fluent_cart_currency_switcher]');
        remove_all_filters('fluent_cart/currency_switcher/render');

        $this->assertStringContainsString('<select', $html);
    }

    public function test_the_filter_is_not_asked_with_a_single_currency(): void
    {
        MultiCurrencySettings::save(['enabled' => 'no', 'currencies' => []]);
        $this->reset();
        $asked = false;
        add_filter('fluent_cart/currency_switcher/render', function ($html) use (&$asked) {
            $asked = true;

            return $html;
        });

        $html = do_shortcode('[fluent_cart_currency_switcher]');
        remove_all_filters('fluent_cart/currency_switcher/render');

        $this->assertSame('', $html);
        $this->assertFalse($asked);
    }

    public function test_the_block_declares_the_shortcode_options(): void
    {
        $attributes = \WP_Block_Type_Registry::get_instance()->get_registered('fluent-cart/currency-switcher')->attributes;

        $this->assertSame(['type' => 'string', 'default' => ''], $attributes['display']);
        $this->assertSame(['type' => 'string', 'default' => 'yes'], $attributes['flag']);
        $this->assertSame(['type' => 'string', 'default' => ''], $attributes['list_display']);
        $this->assertSame(['type' => 'string', 'default' => 'yes'], $attributes['list_flag']);
        $this->assertSame(['type' => 'string', 'default' => 'no'], $attributes['list_symbol']);
        $this->assertSame(['type' => 'string', 'default' => ''], $attributes['symbol']);
        $this->assertSame(['type' => 'string', 'default' => 'left'], $attributes['flag_position']);
        $this->assertSame(['type' => 'string', 'default' => 'left'], $attributes['list_flag_position']);
    }

    public function test_the_block_hands_its_sign_and_flag_position_to_the_switcher(): void
    {
        $this->offerGbp();
        $seen = [];
        add_filter('fluent_cart/currency_switcher/render', function ($html, $context) use (&$seen) {
            $seen[] = $context['atts'];

            return null;
        }, 1, 2);

        do_blocks('<!-- wp:fluent-cart/currency-switcher {"symbol":"no","flag_position":"right"} /-->');
        do_blocks('<!-- wp:fluent-cart/currency-switcher {"symbol":"maybe","flag_position":"up"} /-->');
        do_blocks('<!-- wp:fluent-cart/currency-switcher /-->');
        remove_all_filters('fluent_cart/currency_switcher/render');

        $this->assertSame(['no', 'right'], [$seen[0]['symbol'], $seen[0]['flag_position']]);
        $this->assertSame(['', 'left'], [$seen[1]['symbol'], $seen[1]['flag_position']], 'unknown values fall back to the defaults');
        $this->assertSame(['', 'left'], [$seen[2]['symbol'], $seen[2]['flag_position']]);
    }

    public function test_the_block_hands_its_list_options_to_the_switcher(): void
    {
        $this->offerGbp();
        $seen = [];
        add_filter('fluent_cart/currency_switcher/render', function ($html, $context) use (&$seen) {
            $seen[] = $context['atts'];

            return null;
        }, 1, 2);

        do_blocks('<!-- wp:fluent-cart/currency-switcher {"list_display":"code","list_flag":"no","list_symbol":"yes","list_flag_position":"right"} /-->');
        do_blocks('<!-- wp:fluent-cart/currency-switcher {"list_display":"code_symbol","list_flag":"maybe","list_symbol":"maybe","list_flag_position":"up"} /-->');
        remove_all_filters('fluent_cart/currency_switcher/render');

        $this->assertSame(['code', 'no', 'yes'], [$seen[0]['list_display'], $seen[0]['list_flag'], $seen[0]['list_symbol']]);
        $this->assertSame(['', 'yes', 'no'], [$seen[1]['list_display'], $seen[1]['list_flag'], $seen[1]['list_symbol']], 'unknown values, including the dropped code_symbol, fall back to the defaults');
        $this->assertSame(['right', 'left'], [$seen[0]['list_flag_position'], $seen[1]['list_flag_position']]);
    }

    public function test_the_block_hands_its_options_to_the_switcher_like_the_shortcode(): void
    {
        $this->offerGbp();
        $seen = [];
        add_filter('fluent_cart/currency_switcher/render', function ($html, $context) use (&$seen) {
            $seen[] = $context['atts'];

            return null;
        }, 1, 2);

        do_shortcode('[fluent_cart_currency_switcher display="code" flag="no"]');
        do_blocks('<!-- wp:fluent-cart/currency-switcher {"display":"code","flag":"no"} /-->');
        do_blocks('<!-- wp:fluent-cart/currency-switcher {"display":"<b>bold</b>","flag":"maybe"} /-->');
        do_blocks('<!-- wp:fluent-cart/currency-switcher {"display":"flag"} /-->');
        do_blocks('<!-- wp:fluent-cart/currency-switcher {"display":"name_code","list_display":"flag"} /-->');
        do_blocks('<!-- wp:fluent-cart/currency-switcher {"list_display":"symbol"} /-->');
        remove_all_filters('fluent_cart/currency_switcher/render');

        $this->assertSame(['display' => 'code', 'flag' => 'no'], $seen[0]);
        $this->assertSame('code', $seen[1]['display']);
        $this->assertSame('no', $seen[1]['flag']);
        $this->assertSame('', $seen[2]['display'], 'an unknown display falls back to the default');
        $this->assertSame('yes', $seen[2]['flag']);
        $this->assertSame('flag', $seen[3]['display']);
        $this->assertSame(['name_code', 'flag'], [$seen[4]['display'], $seen[4]['list_display']], 'the button and the list take the same label options');
        $this->assertSame('symbol', $seen[5]['list_display']);
    }

    public function test_the_editor_preview_lists_each_currency_with_its_sign_and_name(): void
    {
        $this->offerGbp();

        $preview = (new CurrencySwitcherBlockEditor())->localizeData()['fluent_cart_currency_switcher_data']['preview'];

        $this->assertSame('select', $preview['style']);
        $this->assertSame(['USD', 'GBP'], array_column($preview['currencies'], 'code'));
        $this->assertSame('£', $preview['currencies'][1]['symbol']);
        $this->assertNotSame('', $preview['currencies'][1]['name']);
    }

    public function test_a_plugin_can_switch_the_editor_preview_to_its_own_switcher(): void
    {
        $this->offerGbp();
        add_filter('fluent_cart/currency_switcher/editor_preview', function ($preview) {
            $preview['style'] = 'button';

            return $preview;
        });

        $preview = (new CurrencySwitcherBlockEditor())->localizeData()['fluent_cart_currency_switcher_data']['preview'];
        remove_all_filters('fluent_cart/currency_switcher/editor_preview');

        $this->assertSame('button', $preview['style']);
    }

    public function test_the_editor_says_the_switcher_needs_pro_when_multi_currency_is_unavailable(): void
    {
        $this->offerGbp();
        add_filter('fluent_cart/currency/multi_currency_available', '__return_false');
        $this->reset();

        try {
            $data = (new CurrencySwitcherBlockEditor())->localizeData()['fluent_cart_currency_switcher_data'];
        } finally {
            remove_all_filters('fluent_cart/currency/multi_currency_available');
            $this->reset();
        }

        $this->assertFalse($data['is_pro_active']);
        $this->assertNotSame('', $data['pro_note']);
        // The notice and crowned icon are styled by the shared Pro stylesheet, in the canvas too
        $this->assertContains('admin/BlockEditor/Components/style/fct-pro-feature.scss', (new CurrencySwitcherBlockEditor())->getStyles());

        add_filter('fluent_cart/currency/multi_currency_available', '__return_true');
        $this->reset();
        $this->assertTrue((new CurrencySwitcherBlockEditor())->localizeData()['fluent_cart_currency_switcher_data']['is_pro_active']);
        remove_all_filters('fluent_cart/currency/multi_currency_available');
    }

    private function offerGbp(): void
    {
        MultiCurrencySettings::save(['enabled' => 'yes', 'currencies' => [['code' => 'GBP']]]);
        RateFixture::set('GBP', '0.79');
        $_COOKIE[CurrencyContext::COOKIE] = 'GBP';
        $this->reset();
    }

    private function reset(): void
    {
        MultiCurrencySettings::clearCache();
        ExchangeRateService::flushCache();
        CurrencyContext::reset();
        CartCurrency::reset();
    }
}
