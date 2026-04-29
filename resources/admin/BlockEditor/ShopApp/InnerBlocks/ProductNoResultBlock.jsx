import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import { ProductContainerContext } from "@/BlockEditor/ShopApp/Context/ProductContainerContext";
const {useContext} = wp.element;

const {useBlockProps, InspectorControls, InnerBlocks} = wp.blockEditor;
const {ToggleControl, TextControl} = wp.components
const ProductNoResultBlock = {
    attributes: {},
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
    ],
    edit: (props) => {
        const { context } = props;

        const { simulateNoResults } = useContext(ProductContainerContext);

        let isShown = simulateNoResults ? 'show' : '';

        const blockProps = useBlockProps({
            className: 'fluent-cart-shop-no-result-wrap' + ' ' + isShown,
        });

        return <div {...blockProps} >
                <InnerBlocks />
            </div>;
    },

    save: (props) => {
        const blockProps = useBlockProps.save();
        return <div {...blockProps} className="fluent-cart-shop-no-result-wrap">
            <InnerBlocks.Content/>
        </div>;
    },
};

export default ProductNoResultBlock;
