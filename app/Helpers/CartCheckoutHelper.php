<?php

namespace FluentCart\App\Helpers;

use FluentCart\Api\Resource\CustomerResource;
use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\Api\Resource\FrontendResource\CustomerAddressResource;
use FluentCart\Api\Resource\ProductResource;
use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Customer;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Services\CheckoutService;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\App\Services\OrderService;
use FluentCart\Framework\Database\Orm\Builder;
use FluentCart\Framework\Database\Orm\Model;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\ArrayableInterface;
use FluentCart\Framework\Support\Collection;

class CartCheckoutHelper implements ArrayableInterface
{
    private $cart;

    private ?array $utmData = [];

    private ?array $checkoutData = [];

    protected ?StoreSettings $storeSettings = null;

    static $instance = false;

    protected array $couponDiscountData = [];


    public bool $disableCoupon = false;

    public function __construct($disableCoupon = false)
    {
        $this->disableCoupon = $disableCoupon;
        $this->init();
    }

    public static function make($disableCoupon = false): ?CartCheckoutHelper
    {
        static $instance = null;

        if (!$instance) {
            $instance = new self($disableCoupon);
        }

        return $instance;
    }


    public function init()
    {

        $this->storeSettings = new StoreSettings();
        $cart = CartHelper::getCart();

        if (!$cart) {
            return;
        }

        $this->cart = $cart;

        $this->validateCartItems();

        if (
            Arr::get($this->cart->checkout_data, 'disable_coupons') == 'yes' ||
            $this->disableCoupon
        ) {
            return;
        }

        $this->applyCoupon();
    }

    public function hasSubscription(): string
    {
        $currentCart = $this->getCart();
        if (!empty($currentCart->cart_data)) {
            foreach ($currentCart->cart_data as $key => $value) {
                if (Arr::get($value, 'other_info.payment_type') === 'subscription') {
                    return 'yes';
                };
            }
        }
        return 'no';
    }

    public function isZeroPayment(): bool
    {
        return $this->getItemsAmountTotal(false, false) <= 0 && $this->hasSubscription() !== 'yes';
    }

    protected function applyCoupon()
    {
        $this->couponDiscountData = $this->cart->getDiscountLines();
    }

    public function getCouponDiscountData(): array
    {
        return $this->cart->getDiscountLines();
    }

    public function getAppliedCouponCodes(): array
    {
        return $this->cart->coupons ?? [];
    }

    public function setCouponDiscountData(array $data)
    {
        $this->couponDiscountData = $data;
    }

    public function validateCartItems()
    {

        $variationIds = (new Collection($this->getItems()))->pluck('id')->toArray();
        $variations = ProductVariation::query()
            ->with(['product.detail', 'media'])
            ->whereIn('id', $variationIds)->get();

        $newCartItems = [];

//        foreach ($variations as $variation) {
//            if (isset($this->cart->cart_data[$variation->id])) {
//                $quantity = Arr::get($this->cart->cart_data[$variation->id], 'quantity');
//                $item = CartHelper::generateCartItemFromVariation($variation, $quantity);
//                $newCartItems[$variation->id] = $item;
//            }
//
//        }
//
//        $this->cart->cart_data = $newCartItems;
//
//        $this->cart->update();

        $this->prepareCartData($this->cart);

    }

    public function getCountryList(): array
    {
        return Helper::getCountryList();
    }

    /**
     * @return StoreSettings
     */
    public function getStoreSettings(): StoreSettings
    {
        return $this->storeSettings;
    }

    public function setCart(Cart $cart)
    {
        $this->cart = $cart;
    }

    public function getCart()
    {
        return $this->cart;
    }

    protected function prepareCartData(Cart $cart)
    {
        $this->utmData = $cart->utm_data;
        if ($checkoutData = $this->cart->checkout_data) {
            $this->checkoutData = $checkoutData;
        }
    }


    /**
     * @param ProductVariation $variation
     *
     * @return Cart
     */
    public static function getCartFromVariation(ProductVariation $variation): Cart
    {
        return CartHelper::generateCartFromVariation($variation);
    }


    public function getSettings($setting = '')
    {
        if (!$this->storeSettings) {
            $this->storeSettings = new StoreSettings();
        }

        if ($setting) {
            return $this->storeSettings->get($setting);
        }

        return $this->storeSettings->get();
    }

    public function getItems(): array
    {
        if (empty($this->cart)) {
            return [];
        }
        return $this->cart->cart_data ?? [];
    }

    public function getUtmData($data): array
    {
        $utmKeys = [
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

        $utmData = [];
        foreach ($utmKeys as $key) {
            $value = Arr::get($data, $key);
            if ($value) {
                $utmData[$key] = sanitize_text_field($value);
            }
        }
        return array_merge($utmData, $this->utmData ?? []);
    }

    public function isEmailLocked()
    {
        // To-do disabled this for now
        return false;
        //        return !!$this->getUser();
    }

    public function getUserId()
    {
        global $current_user;
        $userId = $current_user->ID ?? null;

        if (!$userId && $this->cart && $this->cart->user_id) {
            $userId = $this->cart->user_id;
        }

        return $userId;
    }

    public function getUser()
    {
        static $user;

        if ($user) {
            return $user;
        }

        $userId = $this->getUserId();

        if (!$userId) {
            return false;
        }

        $user = get_user_by('ID', $userId);

        if ($user) {
            return $user;
        }
    }

    public function getCustomer($email = '')
    {

        if (empty($email)) {
            return CustomerResource::getCurrentCustomer();
        }
        $customer = Customer::query();
        $customer = $customer->where('email', $email);
        $customer = $customer->with('billing_address')
            ->with('shipping_address')
            ->first();


        if (!$customer && $this->cart && $this->cart->customer) {
            $customer = $this->cart->customer;
        }

        return $customer ?? false;
    }

    public function requireShippingAddress(): bool
    {
        $shippableTypes = ['physical'];

        foreach ($this->getItems() as $item) {
            if (in_array($item['fulfillment_type'], $shippableTypes)) {
                return true;
            }
        }
        return false;
    }


    public function getEmail()
    {
        $user = $this->getUser();
        if ($user) {
            return $user->user_email;
        }

        if ($customer = $this->getCustomer()) {
            return $customer->email;
        }

        if ($this->cart) {
            return $this->cart->email;
        }

    }

    public function getFullName()
    {
        return trim($this->getFirstName() . ' ' . $this->getLastName());
    }

    public function getFirstName()
    {
        if ($this->cart && $this->cart->first_name) {
            return $this->cart->first_name;
        }

        $user = $this->getUser();
        if (!empty($user->first_name)) {
            return $user->first_name;
        }

        if ($customer = $this->getCustomer()) {
            if ($customer->first_name) {
                return $customer->first_name;
            }
        }

        return '';
    }


    /**
     * @param $productId
     *
     * @return array|null
     */
    public function getOriginalProduct($productId): ?array
    {
        return ProductResource::find($productId);
    }

    /**
     * @param $itemId
     *
     * @return Builder|Builder[]|\FluentCart\Framework\Database\Orm\Collection|Model|null
     */
    public function getOriginalItem($itemId)
    {
        return ProductVariation::query()->find($itemId);
    }

    public function getLastName()
    {
        if ($this->cart && $this->cart->last_name) {
            return $this->cart->last_name;
        }

        $user = $this->getUser();
        if ($user && $user->last_name) {
            return $user->last_name;
        }

        if ($customer = $this->getCustomer()) {
            if ($customer->last_name) {
                return $customer->last_name;
            }
        }

        return '';
    }

    public function getBillingAddress($withNameEmail = false)
    {
        $checkoutBilling = Arr::get($this->checkoutData, 'billing', []);

        if ($customer = $this->getCustomer()) {
            $customerBilling = CustomerAddressResource::find($customer->id, ['type' => 'billing']);
            $checkoutBilling = wp_parse_args($checkoutBilling, $customerBilling);
        }

        if ($withNameEmail) {
            $checkoutBilling['first_name'] = $this->getFirstName();
            $checkoutBilling['last_name'] = $this->getLastName();
            $checkoutBilling['email'] = $this->getEmail();
        }

        return $checkoutBilling;
    }

    public function getShippingAddress()
    {
        $checkoutShipping = Arr::get($this->checkoutData, 'shipping', []);

        //todo : will add dynamic shipping from saved item later

        // $customerAddress = new CustomerAddress();
        // if ($customer = $this->getCustomer()) {
        //     $primaryShipping = $customerAddress->getAddress($customer->id, 'shipping');
        //     $checkoutShipping = wp_parse_args($checkoutShipping, $primaryShipping);
        // }
        return $checkoutShipping;
    }

    public function getAddressBaseFields($type = 'billing'): array
    {
        $getCart = CartHelper::getCart();

        $selectedCountry = Arr::get($getCart, 'checkout_data.form_data.' . $type . '_country');

        if (empty($selectedCountry)) {
            $HTTP_CF_IP_COUNTRY = Arr::get( App::request()->server(), 'HTTP_CF_IPCOUNTRY');
            $selectedCountry = $HTTP_CF_IP_COUNTRY ?? $selectedCountry;
        }

        $states = [];
        if (empty(Arr::get($getCart, 'checkout_data.form_data.' . $type . '_state'))) {
            $states = [
                [
                    'name'  => __('Select an option', 'fluent-cart'),
                    'value' => ''
                ]
            ];
        }

        $addressLocale = [];
        if (!empty($selectedCountry)) {
            $states = array_merge($states, LocalizationManager::getInstance()->statesOptions($selectedCountry));
            $addressLocale = LocalizationManager::getInstance()->addressLocales($selectedCountry);
        }

        $stateLabel = Arr::get($addressLocale, 'state.label', __('State', 'fluent-cart'));
        $countries = [
            [
                'name'  => __('Select a Country', 'fluent-cart'),
                'value' => ''
            ]
        ];
        $countries = array_merge($countries, $this->getCountryList());


        if (empty($states) && !Arr::get($addressLocale, 'state.hidden')) {
            $stateInput = [
                'type'         => 'text',
                'data-type'    => 'text',
                'label'        => '',
                'required'     => 'yes',
                'autocomplete' => 'address-level2',
                'placeholder'  => $stateLabel,
                'value'        => '',
            ];
        } else {
            $stateInput = [
                'type'         => 'select',
                'data-type'    => 'select',
                'label'        => '',
                'options'      => $states,
                'required'     => 'yes',
                'autocomplete' => 'address-level2',
                'placeholder'  => $stateLabel,
                'value'        => Arr::get($getCart, 'checkout_data.form_data.' . $type . '_state') ?? '',
            ];
        }

        return [
            'address_section' => [
                'type'   => 'section',
                'title'  => __('Address', 'fluent-cart'),
                'schema' => [
                    'label'     => [
                        'type'         => 'text',
                        'data-type'    => 'text',
                        'label'        => '',
                        'required'     => 'yes',
                        'autocomplete' => 'label',
                        'value'        => '',
                        'maxlength'    => 15,
                        'placeholder'  => esc_attr__('e.g Home, Office', 'fluent-cart'),
                    ],
                    'name'      => [
                        'type'         => 'text',
                        'data-type'    => 'text',
                        'label'        => '',
                        'required'     => 'no',
                        'autocomplete' => 'name',
                        'placeholder'  => esc_attr__('Name', 'fluent-cart'),
                    ],
                    'country'   => [
                        'type'         => 'select',
                        'options'      => $countries,
                        'data-type'    => 'text',
                        'label'        => '',
                        'required'     => 'yes',
                        'autocomplete' => 'country',
                        'placeholder'  => esc_attr__('Country / Region', 'fluent-cart'),
                        'value'        => $selectedCountry,
                    ],
                    'address_1' => [
                        'type'         => 'text',
                        'data-type'    => 'text',
                        'label'        => '',
                        /* translators: use local order of street name and house number. */
                        'placeholder'  => esc_attr__('Street Address', 'fluent-cart'),
                        'required'     => 'yes',
                        'autocomplete' => 'address-line1',
                        'value'        => Arr::get($getCart, 'checkout_data.form_data.' . $type . '_address_1'),
                    ],
                    'address_2' => [
                        'type'         => 'text',
                        'data-type'    => 'text',
                        'label'        => '',
                        'label_class'  => array(''),
                        'placeholder'  => esc_attr__('Apt, suite, unit', 'fluent-cart'),
                        'autocomplete' => 'address-line2',
                        'value'        => Arr::get($getCart, 'checkout_data.form_data.' . $type . '_address_2', ''),
                    ],
                    'state'     => $stateInput,
                    'city_zip'  => [
                        'type'   => 'section',
                        'schema' => [
                            'city'     => [
                                'type'         => 'text',
                                'data-type'    => 'text',
                                'label'        => '',
                                'required'     => 'yes',
                                'autocomplete' => 'address-level2',
                                'placeholder'  => esc_attr__('Town / City', 'fluent-cart'),
                                'value'        => Arr::get($getCart, 'checkout_data.form_data.' . $type . '_city'),
                            ],
                            'postcode' => [
                                'type'         => 'text',
                                'data-type'    => 'text',
                                'label'        => '',
                                'required'     => 'yes',
                                'validate'     => array('postcode'),
                                'autocomplete' => 'postal-code',
                                'placeholder'  => esc_attr__('Postcode / ZIP', 'fluent-cart'),
                                'value'        => Arr::get($getCart, 'checkout_data.form_data.' . $type . '_postcode'),
                            ],
                        ]
                    ]
                ]
            ]
        ];
    }

    public function getAddressFields($type = 'billing')
    {
        $addresses = [];
        $customer = CustomerResource::getCurrentCustomer();
        $getCart = CartHelper::getCart();

        if (!empty($customer)) {
            $customerId = $customer->id;
            $addresses = CustomerAddressResource::get([
                'type'        => $type,
                'customer_id' => $customerId,
                'status'      => 'active'
            ]);
        }

        $requiredOnLoggedOut = $this->getAddressBaseFields($type);
        $fieldsToUnset = ['name', 'address_2'];
        foreach ($fieldsToUnset as $field) {
            unset($requiredOnLoggedOut['address_section']['schema'][$field]);
        }

        $requireAdditionalAddress = (new StoreSettings())->get('additional_address_field');

        if ($requireAdditionalAddress == 'yes') {

            $schema = [
                'company_name' => [
                    'id'           => 'company_name',
                    'type'         => 'text',
                    'data-type'    => 'text',
                    'label'        => '',
                    'autocomplete' => 'organization',
                    'placeholder'  => esc_attr__('Company Name', 'fluent-cart'),
                    'value'        => Arr::get($getCart, 'checkout_data.form_data.' . $type . '_company_name'),
                ],
                'phone'        => [
                    'id'          => 'phone',
                    'type'        => 'text',
                    'data-type'   => 'text',
                    'label'       => '',
                    'disabled'    => false,
                    'placeholder' => esc_attr__('Phone', 'fluent-cart'),
                    'value'       => Arr::get($getCart, 'checkout_data.form_data.' . $type . '_phone'),
                ]
            ];


            $requiredOnLoggedOut['address_section']['schema'] = array_merge(
                $requiredOnLoggedOut['address_section']['schema'],
                $schema
            );
        }

        $addressLabel = $type === 'billing' ?
            __('Billing Address', 'fluent-cart') :
            __('Shipping Address', 'fluent-cart');

        $requiredOnLoggedIn = [
            'address' => [
                'type'   => 'section',
                'title'  => $addressLabel,
                'schema' => [
                    'address' => [
                        'id'        => 'address',
                        'type'      => 'address_select',
                        'data-type' => 'hidden',
                        'required'  => 'no',
                        'label'     => '',
                        'disabled'  => false,
                        'options'   => $addresses,
                        'value'     => Arr::get($getCart, 'checkout_data.form_data.' . $type . '_address_id'),
                    ]
                ]
            ]
        ];

        $customerName = $this->getFullName();
        $customerEmail = $this->getEmail();
        $fullNameDisabled = $type === 'billing' && is_user_logged_in() && !empty($customerName);
        $savedFullName = Arr::get($getCart, 'checkout_data.form_data.' . $type . '_full_name');
        $savedEmail = Arr::get($getCart, 'checkout_data.form_data.' . $type . '_email');
        if (!empty($savedFullName)) {
            $customerName = $savedFullName;
        }
        if (!empty($savedEmail)) {
            $customerEmail = $savedEmail;
        }

        $fields = [
            'personal_information' => [
                'type'   => 'section',
                'schema' => [
                    'full_name' => [
                        'id'           => 'full_name',
                        'type'         => 'text',
                        'data-type'    => 'text',
                        'label'        => '',
                        'required'     => 'yes',
                        'autocomplete' => 'given-name',
                        'value'        => $customerName,
                        'placeholder'  => esc_attr__('Full Name', 'fluent-cart'),
                    ],
                    'email'     => [
                        'id'           => 'email',
                        'type'         => 'text',
                        'data-type'    => 'email',
                        'required'     => 'yes',
                        'label'        => '',
                        'autocomplete' => 'email username',
                        'value'        => $customerEmail,
                        'disabled'     => is_user_logged_in(),
                        'placeholder'  => esc_attr__('Email address', 'fluent-cart'),
                    ]
                ]
            ],
        ];

        $currentCustomer = CustomerResource::getCurrentCustomer();

        $hasAddress = true;

        if (empty($currentCustomer) || $currentCustomer->billing_address->count() === 0) {
            $hasAddress = false;
        }

        $fields = $fields + ($hasAddress ? $requiredOnLoggedIn : $requiredOnLoggedOut);

        return apply_filters('fluent_cart/checkout_address_fields', $fields, []);
    }

    public function getBillingAddressFields($viewData = [])
    {
        $labels = Arr::get($viewData, 'labels', []);
        $fields = $this->getAddressFields();
        $allowCreateAccount = Arr::get($viewData, 'block_allow_create_account', []);
        $label = Arr::get($allowCreateAccount, 'label', __('Create My Account', 'fluent-cart'));
        $customer = CustomerResource::getCurrentCustomer();

        if (isset($fields['address_section'])) {
            $fields['address_section']['title'] = $labels['billing_address'] ?? __('Billing Address', 'fluent-cart');
        }

        if ((new StoreSettings())->get('user_account_creation_mode') === 'user_choice') {
            $isUserLoggedIn = is_user_logged_in();

            // Show the view if the user is not logged in or does not have an account
            if (!$isUserLoggedIn || ($customer === null)) {
                $checked = $this->hasSubscription() === 'yes' ? 'yes' : 'no';
                $disabled = $this->hasSubscription() === 'yes';
                $fields['personal_information']['schema']['allow_create_account'] = [
                    'id'           => 'allow_create',
                    'type'         => 'checkbox',
                    'data-type'    => 'text',
                    'label'        => $label,
                    'skip_prefix'  => true,
                    'autocomplete' => 'given-name',
                    'value'        => 'yes',
                    'checked'      => $checked,
                    'disabled'     => $disabled
                ];
            }
        }

        unset($fields['address_section']['schema']['label']);

        return apply_filters('fluent_cart/checkout_billing_fields', $fields, [
            'viewData'         => $viewData,
            'customer'         => $customer,
            'labels'           => $labels,
            'has_subscription' => $this->hasSubscription()
        ]);
    }

    public function getShippingAddressFields($viewData = [])
    {
        $labels = Arr::get($viewData, 'labels', []);
        $fields = $this->getAddressFields('shipping');
        $addressSchema = [];

        if (isset($fields['address_section'])) {
            $addressSchema = $fields['address_section']['schema'];
            $fields['address_section']['title'] = $labels['shipping_address'] ?? __('Shipping Address', 'fluent-cart');
        }

        // Remove email field
        if (isset($fields['personal_information']['schema']['email'])) {
            unset($fields['personal_information']['schema']['email']);
            unset($fields['tax_information']);
        }

        // Extract name field and remove personal information section
        $fullNameField = $fields['personal_information']['schema'] ?? [];
        unset($fields['personal_information']);

        if (isset($fields['address_section']) && !empty($addressSchema) && is_array($addressSchema)) {
            $fields['address_section']['schema'] = array_merge(
                $fullNameField,
                $addressSchema
            );
        }

        // Remove label if user is not logged in
        if (!is_user_logged_in()) {
            unset($fields['address_section']['schema']['label']);
        }

        return apply_filters('fluent_cart/checkout_shipping_fields', $fields, [
            'viewData' => $viewData,
            'labels'   => $labels,
        ]);
    }

    public function getItemsAmountTotal($formatted = true, $withCurrency = true, $shippingTotal = 0)
    {
        $checkoutItems = new CheckoutService($this->getItems());

        $subscriptionItems = $checkoutItems->subscriptions;
        $onetimeItems = $checkoutItems->onetime;

        $items = array_merge($onetimeItems, $subscriptionItems);

        $total = OrderService::getItemsAmountTotal($items, false, $withCurrency, $shippingTotal);

        if (!$formatted) {
            return $total;
        }

        return Helper::toDecimal($total, $withCurrency);
    }

    public function getItemsAmountTotalWithShipping($shippingTotal = 0, $formatted = true, $withCurrency = true)
    {
        return $this->getItemsAmountTotal(true, true, $shippingTotal);
    }

    public function getItemsAmountSubtotal($formatted = true, $withCurrency = true)
    {
        $checkoutItems = new CheckoutService($this->getItems());
        $subscriptionItems = $checkoutItems->subscriptions;
        $onetimeItems = $checkoutItems->onetime;

        $items = array_merge($onetimeItems, $subscriptionItems);


        $subtotal = OrderService::getItemsAmountWithoutDiscount($items);

        return $formatted ? Helper::toDecimal($subtotal, $withCurrency) : $subtotal;
    }

    public function getSignupFields()
    {
        $fields = array(
            'full_name' => array(
                'id'           => 'full_name',
                'type'         => 'text',
                'data-type'    => 'text',
                'label'        => __('Full Name', 'fluent-cart'),
                'required'     => 'yes',
                'autocomplete' => 'given-name',
                'placeholder'  => esc_attr__('e.g. James Brown', 'fluent-cart'),
            ),
            'email'     => array(
                'id'           => 'billing_email',
                'type'         => 'text',
                'data-type'    => 'email',
                'required'     => 'yes',
                'label'        => __('Email Address', 'fluent-cart'),
                'autocomplete' => 'email username',
                'placeholder'  => esc_attr__('e.g. name@domain.com', 'fluent-cart'),
            ),
            'password'  => array(
                'id'           => 'password',
                'type'         => 'text',
                'data-type'    => 'password',
                'required'     => 'no',
                'label'        => __('Password', 'fluent-cart'),
                'autocomplete' => 'current-password',
                'placeholder'  => esc_attr__('Enter Strong password', 'fluent-cart'),
            ),
        );

        return apply_filters('fluent_cart/checkout_signup_fields', $fields, []);
    }

    public function getLoginFields()
    {
        $fields = array(
            'user_login' => array(
                'id'           => 'username_email',
                'type'         => 'text',
                'data-type'    => 'text',
                'required'     => 'yes',
                'label'        => __('Username or Email Address', 'fluent-cart'),
                'autocomplete' => 'email username',
                'placeholder'  => esc_attr__('e.g. name@domain.com or username', 'fluent-cart'),
            ),
            'password'   => array(
                'id'           => 'password',
                'type'         => 'text',
                'data-type'    => 'password',
                'required'     => 'yes',
                'label'        => __('Password', 'fluent-cart'),
                'autocomplete' => 'current-password',
                'placeholder'  => esc_attr__('Enter password', 'fluent-cart'),
            ),
        );

        return apply_filters('fluent_cart/checkout_login_fields', $fields, []);
    }

    public function getCartHash()
    {
        if ($this->cart == null) {
            return null;
        }

        return $this->cart->cart_hash;
    }


    public function toArray(): array
    {
        return [
            'address_fields'         => $this->getAddressFields(),
            'billing_address'        => $this->getBillingAddress(),
            'billing_address_fields' => $this->getBillingAddressFields(),
            'cart_hash'              => $this->getCartHash(),
            'customer'               => $this->getCustomer(),
            'info'                   => [
                'email'      => $this->getEmail(),
                'first_name' => $this->getFirstName(),
                'last_name'  => $this->getLastName(),
                'user_id'    => $this->getUserId()
            ],
            'is_email_locked'        => $this->isEmailLocked(),
            'items'                  => $this->getItems(),
            'settings'               => $this->getSettings(),
            'user'                   => $this->getUser(),
        ];
    }

    public function getCouponFields()
    {
        $fields = array(
            'coupon'          => array(
                'name_prefix' => 'coupon_',
                'id'          => 'coupon',
                'type'        => 'text',
                'data-type'   => 'text',
                'label'       => '',
                'required'    => 'no',
                'placeholder' => __('Apply Here', 'fluent-cart'),
            ),
            'applied_coupons' => array(
                'type'      => 'hidden',
                'data-type' => 'hidden',
                'label'     => '',
            ),
        );

        return apply_filters('fluent_cart/checkout_coupon_fields', $fields, []);
    }

    public function getManualDiscountAmount()
    {
        if (empty($this->cart)) {
            return 0;
        }

        if ($this->cart->order) {
            return $this->cart->order->manual_discount_total;
        }

        return (int)Arr::get($this->cart->checkout_data, 'manual_discount.amount', 0);
    }
}
