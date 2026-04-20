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
     * Static cache for loaded cart data with bundle children
     * Keyed by cart_hash (primary key)
     *
     * @var array
     */
    private static $cache = [];

    /**
     * Per-request cache for computed fees.
     * @var array|null
     */
    private $cachedFees = null;

    /**
     * Recursion guard for getFees() to prevent infinite loops.
     * @var bool
     */
    private $isCalculatingFees = false;

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

        $key = $this->getKey();
        if ($key) {
            unset(static::$cache[$key]);
        }
    }


    public function getCartDataAttribute($data): array
    {
        if (!$data) {
            return [];
        }

        $key = $this->getKey();
        
        if ($key && isset(static::$cache[$key])) {
            return static::$cache[$key];
        }

        $decoded = json_decode($data, true);
        
        if (!$decoded || !is_array($decoded)) {
            $result = [];
        } else {
            $result = Helper::loadBundleChild($decoded, ['*']);
        }

        if ($key) {
            static::$cache[$key] = $result;
        }

        return $result;
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

    public function addByCustom(array $variation, array $config = [])
    {
        $variation = CartHelper::normalizeCustomFields(
            is_object($variation) ? $variation : (object) $variation
        );

        $variation = is_array($variation)
            ? $variation
            : (array) $variation;


        if (!is_array($variation)) {
            return new \WP_Error(
                'invalid_custom_item',
                __('Invalid custom item data.', 'fluent-cart')
            );
        }

        $quantity = (int)Arr::get($config, 'quantity', 1);
        $variationId = Arr::get($variation, 'id');

        if ($quantity == 0) {
            // that means we have to remove it
            return $this->removeItem(
                $variationId,
                Arr::get($config, 'remove_args', []),
                true
            );
        }

        $requiredFields = [
            'id',
            'object_id',
            'post_id',
            'post_title',
            'price',
            'unit_price',
            'payment_type'
        ];

        foreach ($requiredFields as $field) {
            if (
                !array_key_exists($field, $variation) ||
                $variation[$field] === '' ||
                $variation[$field] === null
            ) {
                // Missing required field → remove item
                //Invalid custom items are never allowed to persist in cart state. Silent removal here is intentional to avoid breaking cart update/recalculation flows.

                return $this->removeItem($variationId);
            }
        }

        // Subscription items may exist in cart, 
        // but checkout must be initiated via direct checkout flow to ensure proper handling.           
        if (Arr::get($variation, 'payment_type', null) === 'subscription') {
            return new \WP_Error('invalid_item', __('Subscription items must be purchased via direct checkout.', 'fluent-cart'));

        }

        // Find existing item in cart
        $replacingIndex = null; 
        $existingItem = $this->findExistingItemAndIndex(
            $variationId,
            Arr::get($config, 'matched_args', [])
        );

        if ($existingItem) {
            $replacingIndex = $existingItem[0];
        }

        if ($quantity <= 0) {
            // remove the item if quantity is zero or negative after adjustment
            return $this->removeItem($variationId);
        }

        $item = CartHelper::generateCartItemCustomItem($variation, $quantity);

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

        $previousCartData = $this->cart_data;
        $previousCoupons = $this->coupons;
        $previousCheckoutData = $this->checkout_data;

        $discountService = new \FluentCart\App\Services\Coupon\DiscountService($this);
        $result = $discountService->applyCouponCodes($codes);
        if (is_wp_error($result)) {
            return $result;
        }

        $updatedCartItems = $discountService->getCartItems();

        $this->coupons = $discountService->getAppliedCoupons();
        $this->cart_data = $updatedCartItems;


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

    protected function hasZeroRecurringAmount(array $cartItems)
    {
        foreach ($cartItems as $item) {
            if (Arr::get($item, 'other_info.payment_type') !== 'subscription') {
                continue;
            }

            $recurringDiscount = (int)Arr::get($item, 'recurring_discounts.amount', 0);

            if ($recurringDiscount <= 0) {
                continue;
            }

            $unitPrice = (int)Arr::get($item, 'unit_price', 0);
            $remainingRecurring = $unitPrice - $recurringDiscount;

            if ($remainingRecurring <= 0) {
                return true;
            }
        }

        return false;
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
        if ($this->requireShipping()) {
            $shippingTotal = (int)Arr::get($this->checkout_data ?? [], 'shipping_data.shipping_charge', 0);
            return apply_filters('fluent_cart/cart/shipping_total', $shippingTotal, [
                'cart' => $this,
            ]);
        }
        return 0;
    }

    /**
     * Get all fees for this cart.
     * Reads persistent fees from checkout_data.fees and merges with
     * dynamically computed fees from the fluent_cart/cart/fees filter.
     * Uses per-request caching to avoid redundant DB reads and filter evaluations.
     *
     * @return array Validated fee items
     */
    public function getFees(): array
    {
        if ($this->cachedFees !== null) {
            return $this->cachedFees;
        }

        // Recursion guard — if a filter callback calls getFees(), return stored fees only
        if ($this->isCalculatingFees) {
            return $this->getStoredFees();
        }

        $this->isCalculatingFees = true;

        // Start with persistent (stored) fees
        $storedFees = $this->getStoredFees();

        // Custom payment: preserves the original order's charges.
        // Reactivation: renewals should not pick up dynamic fees.
        $isRenewal = Arr::get($this->checkout_data, 'renew_data.is_renewal') === 'yes';
        if ($this->isLocked() || $isRenewal) {
            $this->isCalculatingFees = false;
            $this->cachedFees = $this->validateFees($storedFees);
            return $this->cachedFees;
        }

        // Resolve payment method: prefer explicit key, fall back to form data
        $paymentMethod = Arr::get($this->checkout_data, 'payment_method')
            ?: Arr::get($this->checkout_data, 'form_data._fct_pay_method');

        // Let addons add dynamic (computed) fees via filter
        $allFees = apply_filters('fluent_cart/cart/fees', $storedFees, [
            'cart'           => $this,
            'cart_items'     => $this->cart_data ?? [],
            'cart_subtotal'  => $this->getItemsSubtotal(),
            'shipping_total' => $this->getShippingTotal(),
            'customer_id'    => $this->customer_id,
            'payment_method' => $paymentMethod,
            'checkout_data'  => $this->checkout_data,
        ]);

        if (!is_array($allFees)) {
            $allFees = $storedFees;
        }

        // Validate and deduplicate (last wins — dynamic fees override stored)
        $validFees = $this->validateFees($allFees);

        $this->isCalculatingFees = false;
        $this->cachedFees = $validFees;

        return $validFees;
    }

    /**
     * Get only the persistent (stored) fees from checkout_data.
     *
     * @return array
     */
    public function getStoredFees(): array
    {
        return (array) Arr::get($this->checkout_data ?? [], 'fees', []);
    }

    /**
     * Add a fee to the cart. Persists immediately to the database.
     * If a fee with the same source:key already exists, it will be updated.
     *
     * Usage:
     *   $cart->addFee([
     *       'key'     => 'processing_fee',
     *       'label'   => 'Processing Fee',
     *       'amount'  => 450,           // cents, must be positive
     *       'source'  => 'dynamic-pricing',
     *       'taxable' => false,
     *       'meta'    => ['rule_id' => 42],
     *   ]);
     *
     * @param array $fee Fee data with required keys: key, label, amount
     * @return bool Whether the fee was added successfully
     */
    public function addFee(array $fee): bool
    {
        if (empty($fee['key']) || empty($fee['label']) || empty($fee['amount'])) {
            return false;
        }

        $amount = (int) $fee['amount'];
        if ($amount <= 0) {
            return false;
        }

        $validatedFee = [
            'key'     => sanitize_key($fee['key']),
            'label'   => sanitize_text_field($fee['label']),
            'amount'  => $amount,
            'taxable' => !empty($fee['taxable']),
            'source'  => sanitize_key($fee['source'] ?? 'custom'),
            'meta'    => (array) ($fee['meta'] ?? []),
        ];

        $checkoutData = $this->checkout_data ?? [];
        $fees = (array) Arr::get($checkoutData, 'fees', []);

        // Replace if same source:key exists, otherwise append
        $compositeKey = $validatedFee['source'] . ':' . $validatedFee['key'];
        $replaced = false;

        foreach ($fees as $index => $existingFee) {
            $existingComposite = Arr::get($existingFee, 'source', 'custom') . ':' . Arr::get($existingFee, 'key', '');
            if ($existingComposite === $compositeKey) {
                $fees[$index] = $validatedFee;
                $replaced = true;
                break;
            }
        }

        if (!$replaced) {
            $fees[] = $validatedFee;
        }

        $checkoutData['fees'] = array_values($fees);
        $this->checkout_data = $checkoutData;
        $this->clearFeeCache();
        $this->save();

        return true;
    }

    /**
     * Remove a fee from the cart by key (and optionally source).
     * Persists immediately to the database.
     *
     * @param string $key The fee key to remove
     * @param string|null $source Optional source filter. If null, removes all fees with this key.
     * @return bool Whether any fee was removed
     */
    public function removeFee(string $key, ?string $source = null): bool
    {
        $checkoutData = $this->checkout_data ?? [];
        $fees = (array) Arr::get($checkoutData, 'fees', []);
        $originalCount = count($fees);

        $fees = array_filter($fees, function ($fee) use ($key, $source) {
            if (Arr::get($fee, 'key') !== $key) {
                return true; // keep — different key
            }
            if ($source !== null && Arr::get($fee, 'source', 'custom') !== $source) {
                return true; // keep — different source
            }
            return false; // remove
        });

        if (count($fees) === $originalCount) {
            return false; // nothing was removed
        }

        $checkoutData['fees'] = array_values($fees);
        $this->checkout_data = $checkoutData;
        $this->clearFeeCache();
        $this->save();

        return true;
    }

    /**
     * Remove all fees from a specific source.
     * Useful for addons to clear their fees before recalculating.
     *
     * @param string $source The source identifier
     * @return void
     */
    public function removeFeesBySource(string $source): void
    {
        $checkoutData = $this->checkout_data ?? [];
        $fees = (array) Arr::get($checkoutData, 'fees', []);

        $fees = array_filter($fees, function ($fee) use ($source) {
            return Arr::get($fee, 'source', 'custom') !== $source;
        });

        $checkoutData['fees'] = array_values($fees);
        $this->checkout_data = $checkoutData;
        $this->clearFeeCache();
        $this->save();
    }

    /**
     * Get the total of all fees in cents.
     *
     * @return int
     */
    public function getFeeTotal(): int
    {
        return array_reduce($this->getFees(), function ($carry, $fee) {
            return $carry + (int) $fee['amount'];
        }, 0);
    }

    /**
     * Build cart-data-compatible items for fee items.
     * Used by the tax module to calculate tax on taxable fees
     * through the same pipeline as product items.
     *
     * @return array
     */
    public function getFeeCartItems(): array
    {
        $items = [];
        foreach ($this->getFees() as $fee) {
            $items[] = self::buildFeeCartItem($fee);
        }
        return $items;
    }

    /**
     * Convert a validated fee array into a cart-data-compatible line item.
     * Single source of truth for fee item structure — used by both
     * getFeeCartItems() and TaxModule::calculateCartTax().
     *
     * @param array $fee Validated fee array
     * @return array Cart-data-compatible item
     */
    public static function buildFeeCartItem(array $fee): array
    {
        $amount = (int) ($fee['amount'] ?? 0);

        return [
            'object_id'        => 0,
            'post_id'          => 0,
            'quantity'         => 1,
            'unit_price'       => $amount,
            'price'            => $amount,
            'subtotal'         => $amount,
            'line_total'       => $amount,
            'discount_total'   => 0,
            'coupon_discount'  => 0,
            'tax_amount'       => 0,
            'title'            => $fee['label'] ?? '',
            'post_title'       => '',
            'payment_type'     => 'fee',
            'is_fee'           => true,
            'fulfillment_type' => 'digital',
            'other_info'       => [
                'payment_type' => 'fee',
                'fee_key'      => $fee['key'] ?? '',
                'source'       => $fee['source'] ?? 'custom',
                'taxable'      => !empty($fee['taxable']),
            ],
        ];
    }

    /**
     * Clear the per-request fee cache.
     * Call this after modifying fees or cart data.
     *
     * @return void
     */
    public function clearFeeCache(): void
    {
        $this->cachedFees = null;
    }

    /**
     * Validate and deduplicate an array of fees.
     *
     * @param array $fees Raw fee items
     * @return array Validated fee items
     */
    private function validateFees(array $fees): array
    {
        $validFees = [];

        foreach ($fees as $fee) {
            if (empty($fee['key']) || empty($fee['label']) || empty($fee['amount'])) {
                continue;
            }

            $amount = (int) $fee['amount'];
            if ($amount <= 0) {
                continue;
            }

            $source = sanitize_key($fee['source'] ?? 'custom');
            $compositeKey = $source . ':' . sanitize_key($fee['key']);

            // Last wins — later entries (from filter) override earlier ones (stored)
            $validFees[$compositeKey] = [
                'key'     => sanitize_key($fee['key']),
                'label'   => sanitize_text_field($fee['label']),
                'amount'  => $amount,
                'taxable' => !empty($fee['taxable']),
                'source'  => $source,
                'meta'    => (array) ($fee['meta'] ?? []),
            ];
        }

        return array_values($validFees);
    }

    public function getItemsSubtotal()
    {
        $checkoutItems = new CheckoutService($this->cart_data);
        $subscriptionItems = $checkoutItems->subscriptions;
        $onetimeItems = $checkoutItems->onetime;

        $items = array_merge($onetimeItems, $subscriptionItems);
        return OrderService::getItemsAmountWithoutDiscount($items);
    }

    private static bool $calculatingTotal = false;

    public function getEstimatedTotal($extraAmount = 0)
    {
        // Recursion guard: if a hook calls getEstimatedTotal(), skip hooks to avoid infinite loop
        if (self::$calculatingTotal) {
            return $this->getEstimatedTotalRaw($extraAmount);
        }

        self::$calculatingTotal = true;

        do_action('fluent_cart/cart/before_totals_calculation', [
            'cart' => $this,
        ]);

        $cartData = apply_filters('fluent_cart/cart/item_dynamic_discount', $this->cart_data, [
            'cart' => $this,
        ]);

        $checkoutItems = new CheckoutService($cartData);

        $subscriptionItems = $checkoutItems->subscriptions;
        $onetimeItems = $checkoutItems->onetime;

        $items = array_merge($onetimeItems, $subscriptionItems);

        $total = OrderService::getItemsAmountTotal($items, false, false, $extraAmount);

        $shippingTotal = $this->getShippingTotal();

        if ($shippingTotal) {
            $total += $shippingTotal;
        }

        $feeTotal = $this->getFeeTotal();
        if ($feeTotal > 0) {
            $total += $feeTotal;
        }

        if (Arr::get($this->checkout_data, 'custom_checkout') === 'yes' && !$shippingTotal) {
            $customShippingAmount = (int)Arr::get($this->checkout_data, 'custom_checkout_data.shipping_total', 0);
            // $customerDiscountAmount = (int)Arr::get($this->checkout_data, 'custom_checkout_data.discount_total', 0); // discount is already calculated in via getItemsAmountTotal
            // $total -= $customerDiscountAmount;
            $total += $customShippingAmount;
        }

        if ($total < 0) {
            $total = 0;
        }

        $finalTotal = apply_filters('fluent_cart/cart/estimated_total', $total, [
            'cart' => $this
        ]);

        do_action('fluent_cart/cart/after_totals_calculation', [
            'cart'  => $this,
            'total' => $finalTotal,
        ]);

        self::$calculatingTotal = false;

        return $finalTotal;
    }

    /**
     * Raw total calculation without hooks (used for recursion guard).
     */
    private function getEstimatedTotalRaw($extraAmount = 0)
    {
        $checkoutItems = new CheckoutService($this->cart_data);
        $items = array_merge($checkoutItems->onetime, $checkoutItems->subscriptions);
        $total = OrderService::getItemsAmountTotal($items, false, false, $extraAmount);

        $shippingTotal = (int)Arr::get($this->checkout_data ?? [], 'shipping_data.shipping_charge', 0);
        if ($shippingTotal) {
            $total += $shippingTotal;
        }

        $feeTotal = $this->getFeeTotal();
        if ($feeTotal > 0) {
            $total += $feeTotal;
        }

        return max(0, $total);
    }

    /**
     * Get full cart context data for dynamic pricing and other addons.
     */
    public function getContextData(): array
    {
        $cartData = $this->cart_data ?? [];
        $customerId = $this->customer_id;

        $context = [
            'cart_subtotal'       => $this->getItemsSubtotal(),
            'cart_item_count'     => count($cartData),
            'cart_total_quantity'  => array_sum(array_column($cartData, 'quantity')),
            'shipping_method'     => Arr::get($this->checkout_data, 'shipping_data.method_id'),
            'payment_method'      => Arr::get($this->checkout_data, 'payment_method'),
            'customer_id'         => $customerId,
            'order_type'          => Arr::get($this->checkout_data, 'order_type', 'initial'),
        ];

        return apply_filters('fluent_cart/cart/context_data', $context, [
            'cart' => $this,
        ]);
    }

    public function getEstimatedRecurringTotal()
    {
        return array_reduce(
            $this->cart_data ?? [],
            function ($carry, $item) {
                if (Arr::get($item, 'other_info.payment_type') === 'subscription') {
                    $subtotal = Arr::get($item, 'subtotal', 0);
                    $discount = Arr::get($item, 'recurring_discounts.amount', 0);
                    $carry += ($subtotal - $discount);
                }
                return $carry;
            },
            0
        );
    }

    public function findExistingItemAndIndex($objectId, $extraArgs = [])
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

    // Unique hook handling
    protected function uniqueHooks($hooks)
    {
        return array_values(array_unique($hooks));
    }

    public function addDraftCreatedActions($hooks)
    {
        return [
            '__after_draft_created_actions__' => $this->uniqueHooks($hooks)
        ];
    }

    public function addSuccessActions($hooks)
    {
        return [
            '__on_success_actions__' => $this->uniqueHooks($hooks)
        ];
    }

    public function addCartNotices($notices)
    {
        // Remove duplicates by notice ID
        $uniqueNotices = [];
        foreach ($notices as $notice) {
            $uniqueNotices[$notice['id']] = $notice;
        }

        $uniqueNotices = array_values($uniqueNotices);

        return [
            '__cart_notices' => $uniqueNotices
        ];
    }



}
