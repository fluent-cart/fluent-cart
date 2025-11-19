<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors;


use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\App\Hooks\Handlers\ShortCodes\Checkout\CheckoutPageHandler;
use FluentCart\Framework\Support\Arr;


class CheckoutBlockEditor extends BlockEditor
{
    protected static string $editorName = 'checkout';

    protected function getScripts(): array
    {
        return [
            [
                'source'       => 'admin/BlockEditor/Checkout/CheckoutBlockEditor.jsx',
                'dependencies' => ['wp-blocks', 'wp-components']
            ]
        ];
    }

    protected function getStyles(): array
    {
        return ['admin/BlockEditor/Checkout/style/checkout-block-editor.scss'];
    }

    protected function localizeData(): array
    {

        return [
            $this->getLocalizationKey()     => [
                'slug'        => $this->slugPrefix,
                'name'        => static::getEditorName(),
                'title'       => __('Checkout Page', 'fluent-cart'),
                'description' => __('This block will display the checkout page.', 'fluent-cart')
            ],
            'fluent_cart_block_translation' => TransStrings::blockStrings()
        ];
    }

    public function render(array $shortCodeAttribute, $block = null): string
    {
        $attributes = $block ? $block->attributes : [];

        $cssClasses = [
            Arr::get($attributes, 'className', '')
        ];

        $align = Arr::get($attributes, 'align', '');
        if ($align) {
            $cssClasses[] = 'fct_has_align align' . $align;
        }

        add_filter('fluent_cart/checkout_page_css_classes', function ($classes) use ($cssClasses) {
            if(!is_array($cssClasses)) {
                $cssClasses = [];
            }

            if (empty($cssClasses)) {
                return $classes;
            }
            foreach ($cssClasses as $class) {
                $classes[] = $class;
            }
            return $classes;
        });

        return '[fluent_cart_checkout]';
    }

    /**
     * Returns the default `addressModal`
     *
     * @return array
     */
    public static function getDefaultAddressModal(): array
    {
        return [
            'billingAddress'   => __('Billing Address', 'fluent-cart'),
            'shippingAddress'  => __('Shipping Address', 'fluent-cart'),
            'openButtonText'   => __('Change', 'fluent-cart'),
            'addButtonText'    => __('Add Address', 'fluent-cart'),
            'applyButtonText'  => __('Apply', 'fluent-cart'),
            'submitButtonText' => __('Submit', 'fluent-cart'),
            'cancelButtonText' => __('Cancel', 'fluent-cart')
        ];
    }

    /**
     * Returns the default `SippingMethods`
     *
     * @return array
     */
    public static function getDefaultSippingMethods(): array
    {
        return [
            'heading' => __('Shipping Method', 'fluent-cart')
        ];
    }

    /**
     * Returns the default `PaymentMethods`
     *
     * @return array
     */
    public static function getDefaultPaymentMethods(): array
    {
        return [
            'heading' => __('Payment', 'fluent-cart')
        ];
    }

    /**
     * Returns the default `orderSummary`
     *
     * @return array
     */
    public static function getDefaultOrderSummary(): array
    {
        return [
            'toggleButtonText' => __('View Items', 'fluent-cart'),
            'removeButtonText' => __('Remove', 'fluent-cart'),
            'totalText'        => __('Total', 'fluent-cart'),
            'heading'          => __('Summary', 'fluent-cart'),
            'maxVisibleItems'  => 2,
            'showRemoveButton' => true,
            'coupons'          => self::getDefaultCoupons()
        ];
    }

    /**
     * Returns the default `coupons`
     *
     * @return array
     */
    public static function getDefaultCoupons(): array
    {
        return [
            'iconVisibility' => true,
            'placeholder'    => __('Apply Here', 'fluent-cart'),
            'applyButton'    => __('Apply', 'fluent-cart'),
            'label'          => __('Have a Coupon?', 'fluent-cart'),
            'collapsible'    => true
        ];
    }

    /**
     * Returns the default `submitButton`
     *
     * @return array
     */
    public static function getDefaultSubmitButton(): array
    {
        return [
            'text'      => __('Place Order', 'fluent-cart'),
            'alignment' => 'left',
            'size'      => 'large',
            'full'      => true
        ];
    }

    /**
     * Returns the default `AllowCreateAccount`
     *
     * @return array
     */
    public static function getDefaultAllowCreateAccount(): array
    {
        return [
            'label'    => __('Create my user account', 'fluent-cart'),
            'infoText' => __('By checking this box, you agree to create an account with us to manage your subscription and order details. This is mandatory for subscription-based purchases.', 'fluent-cart')
        ];
    }

    /**
     * Safely encode JSON strings if needed
     *
     * @param mixed $value The value to process
     * @return mixed The processed value (encoded if it was an array or object)
     */
    protected function maybeEncodeJson($value)
    {
        if (is_array($value) || is_object($value)) {
            return json_encode($value);
        }
        return $value;
    }

}
