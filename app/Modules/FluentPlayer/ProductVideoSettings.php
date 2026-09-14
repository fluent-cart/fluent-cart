<?php

namespace FluentCart\App\Modules\FluentPlayer;

use FluentCart\App\Models\Product;
use FluentCart\Framework\Support\Arr;

/**
 * `fct_product_details.other_info.fluent_player_video` =>
 * ['media_ids' => int[], 'positions' => (int|null)[]].
 *
 * `positions[i]` is how many gallery images precede `media_ids[i]` in the
 * product gallery, so a video the admin dragged in front of everything has
 * position 0. null (and any position past the last image) means "after every
 * image" — which is where videos saved before ordering existed still sit.
 * Pure PHP so the Unit suite can cover it.
 */
class ProductVideoSettings
{
    const KEY = 'fluent_player_video';

    const MAX_VIDEOS = 20;

    public static function defaults(): array
    {
        return ['media_ids' => [], 'positions' => []];
    }

    /**
     * Wire shape from the admin is a comma-separated string ("12,15" with
     * "0,,2" for the positions); an empty JSON array does not survive the
     * product update request, so "no videos" could never arrive as `[]`. The
     * stored shape is int[], which is why the array form is accepted too
     * (fromOtherInfo re-runs this).
     *
     * @param mixed $value
     */
    public static function sanitize($value): array
    {
        $config = self::defaults();

        if (!is_array($value)) {
            return $config;
        }

        $raw = self::toList(Arr::get($value, 'media_ids', []));

        $legacy = Arr::get($value, 'media_id');
        if (is_numeric($legacy) && !array_key_exists('media_ids', $value)) {
            $raw = [$legacy];
        }

        $rawPositions = self::toList(Arr::get($value, 'positions', []));

        $ids = [];
        $positions = [];
        foreach (array_slice($raw, 0, self::MAX_VIDEOS, true) as $index => $id) {
            $id = is_string($id) ? trim($id) : $id;
            if (!is_numeric($id)) {
                continue;
            }
            $id = (int) $id;
            if ($id <= 0 || isset($ids[$id])) {
                continue;
            }
            $ids[$id] = $id;
            $positions[$id] = self::toPosition(isset($rawPositions[$index]) ? $rawPositions[$index] : null);
        }

        $config['media_ids'] = array_values($ids);
        $config['positions'] = array_values($positions);

        return $config;
    }

    /**
     * @param mixed $value
     * @return array
     */
    protected static function toList($value): array
    {
        if (is_string($value)) {
            return explode(',', $value);
        }

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param mixed $value
     * @return int|null null = after every image.
     */
    protected static function toPosition($value)
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        $position = (int) $value;

        return $position >= 0 ? $position : null;
    }

    /**
     * @return array<int, int|null> media id => position, in stored order.
     */
    public static function positionMap(array $config): array
    {
        $positions = Arr::get($config, 'positions', []);

        $map = [];
        foreach (Arr::get($config, 'media_ids', []) as $index => $mediaId) {
            $map[(int) $mediaId] = isset($positions[$index]) ? $positions[$index] : null;
        }

        return $map;
    }

    /**
     * @param mixed $otherInfo
     * @return array|null null when no video is set.
     */
    public static function fromOtherInfo($otherInfo): ?array
    {
        if (!is_array($otherInfo) || !array_key_exists(self::KEY, $otherInfo)) {
            return null;
        }

        $config = self::sanitize($otherInfo[self::KEY]);

        return count($config['media_ids']) > 0 ? $config : null;
    }

    public static function fromProduct(Product $product): ?array
    {
        $detail = $product->detail;
        if (!$detail) {
            return null;
        }

        return self::fromOtherInfo($detail->other_info);
    }
}
