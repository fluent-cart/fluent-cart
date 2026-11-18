<?php

namespace Tests\Integration\Blocks;

use FluentCart\Api\Resource\ProductResource;
use FluentCart\Api\Resource\ShopResource;
use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Models\AttributeRelation;
use FluentCart\App\Models\AttributeTerm;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Modules\Data\ProductDataSetup;
use FluentCart\App\Services\Filter\ShopAttributeFilter;
use FluentCart\App\Services\Renderer\ProductCardSwatchesRenderer;
use FluentCart\App\Services\Renderer\ProductFilterRender;
use FluentCart\App\Services\Renderer\ShopAppRenderer;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\ReadsCardSwatches;

/**
 * Shop sidebar filters on variation attributes, and the attribute swatches on product cards.
 */
class ShopAttributeFiltersTest extends WPTestCase
{
    use ReadsCardSwatches;

    private $sizeGroup;
    private $colourGroup;
    private $terms = [];

    /** Sold in M Black and L Camel: no single M Camel variant. */
    private $splitProduct;

    /** Sold in M Camel. */
    private $camelProduct;

    public function setUp(): void
    {
        parent::setUp();

        $this->sizeGroup = AttributeGroup::query()->create(['title' => 'Test Size', 'slug' => 'test-size-' . wp_generate_password(6, false)]);
        $this->colourGroup = AttributeGroup::query()->create(['title' => 'Test Colour', 'slug' => 'test-colour-' . wp_generate_password(6, false), 'settings' => ['type' => 'color']]);

        $this->terms['M'] = $this->term($this->sizeGroup, 'M', 1);
        $this->terms['L'] = $this->term($this->sizeGroup, 'L', 2);
        $this->terms['Black'] = $this->term($this->colourGroup, 'Black', 1, ['color' => '#111111']);
        $this->terms['Camel'] = $this->term($this->colourGroup, 'Camel', 2, ['color' => '#a47551']);
        $this->terms['Rust'] = $this->term($this->colourGroup, 'Rust', 3, ['color' => '#8b3a1f']);

        $this->splitProduct = $this->productWithVariants('Split Coat', [['M', 'Black'], ['L', 'Camel']]);
        $this->camelProduct = $this->productWithVariants('Camel Coat', [['M', 'Camel']]);
    }

    public function test_same_variant_must_match_every_selected_group(): void
    {
        $ids = $this->filteredIds([
            ShopAttributeFilter::filterKey($this->sizeGroup->id)   => [$this->terms['M']->id],
            ShopAttributeFilter::filterKey($this->colourGroup->id) => [$this->terms['Camel']->id],
        ]);

        $this->assertContains($this->camelProduct->ID, $ids);
        $this->assertNotContains($this->splitProduct->ID, $ids, 'M and Camel exist only on different variants');
    }

    public function test_terms_within_a_group_are_alternatives(): void
    {
        $ids = $this->filteredIds([
            ShopAttributeFilter::filterKey($this->colourGroup->id) => [$this->terms['Black']->id, $this->terms['Rust']->id],
        ]);

        $this->assertContains($this->splitProduct->ID, $ids);
        $this->assertNotContains($this->camelProduct->ID, $ids);
    }

    public function test_a_single_checked_box_arrives_as_a_string(): void
    {
        $ids = $this->filteredIds([
            ShopAttributeFilter::filterKey($this->colourGroup->id) => (string) $this->terms['Camel']->id,
        ]);

        $this->assertContains($this->camelProduct->ID, $ids);
        $this->assertContains($this->splitProduct->ID, $ids);
    }

    public function test_inactive_variants_do_not_match(): void
    {
        $this->camelProduct->variants->first()->update(['item_status' => 'inactive']);

        $ids = $this->filteredIds([
            ShopAttributeFilter::filterKey($this->colourGroup->id) => [$this->terms['Camel']->id],
        ]);

        $this->assertNotContains($this->camelProduct->ID, $ids);
    }

    public function test_selection_parsing_is_capped_and_cast(): void
    {
        $filters = ['wildcard' => 'coat', 'attr_x' => [1], 'attr_0' => [1]];
        for ($group = 1; $group <= 15; $group++) {
            $filters['attr_' . $group] = array_merge(range(1, 80), ['abc', '-3']);
        }

        $selection = ShopAttributeFilter::parseSelection($filters);

        $this->assertCount(ShopAttributeFilter::MAX_GROUPS, $selection);
        foreach ($selection as $termIds) {
            $this->assertLessThanOrEqual(ShopAttributeFilter::MAX_TERMS_PER_GROUP, count($termIds));
            $this->assertSame(array_values(array_filter($termIds, 'is_int')), $termIds);
        }
    }

    public function test_selection_parsing_rejects_hostile_shapes(): void
    {
        $this->assertSame([], ShopAttributeFilter::parseSelection(['attr_5' => [[99]]]), 'nested arrays are not term 1');
        $this->assertSame([], ShopAttributeFilter::parseSelection(['attr_6' => ['-1', '0']]), 'no zero or negative ids');
        $this->assertSame([], ShopAttributeFilter::parseSelection(['attr_5' => ['enabled' => true, 'group_id' => 5]]), 'a filter config is not a selection');
        $this->assertSame([5 => [3]], ShopAttributeFilter::parseSelection(['attr_5' => [3], 'attr_05' => [4], 'attr_5x' => [7]]), 'only plain digit keys');
    }

    public function test_counts_cover_published_products_and_follow_a_change(): void
    {
        $counts = ShopAttributeFilter::termCounts([$this->colourGroup->id]);
        $this->assertSame(2, $counts[$this->terms['Camel']->id]);
        $this->assertSame(1, $counts[$this->terms['Black']->id]);
        $this->assertArrayNotHasKey($this->terms['Rust']->id, $counts);

        wp_update_post(['ID' => $this->camelProduct->ID, 'post_status' => 'draft']);

        $counts = ShopAttributeFilter::termCounts([$this->colourGroup->id]);
        $this->assertSame(1, $counts[$this->terms['Camel']->id], 'counts follow a status change');
    }

    public function test_counts_drop_a_product_deleted_from_the_admin(): void
    {
        $this->assertSame(1, ShopAttributeFilter::termCounts([$this->colourGroup->id])[$this->terms['Black']->id]);

        // The admin delete removes the post through the ORM, so no WordPress delete hook runs.
        ProductResource::delete($this->splitProduct->ID);

        $this->assertArrayNotHasKey($this->terms['Black']->id, ShopAttributeFilter::termCounts([$this->colourGroup->id]));
    }

    public function test_sidebar_renders_colour_swatches_and_size_boxes(): void
    {
        // The shape the block editor saves: one section entry plus one entry per chosen group.
        $renderer = new ProductFilterRender([
            'attribute_filters'              => ['filter_type' => 'attribute_settings', 'active' => true, 'show_count' => false],
            'attr_' . $this->colourGroup->id => ['enabled' => true, 'filter_type' => 'attribute', 'is_meta' => false, 'group_id' => $this->colourGroup->id, 'show_count' => false],
            'attr_' . $this->sizeGroup->id   => ['enabled' => true, 'filter_type' => 'attribute', 'is_meta' => false, 'group_id' => $this->sizeGroup->id, 'show_count' => false],
        ]);

        ob_start();
        $renderer->renderOptions();
        $html = ob_get_clean();

        $this->assertStringContainsString('fct-shop-attribute-options is-color', $html);
        $this->assertStringContainsString('fct-shop-attribute-options is-options', $html);
        $this->assertStringContainsString('name="attr_' . $this->colourGroup->id . '"', $html);
        $this->assertStringContainsString('background-color: #a47551', $html);
        $this->assertStringNotContainsString('>Rust<', $html, 'terms no product uses are hidden');
        $this->assertStringContainsString('Test Colour', $html, 'the heading is the attribute name');
        $this->assertStringNotContainsString('fct-shop-attribute-count', $html, 'show counts off hides every count');
        $this->assertStringNotContainsString('attribute_filters', $html, 'the section entry is not a filter');
    }

    public function test_counts_and_empty_values_are_off_by_default(): void
    {
        $renderer = new ProductFilterRender([
            'attr_' . $this->colourGroup->id => ['enabled' => true, 'filter_type' => 'attribute', 'group_id' => $this->colourGroup->id],
        ]);

        ob_start();
        $renderer->renderOptions();
        $html = ob_get_clean();

        $this->assertStringNotContainsString('fct-shop-attribute-count', $html);
        $this->assertStringNotContainsString('>Rust<', $html);
    }

    public function test_show_empty_lists_values_no_product_uses(): void
    {
        $renderer = new ProductFilterRender([
            'attr_' . $this->colourGroup->id => ['enabled' => true, 'filter_type' => 'attribute', 'group_id' => $this->colourGroup->id, 'show_empty' => true],
        ]);

        ob_start();
        $renderer->renderOptions();
        $html = ob_get_clean();

        $this->assertStringContainsString('>Rust<', $html);
    }

    public function test_size_boxes_show_counts_when_enabled(): void
    {
        $renderer = new ProductFilterRender([
            'attr_' . $this->sizeGroup->id => ['enabled' => true, 'filter_type' => 'attribute', 'is_meta' => false, 'group_id' => $this->sizeGroup->id, 'show_count' => true],
        ]);

        ob_start();
        $renderer->renderOptions();
        $html = ob_get_clean();

        $this->assertStringContainsString('fct-shop-attribute-options is-options', $html);
        $this->assertStringContainsString('fct-shop-attribute-count', $html);
    }

    public function test_custom_filters_name_attributes_by_slug_or_id(): void
    {
        $config = ShopAttributeFilter::filterConfig(
            $this->colourGroup->slug . ', ' . $this->sizeGroup->id . ', no-such-attribute',
            false
        );

        $this->assertSame(
            [ShopAttributeFilter::filterKey($this->colourGroup->id), ShopAttributeFilter::filterKey($this->sizeGroup->id)],
            array_keys($config),
            'listed order is kept and unknown names are dropped'
        );
        $this->assertSame($this->sizeGroup->id, $config[ShopAttributeFilter::filterKey($this->sizeGroup->id)]['group_id']);
        $this->assertFalse($config[ShopAttributeFilter::filterKey($this->colourGroup->id)]['show_count']);
    }

    public function test_shortcode_custom_filters_render_attribute_filters(): void
    {
        $renderer = new ShopAppRenderer(Product::query()->where('ID', $this->camelProduct->ID)->paginate(10), [
            'custom_filters' => ['attributes' => [$this->colourGroup->slug], 'show_attribute_counts' => 'false'],
        ]);

        ob_start();
        $renderer->render();
        $html = ob_get_clean();

        $this->assertStringContainsString('name="attr_' . $this->colourGroup->id . '"', $html);
        $this->assertStringNotContainsString('name="attr_' . $this->sizeGroup->id . '"', $html);
        $this->assertStringNotContainsString('fct-shop-attribute-count', $html);
    }

    public function test_sidebar_queries_do_not_grow_with_each_attribute(): void
    {
        $this->sidebarQueries([$this->sizeGroup]); // warm the options cache the first call fills
        $oneGroup = $this->sidebarQueries([$this->colourGroup]);
        $twoGroups = $this->sidebarQueries([$this->colourGroup, $this->sizeGroup]);

        $this->assertCount(count($oneGroup), $twoGroups, implode("\n", $twoGroups));
    }

    public function test_each_group_shows_its_own_terms_however_many_another_group_has(): void
    {
        global $wpdb;
        // A big imported group whose terms all sort first must not use up the other group's share.
        $big = AttributeGroup::query()->create(['title' => 'Test Big', 'slug' => 'test-big-' . wp_generate_password(6, false)]);
        $rows = [];
        for ($i = 0; $i <= 2 * ShopAttributeFilter::MAX_TERMS_SHOWN; $i++) {
            $rows[] = $wpdb->prepare('(%d, %s, %s, 0)', $big->id, 'Big ' . $i, 'big-' . $i . '-' . wp_generate_password(6, false));
        }
        $wpdb->query("INSERT INTO {$wpdb->prefix}fct_atts_terms (group_id, title, slug, serial) VALUES " . implode(',', $rows));

        ShopAttributeFilter::preload([$big->id, $this->colourGroup->id]);

        $this->assertCount(ShopAttributeFilter::MAX_TERMS_SHOWN, ShopAttributeFilter::filterOptions($big->id, [], false)['options']);
        $colour = ShopAttributeFilter::filterOptions($this->colourGroup->id, [], false);
        $this->assertNotNull($colour, 'the other group still has its terms');
        $this->assertSame(['Black', 'Camel', 'Rust'], array_column($colour['options'], 'label'));
    }

    public function test_unused_values_do_not_use_up_the_term_limit_when_empty_values_are_hidden(): void
    {
        global $wpdb;
        // Imported values no product uses, all sorted before the colours products do use.
        $unusedTermRows = [];
        for ($termIndex = 0; $termIndex < ShopAttributeFilter::MAX_TERMS_SHOWN; $termIndex++) {
            $unusedTermRows[] = $wpdb->prepare('(%d, %s, %s, 0)', $this->colourGroup->id, 'Unused ' . $termIndex, 'unused-' . $termIndex . '-' . wp_generate_password(6, false));
        }
        $wpdb->query("INSERT INTO {$wpdb->prefix}fct_atts_terms (group_id, title, slug, serial) VALUES " . implode(',', $unusedTermRows));

        $renderer = new ProductFilterRender([
            'attr_' . $this->colourGroup->id => ['enabled' => true, 'filter_type' => 'attribute', 'group_id' => $this->colourGroup->id],
        ]);
        ob_start();
        $renderer->renderOptions();
        $html = ob_get_clean();

        $this->assertStringContainsString('>Black<', $html);
        $this->assertStringContainsString('>Camel<', $html);
        $this->assertSame(['Black', 'Camel'], array_column(ShopAttributeFilter::filterOptions($this->colourGroup->id)['options'], 'label'));
    }

    public function test_used_values_keep_their_place_when_empty_values_are_shown(): void
    {
        global $wpdb;
        // Imported values no product uses, all sorted before the colours products do use.
        $unusedTermRows = [];
        for ($termIndex = 0; $termIndex < ShopAttributeFilter::MAX_TERMS_SHOWN; $termIndex++) {
            $unusedTermRows[] = $wpdb->prepare('(%d, %s, %s, 0)', $this->colourGroup->id, 'Unused ' . $termIndex, 'unused-' . $termIndex . '-' . wp_generate_password(6, false));
        }
        $wpdb->query("INSERT INTO {$wpdb->prefix}fct_atts_terms (group_id, title, slug, serial) VALUES " . implode(',', $unusedTermRows));

        $renderer = new ProductFilterRender([
            'attr_' . $this->colourGroup->id => ['enabled' => true, 'filter_type' => 'attribute', 'group_id' => $this->colourGroup->id, 'show_empty' => true],
        ]);
        ob_start();
        $renderer->renderOptions();
        $html = ob_get_clean();

        $this->assertStringContainsString('>Black<', $html);
        $this->assertStringContainsString('>Camel<', $html);
        $shownLabels = array_column(ShopAttributeFilter::filterOptions($this->colourGroup->id, [], false)['options'], 'label');
        $this->assertCount(ShopAttributeFilter::MAX_TERMS_SHOWN, $shownLabels);
        $this->assertSame(['Black', 'Camel'], array_slice($shownLabels, -2), 'kept values stay in library order');
    }

    public function test_the_editor_picker_gets_the_storefronts_group_limit(): void
    {
        $localizedData = (new \FluentCart\App\Hooks\Handlers\BlockEditors\ShopApp\ShopAppBlockEditor())->localizeData();
        $productsEditorData = current(array_filter($localizedData, function ($entry) {
            return is_array($entry) && array_key_exists('attribute_groups', $entry);
        }));

        $this->assertSame(ShopAttributeFilter::MAX_GROUPS, $productsEditorData['attribute_group_limit'] ?? null);
        $this->assertStringContainsString((string) ShopAttributeFilter::MAX_GROUPS, $productsEditorData['attribute_group_limit_note'] ?? '');
    }

    public function test_the_attribute_group_limit_is_filterable_within_bounds(): void
    {
        $limit = function () {
            return 12;
        };
        add_filter('fluent_cart/shop_app/product_attribute/max_groups', $limit);
        try {
            $this->assertSame(12, ShopAttributeFilter::maxGroups());
            $selection = [];
            foreach (range(1, 15) as $groupId) {
                $selection['attr_' . $groupId] = [1];
            }
            $this->assertCount(12, ShopAttributeFilter::parseSelection($selection), 'a URL carries up to the filtered limit');
            $editorData = (new \FluentCart\App\Hooks\Handlers\BlockEditors\ShopApp\ShopAppBlockEditor())->localizeData();
            $productsEditorData = current(array_filter($editorData, function ($entry) {
                return is_array($entry) && array_key_exists('attribute_groups', $entry);
            }));
            $this->assertSame(12, $productsEditorData['attribute_group_limit'], 'the editor picker follows the filter');
        } finally {
            remove_filter('fluent_cart/shop_app/product_attribute/max_groups', $limit);
        }

        foreach ([0, -3, 100000] as $filtered) {
            $limit = function () use ($filtered) {
                return $filtered;
            };
            add_filter('fluent_cart/shop_app/product_attribute/max_groups', $limit);
            $bounded = ShopAttributeFilter::maxGroups();
            remove_filter('fluent_cart/shop_app/product_attribute/max_groups', $limit);
            $this->assertGreaterThanOrEqual(1, $bounded, $filtered . ' still shows one group');
            $this->assertLessThanOrEqual(ShopAttributeFilter::MAX_GROUPS_CEILING, $bounded, $filtered . ' stays under the ceiling');
        }
        $this->assertSame(ShopAttributeFilter::MAX_GROUPS, ShopAttributeFilter::maxGroups(), 'unfiltered, the limit is the default');
    }

    public function test_the_attribute_value_limit_is_filterable_within_bounds(): void
    {
        $limit = function () {
            return 2;
        };
        add_filter('fluent_cart/shop_app/product_attribute/max_terms', $limit);
        try {
            $this->assertSame(2, ShopAttributeFilter::maxTerms());
            $this->assertSame([1, 2], ShopAttributeFilter::parseSelection(['attr_5' => [1, 2, 3, 4]])[5], 'a URL carries up to the filtered limit');
            ShopAttributeFilter::preload([$this->colourGroup->id], []);
            $this->assertCount(2, ShopAttributeFilter::filterOptions($this->colourGroup->id, [], false)['options'], 'the sidebar shows up to the filtered limit');
        } finally {
            remove_filter('fluent_cart/shop_app/product_attribute/max_terms', $limit);
        }

        $huge = function () {
            return 100000;
        };
        add_filter('fluent_cart/shop_app/product_attribute/max_terms', $huge);
        $bounded = ShopAttributeFilter::maxTerms();
        remove_filter('fluent_cart/shop_app/product_attribute/max_terms', $huge);
        $this->assertLessThanOrEqual(ShopAttributeFilter::MAX_TERMS_CEILING, $bounded);
    }

    public function test_every_value_the_sidebar_shows_can_be_selected(): void
    {
        $this->assertGreaterThanOrEqual(ShopAttributeFilter::MAX_TERMS_SHOWN, ShopAttributeFilter::MAX_TERMS_PER_GROUP);
        $ids = range(1, ShopAttributeFilter::MAX_TERMS_SHOWN);
        $this->assertSame($ids, ShopAttributeFilter::parseSelection(['attr_5' => $ids])[5], 'a 51st checked value still reaches the query');
    }

    public function test_two_sidebars_with_the_same_attribute_keep_their_own_ids(): void
    {
        $config = [
            'attribute_filters'              => ['filter_type' => 'attribute_settings', 'active' => true],
            'attr_' . $this->colourGroup->id => ['enabled' => true, 'filter_type' => 'attribute', 'is_meta' => false, 'group_id' => $this->colourGroup->id],
        ];
        $render = function () use ($config) {
            ob_start();
            (new ProductFilterRender($config))->renderOptions();
            return ob_get_clean();
        };
        $first = $render();
        $second = $render();

        foreach ([$first, $second] as $html) {
            preg_match_all('/<label class="fct-shop-attribute-option" for="([^"]+)"/', $html, $labels);
            preg_match_all('/<input\s+id="([^"]+)"\s+class="fct-shop-attribute-input"/', $html, $inputs);
            $this->assertNotEmpty($labels[1]);
            $this->assertSame($labels[1], $inputs[1], 'each label targets its own checkbox');
            preg_match('/aria-controls="([^"]+)"/', $html, $controls);
            $this->assertStringContainsString('id="' . $controls[1] . '"', $html);
        }
        preg_match_all('/\bid="([^"]+)"/', $first . $second, $ids);
        $this->assertSame(count($ids[1]), count(array_unique($ids[1])), 'no id repeats across the two sidebars');
    }

    public function test_swatches_start_on_one_variant_the_product_sells(): void
    {
        // Size sorts M first and colour Black first, but only M Camel and L Black exist.
        $product = $this->productWithVariants('Crossed Coat', [['M', 'Camel'], ['L', 'Black']]);
        $html = $this->renderSwatches($product, ['group_ids' => [$this->sizeGroup->id, $this->colourGroup->id]]);

        preg_match_all('/data-term-id="(\d+)"[^>]*aria-pressed="true"|aria-pressed="true"[^>]*data-term-id="(\d+)"/', $html, $pressed);
        $pressedIds = array_map('intval', array_filter(array_merge($pressed[1], $pressed[2])));
        sort($pressedIds);
        $expected = [(int) $this->terms['M']->id, (int) $this->terms['Camel']->id];
        sort($expected);
        $this->assertSame($expected, $pressedIds, 'M and Camel, a variant the product sells, not M and Black');

        // A product that sells the first values together keeps starting on them, as before.
        $full = $this->productWithVariants('Full Coat', [['L', 'Camel'], ['M', 'Black'], ['M', 'Camel'], ['L', 'Black']]);
        $html = $this->renderSwatches($full, ['group_ids' => [$this->sizeGroup->id, $this->colourGroup->id]]);
        preg_match_all('/data-term-id="(\d+)"[^>]*aria-pressed="true"|aria-pressed="true"[^>]*data-term-id="(\d+)"/', $html, $pressed);
        $pressedIds = array_map('intval', array_filter(array_merge($pressed[1], $pressed[2])));
        sort($pressedIds);
        $expected = [(int) $this->terms['M']->id, (int) $this->terms['Black']->id];
        sort($expected);
        $this->assertSame($expected, $pressedIds, 'the first value of each row, not the first variant L Camel');
    }

    public function test_card_swatches_list_colours_with_variant_links(): void
    {
        $product = Product::query()->with(['detail', 'variants'])->find($this->splitProduct->ID);

        $swatches = ProductCardSwatchesRenderer::forProduct($product);

        $this->assertSame($this->colourGroup->id, $swatches['group_id'], 'the colour group is picked over size');
        $this->assertSame(['Black', 'Camel'], array_column($swatches['terms'], 'title'));

        $camelVariant = $product->variants->sortBy('serial_index')->values()[1];
        $this->assertStringContainsString('selected=' . $camelVariant->id, $swatches['terms'][1]['url']);
    }

    public function test_card_swatches_skip_products_without_attributes(): void
    {
        $plain = (new ProductFactory())->create();

        $this->assertNull(ProductCardSwatchesRenderer::forProduct(Product::query()->with(['detail', 'variants'])->find($plain->ID)));
    }

    public function test_swatches_block_is_allowed_in_product_loops_only(): void
    {
        $ancestors = \WP_Block_Type_Registry::get_instance()->get_registered('fluent-cart/product-swatches')->ancestor;

        foreach (['fluent-cart/products', 'fluent-cart/product-carousel', 'fluent-cart/related-product'] as $container) {
            $this->assertContains($container, $ancestors);
        }
        $this->assertNotContains('fluent-cart/product-info', $ancestors);
    }

    public function test_swatches_block_renders_for_the_loop_product(): void
    {
        $html = $this->renderSwatches($this->splitProduct, ['group_ids' => [$this->colourGroup->id], 'show_label' => false]);

        $this->assertStringContainsString('aria-label="Test Colour"', $html);
        $this->assertSame(2, preg_match_all('/data-fct-card-swatch\s/', $html), 'Black and Camel');
        $this->assertStringContainsString('selected=', $html);
        $this->assertStringNotContainsString('data-fct-card-swatch-label', $html);
    }

    public function test_swatches_block_shows_every_attribute_in_library_order_by_default(): void
    {
        $this->colourGroup->update(['serial' => 1]);
        $this->sizeGroup->update(['serial' => 2]);

        $html = $this->renderSwatches($this->splitProduct);

        preg_match_all('/data-group-id="(\d+)"/', $html, $rows);
        $this->assertSame([(string) $this->colourGroup->id, (string) $this->sizeGroup->id], $rows[1]);
    }

    public function test_swatches_block_keeps_the_selected_attributes_order(): void
    {
        $html = $this->renderSwatches($this->splitProduct, ['group_ids' => [$this->sizeGroup->id, $this->colourGroup->id]]);

        preg_match_all('/data-group-id="(\d+)"/', $html, $rows);
        $this->assertSame([(string) $this->sizeGroup->id, (string) $this->colourGroup->id], $rows[1]);
    }

    public function test_swatches_block_gives_each_card_its_variant_combinations(): void
    {
        // The click handler matches every row's choice against these, e.g. L + Camel.
        $html = $this->renderSwatches($this->splitProduct);
        preg_match('/data-variants="([^"]*)"/', $html, $match);
        $variants = json_decode(html_entity_decode($match[1], ENT_QUOTES), true);

        $combinations = array_map(function ($variant) {
            return [$variant['terms'][$this->sizeGroup->id], $variant['terms'][$this->colourGroup->id]];
        }, $variants);
        $this->assertSame([[$this->terms['M']->id, $this->terms['Black']->id], [$this->terms['L']->id, $this->terms['Camel']->id]], $combinations);
    }

    public function test_swatches_block_can_caption_each_row_with_its_attribute_name(): void
    {
        $this->assertStringNotContainsString('fct-card-swatch-group-name', $this->renderSwatches($this->splitProduct), 'off by default');

        $html = $this->renderSwatches($this->splitProduct, ['show_group_name' => true]);

        $this->assertSame(2, substr_count($html, 'fct-card-swatch-group-name'), 'one caption per row');
        $this->assertStringContainsString('>Test Colour</span>', $html);
        $this->assertStringContainsString('>Test Size</span>', $html);
    }

    public function test_swatches_block_hides_the_selected_name_by_default(): void
    {
        $html = $this->renderSwatches($this->splitProduct);

        $this->assertStringContainsString('data-fct-card-swatches', $html);
        $this->assertStringNotContainsString('data-fct-card-swatch-label', $html);
    }

    public function test_swatches_block_shows_the_selected_name_for_option_attributes(): void
    {
        $html = $this->renderSwatches($this->splitProduct, ['group_ids' => [$this->sizeGroup->id], 'show_label' => true]);

        $this->assertStringContainsString('fct-card-swatches is-label', $html);
        $this->assertStringContainsString('data-fct-card-swatch-label', $html);
    }

    public function test_swatches_block_requeues_the_click_script_another_empty_block_lost(): void
    {
        // Another card block loads the card assets first, renders empty, and WP 6.9 drops them;
        // the loader then refuses to queue them again.
        \FluentCart\App\Modules\Templating\AssetLoader::loadProductCardAssets();
        wp_dequeue_script('fluent-cart-product-card-js');

        $this->renderSwatches($this->splitProduct);

        $this->assertTrue(wp_script_is('fluent-cart-product-card-js', 'enqueued'));
    }

    public function test_swatches_block_renders_inside_a_product_carousel(): void
    {
        $html = do_blocks(
            '<!-- wp:fluent-cart/product-carousel {"product_ids":[' . $this->splitProduct->ID . ',' . $this->camelProduct->ID . ']} -->'
            . '<!-- wp:fluent-cart/product-carousel-loop --><!-- wp:fluent-cart/product-swatches /--><!-- /wp:fluent-cart/product-carousel-loop -->'
            . '<!-- /wp:fluent-cart/product-carousel -->'
        );

        $this->assertSame(2, substr_count($html, 'data-fct-card-swatch-rows'), 'one swatches block per carousel card');
    }

    public function test_swatches_load_variants_for_every_cached_product_at_once(): void
    {
        // Carousel and related products cache products without their variants.
        $split = Product::query()->find($this->splitProduct->ID);
        $camel = Product::query()->find($this->camelProduct->ID);
        ProductDataSetup::setProductsCache([$split, $camel]);

        $variantQueries = 0;
        $countVariantQueries = function ($query) use (&$variantQueries) {
            if (strpos($query, 'fct_product_variations') !== false) {
                $variantQueries++;
            }
            return $query;
        };

        add_filter('query', $countVariantQueries);
        $swatches = ProductCardSwatchesRenderer::forProduct($split);
        $camelSwatches = ProductCardSwatchesRenderer::forProduct($camel);
        remove_filter('query', $countVariantQueries);

        $this->assertSame(1, $variantQueries, 'both cards\' variants load in one query');
        $this->assertSame($this->colourGroup->id, $swatches['group_id']);
        $this->assertSame(['Camel'], array_column($camelSwatches['terms'], 'title'));
    }

    public function test_card_swatches_give_the_card_button_each_variants_price_stock_and_type(): void
    {
        [$black, $camel] = $this->splitProduct->variants->sortBy('serial_index')->values()->all();
        ProductVariation::query()->where('id', $black->id)->update(['item_price' => 1500]);
        ProductVariation::query()->where('id', $camel->id)->update([
            'payment_type' => 'subscription',
            'stock_status' => 'out-of-stock',
            'other_info'   => wp_json_encode(['payment_type' => 'subscription', 'repeat_interval' => 'monthly']),
        ]);

        $variants = $this->withStockManagement(function () {
            return $this->variantData($this->renderSwatches($this->splitProduct));
        });

        $this->assertSame([true, false], array_column($variants, 'in_stock'));
        $this->assertSame([false, true], array_column($variants, 'subscription'));
        $this->assertStringContainsString('15', $variants[0]['price']);
        $this->assertSame('', $variants[0]['purchase_url']);
        $this->assertStringContainsString('item_id=' . $camel->id, $variants[1]['purchase_url']);
    }

    public function test_card_swatches_serialize_each_variants_tax_suffix(): void
    {
        update_option('fluent_cart_tax_configuration_settings', [
            'enable_tax' => 'yes', 'tax_inclusion' => 'included',
            'price_suffix_included' => 'Tax included', 'price_suffix_excluded' => 'Tax excluded',
        ]);
        [$black, $camel] = $this->splitProduct->variants->sortBy('serial_index')->values()->all();
        $black->other_info = ['tax_inclusion' => 'included'];
        $black->save();
        $camel->other_info = ['tax_inclusion' => 'excluded'];
        $camel->save();
        $variants = $this->variantData($this->renderSwatches($this->splitProduct));
        $this->assertSame(['Tax included', 'Tax excluded'], array_column($variants, 'price_suffix'), 'Each price carries its own tax suffix');
    }

    public function test_explicit_product_swatch_preview_uses_storefront_eligibility(): void
    {
        $block = new \FluentCart\App\Hooks\Handlers\BlockEditors\ProductSwatchesBlockEditor();
        $attributes = ['product_id' => $this->splitProduct->ID];
        $this->assertNotNull($this->cardButtonConfig($block->render($attributes)), 'Full swatches can purchase');
        $this->assertNull($this->cardButtonConfig($block->render($attributes + ['group_ids' => [$this->colourGroup->id]])), 'Partial swatches keep View Options');
        $detail = $this->splitProduct->detail;
        $detail->other_info = array_merge($detail->other_info ?: [], ['is_bundle_product' => 'yes']);
        $detail->save();
        $this->assertNull($this->cardButtonConfig($block->render($attributes)), 'Bundles keep View Options');
    }

    public function test_card_button_takes_over_only_when_the_rows_pin_one_variant(): void
    {
        $config = $this->cardButtonConfig($this->renderSwatches($this->splitProduct));
        $this->assertTrue($config['can_add']);
        $this->assertNotEmpty($config['labels']['add']);
        $this->assertSame('Not available', $config['labels']['unavailable']);

        // Colour alone cannot tell M Black from another size of Black, so the card keeps View Options.
        $colourOnly = $this->renderSwatches($this->splitProduct, ['group_ids' => [$this->colourGroup->id]]);
        $this->assertNull($this->cardButtonConfig($colourOnly));
    }

    public function test_catalog_mode_keeps_the_card_on_view_options(): void
    {
        add_filter('fluent_cart/product/show_add_to_cart_button', '__return_false');
        add_filter('fluent_cart/product/show_buy_now_button', '__return_false');
        try {
            $html = $this->renderSwatches($this->splitProduct);
        } finally {
            remove_filter('fluent_cart/product/show_add_to_cart_button', '__return_false');
            remove_filter('fluent_cart/product/show_buy_now_button', '__return_false');
        }

        $this->assertNull($this->cardButtonConfig($html));
    }

    public function test_attribute_label_and_selected_name_share_one_line_above_the_swatches(): void
    {
        $html = $this->renderSwatches($this->splitProduct, [
            'group_ids'       => [$this->colourGroup->id],
            'show_group_name' => true,
            'show_label'      => true,
        ]);

        // e.g. "COLOR · Black" over the colour circles.
        $this->assertMatchesRegularExpression(
            '/class="fct-card-swatch-heading".*fct-card-swatch-group-name[^>]*>Test Colour<.*data-fct-card-swatch-label[^>]*>Black<.*class="fct-card-swatch-list"/s',
            $html
        );
    }

    public function test_each_product_type_section_switches_its_cards_off(): void
    {
        $options = $this->optionProduct(['Standard', 'Bundle']);
        ProductDetail::query()->where('post_id', $this->splitProduct->ID)->update(['variation_type' => 'advanced_variations']);

        $advancedOff = ['advanced_enabled' => false];
        $this->assertSame('', trim($this->renderSwatches($this->splitProduct, $advancedOff)));
        $this->assertStringContainsString('data-fct-card-swatches', $this->renderSwatches($options, $advancedOff));

        $simpleOff = ['simple_enabled' => false];
        $this->assertSame('', trim($this->renderSwatches($options, $simpleOff)));
        $this->assertStringContainsString('data-fct-card-swatches', $this->renderSwatches($this->splitProduct, $simpleOff));
    }

    public function test_simple_variations_have_their_own_row_label_max_and_caption(): void
    {
        $options = $this->optionProduct(['Single Pair', '3-Pack', 'Monthly Pair', 'Yearly Box', 'Gift Box']);

        // Defaults: up to 4 options, the row named "Option" for screen readers, no caption.
        $html = $this->renderSwatches($options);
        $this->assertSame(4, preg_match_all('/data-fct-card-swatch\s/', $html));
        $this->assertStringContainsString('aria-label="Option"', $html);
        $this->assertStringNotContainsString('fct-card-swatch-group-name', $html);

        // The advanced settings leave simple variations alone.
        $html = $this->renderSwatches($options, [
            'max_visible'        => 1,
            'show_group_name'    => true,
            'simple_label'       => 'Plan',
            'simple_max_visible' => 2,
            'simple_show_label'  => true,
        ]);
        $this->assertSame(2, preg_match_all('/data-fct-card-swatch\s/', $html));
        $this->assertMatchesRegularExpression('/fct-card-swatch-group-name[^>]*>Plan</', $html);
    }

    public function test_values_past_the_max_become_a_more_chip_that_opens_quick_view(): void
    {
        $options = $this->optionProduct(['Single Pair', '3-Pack', 'Monthly Pair', 'Yearly Box', 'Gift Box', 'Trial']);

        $html = $this->renderSwatches($options);
        $this->assertMatchesRegularExpression('/fct-card-swatch-more[^>]*>\s*\+2\s*</', $html);
        $this->assertStringContainsString('data-fluent-cart-single-product-card-view-button', $html);
        $this->assertStringContainsString('data-product-id="' . $options->ID . '"', $html);
        $this->assertStringContainsString('aria-label="2 more Option choices"', $html);

        $this->assertStringNotContainsString('fct-card-swatch-more', $this->renderSwatches($options, ['simple_max_visible' => 6]));
    }

    public function test_more_chip_links_to_the_product_when_card_actions_are_hidden(): void
    {
        $options = $this->optionProduct(['Single Pair', '3-Pack', 'Monthly Pair', 'Yearly Box', 'Gift Box']);

        add_filter('fluent_cart/product/show_actions', '__return_false');
        try {
            $html = $this->renderSwatches($options);
        } finally {
            remove_filter('fluent_cart/product/show_actions', '__return_false');
        }

        $this->assertMatchesRegularExpression('/<a[^>]*fct-card-swatch-more[^>]*href="' . preg_quote(get_permalink($options->ID), '/') . '"/', $html);
        $this->assertStringNotContainsString('data-fluent-cart-single-product-card-view-button', $html);
    }

    public function test_simple_variations_get_one_option_row_of_their_variants(): void
    {
        $product = (new ProductFactory())->createWithVariants([
            ['variation_title' => 'Standard'],
            ['variation_title' => 'Bundle'],
        ], ['post_title' => 'Option Product']);
        ProductDetail::query()->where('post_id', $product->ID)->update(['variation_type' => 'simple_variations']);

        $html = $this->renderSwatches($product);
        preg_match_all('/data-fct-card-swatch\s.*?data-label="([^"]*)"/s', $html, $labels);
        $variantIds = array_column($this->variantData($html), 'id');

        $this->assertSame(1, substr_count($html, 'data-fct-card-swatches'));
        $this->assertSame(['Standard', 'Bundle'], $labels[1]);
        $this->assertSame($variantIds, array_map(function ($variant) {
            return $variant['terms']['option'];
        }, $this->variantData($html)));
        $this->assertNotNull($this->cardButtonConfig($html));
    }

    /** Renders the block as a product loop does: for the current product. */
    private function renderSwatches($product, array $attributes = []): string
    {
        $GLOBALS['fct_product'] = Product::query()->with(['detail', 'variants'])->find($product->ID);

        try {
            return do_blocks('<!-- wp:fluent-cart/product-swatches ' . wp_json_encode((object) $attributes) . ' /-->');
        } finally {
            unset($GLOBALS['fct_product']);
        }
    }

    private function sidebarQueries(array $groups): array
    {
        $config = [];
        foreach ($groups as $group) {
            $config[ShopAttributeFilter::filterKey($group->id)] = ['enabled' => true, 'filter_type' => 'attribute', 'group_id' => $group->id];
        }

        $queries = [];
        $collect = function ($query) use (&$queries) {
            $queries[] = $query;
            return $query;
        };

        add_filter('query', $collect);
        new ProductFilterRender($config);
        remove_filter('query', $collect);

        return $queries;
    }

    private function term($group, string $title, int $serial, array $settings = [])
    {
        return AttributeTerm::query()->create([
            'group_id' => $group->id,
            'title'    => $title,
            'slug'     => sanitize_title($title) . '-' . wp_generate_password(6, false),
            'serial'   => $serial,
            'settings' => $settings,
        ]);
    }

    private function productWithVariants(string $title, array $combinations)
    {
        $product = (new ProductFactory())->createWithVariants(
            array_map(function ($combination) {
                return ['variation_title' => implode(' / ', $combination)];
            }, $combinations),
            ['post_title' => $title]
        );

        foreach ($product->variants->sortBy('serial_index')->values() as $index => $variant) {
            [$size, $colour] = $combinations[$index];
            AttributeRelation::query()->create(['group_id' => $this->sizeGroup->id, 'term_id' => $this->terms[$size]->id, 'object_id' => $variant->id]);
            AttributeRelation::query()->create(['group_id' => $this->colourGroup->id, 'term_id' => $this->terms[$colour]->id, 'object_id' => $variant->id]);
        }

        return $product;
    }

    private function filteredIds(array $filters): array
    {
        $result = ShopResource::get([
            'status'          => ['post_status' => ['column' => 'post_status', 'operator' => 'in', 'value' => ['publish']]],
            'selected_status' => true,
            'filters'         => $filters,
            'per_page'        => 100,
            'paginate'        => 100,
            'paginate_using'  => 'simple',
        ]);

        return $result['products']->getCollection()->pluck('ID')->map('intval')->all();
    }
}
