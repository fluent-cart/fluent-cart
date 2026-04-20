import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const { useBlockProps } = wp.blockEditor;
const CheckoutNameFieldsBlock = {
    edit: (props) => {
        const blockProps = useBlockProps({
            className: 'fc-checkout-name-fields-block',
        });

        return <div { ...props } {...blockProps}>
            <input type="text" readOnly disabled placeholder={blocktranslate('Name')}/>
            <input type="text" readOnly disabled placeholder={blocktranslate('Email')}/>
            <label htmlFor="allow_create_account">
                <input type="checkbox" name="allow_create_account" readOnly disabled/>
                <span>{blocktranslate('Create an Account?')}</span>
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

export default CheckoutNameFieldsBlock;
