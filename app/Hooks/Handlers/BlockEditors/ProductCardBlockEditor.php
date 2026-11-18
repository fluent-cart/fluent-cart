<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Helpers\CurrenciesHelper;
use FluentCart\App\Hooks\Handlers\ShortCodes\ProductCardShortCode;
use FluentCart\App\Models\Product;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ProductCardLayoutPresets;
use FluentCart\App\Vite;

class ProductCardBlockEditor extends BlockEditor
{
    protected static string $editorName = 'product-card';

    public function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/ProductCard/ProductCardBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components', 'wp-data', 'wp-element', 'wp-block-editor', 'wp-api-fetch', 'wp-url']
            ]
        ];
    }

    public function getStyles(): array
    {
        return ['admin/BlockEditor/ProductCard/style/product-card-block-editor.scss'];
    }

    public function localizeData(): array
    {
        $currencyCode = Helper::shopConfig('currency');
        $currencySign = CurrenciesHelper::getCurrencySign($currencyCode);
        $currencyPosition = Helper::shopConfig('currency_position');

        return [
            $this->getLocalizationKey()      => [
                'slug'  => $this->slugPrefix,
                'name'  => static::getEditorName(),
                'title' => __('Product Card', 'fluent-cart'),
                'description' => __('Shows a product\'s image, price, options and buy button.', 'fluent-cart'),
                'placeholder_image' => Helper::getProductPlaceholderUrl(),
                'currency_sign' => $currencySign,
                'currency_position' => $currencyPosition,
                'card_presets' => ProductCardLayoutPresets::all()
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function blockAttributes(): array
    {
        return [
            // Missing on saved leaf cards: only new insertions opt into children.
            'template_version' => ['type' => 'number', 'default' => 0],
            'card_layout' => ['type' => 'string', 'default' => 'classic'],
            // Any layout in a staggered grid, pictures at their own height. Replaces the Masonry layout.
            'masonry' => ['type' => 'boolean', 'default' => false],
            'product_id' => ['type' => ['string', 'number'], 'default' => ''],
            'query_type' => ['type' => 'string', 'default' => 'default'],
            'price_format' => ['type' => 'string', 'default' => 'starts_from'],
            'card_width' => ['type' => ['string', 'number'], 'default' => 216],
        ];
    }

    public function useContext(): array
    {
        return ['postId', 'postType', 'fluent-cart/price_format', 'fluent-cart/product-card-loop', 'fluent-cart/related_product_ids'];
    }

    protected function skipInnerBlocks(): bool
    {
        return true;
    }

    private function renderTemplate(array $attributes, $block): string
    {
        if (!$block instanceof \WP_Block) {
            return '';
        }
        $inLoop = (bool) Arr::get($block->context, 'fluent-cart/product-card-loop', false);
        $product = $this->resolveProduct($inLoop ? ['query_type' => 'default'] : $attributes);
        if (!$product) {
            return '';
        }

        AssetLoader::loadProductCardAssets();
        Vite::enqueueStyle('fluentcart-card-layouts', 'public/product-card/style/card-layouts.scss');
        $layout = (string) Arr::get($attributes, 'card_layout', 'classic');
        $masonry = (bool) Arr::get($attributes, 'masonry', false);
        // A builder-only layout (Masonry) renders only from a builder's card, which cardBlock() marks with masonry;
        // a Gutenberg card saved with it, from before the switch, is Classic like an unknown layout.
        $builderOnly = !empty(ProductCardLayoutPresets::all()[$layout]['builder_only']);
        if (!ProductCardLayoutPresets::cardBlock($layout) || ($builderOnly && !$masonry)) {
            $layout = 'classic';
        }
        $context = array_merge($block->context, [
            'postId' => (int) $product->ID,
            'postType' => 'fluent-products',
        ]);
        if (!$inLoop) {
            $context['fluent-cart/price_format'] = Arr::get($attributes, 'price_format', 'starts_from');
        }

        // Restore the surrounding loop as well as WordPress's post globals.
        $keys = ['post', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages', 'fct_product'];
        $savedGlobals = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $GLOBALS)) {
                $savedGlobals[$key] = $GLOBALS[$key];
            }
        }
        $html = '';
        try {
            $GLOBALS['post'] = get_post($product->ID);
            setup_postdata($GLOBALS['post']);
            foreach ($block->inner_blocks as $child) {
                $instance = new \WP_Block($child->parsed_block, array_merge($child->context, $context));
                $html .= $instance->render();
            }
        } finally {
            foreach ($keys as $key) {
                if (array_key_exists($key, $savedGlobals)) {
                    $GLOBALS[$key] = $savedGlobals[$key];
                } else {
                    unset($GLOBALS[$key]);
                }
            }
        }

        $className = $layout === 'classic' ? 'fct-product-card-template' : 'fct-card-layout fct-card-layout--' . $layout;
        if ($masonry) {
            $className .= ' fct-card-masonry';
        }
        $html = '<div ' . get_block_wrapper_attributes(['class' => $className]) . '>' . $html . '</div>';
        if ($inLoop) {
            return $html;
        }
        $width = Arr::get($attributes, 'card_width', '100%');
        $width = $width === '100%' ? $width : max(216, min(1000, (int) $width)) . 'px';
        return '<div class="fct-product-card" data-fct-product-card style="width:' . esc_attr($width) . ';max-width:100%">' . $html . '</div>';
    }

    public function render(array $shortCodeAttribute, $block = null): string
    {
        if (Arr::get($shortCodeAttribute, 'template_version', 0) === 1) {
            return $this->renderTemplate($shortCodeAttribute, $block);
        }

        $queryType = Arr::get($shortCodeAttribute, 'query_type', 'default');

        $product = null;
        if ($queryType === 'default') {
            $product = fluent_cart_get_current_product();
            // TODO: Check
            //$product && setup_postdata($product->ID);
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

        return ProductCardShortCode::make([
            'product_id'   => $product->ID,
            'variant_id'   => Arr::get($shortCodeAttribute, 'variant_id', ''),
            'price_format' => Arr::get($shortCodeAttribute, 'price_format', 'starts_from'),
            'card_width'   => Arr::get($shortCodeAttribute, 'card_width', 216),
        ])->renderShortcode($block);
    }



}
