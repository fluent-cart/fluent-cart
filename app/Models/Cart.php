<?php

namespace FluentCart\App\Models;

use FluentCart\Api\Cookie\Cookie;
use FluentCart\Api\CurrencySettings;
use FluentCart\Api\Hasher\Hash;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Concerns\CanSearch;
use FluentCart\App\Services\CheckoutService;
use FluentCart\App\Services\OrderService;
use FluentCart\Framework\Database\Orm\Relations\BelongsTo;
use FluentCart\Framework\Database\Orm\SoftDeletes;
use FluentCart\Framework\Support\Arr;

/**
 *  Cart Session Model - DB Model for Carts
 *
 *  Database Model
 *
 * @package FluentCart\App\Models
 *
 * @version 1.0.0
 */
class Cart extends Model
{
    use CanSearch;

    protected $primaryKey = 'cart_hash';
    public $incrementing = false;
    protected $table = 'fct_carts';

    protected $hidden = ['order_id', 'customer_id', 'user_id'];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'customer_id',
        'user_id',
        'order_id',
        'cart_hash',
        'checkout_data',
        'cart_data',
        'utm_data',
        'coupons',
        'first_name',
        'last_name',
        'email',
        'stage',
        'cart_group',
        'user_agent',
        'ip_address',
        'completed_at',
        'deleted_at',
    ];

    public static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (empty($model->cart_hash)) {
                $model->cart_hash = md5('fct_global_cart_' . wp_generate_uuid4() . time());
            }
        });
    }

    public function setCheckoutDataAttribute($settings)
    {
        $this->attributes['checkout_data'] = json_encode(
            Arr::wrap($settings)
        );
    }

    public function getCheckoutDataAttribute($settings)
    {
        if (!$settings) {
            return [];
        }
        $decoded = json_decode($settings, true);

        if (!$decoded || !is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    public function setCouponsAttribute($coupons)
    {
        if (!$coupons || !is_array($coupons)) {
            $coupons = [];
        }

        $this->attributes['coupons'] = json_encode($coupons);
    }

    public function getCouponsAttribute($coupons)
    {
        if (!$coupons) {
            return [];
        }
        $decoded = json_decode($coupons, true);

        if (!$decoded || !is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    public function setCartDataAttribute($settings)
    {
        $this->attributes['cart_data'] = json_encode(
            Arr::wrap($settings)
        );
    }

    public function getCartDataAttribute($settings)
    {
        if (!$settings) {
            return [];
        }
        return json_decode($settings, true);
    }

    public function setUtmDataAttribute($utmData)
    {
        $this->attributes['utm_data'] = json_encode(
            Arr::wrap($utmData)
        );
    }

    public function getUtmDataAttribute($utmData)
    {
        if (!$utmData) {
            return [];
        }
        return json_decode($utmData, true);
    }


    /**
     * One2One: Order belongs to one Customer
     *
     * @return BelongsTo
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id', 'id');
    }

    /**
     * One2One: Order belongs to one Customer
     *
     * @return BelongsTo
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    public function scopeStageNotCompleted($query)
    {
        return $query->where('stage', '!=', 'completed');
    }

    public function isLocked()
    {
        return Arr::get($this->checkout_data, 'is_locked') === 'yes' && $this->order_id;
    }

    public function addItem($item = [], $replacingIndex = null)
    {
        if ($this->isLocked()) {
            return new \WP_Error('cart_locked', __('This cart is locked and cannot be modified.', 'fluent-cart'));
        }
        $cartData = $this->cart_data;
        if ($replacingIndex !== null && isset($cartData[$replacingIndex])) {
            $cartData[$replacingIndex] = $item;
        } else {
            $cartData[] = $item;
        }

        $this->cart_data = array_values($cartData);
        $this->save();

        $this->reValidateCoupons();

        do_action('fluent_cart/cart/item_added', [
            'cart' => $this,
            'item' => $item
        ]);

        do_action('fluent_cart/cart/cart_data_items_updated', [
            'cart'       => $this,
            'scope'      => 'item_added',
            'scope_data' => $item
        ]);

        return $this;
    }

    public function removeItem($variationId, $extraArgs = [], $triggerEvent = true)
    {
        if ($this->isLocked()) {
            return new \WP_Error('cart_locked', __('This cart is locked and cannot be modified.', 'fluent-cart'));
        }

        $cartData = array_values($this->cart_data);

        if (!$cartData) {
            return $this;
        }

        $existingItemArr = $this->findExistingItemAndIndex($variationId, $extraArgs);
        if (!$existingItemArr) {
            return $this;
        }

        $targetIndex = $existingItemArr[0];
        $removingItem = $existingItemArr[1];

        unset($cartData[$targetIndex]);
        $this->cart_data = array_values($cartData);
        $this->save();

        if ($triggerEvent) {
            $this->reValidateCoupons();
            do_action('fluent_cart/cart/item_removed', [
                'cart'         => $this,
                'variation_id' => $variationId,
                'extra_args'   => $extraArgs,
                'removed_item' => $removingItem
            ]);
        } else {
            do_action('fluent_cart/checkout/cart_amount_updated', [
                'cart' => $this
            ]);
        }

        do_action('fluent_cart/cart/cart_data_items_updated', [
            'cart'       => $this,
            'scope'      => 'item_removed',
            'scope_data' => $variationId
        ]);

        return $this;
    }

    public function addByVariation(ProductVariation $variation, $config = [])
    {
        $quantity = (int)Arr::get($config, 'quantity', 1);
        $byInput = Arr::get($config, 'by_input', false);

        if ($quantity == 0) {
            // that means we have to remove it
            return $this->removeItem($variation->id, Arr::get($config, 'remove_args', []), true);
        }

        $validate = Arr::get($config, 'will_validate', false);

        $replacingIndex = null;

        if (Arr::get($config, 'replace')) {
            $this->removeItem($variation->id, Arr::get($config, 'remove_args', []), false);
        } else {
            $existingItem = $this->findExistingItemAndIndex($variation->id, Arr::get($config, 'matched_args', []));
            if ($existingItem) {
                $prevItem = $existingItem[1];
                $replacingIndex = $existingItem[0];
                if ($prevItem) { // it's promotional item. So we will just use the previous set price
                    if (!$byInput) {
                        $quantity += (int)Arr::get($prevItem, 'quantity', 1);
                    }
                    if (Arr::get($prevItem, 'other_info.promotion_id') || Arr::get($prevItem, 'other_info.is_price_locked') === 'yes') {
                        $unitPrice = Arr::get($prevItem, 'unit_price', 0);
                        if ($unitPrice) {
                            $variation->item_price = $unitPrice;
                        }

                        $providedOtherInfo = Arr::get($config, 'other_info', []);
                        $existingOtherInfo = Arr::get($prevItem, 'other_info', []);
                        $config['other_info'] = wp_parse_args($existingOtherInfo, $providedOtherInfo);
                    }
                }
            }
        }

        if ($quantity <= 0) {
            // remove the item if quantity is zero or negative after adjustment
            return $this->removeItem($variation->id);
        }

        if ($validate) {
            $canPurchase = $variation->canPurchase($quantity);
            $canPurchase = apply_filters('fluent_cart/cart/can_purchase', $canPurchase, [
                'cart'      => $this,
                'variation' => $variation,
                'quantity'  => $quantity
            ]);
            if (is_wp_error($canPurchase)) {
                return $canPurchase;
            }

            if ($this->isLocked()) {
                return new \WP_Error('cart_locked', __('This cart is locked and cannot be modified.', 'fluent-cart'));
            }
        }

        $item = CartHelper::generateCartItemFromVariation($variation, $quantity);
        $otherInfoExtras = Arr::get($config, 'other_info', []);
        if ($otherInfoExtras) {
            $item['other_info'] = wp_parse_args($otherInfoExtras, $item['other_info']);
        }

        return $this->addItem($item, $replacingIndex);
    }

    public function guessCustomer()
    {
        if ($this->customer_id) {
            return Customer::find($this->customer_id);
        }

        if ($this->user_id) {
            $customer = Customer::where('user_id', $this->user_id)->first();
            if ($customer) {
                return $customer;
            }
        }

        if ($this->email) {
            $customer = Customer::where('email', $this->email)->first();
            if ($customer) {
                return $customer;
            }
        }

        return null;
    }

    public function reValidateCoupons()
    {
        if (!$this->coupons) {
            return $this;
        }

        if ($this->isLocked()) {
            return new \WP_Error('cart_locked', __('This cart is locked and cannot be modified.', 'fluent-cart'));
        }

        $prevDiscountTotal = array_sum(array_map(function ($item) {
            return (int)Arr::get($item, 'discount_total', 0);
        }, $this->cart_data ?? []));

        $discountService = new \FluentCart\App\Services\Coupon\DiscountService($this);
        $discountService->resetIndividualItemsDiscounts();
        $discountService->applyCouponCodes($this->coupons);

        $this->coupons = $discountService->getAppliedCoupons();
        $this->cart_data = $discountService->getCartItems();

        $checkoutData = $this->checkout_data;
        if (!is_array($checkoutData)) {
            $checkoutData = [];
        }

        $checkoutData['__per_coupon_discounts'] = $discountService->getPerCouponDiscounts();
        $this->checkout_data = $checkoutData;

        $this->save();

        $newDiscountTotal = array_sum(array_map(function ($item) {
            return (int)Arr::get($item, 'discount_total', 0);
        }, $this->cart_data ?? []));

        do_action('fluent_cart/checkout/cart_amount_updated', [
            'cart' => $this
        ]);

        if ($newDiscountTotal != $prevDiscountTotal) {
            do_action('fluent_cart/cart/cart_data_items_updated', [
                'cart'       => $this,
                'scope'      => 'discounts_recalculated',
                'scope_data' => $this->coupons
            ]);
        }

        return $this;

    }

    public function removeCoupon($removeCodes = [])
    {
        if (!is_array($removeCodes)) {
            $removeCodes = [$removeCodes];
        }

        if ($this->isLocked()) {
            return new \WP_Error('cart_locked', __('This cart is locked and cannot be modified.', 'fluent-cart'));
        }

        $this->coupons = array_filter($this->coupons, function ($code) use ($removeCodes) {
            return !in_array($code, $removeCodes);
        });

        $discountService = new \FluentCart\App\Services\Coupon\DiscountService($this);

        $discountService->resetIndividualItemsDiscounts();
        $discountService->revalidateCoupons();

        $this->cart_data = $discountService->getCartItems();
        $this->coupons = $discountService->getAppliedCoupons();

        $checkoutData = $this->checkout_data;
        if (!is_array($checkoutData)) {
            $checkoutData = [];
        }

        $checkoutData['__per_coupon_discounts'] = $discountService->getPerCouponDiscounts();
        $this->checkout_data = $checkoutData;

        $this->save();

        do_action('fluent_cart/checkout/cart_amount_updated', [
            'cart' => $this
        ]);


        do_action('fluent_cart/cart/cart_data_items_updated', [
            'cart'       => $this,
            'scope'      => 'remove_coupon',
            'scope_data' => $removeCodes
        ]);

        return $this;
    }

    public function applyCoupon($codes = [])
    {
        if ($this->isLocked()) {
            return new \WP_Error('cart_locked', __('This cart is locked and cannot be modified.', 'fluent-cart'));
        }

        $discountService = new \FluentCart\App\Services\Coupon\DiscountService($this);
        $result = $discountService->applyCouponCodes($codes);
        if (is_wp_error($result)) {
            return $result;
        }

        $this->coupons = $discountService->getAppliedCoupons();
        $this->cart_data = $discountService->getCartItems();


        $checkoutData = $this->checkout_data;
        if (!is_array($checkoutData)) {
            $checkoutData = [];
        }

        $checkoutData['__per_coupon_discounts'] = $discountService->getPerCouponDiscounts();
        $this->checkout_data = $checkoutData;

        $this->save();

        do_action('fluent_cart/checkout/cart_amount_updated', [
            'cart' => $this
        ]);

        do_action('fluent_cart/cart/cart_data_items_updated', [
            'cart'       => $this,
            'scope'      => 'apply_coupons',
            'scope_data' => $codes
        ]);

        return $discountService->getResult();
    }

    public function getDiscountLines($revalidate = false)
    {
        if (!$this->coupons) {
            return [];
        }

        if ($revalidate) {
            $this->applyCoupon($this->coupons);
        }

        $coupons = Coupon::whereIn('code', $this->coupons)->get();

        if ($coupons->isEmpty()) {
            return [];
        }

        if ($coupons->count() === 1) {
            $coupon = $coupons->first();
            $discounts = array_sum(array_map(function ($item) {
                return (int)Arr::get($item, 'coupon_discount', 0);
            }, $this->cart_data ?? []));

            $formattedTitle = $coupon->code;
            if ($coupon->type === 'percentage') {
                $formattedTitle .= ' (' . $coupon->amount . '%)';
            }

            $data = [
                'id'                        => $coupon->id,
                'code'                      => $coupon->code,
                'type'                      => $coupon->discount_type,
                'discount'                  => $discounts,
                'formatted_discount'        => CurrencySettings::getPriceHtml($discounts),
                'actual_formatted_discount' => CurrencySettings::getPriceHtml($discounts),
                'formatted_title'           => $formattedTitle
            ];

            return [
                $coupon->code => $data
            ];
        }


        $formattedData = [];

        foreach ($coupons as $coupon) {

            $formattedTitle = $coupon->code;
            if ($coupon->type === 'percentage') {
                $formattedTitle .= ' (' . $coupon->amount . '%)';
            }

            $amount = Arr::get($this->checkout_data, '__per_coupon_discounts.' . $coupon->code, 0);

            $formattedData[$coupon->code] = [
                'id'                        => $coupon->id,
                'code'                      => $coupon->code,
                'type'                      => $coupon->discount_type,
                'discount'                  => $amount,
                'formatted_discount'        => CurrencySettings::getPriceHtml($amount),
                'actual_formatted_discount' => CurrencySettings::getPriceHtml($amount),
                'formatted_title'           => $formattedTitle
            ];
        }

        return $formattedData;
    }

    public function hasSubscription()
    {
        if (!empty($this->cart_data)) {
            foreach ($this->cart_data as $item) {
                if (Arr::get($item, 'other_info.payment_type') === 'subscription') {
                    return true;
                }
            }
        }

        return false;
    }

    public function requireShipping()
    {
        if (!empty($this->cart_data)) {
            foreach ($this->cart_data as $item) {
                if (Arr::get($item, 'fulfillment_type') === 'physical') {
                    return true;
                }
            }
        }

        return false;
    }

    public function getShippingTotal()
    {
        return (int)Arr::get($this->checkout_data ?? [], 'shipping_data.shipping_charge', 0);
    }

    public function getItemsSubtotal()
    {
        $checkoutItems = new CheckoutService($this->cart_data);
        $subscriptionItems = $checkoutItems->subscriptions;
        $onetimeItems = $checkoutItems->onetime;

        $items = array_merge($onetimeItems, $subscriptionItems);
        return OrderService::getItemsAmountWithoutDiscount($items);
    }

    public function getEstimatedTotal($extraAmount = 0)
    {
        $checkoutItems = new CheckoutService($this->cart_data);

        $subscriptionItems = $checkoutItems->subscriptions;
        $onetimeItems = $checkoutItems->onetime;

        $items = array_merge($onetimeItems, $subscriptionItems);

        $total = OrderService::getItemsAmountTotal($items, false, false, $extraAmount);

        $shippingTotal = $this->getShippingTotal();

        if ($shippingTotal) {
            $total += $shippingTotal;
        }

        if (Arr::get($this->checkout_data, 'custom_checkout') === 'yes' && !$shippingTotal) {
            $customShippingAmount = (int)Arr::get($this->checkout_data, 'custom_checkout_data.shipping_total', 0);
            // $customerDiscountAmount = (int)Arr::get($this->checkout_data, 'custom_checkout_data.discount_total', 0);
            // $total -= $customerDiscountAmount;
            $total += $customShippingAmount;
        }

        if ($total < 0) {
            $total = 0;
        }

        return apply_filters('fluent_cart/cart/estimated_total', $total, [
            'cart' => $this
        ]);
    }

    protected function findExistingItemAndIndex($objectId, $extraArgs = [])
    {
        $cartData = array_values($this->cart_data);

        if (!$cartData) {
            return null;
        }

        foreach ($cartData as $index => $item) {
            if (Arr::get($item, 'object_id') == $objectId) {
                $match = true;

                if ($extraArgs) {
                    foreach ($extraArgs as $key => $value) {
                        if (Arr::get($item, $key) != $value) {
                            $match = false;
                            break;
                        }
                    }
                }

                if ($match) {
                    return [$index, $item];
                }
            }
        }

        return null;
    }

    public function getShippingAddress()
    {
        $checkoutData = $this->checkout_data;

        if (!is_array($checkoutData)) {
            return [];
        }

        $formData = Arr::get($checkoutData, 'form_data', []);
        if ($this->isShipToDifferent()) {
            return [
                'full_name' => Arr::get($formData, 'shipping_full_name', ''),
                'company'   => Arr::get($formData, 'shipping_company_name', ''),
                'address_1' => Arr::get($formData, 'shipping_address_1', ''),
                'address_2' => Arr::get($formData, 'shipping_address_2', ''),
                'city'      => Arr::get($formData, 'shipping_city', ''),
                'state'     => Arr::get($formData, 'shipping_state', ''),
                'postcode'  => Arr::get($formData, 'shipping_postcode', ''),
                'country'   => Arr::get($formData, 'shipping_country', ''),
            ];
        }

        return $this->getBillingAddress();
    }

    public function getBillingAddress()
    {
        $checkoutData = $this->checkout_data;

        if (!is_array($checkoutData)) {
            return [];
        }

        $formData = Arr::get($checkoutData, 'form_data', []);

        return [
            'full_name' => Arr::get($formData, 'billing_full_name', ''),
            'company'   => Arr::get($formData, 'billing_company', ''),
            'address_1' => Arr::get($formData, 'billing_address_1', ''),
            'address_2' => Arr::get($formData, 'billing_address_2', ''),
            'city'      => Arr::get($formData, 'billing_city', ''),
            'state'     => Arr::get($formData, 'billing_state', ''),
            'postcode'  => Arr::get($formData, 'billing_postcode', ''),
            'country'   => Arr::get($formData, 'billing_country', ''),
        ];
    }

    public function isZeroPayment()
    {
        return !$this->getEstimatedTotal() && !$this->hasSubscription();
    }

    public function isShipToDifferent()
    {
        return Arr::get($this->checkout_data, 'form_data.ship_to_different') === 'yes';
    }

}
