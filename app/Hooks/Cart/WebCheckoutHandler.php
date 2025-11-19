<?php

namespace FluentCart\App\Hooks\Cart;

use FluentCart\Api\Checkout\CheckoutApi;
use FluentCart\Api\PaymentMethods;
use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\Api\Resource\CustomerResource;
use FluentCart\Api\Resource\FrontendResource\CustomerAddressResource;
use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\CartCheckoutHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\UtmHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\ShippingMethod;
use FluentCart\App\Modules\PaymentMethods\Core\GatewayManager;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\App\Services\Renderer\AddressSelectRenderer;
use FluentCart\App\Services\Renderer\CartDrawerRenderer;
use FluentCart\App\Services\Renderer\CartRenderer;
use FluentCart\App\Services\Renderer\CartSummaryRender;
use FluentCart\App\Services\Renderer\CheckoutFieldsSchema;
use FluentCart\App\Services\Renderer\CheckoutRenderer;
use FluentCart\App\Services\Renderer\ProductModalRenderer;
use FluentCart\App\Services\Renderer\ShippingMethodsRender;
use FluentCart\Framework\Support\Arr;

class WebCheckoutHandler
{
    public function register()
    {
        add_action('wp_ajax_fluent_cart_place_order', [$this, 'handlePlaceOrderAjax']);
        add_action('wp_ajax_nopriv_fluent_cart_place_order', [$this, 'handlePlaceOrderAjax']);

        add_action('wp_ajax_fluent_cart_checkout_routes', [$this, 'globalCheckoutRouteHandler']);
        add_action('wp_ajax_nopriv_fluent_cart_checkout_routes', [$this, 'globalCheckoutRouteHandler']);

        add_action('fluent_cart/order_bump_succeed', function ($data) {
            $cart = Arr::get($data, 'cart', null);
            $order = Arr::get($data, 'order', null);

            if (!$cart || !$order) {
                return;
            }

            $order->addLog('Order Bump Succeeded', 'Order Bump done from variation ID: ' . Arr::get($cart->checkout_data, 'order_bump.upgraded_from', '') . ' to variation ID: ' . Arr::get($cart->checkout_data, 'order_bump.upgraded_to', ''));
            $order->updateMeta('_order_bump', [
                'upgraded_from' => Arr::get($cart->checkout_data, 'order_bump.upgraded_from', ''),
                'upgraded_to'   => Arr::get($cart->checkout_data, 'order_bump.upgraded_to', '')
            ]);

        });

    }

    public function globalCheckoutRouteHandler()
    {
        nocache_headers();

        $startedAt = microtime(true);

        $action = App::request()->get('fc_checkout_action');
        $result = [];
        switch ($action) {
            case 'apply_coupon':
                $result = $this->handleApplyCouponAjax();
                break;
            case 'remove_coupon':
                $result = $this->handleRemoveCouponAjax();
                break;
            case 'get_checkout_summary_view':
            case 'reapply_coupon':
                $result = $this->handleGetCheckoutSummaryViewAjax();
                break;
            case 'get_order_info':
                $result = $this->handleGetOrderInfoAjax();
                break;
            case 'save_checkout_data':
                $result = $this->patchCheckoutData();
                break;
            case 'get_country_info':
                $result = $this->handleGetCountryInfoAjax();
                break;
            case 'update_address_select':
                $result = $this->handleUpdateAddressSelectAjax();
                break;

            case 'get_shipping_methods_list_view':
                $result = $this->handleGetShippingMethodsListViewAjax();
                break;
            case 'fluent_cart_cart_update':
                $result = $this->handleCartUpdateAjax();
                break;
            case 'fluent_cart_cart_status':
                $result = $this->handleCartStatusAjax();
                break;
            case 'apply_order_bump':
                $result = $this->handleOrderBumpRequest();
                break;
            case 'get_product_modal_view':
                $result = $this->getProductModalView();
                break;
        }

        if (is_wp_error($result)) {
            wp_send_json([
                'message' => Arr::get($result, 'message') ?? $result->get_error_message()
            ], 422);
        }

        if (is_array($result)) {
            $result['_bench'] = microtime(true) - $startedAt;
        }

        wp_send_json($result, 200);
    }

    public function handlePlaceOrderAjax()
    {
        nocache_headers();
        $data = App::request()->all();

        CheckoutApi::placeOrder($data, true);
    }

    public function getShippingChargeData($cart): array
    {
        $shippingMethodId = App::request()->getSafe('shipping_method_id', 'sanitize_text_field');

        if (!$shippingMethodId) {
            $shippingMethodId = Arr::get($cart->checkout_data, 'shipping_data.shipping_method_id');
        }

        $shippingMethod = ShippingMethod::query()->find($shippingMethodId);

        $charge = 0;
        if (!empty($shippingMethod)) {
            $shippingCountry = Arr::get($cart->checkout_data, 'form_data.shipping_country');
            $shippingState = Arr::get($cart->checkout_data, 'form_data.shipping_state');

            $lists = AddressHelper::getAvailableShippingMethodLists(['country_code' => $shippingCountry, 'state' => $shippingState]);

            if (isset($lists['available_shipping_methods'])) {
                $availableShippingMethods = Arr::get($lists, 'available_shipping_methods', []);
                $shippingMethodIds = Arr::pluck($availableShippingMethods, 'id');

                if (in_array($shippingMethodId, $shippingMethodIds)) {
                    $shippingMethod = ShippingMethod::query()->find($shippingMethodId);
                    if (!empty($shippingMethod)) {
                        $charge = CartHelper::calculateShippingMethodCharge($shippingMethod);
                    }
                } else {
                    CartHelper::resetShippingCharge();
                }
            } else {
                CartHelper::resetShippingCharge();
            }
        }

        return [
            'charge'           => $charge,
            'formatted_charge' => Helper::toDecimal($charge),
            'shippingMethodId' => $shippingMethodId
        ];
    }

    public function handleGetOrderInfoAjax()
    {
        $method = App::request()->getSafe('method', 'sanitize_text_field');
        $paymentManager = GatewayManager::getInstance()->get($method);
        return $paymentManager->getOrderInfo(App::request()->all());
    }

    public function handleApplyCouponAjax()
    {
        $couponCode = App::request()->getSafe('coupon_code', 'sanitize_text_field');
        $cart = CartHelper::getCart(null, false);

        if (!$cart) {
            return new \WP_Error('no_cart', __('No active cart found', 'fluent-cart'));
        }

        $result = $cart->applyCoupon($couponCode);

        if (is_wp_error($result)) {
            return $result;
        }

        ob_start();
        (new CartSummaryRender($cart))->render($withWrapper = false);
        $summary = ob_get_clean();

        $cartTotal = $cart->getEstimatedTotal();

        return [
            'fragments'         => [
                [
                    'selector' => '[data-fluent-cart-checkout-page-cart-items-wrapper]',
                    'content'  => $summary,
                    'type'     => 'replace'
                ]
            ],
            'cart'              => $cart,
            'total'             => $cartTotal,
            'formatted_total'   => Helper::toDecimal($cartTotal),
            'applied_coupons'   => $cart->coupons,
            'has_subscriptions' => $cart->hasSubscription()
        ];
    }

    public function handleRemoveCouponAjax()
    {
        $couponCode = App::request()->getSafe('coupon_code', 'sanitize_text_field');
        $cart = CartHelper::getCart(null, false);

        if (!$cart) {
            return new \WP_Error('no_cart', __('No active cart found', 'fluent-cart'));
        }

        $cart = $cart->removeCoupon($couponCode);

        if (is_wp_error($cart)) {
            return $cart;
        }

        ob_start();
        (new CartSummaryRender($cart))->render($withWrapper = false);
        $summary = ob_get_clean();

        $cartTotal = $cart->getEstimatedTotal();

        return [
            'fragments'         => [
                [
                    'selector' => '[data-fluent-cart-checkout-page-cart-items-wrapper]',
                    'content'  => $summary,
                    'type'     => 'replace'
                ]
            ],
            'total'             => $cartTotal,
            'formatted_total'   => Helper::toDecimal($cartTotal),
            'applied_coupons'   => $cart->coupons,
            'has_subscriptions' => $cart->hasSubscription()
        ];
    }

    public function handleGetCheckoutSummaryViewAjax()
    {
        $cart = CartHelper::getCart(null, false);
        if (!$cart) {
            return new \WP_Error('no_cart', __('No active cart found', 'fluent-cart'));
        }

        $cart = $cart->reValidateCoupons();

        $checkOutHelper = CartCheckoutHelper::make();
        $checkOutHelper->setCart($cart);

        $shippingChargeData = $this->getShippingChargeData($cart);
        $shippingCharge = Arr::get($shippingChargeData, 'charge');
        $shippingMethodId = Arr::get($shippingChargeData, 'shippingMethodId');
        $formattedShippingCharge = Arr::get($shippingChargeData, 'formatted_charge');
        $subtotal = $checkOutHelper->getItemsAmountSubtotal(false);

        $formattedSubtotal = $checkOutHelper->getItemsAmountSubtotal(true);

        $totalPrice = $checkOutHelper->getItemsAmountTotal(false) + $shippingCharge;

        if (!empty($cart->checkout_data['tax_data'])) {
            $taxTotal = (int)Arr::get($cart->checkout_data, 'tax_data.tax_total', 0);
            $totalPrice += $taxTotal;
        }

        $oldShippingCharge = Arr::get($cart->checkout_data, 'shipping_data.shipping_charge', 0);

        if (!empty($shippingCharge)) {
            $cart->checkout_data = array_merge($cart->checkout_data, [
                'shipping_data' => [
                    'shipping_method_id' => $shippingMethodId,
                    'shipping_charge'    => $shippingCharge
                ]
            ]);
        } else {
            $cart->checkout_data = array_merge($cart->checkout_data, [
                'shipping_data' => [
                    'shipping_method_id' => null,
                    'shipping_charge'    => 0
                ]
            ]);
        }

        $cart->save();

        // shipping charge changed
        if ($shippingCharge !== $oldShippingCharge) {
            do_action('fluent_cart/checkout/shipping_data_changed', [
                'cart' => $cart
            ]);
        }


        $totalPrice = apply_filters('fluent_cart/cart/estimated_total', $totalPrice, [
            'cart' => $cart
        ]);

        ob_start();
        (new CartSummaryRender($cart))->render(false);
        $cartSummaryInner = ob_get_clean();

        return [
            'fragments'                 => [
                [
                    'selector' => '[data-fluent-cart-checkout-page-cart-items-wrapper]',
                    'content'  => $cartSummaryInner,
                    'type'     => 'replace'
                ]
            ],
            'total'                     => $totalPrice,
            'applied_coupons'           => $cart->coupons,
            'shipping_charge'           => $shippingCharge,
            'formatted_shipping_charge' => $formattedShippingCharge,
            'has_subscriptions'         => $cart->hasSubscription(),
            'shipping_method_id'        => $shippingMethodId
        ];

    }

    public function handleUpdateAddressSelectAjax()
    {
        $customerAddressId = App::request()->get('customer_address_id');
        $address = CustomerAddressResource::find($customerAddressId, ['with' => App::request()->get('with', [])]);

        if (!$address) {
            return [
                'message' => __('Address not found', 'fluent-cart')
            ];
        }

        //update address into cart
        $addressId = Arr::get($address, 'address.id');
        $country = Arr::get($address, 'address.country');
        $state = Arr::get($address, 'address.state');
        $type = Arr::get($address, 'address.type', 'billing');
        $cart = CartHelper::getCart(App::request()->get('fct_cart_hash'));

        $oldTaxTotal = Arr::get($cart->checkout_data, 'tax_data.tax_total', 0);
        $oldShippingCharge = Arr::get($cart->checkout_data, 'shipping_data.shipping_charge', 0);

        $checkoutData = Arr::wrap($cart->checkout_data);
        Arr::set($checkoutData, 'form_data.' . $type . '_address_id', $addressId);
        Arr::set($checkoutData, 'form_data.' . $type . '_country', $country);
        Arr::set($checkoutData, 'form_data.' . $type . '_state', $state);

        if ($type === 'billing' && Arr::get($checkoutData, 'form_data.ship_to_different', 'no') === 'no') {
            Arr::set($checkoutData, 'form_data.shipping_address_id', $addressId);
            Arr::set($checkoutData, 'form_data.shipping_country', $country);
            Arr::set($checkoutData, 'form_data.shipping_state', $state);
        }

        $cart->checkout_data = $checkoutData;
        $cart->save();

        $customerId = Arr::get($address, 'address.customer_id');

        $customer = \FluentCart\Api\Resource\CustomerResource::getCurrentCustomer();
        if (empty($customer) || $customer->id != $customerId) {
            return [
                'message' => __('You are not authorized to view this address', 'fluent-cart')
            ];
        }

        $formattedAddress = Arr::get($address, 'address.formatted_address');

        // Use output buffering to generate HTML
        ob_start();

        // Extract the address parts
        $addressParts = [
            trim(Arr::get($formattedAddress, 'address_1') ?? ''),
            trim(Arr::get($formattedAddress, 'address_2') ?? ''),
            trim(Arr::get($formattedAddress, 'city') ?? ''),
            trim(Arr::get($formattedAddress, 'state') ?? ''),
            trim(Arr::get($formattedAddress, 'country') ?? ''),
        ];

        // Filter out empty or null parts
        $addressParts = array_filter($addressParts, function ($part) {
            return $part !== '';
        });

        // Join parts with comma and space


        do_action('fluent_cart/checkout/form_data_changed', [
            'cart' => $cart
        ]);

        ob_start();
        (new CartSummaryRender($cart))->render(false);
        $cartSummaryInner = ob_get_clean();

        $newTaxTotal = Arr::get($cart->checkout_data, 'tax_data.tax_total', 0);
        $newShippingCharge = Arr::get($cart->checkout_data, 'shipping_data.shipping_charge', 0);

        $checkoutData = [
            'message'                 => __('Address Attached', 'fluent-cart'),
            'fragments'               => [
                [
                    'selector' => '[data-fluent-cart-checkout-page-cart-items-wrapper]',
                    'content'  => $cartSummaryInner,
                    'type'     => 'replace'
                ]
            ],
            'tax_total_changes'       => $oldTaxTotal != $newTaxTotal,
            'shipping_charge_changes' => $oldShippingCharge != $newShippingCharge
        ];

        return apply_filters('fluent_cart/checkout/checkout_data_changed', $checkoutData, ['cart' => $cart]);

    }

    public function handleGetCountryInfoAjax()
    {
        $timezone = App::request()->getSafe('timezone', 'sanitize_text_field');

        $code = App::request()->getSafe('country_code', 'sanitize_text_field');
        $countryInfo = LocalizationManager::getCountryInfoFromRequest($timezone, $code);

        return [
            'country_info' => $countryInfo
        ];
    }

    public function getShippingMethodsListView(array $data)
    {
        $availableShippingMethods = AddressHelper::getAvailableShippingMethodLists($data);
        $shippingMethods = Arr::get($availableShippingMethods, 'available_shipping_methods');
        $countryCode = Arr::get($availableShippingMethods, 'country_code');
        $status = Arr::get($availableShippingMethods, 'status');

        if ($status === false) {
            return false;
        }

        $cart = CartHelper::getCart();

        $cartRender = (new CheckoutRenderer($cart));

        ob_start();
        $cartRender->getFragment('shipping_methods');
        $content = ob_get_clean();

        return [
            'view'             => $content,
            'country_code'     => $countryCode,
            'shipping_methods' => $shippingMethods
        ];
    }

    public function handleGetShippingMethodsListViewAjax()
    {
        $data = [
            'country_code' => App::request()->get('country_code'),
            'state'        => App::request()->get('state'),
            'timezone'     => App::request()->get('timezone')
        ];

        $cart = CartHelper::getCart();

        if (!$cart) {
            return new \WP_Error('no_cart', __('No active cart found', 'fluent-cart'));
        }

        $availableShippingMethods = AddressHelper::getShippingMethods($data['country_code'], $data['state'], $data['timezone']);

        ob_start();

        $selectedId = Arr::get($cart->checkout_data, 'shipping_data.shipping_method_id', '');

        if (!$availableShippingMethods || is_wp_error($availableShippingMethods)) {
            (new ShippingMethodsRender($availableShippingMethods, $selectedId))->render();
        } else {
            foreach ($availableShippingMethods as $method) {
                $method->charge_amount = CartHelper::calculateShippingMethodCharge($method, $cart->cart_data);
            }

            (new ShippingMethodsRender($availableShippingMethods, $selectedId))->render();
        }

        $shippingMethodsView = ob_get_clean();

        return [
            'status'       => true,
            'fragments'    => [
                [
                    'selector' => '[data-fluent-cart-checkout-page-shipping-methods-wrapper]',
                    'content'  => $shippingMethodsView,
                    'type'     => 'replace'
                ]
            ],
            'country_code' => $data['country_code'],
        ];
    }

    public function handleCartStatusAjax()
    {
        return CartResource::getStatus();
    }

    public function handleCartUpdateAjax()
    {
        $requestData = App::request()->all();

        $data = [
            'item_id'  => (int)Arr::get($requestData, 'item_id'),
            'quantity' => (int)Arr::get($requestData, 'quantity', 0),
            'by_input' => Arr::get($requestData, 'by_input', false)
        ];

        $cart = CartResource::update($data, '', $requestData);

        if (is_wp_error($cart)) {
            return $cart;
        }

        do_action('fluent_cart/checkout/cart_amount_updated', [
            'cart' => $cart
        ]);

        $itemCount = 0;

        if ($cart) {
            $itemCount = count($cart->cart_data ?? []);
        }

        $cartItems = Arr::get(CartResource::getStatus(), 'cart_data', []);

        $defaultOpen = Arr::get($requestData, 'open_cart', false);
        $isAdminBarEnabled = Arr::get($requestData, 'is_admin_bar_enabled', false);

        $fragments = [];
        if (!empty($cartItems)) {
            ob_start();
            (new CartDrawerRenderer($cartItems, [
                'item_count'           => $itemCount,
                'open_cart'            => $defaultOpen,
                'is_admin_bar_enabled' => $isAdminBarEnabled
            ]))->render();
            $cartDrawerView = ob_get_clean();

            ob_start();
            (new CartRenderer($cartItems))->renderItems($cartItems);
            $cartDrawerItemsView = ob_get_clean();

            ob_start();
            (new CartRenderer($cartItems))->renderTotal();
            $cartDrawerItemsTotalView = ob_get_clean();

            ob_start();
            (new CartDrawerRenderer($cartItems, [
                'item_count'           => $itemCount,
                'open_cart'            => $defaultOpen,
                'is_admin_bar_enabled' => $isAdminBarEnabled
            ]))->renderItemCount();
            $cartItemCount = ob_get_clean();

            $fragments = [
                [
                    'selector' => '[data-fluent-cart-cart-drawer-container]',
                    'content'  => $cartDrawerView,
                    'type'     => 'replace'
                ],
                [
                    'selector' => '[data-fluent-cart-cart-content-wrapper]',
                    'content'  => $cartDrawerItemsView,
                    'type'     => 'replace'
                ],
                [
                    'selector' => '[data-fluent-cart-cart-total-wrapper]',
                    'content'  => $cartDrawerItemsTotalView,
                    'type'     => 'replace'
                ],
                [
                    'selector' => '.fluent-cart-cart-badge-count',
                    'content'  => $itemCount > 0 ? $cartItemCount : '',
                    'type'     => 'replace'
                ]
            ];
        }

        if (empty($cartItems)) {
            ob_start();
            (new CartRenderer($cartItems))->renderEmpty();
            $cartDrawerItemsView = ob_get_clean();
            $fragments[] = [
                'selector' => '[data-fluent-cart-cart-content-wrapper]',
                'content'  => $cartDrawerItemsView,
                'type'     => 'replace'
            ];
        }


        return [
            'message'   => __('Cart updated successfully', 'fluent-cart'),
            'data'      => apply_filters('fluent_cart/checkout/cart_updated', [
                'cart' => $cart,
            ]),
            'fragments' => $fragments
        ];
    }

    public function patchCheckoutData()
    {
        $cart = CartHelper::getCart();
        if (!$cart) {
            return new \WP_Error('no_cart', __('No active cart found', 'fluent-cart'));
        }

        $allData = App::request()->all();
        $dataKey = (string)Arr::get($allData, 'data_key');
        $dataValue = (string)Arr::get($allData, 'data_value');

        if ($dataKey) {
            $allData[$dataKey] = $dataValue;
        }

        $allData = AddressHelper::maybePushAddressDataForCheckout($allData, 'billing');
        if (Arr::get($allData, 'ship_to_different') === 'yes') {
            $allData = AddressHelper::maybePushAddressDataForCheckout($allData, 'shipping');
        } else {
            $allData = AddressHelper::mergeBillingWithShipping($allData);
        }

        $validKeys = [
            'ship_to_different'    => 'form_data.ship_to_different',
            'billing_email'        => 'form_data.billing_email',
            'billing_address_id'   => 'form_data.billing_address_id',
            'shipping_address_id'  => 'form_data.shipping_address_id',
            'billing_company'      => 'form_data.billing_company',
            'order_notes'          => 'form_data.order_notes',
            'shipping_method_id'   => 'shipping_data.shipping_method_id',
            '_fct_pay_method'      => 'form_data._fct_pay_method',
            'billing_company_name' => 'form_data.billing_company_name',
            'fct_billing_tax_id'   => 'tax_data.vat_number'
        ];

        $addressFieldKeys = [
            'full_name',
            'country',
            'address_1',
            'address_2',
            'state',
            'city',
            'postcode'
        ];

        foreach ($addressFieldKeys as $addressFieldKey) {
            $validKeys['billing_' . $addressFieldKey] = 'form_data.billing_' . $addressFieldKey;
            $validKeys['shipping_' . $addressFieldKey] = 'form_data.shipping_' . $addressFieldKey;
        }

        $prevFlatData = [];
        $prevCheckoutData = $cart->checkout_data;
        foreach ($validKeys as $dataName => $dataPath) {
            $prevFlatData[$dataName] = Arr::get($prevCheckoutData, $dataPath, null);
        }
        $prevFlatData = array_filter($prevFlatData);
        $validData = Arr::only($allData, array_keys($validKeys));


        $normalizeData = [];

        foreach ($validData as $key => $value) {
            $prevValue = Arr::get($prevFlatData, $key, null);
            if ($prevValue != $value) {
                $normalizeData[$key] = $value;
            }
        }

        if (!$normalizeData) {
            return [
                'message' => __('No changes detected', 'fluent-cart')
            ];
        }

        $normalizeData = $this->normalizeCheckoutChangeData($normalizeData, $allData);

        // Validate these data and filter out invalid data points and also push the
        // address data into this array.


        $checkoutData = $cart->checkout_data;
        foreach ($normalizeData as $normalizeKey => $normalizeValue) {
            Arr::set($checkoutData, $validKeys[$normalizeKey], $normalizeValue);
        }

        $fillData = [
            'checkout_data' => $checkoutData,
            'cart_data'     => $cart->cart_data,
            'hook_changes'  => [
                'shipping' => false,
                'tax'      => false
            ]
        ];


        $fillData = apply_filters('fluent_cart/checkout/before_patch_checkout_data', $fillData, [
            'cart'      => $cart,
            'prev_data' => $prevFlatData,
            'changes'   => $normalizeData,
            'all_data'  => $allData
        ]);

        $hookChanges = Arr::get($fillData, 'hook_changes', []);
        unset($fillData['hook_changes']);

        if (isset($normalizeData['billing_email'])) {
            $cart->email = $normalizeData['billing_email'];
        }

        if (isset($normalizeData['billing_full_name'])) {
            $fullName = $normalizeData['billing_full_name'];
            $nameParts = explode(' ', $fullName);
            $cart->first_name = array_shift($nameParts);
            $cart->last_name = implode(' ', $nameParts);
        }

        $cart->fill($fillData);

        $cart = CartHelper::getCart();
        $sanitizedUtmData = UtmHelper::getUtmDataOfRequest();
        $cart->utm_data = $sanitizedUtmData;

        $cart->save();

        $fragments = [];

        $cartRender = (new CheckoutRenderer($cart));

        if (!empty($hookChanges['shipping'])) {
            $fragments[] = [
                'selector' => '[data-fluent-cart-checkout-page-shipping-methods-wrapper]',
                'content'  => $cartRender->getFragment('shipping_methods'),
                'type'     => 'replace'
            ];
        }

        if (array_filter($hookChanges)) {
            $fragments[] = [
                'selector' => '[data-fluent-cart-checkout-page-cart-items-wrapper]',
                'content'  => $cartRender->getFragment('cart_summary_fragment'),
                'type'     => 'replace'
            ];

            // also update the payment methods
            $fragments[] = [
                'selector' => '[data-fluent-cart-checkout-payment-methods]',
                'content'  => $cartRender->getFragment('payment_methods'),
                'type'     => 'replace'
            ];
        }

        if (($dataKey === 'billing_address_id' || $dataKey === 'shipping_address_id') && $dataValue !== '') {
            // get current customer

            $customer = CustomerResource::getCurrentCustomer();

            $type = $dataKey === 'billing_address_id' ? 'billing' : 'shipping';
            $requiredShipping = $cart->requireShipping();
            $config = [
                'type'          => $type,
                'product_type'  => $requiredShipping ? 'physical' : 'digital',
                'with_shipping' => $requiredShipping
            ];
            if ($type === 'billing') {
                $config['with_shipping'] = Arr::get($allData, 'ship_to_different', 'no') !== 'yes';
                $config['billing_address_id'] = $dataValue;
            } else {
                $config['shipping_address_id'] = $dataValue;
            }
            $addresses = AddressHelper::getCustomerValidatedAddresses($config, $customer);
            $address = AddressHelper::getPrimaryAddress($addresses, $config, $customer, $type);
            $requirementsFields = CheckoutFieldsSchema::getCheckoutFieldsRequirements(
                $type,
                Arr::get($config, 'product_type'),
                Arr::get($config, 'with_shipping')
            );
            ob_start();
            (new AddressSelectRenderer($addresses, $address, $requirementsFields, $type))->renderAddressInfo();
            $addressRender = ob_get_clean();

            $fragments[] = [
                'selector' => '[data-fluent-cart-checkout-page-form-address-info-wrapper]',
                'content'  => $addressRender,
                'type'     => 'replace'
            ];
        }

        $fragments = apply_filters('fluent_cart/checkout/after_patch_checkout_data_fragments', $fragments, [
            'cart'    => $cart,
            'changes' => $normalizeData
        ]);

        return [
            'message'   => __('Data saved successfully', 'fluent-cart'),
            'changes'   => $normalizeData,
            'fragments' => $fragments,
            'cart'      => $cart
        ];
    }

    public function handleSaveCustomerDataAjax()
    {

        $key = sanitize_text_field(App::request()->get('data_key'));
        $value = sanitize_text_field(App::request()->get('data_value'));

        $cart = CartHelper::getCart();
        if (!$cart) {
            return new \WP_Error('no_cart', __('No active cart found', 'fluent-cart'));
        }

        $addressFieldKeys = [
            'full_name',
            'country',
            'address_1',
            'address_2',
            'state',
            'city',
            'postcode'
        ];

        $validKeys = [
            'ship_to_different'   => 'form_data.ship_to_different',
            'billing_email'       => 'form_data.billing_email',
            'billing_address_id'  => 'form_data.billing_address_id',
            'shipping_address_id' => 'form_data.shipping_address_id',
            'billing_company'     => 'form_data.billing_company',
            'order_notes'         => 'form_data.order_notes',
            'shipping_method_id'  => 'shipping_data.shipping_method_id',
            '_fct_pay_method'     => 'form_data._fct_pay_method',
        ];

        foreach ($addressFieldKeys as $addressFieldKey) {
            $validKeys['billing_' . $addressFieldKey] = 'form_data.billing_' . $addressFieldKey;
            $validKeys['shipping_' . $addressFieldKey] = 'form_data.shipping_' . $addressFieldKey;
        }

        if (!isset($validKeys[$key])) {
            return new \WP_Error('invalid_key', __('Invalid data key', 'fluent-cart'));
        }

        $checkoutData = Arr::wrap($cart->checkout_data);

        $prevValue = Arr::get($checkoutData, $validKeys[$key], '');
        if ($prevValue === $value) {
            return [
                'message' => __('Updated', 'fluent-cart')
            ];
        }

        $oldCheckoutData = $checkoutData;
        Arr::set($checkoutData, $validKeys[$key], $value);

        $fillData = apply_filters('fluent_cart/checkout/before_patch_checkout_data', [
            'checkout_data' => $checkoutData,
            'cart_data'     => $cart->cart_data,
            'hook_changes'  => [
                'shipping' => false,
                'tax'      => false
            ]
        ], [
            'cart'              => $cart,
            'key'               => $key,
            'value'             => $value,
            'prev_value'        => $prevValue,
            'old_checkout_data' => $oldCheckoutData
        ]);

        $changes = Arr::get($fillData, 'hook_changes', []);
        unset($fillData['hook_changes']);


        $cart->fill($fillData);
        $cart->save();
        $fragments = [];


        if (Arr::get($changes, 'shipping', false)) {
            $fragments[] = [
                'selector' => '[data-fluent-cart-checkout-page-shipping-methods-wrapper]',
                'content'  => (new CheckoutRenderer($cart))->getFragment('shipping_methods'),
                'type'     => 'replace'
            ];
        }
        //(new CartSummaryRender($cart))->render(false);

        ob_start();
        (new CartSummaryRender($cart))->render(false);
        $render = ob_get_clean();
        if (array_filter($changes)) {
            $fragments[] = [
                'selector' => '[data-fluent-cart-checkout-page-cart-items-wrapper]',
                //'content'  => (new CheckoutRender($cart))->getFragment('cart_summary_fragment'),
                'content'  => $render,
                'type'     => 'replace'
            ];
        }

        return [
            'fragments'               => $fragments,
            'message'                 => __('Data saved successfully', 'fluent-cart'),
            'tax_total_Changes'       => Arr::get($changes, 'tax', false),
            'shipping_charge_changes' => Arr::get($changes, 'shipping', false),
            'notify'                  => false,
            'cart'                    => $cart
        ];

        // Shipping Module

        add_action('fluent_cart/checkout/customer_data_saved', function ($data) {
            // Recalculate tax if needed

            do_action('fluent_cart/checkout/tax_data_changed', $data);

        });

        do_action('fluent_cart/checkout/customer_data_saved', [
            'cart'      => $cart,
            'key'       => $key,
            'value'     => $value,
            'old_value' => $prevValue,
            'old_data'  => $oldCheckoutData
        ]);

        $didTaxChanges = did_action('fluent_cart/checkout/tax_data_changed');
        $didShippingChanges = did_action('fluent_cart/checkout/shipping_data_changed');


        if ($cart) {
            $checkoutRender = new CheckoutRenderer($cart);
            $shippingChanged = false;
            // we have to decide for which keys we will update shipping option
            $fragments = [];
            if ($cart->requireShipping()) {
                $watchingKeys = ['ship_to_different', 'billing_address', 'billing_country', 'billing_state'];
                if (Arr::get($cart->checkout_data, 'form_data.ship_to_different', null) === 'yes') {
                    $watchingKeys = ['ship_to_different', 'shipping_address', 'shipping_country', 'shipping_state'];
                }

                if (in_array($key, $watchingKeys)) {
                    $fragments[] = [
                        'selector' => '[data-fluent-cart-checkout-page-shipping-methods-wrapper]',
                        'content'  => $checkoutRender->getFragment('shipping_methods'),
                        'type'     => 'replace'
                    ];
                }

                $shippingChanged = true;
            }

            $oldTaxTotal = Arr::get($cart->checkout_data, 'tax_data.tax_total', 0);
            $oldShippingCharge = Arr::get($cart->checkout_data, 'shipping_data.shipping_charge', 0);

            $countryCode = Arr::get($cart->checkout_data, 'form_data.shipping_country');

            if (!$countryCode) {
                $countryCode = Arr::get($cart->checkout_data, 'form_data.billing_country');
            }

            if (Arr::get($cart->checkout_data, 'form_data.ship_to_different', 'no') === 'no') {
                $countryCode = Arr::get($cart->checkout_data, 'form_data.billing_country');
            }

            $data = [
                'country_code' => $countryCode,
                'timezone'     => ''
            ];

            $list = $this->getShippingMethodsListView($data);

            if ($list === false) {
                $cart->checkout_data = array_merge($cart->checkout_data, [
                    'shipping_data' => [
                        'shipping_method_id' => null,
                        'shipping_charge'    => 0
                    ]
                ]);
                $cart->save();
            }

            do_action('fluent_cart/checkout/form_data_changed', [
                'cart' => $cart
            ]);

            $fragments[] = [
                'selector' => '[data-fluent-cart-checkout-page-cart-items-wrapper]',
                'content'  => $checkoutRender->getFragment('cart_summary_fragment'),
                'type'     => 'replace'
            ];

            $newTaxTotal = Arr::get($cart->checkout_data, 'tax_data.tax_total', 0);
            $newShippingCharge = Arr::get($cart->checkout_data, 'shipping_data.shipping_charge', 0);

            $checkoutData = [
                'fragments'               => $fragments,
                'message'                 => __('Data saved successfully', 'fluent-cart'),
                'tax_total_Changes'       => $oldTaxTotal != $newTaxTotal,
                'shipping_charge_changes' => $oldShippingCharge != $newShippingCharge,
                'notify'                  => false
            ];

            return apply_filters('fluent_cart/checkout/checkout_data_changed', $checkoutData, ['cart' => $cart]);
        }

        return [
            'message' => __('Failed to save data', 'fluent-cart'),
            'notify'  => false
        ];
    }

    public function handleOrderBumpRequest()
    {
        $requestData = App::request()->all();

        $cart = CartHelper::getCart();
        if (!$cart) {
            return new \WP_Error('no_cart', __('No active cart found', 'fluent-cart'));
        }

        $checkoutData = $cart->checkout_data;
        if (!empty($checkoutData['upgrade_data']) || !empty($checkoutData['is_locked'])) {
            return new \WP_Error('invalid_request', __('This cart is locked or already has an upgrade applied.', 'fluent-cart'));
        }

        $upgradeFromVariationId = (int)Arr::get($requestData, 'upgrade_form', 0);
        $targetVariationId = (int)Arr::get($requestData, 'upgrade_to', 0);
        $bumpId = (int)Arr::get($requestData, 'bump_id', 0);

        if ($bumpId) {
            $response = new \WP_Error('invalid_bump', __('Could not apply item at this time.', 'fluent-cart'));
            return apply_filters('fluent_cart/apply_order_bump', $response, [
                'bump_id'      => $bumpId,
                'cart'         => $cart,
                'request_data' => $requestData
            ]);
        }

        if (!$upgradeFromVariationId || !$targetVariationId) {
            return new \WP_Error('invalid_request', __('Invalid upgrade request.', 'fluent-cart'));
        }

        $productVariation = ProductVariation::query()->find($targetVariationId);

        if (!$productVariation || !$productVariation->canPurchase()) {
            return new \WP_Error('invalid_variation', __('The selected product variation is not available for purchase.', 'fluent-cart'));
        }

        $cart->removeItem($upgradeFromVariationId);
        $cart = $cart->addByVariation($productVariation, [
            'quantity' => 1,
            'append'   => false
        ]);

        if (is_wp_error($cart)) {
            return $cart;
        }

        $isUpgraded = Arr::get($requestData, 'is_upgraded') === 'yes';
        if ($isUpgraded) {
            $checkoutData['order_bump'] = [
                'upgraded_from' => $upgradeFromVariationId,
                'upgraded_to'   => $targetVariationId
            ];
            $existingActions = Arr::get($checkoutData, '__on_success_actions__', []);
            if (!is_array($existingActions)) {
                $existingActions = [];
            }
            $existingActions[] = 'fluent_cart/order_bump_succeed';
            $checkoutData['__on_success_actions__'] = $existingActions;
        } else {
            unset($checkoutData['order_bump']);
            $existingActions = Arr::get($checkoutData, '__on_success_actions__', []);
            if (!is_array($existingActions)) {
                $existingActions = [];
            }

            if (!empty($existingActions) && in_array('fluent_cart/order_bump_succeed', $existingActions)) {
                $existingActions = array_diff($existingActions, ['fluent_cart/order_bump_succeed']);
                $existingActions = array_values($existingActions);
            }

            if (is_array($existingActions)) {
                $existingActions = array_filter($existingActions, function ($action) {
                    return $action !== 'fluent_cart/order_bump_succeed';
                });
            }

            $checkoutData['__on_success_actions__'] = $existingActions;
        }

        $cart->checkout_data = $checkoutData;
        $cart->save();

        do_action('fluent_cart/checkout/cart_amount_updated', [
            'cart' => $cart
        ]);

        return [
            'message' => $isUpgraded ? __('Item has been applied successfully', 'fluent-cart') : __('Item has been reverted successfully', 'fluent-cart')
        ];
    }


    public function normalizeCheckoutChangeData($changedData, $allData): array
    {

        $sanitizedData = [];
        $errors = [];
        foreach ($changedData as $dataKey => $dataValue) {
            if (in_array($dataKey, ['billing_full_name', 'shipping_full_name'])) {
                $sanitizedData[$dataKey] = sanitize_text_field($dataValue);
            } else if (in_array($dataKey, ['billing_email', 'shipping_email'])) {
                if (empty($dataValue)) {
                    $sanitizedData[$dataKey] = '';
                } else {
                    $sanitizedData[$dataKey] = sanitize_email($dataValue ?? '');
                }
            } else if ($dataKey === 'billing_state' && !empty($dataValue)) {
                $billingCountry = Arr::get($allData, 'billing_country');

                $states = LocalizationManager::getInstance()->statesOptions($billingCountry);
                $countryStates = array_values(array_column($states, 'value'));
                if (!empty($states) && !in_array($dataValue, $countryStates)) {
                    $errors[$dataKey] = __('Invalid state code.', 'fluent-cart');
                    $sanitizedData[$dataKey] = null;
                } else {
                    $sanitizedData[$dataKey] = $dataValue;
                }
            } else if ($dataKey === 'shipping_state' && !empty($dataValue)) {
                $shipToDifferent = ((Arr::get($allData, 'ship_to_different', 'no') === 'yes') == 'yes');
                $shippingCountry = $shipToDifferent ? Arr::get($allData, 'shipping_country') : Arr::get($allData, 'billing_country');
                $states = LocalizationManager::getInstance()->statesOptions($shippingCountry);
                if (!empty($states) && !in_array($dataValue, array_column($states, 'value'))) {
                    $errors[$dataKey] = __('Invalid state code.', 'fluent-cart');
                    $sanitizedData[$dataKey] = null;
                } else {
                    $sanitizedData[$dataKey] = $dataValue;
                }
            } else if ($dataKey === '_fct_pay_method') {
                $value = sanitize_text_field($dataValue);
                $methods = PaymentMethods::getActiveMeta();
                $methods = array_column($methods, 'route');
                if (!in_array($value, $methods)) {
                    $value = null;
                    $errors[$dataKey] = __('Invalid payment method.', 'fluent-cart');
                }
                $sanitizedData[$dataKey] = $value;
            } else if ($dataKey === 'shipping_method_id') {
                $shipToDifferent = ((Arr::get($allData, 'ship_to_different', 'no') === 'yes') == 'yes');
                $countryKey = $shipToDifferent ? 'shipping_country' : 'billing_country';
                $stateKey = $shipToDifferent ? 'shipping_state' : 'billing_state';
                $shippingCountry = Arr::has($changedData, $countryKey) ? Arr::get($changedData, $countryKey) : Arr::get($allData, $countryKey);
                $shippingState = Arr::has($changedData, $stateKey) ? Arr::get($changedData, $stateKey) : Arr::get($allData, $stateKey);
                $availableShippingMethods = AddressHelper::getShippingMethods($shippingCountry, $shippingState);

                $found = false;
                foreach ($availableShippingMethods as $method) {
                    if ($method->id == $dataValue) {
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    $errors[$dataKey] = __('Invalid shipping method.', 'fluent-cart');
                    $sanitizedData[$dataKey] = null;
                } else {
                    $sanitizedData[$dataKey] = sanitize_text_field($dataValue);
                }
            } else {
                $sanitizedData[$dataKey] = sanitize_text_field($dataValue);
            }
        }

        return $sanitizedData;
    }

    private function pushAddressData($data, $type = 'billing')
    {
        return AddressHelper::maybePushAddressDataForCheckout($data, $type);
    }

    public function getProductModalView()
    {
        $productId = App::request()->getSafe('product_id', 'intval');
        $product = Product::query()->find($productId);

        if (!$product) {
            return $this->sendError([
                'message' => __('Product not found', 'fluent-cart')
            ]);
        }
        ob_start();
        (new ProductModalRenderer($product))->render();
        $view = ob_get_clean();
        return [
            'view' => $view
        ];
    }

}
