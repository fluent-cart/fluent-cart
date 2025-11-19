import {useProductData} from "@/BlockEditor/ShopApp/Context/ProductContext";
import {SingleProductDataProvider} from "@/BlockEditor/ShopApp/Context/SingleProductContext";

const {
    InnerBlocks,
    useBlockProps,
    useInnerBlocksProps,
    __experimentalUseBlockPreview,
    BlockContextProvider
} = wp.blockEditor;

const useBlockPreview = __experimentalUseBlockPreview || null;

const {useContext, useEffect, useState, useMemo, memo} = wp.element;
const {useSelect} = wp.data;
let lastChanged = '';

const ProductTemplateInnerBlocks = () => {
    const innerBlocksProps = useInnerBlocksProps(
        {className: 'wc-block-product'},
        {__unstableDisableLayoutClassNames: true}
    );
    return <div {...innerBlocksProps} />;
};


const ProductTemplateBlockPreview = ({
                                         blocks,
                                         blockContextId,
                                         isHidden,
                                         setActiveBlockContextId,
                                     }) => {
    const blockPreviewProps = useBlockPreview({
        blocks,
        props: {
            className: 'fct-block-product',
        },
    });

    const handleOnClick = () => {
        setActiveBlockContextId(blockContextId);
    };

    const style = {
        display: isHidden ? 'none' : undefined,
    };

    return (
        <div
            {...blockPreviewProps}
            tabIndex={0}
            // eslint-disable-next-line jsx-a11y/no-noninteractive-element-to-interactive-role
            role="button"
            onClick={handleOnClick}
            onKeyPress={handleOnClick}
            style={style}
        />
    );
};


const MemoizedProductTemplateBlockPreview = memo(ProductTemplateBlockPreview);

const ProductContent = ({
                            displayTemplate,
                            blocks,
                            blockContext,
                            setActiveBlockContextId,
                        }) => {
    return (
        <BlockContextProvider
            key={blockContext.ID}
            value={blockContext}
        >
            {displayTemplate ? <ProductTemplateInnerBlocks/> : null}
            <MemoizedProductTemplateBlockPreview
                blocks={blocks}
                blockContextId={blockContext.ID}
                setActiveBlockContextId={setActiveBlockContextId}
                isHidden={displayTemplate}
            />
        </BlockContextProvider>
    );
};

const ProductContentComponent = ProductContent;

//Our product loop block

const ProductLoopBlock = {

    attributes: {
        wp_client_id: {
            type: 'string',
            default: ''
        },
        last_changed: {
            type: 'string',
            default: ''
        }
    },
    edit: (props) => {
        const blockProps = useBlockProps({
            className: 'fct-shop-app-preview-wrap',
        });

        const {attributes, setAttributes, clientId, context} = props;

        useEffect(() => {
            setAttributes({last_changed: new Date().toISOString()});
            if (attributes.wp_client_id) {
                return;
            }
            setAttributes({wp_client_id: clientId});
        }, [clientId, attributes.wp_client_id, setAttributes]);

        const productBoxGridSize = context['fluent-cart/product_box_grid_size'];
        const parentData = useProductData();


        // Get the current block data
        const currentBlock = useSelect(
            (select) => select('core/block-editor').getBlock(clientId),
            [clientId]
        );

        const [count, setCount] = useState(1);

        useEffect(() => {
            if (count > 1) {
                lastChanged = new Date().toISOString();
                return;
            }
            setCount(count + 1);
        }, [currentBlock]);

        const blocks = currentBlock.innerBlocks;

        const [ activeBlockContextId, setActiveBlockContextId ] = useState();

        return (
            <div {...blockProps}>
                <div className={
                    "shop-app-preview-products gap-5 grid md:grid-cols-dynamic col-dynamic group-[.list]:grid-cols-1 grid-columns-" + productBoxGridSize
                }>
                    {parentData?.products.map((product) => {

                        const displayTemplate =
                            product.ID ===
                            ( activeBlockContextId || parentData?.products[ 0 ]?.ID );

                            return (

                                <SingleProductDataProvider value={{
                                    product: product
                                }} key={product.ID}>
                                    <div key={product.ID}
                                         className="fluent-cart-product-loop fct-product-block-editor-product-card">

                                        <ProductContentComponent
                                            key={product.ID}
                                            attributes={{
                                                productId: product.ID,
                                            }}
                                            blocks={blocks}
                                            displayTemplate={displayTemplate}
                                            blockContext={product}
                                            setActiveBlockContextId={setActiveBlockContextId}
                                        />
                                    </div>
                                </SingleProductDataProvider>
                            )
                        }
                    )}
                </div>
            </div>
        );
    },

    save: (props) => {
        const blockProps = useBlockProps.save();
        return (
            <div {...blockProps} className="fluent-cart-product-loop fct-product-block-editor-product-card">
                <InnerBlocks.Content/>
            </div>
        );
    },
    supports: {
        align: ['wide', 'full'],
        html: false,
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
    ]
};

export default ProductLoopBlock;
