import {Layout} from "@/BlockEditor/Icons";
import {SingleProductDataProvider} from "@/BlockEditor/ShopApp/Context/SingleProductContext";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {
    useBlockProps,
    InnerBlocks,
    BlockContextProvider,
    useInnerBlocksProps,
    __experimentalUseBlockPreview
} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useState, memo} = wp.element;
const {useSelect} = wp.data;

const useBlockPreview = __experimentalUseBlockPreview || (() => ({}));

const blockEditorData = window.fluent_cart_related_product_data;
const parentBlockName = blockEditorData.slug + '/' + blockEditorData.name;

// Template for the inner product template block: image, title, price, button
const PRODUCT_TEMPLATE = [
    ['fluent-cart/shopapp-product-image'],
    ['fluent-cart/shopapp-product-title'],
    ['fluent-cart/shopapp-product-price'],
    ['fluent-cart/shopapp-product-buttons'],
];

// Product Template Components (following ProductLoopBlock pattern)
const ProductTemplateInnerBlocks = () => {
    const innerBlocksProps = useInnerBlocksProps(
        {className: 'fct-related-product-card'},
        {
            template: PRODUCT_TEMPLATE,
            templateLock: false,
            allowedBlocks: [
                'fluent-cart/shopapp-product-image',
                'fluent-cart/shopapp-product-title',
                'fluent-cart/shopapp-product-price',
                'fluent-cart/shopapp-product-buttons',
            ],
        }
    );
    return <div {...innerBlocksProps} />;
};

const ProductTemplateBlockPreview = memo(({
    blocks,
    blockContextId,
    isHidden,
    setActiveBlockContextId,
}) => {
    const blockPreviewProps = useBlockPreview({
        blocks,
        props: {
            className: 'fct-related-product-card',
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
            role="button"
            onClick={handleOnClick}
            onKeyPress={handleOnClick}
            style={style}
        />
    );
});

const ProductContent = ({
    blocks,
    blockContext,
    displayTemplate,
    setActiveBlockContextId,
}) => {
    // Ensure the product has the required structure
    const product = {
        ...blockContext,
        detail: blockContext.detail || {},
    };

    return (
        <BlockContextProvider
            key={product.ID}
            value={product}
        >
            <SingleProductDataProvider value={{product}}>
                {displayTemplate ? <ProductTemplateInnerBlocks /> : null}
                <ProductTemplateBlockPreview
                    blocks={blocks}
                    blockContextId={product.ID}
                    setActiveBlockContextId={setActiveBlockContextId}
                    isHidden={displayTemplate}
                />
            </SingleProductDataProvider>
        </BlockContextProvider>
    );
};

// Register the product template block directly
registerBlockType('fluent-cart/product-template', {
    apiVersion: 3,
    title: blocktranslate('Product Template'),
    description: blocktranslate('Contains the block elements used to render a product.'),
    icon: {
        src: Layout,
    },
    category: 'fluent-cart',
    parent: [parentBlockName],
    usesContext: ['fluent-cart/related_product_ids', 'fluent-cart/related_products', 'fluent-cart/related_by_categories', 'fluent-cart/related_by_brands', 'fluent-cart/product_id', 'fluent-cart/query_type', 'fluent-cart/columns', 'fluent-cart/posts_per_page', 'fluent-cart/show_image', 'fluent-cart/show_title', 'fluent-cart/show_price', 'fluent-cart/show_button'],
    supports: {
        reusable: false,
        html: false,
    },
    edit: ({context, clientId}) => {
        // Use full product data for editor
        const relatedProducts = context['fluent-cart/related_products'] || [];
        const queryType = context['fluent-cart/query_type'] || 'custom';
        const productId = context['fluent-cart/product_id'] || '';
        const columns = context['fluent-cart/columns'] || 4;
        const [activeBlockContextId, setActiveBlockContextId] = useState();

        const gridStyle = {
            gridTemplateColumns: `repeat(${columns}, 1fr)`,
        };

        // Build hide classes for show/hide element toggles
        const hideClasses = [
            context['fluent-cart/show_image'] === false && 'fct-hide-image',
            context['fluent-cart/show_title'] === false && 'fct-hide-title',
            context['fluent-cart/show_price'] === false && 'fct-hide-price',
            context['fluent-cart/show_button'] === false && 'fct-hide-button',
        ].filter(Boolean).join(' ');

        const blockProps = useBlockProps({
            className: `fct-product-template-block ${hideClasses}`.trim(),
        });

        // Get the current block data
        const currentBlock = useSelect(
            (select) => select('core/block-editor').getBlock(clientId),
            [clientId]
        );

        const blocks = currentBlock?.innerBlocks || [];

        if (relatedProducts.length === 0) {
            // Custom mode with product selected but no results: show a text message
            if (queryType === 'custom' && productId) {
                return (
                    <div {...blockProps}>
                        <div className="fct-no-related-products">
                            <p>
                                {blocktranslate('No products to display. Try adjusting the filters in the block settings panel or add categories or brands to this product.')}
                            </p>
                        </div>
                    </div>
                );
            }

            // Default mode or no product selected: show dummy placeholder cards
            const showImage = context['fluent-cart/show_image'] !== false;
            const showTitle = context['fluent-cart/show_title'] !== false;
            const showPrice = context['fluent-cart/show_price'] !== false;
            const showButton = context['fluent-cart/show_button'] !== false;
            const placeholderCards = Array.from({length: columns}, (_, i) => i + 1);
            return (
                <div {...blockProps}>
                    <div className="fct-related-products-grid" style={gridStyle}>
                        {placeholderCards.map((i) => (
                            <div key={i} className="fct-product-card-placeholder">
                                {showImage && <div className="fct-placeholder-image" />}
                                {showTitle && <div className="fct-placeholder-line fct-placeholder-title" />}
                                {showPrice && <div className="fct-placeholder-line fct-placeholder-price" />}
                                {showButton && <div className="fct-placeholder-line fct-placeholder-button" />}
                            </div>
                        ))}
                    </div>
                </div>
            );
        }

        return (
            <div {...blockProps}>
                <div className="fct-related-products-grid" style={gridStyle}>
                    {relatedProducts.map((product) => {
                        const displayTemplate =
                            product.ID === (activeBlockContextId || relatedProducts[0]?.ID);

                        return (
                            <div
                                key={product.ID}
                                className="fct-product-card"
                            >
                                <ProductContent
                                    key={product.ID}
                                    blocks={blocks}
                                    blockContext={product}
                                    displayTemplate={displayTemplate}
                                    setActiveBlockContextId={setActiveBlockContextId}
                                />
                            </div>
                        );
                    })}
                </div>
            </div>
        );
    },
    save: () => {
        const blockProps = useBlockProps.save();
        return (
            <div {...blockProps} className="fluent-cart-related-product-template">
                <InnerBlocks.Content/>
            </div>
        );
    }
});
