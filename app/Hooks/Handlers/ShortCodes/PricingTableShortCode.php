<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes;

use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;
use FluentCart\App\Services\Renderer\PricingTableRenderer;

class PricingTableShortCode extends ShortCode
{
    const SHORT_CODE = 'fluent_cart_pricing_table';
    protected static string $shortCodeName = 'fluent_cart_pricing_table';

    public static function register()
    {
        parent::register();
        add_action('wp_enqueue_scripts', function () {
            if (App::request()->get('action') === 'elementor') {
                return;
            }
            if (has_shortcode(get_the_content(), static::SHORT_CODE) || has_block('fluent-cart/product-pricing-table')) {
                (new static())->enqueueStyles();
            }
        },10);
    }


    protected function getScripts(): array
    {
        return [
            [
                'source'       => 'public/pricing-table/PricingTable.js',
                'dependencies' => [],
                'inFooter'     => true
            ]
        ];
    }

    protected function getStyles(): array
    {
        return [
            'public/pricing-table/pricing-table.scss'
        ];
    }

    public function viewData(): ?array
    {
        $shortcodeAttributes = $this->shortCodeAttributes;


        $variantIds = Arr::get($shortcodeAttributes, 'variant_ids', '');
        $variantIds = explode(',', $variantIds);
        $variants = ProductVariation::query()
            ->whereIn('id', $variantIds)
            ->with('product.licensesMeta')
            ->get()
            ->map(function (ProductVariation $productVariation) {
                $productVariation = $productVariation->toArray();
                $productVariation['price'] = $productVariation['formatted_total'];
                return $productVariation;
            });

        $activeVariants = [];
        $getActiveVariants = explode(',', Arr::get($shortcodeAttributes, 'active_variant'));

        foreach ($getActiveVariants as $item) {
            if (empty($item)) {
                continue;
            }
            // Check if the item contains an '=' sign using Str::contains()
            if (Str::contains($item, '=')) {
                list($key, $value) = explode('=', $item);
                $activeVariants[trim($key)] = trim($value);
            }
        }

        return [
            'variants'             => $variants->toArray(),
            'show_checkout_button' => Arr::get($shortcodeAttributes, 'show_checkout_button', 1),
            'show_cart_button'     => Arr::get($shortcodeAttributes, 'show_cart_button', 1),
            'group_by'             => Arr::get($shortcodeAttributes, 'group_by', 'repeat_interval'),
            'active_tab'           => Arr::get($shortcodeAttributes, 'active_tab', 0),
            'active_variant'       => $activeVariants,
            'badge'                => Arr::get($shortcodeAttributes, 'badge', ''),
            'colors'               => Arr::get($shortcodeAttributes, 'colors', ''),
            'product_per_row'      => Arr::get($shortcodeAttributes, 'product_per_row', ''),
            'button_options'       => Arr::get($shortcodeAttributes, 'button_options', ''),
            'url_params'           => Arr::get($shortcodeAttributes, 'url_params', ''),
            'icon_visibility'      => !empty(Arr::get($shortcodeAttributes, 'icon_visibility', 1)),
        ];

    }


    public function render(?array $viewData = null)
    {
        ob_start();
        (new PricingTableRenderer($viewData))->render();
        return ob_get_clean();
    }

    protected function localizeData(): array
    {
        return [
            'fluentcart_pricing_table_vars' => [
                'trans'                    => TransStrings::singleProductPageString(),
                'cart_button_text'         => (new StoreSettings())->get('cart_button_text', __('Add to Cart', 'fluent-cart')),
                'out_of_stock_button_text' => (new StoreSettings())->get('out_of_stock_button_text', __('Out of Stock', 'fluent-cart')),
                'in_stock_status'          => Helper::IN_STOCK,
                'out_of_stock_status'      => Helper::OUT_OF_STOCK,
            ]
        ];
    }
}
