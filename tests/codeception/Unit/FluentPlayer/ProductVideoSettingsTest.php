<?php

namespace Tests\Unit\FluentPlayer;

use Codeception\Test\Unit;
use FluentCart\App\Modules\FluentPlayer\ProductVideoSettings;

class ProductVideoSettingsTest extends Unit
{
    public function test_sanitize_keeps_an_ordered_unique_list_of_positive_ids(): void
    {
        $result = ProductVideoSettings::sanitize(['media_ids' => ['77', 12, '77', 0, -3, 'abc', 5], 'display' => 'modal', 'junk' => 'x']);

        $this->assertSame(['media_ids' => [77, 12, 5], 'positions' => [null, null, null]], $result, 'ids are cast, deduplicated, non-positive/non-numeric dropped, unknown keys dropped');
    }

    public function test_sanitize_folds_the_legacy_single_media_id(): void
    {
        $this->assertSame(['media_ids' => [9], 'positions' => [null]], ProductVideoSettings::sanitize(['media_id' => '9']));
        $this->assertSame(['media_ids' => [1, 2], 'positions' => [null, null]], ProductVideoSettings::sanitize(['media_id' => 9, 'media_ids' => [1, 2]]), 'an explicit list wins over the legacy key');
        $this->assertSame(['media_ids' => [], 'positions' => []], ProductVideoSettings::sanitize(['media_id' => 0]));
    }

    public function test_sanitize_accepts_a_comma_separated_string(): void
    {
        $this->assertSame(['media_ids' => [12, 7], 'positions' => [null, null]], ProductVideoSettings::sanitize(['media_ids' => '12, 7,12,abc,0']));
        $this->assertSame(['media_ids' => [], 'positions' => []], ProductVideoSettings::sanitize(['media_ids' => '']), 'an empty string clears the list');
    }

    public function test_sanitize_falls_back_on_invalid_input(): void
    {
        $this->assertSame(['media_ids' => [], 'positions' => []], ProductVideoSettings::sanitize('not-an-array'));
        $this->assertSame(['media_ids' => [], 'positions' => []], ProductVideoSettings::sanitize([]));
        $this->assertSame(['media_ids' => [], 'positions' => []], ProductVideoSettings::sanitize(['media_ids' => 'nope']));
    }

    public function test_sanitize_caps_the_list(): void
    {
        $ids = range(1, ProductVideoSettings::MAX_VIDEOS + 5);

        $this->assertSame(range(1, ProductVideoSettings::MAX_VIDEOS), ProductVideoSettings::sanitize(['media_ids' => $ids])['media_ids']);
    }

    public function test_sanitize_never_processes_more_than_the_cap(): void
    {
        $submitted = array_merge([1, 1, 1], range(2, 200));

        $result = ProductVideoSettings::sanitize(['media_ids' => $submitted])['media_ids'];

        $this->assertSame(range(1, ProductVideoSettings::MAX_VIDEOS - 2), $result, 'only the first MAX_VIDEOS entries are looked at, duplicates included');
    }

    public function test_sanitize_keeps_a_position_per_media_id(): void
    {
        $this->assertSame(
            ['media_ids' => [12, 7], 'positions' => [0, 3]],
            ProductVideoSettings::sanitize(['media_ids' => '12,7', 'positions' => '0,3']),
            'the wire form is two aligned comma-separated lists'
        );
        $this->assertSame(
            ['media_ids' => [12, 7], 'positions' => [0, null]],
            ProductVideoSettings::sanitize(['media_ids' => [12, 7], 'positions' => [0, '']]),
            'an empty slot means "after every image"'
        );
    }

    public function test_sanitize_drops_the_position_of_an_id_it_drops(): void
    {
        $this->assertSame(
            ['media_ids' => [12, 7], 'positions' => [1, 4]],
            ProductVideoSettings::sanitize(['media_ids' => [12, 'x', 12, 7], 'positions' => [1, 2, 3, 4]]),
            'positions stay aligned with the ids that survived'
        );
    }

    public function test_sanitize_reads_a_missing_or_negative_position_as_last(): void
    {
        $this->assertSame([null, null, null], ProductVideoSettings::sanitize(['media_ids' => [1, 2, 3], 'positions' => [-1, 'abc']])['positions']);
        $this->assertSame([null], ProductVideoSettings::sanitize(['media_ids' => [1], 'positions' => 'junk'])['positions']);
    }

    public function test_position_map_pairs_every_id_with_its_position(): void
    {
        $config = ProductVideoSettings::sanitize(['media_ids' => [12, 7, 9], 'positions' => [0, '', 2]]);

        $this->assertSame([12 => 0, 7 => null, 9 => 2], ProductVideoSettings::positionMap($config));
        $this->assertSame([], ProductVideoSettings::positionMap(['media_ids' => []]));
        $this->assertSame([5 => null], ProductVideoSettings::positionMap(['media_ids' => [5]]), 'a config saved before ordering existed reads as "last"');
    }

    public function test_from_other_info_returns_null_when_unset_or_empty(): void
    {
        $this->assertNull(ProductVideoSettings::fromOtherInfo(null));
        $this->assertNull(ProductVideoSettings::fromOtherInfo(['reviews_enabled' => 'yes']));
        $this->assertNull(ProductVideoSettings::fromOtherInfo(['fluent_player_video' => ['media_ids' => []]]));
        $this->assertNull(ProductVideoSettings::fromOtherInfo(['fluent_player_video' => ['media_id' => 0]]));
        $this->assertNull(ProductVideoSettings::fromOtherInfo(['fluent_player_video' => 'garbage']));
    }

    public function test_from_other_info_returns_sanitized_config_when_set(): void
    {
        $this->assertSame(['media_ids' => [12, 13], 'positions' => [0, null]], ProductVideoSettings::fromOtherInfo([
            'reviews_enabled'     => 'yes',
            'fluent_player_video' => ['media_ids' => ['12', 13], 'positions' => ['0', ''], 'display' => 'both'],
        ]));
        $this->assertSame(['media_ids' => [12], 'positions' => [null]], ProductVideoSettings::fromOtherInfo(['fluent_player_video' => ['media_id' => 12]]), 'legacy single id still reads');
    }
}
