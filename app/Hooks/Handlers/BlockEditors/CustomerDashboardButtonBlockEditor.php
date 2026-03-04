<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Services\Renderer\CustomerDashboardButtonRenderer;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;

class CustomerDashboardButtonBlockEditor extends BlockEditor
{
    protected static string $editorName = 'customer-dashboard-button';

    protected function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/CustomerDashboardButton/CustomerDashboardButtonBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    protected function getStyles(): array
    {
        return [
            'admin/BlockEditor/CustomerDashboardButton/style/customer-dashboard-button-block-editor.scss'
        ];
    }

    public function supports(): array
    {
        return [
            'html'       => false,
            'typography' => [
                'fontSize'   => true,
                'fontFamily' => true,
                'fontWeight' => true,
                'lineHeight' => true
            ],
            'color'      => [
                'text'       => true,
                'background' => true
            ],
            'spacing'    => [
                'margin'  => true,
                'padding' => true
            ],
            '__experimentalBorder' => [
                'color'  => true,
                'radius' => true,
                'width'  => true,
                'style'  => true,
                '__experimentalDefaultControls' => [
                    'color'  => true,
                    'radius' => true,
                    'width'  => true
                ]
            ],
            'border' => [
                'color'  => true,
                'radius' => true,
                'width'  => true,
                'style'  => true
            ],
            'shadow'     => true
        ];
    }

    protected function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'        => $this->slugPrefix,
                'name'        => static::getEditorName(),
                'title'       => __('Customer Dashboard Button', 'fluent-cart'),
                'description' => __('Display a button or link that navigates to the customer dashboard.', 'fluent-cart'),
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    public function render(array $shortCodeAttribute, $block = null)
    {
        $slug = fluentCart()->config->get('app.slug');

        Vite::enqueueStyle(
            $slug . '-customer-dashboard-button',
            'public/customer-dashboard-button/customer-dashboard-button.scss'
        );

        $renderer = new CustomerDashboardButtonRenderer();

        ob_start();
        $renderer->render($shortCodeAttribute);
        return ob_get_clean();
    }
}
