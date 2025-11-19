<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\App;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Hooks\Handlers\ShortCodes\PricingTableShortCode;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class PricingTableBlockEditor extends BlockEditor
{

    protected static string $editorName = 'product-pricing-table';

    protected function getScripts(): array
    {
        if (!App::isDevMode()) {
            return [];
        }
        return [
            [
                'source'       => 'admin/BlockEditor/PricingTable/PricingTableBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    protected function getStyles(): array
    {
        return ['admin/BlockEditor/PricingTable/style/pricing-table-block-editor.scss'];
    }

    protected function localizeData(): array
    {
        return [
            $this->getLocalizationKey()      => [
                'slug' => $this->slugPrefix,
                'name' => static::getEditorName(),

                'trans' => TransStrings::getShopAppBlockEditorString(),
                'title' => __('Pricing Table', 'fluent-cart'),
            ],
            'fluent_cart_block_editor_asset' => [
                'placeholder_image' => Vite::getAssetUrl('images/placeholder.svg'),
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings()
        ];
    }


    public function render(array $shortCodeAttribute, $block = null): string
    {

        $groupBy = Arr::get($shortCodeAttribute, 'group_by', 'repeat_interval');
        $showCheckoutButton = Arr::get($shortCodeAttribute, 'show_checkout_button', true) !== false;
        $showCartButton = Arr::get($shortCodeAttribute, 'show_cart_button', true) !== false;
        $iconVisibility = Arr::get($shortCodeAttribute, 'iconVisibility', true) !== false;
        $productPerRow = Arr::get($shortCodeAttribute, 'product_per_row', 0);

        $buttonOptions = Arr::get($shortCodeAttribute, 'buttonOptions', []);
        $checkoutButtonUrlParams = Arr::get($shortCodeAttribute, 'checkout_button_url_params', '');

        $variants = Arr::get($shortCodeAttribute, 'variations', []);
        $variants = is_array($variants) ? $variants : [];
        $variantIds = implode(',', $variants);

        $activeVariant = Arr::get($shortCodeAttribute, 'active_variant', []);
        $activeVariant = is_array($activeVariant) ? $activeVariant : (is_object($activeVariant) ? (array)$activeVariant : []);

        $badge = Arr::get($shortCodeAttribute, 'badge', []);

        $colors = Arr::get($shortCodeAttribute, 'colors', []);

        // Initialize an empty array to store the formatted color strings
        $colorStrings = [];

        // Iterate over each color group (e.g., badgeColors, cardColors)
        foreach ($colors as $colorGroup) {
            // Iterate over each color within the color group
            foreach ($colorGroup as $key => $color) {
                // Append each color key and its value to the $colorStrings array
                $colorStrings[] = $key . '=' . $color['value'];
            }
        }

        // Implode the array into a comma-separated string
        $allColorStrings = implode(', ', $colorStrings);

        $badgeString = $this->assocArrayToString($badge);
        $activeVariantString = $this->assocArrayToString($activeVariant);
        $buttonOptionStrings = $this->assocArrayToString($buttonOptions);


        $activeTab = Arr::get($shortCodeAttribute, 'active_tab', 0);

        $code = "[" . PricingTableShortCode::SHORT_CODE .
            " variant_ids='{$variantIds}'" .
            " show_cart_button='{$showCartButton}'" .
            " show_checkout_button='{$showCheckoutButton}'" .
            " group_by='{$groupBy}'" .
            " active_tab='{$activeTab}'" .
            " active_variant='{$activeVariantString}'" .
            " badge='{$badgeString}'" .
            " colors='{$allColorStrings}'" .
            " product_per_row='{$productPerRow}'" .
            " button_options='{$buttonOptionStrings}'" .
            " url_params='$checkoutButtonUrlParams'".
            " icon_visibility='{$iconVisibility}'" .
            " ]";

        return $code;
    }

    private function assocArrayToString(array $array, string $delimiter = ', '): string
    {
        return implode($delimiter, array_map(
            function ($key, $value) {
                return $key . '=' . $value;
            },
            array_keys($array),
            $array
        ));
    }

}
