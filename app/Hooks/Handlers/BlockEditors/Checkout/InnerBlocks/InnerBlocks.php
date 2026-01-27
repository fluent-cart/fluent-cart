<?php

namespace FluentCart\App\Hooks\Handlers\BlockEditors\Checkout\InnerBlocks;

use FluentCart\Api\Contracts\CanEnqueue;
use FluentCart\Api\ModuleSettings;
use FluentCart\Api\StoreSettings;
use FluentCart\App\App;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Helpers\Helper;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\App\Services\Renderer\CartSummaryRender;
use FluentCart\App\Services\Renderer\CheckoutRenderer;
use FluentCart\App\Services\Translations\TransStrings;
use FluentCart\Framework\Support\Arr;
use FluentCartPro\App\Modules\Promotional\OrderBump\OrderBumpBoot;

class InnerBlocks
{
    use CanEnqueue;

    public static $parentBlock = 'fluent-cart/checkout';

    public array $blocks = [];

    private $cart = null;

    private function getCart()
    {
        if ($this->cart === null) {
            $this->cart = CartHelper::getCart();
        }
        return $this->cart;
    }


    public static function textBlockSupport(): array
    {
        return [
            'html'       => false,
            'align'      => ['left', 'center', 'right'],
            'typography' => [
                'fontSize'   => true,
                'lineHeight' => true
            ],
            'spacing'    => [
                'margin' => true,
                'padding' => true
            ],
            'color'      => [
                'text' => true,
            ],
            '__experimentalBorder' => [
                'radius' => true,
                'color'  => true,
                'width'  => true,
                'style'  => true
            ]
        ];
    }

    public static function buttonBlockSupport(): array
    {
        return [
            'html'       => false,
            'align'      => ['left', 'center', 'right'],
            'typography' => [
                'fontSize'      => true,
                'lineHeight'    => true,
                'fontWeight'    => true,
                'textTransform' => true,
            ],
            'spacing'    => [
                'margin'  => true,
                'padding' => true,
            ],
            'color'      => [
                'text'       => true,
                'background' => true,
            ],
            '__experimentalBorder' => [
                'radius' => true,
                'color'  => true,
                'width'  => true,
                'style'  => true
            ],
            'shadow'     => true,
        ];
    }


    public static function register()
    {
        $self = new self();
        $blocks = $self->getInnerBlocks();

        foreach ($blocks as $block) {

            register_block_type($block['slug'], [
                'apiVersion'      => 2,
                'api_version'     => 2,
                'version'         => 2,
                'title'           => $block['title'],
                'parent'          => array_merge($block['parent'] ?? [], [static::$parentBlock]),
                'render_callback' => $block['callback'],
                'supports'        => Arr::get($block, 'supports', []),
                'attributes'      => Arr::get($block, 'attributes', []),
                'uses_context'    => Arr::get($block, 'uses_context', []),
            ]);
        }

        add_action('enqueue_block_editor_assets', function () use ($self) {
            $self->enqueueScripts();
        });

    }

    public function getInnerBlocks(): array
    {
        return [
            [
                'title'     => __('Checkout Name Fields', 'fluent-cart'),
                'slug'      => 'fluent-cart/checkout-name-fields',
                'callback'  => [$this, 'renderCheckoutNameFields'],
                'component' => 'CheckoutNameFieldsBlock',
                'icon'      => 'editor-code',
                'supports'  => [
                    'html'       => false,
                    'typography' => [
                        'fontSize'   => false,
                        'lineHeight' => false
                    ],
                    'spacing'    => [
                        'margin' => true,
                        'padding' => true
                    ],
                    'color'      => [
                        'text' => false,
                        'background' => true,
                    ],
                ],
                'parent'    => [
                    'fluent-cart/checkout',
                    'core/column',
                    'core/group'
                ],
            ],
            [
                'title'     => __('Checkout Create Account Field', 'fluent-cart'),
                'slug'      => 'fluent-cart/checkout-create-account-field',
                'callback'  => [$this, 'renderCheckoutCreateAccountField'],
                'component' => 'CheckoutCreateAccountFieldBlock',
                'icon'      => 'editor-code',
                'supports'  => static::textBlockSupport(),
                'parent'    => [
                    'fluent-cart/checkout',
                    'core/column',
                    'core/group'
                ],
            ],
            [
                'title'     => __('Checkout Address Fields', 'fluent-cart'),
                'slug'      => 'fluent-cart/checkout-address-fields',
                'callback'  => [$this, 'renderCheckoutAddressFields'],
                'component' => 'CheckoutAddressFieldsBlock',
                'icon'      => 'editor-code',
                'supports'  => [
                    'html'       => false,
                    'typography' => [
                        'fontSize'   => false,
                        'lineHeight' => false
                    ],
                    'spacing'    => [
                        'margin' => true,
                        'padding' => true
                    ],
                    'color'      => [
                        'text' => false,
                        'background' => true,
                    ],
                    '__experimentalBorder' => [
                        'radius' => true,
                        'color'  => true,
                        'width'  => true,
                        'style'  => true
                    ]
                ],
                'parent'    => [
                    'fluent-cart/checkout',
                    'core/column',
                    'core/group'
                ],
            ],
            [
                'title'     => __('Billing Address Field', 'fluent-cart'),
                'slug'      => 'fluent-cart/checkout-billing-address-field',
                'callback'  => [$this, 'renderCheckoutBillingAddressField'],
                'component' => 'CheckoutBillingAddressFieldBlock',
                'icon'      => 'editor-code',
                'supports'  => [
                    'html'       => false,
                    'typography' => [
                        'fontSize'   => false,
                        'lineHeight' => false
                    ],
                    'spacing'    => [
                        'margin' => true,
                        'padding' => true
                    ],
                    'color'      => [
                        'text' => false,
                        'background' => true,
                    ],
                    '__experimentalBorder' => [
                        'radius' => true,
                        'color'  => true,
                        'width'  => true,
                        'style'  => true
                    ]
                ],
                'parent'    => [
                    'fluent-cart/checkout-address-fields'
                ],
            ],
            [
                'title'     => __('Shipping Address Field', 'fluent-cart'),
                'slug'      => 'fluent-cart/checkout-shipping-address-field',
                'callback'  => [$this, 'renderCheckoutShippingAddressField'],
                'component' => 'CheckoutShippingAddressFieldBlock',
                'icon'      => 'editor-code',
                'supports'  => [
                    'html'       => false,
                    'typography' => [
                        'fontSize'   => false,
                        'lineHeight' => false
                    ],
                    'spacing'    => [
                        'margin' => true,
                        'padding' => true
                    ],
                    'color'      => [
                        'text' => false,
                        'background' => true,
                    ],
                    '__experimentalBorder' => [
                        'radius' => true,
                        'color'  => true,
                        'width'  => true,
                        'style'  => true
                    ]
                ],
                'parent'    => [
                    'fluent-cart/checkout-address-fields'
                ],
            ],
            [
                'title' => __('Ship to Different Field', 'fluent-cart'),
                'slug'  => 'fluent-cart/checkout-ship-to-different-field',
                'callback'  => [$this, 'renderCheckoutShipToDifferentField'],
                'component' => 'CheckoutShipToDifferentFieldBlock',
                'icon'      => 'editor-code',
                'supports'  => [
                    'html'       => false,
                    'align'      => ['left', 'center', 'right'],
                    'typography' => [
                        'fontSize'   => true,
                        'lineHeight' => true
                    ],
                    'spacing'    => [
                        'margin' => true,
                        'padding' => true
                    ],
                    'color'      => [
                        'text' => true,
                    ],
                    '__experimentalBorder' => [
                        'radius' => true,
                        'color'  => true,
                        'width'  => true,
                        'style'  => true
                    ]
                ],
                'parent'    => [
                    'fluent-cart/checkout-address-fields'
                ],
            ],
            [
                'title'     => __('Checkout Shipping Methods', 'fluent-cart'),
                'slug'      => 'fluent-cart/checkout-shipping-methods',
                'callback'  => [$this, 'renderCheckoutShippingMethods'],
                'component' => 'CheckoutShippingMethodsBlock',
                'icon'      => 'editor-code',
                'supports'  => static::textBlockSupport(),
                'parent'    => [
                    'fluent-cart/checkout',
                    'core/column',
                    'core/group'
                ],
            ],
            [
                'title'     => __('Checkout Payment Methods', 'fluent-cart'),
                'slug'      => 'fluent-cart/checkout-payment-methods',
                'callback'  => [$this, 'renderCheckoutPaymentMethods'],
                'component' => 'CheckoutPaymentMethodsBlock',
                'icon'      => 'editor-code',
                'supports'  => static::textBlockSupport(),
                'parent'    => [
                    'fluent-cart/checkout',
                    'core/column',
                    'core/group'
                ],
            ],
            [
                'title'     => __('Checkout Agree Terms Field', 'fluent-cart'),
                'slug'      => 'fluent-cart/checkout-agree-terms-field',
                'callback'  => [$this, 'renderCheckoutAgreeTermsField'],
                'component' => 'CheckoutAgreeTermsFieldBlock',
                'icon'      => 'editor-code',
                'supports'  => [
                    'html'       => false,
                    'align'      => ['left', 'center', 'right'],
                    'typography' => [
                        'fontSize'   => true,
                        'lineHeight' => true
                    ],
                    'spacing'    => [
                        'margin' => true
                    ],
                    'color'      => [
                        'text' => true,
                    ]
                ],
                'parent'    => [
                    'fluent-cart/checkout',
                    'core/column',
                    'core/group'
                ],
            ],
            [
                'title'     => __('Checkout Submit Button', 'fluent-cart'),
                'slug'      => 'fluent-cart/checkout-submit-button',
                'callback'  => [$this, 'renderCheckoutSubmitButton'],
                'component' => 'CheckoutSubmitButtonBlock',
                'icon'      => 'editor-code',
                'supports'  => static::buttonBlockSupport(),
                'parent'    => [
                    'fluent-cart/checkout',
                    'core/column',
                    'core/group'
                ],
            ],
            [
                'title'     => __('Checkout Order Notes Field', 'fluent-cart'),
                'slug'      => 'fluent-cart/checkout-order-notes-field',
                'callback'  => [$this, 'renderCheckoutOrderNotesField'],
                'component' => 'CheckoutOrderNotesFieldBlock',
                'icon'      => 'editor-code',
                'supports'  => static::textBlockSupport(),
                'parent'    => [
                    'fluent-cart/checkout',
                    'core/column',
                    'core/group'
                ],
            ],
            [
                'title'     => __('Checkout Summary', 'fluent-cart'),
                'slug'      => 'fluent-cart/checkout-summary',
                'callback'  => [$this, 'renderCheckoutSummary'],
                'component' => 'CheckoutSummaryBlock',
                'icon'      => 'editor-code',
                'supports'  => static::textBlockSupport(),
                'parent'    => [
                    'fluent-cart/checkout',
                    'core/column',
                    'core/group'
                ],
            ],
            [
                'title'     => __('Order Summary', 'fluent-cart'),
                'slug'      => 'fluent-cart/checkout-order-summary',
                'callback'  => [$this, 'renderCheckoutOrderSummary'],
                'component' => 'CheckoutOrderSummaryBlock',
                'icon'      => 'editor-code',
                'supports'  => static::textBlockSupport(),
                'parent'    => [
                    'fluent-cart/checkout-summary'
                ],
            ],
            [
                'title'     => __('Summary Footer', 'fluent-cart'),
                'slug'      => 'fluent-cart/checkout-summary-footer',
                'callback'  => [$this, 'renderCheckoutSummaryFooter'],
                'component' => 'CheckoutSummaryFooterBlock',
                'icon'      => 'editor-code',
                'supports'  => static::textBlockSupport(),
                'parent'    => [
                    'fluent-cart/checkout-summary'
                ],
            ],
            [
                'title'     => __('Subtotal', 'fluent-cart'),
                'slug'      => 'fluent-cart/checkout-subtotal',
                'callback'  => [$this, 'renderCheckoutSubtotal'],
                'component' => 'CheckoutSubtotalBlock',
                'icon'      => 'editor-code',
                'supports'  => static::textBlockSupport(),
                'parent'    => [
                    'fluent-cart/checkout-summary-footer'
                ],
            ],
            [
                'title'     => __('Shipping', 'fluent-cart'),
                'slug'      => 'fluent-cart/checkout-shipping',
                'callback'  => [$this, 'renderCheckoutShipping'],
                'component' => 'CheckoutShippingBlock',
                'icon'      => 'editor-code',
                'supports'  => static::textBlockSupport(),
                'parent'    => [
                    'fluent-cart/checkout-summary-footer'
                ],
            ],
            [
                'title'     => __('Coupon', 'fluent-cart'),
                'slug'      => 'fluent-cart/checkout-coupon',
                'callback'  => [$this, 'renderCheckoutCoupon'],
                'component' => 'CheckoutCouponBlock',
                'icon'      => 'editor-code',
                'supports'  => static::textBlockSupport(),
                'parent'    => [
                    'fluent-cart/checkout-summary-footer'
                ],
            ],
            [
                'title'     => __('Manual Discount', 'fluent-cart'),
                'slug'      => 'fluent-cart/checkout-manual-discount',
                'callback'  => [$this, 'renderCheckoutManualDiscount'],
                'component' => 'CheckoutManualDiscountBlock',
                'icon'      => 'editor-code',
                'supports'  => static::textBlockSupport(),
                'parent'    => [
                    'fluent-cart/checkout-summary-footer'
                ],
            ],
            [
                'title'     => __('Tax', 'fluent-cart'),
                'slug'      => 'fluent-cart/checkout-tax',
                'callback'  => [$this, 'renderCheckoutTax'],
                'component' => 'CheckoutTaxBlock',
                'icon'      => 'editor-code',
                'supports'  => static::textBlockSupport(),
                'parent'    => [
                    'fluent-cart/checkout-summary-footer'
                ],
            ],
            [
                'title'     => __('Shipping Tax', 'fluent-cart'),
                'slug'      => 'fluent-cart/checkout-shipping-tax',
                'callback'  => [$this, 'renderCheckoutShippingTax'],
                'component' => 'CheckoutShippingTaxBlock',
                'icon'      => 'editor-code',
                'supports'  => static::textBlockSupport(),
                'parent'    => [
                    'fluent-cart/checkout-summary-footer'
                ],
            ],
            [
                'title'     => __('Total', 'fluent-cart'),
                'slug'      => 'fluent-cart/checkout-total',
                'callback'  => [$this, 'renderCheckoutTotal'],
                'component' => 'CheckoutTotalBlock',
                'icon'      => 'editor-code',
                'supports'  => static::textBlockSupport(),
                'parent'    => [
                    'fluent-cart/checkout-summary-footer'
                ],
            ],
            [
                'title'     => __('Checkout Order Bump', 'fluent-cart'),
                'slug'      => 'fluent-cart/checkout-order-bump',
                'callback'  => [$this, 'renderCheckoutOrderBump'],
                'component' => 'CheckoutOrderBumpBlock',
                'icon'      => 'editor-code',
                'supports'  => [
                    'html'       => false,
                    'align'      => ['left', 'center', 'right'],
                    'typography' => [
                        'fontSize'   => false,
                        'lineHeight' => false
                    ],
                    'spacing'    => [
                        'margin' => true,
                        'padding' => true
                    ],
                    'color'      => [
                        'text' => false,
                        'background' => true,
                    ],
                    '__experimentalBorder' => [
                        'radius' => true,
                        'color'  => true,
                        'width'  => true,
                        'style'  => true
                    ]
                ],
                'parent'    => [
                    'fluent-cart/checkout',
                    'core/column',
                    'core/group'
                ],
            ]
        ];
    }

    public function renderCheckoutNameFields($attributes, $content, $block)
    {
        $atts = get_block_wrapper_attributes();
        $cart = $this->getCart();
        if (empty(Arr::get($cart, 'cart_data', []))) {
            return '';
        }

        ob_start();
        ?>
        <div <?php echo $atts; ?>>
            <?php (new CheckoutRenderer($cart))->renderNameFields(); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function renderCheckoutCreateAccountField($attributes, $content, $block)
    {

        $atts = get_block_wrapper_attributes();
        $cart = $this->getCart();
        $title = Arr::get($attributes, 'create_account_title');
        if (empty(Arr::get($cart, 'cart_data', []))) {
            return '';
        }
        ob_start();
        ?>
        <div <?php echo $atts; ?>>
            <?php (new CheckoutRenderer($cart))->renderCreateAccountField([
                    'title'       => $title,
                    'wrapper_atts'=> $atts,
            ]); ?>
        </div>
        <?php

        return ob_get_clean();
    }

    public function renderCheckoutAddressFields($attributes, $content, $block)
    {
        $atts = get_block_wrapper_attributes();

        $cart = $this->getCart();
        if (empty(Arr::get($cart, 'cart_data', []))) {
            return '';
        }

        $innerBlocksContent = '';
        if ($block instanceof \WP_Block && !empty($block->inner_blocks)) {
            ob_start();
            ?>
            <div <?php echo $atts; ?>>
            <?php
            foreach ($block->inner_blocks as $inner_block) {
                if (isset($inner_block->parsed_block)) {
                    echo $inner_block->render();
                }
            } ?>
            </div>
            <?php
            $innerBlocksContent = ob_get_clean();
        }
        return $innerBlocksContent;
    }

    public function renderCheckoutBillingAddressField($attributes, $content, $block)
    {
        $atts = get_block_wrapper_attributes();
        $cart = $this->getCart();

        $addressTitle = Arr::get($attributes, 'addressTitle');

        if (empty(Arr::get($cart, 'cart_data', []))) {
            return '';
        }

        ob_start();
        ?>
        <div <?php echo $atts; ?>>
            <?php
            (new CheckoutRenderer($cart))->renderBillingAddressFields($addressTitle);
            ?>
        </div>
        <?php
        return ob_get_clean();

    }

    public function renderCheckoutShippingAddressField($attributes, $content, $block)
    {
        $atts = get_block_wrapper_attributes();
        $cart = $this->getCart();
        $addressTitle = Arr::get($attributes, 'addressTitle');
        if (empty(Arr::get($cart, 'cart_data', []))) {
            return '';
        }
        ob_start();
        ?>
        <div <?php echo $atts; ?>>
            <?php
            (new CheckoutRenderer($cart))->renderShippingAddressFields($addressTitle);
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function renderCheckoutShipToDifferentField($attributes, $content, $block)
    {
        $atts = get_block_wrapper_attributes();
        $cart = $this->getCart();
        $title = Arr::get($attributes, 'ship_to_different_title');
        if (empty(Arr::get($cart, 'cart_data', []))) {
            return '';
        }
        ob_start();
        ?>
        <div <?php echo $atts; ?>>
            <?php
            (new CheckoutRenderer($cart))->renderShipToDifferentField([
                    'title'       => $title,
                    'wrapper_atts'=> $atts,
            ]);
            ?>
        </div>
        <?php
        return ob_get_clean();

    }

    public function renderCheckoutShippingMethods($attributes, $content, $block)
    {
        $cart = $this->getCart();
        if (empty(Arr::get($cart, 'cart_data', []))) {
            return '';
        }
        $class = $cart->requireShipping() ? '' : 'is-hidden';
        $atts = get_block_wrapper_attributes([
            'class' => 'fct_checkout_shipping_methods ' . $class
        ]);
        ob_start();
        ?>
        <div <?php echo $atts; ?>>
            <?php
            (new CheckoutRenderer($cart))->renderShippingOptions();
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function renderCheckoutPaymentMethods($attributes, $content, $block)
    {
        $cart = $this->getCart();
        if (empty(Arr::get($cart, 'cart_data', []))) {
            return '';
        }
        $atts = get_block_wrapper_attributes([
            'class' => 'fct_checkout_payment_methods',
            'data-fluent-cart-checkout-payment-methods' => ''
        ]);
        ob_start(); ?>
        <div <?php echo $atts; ?>>
            <?php
            (new CheckoutRenderer($cart))->renderPaymentMethods();
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function renderCheckoutAgreeTermsField($attributes, $content, $block)
    {
        $cart = $this->getCart();
        if (empty(Arr::get($cart, 'cart_data', []))) {
            return '';
        }
        $title = Arr::get($attributes, 'terms_title');
        $atts = get_block_wrapper_attributes();
        ob_start();
        ?>
        <div <?php echo $atts; ?>>
            <?php
                (new CheckoutRenderer($cart))->agreeTerms([
                    'title'       => $title,
                    'wrapper_atts'=> $atts,
                ]);
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function renderCheckoutSubmitButton($attributes, $content, $block)
    {
        $cart = $this->getCart();
        if (empty(Arr::get($cart, 'cart_data', []))) {
            return '';
        }

        $atts = get_block_wrapper_attributes();
        ob_start();
        (new CheckoutRenderer($cart))->renderCheckoutButton($atts);
        return ob_get_clean();
    }

    public function renderCheckoutOrderNotesField($attributes, $content, $block)
    {
        $cart = $this->getCart();
        if (empty(Arr::get($cart, 'cart_data', []))) {
            return '';
        }
        $atts = get_block_wrapper_attributes();
        $title = Arr::get($attributes, 'title');
        ob_start();
        ?>
        <div <?php echo $atts; ?>>
            <?php (new CheckoutRenderer($cart))->renderOrderNoteField($title); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function renderCheckoutSummary($attributes, $content, $block)
    {
        $cart = $this->getCart();
        if (empty(Arr::get($cart, 'cart_data', []))) {
            return '';
        }
        $atts = get_block_wrapper_attributes([
            'class' => 'fct_checkout_summary'
        ]);

//        ob_start();
//        (new CartSummaryRender($cart))->render();
//        return ob_get_clean();

        $hasOrderSummaryBlock = false;
        $innerBlocksContent = '';
        if ($block instanceof \WP_Block && !empty($block->inner_blocks)) {
            ob_start();
            ?>
            <div <?php echo $atts; ?>>
                <div class="fct_summary active" data-fluent-cart-checkout-page-checkout-form-order-summary>
                    <div class="fct_summary_box" data-fluent-cart-checkout-page-cart-items-wrapper>
                        <div class="fct_checkout_form_section">
                            <?php
                            // Check if block fluent-cart/checkout-order-summary exists
                            foreach ($block->inner_blocks as $inner_block) {
                                if (
                                    isset($inner_block->parsed_block['blockName']) &&
                                    $inner_block->parsed_block['blockName'] === 'fluent-cart/checkout-order-summary'
                                ) {
                                    $hasOrderSummaryBlock = true;
                                    break;
                                }
                            }

                            if ($hasOrderSummaryBlock) {
                                (new CartSummaryRender($cart))->renderOrderSummarySectionHeading();
                            }
                            ?>
                            <div id="order_summary_panel" class="fct_form_section_body">
                                <div class="fct_form_section_body_inner">
                                    <?php
                                    foreach ($block->inner_blocks as $inner_block) {
                                        if (isset($inner_block->parsed_block)) {
                                            echo $inner_block->render();
                                        }
                                    } ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php
            $innerBlocksContent = ob_get_clean();
        }
        return $innerBlocksContent;
    }

    public function renderCheckoutOrderSummary($attributes, $content, $block)
    {
        $cart = $this->getCart();
        if (empty(Arr::get($cart, 'cart_data', []))) {
            return '';
        }
        $atts = get_block_wrapper_attributes([
                'class' => 'fct_items_wrapper',
                'data-fluent-cart-checkout-item-wrapper' => ''
        ]);
        ob_start();
//        (new CartSummaryRender($cart))->render();
        ?>
        <div <?php echo $atts; ?>>
            <?php
            (new CartSummaryRender($cart))->renderItemsLists();
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function renderCheckoutSummaryFooter($attributes, $content, $block)
    {
        $cart = $this->getCart();
        if (empty(Arr::get($cart, 'cart_data', []))) {
            return '';
        }
        $innerBlocksContent = '';
        if ($block instanceof \WP_Block && !empty($block->inner_blocks)) {
            ob_start();
            ?>
            <div class="fct_summary_items">
                <ul class="fct_summary_items_list">
                    <?php
                    foreach ($block->inner_blocks as $inner_block) {
                        if (isset($inner_block->parsed_block)) {
                            echo $inner_block->render();
                        }
                    }
                    ?>
                </ul>
            </div>
            <?php
            $innerBlocksContent = ob_get_clean();
        }

        return $innerBlocksContent;
    }

    public function renderCheckoutSubtotal($attributes, $content, $block)
    {
        $cart = $this->getCart();
        if (empty(Arr::get($cart, 'cart_data', []))) {
            return '';
        }
        $atts = get_block_wrapper_attributes();
        ob_start();
        (new CartSummaryRender($cart))->renderSubtotal($atts);
        return ob_get_clean();
    }

    public function renderCheckoutShipping($attributes, $content, $block)
    {
        $cart = $this->getCart();
        if (empty(Arr::get($cart, 'cart_data', []))) {
            return '';
        }
        $atts = get_block_wrapper_attributes([
            'class' => $cart->getShippingTotal() === 0 ? 'shipping-charge-hidden' : '',
            'data-fluent-cart-checkout-shipping-amount-wrapper' => ''
        ]);
        ob_start();
        (new CartSummaryRender($cart))->renderShipping($atts);
        return ob_get_clean();
    }

    public function renderCheckoutCoupon($attributes, $content, $block)
    {
        $cart = $this->getCart();
        if (empty(Arr::get($cart, 'cart_data', []))) {
            return '';
        }
        $atts = get_block_wrapper_attributes([
            'data-fluent-cart-checkout-page-applied-coupon' => ''
        ]);
        ob_start();
        ?>
        <li <?php echo $atts; ?>>
            <?php
            (new CartSummaryRender($cart))->showCouponField();
            ?>
        </li>
        <?php
        return ob_get_clean();
    }

    public function renderCheckoutManualDiscount($attributes, $content, $block)
    {
        $cart = $this->getCart();
        if (empty(Arr::get($cart, 'cart_data', []))) {
            return '';
        }
        $atts = get_block_wrapper_attributes();
        ob_start();
        (new CartSummaryRender($cart))->showManualDiscount($atts);
        return ob_get_clean();
    }

    public function renderCheckoutTax($attributes, $content, $block)
    {
        $cart = $this->getCart();
        if (empty(Arr::get($cart, 'cart_data', []))) {
            return '';
        }
        $atts = get_block_wrapper_attributes();
        ob_start();
        (new TaxModule())->renderTaxRow($cart, $atts);
        return ob_get_clean();
    }

    public function renderCheckoutShippingTax($attributes, $content, $block)
    {
        $cart = $this->getCart();
        if (empty(Arr::get($cart, 'cart_data', []))) {
            return '';
        }
        $atts = get_block_wrapper_attributes();
        ob_start();
        (new TaxModule())->renderShippingTaxRow($cart, $atts);
        return ob_get_clean();
    }

    public function renderCheckoutTotal($attributes, $content, $block)
    {
        $cart = $this->getCart();
        if (empty(Arr::get($cart, 'cart_data', []))) {
            return '';
        }
        $atts = get_block_wrapper_attributes([
            'class' => 'fct_summary_items_total',
            'data-fluent-cart-checkout-page-current-total' => ''
        ]);
        ob_start();
        (new CartSummaryRender($cart))->renderTotal($atts);
        return ob_get_clean();
    }

    public function renderCheckoutOrderBump($attributes, $content, $block)
    {
        if (!App::isProActive() || !ModuleSettings::isActive('order_bump')) {
            return '';
        }

        $cart = $this->getCart();
        if (empty(Arr::get($cart, 'cart_data', []))) {
            return '';
        }
        $atts = get_block_wrapper_attributes();
        $title = Arr::get($attributes, 'section_title');
        ob_start();
        ?>
        <div <?php echo $atts; ?>>
            <?php
            (new OrderBumpBoot())->maybeShowBumps([
                'cart' => $cart,
                'section_title' => $title
            ]);
            ?>
        </div>
        <?php
        return ob_get_clean();
    }


    protected function getLocalizationKey(): string
    {
        return 'fluent_cart_checkout_inner_blocks';
    }

    protected function localizeData(): array
    {
        return [
            $this->getLocalizationKey()      => [
                'blocks' => Arr::except($this->getInnerBlocks(), ['callback']),
            ],
            'fluentcart_single_product_vars' => [
                'trans'                      => TransStrings::singleProductPageString(),
                'cart_button_text'           => App::storeSettings()->get('cart_button_text', __('Add To Cart', 'fluent-cart')),
                'out_of_stock_button_text'   => App::storeSettings()->get('out_of_stock_button_text', __('Out of Stock', 'fluent-cart')),
                'in_stock_status'            => Helper::IN_STOCK,
                'out_of_stock_status'        => Helper::OUT_OF_STOCK,
                'enable_image_zoom'          => (new StoreSettings())->get('enable_image_zoom_in_single_product'),
                'enable_image_zoom_in_modal' => (new StoreSettings())->get('enable_image_zoom_in_modal')
            ]
        ];
    }


    protected function getStyles(): array
    {
        return [
            'public/checkout/style/checkout.scss',
            'public/components/select/style/style.scss'
        ];
    }

    protected function getScripts(): array
    {
        $scripts = [
            [
                'source'       => 'admin/BlockEditor/ReactSupport.js',
                'dependencies' => ['wp-blocks', 'wp-components']
            ],
            [
                'source'       => 'admin/BlockEditor/Checkout/InnerBlocks/InnerBlocks.jsx',
                'dependencies' => ['wp-blocks', 'wp-components', 'wp-block-editor']
            ]
        ];

        return $scripts;
    }

    protected function generateEnqueueSlug(): string
    {
        return 'fluent_cart_checkout_inner_blocks';
    }

}
