import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {Notice} = wp.components;
const { RichText } = wp.blockEditor;

const PaymentMethods = ({attributes, setAttributes}) => {


    return (
        <div className="fluent-cart-checkout-block-editor-card">
            <div className="fluent-cart-checkout-block-editor-card-header">
                <h3 className="fluent-cart-checkout-block-editor-card-title">
                    <RichText
                        tagName="span"
                        value={attributes.paymentMethods.heading}
                        onChange={(val) => setAttributes({
                            paymentMethods: {
                                ...attributes.paymentMethods,
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
                    <p>{blocktranslate('Please preview your form on the front-end to view payment methods.')}</p>
                </Notice>
            </div>
        </div>
    );
};

export default PaymentMethods;
