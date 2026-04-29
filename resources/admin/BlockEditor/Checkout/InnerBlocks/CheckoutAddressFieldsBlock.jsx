import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const { useBlockProps,InnerBlocks } = wp.blockEditor;

const DEFAULT_TEMPLATE = [
    ['fluent-cart/checkout-billing-address-field'],
    ['fluent-cart/checkout-ship-to-different-field', {
        style: {
            typography: {
                fontSize: '14px'
            }
        }
    }],
    ['fluent-cart/checkout-shipping-address-field']
];

const CheckoutAddressFieldsBlock = {
    edit: (props) => {
        const blockProps = useBlockProps({
            className: 'fc-checkout-address-fields-block',
        });

        return <div { ...props } {...blockProps}>
            <InnerBlocks
                template={DEFAULT_TEMPLATE}
                templateLock={false}
                allowedBlocks={
                    [
                        'core/heading',
                        'core/paragraph',
                        'fluent-cart/checkout-billing-address-field',
                        'fluent-cart/checkout-shipping-address-field',
                        'fluent-cart/checkout-ship-to-different-field'
                    ]
                }
            />
        </div>;
    },
    save: (props) => {
        const blockProps = useBlockProps.save({className: 'fct_checkout_address_fields'});

        return (
            <div {...blockProps}>
                <InnerBlocks.Content/>
            </div>
        );
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

export default CheckoutAddressFieldsBlock;
