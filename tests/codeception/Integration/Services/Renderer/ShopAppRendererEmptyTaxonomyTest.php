<?php

namespace Tests\Integration\Services\Renderer;

use FluentCart\App\Services\Renderer\ProductFilterRender;
use FluentCart\App\Services\Renderer\ShopAppRenderer;
use lucatume\WPBrowser\TestCase\WPTestCase;

class ShopAppRendererEmptyTaxonomyTest extends WPTestCase
{
    private function renderCategoryFilter($showEmpty, bool $includeSetting = true): string
    {
        $config = [
            'custom_filters' => [
                'enabled'    => true,
                'taxonomies' => ['product-categories'],
            ],
        ];

        if ($includeSetting) {
            // Elementor passes this setting through the top-level filters config.
            $config['filters'] = [
                'product-categories' => ['show_empty' => $showEmpty],
            ];
        }

        $shop = new ShopAppRenderer([], $config);
        $filters = new \ReflectionProperty(ShopAppRenderer::class, 'filters');
        $filters->setAccessible(true);

        $renderer = new ProductFilterRender($filters->getValue($shop));
        ob_start();
        $renderer->renderOptions();

        return (string) ob_get_clean();
    }

    public function test_show_empty_controls_whether_an_unassigned_category_appears(): void
    {
        $name = 'Unassigned category ' . uniqid();
        $term = wp_insert_term($name, 'product-categories');
        $this->assertIsArray($term);

        $hidden = $this->renderCategoryFilter(false);
        $hiddenElementor = $this->renderCategoryFilter('');
        $shown = $this->renderCategoryFilter(true);
        $default = $this->renderCategoryFilter(null, false);

        $this->assertStringNotContainsString($name, $hidden);
        $this->assertStringNotContainsString($name, $hiddenElementor);
        $this->assertStringContainsString($name, $shown);
        $this->assertStringContainsString($name, $default);
    }
}
