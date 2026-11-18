<?php

namespace Tests\Integration\Blocks;

use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Models\AttributeTerm;
use FluentCart\App\Models\AttributeRelation;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Services\Renderer\ProductCardSwatchesRenderer;
use Tests\Support\Factory\ProductFactory;
use lucatume\WPBrowser\TestCase\WPTestCase;

class CardSwatchAttributeListTest extends WPTestCase
{
    private $product;
    private $shade;
    private $fit;

    public function setUp(): void
    {
        parent::setUp();
        $this->product = (new ProductFactory())->createWithVariants([['variation_title' => 'Red Slim'], ['variation_title' => 'Blue Wide']]);
        ProductDetail::query()->where('post_id', $this->product->ID)->update(['variation_type' => 'advanced_variations']);
        $this->product->load('detail');
        // Fit is created first (lower id) but Shade comes first in the store's order, so the
        // tests fail if the rows follow ids instead of the library order.
        $this->fit = AttributeGroup::query()->create(['title' => 'Fit', 'slug' => 'fit-' . wp_generate_password(6, false), 'serial' => 2]);
        $this->shade = AttributeGroup::query()->create(['title' => 'Shade', 'slug' => 'shade-' . wp_generate_password(6, false), 'serial' => 1]);
        foreach ($this->product->variants->values() as $index => $variant) {
            foreach ([[$this->shade, ['Red', 'Blue'][$index]], [$this->fit, ['Slim', 'Wide'][$index]]] as [$group, $title]) {
                $term = AttributeTerm::query()->create(['title' => $title, 'slug' => strtolower($title) . '-' . $group->id, 'group_id' => $group->id]);
                AttributeRelation::query()->create(['group_id' => $group->id, 'term_id' => $term->id, 'object_id' => $variant->id]);
            }
        }
    }

    private function rows(array $groupIds, $includeOther): array
    {
        return array_column(ProductCardSwatchesRenderer::rowsForProduct($this->product, $groupIds, '', $includeOther)['rows'], 'group_id');
    }

    public function test_saved_pages_keep_the_old_rule_when_the_checkbox_is_unset(): void
    {
        $this->assertSame([$this->shade->id, $this->fit->id], $this->rows([], null), 'No pick shows every attribute, in the store order');
        $this->assertSame([$this->fit->id], $this->rows([$this->fit->id], null), 'A pick shows only that attribute');
    }

    public function test_picked_attributes_come_first_and_the_checkbox_adds_the_others(): void
    {
        $this->assertSame([$this->fit->id, $this->shade->id], $this->rows([$this->fit->id], true), 'Picked first, then the rest');
        $this->assertSame([$this->fit->id], $this->rows([$this->fit->id], false));
        $this->assertSame([$this->shade->id, $this->fit->id], $this->rows([], true));
        $this->assertSame([], $this->rows([], false), 'Nothing picked and no others: no rows');
    }

    public function test_the_block_setting_reaches_the_rows(): void
    {
        $count = function (array $settings) {
            return substr_count(ProductCardSwatchesRenderer::render($this->product, $settings), 'data-fct-card-swatches');
        };
        $this->assertSame(1, $count(['group_ids' => [$this->fit->id]]), 'Unset: the old rule');
        $this->assertSame(1, $count(['group_ids' => [$this->fit->id], 'show_other_groups' => '']), 'Empty, as builders send it: the old rule');
        $this->assertSame(2, $count(['group_ids' => [$this->fit->id], 'show_other_groups' => true]));
        $this->assertSame(2, $count(['group_ids' => [$this->fit->id], 'show_other_groups' => 'yes']));
        $this->assertSame(0, $count(['group_ids' => [], 'show_other_groups' => false]));
    }

    public function test_an_unticked_attribute_stays_off_cards_even_with_the_others_shown(): void
    {
        // Ticked Fit, unticked Shade, others on: Shade must not come back as an "other" attribute.
        $rows = array_column(ProductCardSwatchesRenderer::rowsForProduct($this->product, [$this->fit->id], '', true, [$this->shade->id])['rows'], 'group_id');
        $this->assertSame([$this->fit->id], $rows);

        // The block setting reaches the rows.
        $html = ProductCardSwatchesRenderer::render($this->product, ['group_ids' => [$this->fit->id], 'show_other_groups' => true, 'hidden_group_ids' => [$this->shade->id]]);
        $this->assertSame(1, substr_count($html, 'data-fct-card-swatches'));

        // Unticking only keeps an attribute out of the others; a ticked one always shows.
        $rows = array_column(ProductCardSwatchesRenderer::rowsForProduct($this->product, [$this->shade->id], '', true, [$this->shade->id])['rows'], 'group_id');
        $this->assertSame([$this->shade->id, $this->fit->id], $rows);
    }

    public function test_picks_the_product_does_not_use_leave_room_for_its_own_attributes(): void
    {
        // Eleven picked attributes this product does not have, then its own two through "others".
        $unused = [];
        for ($index = 0; $index < 11; $index++) {
            $unused[] = AttributeGroup::query()->create(['title' => 'Unused ' . $index, 'slug' => 'unused-' . $index . '-' . wp_generate_password(6, false), 'serial' => 10 + $index])->id;
        }
        $rows = array_column(ProductCardSwatchesRenderer::rowsForProduct($this->product, $unused, '', true)['rows'], 'group_id');
        $this->assertSame([$this->shade->id, $this->fit->id], $rows, 'The 10-row cap counts rows the product has');
    }

    public function test_every_saved_unticked_attribute_counts_past_the_first_hundred(): void
    {
        // A large catalog can save more than 100 unticked attributes; Shade sits at position 120.
        $hidden = range(900000, 900118);
        $hidden[] = $this->shade->id;
        $html = ProductCardSwatchesRenderer::render($this->product, ['group_ids' => [$this->fit->id], 'show_other_groups' => true, 'hidden_group_ids' => $hidden]);
        $this->assertSame(1, substr_count($html, 'data-fct-card-swatches'), 'Shade stays off the card');
    }
}
