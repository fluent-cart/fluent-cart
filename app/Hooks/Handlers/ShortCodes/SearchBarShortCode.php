<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes;

use FluentCart\App\CPT\FluentProducts;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;
use FluentCart\App\Services\Renderer\SearchBarRenderer;

class SearchBarShortCode extends ShortCode
{
    protected static string $shortCodeName = 'fluent_products_search_bar';

    protected function getScripts(): array
    {
        return [
            [
                'source'       => 'public/search-bar-app/SearchBarApp.js',
                'dependencies' => ['jquery']
            ]
        ];
    }

    protected function getStyles(): array
    {
        return [
            'public/search-bar-app/style/style.scss'
        ];
    }

    protected function localizeData(): array
    {
        return [
            'fluentcart_search_bar_vars' => [
                'rest' => Helper::getRestInfo(),
            ]
        ];
    }

    public function viewData(): ?array
    {
        return [
            'url_mode'           => Arr::get($this->shortCodeAttributes, 'url_mode'),
            'category_mode'      => Arr::get($this->shortCodeAttributes, 'category_mode'),
            'termData'           => $this->getTermsData('categories'),
            'link_with_shop_app' => Arr::get($this->shortCodeAttributes, 'link_with_shop_app'),
        ];
    }

    public function render(?array $viewData = null)
    {
        $config = $viewData;
        ob_start();
        (new SearchBarRenderer($config))->render();
        return ob_get_clean();
    }

    private function getTermsData($key): array
    {
        if (!$key) {
            return [];
        }
        $options = get_categories([
            'taxonomy'  => 'product-' . $key,
            'post_type' => FluentProducts::CPT_NAME
        ]);

        return Collection::make($options)->map(function ($meta) {
            return [
                'termId'   => $meta->term_id,
                'termName' => $meta->name,
            ];
        })->toArray();
    }
}
