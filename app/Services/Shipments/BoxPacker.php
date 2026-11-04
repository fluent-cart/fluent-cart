<?php

namespace FluentCart\App\Services\Shipments;

/**
 * Weight-based box packing for live-rate quotes and labels.
 *
 * Lines are grouped by their box; a group heavier than the box max_weight is
 * split into the fewest boxes that fit. Unit-agnostic: callers convert every
 * weight to one unit first, and parcel weights come back in that unit.
 */
class BoxPacker
{
    /**
     * @param array $lines Each: [
     *     'content_weight' => float,  // per unit, excludes box tare
     *     'qty'            => int,
     *     'box'            => ['key', 'length', 'width', 'height', 'dimension_unit', 'tare', 'max_weight'],
     * ]  max_weight <= 0 means unlimited.
     * @param int $maxParcels Hard ceiling on parcels returned, 0 = none. Checkout
     *                        passes one because cart quantity is user-controlled.
     *
     * @return array[] ['length', 'width', 'height', 'dimension_unit', 'weight'];
     *                 empty when neither contents nor box tare weigh anything
     *                 (caller sends a nominal parcel).
     */
    public static function pack(array $lines, int $maxParcels = 0): array
    {
        $packed     = self::groups($lines);
        $totalBoxes = array_sum(array_column($packed, 'boxCount'));

        if ($maxParcels > 0 && $totalBoxes > $maxParcels) {
            if (count($packed) > $maxParcels) {
                // Can't give each group a box: collapse into one parcel.
                $packed = [self::mergeAll($packed)];
            } else {
                // One box per group, then share the leftover budget by excess.
                // Flooring keeps the total at or under the cap.
                $groupCount = count($packed);
                $remaining  = $maxParcels - $groupCount;
                $totalExtra = $totalBoxes - $groupCount;
                foreach ($packed as &$entry) {
                    $extra             = $entry['boxCount'] - 1;
                    $entry['boxCount'] = 1 + ($totalExtra > 0 ? (int) floor($extra * $remaining / $totalExtra) : 0);
                }
                unset($entry);
            }
        }

        $parcels = [];
        foreach ($packed as $entry) {
            $box      = $entry['box'];
            $boxCount = $entry['boxCount'];
            $tare     = (float) (isset($box['tare']) ? $box['tare'] : 0);

            $parcel = [
                'length'         => self::dimension($box, 'length'),
                'width'          => self::dimension($box, 'width'),
                'height'         => self::dimension($box, 'height'),
                'dimension_unit' => (string) (isset($box['dimension_unit']) ? $box['dimension_unit'] : 'cm'),
                'weight'         => $tare + $entry['total'] / $boxCount,
            ];

            for ($i = 0; $i < $boxCount; $i++) {
                $parcels[] = $parcel;
            }
        }

        return $parcels;
    }

    /**
     * Total weight of what pack() would return without a parcel cap: contents
     * plus each box's tare once per box. Counts boxes instead of listing them.
     */
    public static function totalWeight(array $lines): float
    {
        $weight = 0.0;
        foreach (self::groups($lines) as $entry) {
            $weight += $entry['total'] + (float) (isset($entry['box']['tare']) ? $entry['box']['tare'] : 0) * $entry['boxCount'];
        }

        return $weight;
    }

    /**
     * Lines grouped by box key, each with its content weight and box count.
     */
    private static function groups(array $lines): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $box     = isset($line['box']) && is_array($line['box']) ? $line['box'] : [];
            $qty     = max(1, (int) (isset($line['qty']) ? $line['qty'] : 1));
            $content = (float) (isset($line['content_weight']) ? $line['content_weight'] : 0);

            $key = (string) (isset($box['key']) ? $box['key'] : '');
            if (!isset($groups[$key])) {
                $groups[$key] = ['box' => $box, 'content' => 0.0];
            }
            $groups[$key]['content'] += $content * $qty;
        }

        $packed = [];
        foreach ($groups as $group) {
            $total = max(0.0, $group['content']);
            // A weightless item still ships in its box; skip only when nothing weighs.
            if ($total <= 0 && (float) (isset($group['box']['tare']) ? $group['box']['tare'] : 0) <= 0) {
                continue;
            }
            $maxWeight = (float) (isset($group['box']['max_weight']) ? $group['box']['max_weight'] : 0);
            $boxCount  = ($maxWeight > 0 && $total > $maxWeight) ? (int) ceil($total / $maxWeight) : 1;

            $packed[] = ['box' => $group['box'], 'total' => $total, 'boxCount' => $boxCount];
        }

        return $packed;
    }

    /**
     * One parcel holding every group: weight kept (tare per original box
     * folded in), dimensions from the largest box by volume.
     */
    private static function mergeAll(array $packed): array
    {
        $totalWeight = 0.0;
        $largest     = [];
        $volume      = -1.0;

        foreach ($packed as $entry) {
            $box  = $entry['box'];
            $tare = (float) (isset($box['tare']) ? $box['tare'] : 0);
            $totalWeight += $entry['total'] + $tare * $entry['boxCount'];

            $boxVolume = self::dimension($box, 'length') * self::dimension($box, 'width') * self::dimension($box, 'height');
            if ($boxVolume > $volume) {
                $volume  = $boxVolume;
                $largest = $box;
            }
        }

        $largest['tare'] = 0.0;

        return ['box' => $largest, 'total' => $totalWeight, 'boxCount' => 1];
    }

    private static function dimension(array $box, string $key): float
    {
        $value = (float) (isset($box[$key]) ? $box[$key] : 10);

        return $value > 0 ? $value : 10.0;
    }
}
