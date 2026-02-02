<?php

namespace FluentCartPro\App\Hooks\Handlers;

use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Meta;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderItem;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Services\PlanUpgradeService;
use FluentCart\Framework\Support\Arr;

class UpgradeHandler
{
    public function register()
    {
        add_action('fluent_cart_action_upgrade_plan', [$this, 'handleUpgradePlanRedirect'], 10, 1);

        add_action('fluent_cart/upgraded_order_created', [$this, 'handleUpgradedOrderCreated'], 10, 1);
        add_action('fluent_cart/upgrade_plan_completed', [$this, 'handleUpgradePlanCompleted'], 10, 1);
    }

    public function handleUpgradePlanRedirect($data = [])
    {
        $fromOrderHash = sanitize_text_field(Arr::get($data, 'order_hash', ''));
        $parentOrder = Order::query()->where('uuid', $fromOrderHash)->first();

        if (!$parentOrder || !$fromOrderHash) {
            die('Invalid Upgrade Link Provided!');
        }

        if (!in_array($parentOrder->payment_status, [Status::PAYMENT_PAID, Status::PAYMENT_PARTIALLY_PAID, Status::PAYMENT_PARTIALLY_REFUNDED])) {
            die('You can only upgrade a paid order!');
        }

        $latestOrder = $parentOrder;
        if ($parentOrder->type === Status::ORDER_TYPE_SUBSCRIPTION) {
            $latestRenewal = Order::query()
                ->where('parent_id', $parentOrder->id)
                ->where('type', Status::ORDER_TYPE_RENEWAL)
                ->orderBy('id', 'DESC')
                ->first();

            if ($latestRenewal) {
                $latestOrder = $latestRenewal;
            }

        }

        $upgradePath = Meta::query()->where('meta_key', 'variant_upgrade_path')
            ->where('object_type', 'variant_upgrade')
            ->where('id', (int)Arr::get($data, 'path_id'))
            ->first();

        if (!$upgradePath) {
            die('Invalid Upgrade Path Provided!');
        }

        $targetVariantId = (int)Arr::get($data, 'target_id', 0);
        if (!in_array($targetVariantId, $upgradePath->meta_value['to_variants'])) {
            die('Invalid Upgrade Path Provided!');
        }

        $upgradeConfig = [
            'is_prorate'      => !!$upgradePath->meta_value['is_prorate'],
            'discount_amount' => Helper::toCent($upgradePath->meta_value['discount_amount']),
            'from_variant_id' => (int)$upgradePath->object_id,
            'to_variant_id'   => $targetVariantId,
        ];

        $originalItem = OrderItem::query()->where('order_id', $latestOrder->id)
            ->where('object_id', $upgradePath->object_id)
            ->first();

        if (!$originalItem) {
            die('Invalid Upgrade Path Provided! Original item not found in the order.');
        }


        if (Arr::get($originalItem->line_meta, 'parent_item_id')) {
            die('Upgrade is not allowed for bundle items.');
        }

        $upgradeToItem = ProductVariation::query()
            ->where('id', $targetVariantId)
            ->first();

        if (!$upgradeToItem) {
            die('Invalid Upgrade Path Provided! Target variant not found.');
        }

        $canPurchase = $upgradeToItem->canPurchase(1);
        if (is_wp_error($canPurchase)) {
            die($canPurchase->get_error_message());
        }

        $newItem = $upgradeToItem->toArray();

        $proRateCredit = 0;
        if ($upgradeConfig['is_prorate']) {
            $proRateCredit = PlanUpgradeService::calculateUpgradeToDiscount($latestOrder, $originalItem);
        }

        $totalCredit = $proRateCredit + Arr::get($upgradeConfig, 'discount_amount', 0);

        Arr::set($newItem, 'post_title', $upgradeToItem->product->post_title);
        Arr::set($newItem, 'variation_type', $upgradeToItem->product_detail->variation_type);


        $estimatedPrice = Arr::get($newItem, 'item_price', 0);
        if ($totalCredit > $estimatedPrice) {
            $totalCredit = $estimatedPrice;
        }

        if ($totalCredit) {
            $newItem['manual_discount'] = $totalCredit;
        }

        // on upgrade, trial days is not applicable for now
        if (Arr::get($newItem, 'other_info.trial_days', 0) > 0) {
            $newItem['other_info']['trial_days'] = 0;
        }

        $instantCart = CartHelper::generateCartFromCustomVariation($newItem);
        $instantCart->cart_group = 'instant';
        $instantCart->first_name = $parentOrder->customer->first_name;
        $instantCart->last_name = $parentOrder->customer->last_name;
        $instantCart->email = $parentOrder->customer->email;
        $instantCart->customer_id = $parentOrder->customer->customer_id;
        $instantCart->user_id = $parentOrder->customer->user_id;
        $instantCart->cart_hash = md5('upgrade_cart_' . wp_generate_uuid4() . time());
        $instantCart->checkout_data = [
            'is_locked'                       => 'no',
            'upgrade_data'                    => [
                'upgrade_from_order_id' => $parentOrder->id,
                'upgrade_path_id'       => $upgradePath->id,
                'from_variant_id'       => $upgradeConfig['from_variant_id'],
                'total_credit'          => $totalCredit,
            ],
            '__on_success_actions__'          => [
                'fluent_cart/upgrade_plan_completed'
            ],
            '__after_draft_created_actions__' => [
                'fluent_cart/upgraded_order_created'
            ],
            '__cart_notices'                  => [
                [
                    'id'      => 'upgrade_notice',
                    'type'    => 'info',
                    'content' => sprintf(
                        /* translators: %1$s Item Title, %2$s Variation Title */
                        __('You are upgrading your plan from %1$s to %2$s.','fluent-cart-pro'),
                        $originalItem->title,
                        $upgradeToItem->variation_title
                    ),
                ]
            ],
            'manual_discount'                 => [
                'amount' => $totalCredit,
                'title'  => __('Upgrade Discount', 'fluent-cart-pro')
            ]
        ];

        $instantCart->save();

        $cartHash = $instantCart->cart_hash;
        $checkoutUrl = add_query_arg(
            [
                'fct_cart_hash' => $cartHash,
            ],
            (new StoreSettings())->getCheckoutPage()
        );

        wp_redirect($checkoutUrl);
        exit();
    }

    public function handleUpgradePlanCompleted($data)
    {
        $cartModel = Arr::get($data, 'cart', null);
        $newOrder = Arr::get($data, 'order', null);

        $upgradeFromOrderId = Arr::get($cartModel->checkout_data, 'upgrade_data.upgrade_from_order_id', 0);

        $upgradeFromOrder = Order::query()->find($upgradeFromOrderId);

        if ($upgradeFromOrder) {
            $prevConfig = $upgradeFromOrder->config;
            $prevConfig['upgraded_to'] = $newOrder->id;
            $upgradeFromOrder->config = $prevConfig;
            $upgradeFromOrder->save();

            $oldSubscription = Subscription::query()->where('parent_order_id', $upgradeFromOrder->id)->first();
            $newSubscription = Subscription::query()->where('parent_order_id', $newOrder->id)->first();

            if ($oldSubscription) {
                $prevConfig = $oldSubscription->config;
                $prevConfig['upgraded_to_sub_id'] = $newSubscription ? $newSubscription->id : 0;
                $prevConfig['upgraded_to_order_id'] = $newOrder->id;
                $oldSubscription->config = $prevConfig;
                $oldSubscription->save();

                // Canceling old subscription due to upgrade
                $oldSubscription->cancelRemoteSubscription([
                    'fire_hooks' => false,
                    'reason'     => 'upgraded_to_new_plan'
                ]);

            }

            if ($newSubscription) {
                $newConfig = $newSubscription->config;
                $newConfig['is_upgraded'] = 'yes';
                $newConfig['upgraded_from_sub_id'] = $oldSubscription ? $oldSubscription->id : 0;
                $newConfig['upgraded_from_order_id'] = $upgradeFromOrder ? $upgradeFromOrder->id : 0;
                $newSubscription->config = $newConfig;
                $newSubscription->save();
            }

            do_action('fluent_cart/order/upgraded', [
                'order'           => $newOrder,
                'from_order'      => $upgradeFromOrder,
                'cart'            => $cartModel,
                'from_variant_id' => Arr::get($cartModel->checkout_data, 'upgrade_data.from_variant_id', 0),
                'transaction'     => Arr::get($data, 'transaction', null)
            ]);

        }

    }

    public function handleUpgradedOrderCreated($data)
    {
        $cartModel = Arr::get($data, 'cart', null);
        if (!$cartModel) {
            return;
        }

        $upgradeFromOrderId = Arr::get($cartModel->checkout_data, 'upgrade_data.upgrade_from_order_id', 0);
        if (!$upgradeFromOrderId) {
            return;
        }

        $createdOrder = $data['order'];
        $prevConfig = $createdOrder->config;
        $prevConfig['upgraded_from'] = $upgradeFromOrderId;
        if ($proratedCredit = Arr::get($cartModel->checkout_data, 'upgrade_data.prorated_credit', 0)) {
            $prevConfig['prorated_credit'] = $proratedCredit;
        }

        if ($discountAmount = Arr::get($cartModel->checkout_data, 'upgrade_data.discount_amount', 0)) {
            $prevConfig['discounted_amount'] = $discountAmount;
        }

        $createdOrder->config = $prevConfig;
        $createdOrder->save();
    }
}
