import InspectorSettings from "@/BlockEditor/ProductReviews/Components/InspectorSettings";
import {useInsideProductReviews, useReviewBlockProduct} from "@/BlockEditor/ProductReviews/Components/reviewBlockShared";
import {SingleProductDataProvider} from "@/BlockEditor/ShopApp/Context/SingleProductContext.jsx";
import {StarRating} from "@/BlockEditor/Icons";

const {useBlockProps, InnerBlocks} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useEffect, useMemo} = wp.element;

const blockEditorData = window.fluent_cart_product_reviews_data;

// The block is a container now: Rating Summary, Write a Review and Review
// List are child blocks that follow this parent's product. The template
// mirrors the old combined layout via core/columns, Product Info style —
// the summary card (with its Write a Review button inside, behind the
// block's toggle) in a 320px left column (the old .fct-reviews-layout
// aside width), the list filling the right. The standalone Write a Review
// block stays available for placements outside the card.

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
    // The trigger's sibling: the form itself, for containers that want it
    // on the page rather than behind a button.
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
        // Which layout the section was last built from. The blocks are the
        // layout; this only remembers what the picker should show.
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

        // Inside Product Info (or a product loop) that container owns the
        // product: pin to the default query so the container's setup wins.
        useEffect(() => {
            if (insideParent && attributes.query_type !== 'default') {
                setAttributes({query_type: 'default'});
            }
        }, [insideParent, attributes.query_type]);

        // The same scaffold every time, so it is built once rather than on
        // every render. InnerBlocks applies it only while the container is
        // empty, which is the one moment it is read.
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
