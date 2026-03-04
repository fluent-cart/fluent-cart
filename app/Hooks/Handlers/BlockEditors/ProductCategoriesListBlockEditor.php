<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Services\Renderer\ProductCategoriesListRenderer;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;

class ProductCategoriesListBlockEditor extends BlockEditor
{
    protected static string $editorName = 'product-categories-list';

    protected function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/ProductCategoriesList/ProductCategoriesListBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    protected function getStyles(): array
    {
        return [
            'admin/BlockEditor/ProductCategoriesList/style/product-categories-list-block-editor.scss'
        ];
    }

    public function supports(): array
    {
        return [
            'html'       => false,
            'typography' => [
                'fontSize'   => true,
                'lineHeight' => true
            ],
            'color'      => [
                'text'       => true,
                'link'       => true,
                'background' => false,
            ],
            'shadow'     => true
        ];
    }

    protected function localizeData(): array
    {
        $categories = ProductCategoriesListRenderer::getCategories();

        return [
            $this->getLocalizationKey()     => [
                'slug'        => $this->slugPrefix,
                'name'        => static::getEditorName(),
                'title'       => __('Product Categories List', 'fluent-cart'),
                'description' => __('Display a list of product categories.', 'fluent-cart'),
                'categories'  => $categories,
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function render(array $shortCodeAttribute, $block = null)
    {
        $slug = fluentCart()->config->get('app.slug');

        Vite::enqueueStyle(
            $slug . '-product-categories-list',
            'public/product-categories-list/product-categories-list.scss'
        );

        Vite::enqueueScript(
            $slug . '-product-categories-list-js',
            'public/product-categories-list/product-categories-list.js'
        );

        $renderer = new ProductCategoriesListRenderer();

        ob_start();
        $renderer->render($shortCodeAttribute);
        return ob_get_clean();
    }
}
