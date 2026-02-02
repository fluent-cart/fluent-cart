<?php

namespace FluentCartPro\App\Modules\Promotional\OrderBump;

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\Promotional\Models\OrderPromotion;


class OrderBumpBoot
{
    public function register()
    {
        add_action('fluent_cart/after_order_notes', [$this, 'maybeShowBumps']);

        add_filter('fluent_cart/apply_order_bump', [$this, 'applyOrderBump'], 10, 2);

        add_action('fluent_cart/loading_app', function () {
            Vite::enqueueScript('fluent_cart_order_bump_scripts', 'order-bump/order-bump.js');
        });
    }

    public function maybeShowBumps($data)
    {
        $cart = $data['cart'];
        $cartData = $cart->cart_data;

        $addedPromotionIds = [];
        $objectIds = [];

        foreach ($cartData as $cartItem) {
            $objectIds[] = $cartItem['object_id'];
            if (isset($cartItem['other_info']['promotion_id'])) {
                $addedPromotionIds[] = $cartItem['other_info']['promotion_id'];
            }
        }

        $bumps = OrderPromotion::query()->where('type', 'order_bump')
            ->where('status', 'active')
            ->orderBy('priority', 'DESC')
            ->get();

        if ($bumps->isEmpty()) {
            return;
        }

        $formattedBumps = [];


        $hasSubscription = $cart->hasSubscription();

        foreach ($bumps as $bump) {
            $isAdded = in_array($bump->id, $addedPromotionIds);
            if (!$isAdded) {
                if (in_array($bump->src_object_id, $objectIds)) {
                    continue; // Skip if the bump item is already in the cart
                }
            }

            if ($hasSubscription && $bump->product_variant && $bump->product_variant->payment_type === 'subscription') {
                continue; // Skip subscription bumps if the cart has a subscription
            }

            if (count($formattedBumps) >= 5 || !$bump->product_variant) {
                break; // Limit to 5 bumps
            }

            $isValid = $this->isConditonsMatched($bump, [
                'cart'       => $cart,
                'object_ids' => $objectIds
            ]);

            if (!$isValid) {
                continue;
            }

            $formattedBumps[] = [
                'id'              => $bump->id,
                'title'           => $bump->title,
                'description'     => $bump->description,
                'product_variant' => $bump->product_variant,
                'discount'        => $this->getDiscountAmount($bump),
                'variant_id'      => $bump->src_object_id,
                'is_added'        => $isAdded,
            ];
        }

        if (!$formattedBumps) {
            return;
        }

        $sectionTitle = __('Recommended', 'fluent-cart-pro');
        if (!empty(Arr::get($data, 'section_title', ''))) {
            $sectionTitle = Arr::get($data, 'section_title');
        }
        ?>

        <div data-fct_order_bump_wrap class="fct_order_bump_wrap">
            <style>
                .fct_order_promotions_wrap {
                    margin-bottom: 20px;
                    margin-top: 20px;
                }

                .fct_order_promotions {
                    border: 1px solid #e1e1e1;
                    border-radius: 6px;
                    background: #fff;
                }

                .fct_bump_details {
                    flex: 1;
                }

                label.fct_order_promotion_label {
                    cursor: pointer;
                    display: block;
                }

                .fct_order_promotion {
                    display: flex;
                    column-gap: 10px;
                    padding: 10px 15px;
                    align-items: flex-start;
                    border-bottom: 1px solid #e1e1e1;
                }

                .fct_order_promotion:last-child {
                    border-bottom: none;
                }

                .fct_order_promotion .fct_order_promotion_input {
                    min-height: auto;
                }

                .fct_order_promotion_title {
                    font-weight: 500;
                }
            </style>
            <div class="fct_order_promotions_wrap fct_checkout_form_section">
                <div class="fct_form_section_header">
                    <h4 class="fct_form_section_header_label"><?php echo esc_html($sectionTitle); ?></h4>
                </div>
                <div class="fct_form_section_body">
                    <div class="fct_order_promotions">
                        <?php
                        foreach ($formattedBumps as $formattedBump) {
                            $postTitle = Arr::get($formattedBump, 'product_variant.product.post_title', '');
                            $variationTitle = Arr::get($formattedBump, 'product_variant.variation_title', '');
                            ?>
                            <div class="fct_order_promotion">
                                <div class="fct_order_promotion_checkbox">
                                    <input type="checkbox" class="fct_order_promotion_input"
                                           data-fct_order_bump
                                        <?php echo $formattedBump['is_added'] ? 'checked' : ''; ?>
                                           data-fct_bump_id="<?php echo esc_attr($formattedBump['id']); ?>"
                                           id="fct_order_promotion_<?php echo esc_attr($formattedBump['id']); ?>">
                                </div>
                                <div class="fct_bump_details">
                                    <label for="fct_order_promotion_<?php echo esc_attr($formattedBump['id']); ?>"
                                           class="fct_order_promotion_label">
                                        <div class="fct_order_promotion_title">
                                            <?php echo esc_html($formattedBump['title']); ?>
                                        </div>
                                        <div class="fct_order_promotion_product_title">
                                            <?php echo esc_html(Arr::get($formattedBump, 'product_variant.product.post_title', '')); ?>
                                        </div>
                                        <?php if (!empty($variationTitle) && $postTitle !== $variationTitle): ?>
                                            <div class="fct_order_promotion_variation_title">
                                                - <?php echo wp_kses_post($variationTitle); ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="fct_order_promotion_price">
                                            <?php
                                            $price = $formattedBump['product_variant']->item_price;
                                            $discount = $formattedBump['discount'];

                                            if (!empty($discount) && $price) {
                                                $discountedPrice = max(0, $price - $discount);
                                                echo '<span class="fct_order_promotion_original_price" style="text-decoration: line-through; color: #888; margin-right: 8px;">' . Helper::toDecimal($price) . '</span>';
                                                echo '<span class="fct_order_promotion_discounted_price" style="font-weight: 600; color: #000;">' . Helper::toDecimal($discountedPrice) . '</span>';
                                                echo sprintf(
                                                    '<span class="fct_order_promotion_discount" style="color: #d9534f; margin-left: 8px;">(%s %s)</span>',
                                                    esc_html__('Save', 'fluent-cart-pro'),
                                                    esc_html(Helper::toDecimal($discount))
                                                );
                                            } else {
                                                echo '<span class="fct_order_promotion_price" style="font-weight: 600; color: #000;">' . Helper::toDecimal($price) . '</span>';
                                            }
                                            ?>
                                            <?php if (!empty($formattedBump['description'])): ?>
                                                <div
                                                    class="fct_order_promotion_description"><?php echo wp_kses_post($formattedBump['description']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </label>
                                </div>
                            </div>
                            <?php
                        }
                        ?>

                    </div>
                </div>
            </div>
        </div>

        <?php
    }

    public function applyOrderBump($responseData, $data)
    {
        $cart = $data['cart'];
        $bumpId = isset($data['bump_id']) ? intval($data['bump_id']) : 0;

        if (!$bumpId) {
            return new \WP_Error('no_bump_id', 'No bump id provided');
        }

        $bump = OrderPromotion::query()->where('id', $bumpId)
            ->where('type', 'order_bump')
            ->where('status', 'active')
            ->first();

        if (!$bump) {
            return new \WP_Error('invalid_bump', 'Invalid bump');
        }

        $prevShippingRequired = $cart->requireShipping();

        $cartData = $cart->cart_data;
        $willAdd = Arr::get($data, 'request_data.is_upgraded', '') === 'yes';

        if (!$willAdd) {
            $objectId = $bump->src_object_id;
            $cart->removeItem($objectId);

            return [
                'message'          => __('Item has been removed from your cart', 'fluent-cart-pro'),
                'shipping_changed' => $prevShippingRequired != $cart->requireShipping()
            ];
        }

        $hasSubscription = $cart->hasSubscription();
        if ($hasSubscription && $bump->product_variant && $bump->product_variant->payment_type === 'subscription') {
            return new \WP_Error('subscription_bump_not_allowed', 'You can not add a subscription bump when your cart has a subscription product.');
        }

        $exitingOrderObjectIds = [];
        foreach ($cartData as $cartItem) {
            $exitingOrderObjectIds[] = $cartItem['object_id'];
        }

        if ($willAdd && in_array($bump->src_object_id, $exitingOrderObjectIds)) {
            return new \WP_Error('bump_already_in_cart', 'Item already in cart');
        }

        $variant = $bump->product_variant;
        if (!$variant) {
            return new \WP_Error('invalid_variant', 'Invalid variant');
        }

        $isValid = $this->isConditonsMatched($bump, [
            'cart'       => $cart,
            'object_ids' => $exitingOrderObjectIds
        ]);

        if (!$isValid) {
            return new \WP_Error('conditions_not_met', 'Sorry, you can not buy this item now.');
        }

        $discountAmount = $this->getDiscountAmount($bump);
        $originalPrice = $variant->item_price;

        if ($discountAmount) {
            $variant->item_price = max(0, ($variant->item_price - $discountAmount));
        }

        $otherInfo = [
            'promotion_id'         => $bump->id,
            'promotion_type'       => 'order_bump',
            'promo_price_original' => $originalPrice,
        ];

        if (Arr::get($bump->config, 'allow_coupon', 'no') !== 'yes') {
            $otherInfo['is_locked'] = 'yes';
        }

        if (Arr::get($bump->config, 'free_shipping', 'no') === 'yes') {
            $otherInfo['free_shipping'] = 'yes';
        }

        $cart = $cart->addByVariation($variant, [
            'other_info' => $otherInfo,
            'quantity'   => Arr::get($data, 'request_data.quantity', 1)
        ]);

        if (is_wp_error($cart)) {
            return $cart;
        }

        return [
            'message'          => __('Item has been added to your cart', 'fluent-cart-pro'),
            'shipping_changed' => $prevShippingRequired != $cart->requireShipping()
        ];
    }

    protected function isConditonsMatched(OrderPromotion $bump, $args = [])
    {
        if (Arr::get($bump->conditions, 'is_enabled') !== 'yes') {
            return true;
        }

        $cart = Arr::get($args, 'cart', null);

        $conditionGroups = Arr::get($bump->conditions, 'groups', []);

        if (!$conditionGroups) {
            return true;
        }

        $objectIds = Arr::get($args, 'object_ids', []);

        foreach ($conditionGroups as $conditionGroup) {
            if (!$conditionGroup) {
                continue;
            }


            $isAllMatched = true;
            foreach ($conditionGroup as $condition) {
                if (!$isAllMatched) {
                    break;
                }

                $key = Arr::get($condition, 'key');
                $operator = Arr::get($condition, 'operator');
                $value = Arr::get($condition, 'value');

                if ($key === 'order_items') {
                    if (!is_array($value) || !$value) {
                        continue;
                    }
                    $hasCommon = !!array_intersect($value, $objectIds);

                    if ($operator === 'exist') {
                        $isAllMatched = $hasCommon;
                    } else {
                        $isAllMatched = !$hasCommon;
                    }
                } else if ($key === 'order_subtotal') {
                    $subtotal = $cart->getItemsSubtotal();
                    if ($operator === 'greater_than') {
                        $isAllMatched = $subtotal >= Helper::toCent($value);
                    } else if ($operator === 'less_than') {
                        $isAllMatched = $subtotal <= Helper::toCent($value);
                    } else if ($operator === 'equals') {
                        $isAllMatched = $subtotal == Helper::toCent($value);
                    } else if ($operator === 'not_equals') {
                        $isAllMatched = $subtotal != Helper::toCent($value);
                    } else {
                        $isAllMatched = false;
                    }
                }
            }

            if ($isAllMatched) {
                return true;
            }
        }

        return false;
    }

    protected function getDiscountAmount(OrderPromotion $bump)
    {
        $discountAmount = Helper::toCent(Arr::get($bump->config, 'discount.discount_amount', 0));
        if (Arr::get($bump->config, 'discount.discount_type') === 'percentage') {
            $discountAmount = (int)round(($bump->product_variant->item_price * $discountAmount / 100) / 100);
        }

        return $discountAmount;
    }
}
