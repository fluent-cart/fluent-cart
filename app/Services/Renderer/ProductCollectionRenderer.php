<?php

namespace FluentCart\App\Services\Renderer;

class ProductCollectionRenderer
{
    protected $products = [];

    public function __construct($products, $config = [])
    {
        $this->products = $products;

        $defaults = [
            'show_filters' => false,
            'view_changer' => false,
            ''
        ];

    }

    public function render()
    {

    }

}
