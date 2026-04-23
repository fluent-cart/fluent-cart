<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;

class ShippingMethodsRender
{
    protected $shippingMethods = [];

    protected $selectedId = null;

    public function __construct($shippingMethods = [], $selectedId = null)
    {
        $this->shippingMethods = $shippingMethods;
        $this->selectedId = $selectedId;
    }

    public function render()
    {
        $errorId = 'shipping-methods-error';

        ?>
        <div class="fct_shipping_methods" id="shipping_methods" data-fluent-cart-checkout-page-shipping-methods-wrapper>
            <div class="fct_checkout_form_section" aria-describedby="<?php echo esc_attr($errorId); ?>">
                <div class="fct_form_section_header">
                    <h4 id="shipping-methods-title" class="fct_form_section_header_label">
                        <?php echo esc_html__('Shipping Options', 'fluent-cart') ?>
                    </h4>
                </div>
                <div class="fct_form_section_body">
                    <?php $this->renderBody(); ?>
                </div>
                <span
                        id="<?php echo esc_attr($errorId); ?>"
                        data-fluent-cart-checkout-page-form-error=""
                        class="fct_form_error"
                        role="alert"
                        aria-live="polite"
                ></span>
            </div>
        </div>
        <?php
    }

    public function renderBody()
    {
        if (is_wp_error($this->shippingMethods)) {
            $this->renderEmpty($this->shippingMethods->get_error_message());
        } else if ($this->shippingMethods) {
            $this->renderMethods();
        } else {
            $this->renderEmptyState();
        }
    }

    public function renderEmptyState()
    {
        ?>
        <div class="fct-empty-state" role="alert">
            <?php echo esc_html__('No shipping methods available for this address.', 'fluent-cart') ?>
        </div>
        <?php
    }

    public function renderMethods()
    {
        if (is_wp_error($this->shippingMethods)) {
            return;
        }
        $errorId = 'shipping-methods-error';
        ?>
        <div
                class="fct_shipping_methods_list"
                data-fluent-cart-checkout-page-shipping-method-wrapper
                role="radiogroup"
                aria-labelledby="shipping-methods-title"
                aria-describedby="<?php echo esc_attr($errorId); ?>"
        >
            <?php $this->renderLoader(); ?>

            <input type="hidden" name="fc_selected_shipping_method" value="<?php echo esc_attr($this->selectedId); ?>">
            <?php foreach ($this->shippingMethods as $shippingMethod) : ?>
                <div class="fct_shipping_methods_item">
                    <input
                            type="radio"
                            <?php echo checked($this->selectedId, $shippingMethod->id); ?>
                            name="fc_shipping_method"
                            id="shipping_method_<?php echo esc_attr($shippingMethod->id); ?>"
                            value="<?php echo esc_attr($shippingMethod->id); ?>"
                    />
                    <label for="shipping_method_<?php echo esc_attr($shippingMethod->id); ?>">
                        <?php
                        $description = Arr::get($shippingMethod->meta, 'description', '');
                        ?>
                        <?php echo esc_html($shippingMethod->title); ?>
                        <span class="shipping-method-amount" aria-label="<?php
                        /* translators: %s charge amount */
                        printf(esc_attr__('Shipping cost: %s', 'fluent-cart'),
                                esc_html(Helper::toDecimal($shippingMethod->charge_amount))); ?>"
                        >
                            <?php echo esc_html(Helper::toDecimal($shippingMethod->charge_amount)); ?>
                        </span>
                        <span class="fct-checkmark" aria-hidden="true"></span>
                        <?php if (!empty($description)) : ?>
                            <small class="fct_shipping_method_description"><?php echo esc_html($description); ?></small>
                        <?php endif; ?>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    public function renderEmpty($message)
    {
        ?>
        <div class="fct-empty-state" role="alert">
            <?php echo wp_kses_post($message); ?>
        </div>
        <?php
    }

    public function renderLoader()
    {
        ?>
            <div class="fct_shipping_methods_loader">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24">
                    <circle cx="12" cy="12" r="10" opacity="0.2" fill="none" stroke="currentColor" stroke-miterlimit="10" stroke-width="2.5"></circle>

                    <path d="m12,2c5.52,0,10,4.48,10,10" fill="none" stroke="currentColor" stroke-linecap="round" stroke-miterlimit="10" stroke-width="2.5">
                        <animateTransform attributeName="transform" attributeType="XML" type="rotate" dur="0.5s" from="0 12 12" to="360 12 12" repeatCount="indefinite"></animateTransform>
                    </path>
                </svg>
            </div>
        <?php
    }

    /**
     * Get inline SVG icon for a package type.
     */
    public static function getPackageTypeIcon($type)
    {
        $style = 'width="14" height="14" viewBox="0 0 16 16" fill="currentColor" style="vertical-align: -2px; opacity: 0.45;"';

        $icons = [
            'box' => '<svg xmlns="http://www.w3.org/2000/svg" ' . $style . '><path fill-rule="evenodd" d="M5 7a1 1 0 0 0-1 1v3a1 1 0 0 0 1 1h4a1 1 0 0 0 1-1v-3a1 1 0 0 0-1-1zm.5 3.5v-2h3v2z"></path><path fill-rule="evenodd" d="M3.315 2.45a2.25 2.25 0 0 1 1.836-.95h5.796c.753 0 1.455.376 1.872 1.002l1.22 1.828c.3.452.461.983.461 1.526v6.894a1.75 1.75 0 0 1-1.75 1.75h-9.5a1.75 1.75 0 0 1-1.75-1.75v-6.863c0-.57.177-1.125.506-1.59l1.309-1.848Zm1.836.55a.75.75 0 0 0-.612.316l-.839 1.184h3.55v-1.5zm3.599 1.5h3.599l-.778-1.166a.75.75 0 0 0-.624-.334h-2.197zm4.25 1.5h-10v6.75c0 .138.112.25.25.25h9.5a.25.25 0 0 0 .25-.25z"></path></svg>',
            'envelope' => '<svg xmlns="http://www.w3.org/2000/svg" ' . $style . '><path fill-rule="evenodd" d="M3.75 2.5a2.75 2.75 0 0 0-2.75 2.75v5.5a2.75 2.75 0 0 0 2.75 2.75h8.5a2.75 2.75 0 0 0 2.75-2.75v-5.5a2.75 2.75 0 0 0-2.75-2.75zm-1.25 2.75c0-.69.56-1.25 1.25-1.25h8.5c.69 0 1.25.56 1.25 1.25v5.5c0 .69-.56 1.25-1.25 1.25h-8.5c-.69 0-1.25-.56-1.25-1.25zm2.067.32a.75.75 0 0 0-.634 1.36l3.538 1.651c.335.156.723.156 1.058 0l3.538-1.651a.75.75 0 0 0-.634-1.36l-3.433 1.602z"></path></svg>',
            'soft_package' => '<svg xmlns="http://www.w3.org/2000/svg" ' . $style . '><path d="M4 7.5a.75.75 0 0 0 0 1.5h1a.75.75 0 0 0 0-1.5z"></path><path d="M3.25 10.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 0 1.5h-3a.75.75 0 0 1-.75-.75"></path><path fill-rule="evenodd" d="M3.25 2a2.75 2.75 0 0 0-2.75 2.75v6.5a2.75 2.75 0 0 0 2.75 2.75h9.5a2.75 2.75 0 0 0 2.75-2.75v-6.5a2.75 2.75 0 0 0-2.75-2.75zm7.184 1.5h-7.184c-.69 0-1.25.56-1.25 1.25v6.5c0 .69.56 1.25 1.25 1.25h7.184l-.5-.273a2.75 2.75 0 0 1-1.434-2.414v-3.626c0-1.006.55-1.932 1.433-2.414zm3.566 7.552v-6.104a1 1 0 0 0-1.479-.878l-1.87 1.02a1.25 1.25 0 0 0-.651 1.097v3.626c0 .457.25.878.651 1.097l1.87 1.02a1 1 0 0 0 1.479-.878"></path></svg>',
        ];

        return $icons[$type] ?? $icons['box'];
    }
}
