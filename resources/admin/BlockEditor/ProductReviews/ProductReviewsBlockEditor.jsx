import InspectorSettings from "@/BlockEditor/ProductReviews/Components/InspectorSettings";
import {useInsideProductReviews, useReviewBlockProduct} from "@/BlockEditor/ProductReviews/Components/reviewBlockShared";
import {SingleProductDataProvider} from "@/BlockEditor/ShopApp/Context/SingleProductContext.jsx";
import {StarRating} from "@/BlockEditor/Icons";

const {useBlockProps, InnerBlocks} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useEffect, useMemo} = wp.element;

const blockEditorData = window.fluent_cart_product_reviews_data;

// Children inherit the container's product. Start with the summary and
// review button beside the list; merchants can rearrange these blocks.

const buildTemplate = () => [
    ['core/columns', {style: {spacing: {blockGap: '24px'}}}, [
        ['core/column', {width: '320px'}, [
            ['fluent-cart/product-review-summary-group', {}, [
                ['fluent-cart/product-review-summary', {}],
                ['fluent-cart/write-a-review-button', {}],
            ]],
        ]],
        ['core/column', {}, [
            ['fluent-cart/product-review-list', {}],
        ]],
    ]],
];

const ALLOWED_BLOCKS = [
    'fluent-cart/product-review-summary-group',
    'fluent-cart/write-a-review-button',
    // Allow an inline form as an alternative to the review button.
    'fluent-cart/product-review-form',
    'fluent-cart/product-review-list',
    'core/columns',
    'core/group',
    'core/heading',
    'core/paragraph',
    'core/spacer',
];

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: StarRating,
    },
    category: "fluent-cart",
    attributes: {
        query_type: {
            type: 'string',
            default: 'default',
        },
        product_id: {
            type: ['string', 'number'],
            default: '',
        },
        // Picker state only; child blocks define the layout.
        preset: {
            type: 'string',
            default: 'classic',
        },
    },
    edit: ({attributes, setAttributes, clientId}) => {
        const blockProps = useBlockProps({
            className: 'fct-product-reviews-block-editor',
        });

        const insideParent = useInsideProductReviews(clientId);
        const {selectedProduct, setSelectedProduct} = useReviewBlockProduct(attributes, insideParent, false);

        // Inherit Product Info or loop context instead of a saved custom product.
        useEffect(() => {
            if (insideParent && attributes.query_type !== 'default') {
                setAttributes({query_type: 'default'});
            }
        }, [insideParent, attributes.query_type]);

        // Keep the initial InnerBlocks template stable across renders.
        const template = useMemo(() => buildTemplate(), []);

        return (
            <div {...blockProps}>
                <InspectorSettings
                    attributes={attributes}
                    setAttributes={setAttributes}
                    selectedProduct={selectedProduct}
                    setSelectedProduct={setSelectedProduct}
                    insideParent={insideParent}
                    clientId={clientId}
                />

                <SingleProductDataProvider value={{
                    product: selectedProduct
                }}>
                    <InnerBlocks
                        template={template}
                        templateLock={false}
                        allowedBlocks={ALLOWED_BLOCKS}
                    />
                </SingleProductDataProvider>
            </div>
        );
    },
    save: function () {
        return <InnerBlocks.Content/>;
    },
    supports: {
        html: false,
        align: true,
        typography: {
            fontSize: true,
            lineHeight: true,
            __experimentalFontFamily: true,
            __experimentalFontWeight: true,
            __experimentalDefaultControls: {
                fontSize: true,
            },
        },
        color: {
            text: true,
            background: true,
        },
        spacing: {
            margin: true,
            padding: true,
        },
        __experimentalBorder: {
            color: true,
            radius: true,
            style: true,
            width: true,
        },
    },
});
