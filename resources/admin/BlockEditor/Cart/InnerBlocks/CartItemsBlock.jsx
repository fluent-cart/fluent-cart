import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {useBlockProps} = wp.blockEditor;

// The editor has no shopper session, so there is no real cart to draw. Each
// child renders a labelled wireframe of the region it owns instead.
const CartItemsBlock = {
    edit: () => {
        const blockProps = useBlockProps({
            className: 'fct-cart-block-preview-items'
        });

        return (
            <div {...blockProps}>
                {[1, 2].map((row) => (
                    <div key={row} className="fct-cart-block-preview-row">
                        <div className="fct-cart-block-preview-thumb"/>
                        <div className="fct-cart-block-preview-lines">
                            <div className="fct-cart-block-preview-line fct-cart-block-preview-line-title"/>
                            <div className="fct-cart-block-preview-line fct-cart-block-preview-line-meta"/>
                        </div>
                        <div className="fct-cart-block-preview-qty"/>
                        <div className="fct-cart-block-preview-price"/>
                    </div>
                ))}
                <p className="fct-cart-block-preview-note">
                    {blocktranslate('Cart items render here from the shopper\'s own cart.')}
                </p>
            </div>
        );
    },
    save: () => null,
};

export default CartItemsBlock;
