<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencyContext;
use FluentCart\App\Modules\MultiCurrency\Services\CurrencySwitcher;
use FluentCart\App\Modules\MultiCurrency\Services\MultiCurrencySettings;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\Framework\Support\Arr;

/**
 * Currency switcher block — the block form of [fluent_cart_currency_switcher]
 * (dev-docs/multi-currency/plan.md §8.2). Server-rendered; renders nothing
 * unless more than one currency can be offered.
 */
class CurrencySwitcherBlockEditor extends BlockEditor
{
    protected static string $editorName = 'currency-switcher';

    public function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/CurrencySwitcher/CurrencySwitcherBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    // Components/Pro's styles, for the Pro notice and crowned icon
    public function getStyles(): array
    {
        return [
            'admin/BlockEditor/Components/style/fct-pro-feature.scss'
        ];
    }

    /**
     * The shortcode's own options, so the block can show what the shortcode shows.
     */
    public function blockAttributes(): array
    {
        return [
            'display'            => ['type' => 'string', 'default' => ''],
            'flag'               => ['type' => 'string', 'default' => 'yes'],
            // Empty keeps the display's own sign rule, so old shortcodes don't change
            'symbol'             => ['type' => 'string', 'default' => ''],
            'flag_position'      => ['type' => 'string', 'default' => 'left'],
            'list_display'       => ['type' => 'string', 'default' => ''],
            'list_flag'          => ['type' => 'string', 'default' => 'yes'],
            'list_symbol'        => ['type' => 'string', 'default' => 'no'],
            'list_flag_position' => ['type' => 'string', 'default' => 'left'],
        ];
    }

    public function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'          => $this->slugPrefix,
                'name'          => static::getEditorName(),
                'title'         => __('Currency Switcher', 'fluent-cart'),
                'empty_note'    => __('Shows when Settings → Multi Currency offers more than one currency.', 'fluent-cart'),
                'preview'       => $this->editorPreview(),
                // Multi-currency is a Pro feature; without it the switcher renders nothing
                'is_pro_active' => MultiCurrencySettings::isAvailable(),
                'pro_note'      => __('The currency switcher is a FluentCart Pro feature. It shows nothing on your site until FluentCart Pro is active.', 'fluent-cart'),
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    /**
     * The same form as the shortcode (query fields kept, unique id), inside
     * the block wrapper the editor shows, so the class the editor gave the
     * block reaches the page. No wrapper when nothing renders.
     */
    public function render(array $shortCodeAttribute, $block = null): string
    {
        $display = Arr::get($shortCodeAttribute, 'display', '');
        $shortCodeAttribute['display'] = in_array($display, ['name_code', 'name', 'code', 'symbol', 'flag'], true) ? $display : '';
        $shortCodeAttribute['flag'] = Arr::get($shortCodeAttribute, 'flag') === 'no' ? 'no' : 'yes';
        $symbol = Arr::get($shortCodeAttribute, 'symbol', '');
        $shortCodeAttribute['symbol'] = in_array($symbol, ['yes', 'no'], true) ? $symbol : '';
        $shortCodeAttribute['flag_position'] = Arr::get($shortCodeAttribute, 'flag_position') === 'right' ? 'right' : 'left';

        $listDisplay = Arr::get($shortCodeAttribute, 'list_display', '');
        $shortCodeAttribute['list_display'] = in_array($listDisplay, ['name_code', 'name', 'code', 'symbol', 'flag'], true) ? $listDisplay : '';
        $shortCodeAttribute['list_flag'] = Arr::get($shortCodeAttribute, 'list_flag') === 'no' ? 'no' : 'yes';
        $shortCodeAttribute['list_symbol'] = Arr::get($shortCodeAttribute, 'list_symbol') === 'yes' ? 'yes' : 'no';
        $shortCodeAttribute['list_flag_position'] = Arr::get($shortCodeAttribute, 'list_flag_position') === 'right' ? 'right' : 'left';

        return static::wrapUnlessEmpty(
            get_block_wrapper_attributes(),
            CurrencySwitcher::render($shortCodeAttribute)
        );
    }

    /**
     * What the editor draws in place of the frontend switcher: core's select,
     * unless a plugin that replaces the frontend markup says otherwise.
     */
    private function editorPreview(): array
    {
        $currencies = [];
        foreach (CurrencyContext::selectableCurrencies() as $code) {
            $currencies[] = [
                'code'   => $code,
                // Some signs carry a trailing &nbsp;, which plain trim() keeps
                'symbol' => preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', html_entity_decode(CurrenciesHelper::getCurrencySign($code), ENT_QUOTES, 'UTF-8')),
                'name'   => (string) CurrenciesHelper::getCurrencies($code),
            ];
        }

        /**
         * The block editor's preview of the switcher. A plugin that replaces
         * the frontend markup through fluent_cart/currency_switcher/render
         * sets 'style' => 'button' (and a 'flag' URL per currency) to match.
         *
         * @param array $preview ['style' => 'select'|'button', 'currencies' => array[] each {code, symbol, name}]
         */
        return (array) apply_filters('fluent_cart/currency_switcher/editor_preview', [
            'style'      => 'select',
            'currencies' => $currencies,
        ]);
    }
}
