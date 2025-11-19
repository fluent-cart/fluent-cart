<?php

namespace FluentCart\App\Modules\Shipping;

use FluentCart\App\App;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Modules\Shipping\Http\Handlers\ScriptHandler;
use FluentCart\App\Modules\Shipping\Services\Filter\ShippingZoneFilter;
use FluentCart\App\Vite;
use FluentCart\Framework\Support\Arr;

class ShippingModule
{
    public static function register()
    {
        $self = new static();
        add_action('fluentcart_loaded', [$self, 'init']);

        add_filter('fluent_cart/checkout/before_patch_checkout_data', [$self, 'maybeRecalculateShippingCharges'], 9, 2);

        add_action('fluent_cart/cart/cart_data_items_updated', [$self, 'handleItemsChanges'], 9, 1);
    }

    public function init($app)
    {
        $app->router->group(function ($router) {
            require_once __DIR__ . '/Http/shipping-api.php';
        });

        (new ScriptHandler())->register();
    }

    public function handleItemsChanges($data)
    {
        $scope = Arr::get($data, 'scope');

        $ignores = [
            'apply_coupons',
            'discounts_recalculated',
            'remove_coupon'
        ];

        if (in_array($scope, $ignores)) {
            return;
        }

        $cart = Arr::get($data, 'cart');
        if (!$cart->requireShipping()) {
            return;
        }

        $fillData = $this->getRecalculatedCartDataArr([
            'cart_data'     => $cart->cart_data,
            'checkout_data' => $cart->checkout_data
        ], $cart);

        $cart->fill($fillData);
        $cart->save();
    }

    public function maybeRecalculateShippingCharges($fillData, $data)
    {
        $cart = $data['cart'];
        if (!$cart->requireShipping()) {
            return $fillData;
        }

        $watchingKeys = [
            'shipping_country',
            'shipping_state',
            'shipping_method_id'
        ];

        $changes = Arr::get($data, 'changes', []);
        if (!array_intersect(array_keys($changes), $watchingKeys)) {
//            dd('No Changed');
            return $fillData;
        }

        Arr::set($fillData, 'hook_changes.shipping', true);
        $fillData = $this->getRecalculatedCartDataArr($fillData, $cart);

        return $fillData;
    }

    private function getRecalculatedCartDataArr($fillData, $cart)
    {
        $shippingCountry = Arr::get($fillData, 'checkout_data.form_data.shipping_country', null);
        $shippingState = Arr::get($fillData, 'checkout_data.form_data.shipping_state', null);

        $availableShippingMethods = AddressHelper::getShippingMethods($shippingCountry, $shippingState);

        if (!$availableShippingMethods || is_wp_error($availableShippingMethods)) {
            Arr::set($fillData, 'checkout_data.shipping_data.shipping_charge', 0);
            Arr::set($fillData, 'checkout_data.shipping_data.shipping_method_id', null);

            $cartData = Arr::get($fillData, 'cart_data', []);
            foreach ($cartData as &$cartItem) {
                $cartItem['shipping_charge'] = 0;
                $cartItem['itemwise_shipping_charge'] = 0;
            }

            Arr::set($fillData, 'cart_data', $cartData);

            return $fillData;
        }

        $prevShippingMethodId = Arr::get($fillData, 'checkout_data.shipping_data.shipping_method_id', null);

        $shippingMethod = Arr::first($availableShippingMethods);
        if ($prevShippingMethodId) {
            foreach ($availableShippingMethods as $method) {
                if ($method->id == $prevShippingMethodId) {
                    $shippingMethod = $method;
                    break;
                }
            }
        } 

        $calculations = CartHelper::calculateShippingMethodCharge($shippingMethod, $fillData['cart_data'], 'items');

        $newCharge = Arr::get($calculations, 'shipping_amount', 0);
        $items = Arr::get($calculations, 'items', []);

        Arr::set($fillData, 'cart_data', $items);

        Arr::set($fillData, 'checkout_data.shipping_data.shipping_charge', $newCharge);
        Arr::set($fillData, 'checkout_data.shipping_data.shipping_method_id', $shippingMethod->id);

        return $fillData;
    }

}
