const {useBlockProps, InnerBlocks} = wp.blockEditor;

const CheckoutSummaryFooterBlock = {
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
        return null;
    },
    save: () => {
        return null;
    },
    category: "fluent-cart"
};

export default CheckoutSummaryFooterBlock;
