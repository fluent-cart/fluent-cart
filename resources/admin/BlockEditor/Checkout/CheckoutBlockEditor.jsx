import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import InputControl from "./Components/InputControl";
import AddressCard from "./Components/AddressCard";
import OrderSummary from "./Components/OrderSummary";
import InspectorSettings from "./Components/InspectorSettings";
import SubmitButton from "./Components/SubmitButton";
import ShippingMethods from "./Components/ShippingMethods";
import PaymentMethods from "./Components/PaymentMethods";
import AllowCreateAccount from "./Components/AllowCreateAccount";
import colorConfig from "@/BlockEditor/Checkout/colorConfig";
import {Checkout} from "@/BlockEditor/Icons";
import CheckoutPreview from "./Checkout.png";


const {InspectorControls, useBlockProps, InnerBlocks} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {Button, CheckboxControl} = wp.components;
const blockEditorData = window.fluent_cart_checkout_data;

const DEFAULT_TEMPLATE = [
    ['core/columns', {}, [
        ['core/column', {width: 65}, [
            ['fluent-cart/checkout-name-fields', {lock: {remove: true, move: false}}],
            ['fluent-cart/checkout-create-account-field', {
                style: {
                    typography: {
                        fontSize: '14px'
                    }
                }
            }],
            ['fluent-cart/checkout-address-fields'],
            ['fluent-cart/checkout-agree-terms-field', {
                style: {
                    typography: {
                        fontSize: '14px'
                    }
                }
            }],
            ['fluent-cart/checkout-shipping-methods'],
            ['fluent-cart/checkout-payment-methods'],
            ['fluent-cart/checkout-submit-button']
        ]],
        ['core/column', {width: 35}, [
            [
                'fluent-cart/checkout-summary',
                {},
                [
                    ['fluent-cart/checkout-order-summary'],
                    [
                        'fluent-cart/checkout-summary-footer',
                        {},
                        [
                            ['fluent-cart/checkout-subtotal'],
                            ['fluent-cart/checkout-shipping'],
                            ['fluent-cart/checkout-coupon'],
                            ['fluent-cart/checkout-manual-discount'],
                            ['fluent-cart/checkout-tax'],
                            ['fluent-cart/checkout-shipping-tax'],
                            ['fluent-cart/checkout-total']
                        ]
                    ]
                ]
            ],
            [
                'fluent-cart/checkout-order-notes-field'
            ],
            [
                'fluent-cart/checkout-order-bump'
            ]
        ]]
    ]]
];


registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    example: {
        attributes: {},
        innerBlocks: [
            {
                name: 'core/image',
                attributes: {
                    url: CheckoutPreview,
                    alt: 'Checkout Block Preview',
                    style: {
                        width: '100%',
                        height: 'auto',
                    }
                }
            },
        ],
    },
    icon: {
        src: Checkout,
    },
    category: "fluent-cart",
    attributes: {},

    edit: function ({attributes, setAttributes}) {
        const blockProps = useBlockProps({className: 'fluent-cart-checkout-block-editor-wrap'});

        return (
            <div {...blockProps}>
                <InnerBlocks
                    templateLock={false}
                    template={DEFAULT_TEMPLATE}
                    allowedBlocks={
                        [
                            'core/heading',
                            'core/columns',
                            'core/paragraph'
                        ]
                    }
                />
            </div>
        );
    },

    save: function (props) {
        const blockProps = useBlockProps.save({className: 'fluent-cart-checkout-block-editor-wrap'});

        return (
            <div {...blockProps}>
                <div className="fluent-cart-checkout-block-editor-inner">
                    <InnerBlocks.Content/>
                </div>
            </div>
        );
    },
});
