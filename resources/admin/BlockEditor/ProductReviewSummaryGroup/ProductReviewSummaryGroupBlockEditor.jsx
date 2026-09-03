import InspectorSettings from "@/BlockEditor/ProductReviewSummaryGroup/Components/InspectorSettings";
import {useInsideProductReviews, useReviewBlockProduct} from "@/BlockEditor/ProductReviews/Components/reviewBlockShared";
import {SingleProductDataProvider} from "@/BlockEditor/ShopApp/Context/SingleProductContext.jsx";
import {RatingSummary} from "@/BlockEditor/Icons";

const {useBlockProps, InnerBlocks} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useEffect} = wp.element;

const blockEditorData = window.fluent_cart_product_review_summary_group_data;

// The card and its CTA as two children — a pure Rating Summary card plus
// the Write a Review button, both following this wrapper's product.
const TEMPLATE = [
    ['fluent-cart/product-review-summary', {}],
    ['fluent-cart/product-review-form', {}],
];

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: RatingSummary,
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
    },
    edit: ({attributes, setAttributes, clientId}) => {
        const blockProps = useBlockProps({
            className: 'fct-review-summary-group-editor',
        });

        const insideParent = useInsideProductReviews(clientId);
        const {selectedProduct, setSelectedProduct} = useReviewBlockProduct(attributes, insideParent, false);

        // Inside the Product Reviews container the parent owns the product:
        // pin this wrapper to the default query so resolveProduct() follows
        // the parent's setup and a stale custom pick can never win.
        useEffect(() => {
            if (insideParent && attributes.query_type !== 'default') {
                setAttributes({query_type: 'default'});
            }
        }, [insideParent, attributes.query_type]);

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
                        template={TEMPLATE}
                        templateLock={false}
                        allowedBlocks={[
                            'fluent-cart/product-review-summary',
                            'fluent-cart/product-review-form',
                        ]}
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
