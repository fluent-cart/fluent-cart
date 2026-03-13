<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes;

use FluentCart\App\Services\Renderer\ProductCategoriesListRenderer;
use FluentCart\App\Vite;

class ProductCategoriesListShortcode extends ShortCode
{
    protected static string $shortCodeName = 'fluent_cart_product_categories';

    public function getStyles(): array
    {
        return ['public/product-categories-list/product-categories-list.scss'];
    }

    public function getScripts(): array
    {
        return [
            [
                'source'       => 'public/product-categories-list/product-categories-list.js',
                'dependencies' => []
            ]
        ];
    }

    public function render(?array $viewData = null)
    {
        $renderer = new ProductCategoriesListRenderer();

        $data = $viewData ?? $this->shortCodeAttributes ?? [];
        $data['is_shortcode'] = true;

        $renderer->render($data);
    }
}
