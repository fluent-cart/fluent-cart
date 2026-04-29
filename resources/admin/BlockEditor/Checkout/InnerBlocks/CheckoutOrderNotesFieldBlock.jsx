import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const { useBlockProps, RichText } = wp.blockEditor;
const CheckoutOrderNotesFieldBlock = {
    attributes: {
        title: {
            type: 'string',
            default: blocktranslate('Leave a Note')
        }
    },
    edit: (props) => {
        const { attributes, setAttributes } = props;
        const blockProps = useBlockProps({
            className: 'fc-checkout-order-notes-block',
        });

        return <div { ...props } {...blockProps}>
            <div className="fct-toggle-field fct_order_note" id="fct_wrapper_order_notes" data-fct-item-toggle="">
                <label htmlFor="order_notes" data-fct-item-toggle-control=""
                       className="fct-toggle-control fct_order_note_toggle">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none">
                        <path d="M15.6 12.0001L10.2 17.4001V6.6001L15.6 12.0001Z" fill="currentColor"></path>
                    </svg>
                    <RichText
                        tagName="span"
                        value={attributes.title}
                        onChange={(value) => setAttributes({ title: value })}
                        placeholder={blocktranslate('Leave a Note')}
                        allowedFormats={['core/bold', 'core/italic']}
                    />
                </label>
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

export default CheckoutOrderNotesFieldBlock;
