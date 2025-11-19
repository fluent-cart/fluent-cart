<?php

namespace FluentCart\Api\Resource\FrontendResource;

use FluentCart\Api\Cookie\Cookie;
use FluentCart\Api\Resource\BaseResourceApi;
use FluentCart\App\App;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\ProductVariation;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Collection;
use WP_Error;

class CartResource extends BaseResourceApi
{

    public static function getQuery(): Builder
    {
        return Cart::query();
    }

    public static function generateCartForInstantCheckout($variationId, $quantity = 1)
    {

        $variation = ProductVariation::query()
            ->with(['product'])
            ->with(['media', 'shippingClass'])
            ->where('id', $variationId)->first();

        if (!$variation) {
            return new WP_Error(__('Invalid Product', 'fluent-cart'));
        }

        $quantity = apply_filters('fluent_cart/item_max_quantity', $quantity, [
            'variation' => $variation,
            'product'   => $variation->product
        ]);

        if ($variation->payment_type === 'subscription') {
            $quantity = 1;
        }

        $canPurchase = $variation->canPurchase($quantity);
        if (is_wp_error($canPurchase)) {
            return $canPurchase;
        }

        $cart = CartHelper::generateCartFromVariation($variation, $quantity);

        if (is_user_logged_in()) {
            $cart->user_id = get_current_user_id();
        }

        $cart->cart_group = 'instant';

        $cart->cart_hash = md5(time() . wp_generate_uuid4());

        $cart->save();
        return $cart;

    }

    /**
     * Retrieve cart based on the provided parameters.
     *
     * This function looks for a cart associated with the provided cart hash. If no cart is found,
     * it creates a new cart for anonymous users. If the user is logged in, it associates the cart
     * with the logged-in user.
     *
     * @param array $params Optional. Additional parameters for cart retrieval.
     *        [
     *            // Include optional parameters, if any.
     *        ]
     *
     */
    public static function get(array $params = [])
    {
        static $cart;
        if (isset($cart)) {
            return $cart;
        }

        $autoCreate = Arr::get($params, 'create', false);

        $cartHash = Arr::get($params, 'hash');

        //$cartHash = App::request()->get('fct_cart_hash');

        if ($cartHash) {
            $cartQuery = static::getQuery()
                ->where('cart_hash', $cartHash)
                ->where('stage', '!=', 'completed')
                ->where('cart_group', 'instant');

            $tempCart = $cartQuery->first();

            $cart = $tempCart;

            if (!$autoCreate) {
                return $tempCart;
            }
        }

        $cart = static::getOrSetCartForThisDevice($autoCreate);

        return $cart;
    }

    public static function find($id, $params = [])
    {

    }

    /**
     * Create cart with the provided item data.
     *
     * @param array $data Required. Array containing the necessary parameters
     *        [
     *            'id' => (int) Required.The ID of the item,
     *            'quantity' => (int) Optional.The quantity of the item
     *        ]
     * @param array $params Optional. Additional parameters for cart creation or update.
     *        [
     *            // Include optional parameters, if any.
     *        ]
     *
     */
    public static function create($data, $params = [])
    {
        $itemId = Arr::get($data, 'id');
        $quantity = Arr::get($data, 'quantity', 1);

        if ($quantity <= 0) {
            return static::makeErrorResponse([
                ['code' => 403, 'message' => __('Quantity can not be negative.', 'fluent-cart')]
            ]);
        }

        $cart = CartResource::get([
            'create' => true
        ]);
        $cartArray = $cart->cart_data;

        $cartArray = self::updateCartItemsQuantity(
            [
                'item_id'        => $itemId,
                'increment_by'   => $quantity,
                'existing_items' => $cartArray
            ]
        );

        if (Arr::get($cartArray, 'code', '') === 'failed') {
            return static::makeErrorResponse([
                ['code' => 423, 'message' => Arr::get($cartArray, 'message', __('Cart validation error!', 'fluent-cart'))]
            ]);
        }

        $cart->cart_data = Arr::get($cartArray, 'cart_data');
        $message = Arr::get($cartArray, 'message');

        $isCreated = $cart->save();

        if ($isCreated) {
            return static::makeSuccessResponse(
                $isCreated,
                __('Successfully added!', 'fluent-cart')
            );
        }

        return static::makeErrorResponse([
            ['code' => 400, 'message' => __('Could not add', 'fluent-cart')]
        ]);
    }

    /**
     * Update the quantity of an item in the cart.
     *
     * @param array $data Required. Array containing the necessary parameters for item quantity
     *        [
     *            'item_id' => (int) Required.The ID of the product_variation,
     *            'quantity'=> (int) Optional.The quantity of the item
     *        ]
     * @param int $id Required. The ID of the cart.
     * @param array $params Optional. Additional parameters for updating cart
     *        [
     *            // Include optional parameters, if any.
     *        ]
     *
     */
    public static function update($data, $id = '', $params = [])
    {
        $cart = self::get([
            'create' => true,
            'hash'   => Arr::get($params, 'hash'),
        ]);

        $itemId = (int)Arr::get($data, 'item_id');
        $quantity = Arr::get($data, 'quantity', 0);
        $byInput = (bool)Arr::get($data, 'by_input', false);

        if (!$itemId) {
            return new WP_Error(
                'invalid_item',
                __('Invalid item.', 'fluent-cart')
            );
        }

        $variation = ProductVariation::query()->where('id', $itemId)->with('product')->first();


        if (!$variation) {
            return $cart->removeItem($itemId);
        }


        if ($variation->soldIndividually()) {
            if ($quantity >= 1) {
                $quantity = 1;
            }
            $byInput = true;
        }

        $cart = $cart->addByVariation($variation, [
            'quantity'      => $quantity,
            'by_input'      => $byInput,
            'will_validate' => true,
            'replace'       => false
        ]);

        if (is_wp_error($cart)) {
            return $cart;
        }

        $utmData = static::prepareUtmData($data);
        if ($utmData) {
            $cart->utm_data = array_merge(is_array($cart->utm_data) ? $cart->utm_data : [], $utmData);
            $cart->save();
        }

        return $cart;
    }

    public static function prepareUtmData(array $params): array
    {
        $data = [];
        $allowedUtmParams = [
            'utm_campaign',
            'utm_content',
            'utm_term',
            'utm_source',
            'utm_medium',
            'utm_id',
            'refer_url',
            'fbclid',
            'gclid'
        ];

        foreach ($allowedUtmParams as $utmParam) {
            if (isset($params[$utmParam])) {
                $data[$utmParam] = $params[$utmParam];
            }
        }
        return $data;
    }

    /**
     * Delete cart based on the provided user ID or cart hash.
     *
     * @param int $id Required. The user ID associated with the cart.
     * @param array $params Optional. Additional parameters for cart deletion.
     *        [
     *           'cart_hash' => (string) Optional. The cart hash for additional identification.
     *        ]
     *
     */
    public static function delete($id, $params = [])
    {
        $cart = static::get();

        if ($cart == null) {
            return null;
        }

        $deleted = $cart->delete();

        if ($deleted) {
            Cookie::deleteCartHash();
        }

        return $deleted;
    }

    public static function getStatus(): array
    {
        $cart = static::get(
            [
                'create' => false // Do not create a new cart if it doesn't exist
            ]
        );

        if (!$cart) {
            return [];
        }

        return [
            'cart_hash' => $cart->cart_hash,
            'cart_data' => $cart->cart_data,
            'cart_user' => $cart->user_id,
        ];
    }

    public static function isLicensedProduct($productVariation): bool
    {
        return Helper::hasLicense(Arr::get($productVariation, 'product'));
    }


    private static function validateShouldAddProduct($productVariation, $existingItemsArray)
    {
        if (Arr::get($productVariation, 'product.post_status') !== 'publish') {
            return new WP_Error(
                'item_not_available',
                __('Item is not available.', 'fluent-cart')
            );
        }

        $variationIds = (new Collection($existingItemsArray))->pluck('object_id');
        $paymentType = Arr::get($productVariation, 'other_info.payment_type', false);

        $hasInstantCheckoutParam = !empty(App::request()->get(Helper::INSTANT_CHECKOUT_URL_PARAM));

        //early return as don't allow subscription item to add in cart
        if ($paymentType !== 'onetime' && !$hasInstantCheckoutParam) {
            return new WP_Error(
                'item_not_available',
                __('Item is not available.', 'fluent-cart')
            );
        }
        $hasSubscription = static::hasSubscriptionProduct($existingItemsArray);

        if (!$variationIds->contains(Arr::get($productVariation, 'id')) || empty($existingItemsArray)) {
            return new WP_Error(
                'item_not_available',
                __('Item is not available.', 'fluent-cart')
            );

        }

        if ($paymentType === 'onetime' && !$hasSubscription) {
            return true;
        }

        if ($paymentType === 'onetime' && $hasSubscription) {
            return new WP_Error(
                'subscription_items_can_not_combined',
                __('Subscription items can\'t be combined with other products in the cart.', 'fluent-cart')
            );
        }

        return new WP_Error(
            'item_not_available',
            __('Item is not available.', 'fluent-cart')
        );
    }

    public static function hasSubscriptionProduct($existingItemsArray = []): bool
    {
        $subscriptionProduct = (new Collection($existingItemsArray))->pluck('other_info')->filter(function ($info) {
            $otherInfo = (array)$info;
            $type = Arr::get($otherInfo, 'payment_type', false);
            return $type === 'subscription';
        });
        return $subscriptionProduct->count() > 0;
    }

    private static function removeItemFromCart($existingItemsArray, $index): array
    {
        unset($existingItemsArray[$index]);
        $message = __('Item removed from cart', 'fluent-cart');
        return [
            'message'   => $message,
            'cart_data' => $existingItemsArray,
        ];
    }

    public static function updateItemQuantityInCart($productVariation, $existingItemsArray, $index, $quantity = 1, $isFilteredItem = false): array
    {
        $canBeAdded = true;


        if (!$isFilteredItem) {
            $canBeAdded = static::validateShouldAddProduct($productVariation, $existingItemsArray);
        }


        if (is_wp_error($canBeAdded)) {
            return [
                'code'    => 'failed',
                'message' => $canBeAdded->get_error_message()
            ];
        }

        $updatedQuantity = $existingItemsArray[$index]['quantity'] + $quantity;

        if ($updatedQuantity < 0) {
            $updatedQuantity = 0;
        }

        if (!$isFilteredItem) {

            if (!CartHelper::shouldAddItemToCart($productVariation, $updatedQuantity)) {
                return [
                    'code'    => 'failed',
                    'message' => __("You've reached the maximum quantity for this product.", 'fluent-cart')
                ];
            }
        }

        if ($productVariation instanceof ProductVariation) {
            $item = CartHelper::generateCartItemFromVariation($productVariation, $updatedQuantity);
        } else {
            $item = CartHelper::generateCartItemCustomItem($productVariation, $updatedQuantity);
        }


        $existingItemsArray[$index] = $item;
        return [
            'message'   => __('Quantity updated!', 'fluent-cart'),
            'cart_data' => $existingItemsArray,
        ];
    }

    public static function addItemInCart($productVariation, $existingItemsArray, $index, $quantity = 1, $isFilteredItem = false): array
    {

        if ($quantity < 1) {
            $quantity = 1;
        }
        if (!$isFilteredItem) {
            if (!CartHelper::shouldAddItemToCart($productVariation, $quantity)) {
                return [
                    'code'    => 'failed',
                    'message' => sprintf(
                        /* translators: %s is the product title */
                        __('%s is out of stock', 'fluent-cart'),
                        Arr::get($productVariation, 'variation_title')
                    ),
                ];
            }
        }

        if ($productVariation instanceof ProductVariation) {
            $item = CartHelper::generateCartItemFromVariation($productVariation, $quantity);
        } else {
            $item = CartHelper::generateCartItemCustomItem($productVariation, $quantity);
        }


        $existingItemsArray[] = static::getCartSingleItemPreparedArray(
            [
                'variation' => $productVariation,
                'quantity'  => $quantity
            ]
        );


        return [
            'message'   => __('Item added in cart!', 'fluent-cart'),
            'cart_data' => $existingItemsArray,
        ];
    }

    private static function updateCartItemsQuantity($params = []): array
    {
        $itemId = Arr::get($params, 'item_id');

        $incrementBy = Arr::get($params, 'increment_by');
        $existingItemsArray = Arr::get($params, 'existing_items', []);
        if (!is_array($existingItemsArray)) {
            $existingItemsArray = [];
        }


        $index = -1;

        foreach ($existingItemsArray as $itemIndex => $existingItem) {
            if (Arr::get($existingItem, 'object_id') == $itemId) {
                $index = $itemIndex;
                break;
            }
        }


        /** @var $productVariation ProductVariation */
        if ($incrementBy == 0) {
            return static::removeItemFromCart($existingItemsArray, $index);
        }

        $productVariation = ProductVariation::query()->where('id', $itemId)->with([
            'product',
            'product.detail',
            'product.licensesMeta',
            'product_detail',
            'media',
            'shippingClass'
        ])->first();


        $isFilteredItem = false;
        if (empty($productVariation)) {
            $isFilteredItem = true;
            $productVariation = apply_filters('fluent_cart/cart_item_product_variation', $productVariation, $itemId, $incrementBy, $existingItemsArray);
        }


        if (empty($productVariation)) {
            return [
                'code'    => 'failed',
                'message' => __('Item is not available.', 'fluent-cart')
            ];
        }

        //($index === 0 || !empty($index)) && isset($existingItemsArray[$index])
        //inline check will not work

        $isValidIndex = false;
        if ($index != -1) {
            $isValidIndex = true;
        }

        if ($isValidIndex && isset($existingItemsArray[$index])) {

            return static::updateItemQuantityInCart(
                $productVariation,
                $existingItemsArray,
                $index,
                $incrementBy,
                $isFilteredItem,
            );
        }

        return static::addItemInCart(
            $productVariation,
            $existingItemsArray,
            $index,
            $incrementBy,
            $isFilteredItem,
        );
    }


    private static function getCartSingleItemPreparedArray($params = []): array
    {

        $variation = Arr::get($params, 'variation');
        $quantity = Arr::get($params, 'quantity');
        return CartHelper::generateCartItemFromVariation($variation, $quantity);

    }

    /**
     * Check if cart exists
     */

    public static function getOrSetCartForThisDevice($autoCreate = false)
    {

        $cartHash = Cookie::getCartHash();

        if ($cartHash) {
            $cart = static::getQuery()
                ->where('stage', '!=', 'completed')
                ->where('cart_hash', $cartHash)
                ->where('cart_group', 'global')
                ->first();

            if ($cart) {
                return $cart;
            }
        }

        $userId = get_current_user_id();
        if ($userId) {
            $cart = static::getQuery()
                ->where('user_id', $userId)
                ->where('stage', '!=', 'completed')
                ->where('cart_group', 'global')
                ->first();

            if ($cart) {
                return $cart;
            }
        }

        if (!$autoCreate) {
            return null;
        }

        $cart = new Cart();
        $cart->cart_data = [];

        $cart = CartHelper::addCommonCartData($cart);

        $cart->save();

        Cookie::setCartHash($cart->cart_hash);

        return $cart;
    }

    /**
     * This method should be called only if no cart is found in a current device
     */
    private static function setupNewCart()
    {
        $cartArray['cart_data'] = [];
        if ($userId = get_current_user_id()) {
            $cartArray['user_id'] = $userId;
        }
        $cartArray['cart_group'] = 'global';
        return Cart::query()->create($cartArray);

    }

    public static function resetCartData()
    {
        $cart = CartResource::get();

        if (is_array($cart->cart_data) && !empty($cart->cart_data)) {
            $cart->cart_data = [];
            $cart->save();
        }
    }
}
