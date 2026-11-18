<?php

namespace Tests\Integration\Templating;

use FluentCart\App\Models\Product;
use FluentCart\App\Modules\Templating\Bricks\Elements\ProductsCollection;
use FluentCart\App\Services\Renderer\ProductCardLayoutPresets;
use lucatume\WPBrowser\TestCase\WPTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * The Bricks Products element's Card Layout: core's layouts in place of the Fields card.
 */
class BricksProductsCardLayoutTest extends WPTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/Support/Stubs/BricksElementStubs.php';
    }

    private function element(array $settings = []): ProductsCollection
    {
        $element = new ProductsCollection();
        $element->settings = $settings;

        return $element;
    }

    private function controlsOf(): array
    {
        $element = $this->element();
        $element->set_control_groups();
        $element->set_controls();

        return $element->controls;
    }

    private function renderCards(ProductsCollection $element, $products, string $priceFormat = 'starts_from', array $layoutOptions = []): string
    {
        $select = new \ReflectionMethod($element, 'selectedCardLayout');
        $select->setAccessible(true);
        $render = new \ReflectionMethod($element, 'renderProducts');
        $render->setAccessible(true);

        ob_start();
        $render->invoke($element, $products, $select->invoke($element), $priceFormat, $layoutOptions);

        return (string) ob_get_clean();
    }

    public function test_the_picker_lists_cores_layouts_and_hides_the_fields_card_settings(): void
    {
        $element = $this->element();
        $element->set_control_groups();
        $element->set_controls();

        // Classic is the Fields card, picked again from the list; core's Classic preset is not offered.
        // A builder-only layout (Masonry) is a switch now, not a layout.
        $pickable = array_keys(array_filter(ProductCardLayoutPresets::all(), function ($preset) {
            return empty($preset['builder_only']);
        }));
        $this->assertSame(array_merge(['classic'], array_values(array_diff($pickable, ['classic']))), array_keys($element->controls['cardLayout']['options']));
        $classic = ['cardLayout', '=', ['', 'classic']];
        $layout = ['cardLayout', '!=', ['', 'classic']];
        $this->assertSame($layout, $element->controls['layoutPriceFormat']['required']);
        $this->assertSame($classic, $element->control_groups['fields']['required'] ?? null, 'Fields belongs to the Classic card');
        foreach (['sale_badge', 'sale_badge_style', 'sold_out_badge', 'sold_out_badge_style'] as $group) {
            $this->assertArrayNotHasKey('required', $element->control_groups[$group], $group . ' shows for every layout');
        }
    }

    public function test_a_layout_has_the_classic_cards_settings_with_its_own_values(): void
    {
        $element = $this->element();
        $element->set_control_groups();
        $element->set_controls();
        $classic = ['cardLayout', '=', ['', 'classic']];
        $layout = ['cardLayout', '!=', ['', 'classic']];

        // Each layout lists its own parts, in its own order.
        foreach (['compact', 'floating', 'framed'] as $name) {
            $control = $element->controls['layoutElements_' . $name];
            $parts = ProductCardLayoutPresets::cardParts($name);
            $this->assertSame(['repeater', 'display', ['cardLayout', '=', $name]], [$control['type'], $control['group'], $control['required']], $name);
            $this->assertSame($parts, array_column($control['default'], 'element_type'), $name . ': the layout as designed');
            $this->assertSame($parts, array_keys($control['fields']['element_type']['options']), $name . ': only its own parts can be added');
        }
        $this->assertSame(['image', 'quick_view', 'category', 'rating', 'title', 'swatches', 'price', 'button'], ProductCardLayoutPresets::cardParts('compact'));

        // Classic's badge settings are the Classic card's; a layout has the same ones, on by default.
        $this->assertSame([['showSaleBadge', '=', true], $classic], $element->controls['saleBadgeText']['required']);
        $this->assertSame($classic, $element->controls['showSaleBadge']['required']);
        $this->assertSame([true, $layout, 'sale_badge'], [$element->controls['layoutShowSaleBadge']['default'], $element->controls['layoutShowSaleBadge']['required'], $element->controls['layoutShowSaleBadge']['group']]);
        $this->assertSame(true, $element->controls['layoutShowSoldOutBadge']['default']);
        $this->assertSame([['layoutShowSaleBadge', '=', true], $layout], $element->controls['layoutSaleBadgeText']['required']);
        $this->assertSame([['layoutShowSaleBadge', '=', true], ['layoutSaleBadgeShowPercentage', '=', true], $layout], $element->controls['layoutSaleBadgePercentageText']['required']);
        foreach (['layoutSaleBadgeText' => 'Sale!', 'layoutSaleBadgeShape' => 'badge', 'layoutSaleBadgePosition' => 'top-left', 'layoutSoldOutBadgeText' => 'Out of Stock'] as $key => $default) {
            $this->assertSame($default, $element->controls[$key]['default'], $key . ': the layout\'s own value');
        }
        $this->assertSame('sale_badge_style', $element->controls['layoutSaleBadgeBackgroundColor']['group']);
        $this->assertSame($element->controls['saleBadgeBackgroundColor']['css'], $element->controls['layoutSaleBadgeBackgroundColor']['css']);
        $this->assertArrayNotHasKey('layoutShowSwatches', $element->controls);
        $this->assertArrayNotHasKey('layoutShowRating', $element->controls);
    }

    public function test_a_layouts_rating_swatches_and_quick_view_have_their_own_settings(): void
    {
        $element = $this->element();
        $element->set_control_groups();
        $element->set_controls();
        $controls = $element->controls;

        foreach (['layoutSwatches' => 'content', 'layoutRating' => 'content', 'layoutQuickView' => 'content', 'layoutSwatchesStyle' => 'style', 'layoutRatingStyle' => 'style', 'layoutQuickViewStyle' => 'style'] as $group => $tab) {
            $this->assertSame($tab, $element->control_groups[$group]['tab'] ?? null, $group);
        }
        $this->assertSame(['cardLayout', '=', ['', 'classic', 'compact', 'framed']], $element->control_groups['layoutQuickView']['required'], 'Quick View shows for Classic and the layouts that have one');

        // Pre-filled with each layout's own values.
        $this->assertSame([2, true, [['cardLayout', '=', 'compact'], ['layoutSwatchesAdvanced_compact', '=', true]]], [$controls['layoutSwatchesMaxVisible_compact']['default'], $controls['layoutSwatchesSelectedName_compact']['default'], $controls['layoutSwatchesSelectedName_compact']['required']]);
        $this->assertFalse($controls['layoutSwatchesSelectedName_floating']['default'], 'Floating hides the selected choice by default');
        $this->assertSame(['image', 'button'], [$controls['layoutQuickViewPosition_compact']['default'], $controls['layoutQuickViewPosition_framed']['default']]);
        $this->assertArrayNotHasKey('layoutQuickViewPosition_floating', $controls);
        // Beside the button it is always an icon; a position not saved yet reads as the layout's.
        $this->assertSame([['cardLayout', '=', 'compact'], ['layoutQuickViewPosition_compact', '!=', 'button']], $controls['layoutQuickViewShowOn_compact']['required']);
        $this->assertSame([['cardLayout', '=', 'framed'], ['layoutQuickViewPosition_framed', '=', 'image']], $controls['layoutQuickViewDisplay_framed']['required']);
        $this->assertSame(0, $controls['layoutRatingMinReviewCount_framed']['default']);
        // Quick View on the image has the block's six corners, bottom middle by default, shown only on the image.
        $this->assertSame('bottom-center', $controls['layoutQuickViewImagePosition_compact']['default']);
        $this->assertSame(['top-left', 'top-center', 'top-right', 'bottom-left', 'bottom-center', 'bottom-right'], array_keys($controls['layoutQuickViewImagePosition_compact']['options']));
        $this->assertSame([['cardLayout', '=', 'compact'], ['layoutQuickViewPosition_compact', '!=', 'button']], $controls['layoutQuickViewImagePosition_compact']['required'], 'shown on the image only');
        $this->assertSame('layoutRatingStyle', $controls['layoutRatingStarColor']['group']);

        $settings = [
            'cardLayout'                          => 'compact',
            'layoutShowSaleBadge'                 => true,
            'layoutShowSoldOutBadge'              => true,
            // Saved for Compact; a switch not saved is off, as its panel shows it.
            'layoutSwatchesMaxVisible_compact'    => 4,
            'layoutSwatchesGroupName_compact'     => true,
            // A switch not saved is off, so Simple and the framed Selected Name need no entry.
            'layoutQuickViewPosition_compact'     => 'button',
            'layoutRatingMinReviewCount_compact'  => 2,
        ];
        $options = \FluentCart\App\Modules\Templating\Bricks\LayoutCardControls::cardOptions($settings);
        $off = ['advanced_enabled' => false, 'show_group_name' => false, 'show_label' => false, 'show_other_groups' => false, 'simple_enabled' => false, 'simple_show_label' => false, 'simple_show_selected' => false];
        $saved = $options['swatches'];
        ksort($saved);
        $expected = ['max_visible' => 4, 'show_group_name' => true] + $off;  // the saved Show label wins over the off default
        ksort($expected);
        $this->assertSame($expected, $saved, 'this layout\'s saved settings, and the switches it does not hold off');
        $this->assertSame(['position' => 'button'], $options['quick_view']);
        $choices = \FluentCart\App\Modules\Templating\Bricks\LayoutCardControls::cardOptions(['cardLayout' => 'compact', 'layoutSwatchesSoldOutDisplay_compact' => 'cross', 'layoutSwatchesSelectedPosition_compact' => 'below', 'layoutSwatchesShowOtherGroups_compact' => 'yes']);
        $reached = array_intersect_key($choices['swatches'] ?? [], array_flip(['sold_out_display', 'selected_position', 'show_other_groups']));
        ksort($reached);
        $this->assertSame(['selected_position' => 'below', 'show_other_groups' => true, 'sold_out_display' => 'cross'], $reached, 'the choices reach the card');
        $all = \FluentCart\App\Modules\Templating\Bricks\LayoutCardControls::cardOptions(['cardLayout' => 'compact', 'layoutSwatchesMaxVisible_compact' => 0]);
        $this->assertSame(0, $all['swatches']['max_visible'] ?? null, 'All reaches the card as 0');
        $corner = \FluentCart\App\Modules\Templating\Bricks\LayoutCardControls::cardOptions(['cardLayout' => 'compact', 'layoutQuickViewImagePosition_compact' => 'top-right']);
        $this->assertSame('top-right', $corner['quick_view']['image_position'] ?? null, 'the corner reaches the card');
        $this->assertSame(['minReviewCount' => 2], $options['rating']);

        $legacy = \FluentCart\App\Modules\Templating\Bricks\LayoutCardControls::cardOptions(['cardLayout' => 'compact', 'layoutShowSaleBadge' => true, 'layoutShowSoldOutBadge' => true]);
        ksort($off);
        $swatchesOff = $legacy['swatches'] ?? [];
        ksort($swatchesOff);
        $this->assertSame(['swatches' => $off], ['swatches' => $swatchesOff], 'an element with no swatch settings shows none, as its panel reads; its other parts are unchanged');
        $this->assertSame(['swatches'], array_keys($legacy));
    }

    public function test_the_classic_fields_card_has_rating_and_quick_view_with_their_settings(): void
    {
        $element = $this->element();
        $element->set_control_groups();
        $element->set_controls();

        foreach (['layoutRating', 'layoutQuickView', 'layoutQuickViewStyle'] as $group) {
            $this->assertSame('', $element->control_groups[$group]['required'][2][0] ?? null, $group . ' shows for Classic too');
        }
        $this->assertSame(['image', ['cardLayout', '=', ['', 'classic']]], [$element->controls['layoutQuickViewPosition_classic']['default'], $element->controls['layoutQuickViewPosition_classic']['required']], 'Classic\'s own Quick View value');
        // A new element's Fields: Rating after the title, Quick View last. One saved before keeps its own.
        $fields = array_column($element->controls['fields']['default'], 'dynamicData');
        $this->assertSame(['{fct_product_image:link}', '{fct_product_title:linked}', '{fct_product_rating}', '{fct_product_excerpt}', '{fct_product_price}', '{fct_product_button}', '{fct_product_quick_view}'], $fields);

        $product = (new ProductFactory())->withDetailAttributes(['variation_type' => 'simple_variations'])
            ->createWithVariants([['item_price' => 1000], ['item_price' => 2000]], ['post_title' => 'Fields tag product', 'post_status' => 'publish']);
        $tags = new \FluentCart\App\Modules\Templating\Bricks\DynamicData();
        $this->assertArrayHasKey('fct_product_quick_view', $tags->getTagsPairs());
        $this->assertArrayHasKey('fct_product_rating', $tags->getTagsPairs());
        $post = get_post($product->ID);
        \FluentCart\App\Modules\Templating\Bricks\BricksHelper::$imageBadgeSettings = [];
        $this->assertStringContainsString('data-position="image"', $tags->renderValue('{fct_product_quick_view}', $post), 'Classic\'s value when nothing is saved');
        // The element's settings while its card fields render.
        \FluentCart\App\Modules\Templating\Bricks\BricksHelper::$imageBadgeSettings = ['layoutQuickViewPosition_classic' => 'button'];
        $this->assertStringContainsString('data-position="button"', $tags->renderValue('{fct_product_quick_view}', $post));
        \FluentCart\App\Modules\Templating\Bricks\BricksHelper::$imageBadgeSettings = [];
    }

    public function test_classic_has_a_card_elements_list_and_an_empty_one_keeps_the_fields_card(): void
    {
        $element = $this->element();
        $element->set_control_groups();
        $element->set_controls();
        $classic = ['cardLayout', '=', ['', 'classic']];
        $list = $element->controls['layoutElements_classic'];
        $this->assertSame(['repeater', 'display', $classic], [$list['type'], $list['group'], $list['required']]);
        $this->assertSame(ProductCardLayoutPresets::classicCardParts(), array_column($list['default'], 'element_type'), 'a new element starts as core\'s Classic layout');
        $this->assertArrayHasKey('layoutSwatchesMaxVisible_classic', $element->controls, 'Classic has its own Swatches settings');
        $this->assertSame($classic, $element->control_groups['fields']['required'], 'Fields stays for a Classic card without Card Elements');

        $select = new \ReflectionMethod(ProductsCollection::class, 'selectedCardLayout');
        $select->setAccessible(true);
        $rows = [['element_type' => 'image'], ['element_type' => 'title'], ['element_type' => 'price']];
        $this->assertSame('', $select->invoke($this->element()), 'one saved before keeps its Fields card');
        $this->assertSame('', $select->invoke($this->element(['layoutElements_classic' => []])), 'an emptied list goes back to Fields');
        $this->assertSame('classic', $select->invoke($this->element(['layoutElements_classic' => $rows])));
        $this->assertSame('compact', $select->invoke($this->element(['cardLayout' => 'compact', 'layoutElements_classic' => $rows])));

        // Classic's own badge settings, as its Fields card reads them.
        $settings = ['layoutElements_classic' => $rows, 'showSaleBadge' => true, 'saleBadgeText' => 'Deal', 'saleBadgeShape' => 'ribbon'];
        $options = \FluentCart\App\Modules\Templating\Bricks\LayoutCardControls::cardOptions($settings, true, 'classic');
        $this->assertSame(['image', 'title', 'price'], $options['elements']);
        $this->assertSame(['badge_text' => 'Deal', 'badge_style' => 'ribbon'], $options['sale_badge']);
        $this->assertFalse($options['sold_out_badge'], 'Show Sold Out Badge not ticked');

        $product = Product::query()->find((new ProductFactory())->create(['post_title' => 'Classic List Card'])->ID);
        \FluentCart\App\Models\ProductVariation::query()->where('post_id', $product->ID)->update(['compare_price' => 9900, 'item_price' => 4500]);
        $product = Product::query()->with(['detail', 'variants'])->find($product->ID);
        $html = $this->renderCards($this->element($settings), [$product], 'starts_from', $options);
        $this->assertStringContainsString('Classic List Card', $html);
        $this->assertStringContainsString('fct-sale-badge--ribbon', $html);
        $this->assertStringNotContainsString("data-template-provider='bricks'", $html, 'core\'s card, so AJAX pages draw the same');
    }

    public function test_a_product_list_can_draw_each_card_itself(): void
    {
        $products = [Product::query()->find((new ProductFactory())->create(['post_title' => 'List Card Alpha'])->ID)];
        ob_start();
        (new \FluentCart\App\Services\Renderer\ProductListRenderer($products, 'Related', 'fct-similar-product-list-container', [
            'render_card' => function ($product) {
                return '<article class="own-card">' . esc_html($product->post_title) . '</article>';
            },
        ]))->render();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('<article class="own-card">List Card Alpha</article>', $html);
        $this->assertStringContainsString('fct-product-list-item', $html, 'in core\'s list and grid');
        $this->assertStringNotContainsString('data-fluent-cart-shop-app-single-product', $html);
    }

    public function test_unticked_attributes_stay_off_the_card(): void
    {
        $element = $this->element();
        $element->set_control_groups();
        $element->set_controls();
        $control = $element->controls['layoutSwatchesHiddenGroups_compact'] ?? null;

        $this->assertNotNull($control, 'the Swatches panel has the unticked attributes, as Gutenberg\'s list does');
        $this->assertSame(['select', true], [$control['type'], $control['multiple']], 'a multi-select, as the picked attributes');
        $this->assertSame('Never show these attributes', $control['label']);
        $this->assertSame('layoutSwatches', $control['group']);
        $on = true;
        $this->assertSame([['cardLayout', '=', 'compact'], ['layoutSwatchesAdvanced_compact', '=', $on], ['layoutSwatchesShowOtherGroups_compact', '=', $on]], $control['required'], 'only while the other attributes are added: they are what it keeps off');

        $options = \FluentCart\App\Modules\Templating\Bricks\LayoutCardControls::cardOptions([
            'cardLayout' => 'compact', 'layoutSwatchesAdvanced_compact' => true, 'layoutSwatchesShowOtherGroups_compact' => true,
            'layoutSwatchesHiddenGroups_compact' => ['7', '5'],
        ]);
        $this->assertSame(['7', '5'], $options['swatches']['hidden_group_ids'] ?? null, 'they reach the card as hidden_group_ids');

        // An attribute in both lists stays off: two selects can overlap, and the label says never.
        $overlap = \FluentCart\App\Modules\Templating\Bricks\LayoutCardControls::cardOptions([
            'cardLayout' => 'compact', 'layoutSwatchesAdvanced_compact' => true, 'layoutSwatchesShowOtherGroups_compact' => true,
            'layoutSwatchesGroupIds_compact' => ['3', '7', '9'], 'layoutSwatchesHiddenGroups_compact' => ['7'],
        ]);
        $this->assertSame(['3', '9'], $overlap['swatches']['group_ids'] ?? null, 'a picked attribute that is also unticked is dropped from the picks');
        $this->assertSame(['7'], $overlap['swatches']['hidden_group_ids'] ?? null);

        // And the swatch block gets them as ids.
        $block = ProductCardLayoutPresets::withCardOptions(ProductCardLayoutPresets::cardBlock('compact'), $options);
        $found = null;
        $walk = function ($node) use (&$walk, &$found) {
            if (is_array($node)) {
                if (($node['blockName'] ?? '') === 'fluent-cart/product-swatches') {
                    $found = $node['attrs']['hidden_group_ids'] ?? null;
                }
                array_walk($node, function ($child) use (&$walk) {
                    $walk($child);
                });
            }
        };
        $walk($block);
        $this->assertSame([7, 5], $found, 'as a list of ids');
    }

    public function test_the_swatch_settings_read_as_gutenbergs(): void
    {
        $element = $this->element();
        $element->set_control_groups();
        $element->set_controls();
        $controls = $element->controls;
        $keys = array_values(array_filter(array_keys($controls), function ($key) {
            return strpos($key, 'layoutSwatches') === 0 && substr($key, -8) === '_compact';
        }));
        $names = array_map(function ($key) {
            return substr($key, strlen('layoutSwatches'), -strlen('_compact'));
        }, $keys);

        // Gutenberg's two tabs are two stacked sections of the one Swatches group, each opening with its heading.
        $groups = $element->control_groups;
        $this->assertSame(['Swatches', 'content', false], [$groups['layoutSwatches']['title'] ?? null, $groups['layoutSwatches']['tab'] ?? null, isset($groups['layoutSwatchesList'])]);
        $this->assertSame('Swatches', $groups['layoutSwatchesStyle']['title'], 'the Style tab keeps one Swatches group');
        foreach ($keys as $key) {
            $this->assertSame('layoutSwatches', $controls[$key]['group'], $key . ' sits in the Swatches group');
        }
        $this->assertSame(['separator', 'separator'], [$controls['layoutSwatchesAdvancedHeading_compact']['type'], $controls['layoutSwatchesSimpleHeading_compact']['type']], 'each type opens with a heading');
        // Gutenberg's order: Attribute products, then List products, each with its switch and How it shows on the card.
        $this->assertSame(['AdvancedHeading', 'Advanced', 'WhichHeading', 'GroupIds', 'ShowOtherGroups', 'HiddenGroups', 'NoneWarning', 'CardHeading', 'MaxVisible', 'MaxVisibleAll', 'GroupName', 'SelectedName', 'SelectedPosition', 'SimpleHeading', 'Simple', 'SimpleCardHeading', 'SimpleMaxVisible', 'SimpleMaxVisibleAll', 'SimpleRowLabel', 'SimpleRowLabelText', 'SimpleShowSelected', 'SimpleSelectedPosition', 'SoldOutDisplay'], $names);

        $c = function ($name) use ($controls) {
            return $controls['layoutSwatches' . $name . '_compact'];
        };
        // Gutenberg's words.
        $labels = [];
        foreach ($names as $name) {
            $labels[$name] = $c($name)['label'] ?? '';
        }
        $this->assertSame([
            'AdvancedHeading' => 'Attribute products', 'Advanced' => 'Show attributes on the card', 'WhichHeading' => 'Which attributes to show',
            'GroupIds' => '', 'ShowOtherGroups' => 'Also show attributes not listed here', 'HiddenGroups' => 'Never show these attributes', 'NoneWarning' => '', 'CardHeading' => 'How it shows on the card',
            'MaxVisible' => 'Show up to', 'MaxVisibleAll' => 'All', 'GroupName' => 'Show label', 'SelectedName' => 'Show the selected choice',
            'SelectedPosition' => 'Where it appears', 'SimpleHeading' => 'List products',
            'Simple' => 'Show the option list on the card', 'SimpleCardHeading' => 'How it shows on the card', 'SimpleMaxVisible' => 'Show up to',
            'SimpleMaxVisibleAll' => 'All', 'SimpleRowLabel' => 'Show label', 'SimpleRowLabelText' => 'Label name',
            'SimpleShowSelected' => 'Show the selected choice', 'SimpleSelectedPosition' => 'Where it appears', 'SoldOutDisplay' => 'Sold-out options',
        ], $labels);
        $this->assertSame('Any other attribute a product has shows after the ones above.', $c('ShowOtherGroups')['description']);
        $this->assertSame('Extras show as "+2", "+3"…', html_entity_decode($c('MaxVisible')['description']), 'as the panel shows it');
        $this->assertSame(['e.g. Color above the row', 'e.g. Navy', 'e.g. Option above the row', 'e.g. 6 pods'], [$c('GroupName')['description'], $c('SelectedName')['description'], $c('SimpleRowLabel')['description'], $c('SimpleShowSelected')['description']]);
        $this->assertSame(['Option', 'Empty shows "Option".'], [$c('SimpleRowLabelText')['placeholder'], html_entity_decode($c('SimpleRowLabelText')['description'])]);
        $this->assertFalse($c('SimpleRowLabelText')['hasDynamicData'], 'a label name is plain text, so no dynamic-data bolt');
        $this->assertSame(['After the label', 'Below the options'], array_values($c('SelectedPosition')['options']));
        // Gutenberg's warning: nothing picked and the others not added leaves the cards with no options.
        $this->assertSame(['info', 'Pick at least one attribute, or cards will show no options.'], [$c('NoneWarning')['type'], $c('NoneWarning')['content']]);
        $this->assertSame([['cardLayout', '=', 'compact'], ['layoutSwatchesAdvanced_compact', '=', true], ['layoutSwatchesShowOtherGroups_compact', '!=', true], ['layoutSwatchesGroupIds_compact', '=', '']], $c('NoneWarning')['required']);
        // One Sold-out options at the end, shared by both product types, as Gutenberg's.
        $this->assertSame(['normal' => 'No change', 'cross' => 'Cross out', 'hide' => 'Hide'], $c('SoldOutDisplay')['options']);
        $this->assertSame('normal', $c('SoldOutDisplay')['default'], 'starts as No change');
        $this->assertArrayNotHasKey('layoutSwatchesSimpleSoldOutDisplay_compact', $controls, 'there is no separate list setting');

        // Gutenberg's inputs: switches, a number box with All beside it, the layout's own values.
        foreach (['Advanced', 'ShowOtherGroups', 'MaxVisibleAll', 'GroupName', 'SelectedName', 'Simple', 'SimpleMaxVisibleAll', 'SimpleRowLabel', 'SimpleShowSelected'] as $name) {
            $this->assertSame('checkbox', $c($name)['type'], $name . ' is a switch');
        }
        $this->assertSame(['number', 1, 12, 2], [$c('MaxVisible')['type'], $c('MaxVisible')['min'], $c('MaxVisible')['max'], $c('MaxVisible')['default']]);
        $this->assertSame([true, true, false, true, true], [$c('Advanced')['default'], $c('ShowOtherGroups')['default'], $c('MaxVisibleAll')['default'], $c('GroupName')['default'], $c('Simple')['default']]);

        // Settings show as Gutenberg's do: under their switch, and the position with the selected choice.
        $on = true;
        $layout = ['cardLayout', '=', 'compact'];
        $this->assertSame([$layout, ['layoutSwatchesAdvanced_compact', '=', $on]], $c('GroupIds')['required']);
        $this->assertSame([$layout, ['layoutSwatchesAdvanced_compact', '=', $on], ['layoutSwatchesMaxVisibleAll_compact', '!=', $on]], $c('MaxVisible')['required'], 'the number box gives way to All');
        $this->assertSame([$layout, ['layoutSwatchesAdvanced_compact', '=', $on], ['layoutSwatchesSelectedName_compact', '=', $on]], $c('SelectedPosition')['required']);
        $this->assertSame([$layout, ['layoutSwatchesSimple_compact', '=', $on], ['layoutSwatchesSimpleRowLabel_compact', '=', $on]], $c('SimpleRowLabelText')['required']);
        $this->assertSame([$layout, ['layoutSwatchesSimple_compact', '=', $on], ['layoutSwatchesSimpleShowSelected_compact', '=', $on]], $c('SimpleSelectedPosition')['required']);
        $this->assertSame($layout, $c('SoldOutDisplay')['required'], 'shown for the layout, whichever tabs are on: one setting serves both');

        // Bricks drops an unticked switch's key and shows a missing one as off, so a missing switch is off, for any
        // element: what the panel shows is what the card draws.
        $this->assertArrayNotHasKey('layoutSwatchesPanel', $controls, 'no mark: an element needs nothing special');
        $options = \FluentCart\App\Modules\Templating\Bricks\LayoutCardControls::cardOptions([
            'cardLayout' => 'compact', 'layoutSwatchesAdvanced_compact' => true, 'layoutSwatchesMaxVisibleAll_compact' => true, 'layoutSwatchesMaxVisible_compact' => 5,
        ]);
        $swatches = $options['swatches'];
        ksort($swatches);
        $this->assertSame(['advanced_enabled' => true, 'max_visible' => 0, 'show_group_name' => false, 'show_label' => false, 'show_other_groups' => false, 'simple_enabled' => false, 'simple_show_label' => false, 'simple_show_selected' => false], $swatches, 'ticked reads as on, unticked (missing) as off, All as 0');
        // The same for an element that holds only some swatch settings, or none.
        $partial = \FluentCart\App\Modules\Templating\Bricks\LayoutCardControls::cardOptions(['cardLayout' => 'compact', 'layoutSwatchesMaxVisible_compact' => 4]);
        $this->assertSame(false, $partial['swatches']['advanced_enabled'] ?? null, 'Show attributes on the card missing is off, and can be switched off');
        $this->assertSame(false, $partial['swatches']['simple_enabled'] ?? null);
        $bare = \FluentCart\App\Modules\Templating\Bricks\LayoutCardControls::cardOptions(['cardLayout' => 'compact']);
        $this->assertSame(false, $bare['swatches']['advanced_enabled'] ?? null, 'an element with no swatch settings shows none, as its panel reads');
        $both = \FluentCart\App\Modules\Templating\Bricks\LayoutCardControls::cardOptions(['cardLayout' => 'compact', 'layoutSwatchesSoldOutDisplay_compact' => 'cross']);
        $this->assertSame(['cross', 'cross'], [$both['swatches']['sold_out_display'] ?? null, $both['swatches']['simple_sold_out_display'] ?? null], 'the one choice reaches both product types');
    }

    public function test_masonry_gets_no_layout_settings_of_its_own(): void
    {
        // Masonry is a switch (builder_only), so no per-layout setting exists for it: they could not be reached.
        $element = $this->element();
        $element->set_control_groups();
        $element->set_controls();
        $leftover = array_values(array_filter(array_keys($element->controls), function ($key) {
            return substr($key, -strlen('_masonry')) === '_masonry';
        }));
        $this->assertSame([], $leftover, 'no Masonry settings');
        $this->assertSame([], array_values(array_filter(array_keys($element->control_groups), function ($group) {
            return substr($group, -strlen('masonry')) === 'masonry' || substr($group, -strlen('masonryStyle')) === 'masonryStyle';
        })), 'no Masonry groups');
    }

    public function test_a_saved_masonry_layout_is_read_as_it_is(): void
    {
        // Unreleased, so no saved element falls back to Classic: Masonry reads as the layout it is.
        $this->assertSame('masonry', \FluentCart\App\Modules\Templating\Bricks\LayoutCardControls::selected('masonry'));
    }

    public function test_masonry_is_a_switch_on_every_card(): void
    {
        $element = $this->element();
        $element->set_controls();

        // Masonry is a switch now, on every layout and on the Fields card, as in Gutenberg; it is not a layout.
        $this->assertSame(['checkbox', null], [$element->controls['layoutMasonry']['type'] ?? null, $element->controls['layoutMasonry']['required'] ?? null], 'a Masonry switch for every card');
        $this->assertSame('Masonry grid', $element->controls['layoutMasonry']['label'], "core's label");
        $this->assertArrayNotHasKey('masonry', $element->controls['cardLayout']['options'], 'Masonry is not a layout');
        $this->assertArrayNotHasKey('masonryGrid', $element->controls, 'no separate Masonry Grid on the Fields card');
    }

    public function test_a_fields_card_gets_the_stagger_class_with_the_switch(): void
    {
        $product = (new \Tests\Support\Factory\ProductFactory())->create(['post_title' => 'Masonry fields card', 'post_status' => 'publish']);
        $post = get_post($product->ID);
        setup_postdata($post);
        $render = function (array $settings) use ($post) {
            ob_start();
            \FluentCart\App\Modules\Templating\Bricks\BricksHelper::renderCollectionCard($settings, $post, 1, '');

            return (string) ob_get_clean();
        };
        $this->assertStringNotContainsString('fct-card-masonry', $render([]), 'no switch: the card is as before');
        $this->assertStringContainsString('fct-card-masonry', $render(['layoutMasonry' => true]), 'the switch staggers the Fields card');
        wp_reset_postdata();
    }

    public function test_the_layout_stylesheet_is_queued_for_a_layout_or_the_masonry_switch(): void
    {
        $needs = function (array $settings): bool {
            $element = $this->element();
            $element->settings = $settings;
            $method = new \ReflectionMethod($element, 'needsLayoutAssets');
            $method->setAccessible(true);

            return $method->invoke($element);
        };
        $this->assertFalse($needs([]), 'a plain Fields card needs only the card assets');
        $this->assertTrue($needs(['cardLayout' => 'compact']), 'a layout card needs the layout stylesheet');
        $this->assertTrue($needs(['layoutMasonry' => true]), 'a Fields card with Masonry on needs it too, queued with the page assets');
    }

    public function test_a_layout_renders_every_product_with_it(): void
    {
        $factory = new ProductFactory();
        $products = [
            Product::query()->find($factory->create(['post_title' => 'Bricks Layout Alpha'])->ID),
            Product::query()->find($factory->create(['post_title' => 'Bricks Layout Bravo'])->ID),
        ];

        $compact = $this->renderCards($this->element(['cardLayout' => 'compact']), $products);
        $this->assertSame(2, substr_count($compact, 'fct-card-layout--compact'));
        $this->assertStringContainsString('Bricks Layout Bravo', $compact);
        $this->assertStringNotContainsString("data-template-provider='bricks'", $compact, 'AJAX pages must render through core');
    }

    public function test_a_layout_cards_parts_hide_on_request(): void
    {
        $product = Product::query()->find((new ProductFactory())->create(['post_title' => 'Bricks Layout Sale'])->ID);
        \FluentCart\App\Models\ProductVariation::query()->where('post_id', $product->ID)->update(['compare_price' => 9900, 'item_price' => 4500]);
        $product = Product::query()->with(['detail', 'variants'])->find($product->ID);

        // Bricks saves a ticked checkbox as true and drops an unticked one.
        $on = ['cardLayout' => 'compact', 'layoutShowSaleBadge' => true, 'layoutShowSoldOutBadge' => true];
        // The badge parts only; the swatch part reads its own switches (see the Gutenberg panel test).
        $badges = function (array $options) {
            return array_diff_key($options, ['swatches' => 1]);
        };
        $this->assertSame([], $badges(\FluentCart\App\Modules\Templating\Bricks\LayoutCardControls::cardOptions($on)), 'defaults keep the layout as designed');
        $settings = array_diff_key($on, ['layoutShowSaleBadge' => true]);
        $options = \FluentCart\App\Modules\Templating\Bricks\LayoutCardControls::cardOptions($settings);
        $this->assertSame(['sale_badge' => false], $badges($options), 'an unticked toggle leaves its part out');

        $this->assertStringContainsString('class="fct-sale-badge', $this->renderCards($this->element($on), [$product], 'starts_from', []));
        $this->assertStringNotContainsString('class="fct-sale-badge', $this->renderCards($this->element($settings), [$product], 'starts_from', $options));
    }

    public function test_a_layouts_card_elements_and_badge_settings_reach_the_card(): void
    {
        $product = Product::query()->find((new ProductFactory())->create(['post_title' => 'Bricks Layout Deal'])->ID);
        \FluentCart\App\Models\ProductVariation::query()->where('post_id', $product->ID)->update(['compare_price' => 9900, 'item_price' => 4500]);
        $product = Product::query()->with(['detail', 'variants'])->find($product->ID);

        $settings = [
            'cardLayout'             => 'compact',
            'layoutShowSaleBadge'    => true,
            'layoutSaleBadgeText'    => 'Deal',
            'layoutSaleBadgeShape'   => 'ribbon',
            'layoutSaleBadgePosition' => 'top-right',
            'layoutShowSoldOutBadge' => true,
            // Rating left out; Button before Price in the footer row.
            'layoutElements_compact' => [['element_type' => 'image'], ['element_type' => 'title'], ['element_type' => 'button'], ['element_type' => 'price']],
            // Another layout's list is not this one's.
            'layoutElements_framed'  => [['element_type' => 'title']],
        ];
        $options = \FluentCart\App\Modules\Templating\Bricks\LayoutCardControls::cardOptions($settings);
        $this->assertSame([
            'elements'   => ['image', 'title', 'button', 'price'],
            'sale_badge' => ['badge_text' => 'Deal', 'badge_style' => 'ribbon', 'badge_position' => 'top-right'],
        ], array_diff_key($options, ['swatches' => 1]), 'the elements and badge settings; the swatch part reads its own switches');

        // An emptied list shows the whole layout, as an empty Classic list does: AJAX pages
        // cannot carry an empty array, and a card with no parts would show nothing.
        $emptied = \FluentCart\App\Modules\Templating\Bricks\LayoutCardControls::cardOptions(['layoutElements_compact' => []] + $settings);
        $this->assertArrayNotHasKey('elements', $emptied);
        $this->assertStringContainsString('Empty shows them all', $this->controlsOf()['layoutElements_compact']['description']);

        $html = $this->renderCards($this->element($settings), [$product], 'starts_from', $options);
        $this->assertStringContainsString('fct-sale-badge--ribbon fct-sale-badge--top-right', $html);
        $this->assertStringContainsString('Deal', $html);
        $this->assertStringNotContainsString('fct-product-card-rating', $html);
        $this->assertLessThan(strpos($html, 'wp-block-fluent-cart-shopapp-product-price'), strpos($html, 'wp-block-fluent-cart-shopapp-product-buttons'), 'Button moved before Price');
    }

    public function test_an_unknown_layout_keeps_the_fields_card(): void
    {
        $select = new \ReflectionMethod(ProductsCollection::class, 'selectedCardLayout');
        $select->setAccessible(true);

        $this->assertSame('', $select->invoke($this->element(['cardLayout' => 'not-a-layout'])));
        $this->assertSame('', $select->invoke($this->element()));
        $this->assertSame('', $select->invoke($this->element(['cardLayout' => 'classic'])), 'a saved classic is the Fields card');
        $this->assertSame('framed', $select->invoke($this->element(['cardLayout' => 'framed'])));
    }
}
