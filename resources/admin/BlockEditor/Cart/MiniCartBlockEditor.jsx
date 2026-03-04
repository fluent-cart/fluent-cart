import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import apiFetch from "@wordpress/api-fetch";
import {addQueryArgs} from "@wordpress/url";
import {useSingleProductData} from "@/BlockEditor/ShopApp/Context/SingleProductContext";
import {ShoppingBag, ShoppingBagAlt, ShoppingCart} from "@/BlockEditor/Icons";
import MiniCartInspectorSettings from "@/BlockEditor/Cart/Components/MiniCartInspectorSettings";


const {useBlockProps,  RichText} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useEffect, useState} = wp.element;
const {useSelect} = wp.data;
const {store: blockEditorStore} = wp.blockEditor;


const blockEditorData = window.fluent_cart_mini_cart_data;
const rest = window['fluentCartRestVars'].rest;
const fetchUrl = rest.url + '/products/variants/';

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: ShoppingCart,
    },
    category: "fluent-cart",
    attributes: {
        cart_icon: {
            type: 'string',
            default: 'cart'
        },
        show_total_price: {
            type: 'boolean',
            default: true
        },
        show_item_count: {
            type: 'string',
            default: 'has_items' // always | has_items | never
        },
        icon_color: {
            type: 'string',
            default: ''
        },
        price_color: {
            type: 'string',
            default: ''
        },
        product_count_color: {
            type: 'string',
            default: ''
        }
    },
    supports: {
        html: false,
        alignWide: false,
        typography: {
            fontSize: true,
            fontFamily: true,
            fontWeight: true,
            lineHeight: true
        },
        spacing: {
            margin: true,
            padding: true
        },
        reusable: false,
        shadow: false,
    },
    edit: ({ attributes, setAttributes, clientId }) => {
        const {cart_icon, show_total_price, show_item_count, icon_color, price_color, product_count_color} = attributes;
        const blockProps = useBlockProps();

        // Build inline styles
        const iconStyle = icon_color ? { color: icon_color } : {};
        const priceStyle = price_color ? { color: price_color } : {};
        const countStyle = product_count_color ? { backgroundColor: product_count_color } : {};

        const isCustomIcon = cart_icon && !['cart', 'bag', 'bag-alt'].includes(cart_icon);

        return (
            <div{...blockProps}>

                <MiniCartInspectorSettings
                    attributes={attributes}
                    setAttributes={setAttributes}
                    clientId={clientId}
                />


                <button className="fct-mini-cart-button">
                    <span className="fct-mini-cart-wrap" style={iconStyle}>
                        {isCustomIcon ? (
                            <img
                                src={cart_icon}
                                alt="Mini cart icon"
                                className="fct-mini-cart-custom-icon"
                                style={{
                                    width: '20px',
                                    height: '20px',
                                    objectFit: 'contain',
                                }}
                            />
                        ) : cart_icon === 'cart' ? (
                            <ShoppingCart />
                        ) : cart_icon === 'bag' ? (
                            <ShoppingBag />
                        ) : (
                            <ShoppingBagAlt />
                        )}


                        {show_item_count !== 'never' && (
                            <span className="fct-mini-cart-badge" style={countStyle}>0</span>
                        )}
                    </span>

                    {show_total_price && (
                        <span className="fct-mini-cart-amount" style={priceStyle}>$0.00</span>
                    )}
                </button>

            </div>
        );
    },

    save: function (props) {
        return null;
    },
});
