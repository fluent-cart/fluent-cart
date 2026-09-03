import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import CartTextInspectorSettings from "@/BlockEditor/Cart/Components/CartTextInspectorSettings";

const {useBlockProps, RichText} = wp.blockEditor;

// Left side shows the real front-end label so it reads as the shipped output;
// the amount stays a skeleton because it comes from the shopper's own cart.
// The label is editable two ways — inline in the canvas and from the sidebar —
// because neither alone is discoverable for everyone.
const CartTotalBlock = {
    edit: ({attributes, setAttributes}) => {
        const blockProps = useBlockProps({
            className: 'fct-cart-block-preview-summary'
        });

        const defaultLabel = blocktranslate('Total');

        return (
            <>
                <CartTextInspectorSettings
                    label={blocktranslate('Total Label')}
                    help={blocktranslate('Leave empty to use the default.')}
                    value={attributes.total_label}
                    placeholder={defaultLabel}
                    onChange={(value) => setAttributes({total_label: value})}
                />

                <div {...blockProps}>
                    <RichText
                        tagName="span"
                        className="fct-cart-block-preview-total-label"
                        value={attributes.total_label}
                        onChange={(value) => setAttributes({total_label: value})}
                        placeholder={defaultLabel}
                        allowedFormats={[]}
                    />
                    <span className="fct-cart-block-preview-line fct-cart-block-preview-line-total"/>
                </div>
            </>
        );
    },
    save: () => null,
};

export default CartTotalBlock;
