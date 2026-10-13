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
import * as Icons from "@/BlockEditor/Icons";
import {Checkout} from "@/BlockEditor/Icons";
import SelectVariationModal from "@/BlockEditor/Components/ProductPicker/SelectVariationModal";
import SelectProductModal from "@/BlockEditor/Components/ProductPicker/SelectProductModal";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";
import Input from "@/BlockEditor/Components/Input";
import Empty from "@/BlockEditor/Components/Empty";
import ProductsProPanel from "./Components/ProductsProPanel";
import CheckoutPreview from "./Checkout.png";

/**
 * Shared editor components, published for add-ons.
 *
 * An add-on that extends this block's inspector (through the core
 * `editor.BlockEdit` filter) builds inside a separate bundle, so it cannot
 * import from here — and the product picker alone is eight files and about a
 * thousand lines. Re-implementing it would give authors two pickers that look
 * and behave differently in the same inspector, which is the outcome worth
 * avoiding.
 *
 * Treat this object's shape as public API: renaming or dropping a key breaks
 * every add-on that reads it. Consumers are expected to null-check, so a
 * version skew degrades to a disabled panel rather than a broken editor.
 */
window.fluentCartBlockEditor = window.fluentCartBlockEditor || {};
Object.assign(window.fluentCartBlockEditor, {
    SelectVariationModal,
    SelectProductModal,
    EditorPanelRow,
    Input,
    Empty,
    Icons,
    blocktranslate,
});

const {InspectorControls, useBlockProps, InnerBlocks} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {Button, CheckboxControl} = wp.components;
const blockEditorData = window.fluent_cart_checkout_data;

const DEFAULT_TEMPLATE = [
    ['core/columns', {}, [
        ['core/column', {width: 65}, [
            ['fluent-cart/checkout-name-fields', {lock: {remove: true, move: false}}],
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
            ['fluent-cart/checkout-summary'],
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

    /**
     * The product-selector settings, declared here and used nowhere in this
     * plugin.
     *
     * Nothing below reads them: render() ignores them, save() emits no
     * attribute at all, and the Pro teaser panel does not consult them. They
     * exist so the block's SERIALIZED SHAPE does not depend on which plugins
     * happen to be installed.
     *
     * WordPress keeps only declared keys when it parses a block comment, and
     * writes back only what it kept. Without these, an author who opens a
     * configured checkout while the Pro add-on is deactivated and saves the
     * page loses their product configuration silently and permanently — the
     * editor drops the keys on parse and omits them on save. Reinstalling does
     * not bring them back.
     *
     * The front end never needed this: WP_Block_Type::prepare_attributes_for_render()
     * passes undeclared attributes through verbatim, which is why a selector
     * page keeps rendering correctly with the add-on gone. This is purely about
     * the editor's parse-and-re-save round trip.
     *
     * Declared here rather than in blockAttributes() on the PHP side because
     * the JS settings win: processBlockType() merges the server-bootstrapped
     * definition first and this object second, so a PHP declaration would be
     * overwritten by whatever appears here.
     */
    attributes: {
        // No default on purpose. A block saved before the mode control existed
        // has no value, and both the panel and PHP's resolveCheckoutMode() then
        // infer the mode from variant_ids exactly as they did before.
        checkout_mode: {type: 'string'},
        variant_ids: {type: 'array', default: []},
        variations_data: {type: 'array', default: []},
        selection_mode: {type: 'string', default: 'single'},
        max_items: {type: 'number', default: 5},
        selector_label: {type: 'string'},
    },

    edit: function ({attributes, setAttributes}) {
        const blockProps = useBlockProps({className: 'fluent-cart-checkout-block-editor-wrap'});

        // Only when the add-on is absent. With it installed it registers its own
        // Products panel through editor.BlockEdit, and two panels of the same
        // name in one inspector is worse than none.
        const showProTeaser = !blockEditorData?.is_pro_active;

        return (
            <div {...blockProps}>
                {showProTeaser && (
                    <InspectorControls>
                        <ProductsProPanel attributes={attributes}/>
                    </InspectorControls>
                )}
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
