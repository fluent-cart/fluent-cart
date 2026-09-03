import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import CartTextInspectorSettings from "@/BlockEditor/Cart/Components/CartTextInspectorSettings";

const {useBlockProps, RichText} = wp.blockEditor;

// Mirrors the front end, which renders CartRenderer::renderCheckoutButton() as
// a right-aligned "Go to Checkout" link (a.checkout-button) rather than a solid
// button. Editable inline and from the sidebar; empty means "use the default".
const CartCheckoutButtonBlock = {
    edit: ({attributes, setAttributes}) => {
        const blockProps = useBlockProps({
            className: 'fct-cart-block-preview-checkout'
        });

        const defaultText = blocktranslate('Go to Checkout');

        return (
            <>
                <CartTextInspectorSettings
                    label={blocktranslate('Button Text')}
                    help={blocktranslate('Leave empty to use the default.')}
                    value={attributes.button_text}
                    placeholder={defaultText}
                    onChange={(value) => setAttributes({button_text: value})}
                />

                <div {...blockProps}>
                    <RichText
                        tagName="span"
                        className="fct-cart-block-preview-checkout-link"
                        value={attributes.button_text}
                        onChange={(value) => setAttributes({button_text: value})}
                        placeholder={defaultText}
                        allowedFormats={[]}
                    />
                </div>
            </>
        );
    },
    save: () => null,
};

export default CartCheckoutButtonBlock;
