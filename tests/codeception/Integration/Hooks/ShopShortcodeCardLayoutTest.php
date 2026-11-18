<?php

namespace Tests\Integration\Hooks;

use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * [fluent_cart_products card_layout="..."] gives classic themes, which cannot edit
 * archive templates with blocks, the same card layouts as the Product Card block.
 */
class ShopShortcodeCardLayoutTest extends WPTestCase
{
    /** @var int[] */
    private $productIds = [];

    public function setUp(): void
    {
        parent::setUp();
        $factory = new ProductFactory();
        foreach (['Alpha', 'Bravo'] as $name) {
            $this->productIds[] = (int) $factory->create(['post_title' => 'CardLayout ' . $name])->ID;
        }
    }

    private function render(string $attributes = ''): string
    {
        return do_shortcode('[fluent_cart_products per_page="20" ids="' . implode(',', $this->productIds) . '" ' . $attributes . ']');
    }

    public function test_card_layout_renders_every_product_with_that_layout(): void
    {
        $output = $this->render('card_layout="floating"');

        $this->assertSame(2, substr_count($output, 'fct-card-layout--floating'));
        $this->assertStringContainsString('CardLayout Alpha', $output);
        $this->assertStringContainsString('CardLayout Bravo', $output);
        $this->assertStringContainsString('data-card-layout="floating"', $output, 'AJAX pages need the layout too');
    }

    public function test_classic_layout_uses_the_template_card(): void
    {
        $output = $this->render('card_layout="classic"');

        $this->assertSame(2, substr_count($output, 'fct-product-card-template'));
    }

    public function test_without_or_with_an_unknown_layout_the_card_is_unchanged(): void
    {
        foreach (['', 'card_layout="nope"'] as $attributes) {
            $output = $this->render($attributes);
            $this->assertStringNotContainsString('fct-card-layout', $output);
            $this->assertStringNotContainsString('fct-product-card-template', $output);
            $this->assertStringNotContainsString('data-card-layout', $output);
            $this->assertStringContainsString('CardLayout Alpha', $output);
        }
    }

    public function test_a_grid_only_layout_has_no_view_switch_and_shows_a_grid(): void
    {
        $output = $this->render('card_layout="floating" view_mode="list"');

        $this->assertStringNotContainsString('data-fluent-cart-shop-app-grid-view-button', $output);
        $this->assertStringNotContainsString('data-fluent-cart-shop-app-list-view-button', $output);
        $this->assertStringContainsString('mode-grid', $output);
        $this->assertStringNotContainsString('mode-list', $output);
        $this->assertStringNotContainsString('fct-shop-view-switcher-wrap', $output, 'no filters: nothing left to show there');
    }

    public function test_other_layouts_keep_the_view_switch_and_list_view(): void
    {
        foreach (['', 'card_layout="compact"', 'card_layout="framed"'] as $attributes) {
            $output = $this->render($attributes . ' view_mode="list"');
            $this->assertStringContainsString('data-fluent-cart-shop-app-grid-view-button', $output, $attributes);
            $this->assertStringContainsString('mode-list', $output, $attributes);
        }
    }

    public function test_the_renderer_follows_a_grid_only_layout_without_the_flag(): void
    {
        $products = \FluentCart\App\Models\Product::query()->with(['detail', 'variants'])->whereIn('ID', $this->productIds)->paginate(20);
        $render = static function (array $config) use ($products): string {
            ob_start();
            (new \FluentCart\App\Services\Renderer\ShopAppRenderer($products, $config + ['view_mode' => 'list']))->render();
            return (string) ob_get_clean();
        };

        // A builder that passes only the layout, as the Elementor and Divi add-ons do.
        $floating = $render(['card_layout' => 'floating']);
        $this->assertStringNotContainsString('data-fluent-cart-shop-app-grid-view-button', $floating);
        $this->assertStringContainsString('mode-grid', $floating);
        $this->assertStringNotContainsString('mode-list', $floating);

        $compact = $render(['card_layout' => 'compact']);
        $this->assertStringContainsString('data-fluent-cart-shop-app-grid-view-button', $compact);
        $this->assertStringContainsString('mode-list', $compact);
    }

    public function test_a_grid_only_layout_keeps_the_filter_toggle_and_sort_menu(): void
    {
        $products = \FluentCart\App\Models\Product::query()->with(['detail', 'variants'])->whereIn('ID', $this->productIds)->paginate(20);
        ob_start();
        (new \FluentCart\App\Services\Renderer\ShopAppRenderer($products, ['card_layout' => 'floating', 'grid_only' => true, 'custom_filters' => ['enabled' => true]]))->render();
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('fct-shop-sorting-container', $output);
        $this->assertStringContainsString('data-fluent-cart-shop-app-filter-toggle-button', $output);
        $this->assertStringNotContainsString('data-fluent-cart-shop-app-grid-view-button', $output);
    }

    public function test_masonry_staggers_the_layout_cards(): void
    {
        foreach (['framed', 'classic'] as $layout) {
            $output = $this->render('card_layout="' . $layout . '" masonry="yes"');
            $this->assertSame(2, substr_count($output, 'fct-card-masonry'), $layout);
            $this->assertStringContainsString('&quot;masonry&quot;:true', $output, 'AJAX pages need it too');
        }
        $this->assertStringNotContainsString('fct-card-masonry', $this->render('card_layout="framed"'));
        $this->assertStringNotContainsString('fct-card-masonry', $this->render('card_layout="framed" masonry="no"'));
        // The standard card has no masonry look; only layout cards do.
        $this->assertStringNotContainsString('fct-card-masonry', $this->render('masonry="yes"'));
    }

    public function test_ajax_pages_keep_masonry(): void
    {
        $this->assertSame(2, substr_count($this->productViews(['card_layout' => 'framed', 'layout_options' => ['masonry' => 'true']]), 'fct-card-masonry'));
        $this->assertStringNotContainsString('fct-card-masonry', $this->productViews(['card_layout' => 'framed', 'layout_options' => ['masonry' => 'false']]));
    }

    public function test_card_options_set_the_layout_cards_swatches_and_quick_view(): void
    {
        $ids = $this->variableProducts('CardLayout Options');
        // Quick View shows only for a product with options to pick.
        \FluentCart\App\Models\ProductDetail::query()->whereIn('post_id', $ids)->update(['variation_type' => \FluentCart\App\Helpers\Helper::PRODUCT_TYPE_SIMPLE_VARIATION]);
        $render = static function (string $attributes) use ($ids): string {
            return do_shortcode('[fluent_cart_products card_layout="compact" ids="' . implode(',', $ids) . '" ' . $attributes . ']');
        };

        $default = $render('');
        $this->assertSame(0, substr_count($default, 'data-fct-card-swatch-more'), 'Compact shows two options, so two fit');
        $this->assertSame(3, substr_count($default, 'data-position="image"'));

        $set = $render('swatch_count="1" quick_view="button"');
        $this->assertSame(3, substr_count($set, 'data-fct-card-swatch-more'), 'one option, the other behind +1');
        $this->assertSame(3, substr_count($set, 'data-position="button"'));
        $this->assertStringContainsString('&quot;quick_view&quot;:{&quot;position&quot;:&quot;button&quot;}', $set, 'AJAX pages need it too');

        $this->assertStringNotContainsString('fct-card-swatches', $render('layout_hide="swatches"'));
        // An unknown value keeps the layout's own setting.
        $this->assertSame(3, substr_count($render('quick_view="nope" swatch_count="x"'), 'data-position="image"'));
    }

    public function test_card_options_need_a_layout_card(): void
    {
        $this->assertStringNotContainsString('data-layout-options', $this->render('swatch_count="1" quick_view="button" layout_hide="rating"'));
    }

    public function test_shortcode_card_options_read_every_attribute(): void
    {
        $options = \FluentCart\App\Services\Renderer\ProductCardLayoutPresets::shortcodeCardOptions([
            'swatch_count' => '3', 'swatch_selected_name' => 'no', 'swatch_group_name' => 'yes', 'swatch_sold_out' => 'Cross',
            'quick_view' => 'Image', 'masonry' => 'yes', 'layout_hide' => 'rating,quick_view',
        ]);
        $this->assertEquals([
            'swatches'   => ['max_visible' => 3, 'simple_max_visible' => 3, 'show_label' => false, 'show_group_name' => true, 'simple_show_label' => true, 'sold_out_display' => 'cross'],
            'quick_view' => false,
            'masonry'    => true,
            'rating'     => false,
        ], $options, 'values are case-insensitive; a hidden part wins over its settings');
        $this->assertSame('image', \FluentCart\App\Services\Renderer\ProductCardLayoutPresets::shortcodeCardOptions(['quick_view' => 'Image'])['quick_view']['position']);
        $this->assertSame(0, \FluentCart\App\Services\Renderer\ProductCardLayoutPresets::shortcodeCardOptions(['swatch_count' => 'All'])['swatches']['max_visible'], 'All is 0, as the block saves it');
        foreach (['0.5' => 1, '1e-9' => 1, '-0.5' => 1, '2.7' => 2, '0.0' => 0] as $count => $expected) {
            $this->assertSame($expected, \FluentCart\App\Services\Renderer\ProductCardLayoutPresets::shortcodeCardOptions(['swatch_count' => (string) $count])['swatches']['max_visible'], 'a fraction below 1 is one option, not All: ' . $count);
        }
        $this->assertSame([], \FluentCart\App\Services\Renderer\ProductCardLayoutPresets::shortcodeCardOptions(['swatch_selected_name' => '', 'swatch_group_name' => 'maybe', 'swatch_count' => 'x']));
    }

    public function test_layout_hide_can_remove_quick_view_and_swatch_settings_are_validated(): void
    {
        $ids = $this->variableProducts('CardLayout Hide');
        \FluentCart\App\Models\ProductDetail::query()->whereIn('post_id', $ids)->update(['variation_type' => \FluentCart\App\Helpers\Helper::PRODUCT_TYPE_SIMPLE_VARIATION]);
        $render = static function (string $attributes) use ($ids): string {
            return do_shortcode('[fluent_cart_products card_layout="compact" ids="' . implode(',', $ids) . '" ' . $attributes . ']');
        };

        $this->assertSame(3, substr_count($render(''), 'fct-card-quick-view-button'));
        $this->assertSame(0, substr_count($render('layout_hide="quick_view"'), 'fct-card-quick-view-button'));
        // 0 and "all" show every option, as the swatches block's All box does; a negative count is one option.
        $this->assertSame(0, substr_count($render('swatch_count="0"'), 'data-fct-card-swatch-more'), '0 is All');
        $this->assertSame(0, substr_count($render('swatch_count="all"'), 'data-fct-card-swatch-more'));
        $this->assertSame(3, substr_count($render('swatch_count="-2"'), 'data-fct-card-swatch-more'), 'one option and "+N"');
        $this->assertSame(0, substr_count($render('swatch_count="9"'), 'data-fct-card-swatch-more'));
        $this->assertSame(3, substr_count($render('QUICK_VIEW="Button"' . ' quick_view="Button"'), 'data-position="button"'));
    }

    public function test_layout_cards_keep_the_price_format_on_ajax_pages(): void
    {
        $this->assertStringContainsString('data-price-format="range"', $this->render('card_layout="compact" price_format="range"'));
        // The classic card never read price_format, so its wrapper stays as it was.
        $this->assertStringNotContainsString('data-price-format', $this->render('price_format="range"'));
    }

    /** @return int[] Variable products, new to the swatch renderer's per-request cache. */
    private function variableProducts(string $prefix): array
    {
        $factory = new ProductFactory();
        $ids = [];
        foreach (['One', 'Two', 'Three'] as $name) {
            $ids[] = (int) $factory->createWithVariants([['variation_title' => 'Small'], ['variation_title' => 'Large']], ['post_title' => $prefix . ' ' . $name])->ID;
        }
        return $ids;
    }

    private function relationQueries(callable $render): array
    {
        $queries = 0;
        $count = function ($query) use (&$queries) {
            if (strpos($query, 'fct_atts_relations') !== false) {
                $queries++;
            }
            return $query;
        };
        add_filter('query', $count);
        $output = $render();
        remove_filter('query', $count);
        return [$queries, $output];
    }

    public function test_layout_cards_load_every_products_swatch_data_in_one_batch(): void
    {
        $ids = $this->variableProducts('CardLayout Batch');
        [$queries, $output] = $this->relationQueries(function () use ($ids) {
            return do_shortcode('[fluent_cart_products card_layout="compact" ids="' . implode(',', $ids) . '"]');
        });

        $this->assertSame(3, substr_count($output, 'fct-card-layout--compact'));
        $this->assertSame(1, $queries, 'the page\'s swatch data loads once, not per card');
    }

    public function test_ajax_layout_pages_load_swatch_data_in_one_batch(): void
    {
        $ids = $this->variableProducts('CardLayout Ajax Batch');
        [$queries, $views] = $this->relationQueries(function () use ($ids) {
            $request = new \WP_REST_Request('GET', '/fluent-cart/v2/public/product-views');
            $request->set_query_params(['card_layout' => 'compact', 'include_ids' => $ids, 'per_page' => 20]);
            return rest_do_request($request)->get_data()['products']['views'];
        });

        $this->assertSame(3, substr_count($views, 'fct-card-layout--compact'));
        $this->assertSame(1, $queries, 'the page\'s swatch data loads once, not per card');
    }

    private function productViews(array $query): string
    {
        $request = new \WP_REST_Request('GET', '/fluent-cart/v2/public/product-views');
        $request->set_query_params(array_merge(['include_ids' => $this->productIds, 'per_page' => 20], $query));
        return rest_do_request($request)->get_data()['products']['views'];
    }

    public function test_ajax_pages_render_the_requested_layout(): void
    {
        // Explicit: the framework keeps request values between in-process REST calls.
        $this->assertStringNotContainsString('fct-card-layout', $this->productViews(['card_layout' => '']));
        $this->assertSame(2, substr_count($this->productViews(['card_layout' => 'floating']), 'fct-card-layout--floating'));
    }

    public function test_ajax_layout_pages_keep_a_builders_layout_options(): void
    {
        \FluentCart\App\Models\ProductVariation::query()->whereIn('post_id', $this->productIds)->update(['compare_price' => 9900, 'item_price' => 4500]);
        $this->assertStringContainsString('fct-sale-badge', $this->productViews(['card_layout' => 'compact', 'layout_options' => []]));
        // As Paginator.js sends it: a query string, so false arrives as "false".
        $this->assertStringNotContainsString('fct-sale-badge', $this->productViews(['card_layout' => 'compact', 'layout_options' => ['sale_badge' => 'false']]), 'Sale badge off on later pages too');

        ob_start();
        (new \FluentCart\App\Services\Renderer\ShopAppRenderer(\FluentCart\App\Models\Product::query()->with(['detail', 'variants'])->whereIn('ID', $this->productIds)->paginate(20), ['card_layout' => 'compact', 'layout_options' => ['sale_badge' => false]]))->render();
        $this->assertStringContainsString('data-layout-options="{&quot;sale_badge&quot;:false}"', (string) ob_get_clean(), 'the grid hands its options to Paginator.js');
    }
}
