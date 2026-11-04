<?php

namespace Tests\Unit\Services\Shipments;

use Codeception\Test\Unit;
use FluentCart\App\Services\Shipments\BoxPacker;

class BoxPackerTest extends Unit
{
    private function box(array $overrides = []): array
    {
        return array_merge([
            'key'            => 'std',
            'length'         => 20.0,
            'width'          => 15.0,
            'height'         => 10.0,
            'dimension_unit' => 'cm',
            'tare'           => 0.5,
            'max_weight'     => 0,
        ], $overrides);
    }

    public function test_single_line_under_capacity_yields_one_parcel_with_tare(): void
    {
        $parcels = BoxPacker::pack([
            ['content_weight' => 2.0, 'qty' => 3, 'box' => $this->box(['max_weight' => 10])],
        ]);

        $this->assertCount(1, $parcels, 'contents (6) under capacity (10) must be one box');
        $this->assertSame(6.5, $parcels[0]['weight'], 'weight = tare 0.5 + contents 6');
        $this->assertSame(20.0, $parcels[0]['length'], 'box dimensions must pass through');
        $this->assertSame('cm', $parcels[0]['dimension_unit']);
    }

    public function test_contents_over_capacity_split_into_fewest_boxes(): void
    {
        // 8 kg contents, 5 kg cap => ceil(8/5) = 2 boxes, 4 kg contents each + 0.5 tare.
        $parcels = BoxPacker::pack([
            ['content_weight' => 4.0, 'qty' => 2, 'box' => $this->box(['max_weight' => 5])],
        ]);

        $this->assertCount(2, $parcels, '8kg over 5kg cap must split into 2 boxes');
        $this->assertSame(4.5, $parcels[0]['weight'], 'each box = tare 0.5 + 4 contents');
        $this->assertSame(4.5, $parcels[1]['weight']);
        $contents = ($parcels[0]['weight'] - 0.5) + ($parcels[1]['weight'] - 0.5);
        $this->assertSame(8.0, $contents, 'split must preserve total contents weight');
    }

    public function test_zero_max_weight_is_unlimited_single_box(): void
    {
        $parcels = BoxPacker::pack([
            ['content_weight' => 40.0, 'qty' => 1, 'box' => $this->box(['max_weight' => 0])],
        ]);

        $this->assertCount(1, $parcels, 'max_weight 0 means unlimited => one box');
        $this->assertSame(40.5, $parcels[0]['weight']);
    }

    public function test_same_box_lines_combine_before_splitting(): void
    {
        // Two products sharing box "std": 3x2 + 2x1 = 8 contents, cap 5 => 2 boxes.
        $parcels = BoxPacker::pack([
            ['content_weight' => 2.0, 'qty' => 3, 'box' => $this->box(['key' => 'std', 'max_weight' => 5])],
            ['content_weight' => 1.0, 'qty' => 2, 'box' => $this->box(['key' => 'std', 'max_weight' => 5])],
        ]);

        $this->assertCount(2, $parcels, 'same-box products combine then split by capacity');
    }

    public function test_different_boxes_produce_separate_parcels(): void
    {
        $parcels = BoxPacker::pack([
            ['content_weight' => 1.0, 'qty' => 1, 'box' => $this->box(['key' => 'small', 'length' => 10, 'max_weight' => 0])],
            ['content_weight' => 1.0, 'qty' => 1, 'box' => $this->box(['key' => 'large', 'length' => 50, 'max_weight' => 0])],
        ]);

        $this->assertCount(2, $parcels, 'distinct boxes never merge');
        $lengths = [$parcels[0]['length'], $parcels[1]['length']];
        sort($lengths);
        $this->assertSame([10.0, 50.0], $lengths, 'each box keeps its own dimensions');
    }

    public function test_weightless_lines_yield_no_parcels(): void
    {
        $parcels = BoxPacker::pack([
            ['content_weight' => 0.0, 'qty' => 5, 'box' => $this->box(['tare' => 0, 'max_weight' => 0])],
        ]);

        $this->assertSame([], $parcels, 'nothing packable => empty, caller falls back to nominal');
    }

    public function test_weightless_line_ships_its_box_at_tare_weight(): void
    {
        $parcels = BoxPacker::pack([
            ['content_weight' => 0.0, 'qty' => 1, 'box' => $this->box(['length' => 30, 'width' => 20, 'height' => 10, 'tare' => 0.25])],
        ]);

        $this->assertCount(1, $parcels, 'a weightless line still ships in its box');
        $this->assertSame(0.25, $parcels[0]['weight'], 'weight = tare only');
        $this->assertSame([30.0, 20.0, 10.0], [$parcels[0]['length'], $parcels[0]['width'], $parcels[0]['height']]);
    }

    public function test_mixed_cart_keeps_the_weightless_lines_box(): void
    {
        $parcels = BoxPacker::pack([
            ['content_weight' => 1.0, 'qty' => 1, 'box' => $this->box(['key' => 'a', 'length' => 40, 'tare' => 0.5])],
            ['content_weight' => 0.0, 'qty' => 2, 'box' => $this->box(['key' => 'b', 'length' => 25, 'tare' => 0.2])],
        ]);

        $this->assertCount(2, $parcels, 'both boxes must be quoted');
        $byLength = array_column($parcels, 'weight', 'length');
        $this->assertSame(1.5, $byLength[40], 'box a = tare 0.5 + 1 contents');
        $this->assertSame(0.2, $byLength[25], 'box b = tare 0.2 only');
    }

    public function test_cap_still_holds_with_a_weightless_box_group(): void
    {
        // 3 distinct groups (one weightless with tare) over a cap of 2.
        $parcels = BoxPacker::pack([
            ['content_weight' => 2.0, 'qty' => 1, 'box' => $this->box(['key' => 'a', 'tare' => 0, 'max_weight' => 0])],
            ['content_weight' => 3.0, 'qty' => 1, 'box' => $this->box(['key' => 'b', 'tare' => 0, 'max_weight' => 0])],
            ['content_weight' => 0.0, 'qty' => 1, 'box' => $this->box(['key' => 'c', 'tare' => 0.4, 'max_weight' => 0])],
        ], 2);

        $this->assertLessThanOrEqual(2, count($parcels), 'weightless group must not push past the cap');
        $totalWeight = array_sum(array_column($parcels, 'weight'));
        $this->assertEqualsWithDelta(5.4, $totalWeight, 0.001, 'capping keeps the weightless box tare');
    }

    public function test_empty_input_yields_no_parcels(): void
    {
        $this->assertSame([], BoxPacker::pack([]), 'no lines => no parcels');
    }

    public function test_max_parcels_cap_bounds_a_hostile_quantity(): void
    {
        // 1,000,000 units x 1kg over a 1kg box would be a million boxes; cap at 100.
        $parcels = BoxPacker::pack([
            ['content_weight' => 1.0, 'qty' => 1000000, 'box' => $this->box(['tare' => 0, 'max_weight' => 1])],
        ], 100);

        $this->assertLessThanOrEqual(100, count($parcels), 'cap must bound the parcel count');
        $totalWeight = array_sum(array_column($parcels, 'weight'));
        $this->assertEqualsWithDelta(1000000.0, $totalWeight, 0.001, 'capping must preserve total weight');
    }

    public function test_zero_cap_means_unlimited(): void
    {
        $parcels = BoxPacker::pack([
            ['content_weight' => 1.0, 'qty' => 10, 'box' => $this->box(['tare' => 0, 'max_weight' => 1])],
        ], 0);

        $this->assertCount(10, $parcels, 'cap 0 imposes no ceiling => 10 boxes');
    }

    public function test_more_distinct_boxes_than_cap_still_respects_the_ceiling(): void
    {
        // 5 distinct boxes (each would want its own parcel), cap 3.
        $lines = [];
        for ($i = 0; $i < 5; $i++) {
            $lines[] = ['content_weight' => 2.0, 'qty' => 1, 'box' => $this->box(['key' => 'box' . $i, 'tare' => 0, 'max_weight' => 0])];
        }
        $parcels = BoxPacker::pack($lines, 3);

        $this->assertLessThanOrEqual(3, count($parcels), 'more groups than the cap must not exceed the cap');
        $this->assertNotEmpty($parcels, 'a bounded pack still returns a parcel');
        $totalWeight = array_sum(array_column($parcels, 'weight'));
        $this->assertEqualsWithDelta(10.0, $totalWeight, 0.001, 'consolidating overflow must preserve total weight');
    }

    public function test_cap_holds_when_groups_equal_cap_but_split_would_overshoot(): void
    {
        // Natural counts 10 + 1 + 1 = 12 over a cap of 3: group count (3) equals the
        // cap, but a naive proportional+min-1 split would yield 2+1+1 = 4.
        $parcels = BoxPacker::pack([
            ['content_weight' => 10.0, 'qty' => 1, 'box' => $this->box(['key' => 'a', 'tare' => 0, 'max_weight' => 1])],
            ['content_weight' => 1.0,  'qty' => 1, 'box' => $this->box(['key' => 'b', 'tare' => 0, 'max_weight' => 1])],
            ['content_weight' => 1.0,  'qty' => 1, 'box' => $this->box(['key' => 'c', 'tare' => 0, 'max_weight' => 1])],
        ], 3);

        $this->assertLessThanOrEqual(3, count($parcels), 'total must never exceed the cap');
        $totalWeight = array_sum(array_column($parcels, 'weight'));
        $this->assertEqualsWithDelta(12.0, $totalWeight, 0.001, 'capping preserves total weight');
    }

    public function test_total_weight_counts_each_box_tare_without_a_parcel_list(): void
    {
        // 1,000,000 kg in 0.001 kg boxes = 1e9 boxes; packing them one by one would exhaust memory.
        $total = BoxPacker::totalWeight([
            ['content_weight' => 10.0, 'qty' => 100000, 'box' => $this->box(['max_weight' => 0.001])],
            ['content_weight' => 2.0, 'qty' => 3, 'box' => $this->box(['key' => 'other', 'max_weight' => 5])],
        ]);

        $this->assertEqualsWithDelta(1000000 + 1e9 * 0.5 + 6 + 2 * 0.5, $total, 0.001, 'contents plus every box tare, uncapped');
    }

    public function test_total_weight_matches_the_sum_of_packed_parcels(): void
    {
        $lines = [
            ['content_weight' => 0.8, 'qty' => 3, 'box' => $this->box(['max_weight' => 2, 'tare' => 0.25])],
            ['content_weight' => 0.0, 'qty' => 1, 'box' => $this->box(['key' => 'empty', 'tare' => 0])],
            ['content_weight' => 1.0, 'qty' => 2, 'box' => $this->box(['key' => 'heavy'])],
        ];

        $this->assertEqualsWithDelta(
            array_sum(array_column(BoxPacker::pack($lines), 'weight')),
            BoxPacker::totalWeight($lines),
            0.000001,
            'the total must equal what pack() returns without a cap'
        );
    }
}
