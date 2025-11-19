<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Hooks\Handlers\ShortCodes\CustomerProfileHandler;
use FluentCart\Framework\Support\Arr;



class CustomerProfileBlockEditor extends BlockEditor
{
    protected static string $editorName = 'customer-profile';

    protected function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/CustomerProfile/CustomerProfileBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    protected function getStyles(): array
    {
        return ['admin/BlockEditor/CustomerProfile/style/customer-profile-block-editor.scss'];
    }

    protected function localizeData(): array
    {
        return [
            $this->getLocalizationKey() => [
                'slug'  => $this->slugPrefix,
                'name'  => static::getEditorName(),
                'title' => __('Customer Dashboard', 'fluent-cart'),
            ]
        ];
    }

    public function render(array $shortCodeAttribute, $block = null): string
    {
        $defaultsTitles = [
            'purchaseHistory' => __('Purchase History', 'fluent-cart'),
            'subscriptions'   => __('Subscriptions', 'fluent-cart'),
            'licenses'        => __('Licenses', 'fluent-cart'),
            'downloads'       => __('Downloads', 'fluent-cart')
        ];

        $sectionTitles = Arr::get($shortCodeAttribute, 'sectionTitles', $defaultsTitles);
        $jsonTitles = wp_json_encode($sectionTitles);
        $colors = Arr::get($shortCodeAttribute, 'colors', []);



        $colorStrings = [];
        // Iterate over each color group (e.g., generalColors)
        foreach ($colors as $colorGroup) {
            // Iterate over each color within the color group
            foreach ($colorGroup as $key => $color) {
                $value = $color['value'];

                if (is_array($value)) {
                    // Convert box values (like padding/margin) to CSS shorthand string
                    $top = $value['top'] ?? '0';
                    $right = $value['right'] ?? '0';
                    $bottom = $value['bottom'] ?? '0';
                    $left = $value['left'] ?? '0';
                    $value = trim("$top $right $bottom $left");
                }
                // Append each color key and its value to the $colorStrings array
                $colorStrings[] = $key . '=' . $value;
            }
        }

        $allColorStrings = implode(', ', $colorStrings);

        return "[" . CustomerProfileHandler::SHORT_CODE .
            " section_titles='{$jsonTitles}'" .
            " colors='{$allColorStrings}'" .
            " ]";
    }
}
