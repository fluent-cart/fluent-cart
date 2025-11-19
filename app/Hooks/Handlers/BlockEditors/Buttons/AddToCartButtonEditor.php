<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors\Buttons;

use FluentCart\App\Hooks\Handlers\BlockEditors\BlockEditor;
//use FluentCart\App\Hooks\Handlers\ShortCodes\Buttons\AddToCartShortcode;
//use FluentCart\App\Hooks\Handlers\ShortCodes\Buttons\DirectCheckoutShortcode;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\Framework\Support\Arr;

class AddToCartButtonEditor extends BlockEditor
{
    protected static string $editorName = 'add-to-cart-button';



    protected function getStyles(): array
    {
        return ['admin/BlockEditor/Buttons/AddToCart/style/style.css'];
    }


    protected function localizeData(): array
    {
        return [];
    }

    public function render(array $shortCodeAttribute, $block = null): string
    {
        return 'Hello';
    }

}
