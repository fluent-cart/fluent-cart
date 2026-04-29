import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const { useBlockProps, RichText } = wp.blockEditor;

const CheckoutShipToDifferentFieldBlock = {
    attributes: {
        ship_to_different_title: {
            type: 'string',
            default: blocktranslate('Ship to a different address?')
        }
    },
    edit: (props) => {
        const { attributes, setAttributes } = props;
        const blockProps = useBlockProps({
            className: 'fc-checkout-create-account-block',
        });

        return <div { ...props } {...blockProps}>
            <label htmlFor="allow_create_account">
                <input type="checkbox" name="allow_create_account" readOnly disabled placeholder={blocktranslate('Ship to a different address?')}/>
                <RichText
                    tagName="span"
                    value={attributes.ship_to_different_title}
                    onChange={(value) => setAttributes({ ship_to_different_title: value })}
                    placeholder={blocktranslate('Title')}
                    allowedFormats={['core/bold', 'core/italic']}
                />
            </label>
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

export default CheckoutShipToDifferentFieldBlock;
