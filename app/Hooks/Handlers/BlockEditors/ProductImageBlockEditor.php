<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ProductCardRender;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\Product;

class ProductImageBlockEditor extends BlockEditor
{
    protected static string $editorName = 'product-image';

    public function supports(): array
    {
        return [
            'html'                 => false,
            'align'                => true,
            '__experimentalBorder' => [
                'color'  => true,
                'radius' => true,
                'style'  => true,
                'width'  => true,
            ],
            'spacing'              => [
                'margin'  => true,
                'padding' => true,
            ],
            'shadow'               => true,
            '__experimentalFilter' => [
                'duotone' => true,
            ],
        ];
    }

    public function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/ProductImage/ProductImageBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    public function getStyles(): array
    {
        return [
            'admin/BlockEditor/ProductImage/style/product-image-block-editor.scss'
        ];
    }

    public function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'              => $this->slugPrefix,
                'name'              => static::getEditorName(),
                'title'             => __('Product Image', 'fluent-cart'),
                'description'       => __('This block will display the product image.', 'fluent-cart'),
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg')
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    protected function skipInnerBlocks(): bool
    {
        return true;
    }

    public function render(array $shortCodeAttribute, $block = null)
    {
        AssetLoader::loadSingleProductAssets();

        Vite::enqueueStyle(
            'fluent-cart-product-image-block',
            'admin/BlockEditor/ProductImage/style/product-image-block-editor.scss'
        );

        $product = null;
        $insideProductInfo = Arr::get($shortCodeAttribute, 'inside_product_info', 'no');
        if (!in_array($insideProductInfo, ['yes', 'no'], true)) {
            $insideProductInfo = 'no';
        }
        $queryType = Arr::get($shortCodeAttribute, 'query_type', 'default');
        if (!in_array($queryType, ['default', 'custom'], true)) {
            $queryType = 'default';
        }

        if ($insideProductInfo === 'yes' || $queryType === 'default') {
            $product = fluent_cart_get_current_product();
        }

        if (!$product) {
            $productId = Arr::get($shortCodeAttribute, 'product_id', false);
            if ($productId) {
                $product = Product::query()->where('post_status', 'publish')->with(['detail', 'variants'])->find($productId);
            }
        }

        if (!$product) {
            return '';
        }

        $render = new ProductCardRender($product);

        // Handle inner blocks (for overlay content like badges, titles on image)
        $innerBlocksContent = '';
        if ($block instanceof \WP_Block && !empty($block->inner_blocks)) {
            $blockContext = $block->context ?? [];
            foreach ($block->inner_blocks as $inner_block) {
                if (isset($inner_block->parsed_block)) {
                    $innerContext = array_merge($inner_block->context, $blockContext);
                    $instance = new \WP_Block($inner_block->parsed_block, $innerContext);
                    $innerBlocksContent .= $instance->render();
                }
            }
        }

        $wrapper_attributes = get_block_wrapper_attributes([
            'class' => 'fct-product-image-block',
        ]);

        ob_start();
        $render->renderProductImage();
        $renderedImage = ob_get_clean();

        if (!empty($innerBlocksContent)) {
            return sprintf(
                '<div %s>%s<div class="fct-product-image-inner-blocks">%s</div></div>',
                $wrapper_attributes,
                $renderedImage,
                $innerBlocksContent
            );
        }

        return sprintf('<div %s>%s</div>', $wrapper_attributes, $renderedImage);
    }
}
