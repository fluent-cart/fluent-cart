<?php

namespace FluentCart\App\Http\Controllers;


use FluentCart\Api\Checkout\CheckoutApi;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\ShippingMethod;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;

class CheckoutController extends Controller
{
    /**
     * @throws \Exception
     */
    public function placeOrder(Request $request)
    {
        // CheckoutApi::placeOrder() applies the attempt limit; a second count
        // here let a shopper try only twice a minute.
        CheckoutApi::placeOrder($request->all(), true);
    }

    public function getCheckoutSummary(Request $request)
    {
        $checkOutHelper =  \FluentCart\App\Helpers\CartCheckoutHelper::make();
        $shippingMethodId = $request->getSafe('shipping_method_id', 'sanitize_text_field');


        $charge = 0;
        $shippingMethod = is_numeric($shippingMethodId) ? ShippingMethod::query()->find($shippingMethodId) : null;
        if (!empty($shippingMethod)) {
            $charge = CartHelper::calculateShippingMethodCharge($shippingMethod);
        } else if ($shippingMethodId) {
            // A carrier rate has no row to price from: take its cost from the cart address's list.
            $cart = CartHelper::getCart();
            if ($cart) {
                $address = $cart->getShippingAddress();
                $rate = AddressHelper::resolveSelectedRate(Arr::get($address, 'country'), Arr::get($address, 'state'), (array) $cart->cart_data, $shippingMethodId, $address);
                $charge = $rate ? Helper::roundCent(Arr::get($rate, 'cost', 0)) : 0;
            }
        }

        ob_start();
        do_action('fluent_cart/views/checkout_page_cart_item_list', [
            'checkout' => $checkOutHelper,
            'items'    => $checkOutHelper->getItems()
        ]);
        $views = ob_get_clean();

        $items['views'] = $views;
        $items['subtotal'] = $checkOutHelper->getItemsAmountSubtotal(true, true);

        $total = $checkOutHelper->getItemsAmountTotal(false, false);

        $items['has_subscriptions'] = $checkOutHelper->hasSubscription() === 'yes';
        $items['shipping_charge'] = $charge;
        $items['unformatted_total'] = $total + $charge;
        $formatted = Helper::toDecimal($total + $charge, true);
        $items['total'] = $formatted;
        $items['shipping_charge_formated'] = Helper::toDecimal($charge, true);
        $items['shipping_method_id'] = $shippingMethodId;

        return [
            'items' => $items
        ];
    }

    public function getOrderInfo(Request $request)
    {
        $method = (string) $request->getSafe('method', 'sanitize_text_field');

        if ($method === '') {
            return $this->sendError([
                'message' => __('The payment method is required.', 'fluent-cart')
            ], 422);
        }

        $paymentManager = GatewayManager::getInstance()->get($method);

        if (!$paymentManager) {
            return $this->sendError([
                'message' => __('The selected payment method is not available.', 'fluent-cart')
            ], 422);
        }

        return $paymentManager->getOrderInfo($request->all());
    }
}
