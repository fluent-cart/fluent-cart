<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Services\Renderer\StoreLogoRenderer;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;

class StoreLogoBlockEditor extends BlockEditor
{
    protected static string $editorName = 'store-logo';

    public function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/StoreLogo/StoreLogoBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    public function getStyles(): array
    {
        return [];
    }

    public function localizeData(): array
    {
        $renderer = new StoreLogoRenderer();

        return [
            $this->getLocalizationKey()     => [
                'slug'              => $this->slugPrefix,
                'name'              => static::getEditorName(),
                'title'             => __('Store Logo', 'fluent-cart'),
                'description'       => __('Display your store logo.', 'fluent-cart'),
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg'),
                'store_logo'        => $renderer->getStoreLogo(),
                'store_name'        => $renderer->getStoreName(),
                'home_url'          => home_url('/')
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function render(array $shortCodeAttribute, $block = null)
    {
        $renderer = new StoreLogoRenderer();

        ob_start();
        $renderer->render($shortCodeAttribute);
        return ob_get_clean();
    }
}
