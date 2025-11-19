<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Hooks\Handlers\ShortCodes\ProductCardShortCode;
use FluentCart\App\Models\Product;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Helpers\Helper;

class ProductCardBlockEditor extends BlockEditor
{
    protected static string $editorName = 'product-card';

    protected function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/ProductCard/ProductCardBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    protected function getStyles(): array
    {
        return ['admin/BlockEditor/ProductCard/style/product-card-block-editor.scss'];
    }

    protected function localizeData(): array
    {
        $currencyCode = Helper::shopConfig('currency');
        $currencySign = CurrenciesHelper::getCurrencySign($currencyCode);
        $currencyPosition = Helper::shopConfig('currency_position');

        return [
            $this->getLocalizationKey()      => [
                'slug'  => $this->slugPrefix,
                'name'  => static::getEditorName(),
                'title' => __('Product Card', 'fluent-cart'),
                'description' => __('This block will display the product card.', 'fluent-cart'),
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg'),
                'currency_sign' => $currencySign,
                'currency_position' => $currencyPosition
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function render(array $shortCodeAttribute, $block = null): string
    {
        $queryType = Arr::get($shortCodeAttribute, 'query_type', 'default');

        $product = null;
        if ($queryType === 'default') {
            $product = fluent_cart_get_current_product();
            $product && setup_postdata($product->ID);
        } else {
            if ($productId = Arr::get($shortCodeAttribute, 'product_id', false)) {
                $product = Product::query()->with(['variants'])->find($productId);
                if ($product) {
                    setup_postdata($product->ID);
                }
            }
        }

        if (!$product) {
            return '';
        }

        $selectedProductId = 'product_id=' . $product->ID;

        $selectedVariantId = '';
        if ($variationId = Arr::get($shortCodeAttribute, 'variant_id', false)) {
            $selectedVariantId = 'variant_id=' . $variationId;
        }

        $priceFormat = Arr::get($shortCodeAttribute, 'price_format', 'starts_from');
        $cardWidth = Arr::get($shortCodeAttribute, 'card_width', 216);

        $shortcodeName = ProductCardShortCode::getShortCodeName();
        return "[$shortcodeName $selectedVariantId $selectedProductId price_format=$priceFormat card_width=$cardWidth]";
    }



}
