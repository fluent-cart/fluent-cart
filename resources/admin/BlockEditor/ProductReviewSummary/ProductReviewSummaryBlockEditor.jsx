import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import InspectorSettings from "@/BlockEditor/ProductReviewSummary/Components/InspectorSettings";
import SummaryPreview from "@/BlockEditor/ProductReviews/Components/SummaryPreview";
import {useInsideProductReviews, useReviewBlockProduct} from "@/BlockEditor/ProductReviews/Components/reviewBlockShared";
import {RatingBars} from "@/BlockEditor/Icons";

const {useBlockProps} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useEffect} = wp.element;

const blockEditorData = window.fluent_cart_product_review_summary_data;

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: RatingBars,
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
        starColor: {
            type: 'string',
            default: '#f59e0b',
        },
    },
    edit: ({attributes, setAttributes, clientId}) => {
        const blockProps = useBlockProps({
            className: 'fct-review-summary-block-editor',
        });

        const insideParent = useInsideProductReviews(clientId);
        const {selectedProduct, setSelectedProduct, reviewSummary, effectiveProductId} =
            useReviewBlockProduct(attributes, insideParent, true);

        // Inside the container the parent owns the product: pin the child to
        // the default query so resolveProduct() follows the parent's setup
        // and a stale custom pick can never win.
        useEffect(() => {
            if (insideParent && attributes.query_type !== 'default') {
                setAttributes({query_type: 'default'});
            }
        }, [insideParent, attributes.query_type]);

        const otherInfo = selectedProduct?.detail?.other_info || {};
        const avgRating = parseFloat(reviewSummary?.average ?? otherInfo.average_rating) || 0;
        const reviewCount = parseInt(reviewSummary?.total ?? otherInfo.review_count, 10) || 0;
        const ratingBreakdown = reviewSummary?.breakdown || otherInfo.rating_breakdown || {};
        const starColor = attributes.starColor || '#f59e0b';
        const hasProductData = !!effectiveProductId && !!selectedProduct?.ID;

        return (
            <div {...blockProps}>
                <InspectorSettings
                    attributes={attributes}
                    setAttributes={setAttributes}
                    selectedProduct={selectedProduct}
                    setSelectedProduct={setSelectedProduct}
                    insideParent={insideParent}
                />

                <div className="fct-reviews-block-skeleton">
                    <SummaryPreview
                        hasProductData={hasProductData}
                        avgRating={avgRating}
                        reviewCount={reviewCount}
                        ratingBreakdown={ratingBreakdown}
                        starColor={starColor}
                    />
                    {!hasProductData && !insideParent && (
                        <div className="fct-reviews-block-skeleton-caption">
                            {blocktranslate('Reviews will be displayed for the current product')}
                        </div>
                    )}
                </div>
            </div>
        );
    },
    save: function () {
        return null;
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
