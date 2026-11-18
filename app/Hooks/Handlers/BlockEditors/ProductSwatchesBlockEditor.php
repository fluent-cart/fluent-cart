<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;

use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Models\AttributeTerm;
use FluentCart\App\Services\Renderer\AdvancedVariationRenderer;
use FluentCart\App\Services\Renderer\ProductCardSwatchesRenderer;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\Framework\Support\Arr;

/**
 * Attribute swatches (colour, image or label per variant) for the current product
 * of a product loop: Products, Product Carousel or Related Products.
 */
class ProductSwatchesBlockEditor extends BlockEditor
{
    protected static string $editorName = 'product-swatches';

    public function init(): void
    {
        parent::init();

        // WP 6.9+ dequeues what an empty block queued. This block is empty for products
        // without attributes, yet cards loaded later by AJAX still need its styles.
        add_filter('enqueue_empty_block_content_assets', function ($enqueue, $blockName) {
            return $blockName === $this->slugPrefix . '/' . static::getEditorName() ? true : $enqueue;
        }, 10, 2);
    }

    public function blockAttributes(): array
    {
        return [
            'product_id' => ['type' => 'number', 'default' => 0],
            'group_ids' => ['type' => 'array', 'default' => [], 'items' => ['type' => 'number']],
            // No default: blocks saved before it keep "no pick shows every attribute".
            'show_other_groups' => ['type' => 'boolean'],
            'sold_out_display' => ['type' => 'string', 'default' => 'normal', 'enum' => ['normal', 'cross', 'hide']],
            // Attributes the merchant unticked: kept in the list, and left out of the other attributes.
            'hidden_group_ids' => ['type' => 'array', 'default' => [], 'items' => ['type' => 'number']],
            'max_visible' => ['type' => 'number', 'default' => 3],
            'show_label' => ['type' => 'boolean', 'default' => false],
            'show_group_name' => ['type' => 'boolean', 'default' => false],
            'advanced_enabled' => ['type' => 'boolean', 'default' => true],
            'simple_enabled' => ['type' => 'boolean', 'default' => true],
            'simple_label' => ['type' => 'string', 'default' => ''],
            'simple_max_visible' => ['type' => 'number', 'default' => 4],
            'simple_show_label' => ['type' => 'boolean', 'default' => false],
            // No default: blocks saved before the split keep following show_label.
            'simple_show_selected' => ['type' => 'boolean'],
            'selected_position' => ['type' => 'string', 'default' => 'name', 'enum' => ['name', 'below']],
            'simple_selected_position' => ['type' => 'string', 'default' => 'name', 'enum' => ['name', 'below']],
        ];
    }

    public function ancestor(): array
    {
        return [
            'fluent-cart/product-card',
            'fluent-cart/products',
            'fluent-cart/product-carousel',
            'fluent-cart/related-product',
        ];
    }

    public function supports(): array
    {
        return [
            'html'    => false,
            'align'   => ['left', 'center', 'right'],
            'spacing' => [
                'margin'  => true,
                'padding' => true,
            ],
        ];
    }

    public function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/ProductSwatches/ProductSwatchesBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    public function getStyles(): array
    {
        return [
            'admin/BlockEditor/ProductSwatches/style/product-swatches-block-editor.scss'
        ];
    }

    public function localizeData(): array
    {
        return [
            $this->getLocalizationKey()     => [
                'slug'             => $this->slugPrefix,
                'name'             => static::getEditorName(),
                'title'            => __('Product Swatches', 'fluent-cart'),
                'description'      => __('Shows the product\'s options as swatches on its card, so shoppers can pick a variant from the list.', 'fluent-cart'),
                'attribute_groups' => $this->getPreviewGroups(),
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings(),
        ];
    }

    /**
     * Attribute groups with a few real values each, so the editor preview shows what the
     * chosen attributes will look like rather than a fixed sample.
     */
    private function getPreviewGroups(): array
    {
        $groups = AttributeGroup::query()
            ->orderBy('serial', 'ASC')
            ->orderBy('id', 'ASC')
            ->limit(100)
            ->get(['id', 'title', 'settings']);
        if ($groups->isEmpty()) {
            return [];
        }

        // Preview only: one bounded query, at most 12 values kept per group (the swatch maximum).
        $termsByGroup = [];
        $terms = AttributeTerm::query()
            ->search(['group_id' => ['operator' => 'in', 'value' => $groups->pluck('id')->all()]])
            ->orderBy('serial', 'ASC')
            ->orderBy('id', 'ASC')
            ->limit(5000)
            ->get(['group_id', 'title', 'settings']);
        foreach ($terms as $term) {
            $list = &$termsByGroup[(int) $term->group_id];
            if (count($list ?: []) < 12) {
                $settings = (array) $term->settings;
                $list[] = [
                    'title' => $term->title,
                    'color' => AdvancedVariationRenderer::safeCssColor(Arr::get($settings, 'color', '')),
                    'image' => esc_url_raw((string) Arr::get($settings, 'image', '')),
                ];
            }
            unset($list);
        }

        // The sidebar's "8 values" hint: a real count, since the preview keeps at most 12 per group
        // and the term query stops at 5000 rows. One grouped query over at most 100 groups.
        $termCounts = AttributeTerm::query()
            ->selectRaw('group_id, COUNT(*) AS total')
            ->whereIn('group_id', $groups->pluck('id')->all())
            ->groupBy('group_id')
            ->get()
            ->pluck('total', 'group_id')
            ->all();

        return $groups->map(function ($group) use ($termsByGroup, $termCounts) {
            $type = (string) Arr::get((array) $group->settings, 'type', '');
            return [
                'id'    => (int) $group->id,
                'title' => $group->title,
                'type'  => in_array($type, ['color', 'image'], true) ? $type : 'label',
                'terms' => $termsByGroup[(int) $group->id] ?? [],
                'term_count' => (int) ($termCounts[(int) $group->id] ?? 0),
            ];
        })->values()->all();
    }

    public function render(array $shortCodeAttribute, $block = null)
    {
        // Queue assets before any early return: cards loaded later by AJAX (pagination,
        // filters) bring no stylesheet, so a first page without swatches must still load it.
        ProductCardSwatchesRenderer::enqueueAssets();

        $productId = absint(Arr::get($shortCodeAttribute, 'product_id', 0));
        $product = $productId
            ? \FluentCart\App\Models\Product::query()->where('post_status', 'publish')->find($productId)
            : fluent_cart_get_current_product();
        if (!$product) {
            return '';
        }

        return ProductCardSwatchesRenderer::render(
            $product,
            $shortCodeAttribute,
            get_block_wrapper_attributes(['class' => 'fct-card-swatch-rows'])
        );
    }
}
