import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const { useBlockProps, RichText } = wp.blockEditor;
const CheckoutOrderBumpBlock = {
    attributes: {
        section_title: {
            type: 'string',
            default: blocktranslate('Recommended')
        }
    },
    edit: (props) => {
        const { attributes, setAttributes } = props;
        const blockProps = useBlockProps({
            className: 'fc-checkout-order-bump-block',
        });

        return <div {...props} {...blockProps}>
            <div className="fc-checkout-section-title">
                <RichText
                    tagName="span"
                    value={attributes.section_title}
                    onChange={(value) => setAttributes({ section_title: value })}
                    placeholder={blocktranslate('Recommended')}
                    allowedFormats={['core/bold', 'core/italic']}
                />
            </div>

            <div className="fct_form_section_body">
                <div className="fct_order_promotions">
                    <div className="fct_order_promotion">
                        <div className="fct_order_promotion_checkbox">
                            <input type="checkbox" disabled className="fct_order_promotion_input" id="fct_order_promotion_1"/>
                        </div>
                        <div className="fct_bump_details">
                            <label htmlFor="fct_order_promotion_1" className="fct_order_promotion_label">
                                <div className="fct_order_promotion_title">
                                    Product Title
                                </div>
                                <div className="fct_order_promotion_product_title">
                                    Variation Title
                                </div>
                                <div className="fct_order_promotion_price">
                                    <span className="fct_order_promotion_price">
                                        $0.00
                                    </span>
                                </div>
                            </label>
                        </div>
                    </div>

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

export default CheckoutOrderBumpBlock;
