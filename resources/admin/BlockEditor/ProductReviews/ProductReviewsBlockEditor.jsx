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
//
// The template derives from the block's saved attributes: a fresh insert
// carries the defaults, while a legacy self-closing block (saved before
// the split) has its display settings migrated onto the children it
// scaffolds — editing an old customized block must not silently reset its
// storefront presentation to defaults on save.
const buildTemplate = (attributes) => {
    const listAttrs = {
        showSortControls: attributes.showSortControls,
        showVerifiedBadge: attributes.showVerifiedBadge,
        showReviewDate: attributes.showReviewDate,
        showReviewerName: attributes.showReviewerName,
        showViewReply: attributes.showViewReply,
        starColor: attributes.starColor,
        defaultSortBy: attributes.defaultSortBy,
        defaultSortOrder: attributes.defaultSortOrder,
        perPage: attributes.perPage,
    };

    // Legacy render showed the Write a Review CTA only inside the summary
    // card, so summary off means no card and no CTA — just the full-width
    // list, matching what that block rendered on the storefront.
    if (!attributes.showSummary) {
        return [
            ['fluent-cart/product-review-list', listAttrs],
        ];
    }

    return [
        ['core/columns', {style: {spacing: {blockGap: '24px'}}}, [
            ['core/column', {width: '320px'}, [
                ['fluent-cart/product-review-summary-group', {}, [
                    ['fluent-cart/product-review-summary', {
                        starColor: attributes.starColor,
                    }],
                    ['fluent-cart/product-review-form', {}],
                ]],
            ]],
            ['core/column', {}, [
                ['fluent-cart/product-review-list', listAttrs],
            ]],
        ]],
    ];
};

const ALLOWED_BLOCKS = [
    'fluent-cart/product-review-summary-group',
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
        // Legacy display attributes from before the container split. They
        // are not editable here anymore, but blocks saved without inner
        // blocks still render server-side from them — dropping the
        // registration would strip them from old content on next save.
        showSummary: {
            type: 'boolean',
            default: true,
        },
        showSortControls: {
            type: 'boolean',
            default: true,
        },
        showVerifiedBadge: {
            type: 'boolean',
            default: true,
        },
        showReviewDate: {
            type: 'boolean',
            default: true,
        },
        showReviewerName: {
            type: 'boolean',
            default: true,
        },
        showViewReply: {
            type: 'boolean',
            default: true,
        },
        starColor: {
            type: 'string',
            default: '#f59e0b',
        },
        defaultSortBy: {
            type: 'string',
            default: 'created_at',
        },
        defaultSortOrder: {
            type: 'string',
            default: 'DESC',
        },
        perPage: {
            type: 'number',
            default: 0,
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

        // Computed once on mount: InnerBlocks only applies the template while
        // the container is empty, and the saved attributes at mount are the
        // legacy values the scaffold must carry.
        const template = useMemo(() => buildTemplate(attributes), []);

        return (
            <div {...blockProps}>
                <InspectorSettings
                    attributes={attributes}
                    setAttributes={setAttributes}
                    selectedProduct={selectedProduct}
                    setSelectedProduct={setSelectedProduct}
                    insideParent={insideParent}
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
