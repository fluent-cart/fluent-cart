import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {Notice} = wp.components;
const { RichText } = wp.blockEditor;

const ShippingMethods = ({attributes, setAttributes}) => {


    return (
        <div className="fluent-cart-checkout-block-editor-card fluent-cart-checkout-page-shipping-method-wrapper">
            <div className="fluent-cart-checkout-block-editor-card-header">
                <h3 className="fluent-cart-checkout-block-editor-card-title">
                    <RichText
                        tagName="span"
                        value={attributes.shippingMethods.heading}
                        onChange={(val) => setAttributes({
                            shippingMethods: {
                                ...attributes.shippingMethods,
                                heading: val
                            }
                        })}
                    />
                </h3>
            </div>
            <div className="fluent-cart-checkout-block-editor-card-body">
                <Notice
                    status="warning"
                    isDismissible={false}
                >
                    <p>{blocktranslate('Please preview your form on the front-end to view shipping methods.')}</p>
                </Notice>
            </div>
        </div>
    );
};

export default ShippingMethods;
