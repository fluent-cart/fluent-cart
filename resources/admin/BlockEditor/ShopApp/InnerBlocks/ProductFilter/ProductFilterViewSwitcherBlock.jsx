import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {useBlockProps, InspectorControls, InnerBlocks} = wp.blockEditor;
const {ToggleControl, TextControl} = wp.components
const ProductFilterViewSwitcherBlock = {
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
            className : 'fct-shop-view-switcher'
        });

        // get context
        const {context} = props;


        const viewMode = context['fluent-cart/view_mode'];

        return <div {...blockProps} >
                <button type="button" className={viewMode === 'grid' ? 'active' : ''} title={blocktranslate('Grid View')}>
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14" fill="none">
                        <path
                            d="M12.4059 1.59412C13.3334 2.52162 13.3334 4.0144 13.3334 6.99996C13.3334 9.98552 13.3334 11.4783 12.4059 12.4058C11.4784 13.3333 9.98564 13.3333 7.00008 13.3333C4.01452 13.3333 2.52174 13.3333 1.59424 12.4058C0.666748 11.4783 0.666748 9.98552 0.666748 6.99996C0.666748 4.0144 0.666748 2.52162 1.59424 1.59412C2.52174 0.666626 4.01452 0.666626 7.00008 0.666626C9.98564 0.666626 11.4784 0.666626 12.4059 1.59412Z"
                            stroke="currentColor" strokeLinecap="round" strokeLinejoin="round"></path>
                        <path d="M13.3335 7L0.66683 7" stroke="currentColor" strokeLinecap="round"></path>
                        <path d="M7 0.666626L7 13.3333" stroke="currentColor" strokeLinecap="round"></path>
                    </svg>
                </button>
                <button type="button" className={viewMode === 'list' ? 'active' : ''} title={blocktranslate('List View')}>
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <path
                            d="M1.33325 7.60008C1.33325 6.8279 1.49441 6.66675 2.26659 6.66675H13.7333C14.5054 6.66675 14.6666 6.8279 14.6666 7.60008V8.40008C14.6666 9.17226 14.5054 9.33341 13.7333 9.33341H2.26659C1.49441 9.33341 1.33325 9.17226 1.33325 8.40008V7.60008Z"
                            stroke="currentColor" strokeLinecap="round"></path>
                        <path
                            d="M1.33325 2.26671C1.33325 1.49453 1.49441 1.33337 2.26659 1.33337H13.7333C14.5054 1.33337 14.6666 1.49453 14.6666 2.26671V3.06671C14.6666 3.83889 14.5054 4.00004 13.7333 4.00004H2.26659C1.49441 4.00004 1.33325 3.83888 1.33325 3.06671V2.26671Z"
                            stroke="currentColor" strokeLinecap="round"></path>
                        <path
                            d="M1.33325 12.9333C1.33325 12.1612 1.49441 12 2.26659 12H13.7333C14.5054 12 14.6666 12.1612 14.6666 12.9333V13.7333C14.6666 14.5055 14.5054 14.6667 13.7333 14.6667H2.26659C1.49441 14.6667 1.33325 14.5055 1.33325 13.7333V12.9333Z"
                            stroke="currentColor" strokeLinecap="round"></path>
                    </svg>
                </button>
            </div>;
    },

    save: (props) => {
        return null;
    },
};

export default ProductFilterViewSwitcherBlock;
