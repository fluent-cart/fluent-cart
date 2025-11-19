const {useBlockProps, InnerBlocks} = wp.blockEditor;
const ProductSpinnerBlock = {
    attributes: {},
    usesContext: [
        'fluent-cart/paginator',
        'fluent-cart/enable_filter',
    ],
    edit: (props) => {

        const blockProps = useBlockProps({
            className: 'fluent-cart-product-spinner',
        });

        return <div {...blockProps} >
            <InnerBlocks />
        </div>;
    },

    save: (props) => {
        const blockProps = useBlockProps.save();
        return <div {...blockProps} className="fluent-cart-product-spinner">
            <InnerBlocks.Content/>
        </div>;
    },
};

export default ProductSpinnerBlock;
