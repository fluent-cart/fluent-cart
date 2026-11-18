<?php

namespace Tests\Integration\Resources;

use FluentCart\Api\Resource\ShopResource;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\ProductFactory;

/**
 * A price range that starts at 0 is a range like any other. A link such as
 * ?filters[price_range_from]=0&filters[price_range_to]=200 must hide the products above 200.
 */
class ShopResourcePriceRangeTest extends DatabaseTestCase
{
    /** @var int[] */
    private $ids = [];

    public function setUp(): void
    {
        parent::setUp();
        foreach ([5000, 15000, 30000] as $cents) {
            $this->ids[$cents] = (int) (new ProductFactory())->withVariantAttributes(['item_price' => $cents])->create(['post_status' => 'publish'])->ID;
        }
    }

    private function found(array $filters): array
    {
        $result = ShopResource::get(['filters' => $filters, 'include_ids' => array_values($this->ids), 'per_page' => 20]);
        $found = array_map('intval', array_column($result['products']->toArray()['data'], 'ID'));
        sort($found);

        return $found;
    }

    public function test_a_range_starting_at_zero_hides_products_above_it(): void
    {
        $expected = [$this->ids[5000], $this->ids[15000]];
        sort($expected);

        $this->assertSame($expected, $this->found(['price_range_from' => '0', 'price_range_to' => '200']));
        $this->assertSame($expected, $this->found(['price_range_from' => 0, 'price_range_to' => 200]));
    }

    public function test_filters_sent_as_a_json_string_apply_too(): void
    {
        $result = ShopResource::get(['filters' => '{"price_range_from":"0","price_range_to":"200"}', 'include_ids' => array_values($this->ids), 'per_page' => 20]);
        $found = array_map('intval', array_column($result['products']->toArray()['data'], 'ID'));
        sort($found);
        $expected = [$this->ids[5000], $this->ids[15000]];
        sort($expected);

        $this->assertSame($expected, $found);
    }

    public function test_a_range_above_zero_still_applies(): void
    {
        $this->assertSame([$this->ids[15000]], $this->found(['price_range_from' => '100', 'price_range_to' => '200']));
    }

    public function test_without_both_bounds_no_range_applies(): void
    {
        $all = array_values($this->ids);
        sort($all);

        $this->assertSame($all, $this->found([]));
        $this->assertSame($all, $this->found(['price_range_from' => '0']));
        $this->assertSame($all, $this->found(['price_range_to' => '']));
    }

    public function test_a_bound_that_is_not_a_number_is_ignored(): void
    {
        $all = array_values($this->ids);
        sort($all);

        $this->assertSame($all, $this->found(['price_range_from' => '0', 'price_range_to' => 'abc']));
        $this->assertSame($all, $this->found(['price_range_from' => '0', 'price_range_to' => ['1']]));
        $this->assertSame($all, $this->found(['price_range_from' => 'abc', 'price_range_to' => '200']));
    }

    public function test_an_upper_bound_of_zero_is_a_range(): void
    {
        $free = (int) (new ProductFactory())->withVariantAttributes(['item_price' => 0])->create(['post_status' => 'publish'])->ID;
        $this->ids[0] = $free;

        $this->assertSame([$free], $this->found(['price_range_from' => '0', 'price_range_to' => '0']));
    }
}
