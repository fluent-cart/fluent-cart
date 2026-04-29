import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const { useBlockProps } = wp.blockEditor;
const CheckoutShippingMethodsBlock = {
    edit: (props) => {
        const blockProps = useBlockProps({
            className: 'fc-checkout-shipping-methods-block',
        });

        return <div { ...props } {...blockProps}>
            <div className="fc-checkout-section-title">
                {blocktranslate('Shipping Methods')}
            </div>
            <div className="fc-checkout-shipping-methods-list">
                <div className="fc-checkout-shipping-methods-list-item">
                    <input type="radio" name="shipping_method" checked disabled value="flat_rate" />
                    <label>{blocktranslate('Flat Rate')}</label>
                    <span className="fc-checkout-shipping-methods-list-item-price">
                        $10.00
                    </span>
                </div>
                <div className="fc-checkout-shipping-methods-list-item">
                    <input type="radio" name="shipping_method" disabled value="free_shipping" />
                    <label>{blocktranslate('Free Shipping')}</label>
                    <span className="fc-checkout-shipping-methods-list-item-price">
                        {blocktranslate('Free')}
                    </span>
                </div>

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

export default CheckoutShippingMethodsBlock;
