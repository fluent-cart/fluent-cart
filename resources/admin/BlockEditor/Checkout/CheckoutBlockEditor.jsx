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


const {InspectorControls} = wp.blockEditor;
const {useBlockProps} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {Button, CheckboxControl} = wp.components;
const blockEditorData = window.fluent_cart_checkout_data;


registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
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
    attributes: {
        shipToDifferent: {
            type: 'boolean',
            default: false,
        },
        labels: {
            type: 'object',
            default: {
                billing_address: blocktranslate('Billing Address'),
                billing_label: blocktranslate('Address Label'),
                billing_full_name: blocktranslate('Full Name'),
                billing_name: blocktranslate('Name'),
                billing_email: blocktranslate('Email'),
                billing_phone: blocktranslate('Phone'),
                billing_company_name: blocktranslate('Company Name'),
                billing_country: blocktranslate('Country/Region'),
                billing_address_1: blocktranslate('Full Address'),
                billing_city: blocktranslate('Town/City'),
                billing_state: blocktranslate('State'),
                billing_postcode: blocktranslate('Postcode'),

                shipping_address: blocktranslate('Shipping Address'),
                shipping_label: blocktranslate('Address Label'),
                shipping_full_name: blocktranslate('Full Name'),
                shipping_name: blocktranslate('Name'),
                shipping_phone: blocktranslate('Phone'),
                shipping_company_name: blocktranslate('Company Name'),
                shipping_country: blocktranslate('Country/Region'),
                shipping_address_1: blocktranslate('Full Address'),
                shipping_city: blocktranslate('Town/City'),
                shipping_state: blocktranslate('State'),
                shipping_postcode: blocktranslate('Postcode'),
            }
        },
        colors: {
            type: 'object',
            default: colorConfig
        },
        coupons: {
            type: 'object',
            default: {
                label: blocktranslate('Have a Coupon?'),
                placeholder: blocktranslate('Apply Here'),
                applyButton: blocktranslate('Apply'),
                iconVisibility: true,
                collapsible: true,
            }
        },
        submitButton: {
            type: 'object',
            default: {
                text: blocktranslate('Place Order'),
                size: 'large',
                full: true,
                alignment: 'left',
            }
        },
        orderSummary: {
            type: 'object',
            default: {
                heading: blocktranslate('Summary'),
                toggleButtonText: blocktranslate('View Items'),
                removeButtonText: blocktranslate('Remove'),
                totalText: blocktranslate('Total'),
                maxVisibleItems: 2,
                showRemoveButton: true,
            }
        },
        shippingMethods: {
            type: 'object',
            default: {
                heading: blocktranslate('Shipping Methods'),
            }
        },
        paymentMethods: {
            type: 'object',
            default: {
                heading: blocktranslate('Payment Methods'),
            }
        },
        allowCreateAccount: {
            type: 'object',
            default: {
                label: blocktranslate('Create my user account'),
                infoText: blocktranslate('By checking this box, you agree to create an account with us to manage your subscription and order details. This is mandatory for subscription-based purchases.'),
                checked: false,
            }
        },
        addressModal: {
            type: 'object',
            default: {
                billingAddress: blocktranslate('Billing Address'),
                shippingAddress: blocktranslate('Shipping Address'),
                addButtonText: blocktranslate('Add Address'),
                applyButtonText: blocktranslate('Apply'),
                openButtonText: blocktranslate('Change'),
                submitButtonText: blocktranslate('Submit'),
                cancelButtonText: blocktranslate('Cancel'),
            }
        },
        showOrderNotes: {
            type: 'boolean',
            default: false,
        }
    },

    edit: function ({attributes, setAttributes}) {
        const blockProps = useBlockProps();


        const updateLabel = (type, field, value) => {

        };

        const updateHeading = (type, value) => {
            const key = `${type}_address`; // billing_address or shipping_address
            setAttributes({
                labels: {
                    ...attributes.labels,
                    [key]: value
                }
            });
        };

        return (
            <div {...blockProps}>

                <div className="fluent-cart-checkout-block-editor">
                    <div className="fluent-cart-checkout-block-editor-row">
                        <div className="fluent-cart-checkout-block-editor-main">
                            <div className="fluent-cart-checkout-block-editor-input-group">
                                <InputControl
                                    label={attributes.labels.billing_full_name || blocktranslate('Full Name')}
                                    required={true}
                                    onLabelChange={(val) => updateLabel('billing', 'full_name', val)}
                                />

                                <InputControl
                                    label={attributes.labels.billing_email || blocktranslate('Email')}
                                    required={true}
                                    onLabelChange={(val) => updateLabel('billing', 'email', val)}
                                />
                            </div>

                            <AllowCreateAccount
                                attributes={attributes}
                                setAttributes={setAttributes}
                            />

                            <AddressCard
                                heading={attributes.labels.billing_address || blocktranslate('Billing Address')}
                                onHeadingChange={updateHeading}
                                labels={attributes.labels}
                                updateLabel={updateLabel}
                            />

                            <div className="fluent-cart-checkout-block-editor-ship-to-different">
                                <CheckboxControl
                                    label={blocktranslate('Ship to different address')}
                                    checked={attributes.shipToDifferent}
                                    onChange={(checked) => setAttributes({shipToDifferent: checked})}
                                />
                            </div>

                            {attributes.shipToDifferent && (
                                <AddressCard
                                    heading={attributes.labels.shipping_address || blocktranslate('Shipping Address')}
                                    onHeadingChange={updateHeading}
                                    type="shipping"
                                    className="shipping-address"
                                    labels={attributes.labels}
                                    updateLabel={updateLabel}
                                />
                            )}

                            <ShippingMethods
                                attributes={attributes}
                                setAttributes={setAttributes}
                            />

                            <PaymentMethods
                                attributes={attributes}
                                setAttributes={setAttributes}
                            />

                            <SubmitButton attributes={attributes}/>

                        </div>

                        <div className="fluent-cart-checkout-block-editor-sidebar">
                            <OrderSummary
                                attributes={attributes}
                                setAttributes={setAttributes}
                            />

                            <div className="fluent-cart-checkout-block-editor-order-notes">
                                <div
                                    onClick={() => setAttributes({showOrderNotes: !attributes.showOrderNotes})}
                                    className={`order-notes-toggle-button ${attributes.showOrderNotes ? 'active' : ''}`}>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
                                         fill="none">
                                        <path d="M15.6 12.0001L10.2 17.4001V6.6001L15.6 12.0001Z" fill="#565865"></path>
                                    </svg>

                                    {blocktranslate('Leave a Note')}
                                </div>

                                {attributes.showOrderNotes && (
                                    <InputControl type="textarea"/>
                                )}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        );
    },

    save: function (props) {
        return null;
    },
});
