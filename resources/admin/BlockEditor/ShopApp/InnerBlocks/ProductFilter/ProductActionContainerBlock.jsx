import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {useBlockProps, InspectorControls, InnerBlocks} = wp.blockEditor;
const {ToggleControl, TextControl} = wp.components
const ProductActionContainerBlock = {
    attributes: {},
    edit: (props) => {
        const {attributes, setAttributes} = props;

        const blockProps = useBlockProps({
            className: 'fluent-cart-shop-app-view-switcher fluent-cart-product-filter-view-switcher-wrapper',
        });

        // get context
        const {context} = props;

        return <div {...blockProps} >
            <InnerBlocks
                templateLock={false}
                allowedBlocks={
                    [
                        'fluent-cart/shopapp-product-view-switcher',
                        'fluent-cart/shopapp-product-filter-sort-by'
                    ]
                }
            />
            </div>;
    },

    save: (props) => {
        const blockProps = useBlockProps.save({className: 'fluent-cart-shop-app-view-switcher'});

        return (
            <div {...blockProps}>
                <InnerBlocks.Content/>
            </div>
        );
    },
};

export default ProductActionContainerBlock;
