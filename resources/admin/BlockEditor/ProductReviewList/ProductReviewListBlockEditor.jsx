import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import InspectorSettings from "@/BlockEditor/ProductReviewList/Components/InspectorSettings";
import ListPreview from "@/BlockEditor/ProductReviews/Components/ListPreview";
import {useInsideProductReviews, useReviewBlockProduct} from "@/BlockEditor/ProductReviews/Components/reviewBlockShared";
import {ReviewList} from "@/BlockEditor/Icons";

const {useBlockProps} = wp.blockEditor;
const {registerBlockType} = wp.blocks;
const {useEffect} = wp.element;

const blockEditorData = window.fluent_cart_product_review_list_data;

registerBlockType(blockEditorData.slug + '/' + blockEditorData.name, {
    apiVersion: 3,
    title: blockEditorData.title,
    description: blockEditorData.description,
    icon: {
        src: ReviewList,
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
            className: 'fct-review-list-block-editor',
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
        const reviewCount = parseInt(reviewSummary?.total ?? otherInfo.review_count, 10) || 0;
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
                    <div className="fct-reviews-block-skeleton-columns is-single">
                        <ListPreview
                            hasProductData={hasProductData}
                            reviewCount={reviewCount}
                            showSortControls={attributes.showSortControls}
                            showReviewerName={attributes.showReviewerName}
                            showReviewDate={attributes.showReviewDate}
                        />
                    </div>
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
