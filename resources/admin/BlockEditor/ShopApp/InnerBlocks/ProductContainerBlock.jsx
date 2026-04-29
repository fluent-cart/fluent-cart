import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import { ProductContainerContext } from "@/BlockEditor/ShopApp/Context/ProductContainerContext";


const {InnerBlocks, useBlockProps, InspectorControls} = wp.blockEditor;
const { PanelBody, ToggleControl } = wp.components;

const ProductContainerBlock = {
    attributes: {
        simulate_no_results: { type: 'boolean', default: false },
        simulate_loading: { type: 'boolean', default: false },
    },
    category: "fluent-cart",
    edit: (props) => {
        const blockProps = useBlockProps({
            className: 'fluent-cart-product-container',
        });

        const { simulate_no_results, simulate_loading } = props.attributes;

        return (
            <>
                {/* Inspector Controls */}
                <InspectorControls>
                    <PanelBody title={blocktranslate('Simulation')}>
                        <ToggleControl
                            label={blocktranslate('Simulate No Results')}
                            checked={simulate_no_results}
                            onChange={(v) => props.setAttributes({ simulate_no_results: v })}
                        />

                        <ToggleControl
                            label={blocktranslate('Simulate Loading')}
                            checked={simulate_loading}
                            onChange={(v) => props.setAttributes({ simulate_loading: v })}
                        />
                    </PanelBody>
                </InspectorControls>

                <ProductContainerContext.Provider value={{
                    simulateNoResults: simulate_no_results,
                    simulateLoading: simulate_loading,
                }}>
                    <div {...blockProps} >
                        <div  className="shop-app-preview">
                            <InnerBlocks/>
                        </div>
                    </div>
                </ProductContainerContext.Provider>
            </>
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
