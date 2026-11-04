<?php

namespace Tests\Integration\Renderer;

use FluentCart\Api\Taxonomy;
use FluentCart\App\Services\Renderer\ProductFilterRender;
use lucatume\WPBrowser\TestCase\WPTestCase;

/**
 * Regression: the shop sidebar dropped every category deeper than one level.
 *
 * Taxonomy::getFormattedTerms() nests terms to whatever depth the taxonomy has,
 * but ProductFilterRender::renderOptions() walked exactly two levels — the root
 * option and its `children`. A store with
 *
 *     Accessories > Child > Nati > Inner Nati
 *
 * rendered Accessories and Child only; Nati and Inner Nati were never printed,
 * so their products were unreachable from the filter.
 *
 * The two-level render also mis-scoped the parent checkbox: `Child` carried
 * `data-parent-checkbox` (it has children) but sat inside the *root's* child
 * group, so Filter.js `closest()` walked up to Accessories and ticked every
 * category in the sidebar. Wrapping each node that has children in its own
 * group is what scopes that lookup correctly.
 */
class ProductFilterRenderTreeTest extends WPTestCase
{
    private function renderNode(array $option): string
    {
        $renderer = (new \ReflectionClass(ProductFilterRender::class))->newInstanceWithoutConstructor();

        ob_start();
        $renderer->renderOptionNode($option, 'product-categories');

        return (string) ob_get_clean();
    }

    private function tree(): array
    {
        return [
            'value'    => '160',
            'label'    => 'Accessories',
            'children' => [
                [
                    'value'    => '166',
                    'label'    => 'Child',
                    'children' => [
                        [
                            'value'    => '170',
                            'label'    => 'Nati',
                            'children' => [
                                ['value' => '171', 'label' => 'Inner Nati', 'children' => []],
                            ],
                        ],
                        ['value' => '172', 'label' => 'Nati 2', 'children' => []],
                    ],
                ],
                ['value' => '168', 'label' => 'Child 2', 'children' => []],
            ],
        ];
    }

    public function test_renders_every_level_of_the_category_tree(): void
    {
        $html = $this->renderNode($this->tree());

        foreach (['160', '166', '170', '171', '172', '168'] as $termId) {
            $this->assertStringContainsString(
                'value="' . $termId . '"',
                $html,
                'Term ' . $termId . ' is missing from the rendered filter'
            );
        }

        $this->assertStringContainsString('>Inner Nati<', $html);
    }

    public function test_every_node_with_children_gets_its_own_child_group(): void
    {
        $html = $this->renderNode($this->tree());

        // Accessories, Child and Nati each have children; Inner Nati, Nati 2
        // and Child 2 are leaves.
        $this->assertSame(3, substr_count($html, 'fct-shop-checkbox-child-group'));
        $this->assertSame(3, substr_count($html, 'data-parent-checkbox'));
    }

    public function test_leaf_terms_are_not_marked_as_parents(): void
    {
        $html = $this->renderNode(['value' => '171', 'label' => 'Inner Nati', 'children' => []]);

        $this->assertStringNotContainsString('data-parent-checkbox', $html);
        $this->assertStringNotContainsString('fct-shop-checkbox-child-group', $html);
    }

    public function test_grandchild_terms_survive_the_formatter_and_the_renderer(): void
    {
        $taxonomy = 'product-categories';

        $parent = static::factory()->term->create(['taxonomy' => $taxonomy, 'name' => 'Accessories ' . uniqid()]);
        $child = static::factory()->term->create(['taxonomy' => $taxonomy, 'name' => 'Child ' . uniqid(), 'parent' => $parent]);
        $grandChild = static::factory()->term->create(['taxonomy' => $taxonomy, 'name' => 'Nati ' . uniqid(), 'parent' => $child]);

        $terms = Taxonomy::getFormattedTerms($taxonomy, false, [$parent]);

        $html = '';
        foreach ($terms as $option) {
            $html .= $this->renderNode($option);
        }

        $this->assertStringContainsString('value="' . $grandChild . '"', $html);
    }
}
