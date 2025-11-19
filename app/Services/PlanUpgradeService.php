<?php

namespace FluentCart\App\Services;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Meta;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\Subscription;
use FluentCart\Framework\Support\Arr;

class PlanUpgradeService
{

    static string $metaType = 'variant_upgrade';
    static string $metaKey = 'variant_upgrade_path';

    public static function getUpgradeSettings($productId, $variantId = null)
    {
        if ($variantId) {
            return Meta::query()
                ->where('object_id', $variantId)
                ->where('object_type', static::$metaType)
                ->where('meta_key', static::$metaKey)
                ->get();
        }

        return Meta::query()
            ->upgradeablePath($productId)
            ->get();


    }

    public static function getAvailableUpgradePaths($variantId, $metaValue)
    {
        $metaValue = json_decode($metaValue, true);
        return Arr::get($metaValue, 'paths' . '.' . $variantId, []);
    }


    public static function saveUpgradeSetting($settings)
    {
        $data = [
            'object_id'   => Arr::get($settings, 'from_variant'),
            'object_type' => static::$metaType,
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
            'meta_key'    => static::$metaKey,
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'meta_value'  => [
                'to_variants'     => Arr::get($settings, 'to_variants'),
                'is_prorate'      => Arr::get($settings, 'is_prorate'),
                'discount_amount' => Arr::get($settings, 'discount_amount')
            ],
        ];

        return Meta::query()->create($data);
    }

    public static function updateUpgradeSetting($id, $settings)
    {
        $data = [
            'to_variants'     => Arr::get($settings, 'to_variants'),
            'is_prorate'      => Arr::get($settings, 'is_prorate'),
            'discount_amount' => Arr::get($settings, 'discount_amount')
        ];

        return Meta::query()->where('id', $id)->update([
            //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
            'meta_value' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ]);
    }

    public static function validateUpgradePaths($paths)
    {
        foreach ($paths as $variationId => $path) {
            if (empty($path)) {
                continue;
            }

            $targetVariationIds = array_column($path, 'target_variation_id');
            $uniqueIds = array_unique($targetVariationIds);

            if (count($targetVariationIds) !== count($uniqueIds)) {
                $duplicates = array_diff_assoc($targetVariationIds, $uniqueIds);
                wp_send_json([
                    'message' => sprintf(
                        /* translators: 1: Variation ID */
                        __('Duplicate upgrade path found for variation #%1$s. Each target variation must be unique.', 'fluent-cart'),
                        $variationId
                    ),
                    'duplicates' => $duplicates
                ], 423);
            }
        }

    }

    public static function getUpgardePathsFromVariation($variationId, $orderHash)
    {
        if (!$variationId || !$orderHash) {
            return [];
        }

        $upgrades = Meta::query()->where('meta_key', 'variant_upgrade_path')
            ->where('object_type', 'variant_upgrade')
            ->where('object_id', $variationId)
            ->get();

        $order = Order::query()->where('uuid', $orderHash)->first();

        if ($order->type === 'subscription') {
            $lastRenewal = Order::query()
                ->where('parent_id', $order->id)
                ->where('type', 'renewal')
                ->whereIn('payment_status', ['paid', 'partially_refunded'])
                ->orderBy('id', 'DESC')
                ->first();

            if ($lastRenewal) {
                $order = $lastRenewal;
            }
        }

        $originalItem = $order->order_items->where('object_id', $variationId)->first();
        if (!$originalItem) {
            return [];
        }

        $upgradePaths = [];
        foreach ($upgrades as $upgrade) {
            $toVariants = Arr::get($upgrade->meta_value, 'to_variants', []);
            $isProrate = Arr::get($upgrade->meta_value, 'is_prorate', false);
            $discountAmount = Helper::toCent(Arr::get($upgrade->meta_value, 'discount_amount'));

            foreach ($toVariants as $toVariant) {
                // calculate prorate credit, discount amount
                $variant = ProductVariation::query()->find($toVariant);
                if (!$variant) {
                    continue;
                }

                $prorateCredit = 0;
                if ($isProrate) {
                    $prorateCredit = self::calculateUpgradeToDiscount($order, $originalItem);
                }

                $signupFee = Arr::get($variant->other_info, 'signup_fee', 0);
                $cost = floatval($variant->item_price + Arr::get($variant->other_info, 'signup_fee', 0) - $prorateCredit - $discountAmount);

                if ($cost < 0) {
                    $cost = 0;
                }

                $paymentType = Arr::get($variant, 'payment_type');
                $paymentSummary = Helper::toDecimal($cost) . ' one-time';
                if ($paymentType === 'subscription') {
                    $paymentSummary = '<strong>' . Helper::toDecimal($cost) . '</strong> first '
                        . Helper::humanIntervalMaps(Arr::get($variant->other_info, 'repeat_interval'))
                        . ', then ' . Helper::toDecimal($variant->item_price)
                        . '/' . Helper::humanIntervalMaps(Arr::get($variant->other_info, 'repeat_interval')) . ' thereafter.';
                }

                $upgradePaths[] = [
                    'title'           => $variant->variation_title,
                    'to_variant'      => $toVariant,
                    'discount_amount' => (float)$discountAmount,
                    'prorate_credit'  => (float)$prorateCredit,
                    'signup_fee'      => (float)$signupFee,
                    'signup_fee_name' => Arr::get($variant->other_info, 'signup_fee_name', ''),
                    'payment_summary' => $paymentSummary,
                    'price'           => (float)$variant->item_price,
                    'original_price'  => floatval($variant->item_price + $signupFee),
                    'cost'            => $cost,
                    'currency'        => $order->currency,
                    'upgrade_url'     => add_query_arg([
                        'fluent-cart' => 'upgrade_plan',
                        'order_hash'  => $orderHash,
                        'path_id'     => $upgrade->id,
                        'target_id'   => $toVariant,
                    ], home_url())
                ];
            }
        }


        return $upgradePaths;
    }



    public static function calculateUpgradeToDiscount(Order $order, OrderItem $originalItem)
    {
        // check for signup fee item
        $totalPaid = $originalItem->line_total - $originalItem->refund_total;

        $additionalItemIds = Arr::get($originalItem->line_meta, 'additional_item_ids', []);
        if (!empty($additionalItemIds)) {
            $additionalItems = OrderItem::query()
                ->whereIn('id', $additionalItemIds)
                ->get();

            foreach ($additionalItems as $item) {
                if (Arr::get($item->line_meta, 'parent_item_id', '') == $originalItem->id) {
                    $totalPaid += $item->line_total - $item->refund_total;
                }
            }
        }

        if ($originalItem->payment_type === 'onetime') {
            return $totalPaid < 0 ? 0 : $totalPaid;
        }


        $parentOrderId = $order->id;

        if ($order->type === 'renewal') {
            $parentOrderId = $order->parent_id;
        }

        $subscription = Subscription::query()
            ->where('parent_order_id', $parentOrderId)
            ->first();

        if (!$subscription || !$subscription->hasAccessValidity()) {
            return 0;
        }

        $daysRemaining = ceil((strtotime($subscription->next_billing_date) - time()) / 86400); // convert seconds to days

        $maps = [
            'monthly' => 30,
            'yearly'  => 365,
            'weekly'  => 7,
            'daily'   => 1,
        ];

        if (!isset($maps[$subscription->billing_interval])) {
            return 0; // Invalid repeat interval
        }

        $divider = $maps[$subscription->billing_interval];
        if ($daysRemaining > $divider) { // making sure we are not giving discount more than the actual amount
            $daysRemaining = $divider;
        }
        $discountAmount = intval($totalPaid / $divider * $daysRemaining);

        return $discountAmount < 0 ? 0 : $discountAmount;
    }


}
