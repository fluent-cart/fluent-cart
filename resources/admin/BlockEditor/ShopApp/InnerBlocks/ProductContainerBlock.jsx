const {InnerBlocks, useBlockProps} = wp.blockEditor;
const ProductContainerBlock = {
    attributes: {},
    edit: (props) => {
        const blockProps = useBlockProps({
            className: 'fluent-cart-product-container',
        });
        const {context} = props;
        return (
            <div {...blockProps} >
                <div  className="shop-app-preview">
                    <InnerBlocks/>
                </div>
            </div>
        );
    },

    save: (props) => {
        const blockProps = useBlockProps.save();
        return (
            <div {...blockProps}>
                <InnerBlocks.Content/>
            </div>
        );
    },
    usesContext: [
        'fluent-cart/paginator',
        'fluent-cart/per_page',
        'fluent-cart/enable_filter',
        'fluent-cart/product_box_grid_size',
        'fluent-cart/view_mode',
        'fluent-cart/filters',
        'fluent-cart/default_filters',
        'fluent-cart/order_type',
        'fluent-cart/order_by',
        'fluent-cart/live_filter',
        'fluent-cart/price_format',
        'fluent-cart/enable_wildcard_filter'
    ],
    supports: {
        align: ['wide', 'full'],
        html: false,
    }
};

export default ProductContainerBlock;
