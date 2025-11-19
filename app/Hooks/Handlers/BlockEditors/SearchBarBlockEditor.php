<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Hooks\Handlers\ShortCodes\SearchBarShortCode;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class SearchBarBlockEditor extends BlockEditor
{
    protected static string $editorName = 'fluent-products-search-bar';

    protected function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/SearchBar/SearchBarBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    protected function getStyles(): array
    {
        return ['admin/BlockEditor/SearchBar/style/searchbar-block-editor.css'];
    }

    protected function localizeData(): array
    {
        return [
            $this->getLocalizationKey()      => [
                'slug'  => $this->slugPrefix,
                'name'  => static::getEditorName(),
                'title' => __('Product Search', 'fluent-cart'),
            ],
            'fluent_cart_block_editor_asset' => [
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg'),
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings()
        ];
    }

    public function render(array $shortCodeAttribute, $block = null): string
    {
        $urlMode = Arr::get($shortCodeAttribute, 'url_mode');
        $categoryMode = Arr::get($shortCodeAttribute, 'category_mode');
        $linkWithShopApp = Arr::get($shortCodeAttribute, 'link_with_shop_app');

        $url_mode = !empty($urlMode) ? 'url_mode=' . $urlMode : 'url_mode=new-tab';
        $category_mode = !empty($categoryMode) ? 'category_mode=' . $categoryMode : '';
        $link_with_shop_app = !empty($linkWithShopApp) ? 'link_with_shop_app=' . $linkWithShopApp : '';

        $shortcodeName = SearchBarShortCode::getShortCodeName();
        return "[$shortcodeName {$url_mode} {$category_mode} {$link_with_shop_app}]";
    }

}
