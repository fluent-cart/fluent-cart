<?php

namespace FluentCart\App\Http\Controllers\FrontendControllers;

use FluentCart\Api\Resource\FrontendResource\CustomerAddressResource;
use FluentCart\Api\Resource\FrontendResource\CustomerResource;
use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\CustomerHelper;
use FluentCart\App\Hooks\Cart\WebCheckoutHandler;
use FluentCart\App\Http\Controllers\Controller;
use FluentCart\App\Http\Requests\CustomerRequest;
use FluentCart\App\Http\Requests\FrontendRequests\CustomerAddressRequest;
use FluentCart\App\Models\CustomerAddresses;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\App\Services\Renderer\AddressSelectRenderer;
use FluentCart\App\Services\Renderer\CheckoutFieldsSchema;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\Framework\Support\Str;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        return ['customers' => CustomerResource::get($request->all())];
    }

    public function store(CustomerRequest $request)
    {
        $data = $request->getSafe($request->sanitize());
        $isCreated = CustomerResource::create($data);

        if (is_wp_error($isCreated)) {
            return $isCreated;
        }
        return $this->response->sendSuccess($isCreated);
    }

    public function updateDetails(CustomerRequest $request, $customerId)
    {
        $customer = \FluentCart\Api\Resource\CustomerResource::getCurrentCustomer();
        if (empty($customer) || $customer->id != $customerId) {
            return $this->sendError([
                'message' => __('You are not authorized to update this customer', 'fluent-cart')
            ]);
        }
        $data = $request->getSafe($request->sanitize());
        $isUpdated = CustomerResource::update($data, $customerId);
        if (is_wp_error($isUpdated)) {
            return $isUpdated;
        }
        return $this->response->sendSuccess($isUpdated);
    }

    public function getDetails(Request $request, $customerId)
    {
        $customer = \FluentCart\Api\Resource\CustomerResource::getCurrentCustomer();
        if (empty($customer) || $customer->id != $customerId) {
            return $this->sendError([
                'message' => __('You are not authorized to view this customer', 'fluent-cart')
            ]);
        }
        return CustomerResource::find($customerId, ['with' => $request->get('with', [])]);
    }

    public function getAddress(Request $request, $customerId)
    {
        return CustomerAddressResource::get([
            'customer_id' => $customerId,
            'type'        => $request->type
        ]);
    }

    public function updateAddressSelect(Request $request, $customerAddressId)
    {
        $address = CustomerAddressResource::find($customerAddressId, ['with' => $request->get('with', [])]);

        if (!$address) {
            return $this->sendError([
                'message' => __('Address not found', 'fluent-cart')
            ]);
        }

        // Verify address belongs to current customer before any cart mutation
        $customerId = Arr::get($address, 'address.customer_id');
        $customer = \FluentCart\Api\Resource\CustomerResource::getCurrentCustomer();
        if (empty($customer) || $customer->id != $customerId) {
            return $this->sendError([
                'message' => __('You are not authorized to view this address', 'fluent-cart')
            ]);
        }

        //update address into cart
        $addressId = Arr::get($address, 'address.id');
        $country = Arr::get($address, 'address.country');
        $state = Arr::get($address, 'address.state');
        $type = Arr::get($address, 'address.type', 'billing');
        $cart = CartHelper::getCart($request->getSafe('fct_cart_hash', 'sanitize_text_field'));

        $checkoutData = Arr::wrap($cart->checkout_data);
        Arr::set($checkoutData, 'form_data.' . $type . '_address_id', $addressId);
        Arr::set($checkoutData, 'form_data.' . $type . '_country', $country);
        Arr::set($checkoutData, 'form_data.' . $type . '_state', $state);

        if ($type === 'billing' &&  Arr::get($checkoutData, 'form_data.ship_to_different', 'no') === 'no') {
            Arr::set($checkoutData, 'form_data.shipping_address_id', $addressId);
            Arr::set($checkoutData, 'form_data.shipping_country', $country);
            Arr::set($checkoutData, 'form_data.shipping_state', $state);
        }

        $cart->checkout_data = $checkoutData;
        $cart->save();

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
        $addressString = implode(', ', $addressParts);

        do_action('fluent_cart/views/checkout_page_form_address_info_wrapper', [
            'name'    => Arr::get($address, 'address.name'),
            'phone'   => Arr::get($address, 'address.phone'),
            'label'   => Arr::get($address, 'address.label'),
            'address' => $addressString,
        ]);
        $htmlOutput = ob_get_clean();

        $result =  (new WebCheckoutHandler())->handleGetCheckoutSummaryViewAjax();

        return $this->response->sendSuccess([
            'message' => __('Address Attached', 'fluent-cart'),
            'data'    => $htmlOutput,
            'fragments' => $result['fragments']
        ]);
    }


    public function createAddress(Request $request) //CustomerAddressRequest
    {
        $customer = \FluentCart\Api\Resource\CustomerResource::getCurrentCustomer();

        if (empty($customer)) {
            return $this->sendError([
                'message' => __('You don\'t have any associated account', 'fluent-cart')
            ]);
        }

        $customerId = $customer->id;
        $data = $request->all();//$request->getSafe($request->sanitize());

        $validatedData = $this->validateAddressData($data);
        if (is_wp_error($validatedData)) {
            wp_send_json([
                'status' => 'failed',
                'errors' => $validatedData->get_error_data(),
            ], 422);
        }


        // Sanitize all fields with special handling for emails
        $sanitized = [];

        foreach ($validatedData as $key => $value) {
            if (is_array($value)) {
                // Recursively sanitize nested arrays
                $sanitized[$key] = array_map('sanitize_text_field', $value);
                continue;
            }

            // If key contains "email", sanitize as email
            if (stripos($key, 'email') !== false && !empty($value)) {
                $sanitized[$key] = sanitize_email($value);
                continue;
            }

            // Default text sanitization
            $sanitized[$key] = sanitize_text_field($value);
        }

        $data = $sanitized;


        $data = self::formattedAddress($data);

        // Validate label length
        if (!empty($data['label']) && strlen($data['label']) > 15) {
            return $this->sendError([
                'message' => __('Label must not exceed 15 characters.', 'fluent-cart')
            ]);
        }

        $data['status'] = 'active';
        $isCreated = CustomerAddressResource::create($data, ['id' => $customerId]);

        if (is_wp_error($isCreated)) {
            return $isCreated;
        }


        $isCreated = $isCreated['data'];

        $cart = CartHelper::getCart();
        $requiredShipping = $cart->requireShipping();
        $type = Arr::get($data, 'type', 'billing');
        $config = [
            'type'          => $type,
            'product_type'  => $requiredShipping ? 'physical' : 'digital',
            'with_shipping' => $requiredShipping
        ];

        $customer = \FluentCart\Api\Resource\CustomerResource::getCurrentCustomer();
        $addresses = AddressHelper::getCustomerValidatedAddresses($config, $customer);
        $address = AddressHelper::getPrimaryAddress($addresses, $config, $customer, $type);
        $requirementsFields = CheckoutFieldsSchema::getCheckoutFieldsRequirements(
            $type,
            Arr::get($config, 'product_type'),
            Arr::get($config, 'with_shipping')
        );

        ob_start();
        (new AddressSelectRenderer(
            $addresses,
            $address,
            $requirementsFields,
            $type
        ))->renderAddressSelector();
        $selectors = ob_get_clean();


        return $this->response->sendSuccess([
            'message' => __('Customer address created successfully!', 'fluent-cart'),
            'fragment' => [
                [
                    'selector' => '[data-fluent-cart-checkout-page-form-address-modal-address-selector-button-wrapper]',
                    'content'  => $selectors,
                    'type'     => 'replace'
                ]
            ]
        ]);
    }

    private function validateAddressData($data)
    {
        $type = sanitize_text_field(Arr::get($data, 'type'));
        $fulfillmentType = sanitize_text_field(Arr::get($data, 'product_type'));

        // Address creation validates against its own type's rules only — no shipping merge
        $validations = array_filter(CheckoutFieldsSchema::getCheckoutFieldsRequirements($type, $fulfillmentType, false));

        // Name fields are validated via basic_info, not address sections
        unset($validations['full_name'], $validations['first_name'], $validations['last_name'], $validations['company_name']);

        $address = [];
        foreach ($validations as $key => $validation) {
            $address[$key] = Arr::get($data, $type . '_' . $key, '');
        }

        $country = $this->resolveCountryForValidation($address, $type);
        $errors = [];

        foreach ($validations as $key => $rule) {
            $value = Arr::get($address, $key, '');
            $prefixedKey = $type . '_' . $key;
            $titledKey = Str::headline($key);

            $fieldErrors = $this->validateAddressField($key, $value, $rule, $titledKey, $country);
            if (!empty($fieldErrors)) {
                $errors[$prefixedKey] = $fieldErrors;
            }
        }

        if (!empty($errors)) {
            return new \Wp_Error('validation_error', __('Validation error', 'fluent-cart'), $errors);
        }

        return $data;
    }

    private function resolveCountryForValidation(array $address, string $addressType): string
    {
        $country = Arr::get($address, 'country', '');
        if (!empty($country)) {
            return $country;
        }

        // Fall back to store country only when the country field is disabled by admin
        $fieldSettings = CheckoutFieldsSchema::getFieldsSettings();
        $countryEnabled = Arr::get($fieldSettings, $addressType . '_address.country.enabled', 'no') === 'yes';

        if (!$countryEnabled) {
            return (new \FluentCart\Api\StoreSettings())->get('store_country') ?: '';
        }

        return '';
    }

    private function validateAddressField(string $field, $value, string $rule, string $label, string $country): array
    {
        $localization = LocalizationManager::getInstance();

        switch ($field) {
            case 'country':
                return $this->validateCountryField($value, $rule, $label, $localization);
            case 'state':
                return $this->validateStateField($value, $rule, $label, $country, $localization);
            case 'postcode':
                if ($rule === 'required' && empty($value)) {
                    return ['required' => sprintf(__('%s is required.', 'fluent-cart'), $label)];
                }
                if (!empty($value) && !empty($country) && $localization->postcode->isValid($value, $country) === false) {
                    return ['invalid' => sprintf(__('%s is invalid.', 'fluent-cart'), $label)];
                }
                return [];
            default:
                if ($rule === 'required' && empty($value)) {
                    return ['required' => sprintf(__('%s is required.', 'fluent-cart'), $label)];
                }
                return [];
        }
    }

    private function validateCountryField($value, string $rule, string $label, LocalizationManager $localization): array
    {
        if ($rule === 'required' && empty($value)) {
            return ['required' => sprintf(__('%s is required.', 'fluent-cart'), $label)];
        }

        if (!empty($value) && !Arr::has($localization->countries(), $value)) {
            return ['invalid' => sprintf(__('%s is invalid.', 'fluent-cart'), $label)];
        }

        return [];
    }

    private function validateStateField($value, string $rule, string $label, string $country, LocalizationManager $localization): array
    {
        if (empty($country)) {
            return [];
        }

        $states = $localization->statesOptions($country);
        if (empty($states)) {
            return [];
        }

        $stateValues = array_column($states, 'value');

        if ($rule === 'required' && empty($value)) {
            return ['required' => sprintf(__('%s is required.', 'fluent-cart'), $label)];
        }

        if (!empty($value) && !in_array($value, $stateValues)) {
            return ['invalid' => sprintf(__('%s is invalid.', 'fluent-cart'), $label)];
        }

        return [];
    }

    public function updateAddress(CustomerAddressRequest $request)
    {
        $data = $request->getSafe($request->sanitize());
        $data = self::formattedAddress($data);
        $id = Arr::get($request->get('address'), 'id');

        $address = CustomerAddresses::query()->findOrFail($id);

        $customer = \FluentCart\Api\Resource\CustomerResource::getCurrentCustomer();
        if (empty($customer) || $customer->id != $address->customer_id) {
            $this->sendError([
                'message' => __('You are not authorized to update this address', 'fluent-cart')
            ]);
        }

        if ($address->update($data)) {
            return $this->response->sendSuccess([
                'message' => __('Address updated successfully', 'fluent-cart')
            ]);
        }

        return $this->sendError([
            'message' => __('Failed to update address', 'fluent-cart')
        ]);
    }

    public function removeAddress(Request $request)
    {

        $id = Arr::get($request->address, 'id', false);

        $address = CustomerAddresses::query()->findOrFail($id);

        $customer = \FluentCart\Api\Resource\CustomerResource::getCurrentCustomer();
        if (empty($customer) || $customer->id != $address->customer_id) {
            $this->sendError([
                'message' => __('You are not authorized to delete this address', 'fluent-cart')
            ]);
        }

        if ($address->delete()) {
            return $this->response->sendSuccess([
                'message' => __('Address deleted successfully', 'fluent-cart')
            ]);
        }

        return $this->sendError([
            'message' => __('Failed to delete address', 'fluent-cart')
        ]);
    }

    public function setAddressPrimary(Request $request, $customerId)
    {
        $customer = \FluentCart\Api\Resource\CustomerResource::getCurrentCustomer();
        if (empty($customer) || $customer->id != $customerId) {
            return $this->sendError([
                'message' => __('You are not authorized to update this address', 'fluent-cart')
            ]);
        }

        $isUpdated = CustomerAddressResource::makePrimary($customerId, $request->all());

        if (is_wp_error($isUpdated)) {
            return $isUpdated;
        }
        return $this->response->sendSuccess($isUpdated);
    }

    public function getCustomerOrders(Request $request, $customerId): array
    {

        $customer = \FluentCart\Api\Resource\CustomerResource::getCurrentCustomer();
        if (empty($customer) || $customer->id != $customerId) {
            return [
                'orders' => []
            ];
        }
        return [
            'orders' => CustomerResource::getOrders($request->all(), $customerId)
        ];
    }

    private static function formattedAddress(array $data = []): array
    {
        $address = [];

        foreach ($data as $key => $value) {
            if (strpos($key, "billing_") === 0) {
                $newKey = str_replace("billing_", "", $key);
                $address[$newKey] = $value;
            }
            if (strpos($key, "shipping_") === 0) {
                $newKey = str_replace("shipping_", "", $key);
                $address[$newKey] = $value;
            }
        }

        $address['type'] = Arr::get($data, 'type');

        return $address;
    }

}
