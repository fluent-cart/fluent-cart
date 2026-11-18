<?php

namespace Tests\Integration\Blocks;

use FluentCart\App\Models\AttributeGroup;
use FluentCart\App\Models\AttributeTerm;
use FluentCart\App\Models\AttributeRelation;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Services\Renderer\ProductCardSwatchesRenderer;
use Tests\Support\Factory\ProductFactory;
use lucatume\WPBrowser\TestCase\WPTestCase;

class CardSwatchVisibleCountTest extends WPTestCase
{
    private $product;

    public function setUp(): void
    {
        parent::setUp();
        // 14 values, more than the 12 a number can show.
        $titles = array_map(function ($index) {
            return 'Size ' . $index;
        }, range(1, 14));
        $this->product = (new ProductFactory())->createWithVariants(array_map(function ($title) {
            return ['variation_title' => $title];
        }, $titles));
        ProductDetail::query()->where('post_id', $this->product->ID)->update(['variation_type' => 'advanced_variations']);
        $this->product->load('detail');
        $group = AttributeGroup::query()->create(['title' => 'Size', 'slug' => 'size-' . wp_generate_password(6, false)]);
        foreach ($this->product->variants->values() as $index => $variant) {
            $term = AttributeTerm::query()->create(['title' => $titles[$index], 'slug' => 'size-' . $index, 'group_id' => $group->id]);
            AttributeRelation::query()->create(['group_id' => $group->id, 'term_id' => $term->id, 'object_id' => $variant->id]);
        }
    }

    private function shown($maxVisible): array
    {
        $html = ProductCardSwatchesRenderer::render($this->product, ['max_visible' => $maxVisible]);
        preg_match('/data-fct-card-swatch-more[^>]*>\s*\+(\d+)/', $html, $more);
        return [substr_count($html, 'data-term-id='), $more ? (int) $more[1] : 0];
    }

    public function test_zero_shows_every_value_with_no_more_chip(): void
    {
        $this->assertSame([14, 0], $this->shown(0), 'All shows every value');
        $this->assertSame([14, 0], $this->shown('0'), 'A builder may send the number as text');
        $this->assertSame([14, 0], $this->shown(0.0), 'The editor preview sends block numbers as floats');
    }

    public function test_numbers_keep_their_one_to_twelve_range(): void
    {
        $this->assertSame([3, 11], $this->shown(3));
        $this->assertSame([12, 2], $this->shown(20), 'Above 12 still caps at 12');
        $this->assertSame([1, 13], $this->shown(''), 'An empty value is not All');
        $this->assertSame([1, 13], $this->shown(-2));
        $this->assertSame([1, 13], $this->shown('0.5'), 'Only an exact 0 means All');
    }
}
