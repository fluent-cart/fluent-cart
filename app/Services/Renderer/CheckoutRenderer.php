<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\Api\PaymentMethods;
use FluentCart\Api\Resource\CustomerResource;
use FluentCart\Api\Resource\FrontendResource\CustomerAddressResource;
use FluentCart\Api\StoreSettings;
use FluentCart\App\App;use FluentCart\App\Helpers\AddressHelper;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Services\Localization\LocalizationManager;use FluentCart\App\Services\URL;
use FluentCart\Framework\Support\Arr;

class CheckoutRenderer
{

    private $cart;

    private $requireShipping;

    private $hasSubscription = false;

    private $config = [];

    private $billingAddress = [];

    private $shippingAddress = [];

    private $storeSettings;

    public function __construct(Cart $cart, $config = [])
    {
        $this->cart = $cart;
        $this->requireShipping = $cart->requireShipping();
        $this->hasSubscription = $cart->hasSubscription();

        $formData = Arr::get($cart->checkout_data, 'form_data', []);
        $this->storeSettings = new StoreSettings();

        $billingValidations = array_filter(CheckoutFieldsSchema::getCheckoutFieldsRequirements('billing', 'physical'));
        $storeCountry = (new StoreSettings())->get('store_country');
        if (!Arr::has($billingValidations, 'country') && Arr::get($formData, 'billing_country') !== $storeCountry) {
            $formData['billing_country'] = $storeCountry;
            // save $this->cart
            $checkoutData = $this->cart->checkout_data;
            $checkoutData['form_data'] = $formData;
            $this->cart->checkout_data = $checkoutData;
            $this->cart->save();
        }

        $fallbackCountry = '';
        $HTTP_CF_IP_COUNTRY = Arr::get( App::request()->server(), 'HTTP_CF_IPCOUNTRY');

        if ($HTTP_CF_IP_COUNTRY) {
            $fallbackCountry = $HTTP_CF_IP_COUNTRY;
        }

        $this->billingAddress = [
            'full_name'    => trim($this->cart->first_name . ' ' . $this->cart->last_name),
            'country'      => Arr::get($formData, 'billing_country', $fallbackCountry),
            'address_1'    => Arr::get($formData, 'billing_address_1', ''),
            'address_2'    => Arr::get($formData, 'billing_address_2', ''),
            'city'         => Arr::get($formData, 'billing_city', ''),
            'state'        => Arr::get($formData, 'billing_state', ''),
            'postcode'     => Arr::get($formData, 'billing_postcode', ''),
            'company_name' => Arr::get($formData, 'billing_company_name', ''),
            'phone'        => Arr::get($formData, 'billing_phone', ''),
        ];

        if ($this->requireShipping) {
            if (Arr::get($formData, 'ship_to_different', '') === 'yes') {
                $shippingCountry = Arr::get($formData, 'shipping_country', $fallbackCountry);
                $shippingValidations = array_filter(CheckoutFieldsSchema::getCheckoutFieldsRequirements('shipping', 'physical'));
                if (!Arr::has($shippingValidations, 'country')) {
                    $shippingCountry = Arr::get($formData, 'billing_country');
                }

                $this->shippingAddress = [
                    'full_name'    => Arr::get($formData, 'shipping_full_name', ''),
                    'country'      => $shippingCountry,
                    'address_1'    => Arr::get($formData, 'shipping_address_1', ''),
                    'address_2'    => Arr::get($formData, 'shipping_address_2', ''),
                    'city'         => Arr::get($formData, 'shipping_city', ''),
                    'state'        => Arr::get($formData, 'shipping_state', ''),
                    'postcode'     => Arr::get($formData, 'shipping_postcode', ''),
                    'company_name' => Arr::get($formData, 'shipping_company_name', ''),
                    'phone'        => Arr::get($formData, 'shipping_phone', ''),
                ];
            } else {
                $this->shippingAddress = $this->billingAddress;
            }
        }

        $this->config = $config;
    }

    public function render($config = [])
    {
        if ($config) {
            $this->config = wp_parse_args($config, $this->config);
        }

        $this->wrapperStart();
        $this->renderNotices();

        $this->renderCheckoutForm();

        $this->wrapperEnd();
    }

    public function getFragment($fragmentName) {
        $maps = [
            'shipping_methods' => 'renderShippingOptions',
            'payment_methods' => 'renderPaymentMethods',
            'cart_summary_fragment' => 'renderSummaryFragment'
        ];

        if(isset($maps[$fragmentName])) {
            ob_start();
            $this->{$maps[$fragmentName]}();
            return ob_get_clean();
        }
        return '';

    }

    public function wrapperStart()
    {
        $classNames = [
            'fluent-cart-checkout-page',
            'fct-checkout',
            'fct-checkout-type-' . $this->cart->cart_group
        ];
        $configClass = Arr::get($this->config, 'wrapper_class', '');

        if ($configClass) {
            $classNames[] = $configClass;
        }

        $classNames = apply_filters('fluent_cart/checkout_page_css_classes', $classNames, [
            'cart' => $this->cart
        ]);

        $classNames = array_filter(array_unique($classNames));

        $atts = [
            'class'                          => implode(' ', $classNames),
            'data-fluent-cart-checkout-page' => '',
        ];

        do_action('fluent_cart/before_checkout_page_start', [
            'cart' => $this->cart
        ]);
        ?>
        <div <?php RenderHelper::renderAtts($atts); ?> role="main" aria-label="<?php esc_attr_e('Checkout Page', 'fluent-cart'); ?>">
        <?php
        do_action('fluent_cart/afrer_checkout_page_start', [
            'cart' => $this->cart
        ]);
    }

    public function renderNotices()
    {
        $notices = Arr::get($this->cart->checkout_data, '__cart_notices', []);
        $hookedNotices = apply_filters('fluent_cart/checkout_page_notices', [], [
            'cart' => $this->cart
        ]);
        if (!$notices && !$hookedNotices) {
            return;
        }
        ?>
        <div class="fct-cart-notices" role="status" aria-live="polite">
            <?php foreach ($notices as $notice):
                if (empty($notice['content'])) {
                    continue;
                } ?>
                <div class="fct-alert">
                    <?php echo wp_kses_post($notice['content']); ?>
                </div>
            <?php endforeach; ?>
            <?php foreach ($hookedNotices as $notice):
                if (empty($notice['content'])) {
                    continue;
                } ?>
                <div class="fct-alert">
                    <?php echo wp_kses_post($notice['content']); ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    public function renderCheckoutForm()
    {
        global $wp;
        $current_url = home_url(add_query_arg([], $wp->request));
        $current_url = add_query_arg(App::request()->all(), $current_url);

        $formAttributes = [
            'method'                                       => 'POST',
            'data-fluent-cart-checkout-page-checkout-form' => '',
            'class'                                        => 'fct_checkout fluent-cart-checkout-page-checkout-form',
            'action'                                       => $current_url,
            'enctype'                                      => 'multipart/form-data',
        ];
        do_action('fluent_cart/before_checkout_form', ['cart' => $this->cart]);
        ?>
        <form <?php RenderHelper::renderAtts($formAttributes); ?> aria-label="<?php esc_attr_e('Checkout Form', 'fluent-cart'); ?>">
            <?php do_action('fluent_cart/checkout_form_opening', ['cart' => $this->cart]); ?>
            <div class="fct_checkout_inner">
                <div class="fct_checkout_form">
                    <div class="fct_checkout_form_items">
                        <?php $this->renderNameFields(); ?>


                        <?php $this->renderCreateAccountField(); ?>

                        <?php do_action('fluent_cart/before_billing_fields', ['cart' => $this->cart]); ?>

                        <?php $this->renderAddressFields(); ?>
                        <div  class="fct_checkout_shipping_methods <?php echo $this->requireShipping ? '' : 'is-hidden' ?>">
                            <?php $this->renderShippingOptions(); ?>
                        </div>

                        <?php $this->agreeTerms(); ?>

                        <?php do_action('fluent_cart/before_payment_methods', ['cart' => $this->cart]); ?>

                       <div class="fct_checkout_payment_methods " data-fluent-cart-checkout-payment-methods>
                            <?php $this->renderPaymentMethods(); ?>
                       </div>

                       <?php do_action('fluent_cart/after_payment_methods', ['cart' => $this->cart]); ?>

                        <?php $this->renderCheckoutButton(); ?>

                        <?php do_action('fluent_cart/after_checkout_button', ['cart' => $this->cart]); ?>

                    </div>
                </div>
                <div class="fct_checkout_summary">
                    <div class="fct_summary active" data-fluent-cart-checkout-page-checkout-form-order-summary aria-labelledby="order-summary-heading">
                        <span id="order-summary-heading" class="sr-only">
                            <?php esc_html_e('Order Summary', 'fluent-cart'); ?>
                        </span>

                        <?php (new CartSummaryRender($this->cart))->render(); ?>
                        <?php $this->renderOrderNoteField(); ?>
                        <?php do_action('fluent_cart/after_order_notes', ['cart' => $this->cart]); ?>
                    </div>
                </div>
            </div>
        </form>
        <?php
        do_action('fluent_cart/after_checkout_form', ['cart' => $this->cart]);
    }

    public function wrapperEnd()
    {
        do_action('fluent_cart/before_checkout_page_close', [
            'cart' => $this->cart
        ]);
        ?>
        </div>
        <?php
        do_action('fluent_cart/after_checkout_page', [
            'cart' => $this->cart
        ]);
    }

    public function renderCreateAccountField() {
        //check store settings also

        if (!is_user_logged_in() && $this->storeSettings->get('user_account_creation_mode') === 'user_choice') {
            $formRender = new FormFieldRenderer();
            $formRender->renderField([
                'type'           => 'checkbox',
                'id'             => 'allow_create_account',
                'name'           => 'allow_create_account',
                'checkbox_value' => 'yes',
                'label'          => __('Create an account?', 'fluent-cart'),
                'value'          => Arr::get($this->cart->checkout_data, 'form_data.allow_create_account', ''),
                'wrapper_class'  => 'fct_create_account_wrapper',
            ]);
        }
    }

    public function renderNameFields()
    {
        $schema = CheckoutFieldsSchema::getNameEmailFieldsSchema($this->cart, 'render');
        if (!$schema) {
            return;
        }
        (new FormFieldRenderer())->renderSection($schema);
    }

    public function validateAddressField($config, $fields){

        $type = Arr::get($config, 'type', 'billing'); // billing or shipping

        $customer = CustomerResource::getCurrentCustomer();
        if ($customer) {
            $requirementsFields = CheckoutFieldsSchema::getCheckoutFieldsRequirements(
                $type,
                Arr::get($config, 'product_type'),
                Arr::get($config, 'with_shipping')
            );
            $allowedAddresses = AddressHelper::getCustomerValidatedAddresses($config, $customer);
            if (!empty($allowedAddresses)) {
                $primaryAddress = AddressHelper::getPrimaryAddress(
                        $allowedAddresses,
                        $config,
                        $customer,
                        $type
                );


                $addressLabel = $type === 'billing' ?
                    __('Billing Address', 'fluent-cart') :
                    __('Shipping Address', 'fluent-cart');

                $countries = LocalizationManager::getInstance()->countries();

                return [
                    'address_select' => [
                        'type'            => 'address_select',
                        'address_type'    => $type,
                        'options'         => $allowedAddresses,
                        'title'           => $addressLabel,
                        'label'           => '',
                        'countries'       => $countries,
                        'primary_address' => $primaryAddress,
                        'value'           => Arr::get($primaryAddress, 'id'),
                        'requirements_fields' => $requirementsFields
                    ]
                ];
            }

            return $fields;
        }

        return $fields;
    }

    public function renderAddressFields()
    {
        $requireShipping = $this->requireShipping;
        $formData = Arr::get($this->cart->checkout_data, 'form_data', []);


        $billingAddress = $this->billingAddress;


        $billingAddress['type'] = 'billing';
        $billingAddress['product_type'] = $requireShipping ? 'physical' : 'digital';
        $billingAddress['with_shipping'] = $requireShipping && Arr::get($formData, 'ship_to_different', '') !== 'yes';
        $billingAddress['billing_address_id'] = Arr::get($formData, 'billing_address_id', '');

        $billingFields = CheckoutFieldsSchema::getAddressBaseFields($billingAddress);

        $billingFields = $this->validateAddressField($billingAddress, $billingFields);


        foreach ($billingFields as &$field) {
            if(empty($field['wrapper_atts'])) {
                $field['wrapper_atts'] = [];
            }
            $field['wrapper_atts']['data-fluent-cart-checkout-page-form-input-wrapper'] = '';
        }

        $billingFields = $this->maybeRearrangeAddressFields($billingFields);


        $billingFields = apply_filters('fluent_cart/checkout_renderer/billing_fields', $billingFields, [
                'checkout_renderer' => $this,
                'cart' => $this->cart
        ]);

        do_action('fluent_cart/before_billing_fields_section', ['cart' => $this->cart]);

        $formRender = new FormFieldRenderer();

        echo '<div class="fct_checkout_billing_and_shipping">';

        $formRender->renderSection([
            'id'           => 'billing_address_section_section',
            'type'         => 'section',
            'heading'      => __('Billing Address', 'fluent-cart'),
            'fields'       => $billingFields,
            'wrapper_atts' => [
                'data-fluent-cart-checkout-page-form-section' => '',
                'role'  => 'region',
                'aria-label' => __('Billing Address', 'fluent-cart')
            ]
        ]);

        do_action('fluent_cart/after_billing_fields_section', ['cart' => $this->cart]);

        if (!$requireShipping) {
            echo '</div>';
            return;
        }



        $formRender->renderField([
            'type'           => 'checkbox',
            'id'             => 'ship_to_different',
            'name'           => 'ship_to_different',
            'checkbox_value' => 'yes',
            'label'          => __('Ship to a different address?', 'fluent-cart'),
            'value'          => Arr::get($this->cart->checkout_data, 'form_data.ship_to_different', ''),
            'wrapper_class'  => 'fct_ship_to_different_wrapper',
            'extra_atts'     => [
                'data-fluent-cart-ship-to-different-address' => 'yes',
                'aria-controls' => 'shipping_address_section_section',
            ],
        ]);

        $shippingAddress = $this->shippingAddress;
        $shippingAddress['type'] = 'shipping';
        $shippingAddress['product_type'] = 'physical';
        $shippingAddress['shipping_address_id'] = Arr::get($formData, 'shipping_address_id', '');

        $shippingFields = CheckoutFieldsSchema::getAddressBaseFields($shippingAddress);

        $shippingFields = $this->validateAddressField($shippingAddress,$shippingFields);

        $shippingFields = apply_filters('fluent_cart/checkout_renderer/shipping_fields', $shippingFields, [
                'checkout_renderer' => $this,
                'cart' => $this->cart
        ]);

        foreach ($shippingFields as &$field) {
            if(empty($field['wrapper_atts'])) {
                $field['wrapper_atts'] = [];
            }
            $field['wrapper_atts']['data-fluent-cart-checkout-page-form-input-wrapper'] = '';
        }

        $shippingFields = $this->maybeRearrangeAddressFields($shippingFields);

        do_action('fluent_cart/before_shipping_fields_section', ['cart' => $this->cart]);
        $formRender->renderSection([
            'id'           => 'shipping_address_section_section',
            'type'         => 'section',
            'heading'      => __('Shipping Address', 'fluent-cart'),
            'fields'       => $shippingFields,
            'wrapper_atts' => [
                'data-fluent-cart-checkout-page-shipping-fields' => '',
                'style'                                          => Arr::get($this->cart->checkout_data, 'form_data.ship_to_different', '') === 'yes' ? '' : 'display:none',
                'role'  => 'region',
                'aria-label' => __('Shipping Address', 'fluent-cart')
            ]
        ]);
        do_action('fluent_cart/after_shipping_fields_section', ['cart' => $this->cart]);
        echo '</div>';
    }

    public function renderOrderNoteField()
    {
        if (
            !$this->requireShipping &&
            apply_filters('fluent_cart/disable_order_notes_for_digital_products', true, [
                'cart' => $this->cart
            ])
        ) {
            return;
        }

        $fieldId = 'order_notes';

        (new FormFieldRenderer())->renderField([
            'type'            => 'textarea',
            'id'              => $fieldId,
            'name'            => 'order_notes',
            'aria-label'      => __('Order Notes', 'fluent-cart'),
            'placeholder'     => __('Notes about your order, e.g. Leave it at my doorstep.', 'fluent-cart'),
            'extra_atts'      => [
                'rows' => 4
            ],
            'wrapper_atts'    => [
                'data-fct-item-toggle' => '',
                'class'                => 'fct-toggle-field fct_order_note'
            ],
            'before_callback' => function ($field) use ($fieldId) {
                    $toggleId = 'order_notes_toggle';
                    $wrapperId = 'order_notes_wrapper';
                ?>

                <button
                    type="button"
                    data-fct-item-toggle-control
                    id="<?php echo esc_attr($toggleId); ?>"
                    class="fct-toggle-control fct_order_note_toggle"
                    aria-expanded="false"
                    aria-controls="<?php echo esc_attr($wrapperId); ?>"
                >
                     <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none">
                        <path d="M15.6 12.0001L10.2 17.4001V6.6001L15.6 12.0001Z" fill="currentColor"/>
                    </svg>
                    <?php esc_html_e('Leave a Note', 'fluent-cart'); ?>
                </button>

                <div
                    id="<?php echo esc_attr($wrapperId); ?>"
                    class="fct_toggle-wrapper fct_order_note_wrapper"
                    aria-hidden="true"
                >
                <?php
            },
            'after_callback'  => function ($field) {
                echo '</div>';
            }
        ]);

        do_action('fluent_cart/after_order_notes_field', ['cart' => $this->cart]);
    }

    public function renderShippingOptions()
    {
        if (!$this->requireShipping) {
            return;
        }

        $countryCode = $this->shippingAddress['country'] ?: $this->billingAddress['country'];
        $stateCode = $this->shippingAddress['state'] ?: $this->billingAddress['state'];
        $billingValidations = array_filter(CheckoutFieldsSchema::getCheckoutFieldsRequirements('billing', 'physical'));

        if (!isset($billingValidations['country'])) {
            $countryCode = (new StoreSettings())->get('store_country');
        }

        $availableShippingMethods = AddressHelper::getShippingMethods($countryCode, $stateCode);


        $selectedId = Arr::get($this->cart->checkout_data, 'shipping_data.shipping_method_id', '');

        if (!$availableShippingMethods || is_wp_error($availableShippingMethods)) {
            (new ShippingMethodsRender($availableShippingMethods, $selectedId))->render();
        }else{
            foreach ($availableShippingMethods as $method) {
                $method->charge_amount = CartHelper::calculateShippingMethodCharge($method, $this->cart->cart_data);
            }

            (new ShippingMethodsRender($availableShippingMethods, $selectedId))->render();
        }
    }

    public function renderPaymentMethods()
    {
        $selectedPaymentMethod = Arr::get($this->cart->checkout_data, 'form_data._fct_pay_method', '');
        $activePaymentMethods = PaymentMethods::getActiveMethodInstance($this->cart);

        $activePaymentMethods = apply_filters('fluent_cart/checkout_active_payment_methods', $activePaymentMethods, [
            'cart' => $this->cart
        ]);

        if(!$selectedPaymentMethod && !empty($activePaymentMethods)) {
            $selectedPaymentMethod = $activePaymentMethods[0] ? $activePaymentMethods[0]->getMeta('route') : '';
        }

        $checkoutMethodStyle = $this->storeSettings->get('checkout_method_style', 'logo');

        ?>
        <div id="fluent_payment_methods" class="fluent_payment_methods">
            <div class="fct_checkout_form_section" aria-labelledby="payment_methods_label"
            role="radiogroup">
                <div class="fct_form_section_header">
                    <h4 id="payment_methods_label" class="fct_form_section_header_label"><?php esc_html_e('Payment', 'fluent-cart'); ?>
                    </h4>
                </div>
                <div class="fct_form_section_body">
                    <div class="fct_payment_methods_list fct_payment_method_mode_<?php echo esc_attr($checkoutMethodStyle); ?>">
                        <?php if (!empty($activePaymentMethods)): ?>
                            <?php foreach ($activePaymentMethods as $method): ?>
                                <?php
                                    $isSelected = ($selectedPaymentMethod === $method->getMeta('route'));

                                    $this->renderPaymentMethod($method, [
                                        'selected_id' => $selectedPaymentMethod,
                                        'style' => $checkoutMethodStyle,
                                        'aria_checked' => $isSelected ? 'true' : 'false',
                                        'role' => 'radio'
                                    ]);
                                ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php
                                $emptyText = esc_html__('No Payment method is activated for this site yet.', 'fluent-cart');
                                if (current_user_can('manage_options')) {
                                    $emptyText .= '<a href="' . esc_url(URL::getDashboardUrl('settings/payments')) . '" target="_blank">' . esc_html__('Activate from settings.', 'fluent-cart') . '</a>';
                                }
                                echo '<div class="fct-empty-state">' . wp_kses_post($emptyText) . '</div>';
                            ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function renderCheckoutButton()
    {
        $attributes = [
            'type' => 'submit',
            'class' => 'fct_place_order_btn large',
            'id' => 'fluent_cart_order_btn',
            'data-fluent-cart-checkout-page-checkout-button' => '',
            'data-value' => __('Place Order', 'fluent-cart'),
            'disabled' => ''
        ];
        ?>
        <div class="fct_place_order_btn_wrap">
            <button <?php RenderHelper::renderAtts($attributes); ?>>
                <?php esc_html_e('Place Order', 'fluent-cart'); ?>
            </button>
        </div>
        <?php
    }

    protected function renderPaymentMethod($method, $config = [])
    {
        $route = $method->getMeta('route');
        $methodTitle = $method->getMeta('title');
        $methodStyle = Arr::get($config, 'style', 'logo');

        $inputAttributes = array_filter([
             'class'    => 'form-radio-input',
            'type'     => 'radio',
            'name'     => '_fct_pay_method',
            'id'       => 'fluent_cart_payment_method_' . $route,
            'value'    => $route,
            'required' => true,
            'checked'  => $route === Arr::get($config, 'selected_id', '') ? 'true' : '',
            'role'          => Arr::get($config, 'role', 'radio'),
            'aria-checked'  => Arr::get($config, 'aria_checked', 'false'),
        ]);

        $wrapperClass = $methodStyle === 'logo' ? 'fct_payment_method_logo' : 'fct_payment_method';

        $wrapperAttributes = [
            'class' => $wrapperClass . ' ' . 'fct_payment_method_wrapper fct_payment_method_' . $route,
            'tabindex' => '0',
            'role' => 'presentation'
        ];

        ?>
        <div <?php RenderHelper::renderAtts($wrapperAttributes); ?>>
            <span class="fct-payment-method-loader"></span>

            <input <?php RenderHelper::renderAtts($inputAttributes); ?>/>
            <label for="<?php echo esc_attr('fluent_cart_payment_method_' . $route); ?>">
                <?php
                    if ($methodStyle === 'logo') {
                       $method->prepare('logo', $this->hasSubscription);
                    }else {
                        $method->prepare('radio', $this->hasSubscription);
                    }
                 ?>

                <?php echo esc_html($methodTitle); ?>
            </label>
            <?php if ($method->getMeta('instructions')): ?>
                <div class="fct_payment_method_instructions" style="display: none;">
                    <?php echo wp_kses_post($method->getMeta('instructions')); ?>
                </div>
            <?php endif; ?>
            <div class="fluent-cart-checkout_embed_payment_wrapper">
            <?php
                $paymentMethodClass = apply_filters('fluent_cart_payment_method_list_class', '',[
                        'route' => $route,
                        'method_title' => $methodTitle,
                        'method_style' => $methodStyle,
]                );

             ?>
                <div class="<?php echo "fluent-cart-checkout_embed_payment_container fluent-cart-checkout_embed_payment_container_" . esc_attr($route. ' '. $paymentMethodClass);?>" aria-hidden="true">
                    <?php do_action('fluent_cart/checkout_embed_payment_method_content', [
                            'method' => $method,
                            'cart' => $this->cart,
                            'route' => $route
                        ]
                    ); ?>
                </div>
            </div>
        </div>
        <?php
    }

    private function maybeRearrangeAddressFields($fields)
    {
        if (isset($fields['city']) && isset($fields['postcode'])) {
            $cityField = $fields['city'];
            $postcodeField = $fields['postcode'];
            unset($fields['city'], $fields['postcode']);
            $fields['city_postal'] = [
                'type'          => 'sub_section',
                'wrapper_class' => 'fct_2_columns fct_checkout_city_postcode',
                'fields'        => [
                    'city'     => $cityField,
                    'postcode' => $postcodeField
                ],
            ];
        }

        if (isset($fields['phone'])) {
            $phoneField = $fields['phone'];
            unset($fields['phone']);
            $fields['phone'] = $phoneField;
        }

        if (isset($fields['company_name'])) {
            $companyField = $fields['company_name'];
            unset($fields['company_name']);
            $fields['company_name'] = $companyField;
        }

        return $fields;
    }


    public function agreeTerms() {
        if(!CheckoutFieldsSchema::isTermsVisible()) {
            return;
        }

        $termsText = wp_kses_post(CheckoutFieldsSchema::getTermsText());

        ?>
        <div class="fct_checkout_agree_terms" role="group" aria-labelledby="agree_terms_label">
            <label for="agree_terms" class="fct_input_label fct_input_label_checkbox">
                <input
                    data-fluent-cart-agree-terms="yes"
                    type="checkbox"
                    class="fct-input fct-input-checkbox"
                    id="agree_terms"
                    name="agree_terms"
                    value="yes"
                    required
                    aria-required="true"
                    aria-label="<?php echo esc_attr($termsText); ?>"
                >

                 <?php echo wp_kses_post($termsText); ?>
            </label>
        </div>
    <?php }

    private function renderSummaryFragment()
    {
        (new CartSummaryRender($this->cart))->render(false);
    }
}
