<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Modules\Templating\AssetLoader;
use FluentCart\App\Services\Renderer\ProductCardRender;
use FluentCart\App\Services\Renderer\ProductRenderer;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\Product;

class ExcerptBlockEditor extends BlockEditor
{
    protected static string $editorName = 'excerpt';

    public function supports(): array
    {
        return [
            'html'                 => false,
            'align'                => true,
            'typography'           => [
                'fontSize'                      => true,
                'lineHeight'                    => true,
                '__experimentalFontFamily'      => true,
                '__experimentalFontWeight'      => true,
                '__experimentalFontStyle'       => true,
                '__experimentalTextTransform'   => true,
                '__experimentalTextDecoration'  => true,
                '__experimentalLetterSpacing'   => true,
                '__experimentalDefaultControls' => [
                    'fontSize'   => true,
                    'lineHeight' => true,
                    'fontWeight' => true,
                ],
            ],
            'color'                => [
                'text'       => true,
                'background' => true,
                'link'       => true,
                'gradients'   => true,
            ],
            'spacing'              => [
                'margin'  => true,
                'padding' => true,
            ],
            '__experimentalBorder' => [
                'color'  => true,
                'radius' => true,
                'style'  => true,
                'width'  => true,
            ],
            'shadow'               => true,
        ];
    }

    protected function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/Excerpt/ExcerptBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    protected function getStyles(): array
    {
        return [
            'admin/BlockEditor/Excerpt/style/excerpt-block-editor.scss'
        ];
    }

    protected function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'              => $this->slugPrefix,
                'name'              => static::getEditorName(),
                'title'             => __('Excerpt', 'fluent-cart'),
                'description'       => __('This block will display the excerpt.', 'fluent-cart'),
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg')
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function render(array $shortCodeAttribute, $block = null)
    {
        AssetLoader::loadSingleProductAssets();
        $product = null;
        $insideProductInfo = Arr::get($shortCodeAttribute, 'inside_product_info', 'no');
        $queryType = Arr::get($shortCodeAttribute, 'query_type', 'default');

        if ($insideProductInfo === 'yes' || $queryType === 'default') {
            $product = fluent_cart_get_current_product();
        } else {
            $productId = Arr::get($shortCodeAttribute, 'product_id', false);
            if ($productId) {
                $product = Product::query()->with(['detail', 'variants'])->find($productId);
            }
        }

        if (!$product || empty($product->post_excerpt)) {
            return '';
        }

        $wrapper_attributes = get_block_wrapper_attributes([
            'class' => 'fct-product-card-excerpt',
        ]);

        ob_start();
        (new ProductCardRender($product))->renderExcerpt($wrapper_attributes);
        return ob_get_clean();
    }
}
