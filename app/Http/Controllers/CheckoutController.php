<?php

namespace FluentCart\App\Http\Controllers;


use FluentCart\Api\Checkout\CheckoutApi;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Models\ShippingMethod;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\Framework\Http\Request\Request;

class CheckoutController extends Controller
{
    /**
     * @throws \Exception
     */
    public function placeOrder(Request $request)
    {
        CheckoutApi::placeOrder($request->all(), true);
    }

    public function getCheckoutSummary(Request $request)
    {
        $checkOutHelper =  \FluentCart\App\Helpers\CartCheckoutHelper::make();
        $shippingMethodId = $request->getSafe('shipping_method_id', 'sanitize_text_field');


        $charge = 0;
        $shippingMethod = ShippingMethod::query()->find($shippingMethodId);
        if (!empty($shippingMethod)) {
            $charge = CartHelper::calculateShippingMethodCharge($shippingMethod);
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
        $paymentManager = GatewayManager::getInstance()->get($request->getSafe('method', 'sanitize_text_field'));
        return $paymentManager->getOrderInfo($request->all());
    }
}
