const {useBlockProps, InnerBlocks} = wp.blockEditor;
const ProductFilterButtonBlock = {
    attributes: {},
    edit: (props) => {

        const {attributes, setAttributes} = props;

        return <div {...props} {...useBlockProps()}>
            <div className="fct-product-block-filter-item">
                <InnerBlocks allowedBlocks={[
                    'fluent-cart/shopapp-product-filter-apply-button',
                    'fluent-cart/shopapp-product-filter-reset-button'
                ]} />
            </div>
        </div>;
    },
    save: (props) => {
        const blockProps = useBlockProps.save();
        return <div {...blockProps} className="fct-product-block-filter-item">
            <InnerBlocks.Content/>
        </div>;
    }
};

export default ProductFilterButtonBlock;
