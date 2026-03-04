<?php

namespace FluentCart\App\Hooks\Handlers\ShortCodes;

use FluentCart\App\Services\Renderer\StoreLogoRenderer;

class StoreLogoShortCode extends ShortCode
{
    protected static string $shortCodeName = 'fluent_cart_store_logo';

    public function render(?array $viewData = null)
    {
        $data = $viewData ?? $this->shortCodeAttributes ?? [];

        $data['is_shortcode'] = true;

        $renderer = new StoreLogoRenderer();
        $renderer->render($data);
    }
}