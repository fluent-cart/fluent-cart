<?php

namespace FluentCart\Api\Checkout;

use FluentCart\Api\Resource\FrontendResource\CustomerAddressResource;
use FluentCart\Api\Resource\FrontendResource\CustomerResource;
use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Events\Order\OrderCreated;
use FluentCart\App\Events\StockChanged;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\CartCheckoutHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\CheckoutProcessor;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\UtmHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\ShippingMethod;
use FluentCart\App\Services\CheckoutService;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\App\Services\OrderService;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Services\Renderer\CheckoutFieldsSchema;
use FluentCart\Framework\Http\Response;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;
use FluentCart\Framework\Validator\Validator;

class CheckoutApi
{

    /**
     * @throws \Exception
     */

    public static function placeOrder(array $data, $fromCheckout = false)
    {
        $userTz = Arr::get($data, 'user_tz', 'UTC');

        $cart = CartHelper::getCart();

        if (!$cart || !$cart->cart_data || $cart->stage === 'completed') {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Cart is empty or already completed', 'fluent-cart'),
            ]);
        }

        $cart = $cart->reValidateCoupons();

        $cartData = $cart->cart_data;
        $prevOrder = $cart->order;
        if ($prevOrder) {
            $prevOrder->load('order_items');
        }
        $isLockedCart = $cart->isLocked();

        // todo: we should handle this logic as we have multiple options like  PAYMENT_PARTIALLY_PAID....
        if ($prevOrder &&
            (
                in_array($prevOrder->status, Status::getOrderSuccessStatuses()) ||
                $prevOrder->payment_status != Status::PAYMENT_PENDING
            )
        ) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('You have already completed this order.', 'fluent-cart'),
            ]);
        }

        $data = static::addLoggedUserData($data);

        if (empty($data['billing_address_id'])) {
            if ($prevOrder instanceof Order) {
                $oldCustomer = $prevOrder->customer;
                if ($oldCustomer) {
                    $fallbackAddress = $oldCustomer->billing_address;
                    if ($fallbackAddress) {
                        $data['billing_address_id'] = $fallbackAddress->first()->id;
                    }
                }
            }
        }

        $data = static::prepareAddressData($data);

        $cartCheckoutService = new CheckoutService($cartData);
        $validatedData = static::validateData($data, $cart, $cartCheckoutService, $prevOrder);


        if (is_wp_error($validatedData)) {
            wp_send_json([
                'status' => 'failed',
                'errors' => $validatedData->get_error_data(),
            ]);
        }

        $orderData = OrderService::groupSanitizedData($validatedData);

        $shippingMethodId = Arr::get($orderData, 'others.fc_shipping_method');

        $shippingCharge = 0;
        if (!$cartCheckoutService->isAllDigital()) {
            $shippingMethod = ShippingMethod::query()->find($shippingMethodId);
            if (!empty($shippingMethod)) {
                $shippingCharge = CartHelper::calculateShippingMethodCharge($shippingMethod, $cartData);
            }
        }

        Arr::set($orderData, 'others.shipping_total', $shippingCharge);

        $cartCheckoutHelper = CartCheckoutHelper::make();

        try {
            OrderService::validateProducts($cartCheckoutHelper->getItems(), $prevOrder);
        } catch (\Exception $e) {
            wp_send_json([
                'message' => $e->getMessage(),
            ], 422);
        }

        $paymentMethod = PaymentHelper::validateAndGetPayMethod($cartCheckoutHelper, $orderData, $shippingCharge);

        Arr::set($orderData, 'others.payment_method', $paymentMethod);

        $orderData['user_tz'] = $userTz;

        $customer = static::getOrCreateCustomer($cartCheckoutHelper, $orderData);

        $shouldCreateUser = static::shouldCreateUser($orderData, Arr::get($orderData, 'billing_address', []));

        $taxTotal = (int)Arr::get($cart->checkout_data, 'tax_data.tax_total', 0); // behavoir not applied here

        $shippingTax = (int)Arr::get($cart->checkout_data, 'tax_data.shipping_tax', 0);
        $taxBehavior = apply_filters('fluent_cart/cart/tax_behavior', 0, ['cart' => $cart]);

        $checkoutProcessor = new CheckoutProcessor($cartCheckoutHelper->getItems(), [
            'customer_id'               => $customer->id,
            'user_tz'                   => $userTz,
            'create_account_after_paid' => $shouldCreateUser ? 'yes' : 'no',
            'shipping_charge'           => $shippingCharge,
            'tax_total'                 => $taxTotal,
            'tax_behavior'              => $taxBehavior,
            'shipping_tax'              => $shippingTax,
            'payment_method'            => $paymentMethod,
            'applied_coupons'           => $cart->getDiscountLines(),
            'billing_address'           => Arr::get($orderData, 'billing_address', []),
            'shipping_address'          => Arr::get($orderData, 'shipping_address', []),
            'cart_hash'                 => $cart->cart_hash,
            'is_locked'                 => $isLockedCart,
            'manual_discount_total'     => $cartCheckoutHelper->getManualDiscountAmount(),
            'ip_address'                => AddressHelper::getIpAddress(),
            'note'                      => Arr::get($orderData, 'others.order_notes', ''),
            'tax_id'                    => Arr::get($validatedData, 'billing_tax_id', 0),
        ]);

        $createdOrder = $checkoutProcessor->createDraftOrder($prevOrder);
        if (is_wp_error($createdOrder)) {
            wp_send_json([
                'status'  => 'failed',
                'message' => $createdOrder->get_error_message(),
                'data'    => $createdOrder->get_error_data()
            ], 423);
        }

        // prepare other data if any module needs to add data to the order data
        do_action('fluent_cart/checkout/prepare_other_data', [
            'cart'         => $cart,
            'order'        => $createdOrder,
            'prev_order'   => $prevOrder,
            'request_data' => $data,
            'validated_data' => $validatedData
        ]);

        static::finalizeOrder($createdOrder, [
            'billing_address'  => Arr::get($orderData, 'billing_address', []),
            'shipping_address' => Arr::get($orderData, 'shipping_address', []),
            'items'            => $cartCheckoutHelper->getItems(),
            'from_checkout'    => true,
            'prev_order'       => $prevOrder
        ]);

    }

    private static function getOrCreateCustomer(CartCheckoutHelper $cartCheckoutHelper, $orderData)
    {
        $customerEmail = static::getCustomerEmail($orderData['billing_address']);
        if (is_user_logged_in()) {
            $customerEmail = wp_get_current_user()->user_email;
            Arr::set($orderData, 'billing_address.email', $customerEmail);
        }
        $customer = $cartCheckoutHelper->getCustomer($customerEmail);
        return static::createCustomerWithAddress(
            $customer,
            $orderData,
            $orderData['billing_address'],
            $orderData['shipping_address']
        );
    }

    private static function prepareAddressData($data)
    {
        $shippingAddressId = Arr::get($data, 'shipping_address_id');
        $billingAddressId = Arr::get($data, 'billing_address_id');

        $shipToDifferent = Arr::get($data, 'ship_to_different', 'no');

        $datKeys = ['country', 'address_1', 'address_2', 'city', 'state', 'postcode', 'phone'];


        if ($billingAddressId) {
            $billingAddress = CustomerAddresses::query()
                ->where('id', $billingAddressId)
                ->where('type', 'billing')
                ->first();

            if ($billingAddress) {
                foreach ($datKeys as $key) {
                    $data['billing_' . $key] = $billingAddress->{$key};
                }
            }
        }

        if ($shippingAddressId && $shipToDifferent === 'yes') {
            $shippingAddress = CustomerAddresses::query()
                ->where('id', $shippingAddressId)
                ->where('type', 'shipping')
                ->first();

            if ($shippingAddress) {
                $data['shipping_full_name'] = $shippingAddress->name;
                foreach ($datKeys as $key) {
                    $data['shipping_' . $key] = $shippingAddress->{$key};
                }
            }
        } else if ($shipToDifferent !== 'yes') {
            // if not different shipping, copy billing to shipping
            foreach ($datKeys as $key) {
                $data['shipping_' . $key] = Arr::get($data, 'billing_' . $key, '');
            }
            $data['shipping_full_name'] = Arr::get($data, 'billing_full_name', '');
        }

        return $data;
    }

    private static function addLoggedUserData(array $data): array
    {
        if (is_user_logged_in()) {
            $data['billing_email'] = wp_get_current_user()->user_email;
            $userFullName = (CartCheckoutHelper::make())->getFullName();

            if (!empty($userFullName) && empty($data['billing_full_name'])) {
                $data['billing_full_name'] = $userFullName;
            }
        }
        return $data;
    }

    private static function finalizeOrder(Order $order, $args = [])
    {
        AddressHelper::insertOrderAddresses(
            $order->id,
            Arr::get($args, 'billing_address', []),
            Arr::get($args, 'shipping_address', [])
        );

        static::syncCustomerNames($order, $args);
        $cart = CartHelper::getCart();

        $utmData = [];
        if (!empty($cart) && is_array($cart->utm_data) && count($cart->utm_data) > 0) {
            $utmData = $cart->utm_data;
        }

        $requestUtmData = UtmHelper::getUtmDataOfRequest();
        $utmData = wp_parse_args($requestUtmData, $utmData);
        UtmHelper::addUtmToOrder($order->id, $utmData);

        $prevOrder = Arr::get($args, 'prev_order', null);

        (new OrderCreated($order, $prevOrder, $order->customer, $order->getLatestTransaction()))->dispatch();

        static::updateStock($order);

        // we don't have to validate the payment method again, as it's already validated in placeOrder method
        $gateway = App::gateway($order->payment_method);

        if (!$gateway) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Payment method not found!', 'fluent-cart'),
                'data'    => []
            ], 404);
        }

        $paymentInstance = new PaymentInstance($order);

        $data = $gateway->makePaymentFromPaymentInstance($paymentInstance);

        if (is_wp_error($data)) {
            wp_send_json([
                'status'  => 'failed',
                'message' => $data->get_error_message(),
                'data'    => $data->get_error_data()
            ], 422);
        }

        wp_send_json($data, 200);
    }

    private static function syncCustomerNames($order, $args)
    {
        $customer = $order->customer;

        if (empty($customer)) {
            return;
        }

        $firstName = Arr::get($args, 'billing_address.first_name');
        $lastName = Arr::get($args, 'billing_address.last_name');

        $customer->update([
            'first_name' => $firstName,
            'last_name'  => $lastName,
        ]);

        $user = get_user_by('email', $customer->email);

        if (empty($user)) {
            return;
        }

        if (is_user_logged_in() && $user->ID === get_current_user_id()) {
            update_user_meta($user->ID, 'first_name', $firstName);
            update_user_meta($user->ID, 'last_name', $lastName);
        }
    }

    public static function updateStock($order)
    {
        // pluck product ids to update product default variation as stock has changed
        $productIds = OrderService::pluckProductIds($order);
        if (!empty($productIds)) {
            (new StockChanged($productIds))->dispatch();
        }
    }

    public static function createCustomerWithAddress($customer, $orderData, $billingAddress, $shippingAddress)
    {
        if (empty($customer)) {
            $customer = static::createNewCustomer($orderData, $billingAddress, $shippingAddress);
        } else if ($customer instanceof Customer) {
            static::updateExistingCustomer($customer, $orderData, $billingAddress, $shippingAddress);
        }

        return $customer;
    }

    private static function createNewCustomer($orderData, &$billingAddress, $shippingAddress)
    {
        global $current_user;
        if ($current_user->ID) {
            $billingAddress['email'] = $current_user->user_email;
            $billingAddress['user_id'] = $current_user->ID;
        } else {
            static::handleUserCreation($orderData, $billingAddress);
        }

        $customer = CustomerResource::create($billingAddress);
        $customer = Arr::get($customer, 'data', null);
        $customerId = Arr::get($customer, 'id', null);
        static::createCustomerAddress($billingAddress, $customerId);
        static::createCustomerAddress($shippingAddress, $customerId);

        return $customer;
    }

    private static function updateExistingCustomer($customer, $orderData, $billingAddress, $shippingAddress)
    {
        if (empty($customer->user_id)) {
            $currentLoggedInUser = wp_get_current_user();
            if ($currentLoggedInUser && $currentLoggedInUser->user_email === $customer->email) {
                $userId = get_current_user_id();
                $customer->update(['user_id' => $userId]);
                $billingAddress['user_id'] = $userId;
            }
        }

        $customer->load(['billing_address', 'shipping_address']);

        if ($customer->billing_address->count() < 1) {
            static::createCustomerAddress($billingAddress, $customer->id);
        }
        if ($customer->shipping_address->count() < 1) {
            static::createCustomerAddress($shippingAddress, $customer->id);
        }

        static::handleUserCreation($orderData, $billingAddress, $customer);
    }

    private static function handleUserCreation($orderData, &$billingAddress, $customer = null)
    {
        $userEmail = Arr::get($billingAddress, 'email');
        $user = get_user_by('email', $userEmail);

        if ($user) {
            $billingAddress['user_id'] = $user->ID;
            if ($customer) {
                $customer->update(['user_id' => $user->ID]);
            }
        }
    }

    private static function getCustomerEmail($billingAddress)
    {
        return is_user_logged_in() ? wp_get_current_user()->user_email : $billingAddress['email'];
    }

    public static function shouldCreateUser($data, $billingAddress): bool
    {
        $accountCreationMod = (new StoreSettings())->get('user_account_creation_mode');
        $hasSubscription = (CartCheckoutHelper::make())->hasSubscription() === 'yes';
        if ($accountCreationMod === 'all' || $hasSubscription) {
            return true;
        }

        if (is_user_logged_in()) {
            return false;
        }

        $allow_create_account = Arr::get($data, 'others.allow_create_account') === 'yes';

        $userEmail = Arr::get($billingAddress, 'email');
        $user = get_user_by('email', $userEmail);

        return ($allow_create_account && $accountCreationMod === 'user_choice') || !empty($user);
    }

    private static function createCustomerAddress(array $address, $customerId)
    {
        CustomerAddressResource::create($address, ['id' => $customerId]);
    }

    /**
     * Check if a customer is logged in and has the given address type.
     *
     * @param string $type Address type to check ('billing' or 'shipping').
     * @return bool True if the customer is logged in and the address is available.
     */
    private static function hasCustomerWithAddress(string $type): bool
    {
        $addressType = $type . '_address';
        $currentCustomer = \FluentCart\Api\Resource\CustomerResource::getCurrentCustomer();

        return !empty($currentCustomer) && $currentCustomer->$addressType->count() === 1;
    }


    /**
     * Validate the data against the provided rules.
     * @return array
     */

    public static function billingRules($data = []): array
    {
        $baseRules = [
            'billing_full_name' => 'required|sanitizeText|maxLength:255',
            'billing_email'     => 'required|sanitizeText|email|maxLength:255',
            'order_notes'       => 'nullable|sanitizeTextArea|maxLength:200',
        ];

        return static::generateAddressRules('billing', $data, $baseRules, 'getBillingAddressFields');
    }

    public static function shippingIdRules(): array
    {
        return [
            'shipping_address' => 'exists:fct_customer_addresses,id',
        ];
    }

    public static function shippingRules($data = []): array
    {
        $baseRules = [
            'shipping_full_name' => 'required|sanitizeText|maxLength:255'
        ];

        return static::generateAddressRules('shipping', $data, $baseRules, 'getShippingAddressFields');

    }

    /**
     * Validate the data against the provided rules.
     *
     * @return false|\WP_Error|string
     */

    public static function validateData($data, Cart $cart, CheckoutService $cartCheckoutService, $prevOrder)
    {
        $shippingRequired = $cart->requireShipping();
        $isDifferentShipping = $shippingRequired && Arr::get($data, 'ship_to_different', 'no') === 'yes';
        $fulfillmentType = $shippingRequired ? 'physical' : 'digital';

        $billingValidations = array_filter(CheckoutFieldsSchema::getCheckoutFieldsRequirements('billing', $fulfillmentType, !$isDifferentShipping));


        if (!isset($billingValidations['country'])) {
            // get store country
            $data['billing_country'] = (new StoreSettings())->get('store_country');
        }

        $billingAddress = [];
        foreach ($billingValidations as $key => $billingValidation) {
            $billingAddress[$key] = Arr::get($data, 'billing_' . $key, '');
        }
        if (!isset($billingAddress['country'])) {
            // get store country
            $billingAddress['country'] = (new StoreSettings())->get('store_country');
        }

        $shippingAddress = [];
        $shippingValidations = [];
        if ($isDifferentShipping) {
            $shippingValidations = array_filter(CheckoutFieldsSchema::getCheckoutFieldsRequirements('shipping', 'physical'));
            foreach ($shippingValidations as $key => $shippingValidation) {
                $shippingAddress[$key] = Arr::get($data, 'shipping_' . $key, '');
            }
        }

        if (Arr::get($data,'ship_to_different', 'no') === 'yes') {
            if (!isset($data['shipping_country'])) {
                // get store country
                $data['shipping_country'] = (new StoreSettings())->get('store_country');
            }
            if (!isset($shippingAddress['country'])) {
                // get store country
                $shippingAddress['country'] = (new StoreSettings())->get('store_country');
            }

        }

        $errors = [];

        $agreeTermsRequired = CheckoutFieldsSchema::isTermsRequired();


        foreach ($billingValidations as $key => $rule) {
            $value = Arr::get($billingAddress, $key, '');
            $prefixedKey = 'billing_' . $key;
            $titledKey = Str::headline($key);

            if ($key === 'country') {
                $countries = LocalizationManager::getInstance()->countries();

                if ($rule === 'required' && empty($value)) {
                    if (!isset($errors[$key])) {
                        $errors[$prefixedKey] = [];
                    }
                    $errors[$prefixedKey]['required'] = sprintf(
                    /* translators: %s attribute name */
                        __('%s is required.', 'fluent-cart'), $titledKey);
                    continue;
                }

                if (!Arr::has($countries, $value)) {
                    if (!isset($errors[$key])) {
                        $errors[$prefixedKey] = [];
                    }
                    $errors[$prefixedKey]['invalid'] = sprintf(
                        /* translators: %s attribute name */
                        __('%s is invalid.', 'fluent-cart'), $titledKey);
                    continue;
                }
            }

            if ($key === 'state') {
                $country = Arr::get($billingAddress, 'country', '');
                //$countryInfo = LocalizationManager::getCountryInfo(null,$country);


                $states = LocalizationManager::getInstance()->statesOptions($country);

                if (empty($states)) {
                    continue;
                }

                if ($rule === 'required' && empty($value)) {
                    if (!isset($errors[$key])) {
                        $errors[$prefixedKey] = [];
                    }
                    $errors[$prefixedKey]['required'] = sprintf(
                    /* translators: %s attribute name */
                        __('%s is required.', 'fluent-cart'), $titledKey);
                    continue;
                }

                if (!in_array($value, array_column($states, 'value'))) {
                    if (!isset($errors[$key])) {
                        $errors[$prefixedKey] = [];
                    }
                    $errors[$prefixedKey]['invalid'] = sprintf(
                        /* translators: %s attribute name */
                        __('%s is invalid.', 'fluent-cart'), $titledKey);

                    continue;
                }
            }

            if ($rule === 'required' && empty($value)) {
                if (!isset($errors[$key])) {
                    $errors[$prefixedKey] = [];
                }
                $errors[$prefixedKey]['required'] = sprintf(
                    /* translators: %s attribute name */
                    __('%s is required.', 'fluent-cart'), $titledKey);
            }
        }

        foreach ($shippingValidations as $key => $rule) {
            $value = Arr::get($shippingAddress, $key, '');
            $prefixedKey = 'shipping_' . $key;
            $titledKey = Str::headline($key);


            if ($key === 'country') {
                $countries = LocalizationManager::getInstance()->countries();

                if ($rule === 'required' && empty($value)) {
                    if (!isset($errors[$key])) {
                        $errors[$prefixedKey] = [];
                    }
                    $errors[$prefixedKey]['required'] = sprintf(
                    /* translators: %s attribute name */
                        __('%s is required.', 'fluent-cart'), $titledKey);

                    continue;
                }

                if (!Arr::has($countries, $value)) {
                    if (!isset($errors[$key])) {
                        $errors[$prefixedKey] = [];
                    }
                    $errors[$prefixedKey]['invalid'] = sprintf(
                        /* translators: %s attribute name */
                        __('%s is invalid.', 'fluent-cart'), $titledKey);
                    continue;
                }
            }

            if ($key === 'state') {
                $country = Arr::get($shippingAddress, 'country', '');
                //$countryInfo = LocalizationManager::getCountryInfo(null,$country);

                $states = LocalizationManager::getInstance()->statesOptions($country);

                if (empty($states)) {
                    continue;
                }


                if ($rule === 'required' && empty($value)) {
                    if (!isset($errors[$key])) {
                        $errors[$prefixedKey] = [];
                    }
                    $errors[$prefixedKey]['required'] = sprintf(
                    /* translators: %s attribute name */
                        __('%s is required.', 'fluent-cart'), $titledKey);

                    continue;
                }

                if (!in_array($value, array_column($states, 'value'))) {
                    if (!isset($errors[$key])) {
                        $errors[$prefixedKey] = [];
                    }
                    $errors[$prefixedKey]['invalid'] = sprintf(
                        /* translators: %s attribute name */
                        __('%s is invalid.', 'fluent-cart'), $titledKey);

                    continue;
                }
            }

            if ($rule === 'required' && empty($value)) {
                if (!isset($errors[$key])) {
                    $errors[$prefixedKey] = [];
                }
                $errors[$prefixedKey]['required'] = sprintf(
                    /* translators: %s attribute name */
                    __('%s is required.', 'fluent-cart'), $titledKey);
            }
        }

        $basicInfoFields = (CheckoutFieldsSchema::getNameEmailFieldsSchema())['fields'];

        foreach ($basicInfoFields as $field) {
            $fieldName = (string)Arr::get($field, 'name', '');
            $isRequired = Arr::get($field, 'required', 'no') === 'yes';
            if ($fieldName && $isRequired) {
                $value = Arr::get($data, $fieldName, '');
                if (empty($value)) {
                    Arr::set($errors, $fieldName . '.required', sprintf(
                        /* translators: %s attribute name */
                        __('%s is required.', 'fluent-cart'), Arr::get($field, 'aria-label')));
                }
            }
        }

        if (empty($data['agree_terms']) && $agreeTermsRequired) {
            $errors['agree_terms']['required'] = __('You must agree to the terms and conditions.', 'fluent-cart');
        }

        if (empty($data['billing_email']) || !is_email($data['billing_email'])) {
            $errors['billing_email']['invalid'] = __('Email must be a valid email address.', 'fluent-cart');
        }


        if (empty($data['billing_full_name'])) {
            $errors['billing_full_name']['required'] = __('Full name is required.', 'fluent-cart');
        }

        if ($cart->requireShipping()) {
            if (!empty($data['fc_selected_shipping_method'])) {
                $selectedMethod = $data['fc_selected_shipping_method'];
                $shippingCountry = Arr::get($data, 'billing_country', '');
                $shippingState = Arr::get($data, 'billing_state', '');
                $shipToDifferent = Arr::get($data, 'ship_to_different', 'no') === 'yes';

                if ($shipToDifferent) {
                    $shippingCountry = Arr::get($data, 'shipping_country', '');
                    $shippingState = Arr::get($data, 'shipping_state', '');
                }

                $availableShippingMethods = AddressHelper::getShippingMethods($shippingCountry, $shippingState);


                if (empty($availableShippingMethods) || is_wp_error($availableShippingMethods)) {
                    $errors['shipping_method']['unavailable'] = __('We dont ship to this address. Please select a different address.', 'fluent-cart');
                } else {
                    $found = false;
                    foreach ($availableShippingMethods as $shippingMethod) {
                        if ($shippingMethod->id == $selectedMethod) {
                            $found = true;
                            break;
                        }
                    }

                    if (!$found) {
                        $errors['shipping_method']['invalid'] = __('The selected shipping method is not available.', 'fluent-cart');
                    }
                }


            } else {
                $errors['shipping_method']['required'] = __('You must select a shipping method.', 'fluent-cart');
            }
        }

        $errors = apply_filters('fluent_cart/checkout/validate_data', $errors, [
            'data' => $data,
            'cart' => $cart
        ]);

        if (count($errors) > 0) {
            return new \Wp_Error('validation_error', 'Validation error', $errors);
        }

        return $data;
    }

    public static function validateShippingMethod(array $data, CheckoutService $cartCheckoutService): bool
    {

        if ($cartCheckoutService->isAllDigital()) {
            return true;
        }

        $shipping_country = Arr::get($data, 'shipping_country');
        $shipping_state = Arr::get($data, 'shipping_state');


        $methods = ShippingMethod::query()->applicableToCountry($shipping_country, $shipping_state)->get()->keyBy('id');
        if ($methods->count() === 0 || \FluentCart\App\App::isDevMode()) {
            return true;
        }

        $shipping_method = Arr::get($data, 'fc_selected_shipping_method');

        if (empty($shipping_method)) {
            return false;
        }


        $exist = $methods->has($shipping_method);
        if (!$exist) {
            return false;
        }

        return true;
    }


    public static function messages(): array
    {
        return [
            'billing_full_name.required'  => esc_html__('Full name field is required.', 'fluent-cart'),
            'billing_email.required'      => esc_html__('Email field is required.', 'fluent-cart'),
            'billing_email.email'         => esc_html__('Email must be a valid email address.', 'fluent-cart'),
            'billing_address.required'    => esc_html__('Address field is required.', 'fluent-cart'),
            'billing_country.required'    => esc_html__('Country field is required.', 'fluent-cart'),
            'billing_address_1.required'  => esc_html__('Address field is required.', 'fluent-cart'),
            'billing_city.required'       => esc_html__('City field is required.', 'fluent-cart'),
            'billing_postcode.required'   => esc_html__('Postcode field is required.', 'fluent-cart'),
            'shipping_full_name.required' => esc_html__('Full name field is required.', 'fluent-cart'),
            // 'shipping_email.required' => esc_html__('Email field is required.', 'fluent-cart'),
            // 'shipping_email.email' => esc_html__('Email must be a valid email address.', 'fluent-cart'),
            'shipping_address.required'   => esc_html__('Address field is required.', 'fluent-cart'),
            'shipping_city.required'      => esc_html__('City field is required.', 'fluent-cart'),
            'shipping_postcode.required'  => esc_html__('Postcode field is required.', 'fluent-cart'),
        ];
    }

    private static function generateAddressRules($type, $data, $baseRules, $fieldGetter): array
    {
        $hasAddress = static::hasCustomerWithAddress($type);
        $rules = App::localization()->getValidationRule($data, $type);

        if ($hasAddress) {
            $baseRules["{$type}_address"] = 'required|numeric';
            return $baseRules;
        }

        $cartCheckoutHelper = CartCheckoutHelper::make();
        $fields = $cartCheckoutHelper->{$fieldGetter}();
        $addressFields = Arr::get($fields, 'address_section.schema', []);
        $addressFields = Arr::wrap($addressFields);

        $validFields = array_keys($addressFields);

        // Add prefix to each field (billing_ or shipping_)
        $validFields = array_map(function ($field) use ($type) {
            return $type . '_' . $field;
        }, $validFields);

        // Filter only relevant rules
        $filteredRules = array_filter($rules, function ($key) use ($validFields) {
            return in_array($key, $validFields);
        }, ARRAY_FILTER_USE_KEY);

        return $baseRules + $filteredRules;
    }
}
