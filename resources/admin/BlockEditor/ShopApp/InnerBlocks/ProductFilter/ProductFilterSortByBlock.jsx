import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {useBlockProps, InspectorControls, InnerBlocks} = wp.blockEditor;
const {ToggleControl, TextControl} = wp.components
const ProductFilterSortByBlock = {
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
        'fluent-cart/enable_wildcard_filter'
    ],
    edit: (props) => {
        const {attributes, setAttributes} = props;

        const blockProps = useBlockProps({
            className : 'fct-shop-filter-sort-by'
        });

        return <div {...blockProps} >
            {blocktranslate('Sort By')}
        </div>;
    },

    save: (props) => {
        return null;
    },
};

export default ProductFilterSortByBlock;
