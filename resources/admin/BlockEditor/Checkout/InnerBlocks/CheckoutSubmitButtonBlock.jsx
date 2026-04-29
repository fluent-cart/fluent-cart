import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const { useBlockProps } = wp.blockEditor;
const CheckoutSubmitButtonBlock = {
    edit: (props) => {
        const blockProps = useBlockProps({
            className: 'fc-checkout-submit-button-block fct_place_order_btn_wrap fc-checkout-place-order-btn large',
            type: 'submit',
            'data-loading': ''
        });

        return <button { ...props } {...blockProps}>
                {blocktranslate('Place Order')}
            </button>;
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

export default CheckoutSubmitButtonBlock;
