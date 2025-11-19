<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\Framework\Support\Arr;

class AddressSelectRenderer
{
    public string $address_type = 'billing';
    public string $title = '';
    public array $addresses = [];
    public array $countries = [];
    public array $primary_address = [];
    public string $value = '';
    public array $requirements_fields = [];

    public function __construct($addresses, $primary_address, $requirements_fields, $address_type)
    {
        $this->title = $address_type === 'billing' ? __('Billing Address', 'fluent-cart') : __('Shipping Address', 'fluent-cart');
        $this->addresses = $addresses;
        $this->primary_address = $primary_address;
        $this->requirements_fields = $requirements_fields;
        $this->address_type = $address_type;
        $this->countries = LocalizationManager::getInstance()->countries();
    }

    public function render()
    {
        ?>
            <div data-fluent-cart-checkout-page-form-input-wrapper=""
                 class="fct_input_wrapper "
                 id="<?php echo esc_attr($this->address_type); ?>_address_wrapper"
                 role="group"
                 aria-labelledby="<?php echo esc_attr($this->address_type); ?>_address_label"
            >
                 <div data-fluent-cart-checkout-page-form-address-select-wrapper="" class="fct_address_wrapper">
                    <?php $this->renderAddressInfo(); ?>
                    <?php $this->renderAddressModal(); ?>
                 </div>
            </div>
        <?php
    }

    public function renderAddressInfo()
    {
        $attributes = [
            'data-fluent-cart-checkout-page-form-address-input' => '',
            'name' => $this->address_type . '_address_id',
            'data-required' => 'no',
            'data-type' => 'input',
            'id' => $this->address_type . '_address_id',
            'checked' => 'no',
            'value' => $this->primary_address['id'],
            'data-country' => $this->primary_address['country'],
            'data-state' => $this->primary_address['state'],
            'type' => 'hidden'
        ];
        ?>
            <label
                id="<?php echo esc_attr($this->address_type); ?>_address_label"
                class="sr-only"
            >
                <?php echo esc_html(ucfirst($this->address_type)); ?> Address
            </label>

            <input
                <?php RenderHelper::renderAtts($attributes); ?>
                aria-labelledby="<?php echo esc_attr($this->address_type); ?>_address_label
                <?php echo esc_attr($this->address_type); ?>_address_info"
            >
            <div
                    class="fct_address_info"
                    data-fluent-cart-checkout-page-form-address-info-wrapper
                    id="<?php echo esc_attr($this->address_type); ?>_address_info"
            >
                <div class="fct_checkout_address_label">
                    <span><?php echo esc_html($this->primary_address['name']); ?></span>
                </div>
                <p><?php echo esc_html($this->primary_address['formatted_address']['full_address']); ?></p>
            </div>
        <?php

    }


    public function renderAddressModal()
    {
        ?>
        <div class="fct_address_modal_wrapper" data-fluent-cart-checkout-page-form-address-modal-wrapper="">
            <button
                    class="fct_address_modal_open_btn"
                    type="button"
                    data-fluent-cart-checkout-page-form-address-modal-open-button
                    aria-label="<?php echo esc_attr__('Change Address', 'fluent-cart'); ?>"
            >
                <svg aria-hidden="true" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 16 16" fill="none">
                    <g clip-path="url(#clip0_3536_3207)">
                        <path d="M9.38263 2.59046C9.87942 2.05222 10.1278 1.78309 10.3918 1.62611C11.0287 1.24734 11.8129 1.23556 12.4604 1.59504C12.7288 1.74403 12.9848 2.00558 13.4969 2.52867C14.0089 3.05176 14.265 3.31331 14.4108 3.58745C14.7627 4.24891 14.7512 5.05002 14.3804 5.70063C14.2267 5.97027 13.9633 6.22401 13.4364 6.7315L7.16725 12.7697C6.16875 13.7314 5.6695 14.2123 5.04554 14.456C4.42158 14.6997 3.73563 14.6818 2.36374 14.6459L2.17708 14.641C1.75943 14.6301 1.55061 14.6246 1.42922 14.4869C1.30783 14.3491 1.3244 14.1364 1.35755 13.7109L1.37555 13.4799C1.46883 12.2825 1.51548 11.6838 1.7493 11.1456C1.98312 10.6075 2.38645 10.1705 3.19311 9.2965L9.38263 2.59046Z"
                              stroke="currentColor" stroke-linejoin="round"></path>
                        <path d="M8.66699 2.66699L13.3337 7.33366" stroke="currentColor" stroke-linejoin="round"></path>
                        <path d="M9.3335 14.667L14.6668 14.667" stroke="currentColor" stroke-linecap="round"
                              stroke-linejoin="round"></path>
                    </g>
                    <defs>
                        <clipPath id="clip0_3536_3207">
                            <rect width="16" height="16" fill="white"></rect>
                        </clipPath>
                    </defs>
                </svg>
                <?php echo esc_html__('Change', 'fluent-cart'); ?>
            </button>
            
            <div data-fluent-cart-checkout-page-form-address-modal-body="" class="fct_address_modal hidden"
                 data-fluent-cart-address-type="<?php echo esc_attr($this->address_type); ?>"
                 role="dialog"
                 aria-modal="true"
                 aria-labelledby="<?php echo esc_attr($this->address_type); ?>_address_modal_title"
            >
                 
                 <div class="fct_address_modal_inner">
                    <div class="fct_address_modal_header">
                        <h4 id="<?php echo esc_attr($this->address_type); ?>_address_modal_title">
                            <?php echo esc_html($this->title); ?>
                        </h4>
                    </div>
                    
                    <button
                            class="fct_address_modal_close_btn"
                            type="button"
                            data-fluent-cart-checkout-page-form-address-modal-close-button
                            aria-label="<?php echo esc_attr__('Close', 'fluent-cart'); ?>"
                    >
                        <svg viewBox="0 0 24 24" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                            <path d="M6.2253 4.81108C5.83477 4.42056 5.20161 4.42056 4.81108 4.81108C4.42056 5.20161 4.42056 5.83477 4.81108 6.2253L10.5858 12L4.81114 17.7747C4.42062 18.1652 4.42062 18.7984 4.81114 19.1889C5.20167 19.5794 5.83483 19.5794 6.22535 19.1889L12 13.4142L17.7747 19.1889C18.1652 19.5794 18.7984 19.5794 19.1889 19.1889C19.5794 18.7984 19.5794 18.1652 19.1889 17.7747L13.4142 12L19.189 6.2253C19.5795 5.83477 19.5795 5.20161 19.189 4.81108C18.7985 4.42056 18.1653 4.42056 17.7748 4.81108L12 10.5858L6.2253 4.81108Z"></path>
                        </svg>
                    </button>
                    
                    <div class="fct_address_modal_body">
                        <?php $this->renderAddressSelector(); ?>
                        <?php $this->renderAddAddressForm(); ?>
                    </div>
                    
                    <?php $this->renderAddressModalFooter(); ?>
                 </div>
           </div>
        </div>
        <?php

    }


    public function renderAddressModalFooter()
    {
        ?>
        <div class="fct_address_modal_footer">
            <div
                class="fct_address_add_btn"
                data-fluent-cart-checkout-page-form-address-show-add-new-modal-button
                aria-label="<?php echo esc_attr__('Add new address', 'fluent-cart'); ?>"
            >
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14"
                     fill="none">
                    <path d="M7 1.6665V12.3332" stroke="currentColor" stroke-width="1.5"
                          stroke-linecap="round" stroke-linejoin="round"></path>
                    <path d="M1.6665 7H12.3332" stroke="currentColor" stroke-width="1.5"
                          stroke-linecap="round" stroke-linejoin="round"></path>
                </svg>
                <?php echo esc_html__('Add Address', 'fluent-cart'); ?>
            </div>

            <button class="fct_address_apply_btn" type="button"
                    data-fluent-cart-checkout-page-form-address-modal-apply-button="" data-address-id="1" aria-label="<?php echo esc_attr__('Apply selected address', 'fluent-cart'); ?>">
                    <?php echo esc_html__('Apply', 'fluent-cart'); ?>
            </button>
        </div>
        <?php
    }

    public function renderAddressSelector()
    {
        ?>
        <div data-fluent-cart-checkout-page-form-address-modal-address-selector-button-wrapper=""
                             class="fct_address_selector_wrapper">
            <?php if (!empty($this->addresses)) :
                foreach ($this->addresses as $address) : ?>
                    <div class="fct_address_selector" data-id="<?php echo esc_attr($address['id']); ?>"
                         data-fluent-cart-checkout-page-form-address-modal-address-selector-button=""
                         data-country="<?php echo esc_attr($address['country']); ?>"
                         data-state="<?php echo esc_attr($address['state']); ?>"
                         role="button"
                         tabindex="0"
                         aria-pressed="false"
                    >

                        <svg class="fct_address_selector_icon" xmlns="http://www.w3.org/2000/svg"
                             width="28"
                             height="28" viewBox="0 0 28 28" fill="none">
                            <path d="M0 6C0 2.68629 2.68629 0 6 0H28L14 14L0 28V6Z"
                                  fill="currentColor"></path>
                            <path d="M5.2915 9.4165L6.89567 11.0207L11.7082 5.979" stroke="white"
                                  stroke-width="1.5" stroke-linecap="round"
                                  stroke-linejoin="round"></path>
                        </svg>

                        <?php if (!empty(Arr::get($address, 'formatted_address.name')) || !empty(Arr::get($address, 'phone'))) : ?>
                        <div class="fct_address_selector_label">
                            <?php if (!empty(Arr::get($address, 'formatted_address.name'))) : ?>
                                <span><?php echo esc_html(Arr::get($address, 'formatted_address.name')); ?> </span>
                            <?php endif; ?>
                            <?php if (!empty(Arr::get($address, 'phone'))) : ?>
                                <span> <?php echo esc_html(Arr::get($address, 'phone')); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <p class="fct_address_selector_info">
                            <?php echo esc_html(Arr::get($address, 'formatted_address.full_address')); ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="fct_address_selector">
                    <p class="fct_address_selector_info">
                        <?php echo esc_html__('No address found', 'fluent-cart'); ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function renderAddAddressForm()
    {
        ?>
            <div class="fct_add_new_address_form"
                 data-fluent-cart-checkout-page-form-address-show-add-new-modal-form-wrapper="">
                <div id="<?php echo esc_attr($this->address_type); ?>_address_section_section"
                     data-fluent-cart-checkout-page-form-section=""
                     class="fct_checkout_form_section additional-address-field">
                    <div class="fct_form_section_header">
                        <h4 class="fct_form_section_header_label"><?php echo esc_html__('Address', 'fluent-cart'); ?></h4>
                    </div>

                    <div class="fct_form_section_body">
                        <div class="fct_checkout_input_group">
                            <div data-fluent-cart-checkout-page-form-input-wrapper=""
                                 class="fct_input_wrapper "
                                 id="<?php echo esc_attr($this->address_type); ?>_label_wrapper"
                            >
                                <label for="<?php echo esc_attr($this->address_type); ?>_label" class="sr-only">
                                    <?php echo esc_html__('Address Label', 'fluent-cart'); ?>
                                </label>

                                <input
                                    type="text"
                                    name="<?php echo esc_attr($this->address_type); ?>_label"
                                    autocomplete="label"
                                    placeholder="<?php echo esc_html__('e.g Home, Office', 'fluent-cart'); ?>"
                                    data-required="yes"
                                    data-type="input"
                                    id="<?php echo esc_attr($this->address_type); ?>_label"
                                    maxlength="15"
                                    aria-describedby="<?php echo esc_attr($this->address_type); ?>_label_description"
                                >

                                <span id="<?php echo esc_attr($this->address_type); ?>_label_description" class="sr-only">
                                    <?php echo esc_html__('Maximum 15 characters', 'fluent-cart'); ?>
                                </span>

                            </div>

                            <div data-fluent-cart-checkout-page-form-input-wrapper=""
                                 class="fct_input_wrapper "
                                 id="<?php echo esc_attr($this->address_type); ?>_name_wrapper"
                            >
                                <label for="<?php echo esc_attr($this->address_type); ?>_full_name" class="sr-only">
                                    <?php echo esc_html__('Full Name', 'fluent-cart'); ?>
                                </label>

                                <input
                                    type="text"
                                    name="<?php echo esc_attr($this->address_type); ?>_full_name"
                                    autocomplete="name"
                                    placeholder="<?php echo esc_attr__('Name', 'fluent-cart'); ?>"
                                    data-required="no"
                                    data-type="input"
                                    id="<?php echo esc_attr($this->address_type); ?>_full_name"
                                >
                            </div>

                            <?php if (!empty(Arr::get($this->requirements_fields, 'phone'))): ?>
                                <div data-fluent-cart-checkout-page-form-input-wrapper=""
                                     class="fct_input_wrapper "
                                     id="<?php echo esc_attr($this->address_type); ?>_phone_wrapper"
                                >
                                    <label for="<?php echo esc_attr($this->address_type); ?>_phone" class="sr-only">
                                        <?php echo esc_html__('Phone Number', 'fluent-cart'); ?>
                                    </label>

                                    <input
                                        type="text"
                                        name="<?php echo esc_attr($this->address_type); ?>_phone"
                                        placeholder="<?php echo esc_html__('Phone number', 'fluent-cart'); ?>"
                                        data-required="no"
                                        data-type="input"
                                        id="<?php echo esc_attr($this->address_type); ?>_phone"
                                    >
                                </div>
                            <?php endif; ?>

                            <?php if (!empty(Arr::get($this->requirements_fields, 'country'))): ?>
                            <div data-fluent-cart-checkout-page-form-input-wrapper=""
                                 class="fct_input_wrapper "
                                 id="<?php echo esc_attr($this->address_type); ?>_country_wrapper"
                            >
                                <label for="<?php echo esc_attr($this->address_type); ?>_country" class="sr-only">
                                    <?php echo esc_html__('Country / Region', 'fluent-cart'); ?>
                                </label>

                                <select
                                        type="text"
                                        name="<?php echo esc_attr($this->address_type); ?>_country"
                                        autocomplete="country"
                                        data-required="<?php echo Arr::get($this->requirements_fields, 'country') === 'required' ? 'yes' : ''; ?>"
                                        data-type="input"
                                        id="<?php echo esc_attr($this->address_type); ?>_country"
                                        class="hidden-select"
                                >
                                    <option value="">
                                        <?php echo esc_html__('Select a Country', 'fluent-cart'); ?>
                                    </option>
                                    <?php foreach ($this->countries as $countryCode => $country) :?>
                                        <option value="<?php echo esc_attr($countryCode); ?>">
                                            <?php echo esc_html($country); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty(Arr::get($this->requirements_fields, 'address_1'))): ?>
                            <div data-fluent-cart-checkout-page-form-input-wrapper=""
                                 class="fct_input_wrapper "
                                 id="<?php echo esc_attr($this->address_type); ?>_address_1_wrapper"
                            >
                                <label for="<?php echo esc_attr($this->address_type); ?>_address_1" class="sr-only">
                                    <?php echo esc_html__('Street Address', 'fluent-cart'); ?>
                                    <?php echo Arr::get($this->requirements_fields, 'address_1') === 'required' ? esc_html__(' (required)', 'fluent-cart') : ''; ?>
                                </label>
                                <?php
                                    $placeholder_text = esc_html__('Street Address', 'fluent-cart');
                                    if (Arr::get($this->requirements_fields, 'address_1') === 'required') {
                                        $placeholder_text .= ' ' . esc_html__('*', 'fluent-cart');
                                    }
                                ?>
                                <input
                                   type="text"
                                   name="<?php echo esc_attr($this->address_type); ?>_address_1"
                                   autocomplete="address-line1"
                                   placeholder="<?php echo esc_attr($placeholder_text); ?>"
                                   data-required="yes" data-type="input"
                                   aria-required="true"
                                   id="<?php echo esc_attr($this->address_type); ?>_address_1"
                                >
                            </div>
                            <?php endif; ?>

                            <?php if (!empty(Arr::get($this->requirements_fields, 'address_2'))): ?>
                            <div data-fluent-cart-checkout-page-form-input-wrapper=""
                                 class="fct_input_wrapper "
                                 id="<?php echo esc_attr($this->address_type); ?>_address_2_wrapper"
                            >
                                <label for="<?php echo esc_attr($this->address_type); ?>_address_2" class="sr-only">
                                    <?php echo esc_html__('Apartment, suite, unit, etc.', 'fluent-cart'); ?>
                                </label>
                                <input
                                    type="text"
                                    name="<?php echo esc_attr($this->address_type); ?>_address_2"
                                    autocomplete="address-line2"
                                    placeholder="<?php echo esc_html__('Apt, suite, unit', 'fluent-cart'); ?>"
                                    data-required="no"
                                    data-type="input"
                                    id="<?php echo esc_attr($this->address_type); ?>_address_2"
                                >
                                </div>
                            <?php endif; ?>

                            <?php if (!empty(Arr::get($this->requirements_fields, 'state'))): ?>
                            <div data-fluent-cart-checkout-page-form-input-wrapper=""
                                 class="fct_input_wrapper "
                                 id="<?php echo esc_attr($this->address_type); ?>_state_wrapper"
                                 style="display: block;"
                            >
                                <label for="<?php echo esc_attr($this->address_type); ?>_state" class="sr-only">
                                    <?php echo esc_html__('State / Province', 'fluent-cart'); ?>
                                </label>

                                <select
                                    type="select"
                                    name="<?php echo esc_attr($this->address_type); ?>_state"
                                    autocomplete="address-level1"
                                    data-required="yes"
                                    data-type="input"
                                    id="<?php echo esc_attr($this->address_type); ?>_state" checked="no"
                                    class="hidden-select"
                                >
                                    <option value=""><?php echo esc_html__('Select a State', 'fluent-cart'); ?></option>
                                </select>
                            </div>
                            <?php endif; ?>


                            <div id="<?php echo esc_attr($this->address_type); ?>_city_zip_section"
                                 data-fluent-cart-checkout-page-form-section=""
                                 class="fct_checkout_form_section <?php echo empty(Arr::get($this->requirements_fields, 'postcode')) ? 'full-column' : '' ?>">

                                <div class="fct_form_section_body">
                                    <div class="fct_checkout_input_group">
                                        <?php if (!empty(Arr::get($this->requirements_fields, 'city'))): ?>
                                        <div data-fluent-cart-checkout-page-form-input-wrapper=""
                                             class="fct_input_wrapper "
                                             id="<?php echo esc_attr($this->address_type); ?>_city_wrapper"
                                        >
                                            <label for="<?php echo esc_attr($this->address_type); ?>_city" class="sr-only">
                                                <?php echo esc_html__('City / Town', 'fluent-cart'); ?>
                                            </label>

                                            <input type="text"
                                                   name="<?php echo esc_attr($this->address_type); ?>_city"
                                                   autocomplete="address-level2"
                                                   placeholder="<?php echo esc_html__('City / Town', 'fluent-cart'); ?>" data-required="yes"
                                                   data-type="input"
                                                   id="<?php echo esc_attr($this->address_type); ?>_city"
                                                   aria-required="true"
                                            >
                                        </div>
                                        <?php endif; ?>
                                        <?php if (!empty(Arr::get($this->requirements_fields, 'postcode'))): ?>
                                        <div data-fluent-cart-checkout-page-form-input-wrapper=""
                                             class="fct_input_wrapper "
                                             id="<?php echo esc_attr($this->address_type); ?>_postcode_wrapper"
                                        >
                                            <label for="<?php echo esc_attr($this->address_type); ?>_postcode" class="sr-only">
                                                <?php echo esc_html__('Postcode / ZIP', 'fluent-cart'); ?>
                                            </label>

                                            <input type="text"
                                                   name="<?php echo esc_attr($this->address_type); ?>_postcode"
                                                   autocomplete="postal-code"
                                                   placeholder="<?php echo esc_attr__('Postcode / ZIP', 'fluent-cart'); ?>"
                                                   data-required="yes"
                                                   data-type="input"
                                                   id="<?php echo esc_attr($this->address_type); ?>_postcode"
                                                   aria-required="true"
                                            >
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
                <div class="fct_add_new_address_form_footer">

                    <button
                        class="fct_address_cancel_btn"
                        type="button"
                        data-fluent-cart-checkout-page-form-address-show-add-new-modal-cancel-button
                        aria-label="<?php echo esc_attr__('Cancel', 'fluent-cart'); ?>"
                    >
                        <?php echo esc_html__('Cancel', 'fluent-cart'); ?>
                    </button>

                    <button
                        class="fct_address_submit_btn"
                        type="submit"
                        data-fluent-cart-checkout-page-form-address-show-add-new-modal-submit-button
                        aria-label="<?php echo esc_attr__('Submit', 'fluent-cart'); ?>"
                    >
                        <?php echo esc_html__('Submit', 'fluent-cart'); ?>
                    </button>
                </div>
            </div>
        <?php
    }
}
