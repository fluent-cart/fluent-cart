<?php

namespace FluentCart\App\Helpers;

use FluentCart\Api\CurrencySettings;
use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\App;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\ShippingClass;
use FluentCart\App\Models\ShippingMethod;
use FluentCart\App\Services\CheckoutService;
use FluentCart\App\Services\URL;
use FluentCart\Framework\Support\Arr;

class CartHelper
{
    public static function getCart($hash = null, $create = false)
    {
        return CartResource::get([
            'hash'   => $hash ?? App::request()->get(Helper::INSTANT_CHECKOUT_URL_PARAM),
            'create' => $create
        ]);
    }

    public static function generateCartItemFromVariation(ProductVariation $variation, $quantity = 1): array
    {
        $mediaUrl = $variation->thumbnail ?: $variation->product->thumbnail;

        //  $shippingCharge = static::calculateShippingCharge($variation, $quantity);

        $subtotal = $variation->item_price * $quantity;

        //Need to test and check this toArray Issue
        $data = wp_parse_args([
            'quantity'             => $quantity,
            'price'                => $variation->item_price,
            'unit_price'           => $variation->item_price,
            'line_total'           => $variation->item_price * $quantity,
            'subtotal'             => $subtotal,
            'discount_total'       => 0,
            'tax_total'            => 0,
            'line_total_formatted' => CurrencySettings::getFormattedPrice($subtotal),
            'object_id'            => $variation->id,
            'title'                => $variation->variation_title,
            'post_title'           => $variation->product->post_title,
            'coupon_discount'      => 0,
            'cost'                 => $variation->item_cost ?? 0,
            'featured_media'       => $mediaUrl,
            'view_url'             => URL::appendQueryParams(
                $variation->product->view_url,
                [
                    'selected' => $variation->id
                ]
            ),
            'variation_type'       => $variation['product_detail']['variation_type'],
        ], $variation->toArray());

        $cartItem = Arr::only($data, [
            'id',
            'object_id',
            'post_id',
            'quantity',
            'post_title',
            'title',
            'price',
            'unit_price',
            'coupon_discount',
            'fulfillment_type',
            'featured_media',
            'other_info',
            'cost',
            'view_url',
            'line_total_formatted',
            'line_total',
            'subtotal',
            'total',
            'variation_type'
        ]);

        //  $cartItem['shipping_charge'] = $shippingCharge;

        return $cartItem;
    }

    public static function generateCartItemCustomItem(array $variation, $quantity = 1): array
    {

        //Need to test and check this toArray Issue
        $data = wp_parse_args([
            'quantity'       => $quantity,
            'price'          => Arr::get($variation, 'item_price'),
            'unit_price'     => Arr::get($variation, 'item_price'),
            'object_id'      => Arr::get($variation, 'id'),
            'tax_amount'     => Arr::get($variation, 'tax_amount', 0),
            'title'          => Arr::get($variation, 'variation_title'),
            'post_title'     => Arr::get($variation, 'post_title'),
            'cost'           => Arr::get($variation, 'item_cost', 0),
            'featured_media' => Arr::get($variation, 'featured_media'),
            'view_url'       => Arr::get($variation, 'featured_media'),
            'variation_type' => Arr::get($variation, 'variation_type'),
        ], $variation);

        $manualDiscount = Arr::get($data, 'manual_discount', 0);
        $couponDiscount = Arr::get($data, 'coupon_discount', 0);
        $discountTotal = $manualDiscount + $couponDiscount;
        $subtotal = Arr::get($data, 'item_price', 0) * $data['quantity'];

        $data['subtotal'] = $subtotal;
        $data['manual_discount'] = $manualDiscount;
        $data['coupon_discount'] = $couponDiscount;
        $data['discount_total'] = $discountTotal;
        $data['line_total'] = $subtotal - $discountTotal;

        $cartItem = Arr::only($data, [
            'id',
            'object_id',
            'post_id',
            'quantity',
            'post_title',
            'title',
            'price',
            'unit_price',
            'manual_discount',
            'coupon_discount',
            'discount_total',
            'fulfillment_type',
            'featured_media',
            'other_info',
            'cost',
            'view_url',
            'line_total',
            'subtotal',
            'total',
            'variation_type'
        ]);

        return $cartItem;
    }

    public static function calculateShippingCharge(ProductVariation $variation, int $quantity = 1)
    {

        if ($variation->fulfillment_type !== 'physical') {
            return 0;
        }

        $shippingClass = $variation->shippingClass;

        if (!$shippingClass) {
            return 0;
        }

        $factor = empty($shippingClass->per_item) ? 1 : $quantity;
        if ($shippingClass->type === 'percentage') {
            return ($shippingClass->cost / 100) * $variation->item_price * $factor;
        }

        return ($shippingClass->cost * 100) * $factor;
    }

    public static function calculateShippingMethodCharge(ShippingMethod $method, ?array $items = null, $returnType = 'amount')
    {
        static $onceCalculated = false;
        static $onceDistributed = false;
        static $totalItemPrice = 0;
        static $totalQuantity = 0;
        static $physicalItems = [];
        static $isAllDigital = false;
        static $maxShippingCharge = 0;
        static $totalShippingCharge = 0;
        $isUsingCart = false;

        if ($items === null) {
            $isUsingCart = true;
            $items = static::getCart()->cart_data ?? [];
        }

        if ($method->type === 'free_shipping') {
            if ($returnType === 'items') {
                if ($items === null) {
                    $items = static::getCart()->cart_data ?? [];
                }
                foreach ($items as $key => $item) {
                    $items[$key]['shipping_charge'] = 0;
                    $items[$key]['itemwise_shipping_charge'] = 0;
                }
                return [
                    'items'           => $items,
                    'shipping_amount' => 0
                ];
            }
            return 0;
        }

        $totalItemWiseShippingCharge = 0;

        $cartCheckoutService = new CheckoutService($items);
        $isAllDigital = $cartCheckoutService->isAllDigital();
        $physicalItems = $cartCheckoutService->physicalItems;

        if (!$onceCalculated) {
            $onceCalculated = true;
            $productIds = array_unique(array_column($physicalItems, 'post_id'));
            $products = Product::query()->whereIn('ID', $productIds)
                ->with(['detail'])
                ->get()
                ->keyBy('ID');

            $shippingClassIds = $products->pluck('detail.other_info.shipping_class')->filter(function ($item) {
                return !empty($item);
            })->toArray();

            $shippingClasses = ShippingClass::query()->whereIn('id', $shippingClassIds)->get()->keyBy('id');

            foreach ($physicalItems as $key => &$item) {
                $totalQuantity += Arr::get($item, 'quantity');
                $totalItemPrice += (Arr::get($item, 'quantity') * Arr::get($item, 'unit_price')) - Arr::get($item, 'discount_total');
                $itemShippingCharge = 0;

                $product = $products->get(Arr::get($item, 'post_id'));


                if (isset($product->detail->other_info['shipping_class'])) {
                    // shipping_class is null or not defined
                    $shippingClass = $shippingClasses->get(
                        $product->detail->other_info['shipping_class']
                    );

                    if ($shippingClass) {
                        $perItem = $shippingClass->per_item;
                        $factor = empty($perItem) ? 1 : Arr::get($item, 'quantity');
                        if ($shippingClass->type === 'percentage') {
                            $itemShippingCharge = ($shippingClass->cost / 100) * Arr::get($item, 'unit_price') * $factor;
                        } else {
                            $itemShippingCharge = Helper::toCent($shippingClass->cost) * $factor;
                        }
                    }
                }
                $item['shipping_charge'] = $itemShippingCharge;
                $totalShippingCharge += $itemShippingCharge;

                $items[$key] = $item;
                $maxShippingCharge = max($maxShippingCharge, $itemShippingCharge);
            }

            $totalItemWiseShippingCharge = $totalShippingCharge;
        }

        if ($isAllDigital) {
            return 0;
        }

        $settings = Arr::wrap($method->settings);
        $configureRate = Arr::get($settings, 'configure_rate', 'per_order');
        $classAggregation = Arr::get($settings, 'class_aggregation', 'sum_all');

        if ($configureRate === 'per_order') {
            $shippingMethodAmount = $method->amount * 100;
        } else if ($configureRate === 'per_price') {
            $shippingMethodAmount = $totalItemPrice * ($method->amount / 100);
        } else {
            $shippingMethodAmount = $method->amount * $totalQuantity * 100;
        }

        if ($classAggregation === 'highest_class') {
            $shippingMethodAmount += $maxShippingCharge;
        } else {
            $shippingMethodAmount += $totalShippingCharge;
        }

        $remainingShippingMethodAmount = ($shippingMethodAmount - $totalItemWiseShippingCharge);

        if (!$onceDistributed) {
            $onceDistributed = true;
            $totalLineTotal = array_sum(array_column($physicalItems, 'line_total'));
            $distributed = 0;
            $totalRemain = $remainingShippingMethodAmount;
            $itemCount = count($physicalItems);

            if ($totalLineTotal > 0) {
                foreach ($physicalItems as $key => &$item) {
                    $share = ($item['line_total'] / $totalLineTotal) * $remainingShippingMethodAmount;
                    $share = round($share, 2);
                    $items[$key]['itemwise_shipping_charge'] = ceil($share);
                    $distributed += $share;
                }
            } else {
                $equalShare = round($remainingShippingMethodAmount / $itemCount, 2);
                foreach ($physicalItems as $key => &$item) {
                    $items[$key]['itemwise_shipping_charge'] = ceil($equalShare);
                    $distributed += $equalShare;
                }
            }

            $diff = round($totalRemain - $distributed, 2);
            if ($diff != 0) {
                $lastIndex = array_key_last($physicalItems);
                $items[$lastIndex]['itemwise_shipping_charge'] = ceil($diff);
            }
        }

        if ($isUsingCart) {
            $cart = CartHelper::getCart();
            $cart->cart_data = $items;
            $cart->save();

            do_action('fluent_cart/checkout/shipping_data_changed', [
                'cart' => $cart
            ]);
        }

        if ($returnType === 'items') {
            return [
                'items'           => $items,
                'shipping_amount' => $shippingMethodAmount
            ];
        }

        return $shippingMethodAmount;
    }

    public static function resetShippingCharge()
    {
        $cart = CartHelper::getCart();
        $items = $cart->cart_data;
        foreach ($items as $key => $item) {
            $items[$key]['shipping_charge'] = 0;
            $items[$key]['itemwise_shipping_charge'] = 0;
        }
        $cart->cart_data = $items;

        $cart->checkout_data = array_merge($cart->checkout_data, [
            'shipping_data' => [
                'shipping_method_id' => null,
                'shipping_charge'    => 0
            ]
        ]);

        $cart->save();

        do_action('fluent_cart/checkout/shipping_data_changed', [
            'cart' => $cart
        ]);
    }

    public static function generateCartFromVariation(ProductVariation $variation, $quantity = 1): Cart
    {
        $cart = new Cart();
        $cart->cart_data = [
            static::generateCartItemFromVariation($variation, $quantity)
        ];

        $cart = static::addCommonCartData($cart);
        return $cart;
    }

    public static function addCommonCartData(Cart $cart)
    {
        if (is_user_logged_in()) {
            $wpUser = wp_get_current_user();
            $cart->user_id = get_current_user_id();
            $customer = Customer::query()->where('email', wp_get_current_user()->user_email)->first();
            if ($customer) {
                $cart->customer_id = $customer->id;
            }
            $cart->email = $wpUser->user_email;
            $cart->first_name = $wpUser->first_name;
            $cart->last_name = $wpUser->last_name;
            $cart->ip_address = AddressHelper::getIpAddress();
            $cart->user_agent = AddressHelper::getUserAgent();
        }

        return $cart;
    }

    public static function generateCartFromCustomVariation(array $variation, $quantity = 1): Cart
    {
        $cart = new Cart();
        $cart->cart_data = [
            static::generateCartItemCustomItem($variation, $quantity)
        ];
        return $cart;
    }


    /**
     * @param ProductVariation $variation
     * @param int|string $updatedQuantity
     * @return bool
     */
    public static function shouldAddItemToCart(ProductVariation $variation, $updatedQuantity): bool
    {
        if ($variation->manage_stock == 0) {
            return true;
        }
        return $updatedQuantity <= $variation->available;
    }

    public static function doingInstantCheckout()
    {
        $variationId = App::request()->get(Helper::INSTANT_CHECKOUT_URL_PARAM);
        if (empty($variationId)) {
            return false;
        }
        return $variationId;
    }
}
