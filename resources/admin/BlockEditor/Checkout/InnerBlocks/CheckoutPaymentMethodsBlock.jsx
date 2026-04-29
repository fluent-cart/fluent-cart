import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const { useBlockProps } = wp.blockEditor;
const {Notice} = wp.components;

const CheckoutPaymentMethodsBlock = {
    edit: (props) => {
        const blockProps = useBlockProps({
            className: 'fc-checkout-payment-methods-block',
        });

        return <div { ...props } {...blockProps}>
            <div className="fc-checkout-section-title">
                {blocktranslate('Payment')}
            </div>
            <div className="fc-checkout-payment-methods-list">
                <Notice
                    status="warning"
                    isDismissible={false}
                >
                    <div>{blocktranslate('Please preview your form on the front-end to view payment methods.')}</div>
                </Notice>
            </div>
        </div>;
    },
    save: (props) => {
        return null;
    },
    supports: {
        html: false,
        align: ["left", "center", "right"],
        typography: {
            fontSize: true,
            lineHeight: true
        },
        spacing: {
            margin: true
        },
        color: {
            text: true
        }
    },
    category: "fluent-cart"
};

export default CheckoutPaymentMethodsBlock;
