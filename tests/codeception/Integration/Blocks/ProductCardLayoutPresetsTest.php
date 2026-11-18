<?php

namespace Tests\Integration\Blocks;

use FluentCart\App\Hooks\Handlers\BlockEditors\ShopApp\InnerBlocks\InnerBlocks;
use FluentCart\App\Services\Renderer\ProductCardLayoutPresets;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;
use Tests\Support\Traits\BuildsProductCardPresets;

class ProductCardLayoutPresetsTest extends WPTestCase
{
    use BuildsProductCardPresets;

    public function test_nested_presets_render_each_products_own_title_category_and_purchase_id(): void
    {
        $first = (new ProductFactory())->create(['post_title' => 'Coffee preset product', 'post_status' => 'publish']);
        $second = (new ProductFactory())->create(['post_title' => 'Kettle preset product', 'post_status' => 'publish']);
        wp_set_object_terms($first->ID, 'Coffee category', 'product-categories');
        wp_set_object_terms($second->ID, 'Gear category', 'product-categories');

        foreach (array_diff_key(ProductCardLayoutPresets::all(), ['classic' => true]) as $key => $preset) {
            $attributes = ['wp_client_id' => 'layout-test-' . $key];
            $markup = get_comment_delimited_block_content('fluent-cart/shopapp-product-loop', $attributes, $this->cardPresetMarkup($preset['template']));
            $block = new \WP_Block(parse_blocks($markup)[0], ['fluent-cart/per_page' => 100]);
            $html = (new InnerBlocks())->renderProductLoop($attributes, '', $block);
            $saved = get_transient('fct_product_loop_client_' . $attributes['wp_client_id']);
            $this->assertNotEmpty($saved['markup']);
            $replayed = do_blocks($saved['markup']);
            $this->assertStringContainsString('fct-card-layout--' . $key, $replayed, 'The cached pagination template retains its layout');
            $this->assertStringContainsString($first->post_title, $replayed);
            $this->assertStringContainsString($second->post_title, $replayed);
            $document = new \DOMDocument();
            @$document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
            $xpath = new \DOMXPath($document);
            foreach ([$first, $second] as $product) {
                $cards = $xpath->query('//div[@data-fct-product-card][.//*[contains(text(), "' . $product->post_title . '")]]');
                $this->assertSame(1, $cards->length, $key . ' must render each title once');
                $card = $cards->item(0);
                $category = $product->ID === $first->ID ? 'Coffee category' : 'Gear category';
                $otherCategory = $product->ID === $first->ID ? 'Gear category' : 'Coffee category';
                $this->assertStringContainsString($category, $card->textContent);
                $this->assertStringNotContainsString($otherCategory, $card->textContent);
                $this->assertSame(1, $xpath->query('.//*[@data-cart-id="' . $product->variants->first()->id . '"]', $card)->length);
                $this->assertSame(1, $xpath->query('.//*[contains(@class, "fct-card-layout--' . $key . '")]', $card)->length);
            }
        }
    }

    public function test_presets_leave_colours_to_the_stylesheet_and_categories_unpunctuated(): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'Two category product', 'post_status' => 'publish']);
        wp_set_object_terms($product->ID, ['Audio', 'Electronics'], 'product-categories');

        foreach (array_diff_key(ProductCardLayoutPresets::all(), ['classic' => true]) as $key => $preset) {
            $attributes = ['wp_client_id' => 'layout-colour-test-' . $key];
            $markup = get_comment_delimited_block_content('fluent-cart/shopapp-product-loop', $attributes, $this->cardPresetMarkup($preset['template']));
            $html = (new InnerBlocks())->renderProductLoop($attributes, '', new \WP_Block(parse_blocks($markup)[0], ['fluent-cart/per_page' => 100]));

            // An inline colour would override the palette and the theme's "no colours" mode.
            $this->assertStringNotContainsString('background-color', $html, $key . ' must not save a fixed panel colour');
            $this->assertMatchesRegularExpression('/>Audio<\/a>.*?>Electronics<\/a>/s', $html, $key . ' lists both categories');
            $this->assertStringNotContainsString('wp-block-post-terms__separator">, <', $html, $key . ' must not put a comma between category pills');
        }
    }

    public function test_classic_shows_the_sale_and_sold_out_badges_like_the_other_layouts(): void
    {
        $image = ProductCardLayoutPresets::all()['classic']['template'][0];
        $this->assertSame(['fluent-cart/sale-badge', 'fluent-cart/sold-out-badge', 'fluent-cart/product-quick-view'], array_column($image[2] ?? [], 0));

        $product = (new ProductFactory())->create(['post_title' => 'Classic sale product', 'post_status' => 'publish']);
        \FluentCart\App\Models\ProductVariation::query()->where('post_id', $product->ID)->update(['compare_price' => 9900, 'item_price' => 4500]);
        $attributes = ['wp_client_id' => 'layout-classic-badge-test'];
        $markup = get_comment_delimited_block_content('fluent-cart/shopapp-product-loop', $attributes, $this->cardPresetMarkup(ProductCardLayoutPresets::all()['classic']['template']));
        $html = (new InnerBlocks())->renderProductLoop($attributes, '', new \WP_Block(parse_blocks($markup)[0], ['fluent-cart/per_page' => 100]));

        $this->assertStringContainsString($product->post_title, $html);
        $this->assertStringContainsString('fct-sale-badge', $html, 'a discounted product shows its Sale badge');
    }

    public function test_default_preset_comes_first_and_rebuilds_the_standard_card(): void
    {
        $presets = ProductCardLayoutPresets::all();
        $this->assertSame('classic', array_key_first($presets));
        // A flat card without layout groups: picture (holding Quick View, on hover), title, rating, price, options, button.
        $this->assertSame(
            ['fluent-cart/product-image', 'fluent-cart/product-title', 'fluent-cart/product-rating', 'fluent-cart/price-range', 'fluent-cart/product-swatches', 'fluent-cart/shopapp-product-buttons'],
            array_column($presets['classic']['template'], 0)
        );
        // "OPTION · 500 G" for simple variations, "LIGHT" alone for attributes.
        $swatches = ['show_label' => true, 'show_group_name' => false, 'simple_show_label' => true];
        $onImage = ['position' => 'image', 'show_on' => 'hover', 'display' => 'icon_text'];
        $this->assertSame($swatches, $presets['classic']['template'][4][1]);
        $this->assertSame($onImage, $presets['classic']['template'][0][2][2][1]);
        $this->assertSame(['fluent-cart/product-swatches' => $swatches, 'fluent-cart/product-quick-view' => $onImage], $presets['classic']['attributes']);

        $product = (new ProductFactory())->create(['post_title' => 'Default card product', 'post_status' => 'publish']);
        $attributes = ['wp_client_id' => 'layout-default-test'];
        $markup = get_comment_delimited_block_content('fluent-cart/shopapp-product-loop', $attributes, $this->cardPresetMarkup($presets['classic']['template']));
        $html = (new InnerBlocks())->renderProductLoop($attributes, '', new \WP_Block(parse_blocks($markup)[0], ['fluent-cart/per_page' => 100]));

        $this->assertStringNotContainsString('fct-card-layout', $html, 'The default card keeps the standard markup');
        $this->assertSame(1, substr_count($html, '>' . $product->post_title . '</a>'));
        $this->assertStringContainsString('data-cart-id="' . $product->variants->first()->id . '"', $html);
    }

    public function test_colour_band_sets_its_grid_two_options_and_a_quick_view_beside_the_button(): void
    {
        $framed = ProductCardLayoutPresets::all()['framed'];
        $this->assertSame(['view_mode' => 'grid', 'product_box_grid_size' => 3], $framed['products']);
        // Only the chosen option's name as the caption, for advanced and simple variations alike.
        $swatches = ['max_visible' => 2, 'simple_max_visible' => 2, 'show_label' => true, 'show_group_name' => false, 'simple_show_label' => false];
        $this->assertSame($swatches, $framed['attributes']['fluent-cart/product-swatches']);
        $this->assertSame(['position' => 'button', 'display' => 'icon'], $framed['attributes']['fluent-cart/product-quick-view']);

        // The template itself carries the same settings, so a fresh card matches the switched one.
        $details = $framed['template'][0][2][1][2];
        $this->assertSame($swatches, $details[2][1]);
        $footer = $details[3][2];
        $this->assertSame(['fluent-cart/shopapp-product-price', 'fluent-cart/shopapp-product-buttons', 'fluent-cart/product-quick-view'], array_column($footer, 0));
        $this->assertSame(['position' => 'button', 'display' => 'icon'], $footer[2][1]);

        // Quick View only renders for a product with options to pick.
        $product = (new ProductFactory())->createWithVariants([['variation_title' => 'Small'], ['variation_title' => 'Large']], ['post_title' => 'Band quick view product']);
        \FluentCart\App\Models\ProductDetail::query()->where('post_id', $product->ID)->update(['variation_type' => 'simple_variations']);
        $attributes = ['wp_client_id' => 'layout-band-quick-view'];
        $markup = get_comment_delimited_block_content('fluent-cart/shopapp-product-loop', $attributes, $this->cardPresetMarkup($framed['template']));
        $html = (new InnerBlocks())->renderProductLoop($attributes, '', new \WP_Block(parse_blocks($markup)[0], ['fluent-cart/per_page' => 100]));
        $this->assertMatchesRegularExpression('/fct-card-layout-footer.*data-fct-card-quick-view.*data-position="button"/s', $html, 'The band footer renders its Quick View beside the button');
        // Floating has no Quick View of its own.
        $this->assertStringNotContainsString('product-quick-view', json_encode(ProductCardLayoutPresets::all()['floating']['template']));
    }

    public function test_clean_shows_quick_view_on_the_image_on_hover(): void
    {
        $compact = ProductCardLayoutPresets::all()['compact'];
        $onImage = ['position' => 'image', 'show_on' => 'hover', 'display' => 'icon_text'];
        $this->assertSame($onImage, $compact['attributes']['fluent-cart/product-quick-view']);
        // Inside the image block, with the badges, as the Masonry layout has it too.
        foreach (['compact', 'masonry'] as $layout) {
            $media = ProductCardLayoutPresets::all()[$layout]['template'][0][2][0][2];
            $this->assertSame(['fluent-cart/shopapp-product-image'], array_column($media, 0), $layout);
            $this->assertContains('fluent-cart/product-quick-view', array_column($media[0][2], 0), $layout);
            $quickView = $media[0][2][array_search('fluent-cart/product-quick-view', array_column($media[0][2], 0), true)];
            $this->assertSame($onImage, $quickView[1], $layout);
        }
        // The Classic card holds it in its image block too.
        $classic = ProductCardLayoutPresets::all()['classic']['template'];
        $this->assertNotContains('fluent-cart/product-quick-view', array_column($classic, 0));
        $this->assertContains('fluent-cart/product-quick-view', array_column($classic[0][2], 0));

        $product = (new ProductFactory())->createWithVariants([['variation_title' => 'Six'], ['variation_title' => 'Twelve']], ['post_title' => 'Clean quick view product']);
        \FluentCart\App\Models\ProductDetail::query()->where('post_id', $product->ID)->update(['variation_type' => 'simple_variations']);
        $attributes = ['wp_client_id' => 'layout-clean-quick-view'];
        $markup = get_comment_delimited_block_content('fluent-cart/shopapp-product-loop', $attributes, $this->cardPresetMarkup($compact['template']));
        $html = (new InnerBlocks())->renderProductLoop($attributes, '', new \WP_Block(parse_blocks($markup)[0], ['fluent-cart/per_page' => 100]));
        $this->assertMatchesRegularExpression('/fct-product-image-wrap.*fct-product-image-inner-blocks.*data-fct-card-quick-view[^>]*data-position="image"[^>]*data-show="hover"[^>]*data-image-position="bottom-center"/s', $html);
        $this->assertStringContainsString('is-icon-text', $html, 'Clean shows the eye with its "Quick View" label');
    }

    public function test_clean_and_floating_set_their_grid_two_options_and_caption(): void
    {
        $presets = ProductCardLayoutPresets::all();
        $expected = [
            // Clean: "OPTION · 6 EGGS" above the options.
            'compact' => ['max_visible' => 2, 'simple_max_visible' => 2, 'show_label' => true, 'show_group_name' => true, 'simple_show_label' => true],
            // Floating: the options alone, no caption.
            'floating' => ['max_visible' => 2, 'simple_max_visible' => 2, 'show_label' => false, 'show_group_name' => false, 'simple_show_label' => false],
        ];
        foreach ($expected as $key => $swatches) {
            $this->assertSame(['view_mode' => 'grid', 'product_box_grid_size' => 3], $presets[$key]['products'], $key);
            $this->assertSame($swatches, $presets[$key]['attributes']['fluent-cart/product-swatches'], $key);
            $this->assertSame($swatches, $presets[$key]['template'][0][2][1][2][2][1], $key . ' template carries the same settings');
        }
    }

    public function test_layout_groups_are_named_in_the_list_view(): void
    {
        // Without a name every group reads "Group" in the editor's list view.
        $expected = ['fct-card-layout-media' => 'Card Image', 'fct-card-layout-details' => 'Card Details', 'fct-card-layout-meta' => 'Card Meta', 'fct-card-layout-footer' => 'Card Footer'];
        foreach (['compact', 'floating', 'framed'] as $layout) {
            $names = [];
            $walk = function ($nodes) use (&$walk, &$names) {
                foreach ($nodes as $node) {
                    if ($node[0] === 'core/group' && isset($node[1]['className'])) {
                        $names[$node[1]['className']] = $node[1]['metadata']['name'] ?? null;
                    }
                    $walk($node[2] ?? []);
                }
            };
            $walk(ProductCardLayoutPresets::all()[$layout]['template']);
            foreach ($expected as $class => $name) {
                $this->assertSame($name, $names[$class] ?? null, $layout . ' ' . $class);
            }
        }
    }

    public function test_card_elements_are_only_insertable_inside_a_card_container(): void
    {
        $registry = \WP_Block_Type_Registry::get_instance();
        foreach (['fluent-cart/shopapp-product-title', 'fluent-cart/shopapp-product-price', 'fluent-cart/shopapp-product-image', 'fluent-cart/shopapp-product-buttons'] as $name) {
            $ancestor = $registry->get_registered($name)->ancestor;
            $this->assertContains('fluent-cart/shopapp-product-loop', $ancestor, $name);
            // The Products root also holds filters and no-result groups, which have no current product.
            $this->assertNotContains('fluent-cart/products', $ancestor, $name);
            $this->assertNotContains('core/columns', $ancestor, $name);
        }
    }

    public function test_layout_options_apply_a_builders_swatch_and_badge_settings(): void
    {
        $blockNames = function (array $block) use (&$blockNames): array {
            $names = [$block['blockName']];
            foreach ($block['innerBlocks'] as $child) {
                $names = array_merge($names, $blockNames($child));
            }
            return $names;
        };
        $swatchAttrs = function (array $block) use (&$swatchAttrs) {
            if ($block['blockName'] === 'fluent-cart/product-swatches') {
                return $block['attrs'];
            }
            foreach ($block['innerBlocks'] as $child) {
                $found = $swatchAttrs($child);
                if ($found !== null) {
                    return $found;
                }
            }
            return null;
        };

        foreach (['classic', 'compact'] as $layout) {
            $card = ProductCardLayoutPresets::cardBlock($layout);
            $this->assertSame($card, ProductCardLayoutPresets::withCardOptions($card, []), $layout . ': no options leave the layout as designed');

            $trimmed = ProductCardLayoutPresets::withCardOptions($card, ['swatches' => false, 'sale_badge' => false, 'sold_out_badge' => false, 'rating' => 'false']);
            $names = $blockNames($trimmed);
            $this->assertNotContains('fluent-cart/product-swatches', $names, $layout . ': swatches off drops the block');
            $this->assertNotContains('fluent-cart/sale-badge', $names, $layout . ': Sale badge off drops it');
            $this->assertNotContains('fluent-cart/sold-out-badge', $names, $layout . ': Sold Out badge off drops it');
            $this->assertNotContains('fluent-cart/product-rating', $names, $layout . ': rating off drops it, as a query string sends it');
            $this->assertContains('fluent-cart/shopapp-product-buttons', $names, $layout . ': the rest of the card stays');
            $this->assertNotEmpty(render_block($trimmed), $layout . ': the trimmed card still renders');

            $set = $swatchAttrs(ProductCardLayoutPresets::withCardOptions($card, ['swatches' => [
                'max_visible' => 99, 'show_group_name' => true, 'simple_label' => '<b>Pack</b>', 'group_ids' => ['7', 0, 7, 'x'], 'unknown' => 'kept out',
            ]]));
            $this->assertSame(12, $set['max_visible'], $layout . ': Max Swatches stays in the block\'s 1-12 range');
            $this->assertTrue($set['show_group_name']);
            $this->assertSame('Pack', $set['simple_label'], $layout . ': labels are plain text');
            $this->assertSame([7], $set['group_ids'], $layout . ': group ids are unique positive ids');
            $this->assertArrayNotHasKey('unknown', $set, $layout . ': only swatch settings are taken');
        }

        // Through the card render, as a builder calls it.
        $product = (new ProductFactory())->create(['post_title' => 'Layout options sale product', 'post_status' => 'publish']);
        \FluentCart\App\Models\ProductVariation::query()->where('post_id', $product->ID)->update(['compare_price' => 9900, 'item_price' => 4500]);
        $product = \FluentCart\App\Models\Product::query()->with(['detail', 'variants'])->find($product->ID);
        $render = function (array $config) use ($product): string {
            ob_start();
            (new \FluentCart\App\Services\Renderer\ProductCardRender($product, $config))->render();
            return (string) ob_get_clean();
        };
        $this->assertStringContainsString('fct-sale-badge', $render(['card_layout' => 'compact']), 'a layout shows its Sale badge by default');
        $this->assertStringNotContainsString('fct-sale-badge', $render(['card_layout' => 'compact', 'layout_options' => ['sale_badge' => false]]), 'layout_options turns it off');
    }

    public function test_layout_options_pass_the_swatches_blocks_selected_choice_and_hidden_attribute_settings(): void
    {
        $swatchAttrs = static function (array $options): array {
            $find = static function (array $block) use (&$find) {
                if ($block['blockName'] === 'fluent-cart/product-swatches') {
                    return $block['attrs'];
                }
                foreach ($block['innerBlocks'] as $child) {
                    if (($found = $find($child)) !== null) {
                        return $found;
                    }
                }
                return null;
            };
            return $find(ProductCardLayoutPresets::withCardOptions(ProductCardLayoutPresets::cardBlock('compact'), ['swatches' => $options]));
        };

        $set = $swatchAttrs([
            'show_other_groups' => 'false', 'simple_show_selected' => '1',
            'selected_position' => 'below', 'simple_selected_position' => 'below',
            'hidden_group_ids' => ['7', 7, 0, 'x', '9'],
        ]);
        $this->assertFalse($set['show_other_groups']);
        $this->assertTrue($set['simple_show_selected']);
        $this->assertSame('below', $set['selected_position']);
        $this->assertSame('below', $set['simple_selected_position']);
        $this->assertSame([7, 9], $set['hidden_group_ids'], 'unique positive ids');

        $bad = $swatchAttrs(['show_other_groups' => '', 'selected_position' => 'above', 'simple_selected_position' => ['below']]);
        $this->assertArrayNotHasKey('show_other_groups', $bad, 'unset keeps the old rule');
        $this->assertArrayNotHasKey('simple_show_selected', $bad);
        $this->assertArrayNotHasKey('selected_position', $bad, 'only name or below');
        $this->assertArrayNotHasKey('simple_selected_position', $bad);
        $this->assertCount(1000, $swatchAttrs(['hidden_group_ids' => range(1, 5000)])['hidden_group_ids'], 'input is bounded');
    }

    public function test_swatch_counts_follow_the_swatches_block(): void
    {
        $count = static function ($value): int {
            $card = ProductCardLayoutPresets::cardBlock('compact');
            $card = ProductCardLayoutPresets::withCardOptions($card, ['swatches' => ['max_visible' => $value, 'simple_max_visible' => $value]]);
            $find = static function (array $block) use (&$find) {
                if ($block['blockName'] === 'fluent-cart/product-swatches') {
                    return $block['attrs'];
                }
                foreach ($block['innerBlocks'] as $child) {
                    if (($found = $find($child)) !== null) {
                        return $found;
                    }
                }
                return null;
            };
            $attributes = $find($card);
            return $attributes['max_visible'];
        };

        $this->assertSame(0, $count(0), '0 is All, as the block saves it');
        $this->assertSame(0, $count('0'), 'a query string sends 0 as text');
        $this->assertSame(7, $count(7), 'the block offers up to 12, not 6');
        $this->assertSame(12, $count(12));
        $this->assertSame(12, $count(13), 'more than 12 stays at 12');
        $this->assertSame(1, $count(-3), 'a negative count is one option');
        $this->assertSame(1, $count('x'), 'text is one option');
    }

    public function test_layout_elements_leave_out_and_reorder_parts_within_their_area(): void
    {
        $children = function (array $block, string $className) use (&$children) {
            if (strpos((string) ($block['attrs']['className'] ?? ''), $className) !== false) {
                return array_column($block['innerBlocks'], 'blockName');
            }
            foreach ($block['innerBlocks'] as $child) {
                if (($found = $children($child, $className)) !== null) {
                    return $found;
                }
            }
            return null;
        };
        $this->assertSame(['image', 'category', 'rating', 'title', 'swatches', 'price', 'button'], ProductCardLayoutPresets::cardParts('floating'), 'Floating has no Quick View');
        $this->assertSame(['price', 'button', 'quick_view'], array_slice(ProductCardLayoutPresets::cardParts('framed'), -3), 'Framed has Quick View beside the button');
        $this->assertSame([], ProductCardLayoutPresets::cardParts('not-a-layout'));

        $card = ProductCardLayoutPresets::cardBlock('compact');
        // Price is listed before Title, but they sit in different areas, so only the footer row changes.
        $trimmed = ProductCardLayoutPresets::withCardOptions($card, ['elements' => ['button', 'image', 'price', 'title', 'quick_view', 'category', 'x', ['image']]]);
        $this->assertSame(['fluent-cart/shopapp-product-buttons', 'fluent-cart/shopapp-product-price'], $children($trimmed, 'fct-card-layout-footer'));
        $this->assertSame(['core/post-terms'], $children($trimmed, 'fct-card-layout-meta'), 'Rating left out');
        $this->assertSame(['core/group', 'fluent-cart/shopapp-product-title', 'core/group'], $children($trimmed, 'fct-card-layout-details'), 'Swatches left out');
        $this->assertNotEmpty(render_block($trimmed));

        $noMeta = ProductCardLayoutPresets::withCardOptions($card, ['elements' => ['image', 'title', 'price', 'button']]);
        $this->assertNull($children($noMeta, 'fct-card-layout-meta'), 'an area with none of its parts is left out');
        $this->assertSame($card, ProductCardLayoutPresets::withCardOptions($card, ['elements' => ['nothing-known']]), 'no known part keeps the layout as designed');
    }

    public function test_layout_part_settings_reach_the_rating_and_quick_view_blocks(): void
    {
        $attrs = function (array $block, string $name) use (&$attrs) {
            if ($block['blockName'] === $name) {
                return $block['attrs'];
            }
            foreach ($block['innerBlocks'] as $child) {
                if (($found = $attrs($child, $name)) !== null) {
                    return $found;
                }
            }
            return null;
        };
        $set = ProductCardLayoutPresets::withCardOptions(ProductCardLayoutPresets::cardBlock('compact'), [
            'rating'     => ['minReviewCount' => '3', 'minAverageRating' => 9, 'unknown' => 'x'],
            'quick_view' => ['position' => 'button', 'show_on' => 'sideways', 'display' => 'icon_text'],
        ]);
        $this->assertSame(['minReviewCount' => 3, 'minAverageRating' => 5.0], $attrs($set, 'fluent-cart/product-rating'), 'numbers are kept in range; unknown keys are left out');
        $quickView = $attrs($set, 'fluent-cart/product-quick-view');
        $this->assertSame(['button', 'icon_text', 'hover'], [$quickView['position'], $quickView['display'], $quickView['show_on']], 'an unknown Show On keeps the layout\'s');

        // A layout's own block settings, as builders pre-fill them.
        $this->assertSame(2, ProductCardLayoutPresets::partAttributes('compact', 'swatches')['max_visible']);
        $this->assertFalse(ProductCardLayoutPresets::partAttributes('floating', 'swatches')['show_label']);
        $this->assertSame('button', ProductCardLayoutPresets::partAttributes('framed', 'quick_view')['position']);
        $this->assertSame('image', ProductCardLayoutPresets::partAttributes('compact', 'quick_view')['position']);
        $this->assertSame([], ProductCardLayoutPresets::partAttributes('floating', 'quick_view'), 'Floating has no Quick View');
        $this->assertSame([], ProductCardLayoutPresets::partAttributes('compact', 'rating'), 'the rating block keeps its own defaults');
    }

    public function test_the_classic_layout_takes_a_card_elements_list_in_any_order(): void
    {
        // Quick View sits in the image block, yet stays last in the builders' list.
        $this->assertSame(['image', 'title', 'rating', 'price', 'swatches', 'button', 'quick_view'], ProductCardLayoutPresets::classicCardParts());
        $this->assertSame([], ProductCardLayoutPresets::cardParts('classic'), 'Classic is not one of the layouts\' lists');

        // Classic's parts share one area, so any order holds.
        $card = ProductCardLayoutPresets::withCardOptions(ProductCardLayoutPresets::cardBlock('classic'), ['elements' => ['price', 'title', 'image', 'button']]);
        $this->assertSame(['fluent-cart/price-range', 'fluent-cart/product-title', 'fluent-cart/product-image', 'fluent-cart/shopapp-product-buttons'], array_column($card['innerBlocks'], 'blockName'));
        $this->assertNotEmpty(render_block($card));
    }

    public function test_a_builders_own_card_renders_a_layout_part_with_its_settings(): void
    {
        $product = (new ProductFactory())->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([['item_price' => 1000], ['item_price' => 2000]], ['post_title' => 'Card part product', 'post_status' => 'publish']);
        $card = new \FluentCart\App\Services\Renderer\ProductCardRender($product);

        $this->assertSame('', $card->renderCardPart('title'), 'only Rating, Swatches and Quick View');
        $default = $card->renderCardPart('quick_view');
        $this->assertStringContainsString('data-position="image"', $default, 'the Classic layout\'s Quick View by default');
        $this->assertStringContainsString('data-show="hover"', $default);
        $moved = $card->renderCardPart('quick_view', ['position' => 'button', 'display' => 'huge']);
        $this->assertStringContainsString('data-position="button"', $moved);
        $this->assertStringContainsString('is-icon"', $moved, 'beside the button it is an icon');
        $this->assertNotSame($product->ID, isset($GLOBALS['post']) ? $GLOBALS['post']->ID : null, 'the page\'s own post is put back');
        $this->assertTrue(ProductCardLayoutPresets::partAttributes('classic', 'quick_view') !== [], 'Classic has its own Quick View values');
    }

    public function test_layout_badge_settings_set_the_text_shape_and_position(): void
    {
        $product = (new ProductFactory())->create(['post_title' => 'Layout badge product', 'post_status' => 'publish']);
        \FluentCart\App\Models\ProductVariation::query()->where('post_id', $product->ID)->update(['compare_price' => 9900, 'item_price' => 4500]);
        $product = \FluentCart\App\Models\Product::query()->with(['detail', 'variants'])->find($product->ID);
        $render = function (array $saleBadge) use ($product): string {
            ob_start();
            (new \FluentCart\App\Services\Renderer\ProductCardRender($product, ['card_layout' => 'compact', 'layout_options' => ['sale_badge' => $saleBadge]]))->render();
            return (string) ob_get_clean();
        };

        $html = $render(['badge_text' => '<b>Deal</b>', 'badge_style' => 'ribbon', 'badge_position' => 'bottom-right', 'unknown' => 'x']);
        $this->assertStringContainsString('fct-sale-badge--ribbon fct-sale-badge--bottom-right', $html);
        $this->assertMatchesRegularExpression('/fct-sale-badge[^>]*>\s*Deal\s*</', $html, 'the text is plain');

        $html = $render(['badge_style' => 'huge', 'badge_position' => 'middle', 'show_percentage' => 'true', 'percentage_text' => '{percent}% off']);
        $this->assertStringContainsString('fct-sale-badge--badge fct-sale-badge--top-left', $html, 'unknown values keep the layout\'s');
        $this->assertStringContainsString('55% off', $html);
    }

    public function test_only_the_floating_layout_hides_the_grid_list_switch(): void
    {
        $hiding = array_keys(array_filter(
            ProductCardLayoutPresets::all(),
            static fn ($preset) => ($preset['view_switcher'] ?? true) === false
        ));
        $this->assertSame(['floating'], $hiding);
    }

    public function test_a_saved_floating_card_hides_the_switch_and_shows_the_grid_on_the_frontend(): void
    {
        $count = function (string $layout): int {
            return substr_count(do_blocks($this->productsMarkup($layout)), 'fct-shop-view-switcher"');
        };
        // A real Products block, so the context has to reach the switcher through the action container.
        $this->assertSame(0, $count('floating'));
        $this->assertSame(1, $count('classic'));

        $floating = new \WP_Block(parse_blocks($this->productsMarkup('floating'))[0], []);
        $context = (new InnerBlocks())->hideViewSwitcherContext(['fluent-cart/view_mode' => 'list'], [], $floating);
        $this->assertSame('grid', $context['fluent-cart/view_mode']);
        $classic = new \WP_Block(parse_blocks($this->productsMarkup('classic'))[0], []);
        $context = (new InnerBlocks())->hideViewSwitcherContext(['fluent-cart/view_mode' => 'list'], [], $classic);
        $this->assertSame('list', $context['fluent-cart/view_mode']);
    }

    public function test_a_floating_card_keeps_the_filter_toggle_without_the_switch(): void
    {
        $html = do_blocks($this->productsMarkup('floating', ['enable_filter' => true]));
        $this->assertStringContainsString('data-fluent-cart-shop-app-filter-toggle-button', $html, 'phones open the filters with it');
        $this->assertStringNotContainsString('data-fluent-cart-shop-app-grid-view-button', $html);
    }

    public function test_the_frontend_follows_the_first_loops_first_card_like_the_editor(): void
    {
        $card = static function (string $layout): string {
            return get_comment_delimited_block_content('fluent-cart/product-card', ['template_version' => 1, 'card_layout' => $layout], '');
        };
        $loop = static function (string $cards): string {
            return get_comment_delimited_block_content('fluent-cart/shopapp-product-loop', [], $cards);
        };
        $hides = function (string $inner): bool {
            $block = new \WP_Block(parse_blocks(get_comment_delimited_block_content('fluent-cart/products', [], $inner))[0], []);
            return (new InnerBlocks())->hideViewSwitcherContext([], [], $block)['fluent-cart/hide_view_switcher'];
        };
        $this->assertFalse($hides($loop($card('classic')) . $loop($card('floating'))), 'a later loop does not decide');
        $this->assertTrue($hides($loop($card('floating')) . $loop($card('classic'))));
        $this->assertFalse($hides(''), 'no loop, no card');
        // Gutenberg lists a block's descendants depth first, so a loop nested in an earlier sibling is the first.
        $group = static function (string $inner): string {
            return get_comment_delimited_block_content('core/group', [], $inner);
        };
        $this->assertTrue($hides($group($group($loop($card('floating')))) . $loop($card('classic'))));
        $this->assertFalse($hides($group($group($loop($card('classic')))) . $loop($card('floating'))));
    }
}
