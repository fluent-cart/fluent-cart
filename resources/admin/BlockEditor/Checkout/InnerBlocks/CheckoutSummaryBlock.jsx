const blockEditorData = window.fluent_cart_checkout_data;
const {useBlockProps, InnerBlocks} = wp.blockEditor;
const {__} = wp.i18n;

const CheckoutSummaryBlock = {
    deprecated: [
        {
            save: () => {
                const blockProps = useBlockProps.save({className: 'fct_checkout_summary'});
                return (
                    <div {...blockProps}>
                        <InnerBlocks.Content/>
                    </div>
                );
            },
        }
    ],
    edit: () => {
        const blockProps = useBlockProps({
            className: 'fc-checkout-summary-block',
        });

        return (
            <div {...blockProps}>
                <div className="fct_checkout_summary" style={{position: 'relative'}}>
                    <span style={{
                        position: 'absolute', top: '8px', right: '8px',
                        background: '#e0e0e0', color: '#555', fontSize: '11px',
                        padding: '2px 8px', borderRadius: '3px', lineHeight: '1.4',
                    }}>{__('Preview', 'fluent-cart')}</span>
                    <div className="fct_summary active">
                        <div className="fct_summary_box">
                            <div className="fct_checkout_form_section">
                                <div className="fct_form_section_header">
                                    <div className="fct_toggle_content">
                                        <h4>{__('Order summary', 'fluent-cart')}</h4>
                                        <div className="fct_toggle_icon">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="6"
                                                 viewBox="0 0 10 6" fill="none">
                                                <path
                                                    d="M1 1L4.29289 4.29289C4.62623 4.62623 4.79289 4.79289 5 4.79289C5.20711 4.79289 5.37377 4.62623 5.70711 4.29289L9 1"
                                                    stroke="currentColor" strokeWidth="1.5" strokeLinecap="round"
                                                    strokeLinejoin="round"/>
                                            </svg>
                                        </div>
                                    </div>
                                    <div className="fct_summary_toggle_total">
                                        <span className="value">$0.00</span>
                                    </div>
                                </div>
                                <div className="fct_form_section_body">
                                    <div className="fct_items_wrapper">
                                        <div className="fct_line_items">
                                            <div className="fct_line_item fct_has_image">
                                                <div className="fct_line_item_info">
                                                    <div className="fct_item_image">
                                                        <img src={blockEditorData.placeholder_image}
                                                             alt={__('Product Image', 'fluent-cart')}/>
                                                    </div>
                                                    <div className="fct_item_content">
                                                        <div className="fct_item_title">
                                                            {__('Product Title', 'fluent-cart')}
                                                            <div className="fct_item_variant_title">
                                                                {'– ' + __('Variation Title', 'fluent-cart')}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div className="fct_line_item_price">
                                                    <span className="fct_line_item_total">$0.00</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="fct_summary_items">
                                        <ul className="fct_summary_items_list">
                                            <li>
                                                <span className="fct_summary_label">{__('Subtotal', 'fluent-cart')}</span>
                                                <span className="fct_summary_value">$0.00</span>
                                            </li>
                                            <li>
                                                <span className="fct_summary_label">{__('Shipping', 'fluent-cart')}</span>
                                                <span className="fct_summary_value">$0.00</span>
                                            </li>
                                            <li>
                                                <div className="fct_coupon">
                                                    <div className="fct_coupon_toggle">
                                                        <a href="#">{__('Have a Coupon?', 'fluent-cart')}</a>
                                                    </div>
                                                </div>
                                            </li>
                                            <li>
                                                <span className="fct_summary_label">{__('Tax Estimate (Included)', 'fluent-cart')}</span>
                                                <span className="fct_summary_value">$0.00</span>
                                            </li>
                                            <li className="fct_summary_items_total">
                                                <span className="fct_summary_label">{__('Total', 'fluent-cart')}</span>
                                                <span className="fct_summary_value">$0.00</span>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        );
    },
    save: () => {
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

export default CheckoutSummaryBlock;
