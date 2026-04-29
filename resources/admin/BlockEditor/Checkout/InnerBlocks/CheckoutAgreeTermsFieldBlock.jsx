import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const { useBlockProps, RichText } = wp.blockEditor;
const CheckoutAgreeTermsFieldBlock = {
    attributes: {
        terms_title: {
            type: 'string',
            default: blocktranslate('I agree to the terms and conditions.')
        }
    },
    edit: (props) => {
        const { attributes, setAttributes } = props;
        const blockProps = useBlockProps({
            className: 'fc-checkout-agree-terms-block',
        });

        return <div { ...props } {...blockProps}>
            <label htmlFor="terms_and_conditions">
                <input type="checkbox" name="terms_and_conditions" readOnly disabled />
                <RichText
                    tagName="span"
                    value={attributes.terms_title}
                    onChange={(value) => setAttributes({ terms_title: value })}
                    placeholder={blocktranslate('I agree to the terms and conditions.')}
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

export default CheckoutAgreeTermsFieldBlock;
